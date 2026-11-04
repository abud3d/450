<?php
declare(strict_types=1);

namespace Baloot\Game;

/**
 * One decision engine with four persistent playing personalities.
 * Personality changes priorities, never the legal rules.
 */
final class Bot
{
    private const STYLES = ['professional', 'aggressive', 'cautious', 'analyst'];

    public static function styleLabel(string $style): string
    {
        return [
            'professional' => 'محترف',
            'aggressive' => 'هجومي',
            'cautious' => 'حذر',
            'analyst' => 'محلل',
        ][$style] ?? 'محترف';
    }

    private static function style(array $g, int $seat): string
    {
        $style = (string) ($g['players'][$seat]['botStyle'] ?? 'professional');
        return in_array($style, self::STYLES, true) ? $style : 'professional';
    }

    public static function bid(array $g, int $seat): string
    {
        $hand = $g['hands'][$seat] ?? [];
        $upSuit = $g['upCard']['suit'] ?? '';
        $trump = array_values(array_filter($hand, fn($c) => $c['suit'] === $upSuit));
        $trumpValue = 0.0;
        foreach ($trump as $card) $trumpValue += ['J'=>6,'9'=>4,'A'=>2,'10'=>1,'K'=>1,'Q'=>0.5][$card['rank']] ?? 0;
        $style = self::style($g, $seat);
        $threshold = ['professional'=>5.0,'aggressive'=>4.0,'cautious'=>6.0,'analyst'=>5.5][$style];

        if (($g['phase'] ?? '') === 'auction1') {
            $sun = self::sunStrength($hand);
            if ($sun >= ($style === 'aggressive' ? 5.2 : 6.0)) return 'sun';
            return $trumpValue >= $threshold ? 'take' : 'pass';
        }

        $bestSuit = 'pass';
        $bestValue = 0.0;
        foreach (array_values(array_filter(Rules::SUITS, fn($s) => $s !== $upSuit)) as $suit) {
            $value = 0.0;
            foreach ($hand as $card) {
                if ($card['suit'] === $suit) $value += ['A'=>2,'10'=>1.4,'K'=>0.7,'Q'=>0.4,'J'=>0.5][$card['rank']] ?? 0;
            }
            if ($value > $bestValue) { $bestValue = $value; $bestSuit = $suit; }
        }
        $sun = self::sunStrength($hand);
        if ($sun > $bestValue) { $bestValue = $sun; $bestSuit = 'sun'; }
        return $bestValue >= $threshold ? ((($g['phase'] ?? '') === 'auction2') ? 'takeSecond' : $bestSuit) : 'pass';
    }

    private static function sunStrength(array $hand): float
    {
        $value = 0.0;
        foreach ($hand as $card) $value += ['A'=>1.4,'10'=>1.1,'K'=>0.5,'Q'=>0.3][$card['rank']] ?? 0;
        return $value;
    }

    public static function project(array $g, int $seat): string
    {
        return $g['projectOptions'][$seat][0]['type'] ?? 'pass';
    }

    /** Cards already exposed to every player, used for simple card counting. */
    private static function seenCards(array $g): array
    {
        $seen = [];
        foreach ($g['history'] ?? [] as $play) {
            if (isset($play['card']['id'])) $seen[$play['card']['id']] = true;
        }
        foreach ($g['trick'] ?? [] as $play) {
            if (isset($play['card']['id'])) $seen[$play['card']['id']] = true;
        }
        return $seen;
    }

    private static function unseenHigher(array $card, array $g, int $seat): int
    {
        $trump = ($g['contract']['type'] ?? '') === 'hokum' ? ($g['contract']['trump'] ?? null) : null;
        $seen = self::seenCards($g);
        foreach ($g['hands'][$seat] ?? [] as $own) $seen[$own['id']] = true;
        $count = 0;
        foreach (Rules::deck() as $other) {
            if ($other['suit'] !== $card['suit'] || isset($seen[$other['id']])) continue;
            if (Rules::rank($other, $trump) > Rules::rank($card, $trump)) $count++;
        }
        return $count;
    }

    private static function cardCost(array $card, array $g): float
    {
        $trump = ($g['contract']['type'] ?? '') === 'hokum' ? ($g['contract']['trump'] ?? null) : null;
        $rank = Rules::rank($card, $trump);
        $points = Rules::points($card, $g['contract']);
        $isTrump = $trump !== null && $card['suit'] === $trump;
        return ($rank * 0.75) + ($points * 0.28) + ($isTrump ? 2.5 : 0);
    }

    /**
     * Evaluate each legal card against the current trick, partner position,
     * points at stake, trump control and cards that have already appeared.
     */
    public static function card(array $g, int $seat): string
    {
        $hand = $g['hands'][$seat] ?? [];
        $trick = $g['trick'] ?? [];
        $contract = $g['contract'] ?? ['type' => 'sun'];
        $legal = Rules::legal($hand, $trick, $contract, $seat);
        if (!$legal) throw new \DomainException('لا توجد ورقة قانونية للبوت');
        if (count($legal) === 1) return $legal[0]['id'];

        $style = self::style($g, $seat);
        $hokum = ($contract['type'] ?? '') === 'hokum';
        $trumpSuit = $hokum ? ($contract['trump'] ?? null) : null;
        $trumpsInHand = $hokum ? count(array_filter($hand, fn($c) => $c['suit'] === $trumpSuit)) : 0;
        $currentWinner = $trick ? Rules::winner($trick, $contract) : null;
        $partnerWinning = $currentWinner !== null && Rules::team($currentWinner) === Rules::team($seat);
        $pointsOnTable = array_sum(array_map(fn($p) => Rules::points($p['card'], $contract), $trick));
        $isLead = !$trick;
        $weights = [
            'professional' => ['take'=>1.0,'save'=>1.0,'draw'=>1.0],
            'aggressive' => ['take'=>1.35,'save'=>0.65,'draw'=>1.4],
            'cautious' => ['take'=>0.75,'save'=>1.35,'draw'=>0.65],
            'analyst' => ['take'=>1.1,'save'=>1.0,'draw'=>1.1],
        ][$style];

        $scored = [];
        foreach ($legal as $card) {
            $trial = $trick;
            $trial[] = ['seat' => $seat, 'card' => $card];
            $winnerAfter = Rules::winner($trial, $contract);
            $wins = $winnerAfter === $seat;
            $cost = self::cardCost($card, $g);
            $score = -$cost * $weights['save'];

            if ($partnerWinning) {
                // Do not overtake a partner who is already taking the trick
                // unless the points justify spending a valuable card.
                if ($wins) $score -= 18 + ($pointsOnTable * 0.35);
                else $score += 5;
            } elseif (!$isLead && $wins) {
                // Take an opponent's trick with the cheapest sufficient card.
                $score += (10 + $pointsOnTable * 0.9) * $weights['take'];
                $score -= $cost * 0.45;
            } elseif (!$isLead && !$wins) {
                $score -= 1.5;
            }

            if ($isLead && $hokum && $card['suit'] === $trumpSuit) {
                if ($card['rank'] === 'J' && $trumpsInHand >= 2) {
                    // Lead the trump jack to force out a singleton opposing trump.
                    $score += 15 * $weights['draw'];
                } elseif ($card['rank'] === '9' && $trumpsInHand >= 2) {
                    $score += 5 * $weights['draw'];
                } elseif ($card['rank'] === 'J' && $trumpsInHand === 1) {
                    // A lone jack is control, but not automatically a draw lead.
                    $score -= 3;
                }
            }

            if ($isLead && $card['rank'] === 'A') {
                $score += 9;
            } elseif ($isLead && $card['rank'] === '10') {
                $higher = self::unseenHigher($card, $g, $seat);
                $score += $higher === 0 ? 7 : ($higher === 1 ? 1 : -4);
            }

            if ($style === 'analyst') {
                $higher = self::unseenHigher($card, $g, $seat);
                $score += $wins ? max(0, 3 - $higher) : -min(4, $higher) * 0.35;
            } elseif ($style === 'aggressive' && $wins) {
                $score += 2.5;
            } elseif ($style === 'cautious' && $wins && $partnerWinning) {
                $score -= 4;
            }

            $scored[] = ['id' => $card['id'], 'score' => $score, 'rank' => Rules::rank($card, $trumpSuit)];
        }

        usort($scored, static function ($a, $b): int {
            $cmp = $b['score'] <=> $a['score'];
            return $cmp !== 0 ? $cmp : ($a['rank'] <=> $b['rank']);
        });
        return $scored[0]['id'];
    }

    /** Backup action remains deliberately simple and legal. */
    public static function fallbackAct(array &$g, int $seat): bool
    {
        $phase = (string) ($g['phase'] ?? '');
        if (in_array($phase, ['auction1', 'auction2'], true)) {
            Purchase::choose($g, $seat, 'pass');
            return true;
        }
        if ($phase === 'postPurchase') {
            $options = Purchase::options($g, $seat);
            if (!$options) { Purchase::expire($g); return true; }
            Purchase::respond($g, $seat, in_array('pass', $options, true) ? 'pass' : $options[0]);
            return true;
        }
        if ($phase === 'buyerConfirm' || $phase === 'buyerSelectHukm') {
            $options = Purchase::options($g, $seat);
            if (!$options) return false;
            $choice = $phase === 'buyerConfirm'
                ? (in_array('confirmHukm', $options, true) ? 'confirmHukm' : $options[0])
                : (array_values(array_filter($options, static fn($option) => in_array($option, Rules::SUITS, true)))[0] ?? 'sun');
            Purchase::respond($g, $seat, $choice);
            return true;
        }
        if ($phase === 'projectDeclaration') {
            if (!empty($g['projectDeclared'][$seat])) return false;
            Projects::declare($g, $seat, 'pass');
            return true;
        }
        if ($phase === 'playing') {
            $hand = $g['hands'][$seat] ?? [];
            if (!$hand) return false;
            $legal = Rules::legal($hand, $g['trick'] ?? [], $g['contract'], $seat);
            if (!$legal) return false;
            Rules::play($g, $seat, (string) self::card($g, $seat));
            return true;
        }
        return false;
    }

    public static function act(array &$g, int $seat): bool
    {
        if (($g['phase'] ?? '') !== 'projectDeclaration' && ($g['turn'] ?? -1) !== $seat) return false;
        if (in_array($g['phase'] ?? '', ['auction1', 'auction2'], true)) {
            Purchase::choose($g, $seat, self::bid($g, $seat));
            return true;
        }
        if (in_array($g['phase'] ?? '', ['postPurchase', 'buyerConfirm', 'buyerSelectHukm'], true)) {
            $options = Purchase::options($g, $seat);
            if (!$options) return false;
            if (($g['phase'] ?? '') === 'buyerConfirm') $choice = 'confirmHukm';
            elseif (($g['phase'] ?? '') === 'buyerSelectHukm') {
                $choice = 'sun';
                foreach (Rules::SUITS as $suit) if ($suit !== ($g['upCard']['suit'] ?? null)) { $choice = $suit; break; }
            } else $choice = 'pass';
            Purchase::respond($g, $seat, $choice);
            return true;
        }
        if (($g['phase'] ?? '') === 'projectDeclaration') {
            if (!empty($g['projectDeclared'][$seat])) return false;
            Projects::declare($g, $seat, self::project($g, $seat));
            return true;
        }
        if (($g['phase'] ?? '') === 'playing') {
            Rules::play($g, $seat, self::card($g, $seat));
            return true;
        }
        return false;
    }

    private function __construct() {}
}

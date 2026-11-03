<?php
declare(strict_types=1);

namespace Baloot\Game;

/**
 * Deterministic, inexpensive bot policy ported from game/rules.js.
 * The caller is responsible for invoking this only for an automated seat.
 */
final class Bot
{
    public static function bid(array $g, int $seat): string
    {
        $hand = $g['hands'][$seat] ?? [];
        $upSuit = $g['upCard']['suit'] ?? '';
        $trump = array_values(array_filter($hand, fn($c) => $c['suit'] === $upSuit));
        $trumpValue = 0.0;
        foreach ($trump as $card) $trumpValue += ['J'=>6,'9'=>4,'A'=>2,'10'=>1,'K'=>1,'Q'=>0.5][$card['rank']] ?? 0;

        if (($g['phase'] ?? '') === 'auction1') {
            $sun = self::sunStrength($hand);
            if ($sun >= 6) return 'sun';
            return $trumpValue >= 5 ? 'take' : 'pass';
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
        return $bestValue >= 5 ? (($g['phase'] ?? '') === 'auction2' && $bestSuit !== 'sun' ? $bestSuit : (($g['phase'] ?? '') === 'auction2' ? 'takeSecond' : $bestSuit)) : 'pass';
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

    public static function card(array $g, int $seat): string
    {
        $hand = $g['hands'][$seat] ?? [];
        $legal = Rules::legal($hand, $g['trick'] ?? [], $g['contract'], $seat);
        if (!$legal) throw new \DomainException('لا توجد ورقة قانونية للبوت');
        if (count($legal) === 1) return $legal[0]['id'];

        $partnerWinning = !empty($g['trick'])
            && Rules::team(Rules::winner($g['trick'], $g['contract'])) === Rules::team($seat);
        $pointsOnTable = array_sum(array_map(fn($p) => Rules::points($p['card'], $g['contract']), $g['trick'] ?? []));
        usort($legal, function ($a, $b) use ($g, $seat, $partnerWinning, $pointsOnTable) {
            $value = function ($card) use ($g, $seat, $partnerWinning, $pointsOnTable) {
                $trial = $g['trick'];
                $trial[] = ['seat' => $seat, 'card' => $card];
                $wins = Rules::winner($trial, $g['contract']) === $seat;
                $isTrump = ($g['contract']['type'] ?? '') === 'hokum' && $card['suit'] === ($g['contract']['trump'] ?? null);
                return ($wins ? ($partnerWinning ? -20 : $pointsOnTable + Rules::points($card, $g['contract']) + 8) : 0)
                    + ($isTrump ? 2 : 0)
                    + Rules::rank($card, $g['contract']['trump'] ?? null) * 0.1;
            };
            return $value($a) <=> $value($b);
        });
        return $legal[0]['id'];
    }

    /**
     * Apply one automated action. A scheduler should persist the resulting state
     * atomically through RoomStore after this method returns.
     */
    public static function act(array &$g, int $seat): bool
    {
        if (($g['turn'] ?? -1) !== $seat) return false;
        if (in_array($g['phase'] ?? '', ['auction1', 'auction2'], true)) {
            Purchase::choose($g, $seat, self::bid($g, $seat));
            return true;
        }
        if (in_array($g['phase'] ?? '', ['postPurchase', 'buyerConfirm', 'buyerSelectHukm'], true)) {
            $options = Purchase::options($g, $seat);
            if (!$options) return false;
            if (($g['phase'] ?? '') === 'buyerConfirm') $choice = 'confirmHukm';
            elseif (($g['phase'] ?? '') === 'buyerSelectHukm') {
                $scores = [];
                foreach (Rules::SUITS as $suit) {
                    if ($suit === ($g['upCard']['suit'] ?? null)) continue;
                    $scores[$suit] = 0.0;
                    foreach ($g['hands'][$seat] as $card) {
                        if ($card['suit'] === $suit) $scores[$suit] += ['A'=>2,'10'=>1.4,'K'=>0.7,'Q'=>0.4,'J'=>0.5][$card['rank']] ?? 0;
                    }
                }
                arsort($scores);
                $choice = array_key_first($scores) ?? 'sun';
            } else $choice = 'pass';
            Purchase::respond($g, $seat, $choice);
            return true;
        }
        if (($g['phase'] ?? '') === 'projectDeclaration') {
            if (!empty($g['projectDeclared'][$seat])) return false;
            $project = self::project($g, $seat);
            if ($project !== 'pass') {
                foreach ($g['projectOptions'][$seat] as $candidate) {
                    if ($candidate['type'] === $project) { $g['projectClaims'][$seat] = [$candidate]; break; }
                }
            }
            $g['projectDeclared'][$seat] = true;
            if (!in_array(false, $g['projectDeclared'], true)) $g['phase'] = 'playing';
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

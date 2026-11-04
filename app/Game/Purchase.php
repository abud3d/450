<?php
declare(strict_types=1);

namespace Baloot\Game;

/**
 * PHP port of the auction and post-purchase state machine from game/rules.js.
 * All methods mutate the game state by reference and enforce turn ownership.
 */
final class Purchase
{
    public static function order(array $g): array
    {
        if (count($g['auctionOrder'] ?? []) === 4) return array_values($g['auctionOrder']);
        $order = [];
        $seat = Rules::next((int) $g['dealer']);
        for ($i = 0; $i < 4; $i++) {
            $order[] = $seat;
            $seat = Rules::next($seat);
        }
        return $order;
    }

    private static function normalizedOrder(array $g): array
    {
        return array_map(fn($seat) => $seat % 4, self::order($g));
    }

    private static function position(array $g, int $seat): int
    {
        return array_search($seat, self::normalizedOrder($g), true) + 1;
    }

    private static function before(array $g, int $seat): array
    {
        $order = self::normalizedOrder($g);
        $pos = array_search($seat, $order, true);
        return array_reverse(array_slice($order, 0, $pos));
    }

    private static function after(array $g, int $seat): array
    {
        $order = self::normalizedOrder($g);
        $pos = array_search($seat, $order, true);
        return array_merge(array_slice($order, $pos + 1), array_slice($order, 0, $pos));
    }

    private static function ashkalEligible(array $g, int $seat): bool
    {
        $buyer = $g['postPurchase']['buyer'] ?? $g['contract']['buyer'] ?? null;
        if (!is_int($buyer)) return false;
        $buyerPos = self::position($g, $buyer);
        $pos = self::position($g, $seat);
        $allowed = in_array($buyerPos, [1, 2], true) ? [3, 4] : ($buyerPos === 3 ? [4] : ($buyerPos === 4 ? [3] : []));
        return in_array($pos, $allowed, true);
    }

    private static function begin(array &$g, int $buyer, string $stage): void
    {
        $g['phase'] = 'postPurchase';
        $g['postPurchase'] = [
            'buyer' => $buyer, 'stage' => $stage, 'responses' => [],
            'queue' => self::after($g, $buyer),
            'sunCallerSeat' => null, 'ashkalCallerSeat' => null, 'doubleCallerSeat' => null,
        ];
        $g['turn'] = $g['postPurchase']['queue'][0] ?? $buyer;
    }

    private static function finish(array &$g, int $buyer, array $changes = []): void
    {
        $prior = $g['contract'] ?? [];
        $merged = array_merge($prior, $changes, ['buyer' => $buyer]);
        $pending = [
            'level' => $merged['level'] ?? 1,
            'doubleTeam' => $merged['doubleTeam'] ?? null,
            'buyerTeam' => $merged['buyerTeam'] ?? null,
            'restricted' => !empty($merged['restricted']),
            'originalBuyerTeam' => Rules::team($buyer),
        ];
        $g['contract'] = $merged;
        $g['postPurchase'] = null;
        $g['sunBeforeQueue'] = [];
        self::finishDeal($g);
        $g['contract']['level'] = $pending['level'];
        $g['contract']['doubleTeam'] = $pending['doubleTeam'];
        $g['contract']['restricted'] = $pending['restricted'];
        if (array_key_exists('purchaseType', $merged)) $g['contract']['purchaseType'] = $merged['purchaseType'];
        if (is_int($merged['cardReceiver'] ?? null)) $g['contract']['cardReceiver'] = $merged['cardReceiver'];
        if (is_int($pending['buyerTeam'])) $g['contract']['buyerTeam'] = $pending['buyerTeam'];
        $g['contract']['originalBuyerTeam'] = $pending['originalBuyerTeam'];
    }

    private static function finishSecondHukm(array &$g): void
    {
        $buyer = $g['postPurchase']['buyer'];
        $g['phase'] = 'postPurchase';
        $g['contract']['buyer'] = $buyer;
        $g['postPurchase']['stage'] = 'doubleResponse';
        $g['postPurchase']['queue'] = array_values(array_filter(
            self::after($g, $buyer),
            fn($seat) => Rules::team($seat) !== Rules::team($buyer)
        ));
        $g['turn'] = $g['postPurchase']['queue'][0] ?? $buyer;
        if (!$g['postPurchase']['queue']) self::finish($g, $buyer);
    }

    private static function beginSun(array &$g, int $caller, array $queue): void
    {
        $g['phase'] = 'postPurchase';
        $g['contract'] = array_merge($g['contract'] ?? [], [
            'type' => 'sun', 'trump' => null, 'buyer' => $caller,
            'purchaseType' => $g['contract']['purchaseType'] ?? 'sun',
        ]);
        $g['postPurchase']['stage'] = 'sunChallenge';
        $g['postPurchase']['sunCallerSeat'] = $caller;
        $g['postPurchase']['queue'] = array_values($queue);
        $g['turn'] = $g['postPurchase']['queue'][0] ?? $caller;
        if (!$g['postPurchase']['queue']) self::finish($g, $caller, ['type' => 'sun', 'trump' => null]);
    }

    public static function choose(array &$g, int $seat, string $choice): void
    {
        if (!in_array($g['phase'] ?? '', ['auction1', 'auction2'], true) || ($g['turn'] ?? -1) !== $seat) {
            throw new \DomainException('ليس دورك في الشراء');
        }
        if ($g['phase'] === 'auction1') {
            if ($choice === 'sun') {
                $g['contract'] = ['type' => 'sun', 'trump' => null, 'buyer' => $seat, 'purchaseType' => 'sun'];
                self::begin($g, $seat, 'sunChallenge');
                $g['postPurchase']['sunCallerSeat'] = $seat;
                $g['postPurchase']['queue'] = self::before($g, $seat);
                $g['turn'] = $g['postPurchase']['queue'][0] ?? $seat;
                if (!$g['postPurchase']['queue']) self::finish($g, $seat, ['type' => 'sun', 'trump' => null]);
                return;
            }
            if (in_array($choice, ['take', 'hokum'], true)) {
                $g['contract'] = ['type' => 'hokum', 'trump' => $g['upCard']['suit'], 'buyer' => $seat, 'purchaseRound' => 1];
                self::begin($g, $seat, 'responses');
                return;
            }
            if ($choice !== 'pass') throw new \DomainException('اختيار شراء غير صالح');
            $g['auctionCalls'][$seat] = 'بس';
            $g['auctionPasses']++;
            $g['turn'] = Rules::next($seat);
            if ($g['auctionPasses'] >= 4) {
                $g['auctionCalls'] = [null,null,null,null];
                $g['phase'] = 'auction2';
                $g['turn'] = Rules::next((int) $g['dealer']);
                $g['auctionPasses'] = 0;
            }
            return;
        }

        if ($choice === 'sun') {
            $g['contract'] = ['type' => 'sun', 'trump' => null, 'buyer' => $seat, 'purchaseRound' => 2, 'purchaseType' => 'sun'];
            self::begin($g, $seat, 'sunChallenge');
            $g['postPurchase']['sunCallerSeat'] = $seat;
            $g['postPurchase']['queue'] = self::before($g, $seat);
            $g['turn'] = $g['postPurchase']['queue'][0] ?? $seat;
            if (!$g['postPurchase']['queue']) self::finish($g, $seat, ['type' => 'sun', 'trump' => null]);
            return;
        }
        if (in_array($choice, ['takeSecond', 'hokum', 'take', 'S', 'H', 'D', 'C'], true)) {
            $g['contract'] = ['type' => 'hokum', 'trump' => null, 'buyer' => $seat, 'purchaseRound' => 2];
            self::begin($g, $seat, 'secondPasses');
            return;
        }
        if ($choice !== 'pass') throw new \DomainException('اختيار شراء غير صالح');
        $g['auctionCalls'][$seat] = 'ولا';
        $g['auctionPasses']++;
        $g['turn'] = Rules::next($seat);
        if ($g['auctionPasses'] >= 4) {
            // Four 'ولا' calls complete both auctions: deal immediately and rotate the dealer.
            $g['phase'] = 'redeal';
            Rules::nextRound($g);
        }
    }

    public static function options(array $g, int $seat): array
    {
        $p = $g['postPurchase'] ?? null;
        if (!$p) return [];
        if (($g['phase'] ?? '') === 'postPurchase') {
            if (($g['turn'] ?? -1) !== $seat) return [];
            switch ($p['stage']) {
                case 'responses':
                    $o = ['pass', 'sun'];
                    if (self::ashkalEligible($g, $seat)) $o[] = 'ashkal';
                    return $o;
                case 'secondPasses': return ['pass'];
                case 'sunChallenge':
                    $o = count($p['queue']) === 1 ? ['pass', 'takeBefore'] : ['pass', 'sun'];
                    $callerTeam = Rules::team((int) $p['sunCallerSeat']);
                    if (($g['scores'][Rules::team($seat)] ?? 0) < 100 && ($g['scores'][$callerTeam] ?? 0) > 100) $o[] = 'double';
                    return $o;
                case 'ashkalReplies': return ['pass', 'sun'];
                case 'doubleResponse': return ['pass', 'double'];
                case 'doubleBuyer': return ['pass', 'three'];
                case 'doubleCaller': return ['pass', 'four'];
                case 'doubleBuyerCoffee': return ['pass', 'coffee'];
            }
        }
        if (($g['phase'] ?? '') === 'buyerConfirm' && $seat === $p['buyer']) return ['confirmHukm', 'sun'];
        if (($g['phase'] ?? '') === 'buyerSelectHukm' && $seat === $p['buyer']) {
            return array_merge(array_values(array_filter(Rules::SUITS, fn($s) => $s !== ($g['upCard']['suit'] ?? null))), ['sun']);
        }
        return [];
    }

    private static function advance(array &$g): void
    {
        $p =& $g['postPurchase'];
        if ($p['queue']) { $g['turn'] = $p['queue'][0]; return; }
        switch ($p['stage']) {
            case 'responses': $g['phase'] = 'buyerConfirm'; $g['turn'] = $p['buyer']; return;
            case 'secondPasses': $g['phase'] = 'buyerSelectHukm'; $g['turn'] = $p['buyer']; return;
            case 'ashkalReplies': self::finish($g, (int) $p['ashkalCallerSeat'], ['type' => 'sun', 'trump' => null, 'buyer' => $p['ashkalCallerSeat']]); return;
            case 'doubleResponse': self::finish($g, (int) $p['buyer']); return;
            case 'sunChallenge': self::finish($g, (int) $p['sunCallerSeat'], ['type' => 'sun', 'trump' => null]); return;
        }
    }

    public static function respond(array &$g, int $seat, string $choice): void
    {
        if (!in_array($choice, self::options($g, $seat), true)) throw new \DomainException('هذا الخيار غير متاح لك الآن');
        $p =& $g['postPurchase'];
        if (($g['phase'] ?? '') === 'buyerConfirm') {
            if ($choice === 'confirmHukm') { self::finishSecondHukm($g); return; }
            self::beginSun($g, (int) $p['buyer'], self::before($g, (int) $p['buyer']));
            return;
        }
        if (($g['phase'] ?? '') === 'buyerSelectHukm') {
            if ($choice === 'sun') { self::beginSun($g, (int) $p['buyer'], self::before($g, (int) $p['buyer'])); return; }
            if (!in_array($choice, Rules::SUITS, true) || $choice === ($g['upCard']['suit'] ?? null)) throw new \DomainException('نوع الحكم غير متاح');
            $g['contract'] = array_merge($g['contract'], ['type' => 'hokum', 'trump' => $choice, 'buyer' => $p['buyer']]);
            self::finishSecondHukm($g);
            return;
        }

        if ($choice === 'pass') {
            if (in_array($p['stage'], ['responses', 'secondPasses', 'ashkalReplies', 'doubleResponse'], true)) {
                $p['responses'][$seat] = 'pass';
                array_shift($p['queue']);
                self::advance($g);
                return;
            }
            if ($p['stage'] === 'sunChallenge') { self::finish($g, (int) $p['sunCallerSeat'], ['type' => 'sun', 'trump' => null]); return; }
            if ($p['stage'] === 'doubleBuyer') { $g['contract']['level'] = 2; $g['contract']['restricted'] = true; self::finish($g, (int) $p['buyer']); return; }
            if ($p['stage'] === 'doubleCaller') { $g['contract']['level'] = 3; $g['contract']['restricted'] = true; self::finish($g, (int) $p['buyer']); return; }
            if ($p['stage'] === 'doubleBuyerCoffee') { $g['contract']['level'] = 4; $g['contract']['restricted'] = true; self::finish($g, (int) $p['buyer']); return; }
        }
        if ($choice === 'sun') {
            if ($p['stage'] === 'responses') { self::beginSun($g, $seat, self::before($g, $seat)); return; }
            if ($p['stage'] === 'ashkalReplies') {
                $remaining = array_slice($p['queue'], 1);
                $g['contract'] = array_merge($g['contract'], ['type' => 'sun', 'trump' => null, 'buyer' => $seat, 'purchaseType' => 'ashkal']);
                self::beginSun($g, $seat, $remaining);
                return;
            }
            if ($p['stage'] === 'sunChallenge') { self::beginSun($g, $seat, array_slice($p['queue'], 1)); return; }
        }
        if ($choice === 'ashkal') {
            $receiver = ($seat + 2) % 4;
            $g['contract'] = array_merge($g['contract'], ['buyer' => $seat, 'cardReceiver' => $receiver, 'purchaseType' => 'ashkal', 'ashkalCallerSeat' => $seat]);
            $p['ashkalCallerSeat'] = $seat;
            $p['stage'] = 'ashkalReplies';
            $p['queue'] = self::before($g, $seat);
            $g['turn'] = $p['queue'][0] ?? $seat;
            if (!$p['queue']) self::finish($g, $seat);
            return;
        }
        if ($choice === 'takeBefore') { self::finish($g, $seat, ['type' => 'sun', 'trump' => null, 'buyer' => $seat, 'buyerTeam' => Rules::team($seat), 'restricted' => false]); return; }
        if ($choice === 'double' && $p['stage'] === 'sunChallenge') {
            $team = Rules::team($seat);
            $g['contract']['level'] = 2; $g['contract']['doubleTeam'] = $team; $g['contract']['buyerTeam'] = $team; $g['contract']['restricted'] = false;
            self::finish($g, (int) $p['sunCallerSeat'], ['type' => 'sun', 'trump' => null, 'buyerTeam' => $team, 'level' => 2, 'doubleTeam' => $team, 'restricted' => false]);
            return;
        }
        if ($choice === 'double' && $p['stage'] === 'doubleResponse') {
            $team = Rules::team($seat);
            $g['contract']['level'] = 2; $g['contract']['doubleTeam'] = $team; $g['contract']['buyerTeam'] = $team; $g['contract']['doubleCallerSeat'] = $seat;
            $p['doubleCallerSeat'] = $seat; $p['stage'] = 'doubleBuyer'; $g['turn'] = $p['buyer']; return;
        }
        if ($choice === 'three') { $g['contract']['level'] = 3; $p['stage'] = 'doubleCaller'; $g['turn'] = $p['doubleCallerSeat']; return; }
        if ($choice === 'four') { $g['contract']['level'] = 4; $p['stage'] = 'doubleBuyerCoffee'; $g['turn'] = $p['buyer']; return; }
        if ($choice === 'coffee') { $g['contract']['level'] = 5; $g['contract']['restricted'] = false; self::finish($g, (int) $p['buyer']); return; }
        throw new \DomainException('اختيار غير صالح');
    }

    public static function expire(array &$g): void
    {
        $p = $g['postPurchase'] ?? null;
        if (!$p) return;
        if (($g['phase'] ?? '') === 'buyerConfirm') { self::finish($g, (int) $p['buyer']); return; }
        if (($g['phase'] ?? '') === 'buyerSelectHukm') return;
        if (in_array($p['stage'], ['responses', 'secondPasses', 'doubleResponse'], true)) {
            $g['postPurchase']['queue'] = [];
            self::advance($g);
            return;
        }
        if ($p['stage'] === 'sunChallenge') self::finish($g, (int) $p['sunCallerSeat'], ['type' => 'sun', 'trump' => null]);
        elseif ($p['stage'] === 'ashkalReplies') self::finish($g, (int) $p['ashkalCallerSeat'], ['type' => 'sun', 'trump' => null, 'buyer' => $p['ashkalCallerSeat']]);
        elseif ($p['stage'] === 'doubleBuyer') { $g['contract']['level'] = 2; $g['contract']['restricted'] = true; self::finish($g, (int) $p['buyer']); }
        elseif ($p['stage'] === 'doubleCaller') { $g['contract']['level'] = 3; $g['contract']['restricted'] = true; self::finish($g, (int) $p['buyer']); }
        elseif ($p['stage'] === 'doubleBuyerCoffee') { $g['contract']['level'] = 4; $g['contract']['restricted'] = true; self::finish($g, (int) $p['buyer']); }
    }


    public static function doubleOptions(array $g, int $seat): array
    {
        if (($g['phase'] ?? '') !== 'playing' || empty($g['contract']) || !empty($g['history']) || ($g['doubleWindow'] ?? true) === false) return [];
        $c = $g['contract'];
        if (!empty($c['purchaseRound']) || ($c['type'] ?? '') === 'sun') return [];
        $team = Rules::team($seat);
        $original = isset($c['originalBuyerTeam']) ? (int) $c['originalBuyerTeam'] : Rules::team((int) $c['buyer']);
        $level = (int) ($c['level'] ?? 1);
        if ($level === 1 && $team !== $original) return ['double'];
        if ($level === 2 && $team === $original) return ['three'];
        if ($level === 3 && $team === (int) ($c['doubleTeam'] ?? -1)) return ['four'];
        if ($level === 4 && $team === $original) return ['coffee'];
        return [];
    }

    public static function callDouble(array &$g, int $seat, string $call): void
    {
        if (!in_array($call, self::doubleOptions($g, $seat), true)) {
            throw new \DomainException('هذا التصعيد غير متاح لفريقك الآن');
        }
        $team = Rules::team($seat);
        if ($call === 'double') { $g['contract']['level'] = 2; $g['contract']['doubleTeam'] = $team; $g['contract']['buyerTeam'] = $team; }
        elseif ($call === 'three') { $g['contract']['level'] = 3; $g['contract']['buyerTeam'] = $team; }
        elseif ($call === 'four') { $g['contract']['level'] = 4; $g['contract']['buyerTeam'] = $team; }
        elseif ($call === 'coffee') { $g['contract']['level'] = 5; $g['contract']['buyerTeam'] = $team; }
        $g['contract']['lastCallerSeat'] = $seat;
    }

    private static function finishDeal(array &$g): void
    {
        $buyer = (int) $g['contract']['buyer'];
        $g['contract']['originalBuyerTeam'] = Rules::team($buyer);
        $g['contract']['buyerTeam'] = Rules::team($buyer);
        $g['contract']['level'] = 1;
        $g['contract']['doubleTeam'] = null;
        $receiver = is_int($g['contract']['cardReceiver'] ?? null) ? $g['contract']['cardReceiver'] : $buyer;
        $g['hands'][$receiver][] = $g['upCard'];
        $remaining = $g['stock'] ?? [];
        $k = 0;
        for ($n = 0; $n < 4; $n++) {
            $seat = (Rules::next((int) $g['dealer']) + $n) % 4;
            while (count($g['hands'][$seat]) < 8) {
                if (!isset($remaining[$k])) throw new \LogicException('أوراق التوزيع غير مكتملة');
                $g['hands'][$seat][] = $remaining[$k++];
            }
        }
        $suitOrder = $g['contract']['type'] === 'hokum'
            ? array_merge([$g['contract']['trump']], array_values(array_filter(Rules::SUITS, fn($s) => $s !== $g['contract']['trump'])))
            : Rules::SUITS;
        foreach ($g['hands'] as &$hand) {
            usort($hand, function ($a, $b) use ($suitOrder, $g) {
                $sa = array_search($a['suit'], $suitOrder, true); $sb = array_search($b['suit'], $suitOrder, true);
                $ta = $g['contract']['type'] === 'hokum' && $a['suit'] === $g['contract']['trump'] ? ['7','8','Q','K','10','A','9','J'] : ['7','8','9','J','Q','K','10','A'];
                $tb = $g['contract']['type'] === 'hokum' && $b['suit'] === $g['contract']['trump'] ? ['7','8','Q','K','10','A','9','J'] : ['7','8','9','J','Q','K','10','A'];
                return ($sa <=> $sb) ?: (array_search($a['rank'], $ta, true) <=> array_search($b['rank'], $tb, true));
            });
        }
        unset($hand);
        $g['initialHands'] = array_map(fn($h) => array_map(fn($c) => $c, $h), $g['hands']);
        $g['doubleWindow'] = true;
        $g['projectOptions'] = [];
        foreach ($g['initialHands'] as $seat => $hand) {
            $g['projectOptions'][$seat] = array_values(array_filter(Projects::detect($hand, $g['contract'], $seat), fn($p) => $p['type'] !== 'belot'));
        }
        $g['projectClaims'] = [[], [], [], []];
        $g['projectDeclared'] = array_map(fn($o) => count($o) === 0, $g['projectOptions']);
        $g['phase'] = count(array_filter($g['projectDeclared'], fn($v) => !$v)) === 0 ? 'playing' : 'projectDeclaration';
        $g['turn'] = Rules::next((int) $g['dealer']);
    }
}

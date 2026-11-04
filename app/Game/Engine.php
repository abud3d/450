<?php
declare(strict_types=1);

namespace Baloot\Game;

use DomainException;

/**
 * Framework-independent room/game orchestration.
 * Persistence and HTTP authorization remain the responsibility of the API layer.
 */
final class Engine
{
    public static function start(array &$room, int $requestingSeat): void
    {
        if (($room['game'] ?? null) !== null) throw new DomainException('اللعبة بدأت بالفعل');
        $players = $room['players'] ?? [];
        $owner = $room['ownerGuestId'] ?? null;
        if (!isset($players[$requestingSeat]) || !is_array($players[$requestingSeat])
            || !is_string($owner) || !hash_equals($owner, (string) ($players[$requestingSeat]['guestId'] ?? ''))) {
            throw new DomainException('صاحب الغرفة فقط يبدأ اللعبة');
        }
        $botStyles = ['professional', 'aggressive', 'cautious', 'analyst'];
        $usedBotNumbers = [];
        for ($seat = 0; $seat < 4; $seat++) {
            if (empty($players[$seat])) $players[$seat] = ['name' => 'كمبيوتر', 'seat' => $seat, 'bot' => true, 'guestId' => null];
            $players[$seat]['seat'] = $seat;
            $players[$seat]['bot'] = !empty($players[$seat]['bot']);
            if ($players[$seat]['bot']) {
                $number = (int) ($players[$seat]['botNumber'] ?? 0);
                if ($number < 1 || $number > 99 || in_array($number, $usedBotNumbers, true)) {
                    $number = random_int(1, 99);
                    for ($attempt = 0; in_array($number, $usedBotNumbers, true) && $attempt < 99; $attempt++) $number = ($number % 99) + 1;
                }
                $usedBotNumbers[] = $number;
                $players[$seat]['botNumber'] = $number;
                $style = (string) ($players[$seat]['botStyle'] ?? '');
                if (!in_array($style, $botStyles, true)) $style = $botStyles[random_int(0, 3)];
                $players[$seat]['botStyle'] = $style;
                $players[$seat]['name'] = 'كمبيوتر ' . $number;
            }
        }
        $game = Rules::create(array_map(fn($p) => (string) ($p['name'] ?? 'ضيف'), $players));
        $game['players'] = array_values($players);
        $game['qaidEnabled'] = !empty($room['qaidEnabled']);
        Rules::deal($game);
        $room['players'] = array_values($players);
        $room['game'] = $game;
        TurnClock::arm($room, 10);
        $room['lastAction'] = 'وُزعت خمس أوراق لكل لاعب وبدأ الشراء.';
        self::queueBotTurn($room);
    }

    public static function apply(array &$room, int $seat, string $action, array $input = []): array
    {
        if (!isset($room['game']) || !is_array($room['game'])) throw new DomainException('اللعبة لم تبدأ بعد');
        if (!isset($room['players'][$seat]) || !is_array($room['players'][$seat])) throw new DomainException('المقعد غير معروف');
        if (!empty($room['players'][$seat]['botControl']) && $action !== 'timeout_return') throw new DomainException('البوت يدير هذا المقعد حالياً');
        $g =& $room['game'];
        if (isset($room['qaidWindow']) && !in_array($action, ['qaid_claim','qaid_cancel'], true)) {
            throw new DomainException('اللعب متوقف حتى يختار فاتح القيد؛ لا يمكن تنفيذ حركات أخرى.');
        }
        if (!in_array($action, ['timeout_return','timeout_bot'], true)) unset($room['timeoutSeat'], $room['timeoutGraceUntil']);

        switch ($action) {
            case 'bid':
                Purchase::choose($g, $seat, (string) ($input['choice'] ?? ''));
                $room['lastAction'] = 'تم تسجيل قرار الشراء.';
                break;
            case 'post_purchase':
                Purchase::respond($g, $seat, (string) ($input['choice'] ?? ''));
                $room['lastAction'] = 'تم تسجيل رد اللاعب.';
                break;
            case 'double':
                Purchase::callDouble($g, $seat, (string) ($input['call'] ?? ''));
                $room['lastAction'] = 'تم تسجيل التصعيد.';
                break;
            case 'project':
                Projects::declare($g, $seat, (string) ($input['projectType'] ?? 'pass'));
                $room['lastAction'] = 'تم تسجيل إعلان المشروع.';
                break;
            case 'play':
                $beforeTricks = count($g['tricks'] ?? []);
                Rules::play($g, $seat, (string) ($input['cardId'] ?? ''));
                if (count($g['tricks'] ?? []) > $beforeTricks) $room['trickVisibleUntil'] = time() + (in_array($g['phase'], ['roundEnd','gameEnd'], true) ? 6 : 2);
                if (in_array($g['phase'], ['roundEnd','gameEnd'], true)) $room['roundResultReadyAt'] = time() + 6;
                $room['lastAction'] = 'تم لعب الورقة.';
                break;
            case 'qaid_claim':
                if (($room['qaidWindow']['seat'] ?? -1) !== $seat || (int) ($room['qaidWindow']['expiresAt'] ?? 0) <= time()) {
                    throw new DomainException('انتهت مهلة كشف أوراق القيد؛ افتح الطلب من جديد');
                }
                $result = Qaid::decide($g, $seat);
                unset($room['qaidWindow']);
                $room['roundResultReadyAt'] = time();
                $room['lastAction'] = 'تم حسم طلب القيد.';
                return ['ok' => true, 'result' => $result];
            case 'qaid_open':
                if (empty($room['qaidEnabled']) || !in_array($g['phase'], ['playing','roundEnd','gameEnd'], true)) {
                    throw new DomainException('طلب القيد غير متاح الآن');
                }
                if (isset($room['qaidWindow'])) throw new DomainException('هناك طلب قيد جارٍ بالفعل');
                $room['qaidWindow'] = ['seat' => $seat, 'expiresAt' => time() + 60];
                $room['lastAction'] = 'بدأ كشف أوراق القيد.';
                break;
            case 'qaid_cancel':
                if (($room['qaidWindow']['seat'] ?? -1) !== $seat || (int) ($room['qaidWindow']['expiresAt'] ?? 0) <= time()) {
                    throw new DomainException('انتهت مهلة كشف أوراق القيد.');
                }
                $result = Qaid::decide($g, $seat);
                unset($room['qaidWindow']);
                $room['roundResultReadyAt'] = time();
                $room['lastAction'] = 'أُغلق كشف القيد وتم احتساب النتيجة.';
                return ['ok' => true, 'result' => $result];
            case 'next':
                if (!hash_equals((string) ($room['ownerGuestId'] ?? ''), (string) ($room['players'][$seat]['guestId'] ?? ''))) throw new DomainException('صاحب الغرفة فقط يبدأ التوزيعة التالية');
                Rules::nextRound($g);
                $room['lastAction'] = 'بدأت توزيعة جديدة.';
                break;
            case 'continue':
                if (($g['phase'] ?? '') !== 'roundEnd' || time() < (int) ($room['roundResultReadyAt'] ?? 0)) throw new DomainException('النشرة غير جاهزة للمتابعة');
                Rules::nextRound($g);
                $room['roundResultReadyAt'] = 0;
                $room['lastAction'] = 'بدأت التوزيعة التالية.';
                break;
            case 'timeout_return':
                if (($room['timeoutSeat'] ?? $seat) !== $seat && empty($room['players'][$seat]['botControl'])) throw new DomainException('لا يوجد طلب عودة لهذا المقعد');
                $room['players'][$seat]['botControl'] = false;
                unset($room['timeoutSeat']);
                $room['lastAction'] = 'عاد اللاعب لمقعده.';
                break;
            case 'timeout_bot':
                if (($room['timeoutSeat'] ?? -1) !== $seat) throw new DomainException('انتهت مهلة الاختيار أو لم يحن وقتها');
                $room['players'][$seat]['botControl'] = true;
                unset($room['timeoutSeat']);
                $room['lastAction'] = 'البوت يكمل اللعب بدلاً من اللاعب.';
                break;
            case 'replay':
                if (($g['phase'] ?? '') !== 'gameEnd') throw new DomainException('إعادة اللعب متاحة بعد انتهاء الصكة فقط');
                $names = array_map(fn($p) => (string) ($p['name'] ?? 'ضيف'), $room['players']);
                $new = Rules::create($names);
                $new['players'] = $room['players'];
                $new['qaidEnabled'] = !empty($room['qaidEnabled']);
                Rules::deal($new);
                $room['game'] = $new;
                $room['lastAction'] = 'بدأت صكة جديدة بنفس المقاعد.';
                break;
            default:
                throw new DomainException('نوع العملية غير مدعوم');
        }

        if (isset($room['qaidWindow'])) {
            TurnClock::clear($room);
        } else {
            self::queueBotTurn($room);
            if (in_array($g['phase'] ?? '', ['auction1','auction2','postPurchase','buyerConfirm','buyerSelectHukm','projectDeclaration','playing'], true)) TurnClock::arm($room, 10);
            else TurnClock::clear($room);
        }
        return ['ok' => true];
    }

    public static function advanceBots(array &$room, int $limit = 1): int
    {
        unset($room['botActionAt']);
        if (!isset($room['game']) || isset($room['qaidWindow'])) return 0;
        $count = 0;
        while ($count < max(1, min(32, $limit))) {
            $g =& $room['game'];
            $phase = $g['phase'] ?? '';
            if ($phase === 'redeal') {
                Rules::nextRound($g);
                $count++;
                continue;
            }
            if ($phase === 'projectDeclaration') {
                $seat = null;
                foreach ($room['players'] as $i => $player) {
                    if (!empty($player['bot']) || !empty($player['botControl'])) {
                        if (empty($g['projectDeclared'][$i])) { $seat = (int) $i; break; }
                    }
                }
                if ($seat === null) break;
            } elseif (in_array($phase, ['buyerConfirm','buyerSelectHukm'], true)) {
                $seat = (int) ($g['postPurchase']['buyer'] ?? -1);
                if ($seat < 0 || (empty($room['players'][$seat]['bot']) && empty($room['players'][$seat]['botControl']))) break;
            } elseif (in_array($phase, ['auction1','auction2','postPurchase','playing'], true)) {
                $seat = (int) ($g['turn'] ?? -1);
                if ($seat < 0 || (empty($room['players'][$seat]['bot']) && empty($room['players'][$seat]['botControl']))) break;
            } else break;

            $beforeTricks = count($g['tricks'] ?? []);
            $acted = false;
            try {
                $acted = Bot::act($g, $seat);
            } catch (\Throwable $error) {
                error_log('Baloot primary bot failed: ' . $error->getMessage());
            }

            if (!$acted) {
                $retrySeat = (int) ($room['botRetrySeat'] ?? -1);
                $retryCount = (int) ($room['botRetryCount'] ?? 0);
                if ($retrySeat !== $seat || $retryCount < 1) {
                    $room['botRetrySeat'] = $seat;
                    $room['botRetryCount'] = 1;
                    $room['botActionAt'] = time() + 5;
                    $room['lastAction'] = 'تأخر البوت؛ سيتولى بوت احتياطي الحركة بعد 5 ثوانٍ.';
                    TurnClock::arm($room, 35);
                    return 1;
                }
                try {
                    $acted = Bot::fallbackAct($g, $seat);
                } catch (\Throwable $error) {
                    error_log('Baloot backup bot failed: ' . $error->getMessage());
                }
                if (!$acted) {
                    // Keep a bounded retry cadence instead of clearing the only timer.
                    $room['botRetrySeat'] = $seat;
                    $room['botRetryCount'] = $retryCount + 1;
                    $room['botActionAt'] = time() + 5;
                    $room['lastAction'] = 'البوت الاحتياطي يعيد المحاولة بعد 5 ثوانٍ.';
                    TurnClock::arm($room, 35);
                    return 1;
                }
            }

            unset($room['botRetrySeat'], $room['botRetryCount']);
            if (count($g['tricks'] ?? []) > $beforeTricks) {
                $room['trickVisibleUntil'] = time() + (in_array($g['phase'], ['roundEnd','gameEnd'], true) ? 6 : 2);
            }
            if (in_array($g['phase'], ['roundEnd','gameEnd'], true)) $room['roundResultReadyAt'] = time() + 6;
            $count++;
        }
        if ($count > 0) $room['lastAction'] = 'اتخذ البوت قراراً تلقائياً.';
        if (in_array($room['game']['phase'] ?? '', ['auction1','auction2','postPurchase','buyerConfirm','buyerSelectHukm','projectDeclaration','playing'], true)) TurnClock::arm($room, 30);
        else TurnClock::clear($room);
        self::queueBotTurn($room);
        return $count;
    }

    /** Schedule, rather than chain, the next computer action so every card is visible. */
    public static function queueBotTurn(array &$room): void
    {
        unset($room['botActionAt'], $room['autoLastCardAt']);
        if (!isset($room['game']) || isset($room['qaidWindow'])) return;
        $g = $room['game'];
        $phase = $g['phase'] ?? '';
        if ($phase === 'playing') {
            $turnSeat = (int) ($g['turn'] ?? -1);
            if ($turnSeat >= 0 && count($g['hands'][$turnSeat] ?? []) === 1) {
                $room['autoLastCardAt'] = time() + 1;
                return;
            }
        }
        $seat = null;
        if ($phase === 'projectDeclaration') {
            foreach ($room['players'] ?? [] as $i => $player) {
                if ((!empty($player['bot']) || !empty($player['botControl'])) && empty($g['projectDeclared'][$i])) { $seat = (int) $i; break; }
            }
        } elseif (in_array($phase, ['buyerConfirm','buyerSelectHukm'], true)) {
            $seat = (int) ($g['postPurchase']['buyer'] ?? -1);
        } elseif (in_array($phase, ['auction1','auction2','postPurchase','playing'], true)) {
            $seat = (int) ($g['turn'] ?? -1);
        }
        if ($seat !== null && $seat >= 0 && (!empty($room['players'][$seat]['bot']) || !empty($room['players'][$seat]['botControl']))) {
            if (isset($room['botRetrySeat']) && (int) $room['botRetrySeat'] !== $seat) {
                unset($room['botRetrySeat'], $room['botRetryCount']);
            }
            $pause = max(1, min(6, (int) ($room['trickVisibleUntil'] ?? 0) - time()));
            $room['botActionAt'] = time() + $pause;
        }
    }

    /** Play a player's sole remaining card after the short reveal delay. */
    public static function advanceAutoLastCard(array &$room): bool
    {
        if (!isset($room['game']) || isset($room['qaidWindow'])
            || (int) ($room['autoLastCardAt'] ?? PHP_INT_MAX) > time()) return false;
        $g =& $room['game'];
        $seat = (int) ($g['turn'] ?? -1);
        if (($g['phase'] ?? '') !== 'playing' || $seat < 0 || count($g['hands'][$seat] ?? []) !== 1) {
            unset($room['autoLastCardAt']);
            self::queueBotTurn($room);
            return false;
        }
        unset($room['autoLastCardAt']);
        $beforeTricks = count($g['tricks'] ?? []);
        Rules::play($g, $seat, (string) $g['hands'][$seat][0]['id']);
        if (count($g['tricks'] ?? []) > $beforeTricks) {
            $room['trickVisibleUntil'] = time() + (in_array($g['phase'], ['roundEnd','gameEnd'], true) ? 6 : 2);
        }
        if (in_array($g['phase'], ['roundEnd','gameEnd'], true)) $room['roundResultReadyAt'] = time() + 6;
        $room['lastAction'] = 'لُعبت الورقة الأخيرة تلقائياً.';
        if (in_array($g['phase'], ['auction1','auction2','postPurchase','buyerConfirm','buyerSelectHukm','projectDeclaration','playing'], true)) TurnClock::arm($room, 10);
        else TurnClock::clear($room);
        self::queueBotTurn($room);
        return true;
    }

    public static function snapshot(array $room, int $seat): array
    {
        $g = $room['game'] ?? null;
        $players = [];
        foreach ($room['players'] ?? [] as $i => $p) {
            $players[] = [
                'seat' => $i,
                'name' => $p['name'] ?? 'بانتظار لاعب',
                'bot' => !empty($p['bot']) || !empty($p['botControl']),
                'botControl' => !empty($p['botControl']),
                'botNumber' => !empty($p['bot']) ? (int) ($p['botNumber'] ?? ($i + 10)) : null,
                'botStyle' => !empty($p['bot']) ? (string) ($p['botStyle'] ?? 'professional') : null,
                'botStyleLabel' => !empty($p['bot']) ? Bot::styleLabel((string) ($p['botStyle'] ?? 'professional')) : null,
                'connected' => !empty($p['connected']),
                'empty' => empty($p),
                'isOwner' => !empty($p['guestId']) && hash_equals((string) ($room['ownerGuestId'] ?? ''), (string) $p['guestId']),
            ];
        }
        if (!is_array($g)) {
            return [
                'code' => $room['code'] ?? '',
                'players' => $players,
                'phase' => 'lobby',
                'mySeat' => $seat,
                'scores' => [0, 0],
                'runningRaw' => [0, 0],
                'hand' => [],
                'handCounts' => [0, 0, 0, 0],
                'trick' => [],
                'tricksPlayed' => 0,
                'turn' => 0,
                'dealer' => 0,
                'qaidEnabled' => !empty($room['qaidEnabled']),
                'qaidHistory' => [],
                'qaidWindowActive' => false,
                'qaidResult' => null,
                'lastAction' => $room['lastAction'] ?? '',
            ];
        }
        $history = [];
        $runningRaw = [0,0];
        $tricksWonBySeat = [0,0,0,0];
        foreach ($g['tricks'] ?? [] as $trickRecord) {
            $runningRaw[$trickRecord['team']] += (int) ($trickRecord['points'] ?? 0);
            $tricksWonBySeat[$trickRecord['winner']]++;
        }
        $currentTrickPoints = array_sum(array_map(fn($play) => Rules::points($play['card'], $g['contract'] ?? ['type'=>'sun']), $g['trick'] ?? []));
        $lastLandTeam = isset($g['lastWinner']) ? Rules::team((int) $g['lastWinner']) : null;
        $resultReadyAt = (int) ($room['roundResultReadyAt'] ?? 0);
        $trickVisible = (int) ($room['trickVisibleUntil'] ?? 0) > time();
        $projectDisplayBySeat = [[],[],[],[]];
        $announcedProjectsBySeat = [[],[],[],[]];
        // During trick one, reveal a project's name only after its owner has
        // actually played a card. The card faces are shown during trick two.
        if (($g['phase'] ?? '') === 'playing' && count($g['tricks'] ?? []) === 0
            && !empty($g['projectClaims'])) {
            foreach ($g['projectClaims'] as $projectSeat => $claims) {
                foreach ($g['trick'] ?? [] as $play) {
                    if ((int) ($play['seat'] ?? -1) === (int) $projectSeat) {
                        $announcedProjectsBySeat[$projectSeat] = $claims;
                        break;
                    }
                }
            }
        }
        if (($g['phase'] ?? '') === 'playing' && count($g['tricks'] ?? []) === 1
            && !empty($g['projectClaims'])) {
            $resolvedProjects = Projects::resolve(
                $g['projectClaims'],
                (int) ($g['dealer'] ?? 0),
                (string) ($g['contract']['type'] ?? 'sun')
            );
            if (is_int($resolvedProjects['winnerTeam'] ?? null)) {
                foreach ($g['projectClaims'] as $projectSeat => $claims) {
                    $playedInCurrentTrick = false;
                    foreach ($g['trick'] ?? [] as $play) {
                        if ((int) ($play['seat'] ?? -1) === (int) $projectSeat) { $playedInCurrentTrick = true; break; }
                    }
                    if ((int) $projectSeat % 2 === $resolvedProjects['winnerTeam'] && $playedInCurrentTrick) {
                        $projectDisplayBySeat[$projectSeat] = array_values(array_filter(
                            $claims,
                            static fn($project) => ($project['type'] ?? '') !== 'belot'
                        ));
                    }
                }
            }
        }
        // Never expose the proof card from a hidden hand before the claimant opens the qaid review.
        $canReviewQaid = (int) ($room['qaidWindow']['seat'] ?? -1) === $seat;
        foreach ($g['history'] ?? [] as $index => $play) {
            $history[] = [
                'historyIndex' => $index, 'seat' => $play['seat'],
                'playerName' => (string) ($players[(int) $play['seat']]['name'] ?? ('لاعب ' . ((int) $play['seat'] + 1))),
                'card' => $play['card'], 'proofCard' => $canReviewQaid ? ($play['proofCard'] ?? null) : null, 'violation' => !empty($play['violation']),
                'leadSuit' => $play['leadSuit'] ?? null, 'trickIndex' => $play['trickIndex'] ?? 0,
            ];
        }
        $qaidGroups = [];
        foreach ($history as $play) $qaidGroups[$play['trickIndex']][] = $play;
        $qaidHistory = [];
        foreach ($qaidGroups as $trickIndex => $cards) $qaidHistory[] = ['trickIndex' => (int) $trickIndex, 'cards' => $cards];
        $legalCardIds = [];
        if (($g['phase'] ?? '') === 'playing' && (int) ($g['turn'] ?? -1) === $seat) {
            $hand = $g['hands'][$seat] ?? [];
            $legalCardIds = array_column(Rules::legal($hand, $g['trick'] ?? [], $g['contract'] ?? ['type' => 'sun'], $seat), 'id');
            if (!empty($room['qaidEnabled']) && !empty($g['trick'])) {
                $leadSuit = $g['trick'][0]['card']['suit'] ?? null;
                $hasLeadSuit = $leadSuit !== null && (bool) array_filter($hand, static fn($card) => ($card['suit'] ?? null) === $leadSuit);
                if ($hasLeadSuit) foreach ($hand as $card) {
                    if (($card['suit'] ?? null) !== $leadSuit) $legalCardIds[] = $card['id'];
                }
            }
            $legalCardIds = array_values(array_unique($legalCardIds));
        }
        return [
            'code' => $room['code'] ?? '',
            'players' => $players,
            'phase' => $g['phase'],
            'turn' => $g['turn'] ?? 0,
            'auctionCalls' => $g['auctionCalls'] ?? [null,null,null,null],
            'legalCardIds' => $legalCardIds,
            'contract' => $g['contract'] ?? null,
            'upCard' => $g['upCard'] ?? null,
            'qaidEnabled' => !empty($room['qaidEnabled']),
            'qaidHistory' => !empty($room['qaidEnabled']) && $canReviewQaid && empty($g['qaidResult']) && in_array($g['phase'], ['playing','roundEnd','gameEnd'], true) ? $qaidHistory : [],
            'qaidReady' => !empty($room['qaidEnabled']) && in_array($g['phase'], ['playing','roundEnd','gameEnd'], true) && empty($g['qaidResult']),
            'qaidDeadline' => ($room['qaidWindow']['seat'] ?? -1) === $seat ? ($room['qaidWindow']['expiresAt'] ?? null) : null,
            'qaidResult' => $g['qaidResult'] ?? null,
            'dealer' => $g['dealer'] ?? 0,
            'buyer' => $g['contract']['buyer'] ?? null,
            'lastLandTeam' => in_array($g['phase'], ['roundEnd','gameEnd'], true) ? $lastLandTeam : null,
            'lastLandPoints' => in_array($g['phase'], ['roundEnd','gameEnd'], true) ? 10 : 0,
            'tricksPlayed' => count($g['tricks'] ?? []),
            'runningRaw' => $runningRaw,
            'currentTrickPoints' => $currentTrickPoints,
            'tricksWonBySeat' => $tricksWonBySeat,
            'lastTrick' => $trickVisible && !empty($g['tricks']) ? $g['tricks'][count($g['tricks']) - 1]['plays'] : [],
            'trickClearing' => $trickVisible,
            'resultVisible' => in_array($g['phase'], ['roundEnd','gameEnd'], true) && ($resultReadyAt === 0 || time() >= $resultReadyAt),
            'hand' => array_values($g['hands'][$seat] ?? []),
            'handCounts' => array_map('count', $g['hands'] ?? [[],[],[],[]]),
            'trick' => $g['trick'] ?? [],
            'tricks' => $g['tricks'] ?? [],
            'scores' => $g['scores'] ?? [0,0],
            'lastRaw' => $g['lastRaw'] ?? null,
            'lastAwarded' => $g['lastAwarded'] ?? null,
            'lastScoring' => $g['lastScoring'] ?? null,
            'dealScores' => $g['dealScores'] ?? [],
            'tricksWon' => $g['tricksWon'] ?? [0,0],
            'mySeat' => $seat,
            'myProjects' => ($g['tricks'] ?? []) === [] ? ($g['projectClaims'][$seat] ?? []) : [],
            'projectsBySeat' => $announcedProjectsBySeat,
            'projectDisplayBySeat' => $projectDisplayBySeat,
            'projectOptions' => $g['projectOptions'][$seat] ?? [],
            'projectDeclared' => $g['projectDeclared'] ?? [false,false,false,false],
            'doubleOptions' => Purchase::doubleOptions($g, $seat),
            'postPurchaseOptions' => Purchase::options($g, $seat),
            'postPurchaseBuyer' => $g['postPurchase']['buyer'] ?? $g['contract']['buyer'] ?? null,
            'postPurchaseTurn' => $g['turn'] ?? null,
            'timeoutPrompt' => ($room['timeoutSeat'] ?? -1) === $seat,
            'qaidWindowActive' => isset($room['qaidWindow']),
            'lastAction' => $room['lastAction'] ?? '',
        ];
    }

    private function __construct() {}
}

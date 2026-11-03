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
        for ($seat = 0; $seat < 4; $seat++) {
            if (empty($players[$seat])) {
                $players[$seat] = ['name' => 'كمبيوتر ' . ($seat + 10), 'seat' => $seat, 'bot' => true, 'botNumber' => $seat + 10, 'guestId' => null];
            }
            $players[$seat]['seat'] = $seat;
            $players[$seat]['bot'] = !empty($players[$seat]['bot']);
        }
        $game = Rules::create(array_map(fn($p) => (string) ($p['name'] ?? 'ضيف'), $players));
        $game['players'] = array_values($players);
        $game['qaidEnabled'] = !empty($room['qaidEnabled']);
        Rules::deal($game);
        $room['players'] = array_values($players);
        $room['game'] = $game;
        $room['lastAction'] = 'وُزعت خمس أوراق لكل لاعب وبدأ الشراء.';
    }

    public static function apply(array &$room, int $seat, string $action, array $input = []): array
    {
        if (!isset($room['game']) || !is_array($room['game'])) throw new DomainException('اللعبة لم تبدأ بعد');
        if (!isset($room['players'][$seat]) || !is_array($room['players'][$seat])) throw new DomainException('المقعد غير معروف');
        if (!empty($room['players'][$seat]['botControl'])) throw new DomainException('البوت يدير هذا المقعد حالياً');
        $g =& $room['game'];

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
                if (($room['qaidWindow']['seat'] ?? -1) !== $seat || (int) ($room['qaidWindow']['expiresAt'] ?? 0) < time()) {
                    throw new DomainException('انتهت مهلة كشف أوراق القيد؛ افتح الطلب من جديد');
                }
                $result = Qaid::claim($g, $seat, (int) ($input['violationIndex'] ?? -1), (int) ($input['proofIndex'] ?? -1));
                unset($room['qaidWindow']);
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
                if (($room['qaidWindow']['seat'] ?? -1) !== $seat) throw new DomainException('لا يوجد طلب قيد لهذا المقعد');
                unset($room['qaidWindow']);
                $room['lastAction'] = 'أُغلق كشف القيد.';
                break;
            case 'next':
                if ($seat !== 0) throw new DomainException('صاحب الغرفة فقط يبدأ التوزيعة التالية');
                Rules::nextRound($g);
                $room['lastAction'] = 'بدأت توزيعة جديدة.';
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

        if (!isset($room['qaidWindow'])) self::advanceBots($room);
        return ['ok' => true];
    }

    public static function advanceBots(array &$room, int $limit = 16): int
    {
        if (!isset($room['game']) || isset($room['qaidWindow'])) return 0;
        $count = 0;
        while ($count < max(1, min(32, $limit))) {
            $g =& $room['game'];
            $phase = $g['phase'] ?? '';
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

            if (!Bot::act($g, $seat)) break;
            $count++;
        }
        if ($count > 0) $room['lastAction'] = 'اتخذ البوت قراراً تلقائياً.';
        return $count;
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
                'connected' => !empty($p['connected']),
                'empty' => empty($p),
                'isOwner' => !empty($p['guestId']) && hash_equals((string) ($room['ownerGuestId'] ?? ''), (string) $p['guestId']),
            ];
        }
        if (!is_array($g)) {
            return ['code' => $room['code'] ?? '', 'players' => $players, 'phase' => 'lobby', 'lastAction' => $room['lastAction'] ?? ''];
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
        foreach ($g['history'] ?? [] as $index => $play) {
            $history[] = [
                'historyIndex' => $index, 'seat' => $play['seat'],
                'card' => $play['card'], 'violation' => !empty($play['violation']),
                'leadSuit' => $play['leadSuit'] ?? null, 'trickIndex' => $play['trickIndex'] ?? 0,
            ];
        }
        return [
            'code' => $room['code'] ?? '',
            'players' => $players,
            'phase' => $g['phase'],
            'turn' => $g['turn'] ?? 0,
            'contract' => $g['contract'] ?? null,
            'upCard' => $g['upCard'] ?? null,
            'qaidEnabled' => !empty($room['qaidEnabled']),
            'qaidHistory' => !empty($room['qaidEnabled']) ? $history : [],
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
            'projectsBySeat' => ($g['tricks'] ?? []) === [] ? ($g['projectClaims'] ?? [[],[],[],[]]) : [[],[],[],[]],
            'projectDisplayBySeat' => [[],[],[],[]],
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

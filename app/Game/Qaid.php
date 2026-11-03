<?php
declare(strict_types=1);

namespace Baloot\Game;

final class Qaid
{
    public static function claim(array &$game, int $claimantSeat, int $violationIndex, int $proofIndex): array
    {
        if (empty($game['qaidEnabled']) || !in_array($game['phase'] ?? '', ['playing','roundEnd','gameEnd'], true)) {
            throw new \DomainException('طلب القيد غير متاح الآن');
        }
        if ($claimantSeat < 0 || $claimantSeat > 3) throw new \DomainException('مقعد المطالب غير صحيح');
        $first = $game['history'][$violationIndex] ?? null;
        if (!is_array($first) || $first['seat'] === $claimantSeat || Rules::team((int) $first['seat']) === Rules::team($claimantSeat)) {
            throw new \DomainException('لا يمكنك طلب القيد على لاعب من فريقك أو بورقة غير موجودة');
        }
        if (!empty($game['qaidResult'])) throw new \DomainException('تم حسم طلب القيد لهذه التوزيعة مسبقاً');
        if (empty($first['violation'])) throw new \DomainException('الورقة المختارة ليست مخالفة مسجلة');

        $previous = $game['lastAwarded'] ?? [0, 0];
        if (in_array($game['phase'], ['roundEnd','gameEnd'], true)) {
            foreach ([0,1] as $team) $game['scores'][$team] = ($game['scores'][$team] ?? 0) - ($previous[$team] ?? 0);
        }

        $winnerTeam = Rules::team($claimantSeat);
        $claims = array_merge($game['projectClaims'] ?? [[],[],[],[]], [$game['balotClaims'] ?? []]);
        $scored = Scoring::score([0,0], $game['contract'], $claims, (int) ($game['dealer'] ?? 0), [0,0]);
        $base = ($game['contract']['type'] ?? 'sun') === 'sun' ? 26 : 16;
        $total = $base + array_sum($scored['scoredProjectPoints'] ?? [0,0]);
        $awarded = [0,0];
        $awarded[$winnerTeam] = $total;
        $game['scores'][$winnerTeam] = ($game['scores'][$winnerTeam] ?? 0) + $total;

        $buyerTeam = isset($game['contract']['buyerTeam'])
            ? (int) $game['contract']['buyerTeam']
            : Rules::team((int) $game['contract']['buyer']);
        $game['lastAwarded'] = $awarded;
        $game['qaidResult'] = [
            'claimantSeat' => $claimantSeat,
            'accusedSeat' => (int) $first['seat'],
            'winnerTeam' => $winnerTeam,
            'success' => true,
            'violationIndex' => $violationIndex,
            'proofIndex' => $proofIndex,
            'total' => $total,
            'base' => $base,
        ];
        $game['lastScoring'] = array_merge(
            in_array($game['phase'], ['roundEnd','gameEnd'], true) ? ($game['lastScoring'] ?? []) : [],
            [
                'raw' => in_array($game['phase'], ['roundEnd','gameEnd'], true)
                    ? ($game['lastScoring']['raw'] ?? $game['lastRaw'] ?? [0,0]) : [0,0],
                'projects' => in_array($game['phase'], ['roundEnd','gameEnd'], true)
                    ? ($game['lastScoring']['projects'] ?? $scored['projects']) : $scored['projects'],
                'awarded' => $awarded,
                'buyerSucceeded' => $winnerTeam === $buyerTeam,
                'buyerFailed' => $winnerTeam !== $buyerTeam,
                'roundWinner' => $winnerTeam,
                'qaid' => true,
                'scoredProjectPoints' => $scored['scoredProjectPoints'] ?? [0,0],
            ]
        );

        $game['phase'] = (($game['scores'][0] ?? 0) >= 152 || ($game['scores'][1] ?? 0) >= 152)
            && ($game['scores'][0] ?? 0) !== ($game['scores'][1] ?? 0) ? 'gameEnd' : 'roundEnd';
        if ($game['phase'] === 'gameEnd') $game['winner'] = ($game['scores'][0] ?? 0) > ($game['scores'][1] ?? 0) ? 0 : 1;
        return $game['qaidResult'];
    }

    private function __construct() {}
}

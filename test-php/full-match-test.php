<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/Game/Seating.php';
require_once __DIR__ . '/../app/Game/Projects.php';
require_once __DIR__ . '/../app/Game/Scoring.php';
require_once __DIR__ . '/../app/Game/Rules.php';
require_once __DIR__ . '/../app/Game/Purchase.php';
require_once __DIR__ . '/../app/Game/Bot.php';
require_once __DIR__ . '/../app/Game/TurnClock.php';
require_once __DIR__ . '/../app/Game/Qaid.php';
require_once __DIR__ . '/../app/Game/Engine.php';

use Baloot\Game\Engine;
use Baloot\Game\Rules;
use Baloot\Game\TurnClock;

function checkMatch(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}

$owner = str_repeat('a', 64);
$room = [
    'code' => 'FULL42',
    'ownerGuestId' => $owner,
    'qaidEnabled' => true,
    'players' => [
        ['name' => 'Bot 1', 'guestId' => $owner, 'seat' => 0, 'bot' => true],
        ['name' => 'Bot 2', 'guestId' => str_repeat('b',64), 'seat' => 1, 'bot' => true],
        ['name' => 'Bot 3', 'guestId' => str_repeat('c',64), 'seat' => 2, 'bot' => true],
        ['name' => 'Bot 4', 'guestId' => str_repeat('d',64), 'seat' => 3, 'bot' => true],
    ],
    'game' => null,
];
Engine::start($room, 0);
checkMatch(count($room['game']['hands']) === 4, 'four player hands are initialized');
checkMatch(array_sum(array_map('count', $room['game']['hands'])) === 20, 'five initial cards per seat');
checkMatch(count(Engine::snapshot($room, 0)['hand']) === 5, 'seat zero receives only its own hand');
checkMatch(count(Engine::snapshot($room, 1)['hand']) === 5, 'seat one receives only its own hand');

$actions = 0;
$completedDeals = 0;
$maxActions = 25000;
while (($room['game']['phase'] ?? '') !== 'gameEnd' && $actions < $maxActions) {
    $phase = $room['game']['phase'] ?? '';
    if ($phase === 'roundEnd') {
        $g =& $room['game'];
        checkMatch(count($g['tricks']) === 8, 'a completed deal contains eight tricks');
        checkMatch(count($g['history']) === 32, 'a completed deal contains 32 played cards');
        checkMatch(($g['lastLandPoints'] ?? 0) === 10 && ($g['lastLandTeam'] ?? -1) === (($g['lastWinner'] ?? -2) % 2), 'last-trick award is recorded for the winning team');
        checkMatch(array_sum(array_map('count', $g['hands'])) === 0, 'all hands are empty at deal end');
        $expectedRaw = ($g['contract']['type'] ?? '') === 'sun' ? 130 : 162;
        checkMatch(array_sum($g['lastRaw']) === $expectedRaw, 'raw card points plus last trick match the reference total');
        $completedDeals++;
        Rules::nextRound($g);
        TurnClock::arm($room, 30);
        unset($g);
        continue;
    }
    if ($phase === 'redeal') {
        Rules::nextRound($room['game']);
        continue;
    }
    $advanced = Engine::advanceBots($room, 32);
    if ($advanced < 1) {
        throw new RuntimeException('four-bot match stalled in phase ' . $phase . ' at seat ' . (string)($room['game']['turn'] ?? -1));
    }
    $actions += $advanced;
}
checkMatch(($room['game']['phase'] ?? '') === 'gameEnd', 'four bots finish a complete match before action limit');
checkMatch($completedDeals >= 1, 'at least one complete deal was played');
checkMatch(in_array($room['game']['winner'] ?? -1, [0,1], true), 'match winner is one of the two teams');
checkMatch(max($room['game']['scores']) >= 152, 'match ends at the target score');
echo 'Four-player full match passed: ' . $completedDeals . ' completed deals, ' . $actions . ' bot actions, winner team ' . $room['game']['winner'] . PHP_EOL;

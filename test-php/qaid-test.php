<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/Game/Seating.php';
require_once __DIR__ . '/../app/Game/Projects.php';
require_once __DIR__ . '/../app/Game/Scoring.php';
require_once __DIR__ . '/../app/Game/Rules.php';
require_once __DIR__ . '/../app/Game/Qaid.php';

use Baloot\Game\Qaid;
use Baloot\Game\Rules;

function qaidCheck(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function qaidGame(bool $violation): array
{
    $game = Rules::create();
    $game['qaidEnabled'] = true;
    $game['phase'] = 'playing';
    $game['contract'] = ['type' => 'sun', 'trump' => null, 'buyer' => 0, 'buyerTeam' => 0, 'level' => 1];
    $game['history'] = $violation ? [['seat' => 2, 'violation' => true]] : [];
    return $game;
}

$game = qaidGame(true);
$result = Qaid::decide($game, 1, 'correct');
qaidCheck($result['success'] === true && $result['winnerTeam'] === 1, 'Correct qaid verdict should award the claimant team.');
qaidCheck($game['scores'] === [0, 26], 'Correct verdict score should be awarded to claimant team.');

$game = qaidGame(true);
$result = Qaid::decide($game, 1, 'incorrect');
qaidCheck($result['success'] === false && $result['winnerTeam'] === 0, 'Wrong qaid verdict should award the opposing team.');

$game = qaidGame(false);
$result = Qaid::decide($game, 0, 'incorrect');
qaidCheck($result['success'] === true && $result['winnerTeam'] === 0, 'Correctly identifying no violation should award the claimant team.');

$game = qaidGame(false);
$result = Qaid::expire($game, 0);
qaidCheck($result['timeout'] === true && $result['winnerTeam'] === 1, 'Qaid timeout should award the opposing team.');
qaidCheck($game['scores'] === [0, 26], 'Qaid timeout should apply the loss score.');

echo "PHP qaid verdict tests passed\n";

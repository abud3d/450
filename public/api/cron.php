<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/Game/Seating.php';
require_once __DIR__ . '/../../app/Game/Projects.php';
require_once __DIR__ . '/../../app/Game/Scoring.php';
require_once __DIR__ . '/../../app/Game/Rules.php';
require_once __DIR__ . '/../../app/Game/Purchase.php';
require_once __DIR__ . '/../../app/Game/Bot.php';
require_once __DIR__ . '/../../app/Game/TurnClock.php';
require_once __DIR__ . '/../../app/Game/Qaid.php';
require_once __DIR__ . '/../../app/Game/Engine.php';
require_once __DIR__ . '/../../app/Infrastructure/Database.php';
require_once __DIR__ . '/../../app/Infrastructure/RoomStore.php';

if (PHP_SAPI !== 'cli') {
    $secret = (string) getenv('BALOUT_CRON_SECRET');
    $authorization = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
    if ($secret === '' || !hash_equals('Bearer ' . $secret, $authorization)) {
        http_response_code(403);
        exit("Forbidden\n");
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        exit("Method not allowed\n");
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
}

try {
    $advanced = (new Baloot\Infrastructure\RoomStore())->advanceDueRooms(100);
    $result = ['ok' => true, 'roomsAdvanced' => $advanced, 'time' => gmdate('c')];
    if (PHP_SAPI === 'cli') {
        echo json_encode($result, JSON_UNESCAPED_SLASHES) . PHP_EOL;
    } else {
        echo json_encode($result, JSON_UNESCAPED_SLASHES);
    }
} catch (Throwable $e) {
    error_log('Baloot cron: ' . $e->getMessage());
    if (PHP_SAPI !== 'cli') http_response_code(500);
    echo PHP_SAPI === 'cli' ? "Cron failed\n" : '{"ok":false,"error":"Cron failed"}';
}

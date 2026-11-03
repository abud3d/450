<?php
declare(strict_types=1);

use Baloot\Game\Engine;
use Baloot\Infrastructure\RoomStore;

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

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

function apiReply(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
}

function apiBody(): array
{
    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 12000) apiReply(413, ['ok' => false, 'error' => 'حجم الطلب كبير']);
    $data = json_decode(file_get_contents('php://input') ?: '{}', true);
    if (!is_array($data)) apiReply(400, ['ok' => false, 'error' => 'صيغة الطلب غير صحيحة']);
    return $data;
}

function apiOriginGuard(): void
{
    $expected = rtrim((string) getenv('APP_ORIGIN'), '/');
    $origin = rtrim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''), '/');
    if ($expected === '' || $origin === '') apiReply(503, ['ok' => false, 'error' => 'إعداد مصدر التطبيق غير مكتمل']);
    if (!hash_equals($expected, $origin)) apiReply(403, ['ok' => false, 'error' => 'مصدر الطلب غير مسموح']);
}

function apiGuestId(): string
{
    $id = $_COOKIE['baloot_guest'] ?? '';
    if (!is_string($id) || !preg_match('/^[a-f0-9]{64}$/', $id)) {
        $id = bin2hex(random_bytes(32));
        setcookie('baloot_guest', $id, [
            'expires' => time() + 60 * 60 * 24 * 180,
            'path' => '/',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
    return $id;
}

function apiRoomCode(mixed $value): string
{
    $code = strtoupper(trim((string) $value));
    if (!preg_match('/^[A-F0-9]{6}$/', $code)) apiReply(422, ['ok' => false, 'error' => 'رمز الغرفة غير صحيح']);
    return $code;
}

function apiName(mixed $value): string
{
    $name = trim(strip_tags((string) $value));
    return mb_substr($name !== '' ? $name : 'ضيف', 0, 24, 'UTF-8');
}

$store = new RoomStore();
$guestId = apiGuestId();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = (string) ($_GET['action'] ?? '');

try {
    if ($method === 'GET' && in_array($action, ['state','resume'], true)) {
        $code = apiRoomCode($_GET['code'] ?? '');
        $room = $store->read($code);
        if (!$room) apiReply(404, ['ok' => false, 'error' => 'الغرفة غير موجودة']);
        $seat = null;
        foreach ($room['state']['players'] ?? [] as $i => $player) {
            if (is_array($player) && hash_equals((string) ($player['guestId'] ?? ''), $guestId)) { $seat = (int) $i; break; }
        }
        if ($seat === null) apiReply(403, ['ok' => false, 'error' => 'لا يوجد مقعد محفوظ لهذا اللاعب']);
        $snapshot = Engine::snapshot($room['state'], $seat);
        $snapshot['version'] = $room['version'];
        apiReply(200, ['ok' => true, 'seat' => $seat, 'snapshot' => $snapshot]);
    }

    if ($method !== 'POST') apiReply(405, ['ok' => false, 'error' => 'طريقة الطلب غير مدعومة']);
    apiOriginGuard();
    $input = apiBody();

    if ($action === 'create') {
        $room = $store->create($guestId, apiName($input['name'] ?? ''), ($input['qaidEnabled'] ?? true) === true);
        $room['state']['code'] = $room['code'];
        $snapshot = Engine::snapshot($room['state'], 0); $snapshot['version'] = $room['version'];
        apiReply(201, ['ok' => true, 'code' => $room['code'], 'seat' => 0, 'guestId' => $guestId, 'created' => true, 'qaidEnabled' => $room['state']['qaidEnabled'], 'snapshot' => $snapshot]);
    }

    if ($action === 'join') {
        $joined = $store->join(apiRoomCode($input['code'] ?? ''), $guestId, apiName($input['name'] ?? ''));
        $snapshot = Engine::snapshot($joined['state'], $joined['seat']); $snapshot['version'] = $joined['version'];
        apiReply(200, ['ok' => true, 'code' => $joined['code'], 'seat' => $joined['seat'], 'guestId' => $guestId, 'snapshot' => $snapshot]);
    }

    $code = apiRoomCode($input['code'] ?? '');
    $actionId = (string) ($input['actionId'] ?? '');
    if ($actionId === '') apiReply(422, ['ok' => false, 'error' => 'معرّف العملية مطلوب لمنع تكرار الحركة']);

    if ($action === 'move-seat') {
        $moved = $store->moveSeat($code, $guestId, (int) ($input['targetSeat'] ?? -1), $actionId);
        $room = $store->read($code);
        if (!$room) apiReply(404, ['ok' => false, 'error' => 'الغرفة غير موجودة']);
        $snapshot = Engine::snapshot($room['state'], (int) $moved['seat']);
        $snapshot['version'] = $room['version'];
        apiReply(200, ['ok' => true, 'seat' => (int) $moved['seat'], 'snapshot' => $snapshot]);
    }
    $operation = match ($action) {
        'start' => 'start',
        'bid' => 'bid',
        'post-purchase' => 'post_purchase',
        'double' => 'double',
        'project' => 'project',
        'play' => 'play',
        'qaid-open' => 'qaid_open',
        'qaid-cancel' => 'qaid_cancel',
        'claim' => 'qaid_claim',
        'next' => 'next',
        'result-continue' => 'continue',
        'timeout-return' => 'timeout_return',
        'timeout-bot' => 'timeout_bot',
        'replay' => 'replay',
        default => '',
    };
    if ($operation === '') apiReply(404, ['ok' => false, 'error' => 'العملية غير معروفة']);

    $result = $store->mutate(
        $code,
        $guestId,
        $actionId,
        $operation,
        function (array &$state, int $seat) use ($operation, $input): array {
            if ($operation === 'start') {
                Engine::start($state, $seat);
                Engine::advanceBots($state);
                return ['ok' => true];
            }
            return Engine::apply($state, $seat, $operation, $input);
        },
        isset($input['version']) ? (int) $input['version'] : null
    );
    $room = $store->read($code);
    if (!$room) apiReply(404, ['ok' => false, 'error' => 'الغرفة غير موجودة']);
    $seat = null;
    foreach ($room['state']['players'] ?? [] as $i => $player) {
        if (is_array($player) && hash_equals((string) ($player['guestId'] ?? ''), $guestId)) { $seat = (int) $i; break; }
    }
    if ($seat === null) apiReply(403, ['ok' => false, 'error' => 'المقعد غير معروف']);
    $snapshot = Engine::snapshot($room['state'], $seat); $snapshot['version'] = $room['version'];
    apiReply(200, ['ok' => true, 'result' => $result, 'snapshot' => $snapshot]);
} catch (DomainException $e) {
    apiReply(422, ['ok' => false, 'error' => $e->getMessage()]);
} catch (RuntimeException $e) {
    apiReply(409, ['ok' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('Baloot room API: ' . $e->getMessage());
    apiReply(500, ['ok' => false, 'error' => 'تعذر تنفيذ العملية حالياً']);
}

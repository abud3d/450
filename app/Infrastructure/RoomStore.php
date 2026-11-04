<?php
declare(strict_types=1);

namespace Baloot\Infrastructure;

use PDO;
use RuntimeException;

final class RoomStore
{
    public function create(string $ownerGuestId, string $displayName, bool $qaidEnabled = true): array
    {
        $this->assertGuestId($ownerGuestId);
        $name = $this->cleanName($displayName);
        return Database::transaction(function (PDO $pdo) use ($ownerGuestId, $name, $qaidEnabled): array {
            for ($attempt = 0; $attempt < 5; $attempt++) {
                $code = strtoupper(bin2hex(random_bytes(3)));
                try {
                    $state = ['code' => $code, 'phase' => 'lobby', 'ownerGuestId' => $ownerGuestId, 'qaidEnabled' => $qaidEnabled, 'players' => array_fill(0, 4, null), 'game' => null];
                    $state['players'][0] = ['name' => $name, 'guestId' => $ownerGuestId, 'seat' => 0, 'bot' => false];
                    $stmt = $pdo->prepare(
                        "INSERT INTO baloot_rooms (room_code, owner_guest_id, status, phase, state_json, qaid_enabled) VALUES (?, ?, 'lobby', 'lobby', ?, ?)"
                    );
                    $stmt->execute([$code, $ownerGuestId, $this->encode($state), $qaidEnabled ? 1 : 0]);
                    $roomId = (int) $pdo->lastInsertId();
                    $this->insertPlayer($pdo, $roomId, 0, $ownerGuestId, $name, false);
                    return ['code' => $code, 'seat' => 0, 'version' => 0, 'state' => $state];
                } catch (\PDOException $e) {
                    if ((string) $e->getCode() !== '23000' || $attempt === 4) {
                        throw $e;
                    }
                }
            }
            throw new RuntimeException('تعذر إنشاء رمز غرفة فريد.');
        });
    }

    public function join(string $code, string $guestId, string $displayName): array
    {
        $this->assertGuestId($guestId);
        $name = $this->cleanName($displayName);
        return Database::transaction(function (PDO $pdo) use ($code, $guestId, $name): array {
            $room = $this->lockedRoom($pdo, $code);
            $state = $this->decode((string) $room['state_json']);
            $players = $state['players'] ?? array_fill(0, 4, null);

            foreach ($players as $seat => $player) {
                if (is_array($player) && hash_equals((string) ($player['guestId'] ?? ''), $guestId)) {
                    $players[$seat]['name'] = $name;
                    $state['players'] = $players;
                    $this->saveState($pdo, $room, $state);
                    $this->upsertPlayer($pdo, (int) $room['id'], (int) $seat, $guestId, $name);
                    return ['code' => $room['room_code'], 'seat' => (int) $seat, 'version' => (int) $room['version'] + 1, 'state' => $state];
                }
            }

            if (($room['status'] ?? '') !== 'lobby') {
                throw new RuntimeException('اللعبة بدأت؛ لا يمكن شغل مقعد جديد.');
            }
            $seat = null;
            foreach ($players as $i => $player) {
                if ($player === null || (is_array($player) && !empty($player['bot']))) {
                    $seat = (int) $i;
                    break;
                }
            }
            if ($seat === null) {
                throw new RuntimeException('الغرفة مكتملة.');
            }
            $players[$seat] = ['name' => $name, 'guestId' => $guestId, 'seat' => $seat, 'bot' => false];
            $state['players'] = $players;
            $this->saveState($pdo, $room, $state);
            $this->upsertPlayer($pdo, (int) $room['id'], $seat, $guestId, $name);
            return ['code' => $room['room_code'], 'seat' => $seat, 'version' => (int) $room['version'] + 1, 'state' => $state];
        });
    }

    /** Join the newest compatible lobby, or create a room when none is available. */
    public function quickJoin(string $guestId, string $displayName, bool $qaidEnabled = true): array
    {
        $this->assertGuestId($guestId);
        $name = $this->cleanName($displayName);
        $stmt = Database::connection()->prepare(
            "SELECT r.room_code
             FROM baloot_rooms r
             WHERE r.status = 'lobby'
               AND r.qaid_enabled = ?
               AND (SELECT COUNT(*) FROM baloot_room_players p WHERE p.room_id = r.id) < 4
             ORDER BY (SELECT COUNT(*) FROM baloot_room_players ranked WHERE ranked.room_id = r.id) DESC,
                      r.last_activity_at DESC
             LIMIT 25"
        );
        $stmt->execute([$qaidEnabled ? 1 : 0]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $code) {
            try {
                $joined = $this->join((string) $code, $guestId, $name);
                $joined['created'] = false;
                $joined['qaidEnabled'] = $qaidEnabled;
                return $joined;
            } catch (RuntimeException $e) {
                // The lobby may have filled or started between discovery and the locked join.
                continue;
            }
        }

        $created = $this->create($guestId, $name, $qaidEnabled);
        $created['created'] = true;
        $created['qaidEnabled'] = $qaidEnabled;
        return $created;
    }

    /**
     * Idempotent state mutation with row-level serialization and optimistic versioning.
     * The supplied callback receives the decoded state and must return the response to cache.
     */
    public function mutate(string $code, string $guestId, string $actionId, string $actionType, callable $mutation, ?int $expectedVersion = null): array
    {
        $this->assertGuestId($guestId);
        if (!preg_match('/^[a-f0-9-]{36}$/i', $actionId)) {
            throw new RuntimeException('معرّف العملية غير صالح.');
        }
        if (!preg_match('/^[a-z][a-z0-9_]{1,31}$/', $actionType)) {
            throw new RuntimeException('نوع العملية غير صالح.');
        }

        return Database::transaction(function (PDO $pdo) use ($code, $guestId, $actionId, $actionType, $mutation, $expectedVersion): array {
            $room = $this->lockedRoom($pdo, $code);
            $check = $pdo->prepare('SELECT result_json FROM baloot_room_actions WHERE room_id = ? AND action_id = ?');
            $check->execute([(int) $room['id'], $actionId]);
            $prior = $check->fetchColumn();
            if (is_string($prior)) {
                return $this->decode($prior);
            }

            $seatQuery = $pdo->prepare('SELECT seat FROM baloot_room_players WHERE room_id = ? AND guest_id = ? LIMIT 1');
            $seatQuery->execute([(int) $room['id'], $guestId]);
            $seat = $seatQuery->fetchColumn();
            if ($seat === false) {
                throw new RuntimeException('لا تملك مقعداً في هذه الغرفة.');
            }
            if ($expectedVersion !== null && $expectedVersion !== (int) $room['version']) {
                throw new RuntimeException('تغيرت حالة الطاولة؛ حدّث البيانات ثم أعد المحاولة.');
            }

            $state = $this->decode((string) $room['state_json']);
            $beforePhase = (string) ($state['game']['phase'] ?? 'lobby');
            $response = $mutation($state, (int) $seat);
            if (!is_array($response)) {
                throw new RuntimeException('استجابة العملية غير صالحة.');
            }
            $afterPhase = (string) ($state['game']['phase'] ?? 'lobby');
            if ($beforePhase !== 'gameEnd' && $afterPhase === 'gameEnd') {
                $this->recordCompletedMatch($pdo, (int) $room['id'], $state);
            }
            $this->saveState($pdo, $room, $state);
            $touch = $pdo->prepare('UPDATE baloot_room_players SET last_seen_at = UTC_TIMESTAMP(6), connected_at = COALESCE(connected_at, UTC_TIMESTAMP(6)) WHERE room_id = ? AND guest_id = ?');
            $touch->execute([(int) $room['id'], $guestId]);
            $insert = $pdo->prepare('INSERT INTO baloot_room_actions (room_id, action_id, guest_id, action_type, result_json) VALUES (?, ?, ?, ?, ?)');
            $insert->execute([(int) $room['id'], $actionId, $guestId, $actionType, $this->encode($response)]);
            return $response;
        });
    }



    /**
     * Award the final team score to each registered human player exactly once.
     * The unique (match_id, uid) ledger key protects against duplicate requests.
     */
    private function recordCompletedMatch(PDO $pdo, int $roomId, array $state): void
    {
        $game = $state['game'] ?? [];
        $matchId = (string) ($state['matchId'] ?? '');
        if (!preg_match('/^[a-f0-9]{32}$/', $matchId)) {
            $matchId = bin2hex(random_bytes(16));
            $state['matchId'] = $matchId;
        }
        $scores = array_values($game['scores'] ?? [0, 0]);
        $scores = [(int) ($scores[0] ?? 0), (int) ($scores[1] ?? 0)];
        $winner = $scores[0] === $scores[1] ? -1 : ($scores[0] > $scores[1] ? 0 : 1);
        $players = $state['players'] ?? [];
        foreach ($players as $seat => $player) {
            if (!is_array($player) || !empty($player['bot'])) continue;
            $guestId = (string) ($player['guestId'] ?? '');
            if (!preg_match('/^[a-f0-9]{64}$/', $guestId)) continue;
            $lookup = $pdo->prepare('SELECT uid FROM baloot_users WHERE guest_id = ? LIMIT 1');
            $lookup->execute([$guestId]);
            $uid = $lookup->fetchColumn();
            if (!is_string($uid) || $uid === '') continue;
            $team = ((int) $seat) % 2;
            $outcome = $winner < 0 ? 'draw' : ($winner === $team ? 'win' : 'loss');
            $insert = $pdo->prepare(
                'INSERT IGNORE INTO baloot_match_results (match_id, room_id, uid, guest_id, team, final_score, outcome) VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $insert->execute([$matchId, $roomId, $uid, $guestId, $team, $scores[$team], $outcome]);
            if ($insert->rowCount() !== 1) continue;
            $update = $pdo->prepare(
                'UPDATE baloot_users SET points = points + ?, matches = matches + 1, wins = wins + ?, losses = losses + ?, draws = draws + ? WHERE uid = ?'
            );
            $update->execute([
                $scores[$team],
                $outcome === 'win' ? 1 : 0,
                $outcome === 'loss' ? 1 : 0,
                $outcome === 'draw' ? 1 : 0,
                $uid,
            ]);
        }
    }

    public function moveSeat(string $code, string $guestId, int $targetSeat, string $actionId): array
    {
        $this->assertGuestId($guestId);
        if ($targetSeat < 0 || $targetSeat > 3 || !preg_match('/^[a-f0-9-]{36}$/i', $actionId)) {
            throw new RuntimeException('طلب نقل المقعد غير صالح.');
        }
        return Database::transaction(function (PDO $pdo) use ($code, $guestId, $targetSeat, $actionId): array {
            $room = $this->lockedRoom($pdo, $code);
            $check = $pdo->prepare('SELECT result_json FROM baloot_room_actions WHERE room_id = ? AND action_id = ?');
            $check->execute([(int) $room['id'], $actionId]);
            $prior = $check->fetchColumn();
            if (is_string($prior)) return $this->decode($prior);
            $state = $this->decode((string) $room['state_json']);
            if (!empty($state['game'])) throw new RuntimeException('تغيير المقاعد متاح قبل بدء اللعبة فقط.');
            $players = $state['players'] ?? [];
            $seat = null;
            foreach ($players as $i => $player) {
                if (is_array($player) && hash_equals((string) ($player['guestId'] ?? ''), $guestId)) { $seat = (int) $i; break; }
            }
            if ($seat === null) throw new RuntimeException('لا يوجد مقعد لهذا اللاعب.');
            if ($seat === $targetSeat) return ['seat' => $seat];
            $target = $players[$targetSeat] ?? null;
            if (is_array($target) && empty($target['bot'])) throw new RuntimeException('لا يمكن نقل لاعب آخر من مقعده.');
            $current = $players[$seat];
            if (is_array($target)) { $target['seat'] = $seat; $players[$seat] = $target; }
            else $players[$seat] = null;
            $current['seat'] = $targetSeat;
            $players[$targetSeat] = $current;
            $state['players'] = $players;
            $this->saveState($pdo, $room, $state);
            $update = $pdo->prepare('UPDATE baloot_room_players SET seat = ? WHERE room_id = ? AND guest_id = ?');
            $update->execute([$targetSeat, (int) $room['id'], $guestId]);
            $result = ['seat' => $targetSeat];
            $insert = $pdo->prepare('INSERT INTO baloot_room_actions (room_id, action_id, guest_id, action_type, result_json) VALUES (?, ?, ?, ?, ?)');
            $insert->execute([(int) $room['id'], $actionId, $guestId, 'move_seat', $this->encode($result)]);
            return $result;
        });
    }



    public function allowRate(string $scope, string $clientKey, int $maximum, int $windowSeconds): bool
    {
        $maximum = max(1, min(10000, $maximum));
        $windowSeconds = max(1, min(86400, $windowSeconds));
        $key = hash('sha256', $scope . '|' . $clientKey);
        $now = time();
        return Database::transaction(function (PDO $pdo) use ($key, $maximum, $windowSeconds, $now): bool {
            $sql = "INSERT INTO baloot_api_limits (limit_key, window_started_at, hit_count) VALUES (?, ?, 1)
                    ON DUPLICATE KEY UPDATE
                    hit_count = IF(window_started_at + ? <= ?, 1, hit_count + 1),
                    window_started_at = IF(window_started_at + ? <= ?, ?, window_started_at)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$key, $now, $windowSeconds, $now, $windowSeconds, $now, $now]);
            $read = $pdo->prepare('SELECT hit_count FROM baloot_api_limits WHERE limit_key = ?');
            $read->execute([$key]);
            return (int) $read->fetchColumn() <= $maximum;
        });
    }

    /** Advance at most one scheduled bot action for the requested room. */
    public function advanceRoomBots(string $code): bool
    {
        return Database::transaction(function (PDO $pdo) use ($code): bool {
            $room = $this->lockedRoom($pdo, $code);
            $state = $this->decode((string) $room['state_json']);
            $now = time();
            $autoLastDue = isset($state['autoLastCardAt']) && (int) $state['autoLastCardAt'] <= $now;
            $botDue = isset($state['botActionAt']) && (int) $state['botActionAt'] <= $now;
            if (!$autoLastDue && !$botDue) return false;
            $beforePhase = (string) ($state['game']['phase'] ?? 'lobby');
            if ($autoLastDue) \Baloot\Game\Engine::advanceAutoLastCard($state);
            else \Baloot\Game\Engine::advanceBots($state, 1);
            $afterPhase = (string) ($state['game']['phase'] ?? 'lobby');
            if ($beforePhase !== 'gameEnd' && $afterPhase === 'gameEnd') {
                $this->recordCompletedMatch($pdo, (int) $room['id'], $state);
            }
            $this->saveState($pdo, $room, $state);
            return true;
        });
    }

    public function advanceDueRooms(int $limit = 100): int
    {
        $limit = max(1, min(500, $limit));
        $query = Database::connection()->query("SELECT room_code FROM baloot_rooms WHERE status = 'active' ORDER BY last_activity_at ASC LIMIT " . $limit);
        $codes = $query->fetchAll(PDO::FETCH_COLUMN);
        $advanced = 0;
        foreach ($codes as $code) {
            $changed = Database::transaction(function (PDO $pdo) use ($code): bool {
                $room = $this->lockedRoom($pdo, (string) $code);
                $state = $this->decode((string) $room['state_json']);
                $now = time();
                $beforePhase = (string) ($state['game']['phase'] ?? 'lobby');
                $game =& $state['game'];
                if (!is_array($game ?? null)) return false;
                $didChange = false;

                if (isset($state['qaidWindow']) && (int) ($state['qaidWindow']['expiresAt'] ?? 0) <= $now) {
                    unset($state['qaidWindow']);
                    if (($game['phase'] ?? '') === 'roundEnd') $state['roundResultReadyAt'] = $now;
                    $state['lastAction'] = 'انتهت مهلة كشف أوراق القيد.';
                    $didChange = true;
                }

                if (!$didChange && !isset($state['qaidWindow'])
                    && isset($state['autoLastCardAt']) && (int) $state['autoLastCardAt'] <= $now) {
                    $didChange = \Baloot\Game\Engine::advanceAutoLastCard($state);
                }

                if (!$didChange && ($game['phase'] ?? '') === 'roundEnd'
                    && (int) ($state['roundResultReadyAt'] ?? 0) > 0
                    && $now >= (int) $state['roundResultReadyAt'] + 10
                    && !isset($state['qaidWindow'])) {
                    \Baloot\Game\Rules::nextRound($game);
                    unset($state['roundResultReadyAt'], $state['timeoutSeat'], $state['timeoutGraceUntil']);
                    \Baloot\Game\Engine::advanceBots($state);
                    $state['lastAction'] = 'بدأت التوزيعة التالية تلقائياً.';
                    $didChange = true;
                } elseif (!$didChange && isset($state['qaidWindow'])) {
                    // A qaid review pauses game timers until it is claimed or expires.
                } elseif (!$didChange && \Baloot\Game\TurnClock::expired($state, $now)) {
                    $phase = $game['phase'] ?? '';
                    if ($phase === 'postPurchase') {
                        \Baloot\Game\Purchase::expire($game);
                        \Baloot\Game\Engine::advanceBots($state);
                        $state['lastAction'] = 'انتهت مهلة الرد على الشراء.';
                        $didChange = true;
                    } elseif ($phase === 'projectDeclaration') {
                        foreach ($state['players'] as $seat => $player) {
                            if (empty($game['projectDeclared'][$seat])) {
                                if (!empty($player['bot']) || !empty($player['botControl'])) {
                                    \Baloot\Game\Bot::act($game, (int) $seat);
                                } else {
                                    \Baloot\Game\Projects::declare($game, (int) $seat, 'pass');
                                }
                                $didChange = true;
                            }
                        }
                        \Baloot\Game\Engine::advanceBots($state);
                        $state['lastAction'] = 'انتهت مهلة إعلان المشاريع.';
                    } elseif (in_array($phase, ['auction1','auction2','playing','buyerConfirm','buyerSelectHukm'], true)) {
                        $seat = in_array($phase, ['buyerConfirm','buyerSelectHukm'], true) ? (int) ($game['postPurchase']['buyer'] ?? -1) : (int) ($game['turn'] ?? -1);
                        $player = $state['players'][$seat] ?? null;
                        if ($seat >= 0 && (is_array($player)) && (!empty($player['bot']) || !empty($player['botControl']))) {
                            \Baloot\Game\Engine::advanceBots($state);
                            $didChange = true;
                        } elseif ($seat >= 0 && (int) ($state['timeoutSeat'] ?? -1) === $seat
                            && (int) ($state['timeoutGraceUntil'] ?? PHP_INT_MAX) <= $now) {
                            $state['players'][$seat]['botControl'] = true;
                            unset($state['timeoutSeat'], $state['timeoutGraceUntil']);
                            \Baloot\Game\Engine::advanceBots($state);
                            $state['lastAction'] = 'انتهت مهلة اللاعب؛ البوت يكمل اللعب.';
                            $didChange = true;
                        } elseif ($seat >= 0 && (int) ($state['timeoutSeat'] ?? -1) !== $seat) {
                            $state['timeoutSeat'] = $seat;
                            $state['timeoutGraceUntil'] = $now + 2;
                            \Baloot\Game\TurnClock::arm($state, 2, $now);
                            $state['lastAction'] = 'تأخر اللاعب؛ سيُعرض خيار تولي البوت للمقعد.';
                            $didChange = true;
                        }
                    }
                }

                if ($didChange) {
                    $afterPhase = (string) ($state['game']['phase'] ?? 'lobby');
                    if ($beforePhase !== 'gameEnd' && $afterPhase === 'gameEnd') {
                        $this->recordCompletedMatch($pdo, (int) $room['id'], $state);
                    }
                    $this->saveState($pdo, $room, $state);
                }
                return $didChange;
            });
            if ($changed) $advanced++;
        }
        return $advanced;
    }

    public function touchPlayer(string $code, string $guestId): bool
    {
        $stmt = Database::connection()->prepare(
            'UPDATE baloot_room_players p
             INNER JOIN baloot_rooms r ON r.id = p.room_id
             SET p.last_seen_at = UTC_TIMESTAMP(6),
                 p.connected_at = COALESCE(p.connected_at, UTC_TIMESTAMP(6))
             WHERE r.room_code = ? AND p.guest_id = ?'
        );
        $stmt->execute([strtoupper($code), $guestId]);
        return $stmt->rowCount() > 0;
    }

    public function read(string $code): ?array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT id, room_code, status, phase, state_json, version, qaid_enabled, last_activity_at FROM baloot_rooms WHERE room_code = ? LIMIT 1');
        $stmt->execute([strtoupper($code)]);
        $room = $stmt->fetch();
        if (!$room) {
            return null;
        }
        $room['state'] = $this->decode((string) $room['state_json']);
        unset($room['state_json']);
        $presence = $pdo->prepare(
            'SELECT seat, guest_id, (last_seen_at >= UTC_TIMESTAMP(6) - INTERVAL 20 SECOND) AS is_connected
             FROM baloot_room_players WHERE room_id = ?'
        );
        $presence->execute([(int) $room['id']]);
        foreach ($presence->fetchAll() as $player) {
            $seat = (int) $player['seat'];
            if (isset($room['state']['players'][$seat])
                && hash_equals((string) ($room['state']['players'][$seat]['guestId'] ?? ''), (string) $player['guest_id'])) {
                $room['state']['players'][$seat]['connected'] = (bool) $player['is_connected'];
            }
        }
        unset($room['id']);
        $room['version'] = (int) $room['version'];
        $room['qaid_enabled'] = (bool) $room['qaid_enabled'];
        return $room;
    }

    private function lockedRoom(PDO $pdo, string $code): array
    {
        $stmt = $pdo->prepare('SELECT * FROM baloot_rooms WHERE room_code = ? LIMIT 1 FOR UPDATE');
        $stmt->execute([strtoupper($code)]);
        $room = $stmt->fetch();
        if (!$room) {
            throw new RuntimeException('الغرفة غير موجودة.');
        }
        return $room;
    }

    private function saveState(PDO $pdo, array $room, array $state): void
    {
        $phase = (string) ($state['game']['phase'] ?? $state['phase'] ?? 'lobby');
        $status = in_array($phase, ['gameEnd', 'finished'], true) ? 'finished' : ($phase === 'lobby' ? 'lobby' : 'active');
        $stmt = $pdo->prepare('UPDATE baloot_rooms SET state_json = ?, phase = ?, status = ?, version = version + 1, last_activity_at = UTC_TIMESTAMP(6) WHERE id = ?');
        $stmt->execute([$this->encode($state), $phase, $status, (int) $room['id']]);
    }

    private function insertPlayer(PDO $pdo, int $roomId, int $seat, string $guestId, string $name, bool $bot): void
    {
        $stmt = $pdo->prepare('INSERT INTO baloot_room_players (room_id, seat, guest_id, display_name, is_bot, connected_at, last_seen_at) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))');
        $stmt->execute([$roomId, $seat, $guestId, $name, $bot ? 1 : 0]);
    }

    private function upsertPlayer(PDO $pdo, int $roomId, int $seat, string $guestId, string $name): void
    {
        $stmt = $pdo->prepare('UPDATE baloot_room_players SET display_name = ?, last_seen_at = UTC_TIMESTAMP(6), connected_at = UTC_TIMESTAMP(6) WHERE room_id = ? AND guest_id = ?');
        $stmt->execute([$name, $roomId, $guestId]);
        if ($stmt->rowCount() === 0) {
            $this->insertPlayer($pdo, $roomId, $seat, $guestId, $name, false);
        }
    }

    private function assertGuestId(string $guestId): void
    {
        if (!preg_match('/^[a-f0-9]{32,64}$/i', $guestId)) {
            throw new RuntimeException('معرّف اللاعب غير صالح.');
        }
    }

    private function cleanName(string $name): string
    {
        $name = trim(strip_tags($name));
        if ($name === '') $name = 'ضيف';
        return mb_substr($name, 0, 24, 'UTF-8');
    }

    private function encode(array $value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return $json;
    }

    private function decode(string $json): array
    {
        $value = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($value)) {
            throw new RuntimeException('بيانات الغرفة تالفة.');
        }
        return $value;
    }
}

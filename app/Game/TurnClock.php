<?php
declare(strict_types=1);

namespace Baloot\Game;

/**
 * Stateless deadline helpers. Persist the returned timestamps inside the room
 * state so deadlines survive PHP request shutdowns, Apache restarts and cron runs.
 */
final class TurnClock
{
    public static function arm(array &$roomState, int $seconds = 30, ?int $now = null): void
    {
        $seconds = max(5, min(300, $seconds));
        $roomState['turnDeadlineAt'] = ($now ?? time()) + $seconds;
    }

    public static function clear(array &$roomState): void
    {
        unset($roomState['turnDeadlineAt']);
    }

    public static function expired(array $roomState, ?int $now = null): bool
    {
        $deadline = $roomState['turnDeadlineAt'] ?? null;
        return is_int($deadline) && $deadline <= ($now ?? time());
    }

    /**
     * Resolve one overdue turn. Bot seats act immediately; a human timeout is
     * reported to the caller, which may offer takeover or apply the room policy.
     */
    public static function resolve(array &$roomState, int $seat, bool $seatIsBot, ?int $now = null): string
    {
        if (!self::expired($roomState, $now)) return 'not_due';
        if (($roomState['turn'] ?? -1) !== $seat) {
            self::clear($roomState);
            return 'stale_deadline';
        }
        if (!$seatIsBot) return 'human_timeout';
        if (!Bot::act($roomState, $seat)) return 'no_bot_action';
        self::arm($roomState, 30, $now);
        return 'bot_acted';
    }

    private function __construct() {}
}

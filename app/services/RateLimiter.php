<?php
declare(strict_types=1);

/**
 * Fixed-window counter in the `rate_limits` table.
 * Used for lead submissions, tracking events and (later) admin logins.
 * Generation limits are counted from the `generations` table instead,
 * so a failed attempt never eats a visitor's quota twice.
 */
final class RateLimiter
{
    /** Count one hit. Returns false when the limit for this window is already reached. */
    public static function hit(string $action, string $key, int $limit, int $windowSeconds): bool
    {
        if ($limit <= 0) {
            return true;
        }
        $key = mb_substr($key, 0, 120);
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $row = q('SELECT id, `count`, window_start FROM rate_limits WHERE `key` = ? AND action = ? FOR UPDATE', [$key, $action])->fetch();
            $now = utc_now();
            if (!$row) {
                q('INSERT INTO rate_limits (`key`, action, `count`, window_start) VALUES (?, ?, 1, ?)', [$key, $action, $now]);
                $pdo->commit();
                return true;
            }
            if (strtotime($row['window_start'] . ' UTC') <= time() - $windowSeconds) {
                q('UPDATE rate_limits SET `count` = 1, window_start = ? WHERE id = ?', [$now, $row['id']]);
                $pdo->commit();
                return true;
            }
            if ((int) $row['count'] >= $limit) {
                $pdo->commit();
                return false;
            }
            q('UPDATE rate_limits SET `count` = `count` + 1 WHERE id = ?', [$row['id']]);
            $pdo->commit();
            return true;
        } catch (Throwable $e) {
            $pdo->rollBack();
            log_error('RateLimiter failed: ' . $e->getMessage());
            return true; // fail open: a limiter error must not block real visitors
        }
    }

    public static function clear(string $action, string $key): void
    {
        q('DELETE FROM rate_limits WHERE `key` = ? AND action = ?', [$key, $action]);
    }

    /** Drop rows whose window ended more than a day ago (called from cron). */
    public static function prune(): int
    {
        return q('DELETE FROM rate_limits WHERE window_start < ?', [utc_now('-2 days')])->rowCount();
    }
}

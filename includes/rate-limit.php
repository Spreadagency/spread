<?php
/**
 * Spread AI — Rate Limiting (DB-based)
 *
 * Uses PHP-computed timestamps (not DB clock) to avoid clock-drift bugs.
 * Requires the `rate_limits` table (see sql/security-upgrade.sql).
 */

require_once __DIR__ . '/db.php';

/**
 * Client IP (best effort behind cPanel/proxies)
 */
function client_ip(): string
{
    return substr($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0', 0, 45);
}

/**
 * Check whether an action is allowed for a given key.
 * Does NOT record the attempt (call rate_limit_hit for that).
 */
function rate_limit_ok(string $action, string $key, int $maxAttempts, int $windowSeconds): bool
{
    try {
        $cutoff = date('Y-m-d H:i:s', time() - $windowSeconds);
        $count = db_count(
            'SELECT COUNT(*) FROM rate_limits WHERE action = ? AND rate_key = ? AND created_at > ?',
            [$action, substr($key, 0, 120), $cutoff]
        );
        return $count < $maxAttempts;
    } catch (\Throwable $e) {
        // If the table is missing, fail open so the site keeps working
        return true;
    }
}

/**
 * Record an attempt for an action + key.
 */
function rate_limit_hit(string $action, string $key): void
{
    try {
        db_run(
            'INSERT INTO rate_limits (action, rate_key, created_at) VALUES (?, ?, ?)',
            [$action, substr($key, 0, 120), date('Y-m-d H:i:s')]
        );

        // Occasional cleanup: 2% chance per hit, remove rows older than 24h
        if (random_int(1, 50) === 1) {
            db_run(
                'DELETE FROM rate_limits WHERE created_at < ?',
                [date('Y-m-d H:i:s', time() - 86400)]
            );
        }
    } catch (\Throwable $e) {
        // best effort
    }
}

/**
 * Convenience: check + hit in one call.
 * Returns true if allowed (and records the attempt), false if blocked.
 */
function rate_limit(string $action, string $key, int $maxAttempts, int $windowSeconds): bool
{
    if (!rate_limit_ok($action, $key, $maxAttempts, $windowSeconds)) {
        return false;
    }
    rate_limit_hit($action, $key);
    return true;
}

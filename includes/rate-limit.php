<?php
/**
 * Spread AI — Rate Limiting (DB-based)
 *
 * Uses PHP-computed timestamps (not DB clock) to avoid clock-drift bugs.
 * Requires the `rate_limits` table (see sql/security-upgrade.sql).
 */

require_once __DIR__ . '/db.php';

/** العمليات اللي لو العدّاد وقع بتترفض بدل ما تعدّي (المرحلة 6) */
const RATE_FAIL_CLOSED = ['login', 'login_acct', 'admin_login', 'admin_login_acct', 'login_verify', '2fa_verify', 'forgot', 'register'];
/** حد المحاولات الغلط لكل حساب (من أي IP) — بيوقف التخمين الموزّع على حساب واحد */
const LOGIN_ACCT_MAX = 10;
const LOGIN_ACCT_WINDOW = 900;

/** مفتاح الحساب في عدّاد الدخول (الإيميل متشفّر — مش بيتخزن نص) */
function login_acct_key(string $email): string
{
    return 'e' . substr(hash('sha256', mb_strtolower(trim($email))), 0, 40);
}

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
        // المرحلة 6: الدخول والتحقق والتسجيل بيقفلوا لو العدّاد مش شغال (مايبقوش من غير حماية في صمت) — الباقي بيكمّل زي الأول
        error_log('[rate_limit] ' . $action . ': ' . $e->getMessage());
        return !in_array($action, RATE_FAIL_CLOSED, true);
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

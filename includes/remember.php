<?php
/**
 * Spread AI — «افتكرني» / الدخول المستمر (المرحلة 10)
 *
 * الكوكي فيها «selector:validator» عشوائيين بس — مفيش باسورد ولا إيميل ولا أي بيانات حساسة.
 *   • قاعدة البيانات بتخزّن hash(validator) — لو اتسرّبت مابتدخّلش حد.
 *   • كل استخدام بيغيّر الـ validator (rotation) — والتوكن القديم لو اتستخدم تاني = سرقة ← كل توكنات العميل بتتلغي.
 *   • HttpOnly · Secure (على https) · SameSite=Lax · صلاحية remember_days (افتراضي 30 يوم).
 *   • الخروج بيلغي توكن الجهاز ده · «خروج من الأجهزة الأخرى» وتغيير الباسورد بيلغوا الباقي.
 */

const REMEMBER_COOKIE = 'spread_rm';

function remember_days(): int
{
    return max(1, min(365, (int) (function_exists('get_setting') ? get_setting('remember_days', 30) : 30)));
}

/** افتراضي النظام: «افتكرني» متعلّم (والجوجل بيستخدمه) */
function remember_default(): bool
{
    return !function_exists('get_setting') || get_setting('remember_default', '1') === '1';
}

function remember_ready(): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        db()->query('SELECT 1 FROM user_remember_tokens LIMIT 1');
        return $ok = true;
    } catch (\Throwable $e) {
        return $ok = false;
    }
}

function remember_set_cookie(string $value, int $expires): void
{
    if (headers_sent()) return;
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    setcookie(REMEMBER_COOKIE, $value, ['expires' => $expires, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
    if ($value === '') unset($_COOKIE[REMEMBER_COOKIE]); else $_COOKIE[REMEMBER_COOKIE] = $value;
}

/** @return array{0:string,1:string}|null */
function remember_parse_cookie(): ?array
{
    $c = (string) ($_COOKIE[REMEMBER_COOKIE] ?? '');
    if (!preg_match('/^([a-f0-9]{24}):([a-f0-9]{64})$/', $c, $m)) return null;
    return [$m[1], $m[2]];
}

/** إصدار توكن جديد للجهاز ده (بعد تسجيل الدخول) */
function remember_issue(int $userId): void
{
    if (!remember_ready() || !empty($_SESSION['impersonator_admin_id'])) return;
    try {
        remember_forget_current(false);
        $sel = bin2hex(random_bytes(12));
        $val = bin2hex(random_bytes(32));
        $exp = time() + remember_days() * 86400;
        db_insert('INSERT INTO user_remember_tokens (user_id, selector, validator_hash, user_agent, ip, expires_at, sid_hash) VALUES (?,?,?,?,?,?,?)',
            [$userId, $sel, hash('sha256', $val), mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
             mb_substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45), date('Y-m-d H:i:s', $exp),
             function_exists('account_sid_hash') ? account_sid_hash() : null]);
        remember_set_cookie($sel . ':' . $val, $exp);
        // توكنات منتهية قديمة للعميل ده بتتمسح
        db_run('DELETE FROM user_remember_tokens WHERE user_id = ? AND (expires_at < NOW() OR revoked_at < NOW() - INTERVAL 30 DAY)', [$userId]);
    } catch (\Throwable $e) {
        error_log('[remember] issue ' . $e->getMessage());
    }
}

/**
 * مفيش جلسة بس فيه كوكي «افتكرني» صالحة ← دخول تلقائي آمن + تغيير الـ validator
 * بيتنادى أول ما auth.php يتحمّل.
 */
function remember_try_login(): void
{
    if (!empty($_SESSION['user_id']) || session_status() !== PHP_SESSION_ACTIVE || !remember_ready()) return;
    $p = remember_parse_cookie();
    if (!$p) return;
    [$sel, $val] = $p;
    try {
        $row = db_one('SELECT * FROM user_remember_tokens WHERE selector = ?', [$sel]);
        if (!$row || $row['revoked_at'] !== null || strtotime((string) $row['expires_at']) < time()) {
            remember_set_cookie('', time() - 3600);
            return;
        }
        if (!hash_equals((string) $row['validator_hash'], hash('sha256', $val))) {
            // selector صح و validator غلط = توكن قديم اتسرق واتستخدم ← نلغي كل توكنات العميل
            db_run('UPDATE user_remember_tokens SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL', [(int) $row['user_id']]);
            remember_set_cookie('', time() - 3600);
            error_log('[remember] validator mismatch — all tokens revoked for user ' . (int) $row['user_id']);
            return;
        }
        $u = db_one('SELECT id, name, status, email_verified_at, approval_status, two_fa_enabled FROM users WHERE id = ?', [(int) $row['user_id']]);
        if (!$u || $u['status'] !== 'active' || empty($u['email_verified_at']) || ($u['approval_status'] ?? 'approved') !== 'approved') {
            db_run('UPDATE user_remember_tokens SET revoked_at = NOW() WHERE id = ?', [$row['id']]);
            remember_set_cookie('', time() - 3600);
            return;
        }
        // تغيير الـ validator في كل استخدام + تمديد الصلاحية
        $newVal = bin2hex(random_bytes(32));
        $exp = time() + remember_days() * 86400;
        db_run('UPDATE user_remember_tokens SET validator_hash = ?, last_used_at = NOW(), expires_at = ?, ip = ? WHERE id = ?',
            [hash('sha256', $newVal), date('Y-m-d H:i:s', $exp), mb_substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45), $row['id']]);
        remember_set_cookie($sel . ':' . $newVal, $exp);

        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $u['id'];
        $_SESSION['user_name'] = $u['name'];
        $_SESSION['remembered'] = 1;
        if (function_exists('account_session_register')) account_session_register((int) $u['id']);
        if (function_exists('account_sid_hash')) db_run('UPDATE user_remember_tokens SET sid_hash = ? WHERE id = ?', [account_sid_hash(), $row['id']]);
    } catch (\Throwable $e) {
        error_log('[remember] login ' . $e->getMessage());
    }
}

/** إلغاء توكن الجهاز الحالي (الخروج) */
function remember_forget_current(bool $clearCookie = true): void
{
    $p = remember_parse_cookie();
    if ($p && remember_ready()) {
        try { db_run('UPDATE user_remember_tokens SET revoked_at = NOW() WHERE selector = ? AND revoked_at IS NULL', [$p[0]]); } catch (\Throwable $e) {}
    }
    if ($clearCookie && isset($_COOKIE[REMEMBER_COOKIE])) remember_set_cookie('', time() - 3600);
}

/** إلغاء كل توكنات العميل ما عدا الجهاز ده (خروج من الأجهزة الأخرى · تغيير الباسورد) */
function remember_revoke_others(int $userId): int
{
    if (!remember_ready()) return 0;
    $p = remember_parse_cookie();
    try {
        $st = db()->prepare('UPDATE user_remember_tokens SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL AND selector <> ?');
        $st->execute([$userId, $p[0] ?? '']);
        return $st->rowCount();
    } catch (\Throwable $e) {
        return 0;
    }
}

/** إنهاء جلسة معيّنة من الإعدادات ← «افتكرني» بتاع نفس الجهاز بيتلغي */
function remember_revoke_session(int $userId, string $sidHash): void
{
    if (!remember_ready() || $sidHash === '') return;
    try { db_run('UPDATE user_remember_tokens SET revoked_at = NOW() WHERE user_id = ? AND sid_hash = ? AND revoked_at IS NULL', [$userId, $sidHash]); } catch (\Throwable $e) {}
}

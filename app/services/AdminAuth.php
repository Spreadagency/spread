<?php
declare(strict_types=1);

/**
 * Admin authentication and roles.
 *   owner  — everything
 *   editor — leads, images, SEO, page content (no settings / API / users)
 *   viewer — read-only
 */
final class AdminAuth
{
    private const IDLE_LIMIT = 8 * 3600;
    private static ?array $user = null;
    private static bool $loaded = false;

    public static function user(): ?array
    {
        if (self::$loaded) {
            return self::$user;
        }
        self::$loaded = true;
        $id = (int) ($_SESSION['admin_id'] ?? 0);
        if ($id <= 0) {
            return null;
        }
        // Expire idle sessions and sessions moved to another browser.
        if ((time() - (int) ($_SESSION['admin_seen'] ?? 0)) > self::IDLE_LIMIT || ($_SESSION['admin_fp'] ?? '') !== self::fingerprint()) {
            self::logout();
            return null;
        }
        $_SESSION['admin_seen'] = time();
        self::$user = q_row('SELECT id, name, email, role, must_change_password, last_login_at FROM admins WHERE id = ?', [$id]);
        if (!self::$user) {
            self::logout();
        }
        return self::$user;
    }

    /** @return array{ok:bool, error?:string} */
    public static function attempt(string $email, string $password): array
    {
        $email = mb_strtolower(trim($email));
        $ip = client_ip();
        if (!RateLimiter::hit('login', 'ip:' . $ip, 5, 900) || !RateLimiter::hit('login', 'email:' . $email, 5, 900)) {
            return ['ok' => false, 'error' => 'محاولات كتير غلط. استنى 15 دقيقة وجرّب تاني.'];
        }
        $row = q_row('SELECT * FROM admins WHERE email = ?', [$email]);
        // Always run password_verify so response time doesn't reveal valid emails.
        $hash = $row['password_hash'] ?? '$2y$12$50Jt2F2.gI7Qq91sInMSlO5Dq4qJa7EYQ.gGvAiqxSJiWRqO/dBj6';
        if (!password_verify($password, $hash) || !$row) {
            app_log('warning', 'Admin login failed', ['email' => $email, 'ip' => $ip]);
            return ['ok' => false, 'error' => 'الإيميل أو الباسورد غلط.'];
        }
        if (password_needs_rehash($row['password_hash'], PASSWORD_BCRYPT, ['cost' => 12])) {
            q('UPDATE admins SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]), $row['id']]);
        }
        session_regenerate_id(true);
        $_SESSION['admin_id'] = (int) $row['id'];
        $_SESSION['admin_seen'] = time();
        $_SESSION['admin_fp'] = self::fingerprint();
        unset($_SESSION['csrf']); // new token for the authenticated session
        q('UPDATE admins SET last_login_at = ? WHERE id = ?', [utc_now(), $row['id']]);
        RateLimiter::clear('login', 'ip:' . $ip);
        RateLimiter::clear('login', 'email:' . $email);
        self::$loaded = false;
        admin_log('login', null, (int) $row['id']);
        return ['ok' => true];
    }

    public static function logout(): void
    {
        unset($_SESSION['admin_id'], $_SESSION['admin_seen'], $_SESSION['admin_fp']);
        session_regenerate_id(true);
        self::$user = null;
    }

    /** $perm: view | edit | owner */
    public static function can(string $perm): bool
    {
        $role = self::user()['role'] ?? null;
        return match ($perm) {
            'view' => $role !== null,
            'edit' => in_array($role, ['owner', 'editor'], true),
            'owner' => $role === 'owner',
            default => false,
        };
    }

    /** Optional IP allowlist (settings: admin_ip_allowlist, comma separated IPs / IPv4 CIDRs). */
    public static function ipAllowed(?string $raw = null): bool
    {
        $list = array_filter(array_map('trim', explode(',', $raw ?? (string) Settings::get('admin_ip_allowlist', ''))));
        if (!$list) {
            return true;
        }
        $ip = client_ip();
        foreach ($list as $entry) {
            if ($entry === $ip) {
                return true;
            }
            if (str_contains($entry, '/') && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                [$net, $bits] = explode('/', $entry, 2);
                $bits = (int) $bits;
                if ($bits >= 0 && $bits <= 32 && filter_var($net, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    $mask = $bits === 0 ? 0 : (~0 << (32 - $bits)) & 0xFFFFFFFF;
                    if ((ip2long($ip) & $mask) === (ip2long($net) & $mask)) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    private static function fingerprint(): string
    {
        return hash('sha256', user_agent() . '|' . config('app_key'));
    }
}

function admin_log(string $action, ?string $details = null, ?int $adminId = null): void
{
    try {
        q('INSERT INTO admin_logs (admin_id, action, details, created_at) VALUES (?, ?, ?, ?)', [
            $adminId ?? (AdminAuth::user()['id'] ?? null), $action, $details !== null ? mb_substr($details, 0, 2000) : null, utc_now(),
        ]);
    } catch (Throwable $e) {
        log_error('admin_log failed: ' . $e->getMessage());
    }
}

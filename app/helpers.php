<?php
declare(strict_types=1);

/* ============================================================
   Output
   ============================================================ */

function e(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Escape and turn newlines into <br>. */
function e_nl(?string $s): string
{
    return nl2br(e($s), false);
}

function is_api_request(): bool
{
    return str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/api/');
}

/**
 * Send JSON and stop. Tasks queued with defer() run after the response is
 * flushed to the visitor (PHP-FPM / LiteSpeed), so CAPI and webhooks never
 * slow the page down.
 */
function json_response(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    finish_request();
    run_deferred();
    exit;
}

/** Flush the response to the client if the SAPI supports it. */
function finish_request(): bool
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    if (function_exists('fastcgi_finish_request')) {
        return fastcgi_finish_request();
    }
    if (function_exists('litespeed_finish_request')) {
        return litespeed_finish_request();
    }
    return false;
}

function can_finish_request(): bool
{
    return function_exists('fastcgi_finish_request') || function_exists('litespeed_finish_request');
}

function defer(callable $task): void
{
    $GLOBALS['__deferred'][] = $task;
}

function run_deferred(): void
{
    ignore_user_abort(true);
    foreach ($GLOBALS['__deferred'] ?? [] as $task) {
        try {
            $task();
        } catch (Throwable $e) {
            log_error('Deferred task failed: ' . $e->getMessage());
        }
    }
    $GLOBALS['__deferred'] = [];
}

/* ============================================================
   Input
   ============================================================ */

function require_method(string $method): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== $method) {
        header('Allow: ' . $method);
        json_response(['error' => 'Method not allowed'], 405);
    }
}

/** JSON body (or form fields as a fallback). */
function input_json(): array
{
    $raw = file_get_contents('php://input') ?: '';
    if ($raw !== '' && (str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'json') || $raw[0] === '{')) {
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }
    return $_POST;
}

function str_in(array $a, string $key, int $max = 255): ?string
{
    $v = $a[$key] ?? null;
    if (!is_scalar($v)) {
        return null;
    }
    $v = trim((string) $v);
    return $v === '' ? null : mb_substr($v, 0, $max);
}

function client_ip(): string
{
    if (config('behind_cloudflare') && !empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        $ip = $_SERVER['HTTP_CF_CONNECTING_IP'];
    } else {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
}

function user_agent(): string
{
    return mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
}

/**
 * Egyptian mobile normalization (spec §5):
 * Arabic-Indic digits → Latin, strip separators, +20 / 0020 / 20 → 0.
 * Returns 01xxxxxxxxx or null when invalid.
 */
function normalize_phone(?string $v): ?string
{
    if ($v === null) {
        return null;
    }
    $p = strtr($v, [
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
    ]);
    $p = preg_replace('/[\s\-().\x{200E}\x{200F}]/u', '', $p) ?? '';
    if (str_starts_with($p, '+20')) {
        $p = '0' . substr($p, 3);
    } elseif (str_starts_with($p, '0020')) {
        $p = '0' . substr($p, 4);
    } elseif (preg_match('/^20\d{10}$/', $p)) {
        $p = '0' . substr($p, 2);
    }
    return preg_match('/^01[0125][0-9]{8}$/', $p) ? $p : null;
}

/** 01xxxxxxxxx → 201xxxxxxxxx (CAPI / wa.me format). */
function phone_international(string $phone): string
{
    return '20' . ltrim($phone, '0');
}

function first_name(string $name): string
{
    $parts = preg_split('/\s+/u', trim($name)) ?: [''];
    return $parts[0];
}

/* ============================================================
   URLs
   ============================================================ */

/** Base URL of the public folder, no trailing slash. */
function base_url(): string
{
    static $base = null;
    if ($base !== null) {
        return $base;
    }
    $configured = rtrim((string) config('app_url', ''), '/');
    if ($configured !== '') {
        return $base = $configured;
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $script = str_replace('\\', '/', (string) realpath($_SERVER['SCRIPT_FILENAME'] ?? ''));
    $public = str_replace('\\', '/', (string) realpath(PUBLIC_PATH));
    $rel = str_starts_with($script, $public) ? substr($script, strlen($public)) : '';
    $name = $_SERVER['SCRIPT_NAME'] ?? '';
    $path = ($rel !== '' && str_ends_with($name, $rel)) ? substr($name, 0, -strlen($rel)) : '';
    return $base = ($https ? 'https' : 'http') . '://' . $host . rtrim($path, '/');
}

function url(string $path = ''): string
{
    return base_url() . '/' . ltrim($path, '/');
}

/** Asset URL with a cache-busting version from the file's mtime. */
function asset(string $path): string
{
    if (preg_match('#^https?://#', $path)) {
        return $path;
    }
    $file = PUBLIC_PATH . '/' . ltrim($path, '/');
    $v = is_file($file) ? '?v=' . filemtime($file) : '';
    return url($path) . $v;
}

function share_url(string $token): string
{
    return url('r/' . $token);
}

/* ============================================================
   Signing (HMAC with APP_KEY)
   ============================================================ */

function sign(string $data, string $purpose): string
{
    return rtrim(strtr(base64_encode(hash_hmac('sha256', $purpose . '|' . $data, (string) config('app_key'), true)), '+/', '-_'), '=');
}

function sign_valid(string $data, string $purpose, string $sig): bool
{
    return hash_equals(sign($data, $purpose), $sig);
}

/** Signed, expiring URL for a protected image. $kind: before|after|og */
function image_url(int $genId, string $kind, int $ttl = 86400): string
{
    $exp = time() + $ttl;
    $data = $genId . ':' . $kind . ':' . $exp;
    return url('img.php') . '?' . http_build_query(['g' => $genId, 'k' => $kind, 'e' => $exp, 's' => sign($data, 'img')]);
}

/** Public image of a shared result (the share token is the capability). */
function shared_image_url(string $token, string $kind = 'after'): string
{
    return url('img.php') . '?' . http_build_query(['r' => $token, 'k' => $kind]);
}

/* ============================================================
   Session, cookies, current lead
   ============================================================ */

function is_https(): bool
{
    return str_starts_with(base_url(), 'https://');
}

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name('slim_sess');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    ini_set('session.use_strict_mode', '1');
    session_start();
}

function set_cookie(string $name, string $value, int $ttl): void
{
    setcookie($name, $value, [
        'expires' => $ttl > 0 ? time() + $ttl : time() - 3600,
        'path' => '/',
        'secure' => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    $_COOKIE[$name] = $value;
}

/** Anonymous per-browser id used for per-device limits. */
function device_cookie(): string
{
    $v = $_COOKIE['dvc'] ?? '';
    if (!preg_match('/^[a-f0-9]{32}$/', $v)) {
        $v = bin2hex(random_bytes(16));
        set_cookie('dvc', $v, 365 * 86400);
    }
    return $v;
}

function remember_lead(int $leadId): void
{
    session_regenerate_id(true);
    $_SESSION['lead_id'] = $leadId;
    $exp = time() + 30 * 86400;
    set_cookie('lead_ref', $leadId . '.' . $exp . '.' . sign($leadId . '.' . $exp, 'lead'), 30 * 86400);
}

/** Lead id from the session, or from the signed lead_ref cookie. */
function current_lead_id(): ?int
{
    if (!empty($_SESSION['lead_id'])) {
        return (int) $_SESSION['lead_id'];
    }
    $ref = $_COOKIE['lead_ref'] ?? '';
    if (preg_match('/^(\d+)\.(\d+)\.([A-Za-z0-9_-]+)$/', $ref, $m) && (int) $m[2] > time() && sign_valid($m[1] . '.' . $m[2], 'lead', $m[3])) {
        $_SESSION['lead_id'] = (int) $m[1];
        return (int) $m[1];
    }
    return null;
}

function current_lead(): ?array
{
    $id = current_lead_id();
    return $id ? q_row('SELECT * FROM leads WHERE id = ?', [$id]) : null;
}

function require_lead(): array
{
    $lead = current_lead();
    if (!$lead) {
        json_response(['error' => 'سجّل اسمك ورقمك الأول.', 'code' => 'no_lead'], 401);
    }
    return $lead;
}

/* ============================================================
   Events, logging
   ============================================================ */

function new_event_id(): string
{
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}

function clean_event_id(?string $id): string
{
    return ($id !== null && preg_match('/^[A-Za-z0-9._-]{8,64}$/', $id)) ? $id : new_event_id();
}

function log_event(string $type, ?int $leadId = null, ?int $genId = null, ?string $eventId = null, array $meta = []): void
{
    try {
        q('INSERT INTO events (lead_id, generation_id, type, event_id, meta, ip, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)', [
            $leadId, $genId, $type, $eventId, $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null, client_ip(), utc_now(),
        ]);
    } catch (Throwable $e) {
        log_error('log_event failed: ' . $e->getMessage());
    }
}

function log_error(string $message, array $context = []): void
{
    app_log('error', $message, $context);
}

function app_log(string $level, string $message, array $context = []): void
{
    $line = sprintf("[%s] %s: %s%s\n", date('c'), strtoupper($level), $message, $context ? ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '');
    @file_put_contents(LOG_PATH . '/app-' . date('Y-m-d') . '.log', $line, FILE_APPEND | LOCK_EX);
}

/* ============================================================
   HTTP client (curl)
   ============================================================ */

/**
 * @return array{status:int, body:string, error:?string, ms:int}
 */
function http_post_json(string $url, array $payload, array $headers = [], int $timeout = 10): array
{
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return http_request('POST', $url, $body, array_merge(['Content-Type: application/json'], $headers), $timeout);
}

function http_request(string $method, string $url, ?string $body, array $headers = [], int $timeout = 10): array
{
    $start = microtime(true);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_USERAGENT => 'SlimSimulator/1.0',
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $resp = curl_exec($ch);
    $err = $resp === false ? curl_error($ch) : null;
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $status, 'body' => $resp === false ? '' : (string) $resp, 'error' => $err, 'ms' => (int) round((microtime(true) - $start) * 1000)];
}

/* ============================================================
   Page security headers
   ============================================================ */

function send_page_headers(): void
{
    $extra = trim(preg_replace('/[^a-zA-Z0-9.:\/*\-\s]/', '', (string) Settings::get('csp_extra_hosts', '')) ?? '');
    $csp = [
        "default-src 'self'",
        "script-src 'self' 'unsafe-inline' https://connect.facebook.net https://www.googletagmanager.com https://www.google-analytics.com https://challenges.cloudflare.com $extra",
        "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
        "font-src 'self' https://fonts.gstatic.com data:",
        "img-src 'self' data: blob: https://www.facebook.com https://www.google-analytics.com https://www.googletagmanager.com https://*.google.com https://*.googleapis.com https://*.gstatic.com $extra",
        "connect-src 'self' https://www.facebook.com https://connect.facebook.net https://*.google-analytics.com https://*.analytics.google.com https://www.googletagmanager.com https://challenges.cloudflare.com $extra",
        "frame-src https://www.google.com https://maps.google.com https://challenges.cloudflare.com https://www.googletagmanager.com $extra",
        "base-uri 'self'",
        "form-action 'self'",
        "frame-ancestors 'self'",
    ];
    header('Content-Security-Policy: ' . preg_replace('/\s+/', ' ', implode('; ', $csp)));
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('X-Frame-Options: SAMEORIGIN');
    header('Permissions-Policy: camera=(self), microphone=(), geolocation=()');
}

/* ============================================================
   Content
   ============================================================ */

function content_items(string $type): array
{
    try {
        return q('SELECT * FROM content_items WHERE type = ? AND is_active = 1 ORDER BY sort_order, id', [$type])->fetchAll();
    } catch (Throwable $e) {
        log_error('content_items failed: ' . $e->getMessage());
        return [];
    }
}

function branches(): array
{
    try {
        return q('SELECT * FROM branches WHERE is_active = 1 ORDER BY sort_order, id')->fetchAll();
    } catch (Throwable $e) {
        log_error('branches failed: ' . $e->getMessage());
        return [];
    }
}

function whatsapp_link(?string $name = null): string
{
    $num = preg_replace('/\D/', '', (string) Settings::get('whatsapp_number', ''));
    if ($num === '') {
        return '#';
    }
    $msg = str_replace('{name}', $name ?: 'مهتم', (string) Settings::get('whatsapp_message'));
    return 'https://wa.me/' . $num . '?text=' . rawurlencode($msg);
}

/** Validate a #RRGGBB color from settings. */
function css_color(string $key, string $fallback): string
{
    $c = (string) Settings::get($key, $fallback);
    return preg_match('/^#[0-9a-fA-F]{6}$/', $c) ? $c : $fallback;
}

/** Status payload for a generation, as returned by the API. */
function generation_payload(array $gen): array
{
    $status = $gen['status'];
    $out = ['status' => $status, 'id' => (int) $gen['id']];
    if ($status === 'done' && $gen['result_path']) {
        $out['token'] = $gen['share_token'];
        $out['after'] = image_url((int) $gen['id'], 'after');
        $out['before'] = $gen['original_path'] ? image_url((int) $gen['id'], 'before') : null;
        $out['share_url'] = share_url((string) $gen['share_token']);
    }
    if (in_array($status, ['failed', 'rejected'], true)) {
        $out['message'] = $status === 'rejected'
            ? 'محتاجين صورة واضحة لشخص واحد بالغ — جرّب صورة تانية'
            : 'حصلت مشكلة بسيطة، جرّب تاني';
    }
    return $out;
}

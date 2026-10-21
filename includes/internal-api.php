<?php
/**
 * Spread AI — Internal API (نظام الجودة · MCP) — الأساس
 *
 * بيستخدمه خادم الـ MCP بس (مش المتصفح):
 *   Authorization: Bearer <INTERNAL_API_TOKEN>   (أو X-Internal-Token لو Apache شال Authorization)
 *   X-Scope: read | ai_write | draft             (الصلاحية المطلوبة — لازم تطابق المسار)
 *   X-Client: claude | n8n | test                (للسجل)
 *
 * الحماية (طبقات):
 *   1) IP خادم الـ MCP بس (INTERNAL_API_IPS) · 2) التوكن (مقارنة آمنة) · 3) الصلاحية = نوع المسار ·
 *   4) اتصال MySQL منفصل لكل صلاحية بمستخدم صلاحياته محدودة (read = SELECT · ai_write/draft = قاعدة الـ AI بس) ·
 *   5) حد للطلبات · 6) إخفاء البيانات الشخصية من كل رد · 7) سجل لكل طلب في mcp_audit_log
 * الـ MCP مايقدرش يحذف ولا يلمس الكريدت أو الدفع أو الحسابات أو الأفلييت — ده متقفل في MySQL نفسه مش في الكود بس.
 */

// ─── من غير كوكيز ولا جلسة متصفح (قبل config.php) ───
if (!defined('SPREAD_INTERNAL_API')) define('SPREAD_INTERNAL_API', true);
$_COOKIE = [];
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_cookies', '0');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    session_id('iapi' . bin2hex(random_bytes(12)));
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/rate-limit.php';
require_once __DIR__ . '/credits.php';   // get_setting
require_once __DIR__ . '/mcp-config.php'; // قاعدة الـ AI + اتصال لكل صلاحية

register_shutdown_function(function () {
    if (session_status() === PHP_SESSION_ACTIVE) { $_SESSION = []; @session_destroy(); }
});

const IAPI_SCOPES = ['read', 'ai_write', 'draft'];
const IAPI_MAX_LIMIT = 50;

/* ═══════════ استعلامات بصلاحية الطلب ═══════════ */

function iq_all(string $sql, array $p = [], string $scope = 'read'): array
{
    $st = iapi_pdo($scope)->prepare($sql);
    $st->execute($p);
    return $st->fetchAll();
}

function iq_one(string $sql, array $p = [], string $scope = 'read'): ?array
{
    $st = iapi_pdo($scope)->prepare($sql);
    $st->execute($p);
    $r = $st->fetch();
    return $r ?: null;
}

function iq_val(string $sql, array $p = [], string $scope = 'read')
{
    $r = iq_one($sql, $p, $scope);
    return $r ? reset($r) : null;
}

function iq_exec(string $sql, array $p, string $scope): int
{
    $st = iapi_pdo($scope)->prepare($sql);
    $st->execute($p);
    return (int) iapi_pdo($scope)->lastInsertId() ?: $st->rowCount();
}

/* ═══════════ الطلب ═══════════ */

function iapi_ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '');
}

function iapi_header(string $name): string
{
    $k = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    return trim((string) ($_SERVER[$k] ?? $_SERVER['REDIRECT_' . $k] ?? ''));
}

function iapi_token(): string
{
    $h = iapi_header('Authorization');
    if (preg_match('/^Bearer\s+(\S+)$/i', $h, $m)) return $m[1];
    return iapi_header('X-Internal-Token');
}

/** المدخلات: JSON في الجسم + الـ query string */
function iapi_in(): array
{
    static $in = null;
    if ($in !== null) return $in;
    $raw = (string) file_get_contents('php://input');
    $j = $raw !== '' ? json_decode($raw, true) : null;
    $in = (is_array($j) ? $j : []) + $_GET;
    unset($in['r']);
    return $in;
}

function iapi_str(string $k, int $max = 500, string $default = ''): string
{
    $v = iapi_in()[$k] ?? $default;
    return is_scalar($v) ? mb_substr(trim((string) $v), 0, $max) : $default;
}

function iapi_int(string $k, int $default = 0, ?int $min = null, ?int $max = null): int
{
    $v = iapi_in()[$k] ?? null;
    $v = is_numeric($v) ? (int) $v : $default;
    if ($min !== null) $v = max($min, $v);
    if ($max !== null) $v = min($max, $v);
    return $v;
}

function iapi_arr(string $k): array
{
    $v = iapi_in()[$k] ?? [];
    if (is_string($v)) { $d = json_decode($v, true); $v = is_array($d) ? $d : []; }
    return is_array($v) ? $v : [];
}

function iapi_date(string $k, string $default): string
{
    $v = iapi_str($k, 10);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : $default;
}

/** limit (≤ 50) + cursor (offset) */
function iapi_page(int $default = 20): array
{
    return [iapi_int('limit', $default, 1, IAPI_MAX_LIMIT), iapi_int('cursor', 0, 0, 100000)];
}

/* ═══════════ إخفاء البيانات الشخصية ═══════════ */

function iapi_mask_email(string $e): string
{
    if (!str_contains($e, '@')) return $e;
    [$u, $d] = explode('@', $e, 2);
    return mb_substr($u, 0, 1) . '***@' . $d;
}

function iapi_mask_phone(string $p): string
{
    $digits = preg_replace('/\D/', '', $p);
    return strlen($digits) < 7 ? '***' : substr($digits, 0, 4) . '***' . substr($digits, -3);
}

/** كل الردود بتعدي على ده: مفاتيح حساسة بتتشال · إيميلات وأرقام تليفونات في أي نص بتتخفى */
function iapi_mask($v, string $key = '')
{
    static $drop = ['password', 'access_token', 'access_token_enc', 'token', 'refresh_token', 'secret', 'api_key', 'device_hash', 'card', 'card_number', 'iban', 'receipt_path', 'otp'];
    static $phoneKeys = ['phone', 'phones', 'whatsapp', 'wa_phone', 'whatsapp_phone', 'mobile'];
    static $ipKeys = ['signup_ip', 'ip', 'ip_address', 'last_ip'];
    $lk = strtolower($key);
    if (is_array($v)) {
        $out = [];
        foreach ($v as $k => $x) {
            if (is_string($k) && in_array(strtolower($k), $drop, true)) continue;
            $out[$k] = iapi_mask($x, is_string($k) ? $k : $key);
        }
        return $out;
    }
    if (!is_string($v) || $v === '') return $v;
    if ($lk === 'email') return iapi_mask_email($v);
    if (in_array($lk, $phoneKeys, true)) return implode('، ', array_map('iapi_mask_phone', preg_split('/[,،\n\/]+/u', $v)));
    if (in_array($lk, $ipKeys, true)) return preg_replace('/\.\d+\.\d+$/', '.x.x', $v);
    // جوه أي نص (الهوية · المنشورات · البلاغات)
    $v = preg_replace_callback('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', fn($m) => iapi_mask_email($m[0]), $v);
    $v = preg_replace_callback('/(?<!\d)(?:\+?20|0020)?\s?0?1[0125][\s\-]?\d{3,4}[\s\-]?\d{4}(?!\d)/', fn($m) => iapi_mask_phone($m[0]), $v);
    return $v;
}

/* ═══════════ الرد والسجل ═══════════ */

function iapi_send(array $payload, int $code = 200): void
{
    while (ob_get_level() > 0) ob_end_clean();
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex');
    header('X-Content-Type-Options: nosniff');
    iapi_audit($payload['ok'] ? 'ok' : (string) ($payload['code'] ?? 'error'));
    echo json_encode(iapi_mask($payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function iapi_ok(array $data = []): void
{
    iapi_send(['ok' => true] + $data);
}

/** الخطأ نص عادي واضح — Claude بيقراه ويتصرف */
function iapi_fail(string $error, string $code = 'error', int $http = 400): void
{
    iapi_send(['ok' => false, 'error' => $error, 'code' => $code], $http);
}

/** سجل كل طلب (mcp_audit_log في قاعدة الـ AI) — فشل السجل مايوقّفش الطلب */
function iapi_audit(string $status): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $ctx = $GLOBALS['__iapi'] ?? [];
        $params = iapi_mask(iapi_in());
        $json = json_encode($params, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if (strlen((string) $json) > 4000) $json = json_encode(['_truncated' => true, 'keys' => array_keys($params)]);
        $scope = in_array($ctx['scope'] ?? '', IAPI_SCOPES, true) ? 'ai_write' : null;
        $sql = 'INSERT INTO ' . mcp_t('mcp_audit_log') . ' (client, tool, scope, params, result_status, duration_ms, ip) VALUES (?,?,?,?,?,?,?)';
        $vals = [mb_substr(iapi_header('X-Client') ?: 'unknown', 0, 40), mb_substr((string) ($ctx['route'] ?? '?'), 0, 80),
                 mb_substr((string) ($ctx['scope'] ?? '-'), 0, 10), $json, mb_substr($status, 0, 20),
                 isset($ctx['t0']) ? (int) round((microtime(true) - $ctx['t0']) * 1000) : null, mb_substr(iapi_ip(), 0, 45)];
        // السجل بيتكتب بمستخدم ai_write (له INSERT على قاعدة الـ AI) — حتى لطلبات القراءة
        if ($scope) iq_exec($sql, $vals, 'ai_write');
        else db_run($sql, $vals);
    } catch (\Throwable $e) {
        error_log('[iapi] audit: ' . $e->getMessage());
    }
}

/* ═══════════ البوابة ═══════════ */

/** كل الشروط قبل أي مسار — بيرجع [route, scope] */
function iapi_boot(array $routes): array
{
    $GLOBALS['__iapi'] = ['t0' => microtime(true), 'route' => (string) ($_GET['r'] ?? ''), 'scope' => '-'];
    set_exception_handler(function (\Throwable $e) {
        error_log('[iapi] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        $msg = $e instanceof \PDOException && str_contains($e->getMessage(), 'denied')
            ? 'العملية دي مش مسموحة لصلاحية الـ MCP (الحماية في قاعدة البيانات رفضتها)' : 'حصلت مشكلة في السيرفر — جرّب تاني بعد شوية';
        iapi_fail($msg, $e instanceof \PDOException && str_contains($e->getMessage(), 'denied') ? 'db_denied' : 'server_error', $e instanceof \PDOException && str_contains($e->getMessage(), 'denied') ? 403 : 500);
    });

    $token = iapi_conf('INTERNAL_API_TOKEN');
    if ($token === '' || strlen($token) < 32) iapi_fail('الـ Internal API مش متفعّل — حط INTERNAL_API_TOKEN (32 حرف على الأقل) في config.php', 'disabled', 503);

    // 1) الـ IP
    $ips = array_filter(array_map('trim', explode(',', iapi_conf('INTERNAL_API_IPS'))));
    if (!$ips) $ips = ['127.0.0.1', '::1'];
    if (!in_array(iapi_ip(), $ips, true)) iapi_fail('العنوان ده مش مسموح له يستخدم الـ Internal API', 'ip_denied', 403);

    // 2) التوكن
    $given = iapi_token();
    if ($given === '' || !hash_equals($token, $given)) {
        if (function_exists('rate_limit_hit')) rate_limit_hit('iapi_auth', iapi_ip());
        iapi_fail('التوكن غلط أو ناقص (Authorization: Bearer …)', 'auth', 401);
    }

    // 3) الحد: 300 طلب/دقيقة لكل IP
    if (function_exists('rate_limit') && !rate_limit('iapi', iapi_ip(), (int) (get_setting('iapi_rate_per_min', '300') ?: 300), 60)) {
        iapi_fail('طلبات كتير — استنى دقيقة', 'rate_limit', 429);
    }

    // 4) المسار والصلاحية
    $route = (string) ($_GET['r'] ?? '');
    if (!isset($routes[$route])) iapi_fail('المسار ده مش موجود: «' . $route . '» — المسارات المتاحة: ' . implode('، ', array_keys($routes)), 'unknown_route', 404);
    $scope = iapi_header('X-Scope');
    if (!in_array($scope, IAPI_SCOPES, true)) iapi_fail('X-Scope لازم يكون read أو ai_write أو draft', 'scope', 400);
    if ($routes[$route][0] !== $scope) iapi_fail('المسار «' . $route . '» محتاج صلاحية ' . $routes[$route][0] . ' — الطلب جه بـ ' . $scope, 'scope_mismatch', 403);
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($scope !== 'read' && $method !== 'POST') iapi_fail('العمليات اللي بتكتب لازم POST', 'method', 405);

    $GLOBALS['__iapi']['route'] = $route;
    $GLOBALS['__iapi']['scope'] = $scope;
    return [$route, $scope];
}

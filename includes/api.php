<?php
/**
 * Spread AI v2 — طبقة الـ API (JSON)
 *
 * كل endpoint تحت public/api/ بيبدأ بـ:
 *     require_once __DIR__ . '/../../includes/api.php';
 *     $user = api_boot();
 *
 * القرارات:
 *  • كل الطلبات GET أو POST بس (بعض الاستضافات بتحجب PATCH/DELETE)،
 *    والفعل بيتحدد بـ action.
 *  • الجسم ممكن ييجي base64 (هيدر X-Payload: b64) — نفس الحل المجرّب
 *    للفورمات عشان mod_security مايحجبش العربي الطويل.
 *  • CSRF في الهيدر X-CSRF-Token للطلبات اللي بتغيّر بيانات.
 *  • أي استثناء بيرجع رسالة ودّية للعميل، والتفاصيل في اللوج.
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/credits.php';
require_once __DIR__ . '/rate-limit.php';
require_once __DIR__ . '/lifecycle.php';

/** إرسال رد وإنهاء */
function api_send(array $payload, int $code = 200): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function api_ok(array $data = [], int $code = 200): void
{
    api_send(['ok' => true] + $data, $code);
}

/**
 * @param string $error  رسالة للعميل (مفهومة، مش تقنية)
 * @param string $code   كود ثابت للواجهة تتصرف بناءً عليه
 */
function api_fail(string $error, string $code = 'error', int $http = 400, array $extra = []): void
{
    api_send(['ok' => false, 'error' => $error, 'code' => $code] + $extra, $http);
}

/** قراءة جسم الطلب (JSON عادي أو base64) مرة واحدة */
function api_input(): array
{
    static $in = null;
    if ($in !== null) {
        return $in;
    }
    $raw = (string) file_get_contents('php://input');
    $in = [];

    if ($raw !== '') {
        if (strtolower((string) ($_SERVER['HTTP_X_PAYLOAD'] ?? '')) === 'b64') {
            $dec = base64_decode($raw, true);
            $raw = $dec === false ? '' : $dec;
        }
        $j = json_decode($raw, true);
        if (is_array($j)) {
            $in = $j;
        }
    }
    // دعم الفورمات العادية كمان
    if (!$in && !empty($_POST)) {
        $in = $_POST;
    }
    return $in;
}

function api_param(string $key, $default = null)
{
    $in = api_input();
    return array_key_exists($key, $in) ? $in[$key] : ($_GET[$key] ?? $default);
}

/** نص نظيف محدود الطول */
function api_str(string $key, int $max = 500, string $default = ''): string
{
    $v = api_param($key, $default);
    if (!is_scalar($v)) {
        return $default;
    }
    $v = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', (string) $v));
    return mb_substr($v, 0, $max);
}

function api_int(string $key, int $default = 0, ?int $min = null, ?int $max = null): int
{
    $v = api_param($key, $default);
    $v = is_numeric($v) ? (int) $v : $default;
    if ($min !== null) $v = max($min, $v);
    if ($max !== null) $v = min($max, $v);
    return $v;
}

function api_action(): string
{
    return preg_replace('/[^a-z_]/', '', strtolower((string) api_param('action', ''))) ?: '';
}

/**
 * تهيئة: جلسة + تسجيل دخول + CSRF + حد الطلبات + معالج أخطاء
 * @return array المستخدم الحالي
 */
function api_boot(bool $requireAuth = true): array
{
    set_exception_handler(function (\Throwable $e) {
        error_log('[api] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        api_fail('حصلت مشكلة مؤقتة — جرّب تاني بعد لحظة', 'server_error', 500);
    });

    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (!in_array($method, ['GET', 'POST'], true)) {
        api_fail('طريقة الطلب مش مدعومة', 'method', 405);
    }

    $user = [];
    if ($requireAuth) {
        if (!function_exists('is_logged_in') || !is_logged_in()) {
            api_fail('انتهت الجلسة — سجّل دخولك تاني', 'auth', 401);
        }
        $user = current_user();
        if (!$user || ($user['status'] ?? 'active') !== 'active') {
            api_fail('الحساب موقوف', 'auth', 403);
        }
        // جلسة اتنهت من جهاز تاني (⑥-أ)
        if (function_exists('account_session_check') && !account_session_check((int) $user['id'])) {
            unset($_SESSION['user_id']);
            api_fail('الجلسة دي اتنهت من جهاز تاني — سجّل دخولك تاني', 'auth', 401);
        }
    }

    // CSRF للطلبات اللي بتغيّر بيانات
    if ($method === 'POST') {
        $tok = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? (api_input()['csrf'] ?? ''));
        if (empty($_SESSION['csrf']) || !hash_equals((string) $_SESSION['csrf'], $tok)) {
            api_fail('انتهت صلاحية الصفحة — حدّثها وجرّب تاني', 'csrf', 419);
        }
    }

    // حد الطلبات لكل مستخدم
    $limit = (int) (function_exists('get_setting') ? get_setting('api_rate_per_minute', 90) : 90);
    $key = 'u' . ($user['id'] ?? ('ip' . ($_SERVER['REMOTE_ADDR'] ?? '')));
    if (!rate_limit('api', $key, max(10, $limit), 60)) {
        api_fail('طلبات كتير بسرعة — استنى لحظة', 'rate_limit', 429);
    }

    return $user;
}

/** التأكد إن السجل ملك المستخدم (وإلا 404 — مانقولش إنه موجود) */
function api_own(string $table, int $id, int $userId): array
{
    if (!in_array($table, ['campaigns', 'contents', 'studio_designs', 'researches'], true)) {
        api_fail('غير مسموح', 'forbidden', 403);
    }
    $row = db_one("SELECT * FROM `{$table}` WHERE id = ? AND user_id = ?", [$id, $userId]);
    if (!$row) {
        api_fail('العنصر ده مش موجود', 'not_found', 404);
    }
    return $row;
}

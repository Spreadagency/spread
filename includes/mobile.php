<?php
/**
 * Spread AI — طبقة تطبيق الموبايل (توكنات الأجهزة · التحقق · منع الخصم المكرر)
 * بتتحمّل بعد includes/mobile-boot.php و includes/api.php (من public/api/v1/*)
 *
 * الجداول (إضافة بس — بتتعمل تلقائيًا أول مرة، ونسختها في sql/2026_mobile_api.sql):
 *   mobile_tokens       توكن لكل جهاز (بنخزّن sha256 بس) — access | challenge (التحقق بخطوتين) | handoff (فتح الموقع)
 *   mobile_idempotency  نتيجة كل عملية AI بمفتاح Idempotency-Key — إعادة نفس الطلب مابتخصمش تاني
 */

require_once __DIR__ . '/api.php';

const MOBILE_TOKEN_DAYS = 90;          // صلاحية توكن الجهاز (بتتجدد مع الاستخدام)
const MOBILE_CHALLENGE_SEC = 900;      // التحقق بخطوتين: 15 دقيقة
const MOBILE_HANDOFF_SEC = 120;        // فتح الموقع من التطبيق: دقيقتين
const MOBILE_SCHEMA_V = '1';

function mobile_schema_sql(): array
{
    $t = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    return [
        "CREATE TABLE IF NOT EXISTS `mobile_tokens` (
            `id`           BIGINT AUTO_INCREMENT PRIMARY KEY,
            `user_id`      INT NOT NULL,
            `token_hash`   CHAR(64) NOT NULL,
            `kind`         VARCHAR(12) NOT NULL DEFAULT 'access',
            `device_name`  VARCHAR(120) NULL,
            `platform`     VARCHAR(12) NULL,
            `app_version`  VARCHAR(20) NULL,
            `push_token`   VARCHAR(255) NULL,
            `target`       VARCHAR(300) NULL,
            `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `last_used_at` DATETIME NULL,
            `expires_at`   DATETIME NOT NULL,
            `revoked_at`   DATETIME NULL,
            UNIQUE KEY `uq_mt_hash` (`token_hash`),
            KEY `idx_mt_user` (`user_id`, `kind`, `revoked_at`)
        ) {$t}",
        "CREATE TABLE IF NOT EXISTS `mobile_idempotency` (
            `id`          BIGINT AUTO_INCREMENT PRIMARY KEY,
            `user_id`     INT NOT NULL,
            `idem_key`    VARCHAR(80) NOT NULL,
            `endpoint`    VARCHAR(60) NOT NULL,
            `status`      VARCHAR(10) NOT NULL DEFAULT 'running',
            `http_code`   SMALLINT NULL,
            `response`    MEDIUMTEXT NULL,
            `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `finished_at` DATETIME NULL,
            UNIQUE KEY `uq_mi_key` (`user_id`, `idem_key`),
            KEY `idx_mi_created` (`created_at`)
        ) {$t}",
    ];
}

/** إنشاء الجداول مرة واحدة (آمن للتكرار) */
function mobile_ensure_schema(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        if (get_setting('mobile_schema_v', '') === MOBILE_SCHEMA_V) return;
    } catch (\Throwable $e) { /* جدول الإعدادات مش موجود؟ نكمّل */ }
    foreach (mobile_schema_sql() as $sql) {
        try { db()->exec($sql); } catch (\Throwable $e) { error_log('[mobile] schema: ' . $e->getMessage()); }
    }
    try { set_setting('mobile_schema_v', MOBILE_SCHEMA_V); } catch (\Throwable $e) {}
}

/* ═══════════════ التوكنات ═══════════════ */

function mobile_new_token(string $prefix = 'spm'): string
{
    return $prefix . '_' . rtrim(strtr(base64_encode(random_bytes(36)), '+/', '-_'), '=');
}

function mobile_token_hash(string $token): string
{
    return hash('sha256', $token);
}

/** وصف الجهاز من الهيدرز اللي التطبيق بيبعتها */
function mobile_device_meta(): array
{
    $clean = fn($v, $n) => mb_substr(trim(preg_replace('/[\x00-\x1F]/u', '', (string) $v)), 0, $n);
    $plat = strtolower($clean($_SERVER['HTTP_X_APP_PLATFORM'] ?? '', 12));
    return [
        'device_name' => $clean($_SERVER['HTTP_X_DEVICE_NAME'] ?? '', 120) ?: 'Spread AI App',
        'platform'    => in_array($plat, ['ios', 'android', 'web'], true) ? $plat : null,
        'app_version' => $clean($_SERVER['HTTP_X_APP_VERSION'] ?? '', 20) ?: null,
    ];
}

/**
 * توكن جديد. access ← بيتسجّل كجلسة نشطة (user_sessions) بنفس رقم الجلسة المشتق من التوكن
 * @return string التوكن الخام (بيرجع للتطبيق مرة واحدة بس)
 */
function mobile_issue_token(int $userId, string $kind = 'access', ?string $target = null): string
{
    mobile_ensure_schema();
    $prefix = $kind === 'challenge' ? 'spc' : 'spm';
    $tok = mobile_new_token($prefix);
    $ttl = ['access' => MOBILE_TOKEN_DAYS * 86400, 'challenge' => MOBILE_CHALLENGE_SEC, 'handoff' => MOBILE_HANDOFF_SEC][$kind] ?? 600;
    $m = mobile_device_meta();
    db_run('INSERT INTO mobile_tokens (user_id, token_hash, kind, device_name, platform, app_version, target, expires_at)
            VALUES (?,?,?,?,?,?,?,?)',
        [$userId, mobile_token_hash($tok), $kind, $m['device_name'], $m['platform'], $m['app_version'], $target,
         date('Y-m-d H:i:s', time() + $ttl)]);
    return $tok;
}

function mobile_token_row(string $token, ?string $kind = null): ?array
{
    mobile_ensure_schema();
    $row = db_one('SELECT * FROM mobile_tokens WHERE token_hash = ? AND revoked_at IS NULL AND expires_at > NOW() LIMIT 1',
        [mobile_token_hash($token)]);
    if (!$row || ($kind !== null && $row['kind'] !== $kind)) return null;
    return $row;
}

function mobile_revoke_token(string $token): void
{
    db_run('UPDATE mobile_tokens SET revoked_at = NOW() WHERE token_hash = ? AND revoked_at IS NULL', [mobile_token_hash($token)]);
}

/**
 * نقل الطلب الحالي لجلسة التوكن الجديد (بعد الدخول) — علشان الجهاز يتسجّل في user_sessions
 * والجلسة القديمة (المؤقتة أو جلسة كود التحقق) تتمسح
 */
function mobile_switch_session(string $token, int $userId): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION = [];
        session_destroy();
    }
    session_id(mobile_sid($token));
    session_start();
    $GLOBALS['__mobile_ephemeral'] = false;
    $_SESSION = ['user_id' => $userId];
    csrf_token();
    if (function_exists('account_session_register')) {
        account_session_register($userId);
    }
}

/**
 * أسباب منع الدخول (نفس شروط require_login في الموقع) — null = تمام
 * @return array{code:string, error:string, http:int}|null
 */
function mobile_account_block(array $u): ?array
{
    if (($u['status'] ?? 'active') !== 'active') {
        return ['code' => 'suspended', 'error' => 'الحساب موقوف. تواصل مع الإدارة.', 'http' => 403];
    }
    if (empty($u['email_verified_at'])) {
        return ['code' => 'email_unverified', 'error' => 'الحساب لسه مش مفعل — افتح رابط التفعيل اللي وصلك على الإيميل', 'http' => 403];
    }
    $ap = (string) ($u['approval_status'] ?? 'approved');
    if ($ap === 'pending') {
        return ['code' => 'pending_approval', 'error' => 'حسابك تحت المراجعة — الإدارة هتوافق عليه قريبًا وهيوصلك إيميل تأكيد.', 'http' => 403];
    }
    if ($ap === 'rejected') {
        return ['code' => 'rejected', 'error' => 'عذرًا، لم تتم الموافقة على حسابك. تواصل مع الإدارة.', 'http' => 403];
    }
    return null;
}

/**
 * التحقق من توكن الطلب الحالي — بيحط user_id في الجلسة لو التوكن سليم
 * @return array{user:?array, token:?string, row:?array, block:?array}
 */
function mobile_authenticate(): array
{
    static $res = null;
    if ($res !== null) return $res;
    $res = ['user' => null, 'token' => null, 'row' => null, 'block' => null];
    $tok = mobile_bearer();
    if ($tok === null) {
        unset($_SESSION['user_id']);
        return $res;
    }
    $res['token'] = $tok;
    $row = mobile_token_row($tok);
    if (!$row) {
        unset($_SESSION['user_id']);
        return $res;
    }
    $res['row'] = $row;
    if ($row['kind'] !== 'access') {
        unset($_SESSION['user_id']);   // كود التحقق بخطوتين مش دخول
        return $res;
    }
    $u = db_one('SELECT * FROM users WHERE id = ? LIMIT 1', [(int) $row['user_id']]);
    if (!$u) {
        mobile_revoke_token($tok);
        unset($_SESSION['user_id']);
        $res['row'] = null;
        return $res;
    }
    $_SESSION['user_id'] = (int) $u['id'];
    csrf_token();
    // «خروج من الأجهزة الأخرى» من الموقع ← التوكن ده كمان يتلغي
    if (function_exists('account_session_check') && !account_session_check((int) $u['id'])) {
        mobile_revoke_token($tok);
        unset($_SESSION['user_id']);
        $res['row'] = null;
        return $res;
    }
    $res['user'] = $u;
    $res['block'] = mobile_account_block($u);
    // آخر استخدام + تجديد الصلاحية (مرة كل 10 دقايق بالكتير)
    if (empty($row['last_used_at']) || strtotime((string) $row['last_used_at']) < time() - 600) {
        db_run('UPDATE mobile_tokens SET last_used_at = NOW(), expires_at = ? WHERE id = ?',
            [date('Y-m-d H:i:s', time() + MOBILE_TOKEN_DAYS * 86400), $row['id']]);
    }
    return $res;
}

/**
 * لازم يكون فيه مستخدم داخل وحسابه سليم — وإلا رد JSON (مش تحويل لصفحة دخول)
 * @return array المستخدم
 */
function mobile_require_user(bool $allowBlocked = false): array
{
    $a = mobile_authenticate();
    if (!$a['user']) {
        api_fail('انتهت الجلسة — سجّل دخولك تاني', 'auth', 401);
    }
    if (!$allowBlocked && $a['block']) {
        api_fail($a['block']['error'], $a['block']['code'], $a['block']['http']);
    }
    return $a['user'];
}

/** الكود القديم بيشيك CSRF — في طلبات التوكن مفيش كوكيز أصلًا، فبنملا القيمة من الجلسة */
function mobile_satisfy_csrf(): void
{
    $t = csrf_token();
    $_SERVER['HTTP_X_CSRF_TOKEN'] = $t;
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
        $_POST['csrf'] = $t;
    }
}

/* ═══════════════ منع الخصم المكرر (Idempotency-Key) ═══════════════ */

function mobile_idem_key(): ?string
{
    $k = trim((string) ($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? ''));
    return preg_match('/^[A-Za-z0-9_\-]{8,80}$/', $k) ? $k : null;
}

/**
 * قبل تنفيذ عملية مكلفة: نفس المفتاح اتنفّذ قبل كده؟ ← نرجّع نفس الرد من غير تنفيذ
 * لسه شغال؟ ← 409. جديد ← نسجّله «شغال» ونكمّل.
 */
function mobile_idem_begin(int $userId, string $endpoint): void
{
    $key = mobile_idem_key();
    if ($key === null) return;
    mobile_ensure_schema();
    $row = db_one('SELECT * FROM mobile_idempotency WHERE user_id = ? AND idem_key = ?', [$userId, $key]);
    if ($row) {
        if ($row['endpoint'] !== $endpoint) {
            api_fail('مفتاح العملية مستخدم لعملية تانية', 'idempotency_mismatch', 422);
        }
        if ($row['status'] === 'done' && $row['response'] !== null) {
            while (ob_get_level() > 0) ob_end_clean();
            http_response_code((int) ($row['http_code'] ?: 200));
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            header('X-Idempotent-Replay: 1');
            echo $row['response'];
            exit;
        }
        if ($row['status'] === 'running' && strtotime((string) $row['created_at']) > time() - 420) {
            api_fail('العملية دي لسه شغالة — استنى ثواني', 'in_progress', 409);
        }
        // فشلت قبل كده أو علّقت ← نسمح بإعادة المحاولة بنفس المفتاح
        db_run("UPDATE mobile_idempotency SET status = 'running', created_at = NOW(), finished_at = NULL, response = NULL WHERE id = ?", [$row['id']]);
        $id = (int) $row['id'];
    } else {
        try {
            $id = db_insert('INSERT INTO mobile_idempotency (user_id, idem_key, endpoint) VALUES (?,?,?)', [$userId, $key, $endpoint]);
        } catch (\Throwable $e) {
            api_fail('العملية دي لسه شغالة — استنى ثواني', 'in_progress', 409);
        }
    }
    $GLOBALS['__mobile_idem'] = ['id' => $id, 'saved' => false];
    // لو الطلب وقع (خطأ PHP) من غير رد ← نعلّم «فشل» علشان يقدر يعيد
    register_shutdown_function(function () {
        $m = $GLOBALS['__mobile_idem'] ?? null;
        if ($m && empty($m['saved'])) {
            try { db_run("UPDATE mobile_idempotency SET status = 'failed', finished_at = NOW() WHERE id = ?", [$m['id']]); } catch (\Throwable $e) {}
        }
    });
}

/**
 * بيتنادى من api_send() و json_response() (هوك اختياري) — بيحفظ الرد لمفتاح العملية
 * الردود الناجحة بس بتتحفظ للإعادة؛ الأخطاء بتسمح بإعادة المحاولة
 */
function mobile_on_response(array $payload, int $code): void
{
    $m = $GLOBALS['__mobile_idem'] ?? null;
    if (!$m || !empty($m['saved'])) return;
    $GLOBALS['__mobile_idem']['saved'] = true;
    $ok = !empty($payload['ok']) && $code < 400;
    try {
        db_run('UPDATE mobile_idempotency SET status = ?, http_code = ?, response = ?, finished_at = NOW() WHERE id = ?',
            [$ok ? 'done' : 'failed', $code, $ok ? json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null, $m['id']]);
        if (random_int(1, 50) === 1) {
            db_run('DELETE FROM mobile_idempotency WHERE created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)');
        }
    } catch (\Throwable $e) {}
}

/* ═══════════════ مساعدات ═══════════════ */

/** رابط كامل لملف في storage (صورة · لوجو) */
function mobile_media_url(?string $path): ?string
{
    if ($path === null || $path === '') return null;
    if (preg_match('~^https?://~i', $path)) return $path;
    return APP_URL . '/storage/' . ltrim(preg_replace('~^storage/~', '', $path), '/');
}

function mobile_user_api(array $u): array
{
    return [
        'id'            => (int) $u['id'],
        'name'          => (string) $u['name'],
        'email'         => (string) $u['email'],
        'phone'         => (string) ($u['phone'] ?? ''),
        'avatar'        => !empty($u['avatar_url']) ? mobile_media_url((string) $u['avatar_url']) : null,
        'auth_provider' => (string) ($u['auth_provider'] ?? 'password'),
        'two_fa'        => !empty($u['two_fa_enabled']),
        'created_at'    => (string) ($u['created_at'] ?? ''),
        'deletion_at'   => function_exists('account_deletion_date') ? account_deletion_date($u) : null,
        'needs_phone'   => function_exists('account_needs_phone') ? account_needs_phone($u) : false,
    ];
}

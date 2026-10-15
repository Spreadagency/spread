<?php
/**
 * Spread AI — نظام الرسايل والإشعارات (واتساب + داخل الموقع)
 *
 * المعمارية:
 *   حدث في السيستم / أدمن بيبعت
 *     → Notifier::notify()/direct()/broadcast()
 *     → داخل الموقع: صف في user_notifications (نفس الجرس وصفحة الإشعارات والـ push بتاع التطبيق)
 *     → واتساب: صف في messages بحالة pending
 *     → فوري: dispatch() يعلّمها processing ويبعت IDs لـ n8n (webhook، مهلة 3 ثواني)
 *     → n8n → Evolution API → callback.php (sent / failed)
 *   لو n8n واقف أو الـ webhook وقع: الرسالة بتفضل pending، وجدولة n8n (كل دقيقتين) بتسحبها من pending.php.
 *   الموقع عمره ما بيكلّم Evolution مباشرة.
 *
 * الجداول (إضافة بس — بتتعمل تلقائيًا أول مرة، ونسختها في sql/2026_whatsapp_messaging.sql):
 *   messages · message_templates · message_broadcasts · user_notification_prefs · wa_otps
 *   + أعمدة في users: whatsapp_phone · whatsapp_verified · whatsapp_opt_in · last_active_at
 */

if (!function_exists('db')) require_once __DIR__ . '/db.php';
if (!function_exists('get_setting')) require_once __DIR__ . '/credits.php';

const WA_SCHEMA_V = '1';
const WA_DEDUPE_MIN = 30;        // نفس الحدث لنفس العميل خلال 30 دقيقة مايتسجلش تاني
const WA_MAX_ATTEMPTS = 3;
const WA_OTP_TTL_MIN = 10;
const WA_OTP_PER_HOUR = 3;
const WA_BATCH = 500;            // إدخال رسايل الحملة على دفعات

/* ═══════════════ البنية ═══════════════ */

function wa_schema_sql(): array
{
    $t = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    return [
        "CREATE TABLE IF NOT EXISTS `user_notification_prefs` (
            `user_id`   INT NOT NULL,
            `event_key` VARCHAR(50) NOT NULL,
            `whatsapp`  TINYINT(1) NOT NULL DEFAULT 1,
            `inapp`     TINYINT(1) NOT NULL DEFAULT 1,
            PRIMARY KEY (`user_id`, `event_key`)
        ) $t",
        "CREATE TABLE IF NOT EXISTS `message_templates` (
            `id`              INT AUTO_INCREMENT PRIMARY KEY,
            `event_key`       VARCHAR(50) NOT NULL,
            `title`           VARCHAR(150) NOT NULL,
            `body`            TEXT NOT NULL,
            `default_channel` ENUM('inapp','whatsapp','both') NOT NULL DEFAULT 'both',
            `is_critical`     TINYINT(1) NOT NULL DEFAULT 0,
            `is_active`       TINYINT(1) NOT NULL DEFAULT 1,
            `audience_json`   TEXT NULL,
            `updated_at`      DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `uq_mt_event` (`event_key`)
        ) $t",
        "CREATE TABLE IF NOT EXISTS `message_broadcasts` (
            `id`            INT AUTO_INCREMENT PRIMARY KEY,
            `admin_id`      INT NULL,
            `source`        VARCHAR(30) NOT NULL DEFAULT 'manual',
            `title`         VARCHAR(150) NOT NULL,
            `body`          TEXT NOT NULL,
            `media_url`     VARCHAR(500) NULL,
            `link`          VARCHAR(500) NULL,
            `channel`       ENUM('inapp','whatsapp','both') NOT NULL DEFAULT 'both',
            `audience_json` TEXT NOT NULL,
            `scheduled_at`  DATETIME NOT NULL,
            `status`        ENUM('draft','queued','sending','done','cancelled') NOT NULL DEFAULT 'queued',
            `total`         INT NOT NULL DEFAULT 0,
            `created_at`    DATETIME DEFAULT CURRENT_TIMESTAMP,
            KEY `idx_mb_status` (`status`, `scheduled_at`)
        ) $t",
        "CREATE TABLE IF NOT EXISTS `messages` (
            `id`             BIGINT AUTO_INCREMENT PRIMARY KEY,
            `user_id`        INT NOT NULL,
            `type`           ENUM('notification','direct','broadcast','otp') NOT NULL,
            `event_key`      VARCHAR(50) NULL,
            `broadcast_id`   INT NULL,
            `admin_id`       INT NULL,
            `notif_id`       INT NULL,
            `title`          VARCHAR(150) NOT NULL,
            `body`           TEXT NOT NULL,
            `media_url`      VARCHAR(500) NULL,
            `media_type`     ENUM('image','video','document') NULL,
            `link`           VARCHAR(500) NULL,
            `channel`        ENUM('inapp','whatsapp','both') NOT NULL,
            `read_at`        DATETIME NULL,
            `wa_phone`       VARCHAR(20) NULL,
            `wa_status`      ENUM('none','pending','processing','sent','failed','skipped') NOT NULL DEFAULT 'none',
            `wa_attempts`    TINYINT NOT NULL DEFAULT 0,
            `wa_error`       VARCHAR(500) NULL,
            `wa_provider_id` VARCHAR(100) NULL,
            `locked_at`      DATETIME NULL,
            `scheduled_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `sent_at`        DATETIME NULL,
            `created_at`     DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at`     DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY `idx_user_inbox` (`user_id`, `channel`, `created_at`),
            KEY `idx_wa_queue` (`wa_status`, `scheduled_at`),
            KEY `idx_broadcast` (`broadcast_id`),
            KEY `idx_user_event` (`user_id`, `event_key`, `created_at`),
            KEY `idx_notif` (`notif_id`)
        ) $t",
        "CREATE TABLE IF NOT EXISTS `wa_otps` (
            `id`         INT AUTO_INCREMENT PRIMARY KEY,
            `user_id`    INT NOT NULL,
            `phone`      VARCHAR(20) NOT NULL,
            `code_hash`  CHAR(64) NOT NULL,
            `attempts`   TINYINT NOT NULL DEFAULT 0,
            `expires_at` DATETIME NOT NULL,
            `used_at`    DATETIME NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY `idx_wo_user` (`user_id`, `created_at`)
        ) $t",
    ];
}

/** أعمدة users الجديدة — بنتأكد عمود عمود (MySQL مالوش ADD COLUMN IF NOT EXISTS) */
function wa_user_columns(): array
{
    return [
        'whatsapp_phone'    => 'VARCHAR(20) NULL',
        'whatsapp_verified' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'whatsapp_opt_in'   => 'TINYINT(1) NOT NULL DEFAULT 1',
        'last_active_at'    => 'DATETIME NULL',
    ];
}

function wa_ensure_schema(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        if (get_setting('wa_schema_v', '') === WA_SCHEMA_V) return;
    } catch (\Throwable $e) { /* نكمّل */ }
    foreach (wa_schema_sql() as $sql) {
        try { db()->exec($sql); } catch (\Throwable $e) { error_log('[wa] schema: ' . $e->getMessage()); }
    }
    try {
        $have = array_column(db_all('SHOW COLUMNS FROM users'), 'Field');
        foreach (wa_user_columns() as $col => $def) {
            if (!in_array($col, $have, true)) db()->exec("ALTER TABLE `users` ADD COLUMN `$col` $def");
        }
    } catch (\Throwable $e) { error_log('[wa] users columns: ' . $e->getMessage()); }
    wa_seed_templates();
    foreach (wa_default_settings() as $k => $v) {
        try { if (get_setting($k, null) === null) set_setting($k, $v); } catch (\Throwable $e) {}
    }
    try { set_setting('wa_schema_v', WA_SCHEMA_V); } catch (\Throwable $e) {}
}

function wa_default_settings(): array
{
    return [
        'wa_enabled'         => '0',   // بيتفعّل من لوحة الأدمن بعد ربط n8n
        'wa_n8n_webhook_url' => '',
        'wa_shared_secret'   => '',
        'wa_daily_limit'     => '3',
        'wa_quiet_from'      => '23:00',
        'wa_quiet_to'        => '09:00',
        'wa_mirror_notifications' => '1',   // كل إشعار جديد في المنصة يروح واتساب كمان
    ];
}

/* ═══════════════ الأحداث والقوالب ═══════════════ */

/**
 * كتالوج الأحداث: [label, vars, critical, default_channel, title, body, active]
 * vars المتاحة دايمًا: name · first_name · credits · link
 */
function wa_event_catalog(): array
{
    return [
        'notification'          => ['أي إشعار جديد في المنصة (الدفع · الباقات · …)', ['title', 'body'], 0, 'whatsapp',
            '{{title}}', "*{{title}}*\n{{body}}\n{{link}}", 1],
        'account_activated'     => ['تفعيل الحساب', [], 1, 'both',
            'تم تفعيل حسابك', 'أهلًا {{name}} 👋 حسابك على Spread AI اتفعّل وعندك {{credits}}. ابدأ من هنا: {{link}}', 1],
        'ideas_ready'           => ['خطة الأفكار جاهزة', ['count', 'campaign'], 0, 'both',
            'خطة الأفكار جاهزة', '{{first_name}}، جهزنالك {{count}} فكرة جديدة. اختار اللي يعجبك 👈 {{link}}', 1],
        'designs_ready'         => ['التصميمات جاهزة', ['count'], 0, 'both',
            'التصميمات جاهزة', 'التصميمات اتولدت ومستنية اعتمادك ✅ {{link}}', 1],
        'post_published'        => ['البوست اتنشر', ['page_name'], 0, 'both',
            'البوست اتنشر', 'البوست اتنشر على صفحة {{page_name}} ✅ {{link}}', 1],
        'post_failed'           => ['فشل النشر', ['page_name', 'date'], 1, 'both',
            'فشل النشر', '⚠️ البوست المجدول {{date}} منزلش على {{page_name}}. راجعه من هنا: {{link}}', 1],
        'credits_low'           => ['الرصيد قرب يخلص', [], 0, 'both',
            'الرصيد قرب يخلص', 'فاضل لك {{credits}}. اشحن من هنا: {{link}}', 1],
        'subscription_expiring' => ['الاشتراك قرب يخلص', ['date', 'plan'], 1, 'both',
            'الاشتراك قرب يخلص', 'اشتراكك في {{plan}} بيخلص يوم {{date}}. جدّد من هنا: {{link}}', 1],
        'referral_reward'       => ['مكافأة الإحالة', ['reward'], 0, 'both',
            'كسبت مكافأة 🎁', 'مبروك! حد سجّل بكودك وكسبت {{reward}} 🎉 {{link}}', 1],
        'inactive_reminder'     => ['تذكير لو مدخلش من فترة', ['days'], 0, 'both',
            'وحشتنا', '{{first_name}}، بقالك فترة مدخلتش. عندك {{credits}} مستنيينك 👈 {{link}}', 1],
        'offer_new'             => ['عرض جديد (كود خصم / حملة)', ['offer_title', 'offer_desc', 'code'], 0, 'both',
            'عرض جديد 🎁 {{offer_title}}', "🎁 *عرض جديد:* {{offer_title}}\n{{offer_desc}}\nاستخدم الكود *{{code}}* 👈 {{link}}", 1],
        'announcement_new'      => ['إعلان جديد في المنصة', ['ann_title', 'ann_body'], 0, 'both',
            '{{ann_title}}', "📣 *{{ann_title}}*\n{{ann_body}}\n{{link}}", 1],
        'otp'                   => ['كود التحقق من رقم الواتساب', ['code'], 1, 'whatsapp',
            'كود التحقق', 'كود التحقق بتاعك في Spread AI: *{{code}}* (صالح 10 دقايق)', 1],
    ];
}

function wa_seed_templates(): void
{
    foreach (wa_event_catalog() as $key => [$label, $vars, $crit, $ch, $title, $body, $active]) {
        try {
            db_run('INSERT IGNORE INTO message_templates (event_key, title, body, default_channel, is_critical, is_active) VALUES (?,?,?,?,?,?)',
                [$key, $title, $body, $ch, $crit, $active]);
        } catch (\Throwable $e) { error_log('[wa] seed: ' . $e->getMessage()); }
    }
}

function wa_template(string $eventKey): ?array
{
    try {
        $t = db_one('SELECT * FROM message_templates WHERE event_key = ?', [$eventKey]);
        return $t ?: null;
    } catch (\Throwable $e) {
        return null;
    }
}

/** استبدال {{var}} — المتغيرات الناقصة بتتشال، والمسافات الزايدة بتتظبط */
function wa_render(string $tpl, array $vars, bool $keepUnknown = false): string
{
    $out = preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/i', function ($m) use ($vars, $keepUnknown) {
        $k = strtolower($m[1]);
        if (!array_key_exists($k, $vars)) return $keepUnknown ? $m[0] : '';
        return is_scalar($vars[$k]) ? (string) $vars[$k] : '';
    }, $tpl);
    $out = preg_replace("/[ \t]+\n/u", "\n", (string) $out);
    $out = preg_replace("/\n{3,}/u", "\n\n", $out);
    return trim($out);
}

/* ═══════════════ مساعدات ═══════════════ */

function wa_setting(string $key): string
{
    $def = wa_default_settings()[$key] ?? '';
    try { return (string) get_setting($key, $def); } catch (\Throwable $e) { return $def; }
}

function wa_enabled(): bool
{
    return wa_setting('wa_enabled') === '1';
}

/**
 * تطبيع الرقم: أرقام بس · 01xxxxxxxxx → 201xxxxxxxxx · +20/0020 → 20
 * مصري: يبدأ بـ 20 وطوله 12 · دولي: 10–15 رقم · غير كده ''
 */
function wa_normalize_phone(string $p): string
{
    $p = preg_replace('/\D+/', '', $p);
    if ($p === '') return '';
    if (strpos($p, '00') === 0) $p = substr($p, 2);
    if (preg_match('/^01[0125]\d{8}$/', $p)) $p = '2' . $p;            // 01xxxxxxxxx
    elseif (preg_match('/^1[0125]\d{8}$/', $p)) $p = '20' . $p;          // 1xxxxxxxxx
    if (strpos($p, '20') === 0) return strlen($p) === 12 ? $p : '';
    return (strlen($p) >= 10 && strlen($p) <= 15) ? $p : '';
}

/** رابط كامل جوه المنصة (للواتساب) */
function wa_abs_link(string $path): string
{
    $path = trim($path);
    if ($path === '') return rtrim(APP_URL, '/') . '/dashboard.php';
    if (preg_match('#^https?://#i', $path)) return $path;
    return rtrim(APP_URL, '/') . '/' . ltrim($path, '/');
}

/** الروابط اللي الأدمن بيكتبها: https بس */
function wa_clean_link(string $link): ?string
{
    $link = trim($link);
    if ($link === '') return null;
    if (!preg_match('#^https://[^\s<>"\']+$#i', $link) || !filter_var($link, FILTER_VALIDATE_URL)) return null;
    return mb_substr($link, 0, 500);
}

function wa_media_type(?string $url): ?string
{
    if (!$url) return null;
    $ext = strtolower(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
    if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) return 'image';
    if (in_array($ext, ['mp4', 'mov', 'webm', '3gp'], true)) return 'video';
    return 'document';
}

/** نص الرصيد حسب قاعدة العرض (النسبة % للعميل إلا لو الأدمن مظهر الأرقام) */
function wa_credits_text(int $uid): string
{
    try {
        if (!function_exists('ui_credits_usage')) require_once __DIR__ . '/ui-v2.php';
        $u = ui_credits_usage($uid);
        if ($u['show'] ?? true) return (int) $u['balance'] . ' كريدت';
        return max(0, 100 - (int) $u['pct']) . '% من باقتك';
    } catch (\Throwable $e) {
        return function_exists('credits_balance') ? credits_balance($uid) . ' كريدت' : '';
    }
}

/** مكافأة/كمية كريدت: رقم لو العرض بالأرقام، وإلا «رصيد إضافي» */
function wa_credit_amount_text(int $n): string
{
    $show = true;
    try { if (function_exists('credits_show_numbers')) $show = credits_show_numbers(); } catch (\Throwable $e) {}
    return $show ? $n . ' كريدت' : 'رصيد إضافي';
}

function wa_user(int $uid): ?array
{
    try {
        $u = db_one('SELECT * FROM users WHERE id = ?', [$uid]);
        return $u ?: null;
    } catch (\Throwable $e) {
        return null;
    }
}

/** ليه الواتساب مش هيوصل للعميل ده؟ ('' = هيوصل) */
function wa_block_reason(array $u, bool $requireVerified = true): string
{
    if (!wa_enabled()) return 'wa_disabled';
    if (array_key_exists('whatsapp_opt_in', $u) && (int) $u['whatsapp_opt_in'] !== 1) return 'opted_out';
    if (empty($u['whatsapp_phone'])) return 'no_phone';
    if ($requireVerified && empty($u['whatsapp_verified'])) return 'not_verified';
    return '';
}

/** تفضيلات العميل للحدث (الافتراضي: الاتنين مفعّلين) */
function wa_prefs(int $uid, string $eventKey): array
{
    try {
        $p = db_one('SELECT whatsapp, inapp FROM user_notification_prefs WHERE user_id = ? AND event_key = ?', [$uid, $eventKey]);
        if ($p) return ['whatsapp' => (int) $p['whatsapp'] === 1, 'inapp' => (int) $p['inapp'] === 1];
    } catch (\Throwable $e) {}
    return ['whatsapp' => true, 'inapp' => true];
}

/* ── ساعات الهدوء (توقيت القاهرة) ── */

function wa_hm(string $v, string $def): int
{
    if (!preg_match('/^(\d{1,2}):(\d{2})$/', trim($v), $m)) [$m[1], $m[2]] = explode(':', $def);
    return ((int) $m[1]) * 60 + (int) $m[2];
}

function wa_in_quiet(?int $ts = null): bool
{
    $ts = $ts ?? time();
    $from = wa_hm(wa_setting('wa_quiet_from'), '23:00');
    $to = wa_hm(wa_setting('wa_quiet_to'), '09:00');
    if ($from === $to) return false;
    $now = ((int) date('G', $ts)) * 60 + (int) date('i', $ts);
    return $from < $to ? ($now >= $from && $now < $to) : ($now >= $from || $now < $to);
}

/** أول وقت بعد ساعات الهدوء */
function wa_quiet_end(?int $ts = null): string
{
    $ts = $ts ?? time();
    $to = wa_hm(wa_setting('wa_quiet_to'), '09:00');
    $cand = strtotime(date('Y-m-d', $ts) . sprintf(' %02d:%02d:00', intdiv($to, 60), $to % 60));
    if ($cand <= $ts) $cand = strtotime('+1 day', $cand);
    return date('Y-m-d H:i:s', $cand);
}

/** رسايل الواتساب (الإشعارات) اللي اتسجلت للعميل النهارده */
function wa_today_count(int $uid): int
{
    return (int) (db_one("SELECT COUNT(*) n FROM messages WHERE user_id = ? AND type = 'notification'
                          AND wa_status IN ('pending','processing','sent') AND created_at >= CURDATE()", [$uid])['n'] ?? 0);
}

/* ── داخل الموقع: نفس جدول الجرس الحالي (user_notifications) ── */

function wa_inapp_insert(int $uid, string $kind, string $title, string $body, string $url = '', string $tone = 'brand', bool $push = true): ?int
{
    // رابط داخلي بس في الجرس (notif.php بيرفض أي حاجة بره المنصة)
    if (!preg_match('#^[a-z0-9_\-/]+\.php([?\#][^\s]*)?$#i', $url)) $url = 'notifications.php';
    // *عريض* بتاع الواتساب مالوش معنى جوه المنصة
    $title = preg_replace('/\*([^*\n]+)\*/u', '$1', $title);
    $body = preg_replace('/\*([^*\n]+)\*/u', '$1', $body);
    try {
        $id = db_insert('INSERT INTO user_notifications (user_id, kind, title, body, url, tone) VALUES (?,?,?,?,?,?)',
            [$uid, mb_substr($kind, 0, 40), mb_substr($title, 0, 190), $body !== '' ? mb_substr($body, 0, 2000) : null, mb_substr($url, 0, 255), $tone]);
    } catch (\Throwable $e) {
        error_log('[wa] inapp: ' . $e->getMessage());
        return null;
    }
    if ($push) {
        if (!function_exists('mobile_push_user') && is_file(__DIR__ . '/mobile-push.php')) require_once __DIR__ . '/mobile-push.php';
        if (function_exists('mobile_push_user')) {
            try { mobile_push_user($uid, $title, mb_substr($body, 0, 180), ['screen' => 'notifications', 'kind' => $kind]); } catch (\Throwable $e) {}
        }
    }
    return $id ?: null;
}

/* ═══════════════ الجمهور (الحملات + شروط القوالب) ═══════════════ */

/**
 * $aud: {"type":"all"} · {"type":"user","user_id":5} · {"type":"users","user_ids":[1,2]}
 *       {"type":"filter","plan_ids":[2],"subscribed":"yes|no","credits_lt":20,"registered_from":"2026-01-01",
 *        "registered_to":"2026-02-01","inactive_days":7,"wa_verified":true}
 * بيرجّع شرط WHERE على users u (العملاء النشطين بس)
 */
function wa_audience_where(array $aud, array &$params): string
{
    $w = ["u.status = 'active'", "u.approval_status = 'approved'"];
    try {
        $cols = array_column(db_all('SHOW COLUMNS FROM users'), 'Field');
        if (in_array('deletion_requested_at', $cols, true)) $w[] = 'u.deletion_requested_at IS NULL';
    } catch (\Throwable $e) {}
    $type = (string) ($aud['type'] ?? 'all');
    if ($type === 'user') {
        $w[] = 'u.id = ?';
        $params[] = (int) ($aud['user_id'] ?? 0);
    } elseif ($type === 'users') {
        $ids = array_values(array_filter(array_map('intval', (array) ($aud['user_ids'] ?? []))));
        if (!$ids) $ids = [0];
        $w[] = 'u.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
        array_push($params, ...$ids);
    } elseif ($type === 'filter') {
        $plans = array_values(array_filter(array_map('intval', (array) ($aud['plan_ids'] ?? []))));
        if ($plans) {
            $w[] = 'EXISTS (SELECT 1 FROM user_plans up WHERE up.user_id = u.id AND up.status = \'active\' AND up.ends_at > NOW()
                    AND up.package_id IN (' . implode(',', array_fill(0, count($plans), '?')) . '))';
            array_push($params, ...$plans);
        }
        $sub = (string) ($aud['subscribed'] ?? '');
        if ($sub === 'yes' || $sub === 'no') {
            $w[] = ($sub === 'no' ? 'NOT ' : '') . 'EXISTS (SELECT 1 FROM user_plans up2 WHERE up2.user_id = u.id AND up2.status = \'active\' AND up2.ends_at > NOW() AND up2.source <> \'trial\')';
        }
        if (isset($aud['credits_lt']) && $aud['credits_lt'] !== '' && $aud['credits_lt'] !== null) {
            $w[] = 'COALESCE((SELECT cw.balance FROM credit_wallets cw WHERE cw.user_id = u.id), 0) < ?';
            $params[] = (int) $aud['credits_lt'];
        }
        if (!empty($aud['registered_from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $aud['registered_from'])) {
            $w[] = 'u.created_at >= ?';
            $params[] = $aud['registered_from'] . ' 00:00:00';
        }
        if (!empty($aud['registered_to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $aud['registered_to'])) {
            $w[] = 'u.created_at <= ?';
            $params[] = $aud['registered_to'] . ' 23:59:59';
        }
        if (!empty($aud['inactive_days'])) {
            $w[] = 'COALESCE(u.last_active_at, u.created_at) < NOW() - INTERVAL ? DAY';
            $params[] = max(1, (int) $aud['inactive_days']);
        }
        if (!empty($aud['wa_verified'])) {
            $w[] = "u.whatsapp_verified = 1 AND u.whatsapp_opt_in = 1 AND u.whatsapp_phone IS NOT NULL AND u.whatsapp_phone <> ''";
        }
    }
    return implode(' AND ', $w);
}

/** فلتر الجمهور من فورم الأدمن (الإرسال + شروط القوالب) — $prefix للحقول ('c_' في القوالب) */
function wa_audience_from_form(array $in, string $prefix = ''): array
{
    $f = fn($k) => (string) ($in[$prefix . $k] ?? '');
    $aud = ['type' => 'filter'];
    $plans = array_values(array_filter(array_map('intval', (array) ($in[$prefix . 'plan_ids'] ?? []))));
    if ($plans) $aud['plan_ids'] = $plans;
    if (in_array($f('subscribed'), ['yes', 'no'], true)) $aud['subscribed'] = $f('subscribed');
    if ($f('credits_lt') !== '' && is_numeric($f('credits_lt'))) $aud['credits_lt'] = max(0, (int) $f('credits_lt'));
    foreach (['registered_from', 'registered_to'] as $k) {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $f($k))) $aud[$k] = $f($k);
    }
    if ((int) $f('inactive_days') > 0) $aud['inactive_days'] = (int) $f('inactive_days');
    if (!empty($in[$prefix . 'wa_verified'])) $aud['wa_verified'] = true;
    return $aud;
}

/** [الإجمالي, منهم يوصلهم واتساب] */
function wa_audience_count(array $aud): array
{
    $p = [];
    $where = wa_audience_where($aud, $p);
    $r = db_one("SELECT COUNT(*) total,
                        SUM(u.whatsapp_verified = 1 AND u.whatsapp_opt_in = 1 AND u.whatsapp_phone IS NOT NULL AND u.whatsapp_phone <> '') wa
                 FROM users u WHERE $where", $p) ?: [];
    return [(int) ($r['total'] ?? 0), (int) ($r['wa'] ?? 0)];
}

/** شرط القالب: العميل ده مطابق؟ (مفيش شرط = الكل) */
function wa_user_matches(int $uid, ?string $audienceJson): bool
{
    $aud = $audienceJson ? json_decode($audienceJson, true) : null;
    if (!is_array($aud) || ($aud['type'] ?? 'all') === 'all' && count($aud) <= 1) return true;
    $aud['type'] = 'filter';
    $p = [];
    $where = wa_audience_where($aud, $p);
    $p[] = $uid;
    try {
        return (bool) db_one("SELECT 1 x FROM users u WHERE $where AND u.id = ?", $p);
    } catch (\Throwable $e) {
        error_log('[wa] audience: ' . $e->getMessage());
        return true;
    }
}

/** وصف الجمهور بالعربي (للسجل والحملات) */
function wa_audience_label(array $aud): string
{
    $type = (string) ($aud['type'] ?? 'all');
    if ($type === 'all') return 'كل العملاء';
    if ($type === 'user') {
        $u = db_one('SELECT name FROM users WHERE id = ?', [(int) ($aud['user_id'] ?? 0)]);
        return 'عميل: ' . ($u['name'] ?? '#' . (int) ($aud['user_id'] ?? 0));
    }
    if ($type === 'users') return count((array) ($aud['user_ids'] ?? [])) . ' عميل مختار';
    $bits = [];
    if (!empty($aud['plan_ids'])) {
        $ids = array_map('intval', (array) $aud['plan_ids']);
        $names = array_column(db_all('SELECT name FROM credit_packages WHERE id IN (' . implode(',', $ids ?: [0]) . ')'), 'name');
        $bits[] = 'باقة: ' . implode('، ', $names);
    }
    if (($aud['subscribed'] ?? '') === 'yes') $bits[] = 'مشتركين';
    if (($aud['subscribed'] ?? '') === 'no') $bits[] = 'غير مشتركين';
    if (isset($aud['credits_lt']) && $aud['credits_lt'] !== '') $bits[] = 'رصيد أقل من ' . (int) $aud['credits_lt'];
    if (!empty($aud['registered_from']) || !empty($aud['registered_to'])) $bits[] = 'مسجّل ' . ($aud['registered_from'] ?? '…') . ' → ' . ($aud['registered_to'] ?? '…');
    if (!empty($aud['inactive_days'])) $bits[] = 'مدخلش من ' . (int) $aud['inactive_days'] . ' يوم';
    if (!empty($aud['wa_verified'])) $bits[] = 'واتساب متحقق';
    return $bits ? implode(' · ', $bits) : 'كل العملاء';
}

/* ═══════════════ الإرسال ═══════════════ */

final class Notifier
{
    /**
     * إشعار تلقائي من قالب
     * $opts: url (رابط داخلي للحدث) · tone · media_url · dedupe_title (للأحداث العامة) · notif_id (لو داخل الموقع اتسجل خلاص)
     *        queue_only (ماتبعتش بالـ webhook — للكرون والإرسال الجماعي)
     * @return int|null رقم الرسالة في messages (null = اتلغى: قالب مقفول / العميل قافل الحدث / تكرار / مش مطابق للشرط)
     */
    public static function notify(int $userId, string $eventKey, array $vars = [], array $opts = []): ?int
    {
        try {
            wa_ensure_schema();
            $tpl = wa_template($eventKey);
            if (!$tpl || (int) $tpl['is_active'] !== 1) return null;
            $u = wa_user($userId);
            if (!$u || ($u['status'] ?? 'active') !== 'active') return null;
            $critical = (int) $tpl['is_critical'] === 1;

            // شرط القالب (مثلًا: العروض للمشتركين بس)
            if (!empty($tpl['audience_json']) && !wa_user_matches($userId, $tpl['audience_json'])) return null;

            // القناة = القناة الافتراضية ∩ تفضيلات العميل (الحرج مابيتقفلش)
            $ch = (string) $tpl['default_channel'];
            $prefs = $critical ? ['whatsapp' => true, 'inapp' => true] : wa_prefs($userId, $eventKey);
            $notifId = array_key_exists('notif_id', $opts) ? (int) $opts['notif_id'] : null;
            $wantInapp = $notifId === null && in_array($ch, ['inapp', 'both'], true) && $prefs['inapp'];
            $wantWa = in_array($ch, ['whatsapp', 'both'], true) && $prefs['whatsapp'];
            if ($notifId !== null && !$wantWa) return null;          // نسخة واتساب لإشعار موجود والعميل قافلها
            if (!$wantInapp && !$wantWa) return null;

            // منع التكرار (نفس الحدث لنفس العميل خلال 30 دقيقة)
            if ($eventKey !== 'otp') {
                $dp = [$userId, $eventKey];
                $extra = '';
                if (!empty($opts['dedupe_title'])) { $extra = ' AND title = ?'; $dp[] = mb_substr((string) $opts['dedupe_title'], 0, 150); }
                $dup = db_one('SELECT id FROM messages WHERE user_id = ? AND event_key = ?' . $extra . '
                               AND created_at >= NOW() - INTERVAL ' . WA_DEDUPE_MIN . ' MINUTE LIMIT 1', $dp);
                if ($dup) return null;
            }

            // المتغيرات
            $url = (string) ($opts['url'] ?? ($vars['url'] ?? ''));
            $first = trim(explode(' ', trim((string) $u['name']))[0] ?? '');
            $vars = array_change_key_case($vars, CASE_LOWER) + [
                'name' => (string) $u['name'], 'first_name' => $first !== '' ? $first : (string) $u['name'],
                'credits' => isset($vars['credits']) ? (string) $vars['credits'] : wa_credits_text($userId),
                'link' => wa_abs_link($url),
            ];
            $title = mb_substr(wa_render((string) $tpl['title'], $vars), 0, 150) ?: 'إشعار';
            $body = wa_render((string) $tpl['body'], $vars);

            // داخل الموقع
            if ($wantInapp) {
                $inBody = trim(str_replace((string) $vars['link'], '', $body));
                $notifId = wa_inapp_insert($userId, $eventKey, $title, $inBody, $url, (string) ($opts['tone'] ?? ($critical ? 'warn' : 'brand')));
            }

            // واتساب
            $waStatus = 'none';
            $waErr = null;
            $phone = null;
            $sched = date('Y-m-d H:i:s');
            if ($wantWa) {
                $reason = wa_block_reason($u);
                if ($reason !== '') {
                    $waStatus = 'skipped';
                    $waErr = $reason;
                } elseif (!$critical && wa_today_count($userId) >= max(1, (int) wa_setting('wa_daily_limit'))) {
                    $waStatus = 'skipped';
                    $waErr = 'daily_limit';
                } else {
                    $waStatus = 'pending';
                    $phone = (string) $u['whatsapp_phone'];
                    if (!$critical && wa_in_quiet()) $sched = wa_quiet_end();
                }
            }
            $channel = ($notifId && $wantWa) ? 'both' : ($wantWa ? 'whatsapp' : 'inapp');
            $media = !empty($opts['media_url']) ? (string) $opts['media_url'] : null;

            $id = db_insert('INSERT INTO messages (user_id, type, event_key, notif_id, title, body, media_url, media_type, link, channel,
                                                   wa_phone, wa_status, wa_error, scheduled_at)
                             VALUES (?, "notification", ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$userId, $eventKey, $notifId ?: null, $title, $body, $media, wa_media_type($media), $url !== '' ? wa_abs_link($url) : null,
                 $channel, $phone, $waStatus, $waErr, $sched]);
            // queue_only: إرسال جماعي (الكرون) — يفضل في الطابور وn8n يسحبه بالتدريج
            if ($waStatus === 'pending' && strtotime($sched) <= time() && empty($opts['queue_only'])) self::dispatchLater($id);
            return $id;
        } catch (\Throwable $e) {
            error_log('[wa] notify ' . $eventKey . ': ' . $e->getMessage());
            return null;
        }
    }

    /** إشعار المنصة العادي (notify_user) → نسخة على الواتساب */
    public static function mirrorNotification(int $userId, string $kind, string $title, string $body, string $url, ?int $notifId): ?int
    {
        if (wa_setting('wa_mirror_notifications') !== '1') return null;
        $event = ($kind !== '' && $kind !== 'notification' && wa_template($kind)) ? $kind : 'notification';
        return self::notify($userId, $event, ['title' => $title, 'body' => $body], [
            'url' => $url, 'notif_id' => $notifId ?? 0, 'dedupe_title' => $title,
        ]);
    }

    /** رسالة خاصة من الأدمن لعميل واحد (فورية) */
    public static function direct(int $userId, string $title, string $body, string $channel, ?string $mediaUrl, ?string $link, int $adminId): int
    {
        wa_ensure_schema();
        $u = wa_user($userId);
        if (!$u) throw new \RuntimeException('العميل مش موجود');
        $channel = in_array($channel, ['inapp', 'whatsapp', 'both'], true) ? $channel : 'both';
        $vars = self::adminVars($u);
        $title = mb_substr(wa_render($title, $vars), 0, 150);
        $body = wa_render($body, $vars);
        $link = $link ? wa_clean_link($link) : null;
        $notifId = null;
        if ($channel !== 'whatsapp') {
            $notifId = wa_inapp_insert($userId, 'admin_direct', $title, $body, '', 'brand');
        }
        [$waStatus, $waErr, $phone] = self::adminWaState($u, $channel);
        $id = db_insert('INSERT INTO messages (user_id, type, admin_id, notif_id, title, body, media_url, media_type, link, channel, wa_phone, wa_status, wa_error)
                         VALUES (?, "direct", ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$userId, $adminId, $notifId, $title, $body, $mediaUrl, wa_media_type($mediaUrl), $link, $channel, $phone, $waStatus, $waErr]);
        if ($waStatus === 'pending') self::dispatch([$id]);
        return $id;
    }

    /**
     * حملة جماعية: broadcast + صف لكل عميل مطابق (على دفعات)
     * $data: title, body, channel, audience (array), media_url?, link?, scheduled_at? ('Y-m-d H:i:s'), source?
     * الحملات مابتتبعتش بالـ webhook — n8n بيسحبها بالتدريج (حماية الرقم من الحظر)
     */
    public static function broadcast(array $data, int $adminId): int
    {
        wa_ensure_schema();
        $aud = (array) ($data['audience'] ?? ['type' => 'all']);
        $channel = in_array($data['channel'] ?? '', ['inapp', 'whatsapp', 'both'], true) ? $data['channel'] : 'both';
        $sched = !empty($data['scheduled_at']) ? (string) $data['scheduled_at'] : date('Y-m-d H:i:s');
        $now = strtotime($sched) <= time();
        $link = !empty($data['link']) ? wa_clean_link((string) $data['link']) : null;
        $media = !empty($data['media_url']) ? (string) $data['media_url'] : null;
        $bid = db_insert('INSERT INTO message_broadcasts (admin_id, source, title, body, media_url, link, channel, audience_json, scheduled_at, status)
                          VALUES (?,?,?,?,?,?,?,?,?, "queued")',
            [$adminId ?: null, mb_substr((string) ($data['source'] ?? 'manual'), 0, 30), mb_substr((string) $data['title'], 0, 150), (string) $data['body'],
             $media, $link, $channel, json_encode($aud, JSON_UNESCAPED_UNICODE), $sched]);
        // الحملات المجدولة: الصفوف بتتعمل دلوقتي، وداخل الموقع بيظهر في ميعاده (wa_release_scheduled)
        $total = self::fanout($bid, $now);
        db_run('UPDATE message_broadcasts SET total = ? WHERE id = ?', [$total, $bid]);
        if ($total === 0) db_run('UPDATE message_broadcasts SET status = "done" WHERE id = ?', [$bid]);
        return $bid;
    }

    /** إنشاء رسايل الحملة على دفعات 500 */
    private static function fanout(int $bid, bool $inappNow): int
    {
        $b = db_one('SELECT * FROM message_broadcasts WHERE id = ?', [$bid]);
        $aud = json_decode((string) $b['audience_json'], true) ?: ['type' => 'all'];
        $p = [];
        $where = wa_audience_where($aud, $p);
        $total = 0;
        $last = 0;
        do {
            $rows = db_all("SELECT u.* FROM users u WHERE $where AND u.id > ? ORDER BY u.id ASC LIMIT " . WA_BATCH, array_merge($p, [$last]));
            foreach ($rows as $u) {
                $last = (int) $u['id'];
                $vars = self::adminVars($u);
                $title = mb_substr(wa_render((string) $b['title'], $vars), 0, 150);
                $body = wa_render((string) $b['body'], $vars);
                $notifId = null;
                if ($b['channel'] !== 'whatsapp' && $inappNow) {
                    $notifId = wa_inapp_insert((int) $u['id'], 'admin_broadcast', $title, $body, '', 'brand', false);
                }
                [$waStatus, $waErr, $phone] = self::adminWaState($u, (string) $b['channel']);
                db_insert('INSERT INTO messages (user_id, type, broadcast_id, admin_id, notif_id, title, body, media_url, media_type, link, channel,
                                                 wa_phone, wa_status, wa_error, scheduled_at)
                           VALUES (?, "broadcast", ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [(int) $u['id'], $bid, $b['admin_id'], $notifId, $title, $body, $b['media_url'], wa_media_type($b['media_url']), $b['link'],
                     (string) $b['channel'], $phone, $waStatus, $waErr, $b['scheduled_at']]);
                $total++;
            }
        } while (count($rows) === WA_BATCH);
        return $total;
    }

    /**
     * حدث لكل العملاء (عرض جديد · إعلان جديد): حملة من قالب الحدث
     * الجمهور = شروط القالب ∩ $audience (لو اتبعت) · ساعات الهدوء بتأجّل الحملة لآخرها
     * @return int|null رقم الحملة
     */
    public static function eventBroadcast(string $eventKey, array $vars, string $url = '', array $audience = [], ?string $mediaUrl = null): ?int
    {
        try {
            wa_ensure_schema();
            $tpl = wa_template($eventKey);
            if (!$tpl || (int) $tpl['is_active'] !== 1) return null;
            $aud = $tpl['audience_json'] ? (json_decode((string) $tpl['audience_json'], true) ?: []) : [];
            $aud = array_merge(['type' => 'filter'], $aud, $audience);
            $link = wa_abs_link($url);
            $vars = array_change_key_case($vars, CASE_LOWER) + ['link' => $link];
            return self::broadcast([
                'title' => wa_render((string) $tpl['title'], $vars, true),
                'body' => wa_render((string) $tpl['body'], $vars, true),
                'channel' => (string) $tpl['default_channel'],
                'audience' => $aud,
                'media_url' => $mediaUrl,
                'link' => preg_match('#^https://#i', $link) ? $link : null,
                'scheduled_at' => wa_in_quiet() ? wa_quiet_end() : null,
                'source' => $eventKey,
            ], 0);
        } catch (\Throwable $e) {
            error_log('[wa] eventBroadcast ' . $eventKey . ': ' . $e->getMessage());
            return null;
        }
    }

    /** كود تحقق 6 أرقام على الواتساب (الرقم لسه مش متحقق — ده المقصود) */
    public static function sendOtp(int $userId, string $phone): bool
    {
        wa_ensure_schema();
        $phone = wa_normalize_phone($phone);
        if ($phone === '' || !wa_enabled()) return false;
        $n = (int) (db_one('SELECT COUNT(*) n FROM wa_otps WHERE user_id = ? AND created_at >= NOW() - INTERVAL 1 HOUR', [$userId])['n'] ?? 0);
        if ($n >= WA_OTP_PER_HOUR) return false;
        $code = (string) random_int(100000, 999999);
        db_run('UPDATE wa_otps SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL', [$userId]);
        db_insert('INSERT INTO wa_otps (user_id, phone, code_hash, expires_at) VALUES (?,?,?, NOW() + INTERVAL ' . WA_OTP_TTL_MIN . ' MINUTE)',
            [$userId, $phone, hash('sha256', $userId . '|' . $code)]);
        $tpl = wa_template('otp');
        $body = wa_render((string) ($tpl['body'] ?? 'كود التحقق بتاعك في Spread AI: {{code}}'), ['code' => $code]);
        $id = db_insert('INSERT INTO messages (user_id, type, event_key, title, body, channel, wa_phone, wa_status)
                         VALUES (?, "otp", "otp", ?, ?, "whatsapp", ?, "pending")',
            [$userId, mb_substr((string) ($tpl['title'] ?? 'كود التحقق'), 0, 150), $body, $phone]);
        self::dispatch([$id]);
        return true;
    }

    /** @var int[] */
    private static array $later = [];

    /**
     * الإشعارات التلقائية: الإرسال لـ n8n بعد ما الرد يوصل للعميل (مايأخرش الطلب الأصلي)
     */
    public static function dispatchLater(int $id): void
    {
        if (!self::$later) {
            register_shutdown_function(static function () {
                $ids = self::$later;
                self::$later = [];
                if (!$ids) return;
                if (PHP_SAPI !== 'cli' && function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
                self::dispatch($ids);
            });
        }
        self::$later[] = $id;
    }

    /** يبعت IDs لـ n8n فورًا — لو فشل بيرجّعها pending (الجدولة هتلقطها) — عمره ما بيرمي exception */
    public static function dispatch(array $messageIds): void
    {
        try {
            $ids = array_values(array_unique(array_filter(array_map('intval', $messageIds))));
            $url = trim(wa_setting('wa_n8n_webhook_url'));
            $secret = wa_setting('wa_shared_secret');
            if (!$ids || $url === '' || $secret === '' || !wa_enabled() || !function_exists('curl_init')) return;
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $token = bin2hex(random_bytes(8));
            // atomic: ناخد بس اللي لسه pending (يمنع الإرسال المزدوج مع الجدولة)
            db_run("UPDATE messages SET wa_status = 'processing', locked_at = NOW(), wa_error = ?
                    WHERE id IN ($ph) AND wa_status = 'pending' AND scheduled_at <= NOW()", array_merge(['lock:' . $token], $ids));
            $rows = db_all("SELECT id, wa_phone, body, link, media_url, media_type, type FROM messages WHERE id IN ($ph) AND wa_status = 'processing' AND wa_error = ?",
                array_merge($ids, ['lock:' . $token]));
            if (!$rows) return;
            $claimed = array_map(fn($r) => (int) $r['id'], $rows);
            db_run('UPDATE messages SET wa_error = NULL WHERE id IN (' . implode(',', $claimed) . ')');
            $payload = ['messages' => array_map([self::class, 'wire'], $rows)];
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Spread-Key: ' . $secret],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_TIMEOUT => 3,
                CURLOPT_NOSIGNAL => true,
            ]);
            curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_errno($ch);
            curl_close($ch);
            if ($err !== 0 || $code < 200 || $code >= 300) {
                // n8n مارجعش 2xx → ترجع للطابور
                db_run("UPDATE messages SET wa_status = 'pending', locked_at = NULL
                        WHERE id IN (" . implode(',', $claimed) . ") AND wa_status = 'processing'");
            }
        } catch (\Throwable $e) {
            error_log('[wa] dispatch: ' . $e->getMessage());
        }
    }

    /** شكل الرسالة اللي n8n بيستلمها */
    public static function wire(array $r): array
    {
        $text = (string) $r['body'];
        // رابط الأدمن: لو مش مكتوب في النص بنضيفه آخر الرسالة
        if (!empty($r['link']) && ($r['type'] ?? '') !== 'notification' && strpos($text, (string) $r['link']) === false) {
            $text .= "\n" . $r['link'];
        }
        $media = !empty($r['media_url']) ? wa_abs_link((string) $r['media_url']) : null;
        return ['id' => (int) $r['id'], 'phone' => (string) $r['wa_phone'], 'text' => $text,
                'media_url' => $media, 'media_type' => $media ? ($r['media_type'] ?: wa_media_type($media)) : null];
    }

    private static function adminVars(array $u): array
    {
        $first = trim(explode(' ', trim((string) $u['name']))[0] ?? '');
        return ['name' => (string) $u['name'], 'first_name' => $first, 'credits' => wa_credits_text((int) $u['id'])];
    }

    /** الرسايل الإدارية: مش خاضعة للحد اليومي، بس خاضعة لموافقة العميل على الواتساب */
    private static function adminWaState(array $u, string $channel): array
    {
        if ($channel === 'inapp') return ['none', null, null];
        $reason = wa_block_reason($u);
        if ($reason !== '') return ['skipped', $reason, null];
        return ['pending', null, (string) $u['whatsapp_phone']];
    }
}

/* ═══════════════ طابور n8n ═══════════════ */

/** التحقق من مفتاح n8n */
function wa_check_key(): bool
{
    $secret = wa_setting('wa_shared_secret');
    $got = (string) ($_SERVER['HTTP_X_SPREAD_KEY'] ?? '');
    return $secret !== '' && $got !== '' && hash_equals($secret, $got);
}

/**
 * حجز رسايل للإرسال (atomic) — pending اللي ميعادها جه · failed أقل من 3 محاولات (بعد 5 دقايق، خلال ساعتين) · processing علقت 10 دقايق
 * @return array [{id, phone, text, media_url, media_type}]
 */
function wa_claim_pending(int $limit): array
{
    wa_ensure_schema();
    if (!wa_enabled()) return [];
    wa_release_scheduled();
    $limit = max(1, min(100, $limit));
    $pdo = db();
    $out = [];
    $pdo->beginTransaction();
    try {
        $rows = db_all("SELECT m.id, m.user_id, m.type, m.broadcast_id, m.wa_phone, m.body, m.link, m.media_url, m.media_type
                        FROM messages m
                        WHERE (m.wa_status = 'pending' AND m.scheduled_at <= NOW())
                           OR (m.wa_status = 'failed' AND m.wa_attempts < " . WA_MAX_ATTEMPTS . "
                               AND m.updated_at <= NOW() - INTERVAL 5 MINUTE AND m.updated_at >= NOW() - INTERVAL 2 HOUR)
                           OR (m.wa_status = 'processing' AND m.locked_at < NOW() - INTERVAL 10 MINUTE)
                        ORDER BY (m.type = 'otp') DESC, m.scheduled_at ASC, m.id ASC
                        LIMIT $limit FOR UPDATE");
        $skip = [];
        $take = [];
        $bids = [];
        foreach ($rows as $r) {
            if ($r['broadcast_id']) {
                $bid = (int) $r['broadcast_id'];
                if (!isset($bids[$bid])) $bids[$bid] = (string) (db_one('SELECT status FROM message_broadcasts WHERE id = ?', [$bid])['status'] ?? '');
                if ($bids[$bid] === 'cancelled') { $skip[(int) $r['id']] = 'cancelled'; continue; }
            }
            if ($r['type'] !== 'otp') {
                $u = db_one('SELECT whatsapp_opt_in FROM users WHERE id = ?', [(int) $r['user_id']]);
                if ($u && (int) $u['whatsapp_opt_in'] !== 1) { $skip[(int) $r['id']] = 'opted_out'; continue; }
            }
            if ((string) $r['wa_phone'] === '') { $skip[(int) $r['id']] = 'no_phone'; continue; }
            $take[] = $r;
        }
        foreach ($skip as $id => $why) {
            db_run("UPDATE messages SET wa_status = 'skipped', wa_error = ?, locked_at = NULL WHERE id = ?", [$why, $id]);
        }
        if ($take) {
            db_run("UPDATE messages SET wa_status = 'processing', locked_at = NOW() WHERE id IN (" . implode(',', array_map(fn($r) => (int) $r['id'], $take)) . ')');
        }
        $pdo->commit();
        foreach (array_keys($bids) as $bid) {
            db_run("UPDATE message_broadcasts SET status = 'sending' WHERE id = ? AND status = 'queued'", [$bid]);
            wa_broadcast_refresh($bid);
        }
        foreach ($take as $r) $out[] = Notifier::wire($r);
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return $out;
}

/** نتيجة الإرسال من n8n */
function wa_callback(int $messageId, string $status, string $providerId = '', string $error = ''): bool
{
    wa_ensure_schema();
    $m = db_one('SELECT id, type, body, broadcast_id, wa_status FROM messages WHERE id = ?', [$messageId]);
    if (!$m) return false;
    // كود التحقق مايتخزنش بعد ما يتبعت
    if ($m['type'] === 'otp' && $status === 'sent') {
        db_run('UPDATE messages SET body = ? WHERE id = ?', [preg_replace('/\d{6}/', '••••••', (string) $m['body']), $messageId]);
    }
    if ($status === 'sent') {
        db_run("UPDATE messages SET wa_status = 'sent', sent_at = NOW(), wa_provider_id = ?, wa_error = NULL, locked_at = NULL WHERE id = ?",
            [mb_substr($providerId, 0, 100) ?: null, $messageId]);
    } else {
        db_run("UPDATE messages SET wa_status = 'failed', wa_attempts = wa_attempts + 1, wa_error = ?, locked_at = NULL WHERE id = ?",
            [mb_substr($error !== '' ? $error : 'failed', 0, 500), $messageId]);
    }
    if ($m['broadcast_id']) wa_broadcast_refresh((int) $m['broadcast_id']);
    return true;
}

/** الحملة خلصت؟ (مفيش pending/processing ولا failed لسه هيتعاد) */
function wa_broadcast_refresh(int $bid): void
{
    $b = db_one('SELECT status FROM message_broadcasts WHERE id = ?', [$bid]);
    if (!$b || in_array($b['status'], ['done', 'cancelled', 'draft'], true)) return;
    $open = (int) (db_one("SELECT COUNT(*) n FROM messages WHERE broadcast_id = ?
                           AND (wa_status IN ('pending','processing') OR (wa_status = 'failed' AND wa_attempts < " . WA_MAX_ATTEMPTS . "))", [$bid])['n'] ?? 0);
    $unreleased = (int) (db_one("SELECT COUNT(*) n FROM messages WHERE broadcast_id = ? AND channel <> 'whatsapp' AND notif_id IS NULL", [$bid])['n'] ?? 0);
    if ($open === 0 && $unreleased === 0) db_run("UPDATE message_broadcasts SET status = 'done' WHERE id = ?", [$bid]);
}

/** الحملات المجدولة: لما ميعادها ييجي، داخل الموقع يظهر للعملاء */
function wa_release_scheduled(): void
{
    try {
        $rows = db_all("SELECT m.id, m.user_id, m.title, m.body FROM messages m
                        JOIN message_broadcasts b ON b.id = m.broadcast_id AND b.status IN ('queued','sending')
                        WHERE m.channel <> 'whatsapp' AND m.notif_id IS NULL AND m.scheduled_at <= NOW() LIMIT 1000");
        foreach ($rows as $r) {
            $nid = wa_inapp_insert((int) $r['user_id'], 'admin_broadcast', (string) $r['title'], (string) $r['body'], '', 'brand', false);
            if ($nid) db_run('UPDATE messages SET notif_id = ? WHERE id = ?', [$nid, (int) $r['id']]);
        }
        foreach (db_all("SELECT id FROM message_broadcasts WHERE status IN ('queued','sending') AND scheduled_at <= NOW()") as $b) {
            wa_broadcast_refresh((int) $b['id']);
        }
    } catch (\Throwable $e) {
        error_log('[wa] release: ' . $e->getMessage());
    }
}

/** إحصائيات حملة */
function wa_broadcast_stats(int $bid): array
{
    $r = db_one("SELECT COUNT(*) total,
                        SUM(wa_status = 'sent') sent,
                        SUM(wa_status = 'failed' AND wa_attempts >= " . WA_MAX_ATTEMPTS . ") failed,
                        SUM(wa_status IN ('pending','processing') OR (wa_status = 'failed' AND wa_attempts < " . WA_MAX_ATTEMPTS . ")) queued,
                        SUM(wa_status = 'skipped') skipped,
                        SUM(wa_status <> 'none') wa
                 FROM messages WHERE broadcast_id = ?", [$bid]) ?: [];
    $read = db_one('SELECT COUNT(*) inapp, SUM(un.read_at IS NOT NULL) rd FROM messages m JOIN user_notifications un ON un.id = m.notif_id WHERE m.broadcast_id = ?', [$bid]) ?: [];
    $inapp = (int) ($read['inapp'] ?? 0);
    return [
        'total' => (int) ($r['total'] ?? 0), 'sent' => (int) ($r['sent'] ?? 0), 'failed' => (int) ($r['failed'] ?? 0),
        'queued' => (int) ($r['queued'] ?? 0), 'skipped' => (int) ($r['skipped'] ?? 0), 'wa' => (int) ($r['wa'] ?? 0),
        'inapp' => $inapp, 'read_pct' => $inapp > 0 ? (int) round(((int) ($read['rd'] ?? 0)) / $inapp * 100) : 0,
    ];
}

/** آخر نشاط للعميل (مرة كل 5 دقايق بالكتير) */
function wa_touch_active(int $uid): void
{
    if ($uid <= 0) return;
    if (session_status() === PHP_SESSION_ACTIVE) {
        $last = (int) ($_SESSION['wa_active_at'] ?? 0);
        if ($last > time() - 300) return;
        $_SESSION['wa_active_at'] = time();
    }
    try {
        db_run('UPDATE users SET last_active_at = NOW() WHERE id = ? AND (last_active_at IS NULL OR last_active_at < NOW() - INTERVAL 5 MINUTE)', [$uid]);
    } catch (\Throwable $e) {
        // قبل إنشاء العمود: نعمل البنية مرة
        static $tried = false;
        if (!$tried) { $tried = true; wa_ensure_schema(); }
    }
}

/**
 * الرصيد قرب يخلص → credits_low مرة واحدة لحد أول شحن بعدها
 *   العرض بالأرقام: الرصيد ≤ wa_credits_low_at (افتراضي 10)
 *   العرض بالنسبة %: الباقي ≤ wa_credits_low_pct (افتراضي 15%) من إجمالي الباقة
 */
function wa_check_credits_low(int $uid, int $balanceAfter): void
{
    try {
        $show = true;
        if (function_exists('credits_show_numbers')) $show = credits_show_numbers();
        if ($show) {
            $at = (int) (get_setting('wa_credits_low_at', '10') ?? 10);
            if ($at <= 0 || $balanceAfter > $at) return;
            $text = max(0, $balanceAfter) . ' كريدت';
        } else {
            if (!function_exists('ui_credits_usage')) require_once __DIR__ . '/ui-v2.php';
            $u = ui_credits_usage($uid);
            $total = max(1, (int) ($u['total'] ?? 0));
            $left = (int) floor(max(0, $balanceAfter) / $total * 100);
            if ($left > (int) (get_setting('wa_credits_low_pct', '15') ?? 15)) return;
            $text = $left . '% من باقتك';
        }
        $lastAdd = (string) (db_one('SELECT MAX(created_at) t FROM credit_transactions WHERE user_id = ? AND action_type = "add"', [$uid])['t'] ?? '');
        $sent = db_one('SELECT id FROM messages WHERE user_id = ? AND event_key = "credits_low"' . ($lastAdd !== '' ? ' AND created_at >= ?' : '') . ' LIMIT 1',
            $lastAdd !== '' ? [$uid, $lastAdd] : [$uid]);
        if ($sent) return;
        Notifier::notify($uid, 'credits_low', ['credits' => $text], ['url' => 'packages.php', 'tone' => 'warn']);
    } catch (\Throwable $e) {
        error_log('[wa] credits_low: ' . $e->getMessage());
    }
}

/** نص حالة الواتساب بالعربي */
function wa_status_label(string $s, ?string $err = null): string
{
    $map = ['none' => '—', 'pending' => 'في الطابور', 'processing' => 'بيتبعت', 'sent' => 'اتبعت ✓', 'failed' => 'فشل', 'skipped' => 'اتخطى'];
    $why = ['no_phone' => 'مفيش رقم', 'not_verified' => 'الرقم مش متحقق', 'opted_out' => 'العميل قافل الواتساب', 'wa_disabled' => 'الواتساب مقفول',
            'daily_limit' => 'الحد اليومي', 'cancelled' => 'الحملة اتلغت'];
    $l = $map[$s] ?? $s;
    if ($s === 'skipped' && $err) $l .= ' (' . ($why[$err] ?? $err) . ')';
    return $l;
}

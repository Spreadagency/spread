<?php
/** حماية لوحة تحكم الموقع · الأدوار والصلاحيات · سجل النشاط */
require_once dirname(__DIR__) . '/site/functions.php';

function sa_admin(): ?array
{
    if (empty($_SESSION['site_admin_id'])) return null;
    static $a = null;
    if ($a === null) {
        $a = s_one('SELECT * FROM site_admins WHERE id = ? AND status = "active"', [$_SESSION['site_admin_id']]);
    }
    return $a ?: null;
}

function sa_require(): void
{
    if (!sa_admin()) {
        s_redirect('site-admin/login.php');
    }
}

/* ═══════════════ حد محاولات الدخول (5 كل 15 دقيقة لكل IP ولكل إيميل) ═══════════════
 * قاعدة الموقع التعريفي مافيهاش جدول rate_limits — المحاولات الفاشلة بتتسجل في إعداد واحد (login_throttle) وبتتنضّف لوحدها. */
const SA_LOGIN_MAX = 5;
const SA_LOGIN_WINDOW = 900;

function sa_login_throttle(): array
{
    // قراءة مباشرة (مش من كاش s_setting) عشان العدّاد يبقى دايمًا آخر قيمة
    $j = json_decode((string) (s_one('SELECT setting_value FROM site_settings WHERE setting_key = ?', ['login_throttle'])['setting_value'] ?? '{}'), true);
    $now = time();
    $out = [];
    foreach (is_array($j) ? $j : [] as $k => $times) {
        $times = array_values(array_filter((array) $times, fn($t) => is_int($t) && $t > $now - SA_LOGIN_WINDOW));
        if ($times) $out[(string) $k] = $times;
    }
    return $out;
}

function sa_login_keys(string $ip, string $email): array
{
    return ['i' . substr(hash('sha256', $ip), 0, 16), 'e' . substr(hash('sha256', $email), 0, 16)];
}

function sa_login_allowed(string $ip, string $email): bool
{
    try {
        $t = sa_login_throttle();
        foreach (sa_login_keys($ip, $email) as $k) if (count($t[$k] ?? []) >= SA_LOGIN_MAX) return false;
    } catch (\Throwable $e) {
        error_log('[site-admin] throttle: ' . $e->getMessage());
        return false;   // مفيش دخول من غير عدّاد شغال
    }
    return true;
}

function sa_login_failed(string $ip, string $email): void
{
    try {
        $t = sa_login_throttle();
        foreach (sa_login_keys($ip, $email) as $k) $t[$k][] = time();
        if (count($t) > 500) $t = array_slice($t, -500, null, true);
        s_set('login_throttle', json_encode($t));
    } catch (\Throwable $e) {
        error_log('[site-admin] throttle: ' . $e->getMessage());
    }
}

function sa_login(string $email, string $pass): bool
{
    $u = s_one('SELECT * FROM site_admins WHERE email = ? AND status = "active"', [mb_strtolower(trim($email))]);
    if (!$u || !password_verify($pass, $u['password'])) return false;
    session_regenerate_id(true);
    $_SESSION['site_admin_id'] = (int) $u['id'];
    s_run('UPDATE site_admins SET last_login_at = NOW() WHERE id = ?', [(int) $u['id']]);
    sa_log('login', 'system', 'تسجيل دخول للوحة', (int) $u['id'], $u);
    return true;
}

/* ═══════════════ الأدوار والصلاحيات ═══════════════ */

/** الأدوار: key => [الاسم, وصف] */
function sa_roles(): array
{
    return [
        'super_admin'     => ['Super Admin', 'كل الصلاحيات — ومنها إدارة المستخدمين والأدوار'],
        'admin'           => ['Admin', 'كل أقسام الموقع والإعدادات — من غير إدارة المستخدمين'],
        'editor'          => ['Editor', 'المحتوى والصفحات والعروض والأسعار والـ SEO'],
        'content_manager' => ['Content Manager', 'المحتوى والتصميمات والفيديوهات والآراء والوسائط'],
        'viewer'          => ['Viewer', 'مشاهدة اللوحة والتحليلات فقط'],
    ];
}

/** أقسام الصلاحيات: key => الاسم */
function sa_perm_sections(): array
{
    return [
        'homepage'     => 'الصفحة الرئيسية والأقسام',
        'pages'        => 'الصفحات',
        'navigation'   => 'القوائم والهيدر والفوتر',
        'design'       => 'الهوية البصرية',
        'seo'          => 'SEO',
        'content'      => 'المحتوى (المميزات · المشاكل · الخطوات · الخدمات · البراندات)',
        'designs'      => 'التصميمات',
        'videos'       => 'الفيديوهات',
        'testimonials' => 'آراء العملاء',
        'faq'          => 'الأسئلة الشائعة',
        'create_post'  => 'التجربة التفاعلية (اصنع منشورك)',
        'offers'       => 'العروض',
        'pricing'      => 'الباقات والأسعار',
        'leads'        => 'العملاء المحتملين',
        'analytics'    => 'التحليلات',
        'media'        => 'مكتبة الوسائط',
        'settings'     => 'الإعدادات',
        'activity'     => 'سجل النشاط',
        'users'        => 'المستخدمين والصلاحيات',
    ];
}

function sa_default_matrix(): array
{
    $all = array_keys(sa_perm_sections());
    $editor = ['homepage', 'pages', 'navigation', 'seo', 'content', 'designs', 'videos', 'testimonials', 'faq', 'create_post', 'offers', 'pricing', 'media', 'leads', 'analytics'];
    return [
        'super_admin'     => $all,
        'admin'           => array_values(array_diff($all, ['users'])),
        'editor'          => $editor,
        'content_manager' => ['homepage', 'content', 'designs', 'videos', 'testimonials', 'faq', 'media'],
        'viewer'          => ['analytics', 'leads'],
    ];
}

/** مصفوفة الصلاحيات الحالية (المعدّلة من «المستخدمين والصلاحيات» أو الافتراضية) */
function sa_matrix(): array
{
    static $m = null;
    if ($m !== null) return $m;
    $m = sa_default_matrix();
    $saved = json_decode(s_setting('roles_matrix'), true);
    if (is_array($saved)) {
        foreach ($saved as $role => $perms) {
            if ($role === 'super_admin' || !isset($m[$role]) || !is_array($perms)) continue; // Super Admin دايمًا كل حاجة
            $m[$role] = array_values(array_intersect($perms, array_keys(sa_perm_sections())));
        }
    }
    return $m;
}

function sa_role(?array $admin = null): string
{
    $admin = $admin ?? sa_admin();
    $r = (string) ($admin['role'] ?? 'super_admin');
    return isset(sa_roles()[$r]) ? $r : 'viewer';
}

/** الأدمن الحالي يقدر يدير القسم ده؟ */
function sa_can(string $perm, ?array $admin = null): bool
{
    $admin = $admin ?? sa_admin();
    if (!$admin) return false;
    $role = sa_role($admin);
    if ($role === 'super_admin') return true;
    return in_array($perm, sa_matrix()[$role] ?? [], true);
}

/** لازم صلاحية — وإلا صفحة «مش مسموح» */
function sa_require_perm(string $perm): void
{
    sa_require();
    if (sa_can($perm)) return;
    http_response_code(403);
    $__t = 'غير مسموح';
    $__perm_denied = true;
    include __DIR__ . '/layout.php';
    echo sa_empty('lock', 'القسم ده مش ضمن صلاحياتك', 'كلّم الـ Super Admin لو محتاج تدخل «' . (sa_perm_sections()[$perm] ?? $perm) . '».',
        '<a class="ad-btn ad-sec" href="dashboard.php">رجوع للوحة التحكم</a>');
    include __DIR__ . '/layout-end.php';
    exit;
}

/* ═══════════════ سجل النشاط ═══════════════ */

/**
 * تسجيل عملية إدارية
 * @param string $action create|update|delete|toggle|reorder|publish|duplicate|settings|login|logout|upload
 */
function sa_log(string $action, string $section, string $summary, ?int $targetId = null, ?array $admin = null): void
{
    $admin = $admin ?? sa_admin();
    $ip = mb_substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    s_run('INSERT INTO site_activity_log (admin_id, admin_name, action, section, target_id, summary, ip) VALUES (?,?,?,?,?,?,?)', [
        $admin ? (int) $admin['id'] : null, $admin ? mb_substr((string) $admin['name'], 0, 150) : null,
        mb_substr($action, 0, 40), mb_substr($section, 0, 60), $targetId, mb_substr($summary, 0, 500), $ip,
    ]);
}

function sa_action_labels(): array
{
    return [
        'create' => 'إضافة', 'update' => 'تعديل', 'delete' => 'حذف', 'toggle' => 'إظهار/إخفاء', 'reorder' => 'ترتيب',
        'publish' => 'نشر', 'unpublish' => 'إلغاء النشر', 'duplicate' => 'نسخ', 'settings' => 'إعدادات', 'login' => 'دخول',
        'logout' => 'خروج', 'upload' => 'رفع ملف', 'role' => 'صلاحيات',
    ];
}

/* ═══════════════ JSON (طلبات الواجهة) ═══════════════ */

function sa_is_ajax(): bool
{
    return ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest' || str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
}

function sa_json(array $d, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}

/** CSRF لطلبات الـ JSON (رسالة JSON بدل صفحة) */
function sa_check_csrf_json(): void
{
    if (!hash_equals($_SESSION['site_csrf'] ?? '', (string) ($_POST['csrf'] ?? ''))) {
        sa_json(['ok' => false, 'error' => 'انتهت الجلسة — حدّث الصفحة وحاول تاني.'], 403);
    }
}

require_once __DIR__ . '/ui.php';

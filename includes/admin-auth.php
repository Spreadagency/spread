<?php
/**
 * Spread AI — Admin Auth (separate from user auth)
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

function current_admin(): ?array
{
    if (empty($_SESSION['admin_id'])) return null;
    static $admin = null;
    if ($admin === null) {
        $admin = db_one('SELECT * FROM admin_users WHERE id = ? LIMIT 1', [$_SESSION['admin_id']]);
    }
    return $admin ?: null;
}

function is_admin_logged_in(): bool
{
    return current_admin() !== null;
}

function require_admin(): void
{
    if (!is_admin_logged_in()) {
        flash_set('warning', 'سجّل دخول كأدمن أولًا');
        redirect('admin/login.php');
    }
    $a = current_admin();
    if ($a['status'] !== 'active') {
        unset($_SESSION['admin_id']);
        flash_set('danger', 'حساب الأدمن موقوف');
        redirect('admin/login.php');
    }
}

function login_admin(int $adminId): void
{
    $_SESSION['admin_id'] = $adminId;
    session_regenerate_id(true);
}

function logout_admin(): void
{
    unset($_SESSION['admin_id']);
    session_regenerate_id(true);
}

function authenticate_admin(string $email, string $password): array
{
    $a = db_one('SELECT * FROM admin_users WHERE email = ? LIMIT 1', [$email]);
    if (!$a) return ['ok' => false, 'error' => 'البيانات غير صحيحة'];
    if (!password_verify($password, $a['password'])) {
        return ['ok' => false, 'error' => 'البيانات غير صحيحة'];
    }
    if ($a['status'] !== 'active') {
        return ['ok' => false, 'error' => 'الحساب موقوف'];
    }
    return ['ok' => true, 'admin' => $a];
}

/**
 * Log admin action
 */
function admin_log(string $action, ?string $entityType = null, ?int $entityId = null, $meta = null): void
{
    if (empty($_SESSION['admin_id'])) return;
    db_run(
        'INSERT INTO admin_logs (admin_user_id, action, entity_type, entity_id, meta) VALUES (?, ?, ?, ?, ?)',
        [
            $_SESSION['admin_id'],
            $action,
            $entityType,
            $entityId,
            is_array($meta) ? json_encode($meta, JSON_UNESCAPED_UNICODE) : $meta
        ]
    );
}

/* ═══════════════ أدوار الأدمن والصلاحيات ═══════════════ */

/**
 * الأدوار:
 *  assistant = مساعد شخصي  → يدخل بحساب العميل ويساعده + يضيف صور للاستوديو (بدون كريدت)
 *  manager   = إدارة حسابات → كل ما سبق + شحن كريدت وإدارة الاشتراكات
 *  super     = أدمن كامل    → كل حاجة + حذف العملاء وإدارة فريق الأدمن
 */
function admin_role(): string
{
    $a = current_admin();
    return $a['role'] ?? 'super';
}

function admin_role_label(?string $role = null): string
{
    return [
        'assistant' => '🤝 مساعد شخصي',
        'manager'   => '💳 إدارة الحسابات',
        'super'     => '👑 أدمن كامل',
    ][$role ?? admin_role()] ?? $role ?? '';
}

/** هل للأدمن الحالي صلاحية معينة؟ */
function admin_can(string $permission): bool
{
    $role = admin_role();

    $matrix = [
        // مساعدة العملاء
        'impersonate'     => ['assistant', 'manager', 'super'],  // الدخول بحساب العميل
        'studio_media'    => ['assistant', 'manager', 'super'],  // إضافة صور لمعرض الإلهام
        'view_users'      => ['assistant', 'manager', 'super'],
        'view_content'    => ['assistant', 'manager', 'super'],
        // إدارة الحسابات
        'add_credits'     => ['manager', 'super'],
        'approve_users'   => ['manager', 'super'],
        'manage_packages' => ['manager', 'super'],
        'view_costs'      => ['manager', 'super'],  // 8-أ: تكاليف الـ AI · سجل العمليات · التنبيهات
        // أدمن كامل
        'delete_user'     => ['super'],
        'manage_admins'   => ['super'],
        'site_settings'   => ['super'],
        'ai_settings'     => ['super'],
        'features'        => ['super'],
    ];

    return in_array($role, $matrix[$permission] ?? ['super'], true);
}

/** حارس: يوقف الصفحة لو الصلاحية ناقصة */
function require_admin_can(string $permission): void
{
    if (!admin_can($permission)) {
        http_response_code(403);
        $label = admin_role_label();
        exit('<div style="font-family:system-ui;direction:rtl;text-align:center;padding:60px">'
            . '<h2>🚫 غير مسموح</h2>'
            . '<p>دورك الحالي (' . htmlspecialchars($label) . ') مش ليه صلاحية الصفحة دي.</p>'
            . '<p><a href="' . htmlspecialchars(url('admin/dashboard.php')) . '">رجوع للوحة</a></p></div>');
    }
}

/* ═══════════════ الدخول بحساب العميل (Impersonation) ═══════════════ */

/** هل الأدمن داخل حاليًا بحساب عميل؟ */
function is_impersonating(): bool
{
    return !empty($_SESSION['impersonator_admin_id']) && !empty($_SESSION['user_id']);
}

function impersonator_admin(): ?array
{
    if (empty($_SESSION['impersonator_admin_id'])) {
        return null;
    }
    return db_one('SELECT * FROM admin_users WHERE id = ?', [$_SESSION['impersonator_admin_id']]) ?: null;
}

/** يبدأ جلسة مساعدة: الأدمن يشوف المنصة بعين العميل */
function impersonate_start(int $userId): bool
{
    $admin = current_admin();
    if (!$admin || !admin_can('impersonate')) {
        return false;
    }
    $u = db_one('SELECT id FROM users WHERE id = ?', [$userId]);
    if (!$u) {
        return false;
    }

    $_SESSION['user_id'] = (int) $userId;
    $_SESSION['impersonator_admin_id'] = (int) $admin['id'];

    try {
        $logId = db_insert(
            'INSERT INTO impersonation_logs (admin_id, user_id, ip) VALUES (?, ?, ?)',
            [$admin['id'], $userId, mb_substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45)]
        );
        $_SESSION['impersonation_log_id'] = $logId;
    } catch (\Throwable $e) {
    }
    if (function_exists('admin_log')) {
        admin_log('impersonate_start', 'user', $userId);
    }
    return true;
}

/** ينهي جلسة المساعدة ويرجّع الأدمن للوحته */
function impersonate_stop(): void
{
    if (!empty($_SESSION['impersonation_log_id'])) {
        try {
            db_run('UPDATE impersonation_logs SET ended_at = NOW() WHERE id = ?', [$_SESSION['impersonation_log_id']]);
        } catch (\Throwable $e) {
        }
    }
    if (function_exists('admin_log') && !empty($_SESSION['user_id'])) {
        admin_log('impersonate_stop', 'user', (int) $_SESSION['user_id']);
    }
    unset($_SESSION['user_id'], $_SESSION['impersonator_admin_id'], $_SESSION['impersonation_log_id']);
}

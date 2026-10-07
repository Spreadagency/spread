<?php
/**
 * Spread AI v2 — دخول الأدمن بحساب العميل (مساعدة)
 * ?user_id=N  → يبدأ · ?stop=1 → ينهي
 */
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/functions.php';

if (isset($_GET['stop'])) {
    impersonate_stop();
    flash_set('success', 'رجعت للوحة الأدمن ✓');
    redirect('admin/users.php');
}

require_admin();
require_admin_can('impersonate');

$userId = (int) ($_GET['user_id'] ?? 0);
$u = db_one('SELECT id, name, email FROM users WHERE id = ?', [$userId]);
if (!$u) {
    flash_set('danger', 'العميل غير موجود');
    redirect('admin/users.php');
}

if (!impersonate_start($userId)) {
    flash_set('danger', 'تعذّر الدخول بحساب العميل');
    redirect('admin/users.php');
}

flash_set('success', 'دخلت بحساب «' . $u['name'] . '» — كل اللي هتعمله هيتسجل باسمه');
redirect('dashboard.php');

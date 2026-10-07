<?php
/**
 * شريط تنبيه بيظهر للأدمن وهو داخل بحساب عميل
 */
// صفحات العميل مبتحمّلش admin-auth — نحمّله بس لو فيه جلسة مساعدة
if (empty($_SESSION['impersonator_admin_id'])) {
    return;
}
if (!function_exists('is_impersonating')) {
    require_once __DIR__ . '/../includes/admin-auth.php';
}
if (!is_impersonating()) {
    return;
}
$__imp_admin = impersonator_admin();
$__imp_user = current_user();
?>
<div style="position:sticky;top:0;z-index:80;background:#b8860b;color:#fff;padding:8px 14px;font-size:13px;
            display:flex;align-items:center;gap:10px;flex-wrap:wrap;justify-content:center;box-shadow:0 2px 10px rgba(0,0,0,.15)">
    <span>👁 <b><?= e($__imp_admin['name'] ?? 'أدمن') ?></b> داخل بحساب العميل
        <b><?= e($__imp_user['name'] ?? '') ?></b> (<?= e($__imp_user['email'] ?? '') ?>)</span>
    <a href="<?= e(url('admin/impersonate.php?stop=1')) ?>"
       style="background:#fff;color:#8b6508;padding:4px 12px;border-radius:8px;text-decoration:none;font-weight:700">
        ✕ خروج ورجوع للوحة
    </a>
</div>

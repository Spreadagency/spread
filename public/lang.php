<?php
/**
 * Spread AI — تغيير لغة الواجهة (الترجمة · المرحلة 2): lang.php?l=en&back=/المسار
 * بيحفظ الاختيار في الكوكي (المنصة والموقع) وفي حساب العميل لو داخل، ويرجّع لنفس الصفحة (مسار محلي بس).
 * GET عادي زي أي لينك — بيغيّر تفضيل عرض بس، مفيش بيانات حساسة.
 */
require_once __DIR__ . '/../includes/auth.php';

$l = (string) ($_GET['l'] ?? '');
$back = i18n_safe_back((string) ($_GET['back'] ?? '/'));
if (i18n_valid($l)) {
    i18n_remember($l);
    i18n_set($l);
    if (is_logged_in()) {
        try {
            db_run('UPDATE users SET locale = ? WHERE id = ?', [$l === I18N_DEFAULT ? null : $l, (int) $_SESSION['user_id']]);
        } catch (\Throwable $e) {
            // ترحيل 2026_i18n.sql لسه ماتشغّلش ← الكوكي بس
        }
    }
}
header('Cache-Control: no-store');
header('Location: ' . $back, true, 302);
exit;

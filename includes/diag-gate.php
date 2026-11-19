<?php
/**
 * Spread AI — قفل صفحات التشخيص (Content Engine · المرحلة B)
 * صفحات التشخيص بتكشف معلومات عن السيرفر — بتفتح بس لو:
 *   - الموقع لسه بيتركّب (مفيش includes/config.php)
 *   - أو أدمن داخل لوحة التحكم في نفس المتصفح
 *   - أو فيه ملف اسمه .diag-open في فولدر الموقع الرئيسي اتعمل من أقل من ساعة
 *     (لما قاعدة البيانات واقعة واللوحة مش بتفتح: اعمله من File Manager، وامسحه بعد ما تخلص)
 * مابيلمسش قاعدة البيانات ولا بيحمّل config.php — عشان يشتغل حتى لو هما بايظين.
 */
function diag_gate(): void
{
    if (PHP_SAPI === 'cli') return;
    $root = dirname(__DIR__);
    if (!is_file($root . '/includes/config.php')) return;
    $flag = $root . '/.diag-open';
    if (is_file($flag) && time() - (int) filemtime($flag) < 3600) return;
    if (session_status() === PHP_SESSION_NONE) @session_start();
    if (!empty($_SESSION['admin_id'])) return;
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="ar" dir="rtl"><meta charset="utf-8"><title>مقفولة</title>'
        . '<body style="font-family:Tahoma,Arial;padding:40px;line-height:2;color:#222">'
        . '<h2>صفحة التشخيص مقفولة 🔒</h2>'
        . '<p>ادخل لوحة التحكم كأدمن الأول وارجع افتح الصفحة.<br>'
        . 'لو اللوحة مش بتفتح: اعمل ملف فاضي اسمه <code>.diag-open</code> في فولدر الموقع الرئيسي من File Manager (بيشتغل ساعة)، وامسحه بعد ما تخلص.</p>';
    exit;
}
diag_gate();

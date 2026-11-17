<?php
/**
 * Spread AI — فحص الترجمة: كل نص ملفوف بـ __('…') في الملفات دي ليه ترجمة إنجليزي؟
 * للتطوير (وممكن يتشغّل في الـ CI):
 *
 *   php tests/i18n-check.php                 ← الملفات المترجمة (I18N_DONE تحت)
 *   php tests/i18n-check.php public/x.php    ← ملفات معيّنة
 *
 * بيطبع النصوص الناقصة ويرجّع 1 لو فيه نقص.
 */
if (PHP_SAPI !== 'cli') exit("CLI only\n");
require_once __DIR__ . '/../includes/i18n.php';

/** الملفات اللي اتترجمت بالكامل (كل دفعة بتضيف ملفاتها هنا) */
const I18N_DONE = [
    'templates/v2/auth.php', 'public/login.php', 'public/register.php', 'public/forgot-password.php', 'public/reset-password.php', 'public/resend-verification.php',
    'site/parts/site-header.php', 'site/parts/site-footer.php', 'site/sections/hero.php',
    // الدفعة 2: إطار المنصة · الرئيسية · «ابدأ» · إنشاء المحتوى
    'templates/v2/sidebar.php', 'templates/v2/topbar.php', 'templates/v2/tabbar.php', 'templates/v2/dashboard.php', 'templates/v2/quick-post.php',
    'templates/sidebar.php', 'templates/topbar.php', 'public/dashboard.php', 'public/start.php', 'public/create-content.php',
    // رحلة الهوية الجديدة + عرض البداية
    'templates/trial-banner.php',
];

function i18n_keys_in(string $src): array
{
    $out = [];
    // __('…') · __v('…' و i18n_js(['…', …]) — نصوص PHP بعلامة ' بس (زي ما الكود مكتوب)
    preg_match_all("/\\b(?:__|__v)\\(\\s*'((?:[^'\\\\]|\\\\.)*)'/u", $src, $m);
    foreach ($m[1] as $k) $out[] = stripcslashes(str_replace("\\'", "'", $k));
    if (preg_match_all('/i18n_js\(\s*\[(.*?)\]\s*\)/su', $src, $mm)) {
        foreach ($mm[1] as $list) {
            preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/u", $list, $m2);
            foreach ($m2[1] as $k) $out[] = str_replace("\\'", "'", $k);
        }
    }
    return array_values(array_unique(array_filter($out, fn($k) => preg_match('/\p{Arabic}/u', $k))));
}

$root = dirname(__DIR__);
$files = array_slice($argv, 1) ?: I18N_DONE;
$dict = i18n_dict('en');
$missing = 0;
foreach ($files as $f) {
    $src = @file_get_contents($root . '/' . ltrim($f, '/'));
    if ($src === false) { echo "✗ الملف مش موجود: $f\n"; $missing++; continue; }
    foreach (i18n_keys_in($src) as $k) {
        if (!isset($dict[$k])) { echo "✗ $f: «" . $k . "»\n"; $missing++; }
    }
}
echo $missing ? "\n✗ $missing نص من غير ترجمة\n" : "✓ كل النصوص ليها ترجمة (" . count($files) . " ملف)\n";
exit($missing ? 1 : 0);

<?php
/**
 * Spread AI — Common Header
 * Variables expected: $page_title (optional), $body_class (optional)
 */

$page_title = $page_title ?? APP_NAME;
// الترجمة: الصفحة بتتعرض بالإنجليزي بس لو اتترجمت ($i18n_ready = true قبل الهيدر) — غير كده بتفضل عربي ومن اليمين للشمال
// زي ما هي، حتى لو العميل مختار English (علشان مايبقاش فيه صفحة نصها عربي واتجاهها إنجليزي لحد ما دفعتها تتترجم)
if (empty($i18n_ready)) i18n_set('ar');
$body_class = $body_class ?? '';
$__v2 = function_exists('ui_v2_enabled') && ui_v2_enabled();
// 8-ب: العميل بيشوف نسب % بس؟ → .cr (أرقام الكريدت) بتستخبى و .cr-alt بتظهر
$__crOff = function_exists('credits_show_numbers') && !credits_show_numbers();
$__asset = function (string $rel): string {
    return url($rel) . '?v=' . (@filemtime(__DIR__ . '/../' . $rel) ?: time());
};
?>
<!DOCTYPE html>
<html <?= i18n_html_attrs() ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <meta name="app-base" content="<?= e(rtrim(url(''), '/')) ?>">
    <title><?= e(__($page_title)) ?> — <?= e(function_exists('site_name') ? site_name() : APP_NAME) ?></title>
    <?php $__fav = function_exists('site_setting') ? (string) site_setting('site_logo', '') : ''; ?>
    <link rel="icon" type="image/png" href="<?= e($__fav !== '' ? url('storage/' . $__fav) : url('assets/img/favicon-v2.png')) ?>">
    <link rel="apple-touch-icon" href="<?= e(url('assets/img/spread-mark-128.png')) ?>">
    <link rel="stylesheet" href="<?= url('assets/css/main.css') ?>?v=<?= @filemtime(__DIR__ . '/../assets/css/main.css') ?: time() ?>">
    <?= function_exists('site_theme_css') ? site_theme_css() : '' ?>
    <style>body.cr-off .cr{display:none!important}body:not(.cr-off) .cr-alt{display:none!important}</style>
    <script>window.SPREAD_CR = <?= $__crOff ? 'false' : 'true' ?>;</script>
<?php if ($__v2): ?>
    <!-- الواجهة الجديدة (v2) -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="<?= $__asset('assets/css/tokens-v2.css') ?>">
    <link rel="stylesheet" href="<?= $__asset('assets/css/theme-v2.css') ?>">
    <link rel="stylesheet" href="<?= $__asset('assets/css/shell-v2.css') ?>">
    <?php if (preg_match('/\b(cf|jr|rs)-page\b/', (string) ($body_class ?? ''))): ?><link rel="stylesheet" href="<?= $__asset('assets/css/campaign-v2.css') ?>"><?php endif; ?>
    <?php if (preg_match('/\brs-page\b/', (string) ($body_class ?? ''))): ?><link rel="stylesheet" href="<?= $__asset('assets/css/research-v2.css') ?>"><?php endif; ?>
    <link rel="icon" type="image/png" href="<?= url('assets/img/favicon-v2.png') ?>">
    <meta name="theme-color" content="#EEF5FB">
<?php endif; ?>
<?php if (i18n_dir() === 'ltr'): ?>
    <!-- الترجمة: الواجهة الإنجليزي (من الشمال لليمين) — تظبيطات الاتجاه بس -->
    <link rel="stylesheet" href="<?= $__asset('assets/css/ltr.css') ?>">
<?php endif; ?>
<?php if (!empty($use_app)): ?>
    <!-- الشاشات التفاعلية: petite-vue (~7KB) + مكتبة الواجهة — بتتحمّل هنا بس -->
    <style>[v-cloak]{display:none!important}</style>
    <script src="<?= $__asset('assets/vendor/petite-vue.iife.js') ?>" defer></script>
    <script src="<?= $__asset('assets/js/spread-app.js') ?>" defer></script>
<?php endif; ?>
</head>
<body class="<?= e(trim($body_class . ($__v2 ? ' ui-v2' : '') . ($__crOff ? ' cr-off' : ''))) ?>">
<?php if (is_file(__DIR__ . '/impersonation-bar.php')) { include __DIR__ . '/impersonation-bar.php'; } ?>
<?= function_exists('site_header_message_html') ? site_header_message_html() : '' ?>

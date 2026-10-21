<?php
// شريط التنقل السفلي (موبايل) — لصفحات العملاء فقط
if (!isset($__no_tabbar) && function_exists('current_user') && !empty($_SESSION['user_id'])) {
    include __DIR__ . '/tabbar.php';
}
?>
<?= function_exists('site_footer_extras_html') ? site_footer_extras_html() : '' ?>
    <script src="<?= url('assets/js/main.js') ?>?v=<?= @filemtime(__DIR__ . '/../assets/js/main.js') ?: time() ?>"></script>
<?php if (!empty($_SESSION['user_id']) && (!function_exists('get_setting') || get_setting('issues_enabled', '1') === '1')): ?>
    <!-- نظام الجودة: «بلّغ عن مشكلة» + تقييم التصميم -->
    <link rel="stylesheet" href="<?= url('assets/css/quality.css') ?>?v=<?= @filemtime(__DIR__ . '/../assets/css/quality.css') ?: time() ?>">
    <script src="<?= url('assets/js/quality.js') ?>?v=<?= @filemtime(__DIR__ . '/../assets/js/quality.js') ?: time() ?>" defer></script>
<?php endif; ?>
<?php if (function_exists('ui_v2_enabled') && ui_v2_enabled()
          && (!function_exists('get_setting') || get_setting('ui_v2_thinking', '1') === '1')): ?>
    <script src="<?= url('assets/js/orb.js') ?>?v=<?= @filemtime(__DIR__ . '/../assets/js/orb.js') ?: time() ?>" defer></script>
    <script src="<?= url('assets/js/thinking.js') ?>?v=<?= @filemtime(__DIR__ . '/../assets/js/thinking.js') ?: time() ?>" defer></script>
<?php endif; ?>
</body>
</html>

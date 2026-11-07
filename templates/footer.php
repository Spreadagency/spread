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
<?php
// المرحلة 7: «مساعدك» (🛟 الدعم · 🤖 الوكيل) — لصفحات العملاء بس، ولو المساعد متاح
if (!empty($_SESSION['user_id']) && !isset($__no_assistant) && empty($_SESSION['impersonator_admin_id']) && is_file(__DIR__ . '/../includes/assistants.php')) {
    require_once __DIR__ . '/../includes/assistants.php';
    try {
        $__saOn = as_ready() && (get_setting('assistant_support_enabled', '1') === '1' || get_setting('assistant_agent_enabled', '1') === '1');
    } catch (\Throwable $e) { $__saOn = false; }
    if ($__saOn): ?>
    <link rel="stylesheet" href="<?= url('assets/css/assistant.css') ?>?v=<?= @filemtime(__DIR__ . '/../assets/css/assistant.css') ?: time() ?>">
    <script src="<?= url('assets/js/assistant.js') ?>?v=<?= @filemtime(__DIR__ . '/../assets/js/assistant.js') ?: time() ?>" defer></script>
<?php endif;
} ?>
<?php if (function_exists('ui_v2_enabled') && ui_v2_enabled()
          && (!function_exists('get_setting') || get_setting('ui_v2_thinking', '1') === '1')): ?>
    <script src="<?= url('assets/js/orb.js') ?>?v=<?= @filemtime(__DIR__ . '/../assets/js/orb.js') ?: time() ?>" defer></script>
    <script src="<?= url('assets/js/thinking.js') ?>?v=<?= @filemtime(__DIR__ . '/../assets/js/thinking.js') ?: time() ?>" defer></script>
<?php endif; ?>
</body>
</html>

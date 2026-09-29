<?php
/**
 * Spread AI v2 — الشريط العائم للموبايل (زي التصميم)
 * الرئيسية · حملاتي · [＋ خطة جديدة] · المحتويات · الإعدادات
 */
require_once __DIR__ . '/../../includes/ui-v2.php';
$__section = ui_section_of($active ?? '');
$__t = function (string $sec, string $icon, string $label, string $href) use ($__section) {
    $on = $__section === $sec;
    return '<a href="' . e(url($href)) . '" class="' . ($on ? 'on' : '') . '"' . ($on ? ' aria-current="page"' : '') . '>'
        . ui_icon($icon, 22) . '<span>' . e($label) . '</span></a>';
};
?>
<nav class="v2-dock" aria-label="التنقل السريع">
    <?= $__t('dashboard', 'home', 'الرئيسية', 'dashboard.php') ?>
    <?= $__t('campaigns', 'calendar', 'حملاتي', ui_campaigns_on() ? 'campaigns.php' : 'content-plan.php') ?>
    <?php $__fab = ui_campaigns_on() ? ['campaigns.php?new=1', 'خطة جديدة'] : ['content-plan.php', 'خطة المحتوى']; ?>
    <a href="<?= url($__fab[0]) ?>" class="v2-fab" aria-label="<?= e($__fab[1]) ?>" title="<?= e($__fab[1]) ?>">
        <?= ui_icon('plus', 28) ?>
    </a>
    <?= $__t('contents', 'folder', 'المحتويات', 'content-history.php') ?>
    <?= $__t('settings', 'settings', 'الإعدادات', 'profile.php') ?>
</nav>

<?php
if (function_exists('ui_v2_enabled') && ui_v2_enabled()) { include __DIR__ . '/v2/tabbar.php'; return; }
/**
 * Spread AI v2 — شريط التنقل السفلي (موبايل)
 * ثابت: الرئيسية · خطة · تصميم · منشور — وزرار القائمة لباقي الصفحات
 * Variables expected: $active
 */
$tabActive = $active ?? '';

$allTabs = [
    ['key' => 'dashboard',     'menu' => null,             'url' => 'dashboard.php',      'ic' => '⌂',  'label' => 'الرئيسية'],
    ['key' => 'plan',          'menu' => 'content-plan',   'url' => 'content-plan.php',   'ic' => '🗓', 'label' => 'خطة'],
    ['key' => 'design-studio', 'menu' => 'design-studio',  'url' => 'design-studio.php',  'ic' => '✨', 'label' => 'تصميم'],
    ['key' => 'create',        'menu' => 'create-content', 'url' => 'create-content.php', 'ic' => '✎',  'label' => 'منشور'],
];
$tabs = array_values(array_filter($allTabs, fn($t) => $t['menu'] === null || menu_visible($t['menu'])));
?>
<nav class="tabbar" aria-label="التنقل السريع" style="grid-template-columns:repeat(<?= count($tabs) + 1 ?>,1fr)">
    <?php foreach ($tabs as $t): ?>
        <a href="<?= url($t['url']) ?>" class="<?= $tabActive === $t['key'] ? 'on' : '' ?>">
            <span class="ic"><?= $t['ic'] ?></span>
            <span><?= e($t['label']) ?></span>
        </a>
    <?php endforeach; ?>
    <button type="button" onclick="toggleSidebar()" aria-label="باقي القوائم">
        <span class="ic">☰</span>
        <span>القائمة</span>
    </button>
</nav>

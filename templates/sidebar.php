<?php
// الشكل الجديد: إطار مختلف بالكامل (templates/v2) — الشكل القديم بيكمل تحت زي ما هو
if (function_exists('ui_v2_enabled') && ui_v2_enabled()) { include __DIR__ . '/v2/sidebar.php'; return; }
/**
 * Spread AI — Client Sidebar
 * Variables expected: $active (one of: dashboard, brand, create, history, credits, profile)
 */

$active = $active ?? '';
$user = current_user();
$brand = user_brand();
$balance = user_credits();
?>
<aside class="sidebar">
    <div class="brand">
        <?= function_exists('site_logo_html') ? site_logo_html() : '<div class="brand-mark">S</div>' ?>
        <div>
            <div class="brand-name"><?= e(function_exists('site_name') ? site_name() : APP_NAME) ?></div>
            <div class="brand-sub"><?= e(function_exists('site_tagline') ? site_tagline() : APP_TAGLINE) ?></div>
        </div>
    </div>

    <div class="nav">
        <div class="nav-section">القائمة الرئيسية</div>
        <a href="<?= url('dashboard.php') ?>" class="<?= $active === 'dashboard' ? 'active' : '' ?>">
            <span class="ico">◉</span> الرئيسية
        </a>
        <?php if (menu_visible('create-content')): ?>
        <?php if (function_exists('ui_v2_enabled') && ui_v2_enabled() && get_setting('campaigns_enabled', '1') === '1'): ?>
        <a href="<?= url('campaigns.php') ?>" class="<?= $active === 'campaigns' ? 'active' : '' ?>">
            <span class="ico">📣</span> حملاتي
        </a>
        <?php endif; ?>
        <a href="<?= url('create-content.php') ?>" class="<?= $active === 'create' ? 'active' : '' ?>">
            <span class="ico">✎</span> إنشاء منشور
        </a>
        <?php endif; ?>
        <?php if (menu_visible('content-plan')): ?>
        <a href="<?= url('content-plan.php') ?>" class="<?= $active === 'plan' ? 'active' : '' ?>">
            <span class="ico">🗓</span> خطة المحتوى
        </a>
        <?php endif; ?>
        <?php if (menu_visible('design-studio')): ?>
        <a href="<?= url('design-studio.php') ?>" class="<?= $active === 'design-studio' ? 'active' : '' ?>">
            <span class="ico">✨</span> استوديو التصميم
        </a>
        <?php endif; ?>
        <?php if (menu_visible('brand-agent')): ?>
        <a href="<?= url('brand-agent.php') ?>" class="<?= $active === 'brand-agent' ? 'active' : '' ?>">
            <span class="ico">🤖</span> مساعد الهوية
        </a>
        <?php endif; ?>
        <?php if (menu_visible('studio')): ?>
        <a href="<?= url('studio.php') ?>" class="<?= $active === 'studio' ? 'active' : '' ?>">
            <span class="ico">🎨</span> معرض الإلهام
        </a>
        <?php endif; ?>
        <?php if (menu_visible('content-history')): ?>
        <a href="<?= url('content-history.php') ?>" class="<?= $active === 'history' ? 'active' : '' ?>">
            <span class="ico">▤</span> المحتوى السابق
        </a>
        <?php endif; ?>
        <?php if (menu_visible('brand-profile')): ?>
        <?php if (function_exists('ui_v2_enabled') && ui_v2_enabled()): ?>
        <a href="<?= url('brand-brain.php') ?>" class="<?= in_array($active, ['brand-brain', 'brand'], true) ? 'active' : '' ?>">
            <span class="ico">🧠</span> Brand Brain · الهوية
        </a>
        <?php else: ?>
        <a href="<?= url('brand-profile.php') ?>" class="<?= $active === 'brand' ? 'active' : '' ?>">
            <span class="ico">◈</span> هوية البراند
        </a>
        <?php endif; ?>
        <?php endif; ?>
        <?php if (menu_visible('sources')): ?>
        <a href="<?= url('sources.php') ?>" class="<?= $active === 'sources' ? 'active' : '' ?>">
            <span class="ico">📄</span> مستندات الهوية
        </a>
        <?php endif; ?>

        <div class="nav-section">الحساب</div>
        <?php if (menu_visible('referrals')): ?>
        <a href="<?= url('referrals.php') ?>" class="<?= $active === 'referrals' ? 'active' : '' ?>">
            <span class="ico">🎁</span> <?= (function_exists('credits_show_numbers') && !credits_show_numbers()) ? 'ادعُ واكسب' : 'اربح كريدت' ?>
        </a>
        <?php endif; ?>
        <?php if (menu_visible('credits')): ?>
        <a href="<?= url('credits.php') ?>" class="<?= $active === 'credits' ? 'active' : '' ?>">
            <span class="ico">◇</span> <span class="cr">الرصيد</span><span class="cr-alt">الباقة والاستهلاك</span> <span class="pill cr"><?= $balance ?></span><span class="pill cr-alt"><?= function_exists('plan_usage') ? (int) plan_usage((int) (current_user()['id'] ?? 0))['pct'] : 0 ?>%</span>
        </a>
        <?php endif; ?>
        <a href="<?= url('profile.php') ?>" class="<?= $active === 'profile' ? 'active' : '' ?>">
            <span class="ico">○</span> الملف الشخصي
        </a>
        <a href="<?= url('logout.php') ?>">
            <span class="ico">⏻</span> تسجيل خروج
        </a>
        <?php if (function_exists('feature_allows') || is_file(__DIR__ . '/../includes/social.php')): require_once __DIR__ . '/../includes/social.php';
            if (!empty($_SESSION['user_id']) && feature_allows((int) $_SESSION['user_id'])): ?>
        <?php if (menu_visible('social-accounts')): ?>
        <a href="<?= url('social-accounts.php') ?>" class="<?= $active === 'social-accounts' ? 'active' : '' ?>">
            <span class="ico">🔗</span> حساباتي المربوطة
        </a>
        <?php endif; ?>
        <?php endif; endif; ?>
        <?php if (menu_visible('help')): ?>
        <a href="<?= url('help.php') ?>" class="<?= $active === 'help' ? 'active' : '' ?>">
            <span class="ico">❓</span> دليل المنصة
        </a>
        <?php endif; ?>
    </div>
    <?php
    if (function_exists('ui_v2_enabled') && ui_v2_enabled() && !empty($user['id'])):
        require_once __DIR__ . '/../includes/brand-brain.php';
        $__bh = brand_health(brand_for_user((int) $user['id']));
    ?>
    <a href="<?= url('brand-brain.php') ?>" class="sb-brand">
        <span class="sb-brand-top"><b>🧠 Brand Brain</b><b><?= (int) $__bh['pct'] ?>%</b></span>
        <span class="bar"><span class="bar-fill" style="width:<?= (int) $__bh['pct'] ?>%"></span></span>
        <span class="sb-brand-msg"><?= $__bh['unlocked'] ? 'الهوية جاهزة ✓' : 'كمّل هويتك ←' ?></span>
    </a>
    <?php endif; ?>
</aside>

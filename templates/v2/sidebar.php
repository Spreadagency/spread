<?php
/**
 * Spread AI v2 — السايدبار الجديد (زي التصميم)
 * اللوجو + «CREATE · PLAN · PUBLISH» · زرار حملة جديدة · 7 أقسام · Brand Brain · خروج
 */
require_once __DIR__ . '/../../includes/ui-v2.php';

$active = $active ?? '';
$user = $user ?? current_user();
$__section = ui_section_of($active);
$__activeCampaigns = 0;
try {
    $__activeCampaigns = (int) (db_one('SELECT COUNT(*) n FROM campaigns WHERE user_id = ? AND status = "active"', [(int) $user['id']])['n'] ?? 0);
} catch (\Throwable $e) {}
?>
<aside class="v2-side" aria-label="القائمة الرئيسية">
    <a href="<?= url('dashboard.php') ?>" class="v2-logo">
        <img src="<?= url('assets/img/spread-mark-128.png') ?>" alt="" width="40" height="33">
        <span>
            <b>Spread <i>AI</i></b>
            <small>CREATE · PLAN · PUBLISH</small>
        </span>
    </a>

    <?php if (ui_campaigns_on()): ?>
    <a href="<?= url('campaigns.php?new=1') ?>" class="v2-cta">
        <?= ui_icon('plus', 20) ?>
        <span>حملة جديدة بالذكاء الاصطناعي</span>
    </a>
    <?php else: ?>
    <a href="<?= url('create-content.php') ?>" class="v2-cta">
        <?= ui_icon('plus', 20) ?>
        <span>منشور جديد بالذكاء الاصطناعي</span>
    </a>
    <?php endif; ?>

    <nav class="v2-nav">
        <?php foreach (ui_sections() as $key => [$label, $icon, $href, $keys, $subs]):
            // القسم بيختفي لو الأدمن مخبّي كل صفحاته
            if ($subs && !ui_subtabs($key)) continue;
            // رحلتك الأولى: محتاجة الحملات، وبتختفي لما تخلص (إلا وانت جواها)
            if ($key === 'journey') {
                if (!ui_campaigns_on()) continue;
                require_once __DIR__ . '/../../includes/journey.php';
                if ($__section !== 'journey' && journey_finished_cached((int) $user['id'])) continue;
            }
            if ($key === 'research' && !ui_research_on()) continue;
            // الحملات مقفولة من الأدمن: القسم يفضل بـ«خطة المحتوى» القديمة بس
            if ($key === 'campaigns' && !ui_campaigns_on()) {
                $subs = array_values(array_filter($subs, fn($t) => $t[2] !== 'campaigns'));
                if (!$subs) continue;
            }
            $first = $subs ? ($key === 'campaigns' && !ui_campaigns_on() ? $subs[0][1] : ui_subtabs($key)[0][1]) : $href;
        ?>
            <a href="<?= url($first) ?>" class="<?= $__section === $key ? 'on' : '' ?>" <?= $__section === $key ? 'aria-current="page"' : '' ?>>
                <?= ui_icon($icon, 20) ?>
                <span><?= e($label) ?></span>
                <?php if ($key === 'campaigns' && $__activeCampaigns > 0): ?>
                    <em class="v2-badge"><?= $__activeCampaigns ?></em>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>
    </nav>

    <div class="v2-side-foot">
        <?php
        if (!empty($user['id']) && is_file(__DIR__ . '/../../includes/brand-brain.php')):
            require_once __DIR__ . '/../../includes/brand-brain.php';
            $__bh = brand_health(brand_for_user((int) $user['id']));
        ?>
        <a href="<?= url('brand-brain.php') ?>" class="sb-brand v2-brand">
            <span class="v2-brand-ic"><?= ui_icon('brain', 18) ?></span>
            <span class="v2-brand-txt">
                <span class="sb-brand-top"><b>Brand Brain</b><b><?= (int) $__bh['pct'] ?>%</b></span>
                <span class="bar"><span class="bar-fill" style="width:<?= (int) $__bh['pct'] ?>%"></span></span>
                <span class="sb-brand-msg"><?= $__bh['unlocked'] ? 'الهوية جاهزة ✓' : 'كمّل هويتك ←' ?></span>
            </span>
        </a>
        <?php endif; ?>
        <a href="<?= url('logout.php') ?>" class="v2-logout"><?= ui_icon('logout', 18) ?> تسجيل الخروج</a>
    </div>
</aside>

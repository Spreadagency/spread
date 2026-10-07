<?php
/**
 * Spread AI — Admin Sidebar (المرحلة 8 · تصميم PlatformAdmin)
 * Variables: $active
 * كمان بيطبع عناصر الشريط العلوي (بحث · حالة النظام · الإشعارات · الحساب) في <template>
 * والـ JS بينقلها لـ .topbar بتاع كل صفحة — فمفيش صفحة محتاجة تتعدّل.
 */
require_once __DIR__ . '/../includes/admin-ui.php';
$active = $active ?? '';
$admin = current_admin();
$__nav = admin_nav_allowed();
$__siteName = function_exists('site_name') ? site_name() : APP_NAME;
$__logo = function_exists('site_setting') ? site_setting('site_logo', '') : '';
?>
<aside class="sidebar" aria-label="قائمة الأدمن">
    <div class="a2-brand">
        <span class="a2-logo"><?php if ($__logo !== ''): ?><img src="<?= e(url('storage/' . $__logo)) ?>" alt=""><?php else: ?><?= e(mb_substr($__siteName, 0, 1)) ?><?php endif; ?></span>
        <span class="a2-word"><b><?= e(preg_replace('/\s*AI$/i', '', $__siteName)) ?> <i>AI</i></b><small>PLATFORM ADMIN</small></span>
    </div>

    <nav class="nav">
        <?php foreach ($__nav as $__g => $__items): ?>
            <?php if ($__g !== ''): ?><div class="nav-section"><?= e($__g) ?></div><?php endif; ?>
            <?php foreach ($__items as $__it):
                $__on = in_array($active, $__it[6], true);
                $__cnt = !empty($__it[7]) ? admin_nav_count($__it[7]) : 0;
            ?>
            <a href="<?= url($__it[4]) ?>" class="<?= $__on ? 'active' : '' ?>" title="<?= e($__it[2]) ?>" data-en="<?= e($__it[2]) ?>" <?= $__on ? 'aria-current="page"' : '' ?>>
                <span class="ico"><?= admin_icon($__it[3]) ?></span><span class="lbl"><?= e($__it[1]) ?></span>
                <?php if ($__cnt > 0): ?><span class="a2-cnt"><?= $__cnt > 99 ? '99+' : $__cnt ?></span><span class="a2-dot"></span><?php endif; ?>
            </a>
            <?php endforeach; ?>
        <?php endforeach; ?>
    </nav>

    <div class="a2-sbfoot">
        <a href="<?= url('dashboard.php') ?>" target="_blank" rel="noopener"><?= admin_icon('ext', 18) ?><span>افتح المنصة</span></a>
        <button type="button" class="a2-col" onclick="a2Collapse()" aria-label="تصغير القائمة"><?= admin_icon('chev', 18) ?><span>تصغير القائمة</span></button>
        <?php @include_once __DIR__ . '/../includes/version.php'; ?>
        <a class="a2-ver" href="<?= url('admin/ui-diagnose.php') ?>" title="فحص التحديث"><span>نسخة <?= defined('SPREAD_BUILD') ? e(SPREAD_BUILD) : '—' ?><?= defined('SPREAD_BUILD_DATE') ? ' · ' . e(SPREAD_BUILD_DATE) : '' ?></span></a>
    </div>
</aside>

<?php
$__alerts = admin_alerts_open(6);
$__alertsN = admin_alerts_open_count();
[$__sysLbl, $__sysLvl] = admin_system_status();
$__name = (string) ($admin['name'] ?? $admin['email'] ?? 'Admin');
$__alertIco = ['critical' => '🚨', 'warn' => '⚠️', 'info' => 'ℹ️'];
?>
<?php
// تنبيه أعلى كل الصفحات: ترحيلات مستنية (التعديلات مش هتشتغل من غيرها)
$__updPending = [];
if (function_exists('admin_can') && admin_can('site_settings') && is_file(__DIR__ . '/../includes/migrations.php')) {
    // كاش دقيقة في الجلسة — علشان مانحسبش بصمة ملفات الترحيلات مع كل صفحة
    $__mc = $_SESSION['a2_mig_pending'] ?? null;
    if (is_array($__mc) && time() - (int) ($__mc['t'] ?? 0) < 60) {
        $__updPending = (array) $__mc['p'];
    } else {
        try {
            require_once __DIR__ . '/../includes/migrations.php';
            foreach (migrations_status() as $__m) if ($__m['state'] === 'pending') $__updPending[] = $__m['file'];
            $_SESSION['a2_mig_pending'] = ['t' => time(), 'p' => $__updPending];
        } catch (\Throwable $e) {}
    }
}
?>
<?php if ($__updPending && ($active ?? '') !== 'ui-diagnose'): ?>
<template id="a2-upd"><div class="a2-banner" role="alert">
    <span>⏳ فيه <b><?= count($__updPending) ?></b> ترحيل مستني يتشغّل — التعديلات الجديدة مش هتظهر للعملاء من غيره <span class="a2-muted" dir="ltr" style="font-size:12px">(<?= e(implode(' · ', array_slice($__updPending, 0, 3))) ?>)</span></span>
    <a class="btn sm" href="<?= url('admin/ui-diagnose.php') ?>">افتح «فحص التحديث»</a>
</div></template>
<?php endif; ?>
<template id="a2-topx">
    <div class="a2-search" role="search">
        <?= admin_icon('search', 18) ?>
        <input type="search" id="a2-q" placeholder="ابحث: قسم، عميل، إيميل، موبايل…" aria-label="بحث في اللوحة" autocomplete="off">
        <div class="a2-pop" id="a2-qpop" hidden></div>
    </div>
    <span class="a2-grow"></span>
    <span class="a2-sys <?= $__sysLvl === 'ok' ? '' : e($__sysLvl) ?>" title="حالة المزودين والتنبيهات"><i></i><?= e($__sysLbl) ?></span>
    <div class="a2-bell">
        <button type="button" class="a2-ib" id="a2-bellbtn" aria-label="الإشعارات" aria-expanded="false" aria-haspopup="true">
            <?= admin_icon('bell', 20) ?><?php if ($__alertsN > 0): ?><span class="a2-badge"><?= $__alertsN > 9 ? '9+' : $__alertsN ?></span><?php endif; ?>
        </button>
        <div class="a2-pop" id="a2-bellpop" hidden role="menu">
            <div class="a2-pop-h">الإشعارات</div>
            <?php if (!$__alerts): ?>
                <div class="a2-empty">مفيش تنبيهات مفتوحة 🎉</div>
            <?php else: foreach ($__alerts as $__a): ?>
                <a class="a2-al <?= e($__a['level']) ?>" href="<?= url($__a['link'] ?: 'admin/alerts.php') ?>">
                    <span class="a2-al-i"><?= $__alertIco[$__a['level']] ?? 'ℹ️' ?></span>
                    <span><b><?= e($__a['title']) ?></b><small><?= e(time_ago($__a['last_at'])) ?><?= (int) $__a['hits'] > 1 ? ' · ' . (int) $__a['hits'] . ' مرات' : '' ?></small></span>
                </a>
            <?php endforeach; endif; ?>
            <div class="a2-pop-foot">
                <a href="<?= url('admin/alerts.php') ?>">كل التنبيهات</a>
                <?php if ($__alertsN > 0 && admin_can('view_costs')): ?>
                <form method="post" action="<?= url('admin/alerts.php') ?>" style="margin:0">
                    <?= csrf_field() ?><input type="hidden" name="action" value="read_all"><input type="hidden" name="back" value="<?= e($_SERVER['REQUEST_URI'] ?? '') ?>">
                    <button type="submit">تعليم الكل كمقروء</button>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="a2-me">
        <button type="button" class="a2-ib" id="a2-mebtn" aria-label="حسابك" aria-expanded="false" style="width:auto;padding:0">
            <span class="a2-av"><?= e(mb_strtoupper(mb_substr($__name, 0, 1))) ?></span>
        </button>
        <div class="a2-pop" id="a2-mepop" hidden role="menu">
            <div class="a2-who"><b><?= e($__name) ?></b><small><?= e(admin_role_label()) ?></small></div>
            <a href="<?= url('dashboard.php') ?>" target="_blank" rel="noopener"><?= admin_icon('ext', 16) ?> افتح المنصة</a>
            <a href="<?= url('admin/logout.php') ?>"><?= admin_icon('out', 16) ?> تسجيل خروج</a>
        </div>
    </div>
</template>
<script type="application/json" id="a2-navdata"><?php
    $__flat = [];
    foreach ($__nav as $__g => $__items) foreach ($__items as $__it) $__flat[] = ['t' => $__it[1], 'en' => $__it[2], 'u' => url($__it[4])];
    // 8-ج: الإعدادات نفسها في البحث العام (اسم الإعداد ← تبويبه في مركز الإعدادات)
    $__sets = [];
    if (function_exists('settings_registry') && admin_can('site_settings')) {
        foreach (settings_registry() as $__tk => $__t) foreach ($__t['sections'] as $__s) foreach ($__s['fields'] as $__f) {
            if (($__f['type'] ?? '') === 'info') continue;
            $__sets[] = ['t' => $__f['label'], 'k' => $__f['key'], 'g' => $__t['label'], 'u' => url('admin/settings.php?tab=' . $__tk) . '#f-' . $__f['key']];
        }
    }
    echo json_encode(['nav' => $__flat, 'sets' => $__sets, 'users' => admin_can('view_users') ? url('admin/users.php') : null], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
?></script>

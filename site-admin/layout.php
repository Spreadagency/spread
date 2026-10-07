<?php
/**
 * قالب لوحة تحكم الموقع — Website OS
 *   سايدبار بالأقسام (قابل للتصغير · منيو جانبية على الموبايل) · توب بار (بحث · معاينة · الموقع) · المحتوى
 *   كل الروابط القديمة (dashboard.php · slides.php · …) زي ما هي.
 */
require_once __DIR__ . '/auth.php';
sa_require();
$__me = sa_admin();

/** القائمة: [المجموعة => [[ملف, أيقونة, الاسم, English, صلاحية], …]] */
function sa_nav(): array
{
    return [
        '' => [
            ['dashboard.php', 'home', 'لوحة التحكم', 'Dashboard', ''],
        ],
        'Website' => [
            ['homepage.php', 'layout', 'الصفحة الرئيسية', 'Homepage', 'homepage'],
            ['slides.php', 'sparkle', 'Hero والسلايدر', 'Hero & Slider', 'homepage'],
            ['sections.php', 'puzzle', 'أقسام المحتوى', 'Sections', 'homepage'],
            ['pages.php', 'file', 'الصفحات', 'Pages', 'pages'],
            ['settings.php', 'compass', 'القوائم والهيدر', 'Navigation', 'navigation'],
            ['design-system.php', 'palette', 'الهوية البصرية', 'Design System', 'design'],
            ['seo.php', 'search', 'SEO', 'SEO Manager', 'seo'],
        ],
        'Content' => [
            ['solutions.php', 'star', 'المميزات والحلول', 'Features', 'content'],
            ['problems.php', 'alert', 'المشاكل', 'Problems', 'content'],
            ['steps.php', 'list', 'خطوات العمل', 'How it works', 'content'],
            ['services.php', 'wand', 'الخدمات', 'Services', 'content'],
            ['gallery.php', 'image', 'التصميمات', 'Designs', 'designs'],
            ['videos.php', 'video', 'الفيديوهات', 'Videos', 'videos'],
            ['brands.php', 'tag', 'البراندات', 'Brands', 'content'],
            ['testimonials.php', 'quote', 'آراء العملاء', 'Testimonials', 'testimonials'],
            ['faq.php', 'help', 'الأسئلة الشائعة', 'FAQ', 'faq'],
        ],
        'Conversion' => [
            ['create-post.php', 'rocket', 'التجربة التفاعلية', 'Create Post', 'create_post'],
            ['promos.php', 'gift', 'العروض', 'Offers', 'offers'],
            ['packages.php', 'card', 'الباقات والأسعار', 'Pricing', 'pricing'],
            ['leads.php', 'users', 'العملاء المحتملين', 'Leads', 'leads'],
            ['analytics.php', 'chart', 'التحليلات', 'Analytics', 'analytics'],
        ],
        'Media' => [
            ['media.php', 'folder', 'مكتبة الوسائط', 'Media Library', 'media'],
        ],
        'System' => [
            ['users.php', 'shield', 'المستخدمين والصلاحيات', 'Users & Roles', 'users'],
            ['activity.php', 'list', 'سجل النشاط', 'Activity Log', 'activity'],
        ],
    ];
}

$__cur = basename($_SERVER['SCRIPT_NAME']);
$__curItem = null;
foreach (sa_nav() as $__items) foreach ($__items as $__it) if ($__it[0] === $__cur) $__curItem = $__it;
$__v = fn(string $f) => @filemtime(dirname(__DIR__) . '/site-assets/' . $f) ?: 1;
$__role = sa_roles()[sa_role($__me)][0] ?? '';
$__lastAct = s_one('SELECT created_at FROM site_activity_log WHERE action NOT IN ("login","logout") ORDER BY id DESC LIMIT 1');
?><!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#FFFFFF">
<meta name="csrf" content="<?= e(s_csrf()) ?>">
<title><?= e($__t ?? 'لوحة الموقع') ?> — Spread AI · Website OS</title>
<link rel="icon" href="<?= e(s_url('site-assets/img/spread-mark.png')) ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&family=Readex+Pro:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(s_url('site-assets/css/site-admin.css')) ?>?v=<?= $__v('css/site-admin.css') ?>">
<script>try{if(localStorage.getItem('sa-collapsed')==='1')document.documentElement.classList.add('ad-collapsed')}catch(e){}</script>
<script src="<?= e(s_url('site-assets/js/admin.js')) ?>?v=<?= $__v('js/admin.js') ?>" defer></script>
<script src="<?= e(s_url('site-assets/js/site-admin.js')) ?>?v=<?= $__v('js/site-admin.js') ?>" defer></script>
</head>
<body class="ad">
<a href="#ad-main" class="ad-skip">تخطّي للمحتوى</a>
<div class="ad-shell">
  <aside class="ad-side" id="ad-side" aria-label="القائمة الرئيسية">
    <div class="ad-brand">
      <a href="dashboard.php" class="ad-logo" aria-label="Spread AI — Website OS">
        <img src="<?= e(s_url('site-assets/img/spread-mark.png')) ?>" alt="" width="36" height="30">
        <span class="ad-logo-t" dir="ltr"><b>Spread <i>AI</i></b><small>WEBSITE OS</small></span>
      </a>
      <button type="button" class="ad-ib ad-only-m" data-menu-close aria-label="إغلاق القائمة"><?= sa_icon('x', 20) ?></button>
    </div>
    <div class="ad-navsearch ad-only-m"><span><?= sa_icon('search', 16) ?></span><input type="search" placeholder="ابحث عن قسم..." data-nav-filter aria-label="ابحث عن قسم"></div>
    <nav class="ad-nav-wrap ad-scroll">
      <?php foreach (sa_nav() as $group => $items):
        $items = array_values(array_filter($items, fn($it) => $it[4] === '' || sa_can($it[4])));
        if (!$items) continue; ?>
        <?php if ($group !== ''): ?><div class="ad-grp" data-grp><?= e($group) ?></div><?php endif; ?>
        <?php foreach ($items as [$file, $ic, $label, $en, $perm]): $on = $file === $__cur; ?>
          <a href="<?= e($file) ?>" class="ad-nav<?= $on ? ' on' : '' ?>"<?= $on ? ' aria-current="page"' : '' ?> data-nav="<?= e($label . ' ' . $en) ?>" title="<?= e($label) ?>">
            <span class="ad-nav-ic"><?= sa_icon($ic, 19) ?></span><span class="ad-nav-l"><?= e($label) ?></span><span class="ad-nav-en ad-only-m" dir="ltr"><?= e($en) ?></span>
          </a>
        <?php endforeach; ?>
      <?php endforeach; ?>
    </nav>
    <div class="ad-side-foot">
      <a href="logout.php" class="ad-nav" title="خروج"><span class="ad-nav-ic"><?= sa_icon('logout', 19) ?></span><span class="ad-nav-l">تسجيل الخروج</span></a>
      <button type="button" class="ad-collapse ad-only-d" data-collapse aria-label="تصغير القائمة"><?= sa_icon('chev-r', 18) ?><span>تصغير القائمة</span></button>
    </div>
  </aside>
  <div class="ad-side-bg" data-menu-close></div>

  <div class="ad-main-col">
    <header class="ad-top">
      <button type="button" class="ad-ib ad-only-m" data-menu-open aria-label="فتح القائمة" aria-controls="ad-side"><?= sa_icon('menu', 22) ?></button>
      <div class="ad-top-title ad-only-m"><b>Spread AI Admin</b><small><?= e($__curItem[2] ?? ($__t ?? 'لوحة التحكم')) ?></small></div>
      <div class="ad-topsearch ad-only-d" role="search">
        <span><?= sa_icon('search', 17) ?></span>
        <input type="search" placeholder="ابحث عن أي قسم... (العروض، Pricing، SEO)" data-jump list="ad-jump-list" aria-label="ابحث عن أي قسم">
        <datalist id="ad-jump-list">
          <?php foreach (sa_nav() as $items) foreach ($items as $it) if ($it[4] === '' || sa_can($it[4])): ?><option value="<?= e($it[2]) ?>" data-href="<?= e($it[0]) ?>"><?= e($it[3]) ?></option><?php endif; ?>
        </datalist>
      </div>
      <span class="ad-last ad-only-d"><?= $__lastAct ? 'آخر تعديل: ' . e(sa_ago($__lastAct['created_at'])) : 'لسه مفيش تعديلات' ?></span>
      <span class="ad-sp"></span>
      <a class="ad-btn ad-sec ad-only-d" href="<?= e(s_url('index.php')) ?>" target="_blank" rel="noopener"><?= sa_icon('eye', 17) ?><span>معاينة الموقع</span></a>
      <a class="ad-ib ad-only-m" href="<?= e(s_url('index.php')) ?>" target="_blank" rel="noopener" aria-label="معاينة الموقع"><?= sa_icon('eye', 20) ?></a>
      <div class="ad-me" data-pop>
        <button type="button" class="ad-avatar" aria-haspopup="true" aria-expanded="false" data-pop-btn title="<?= e($__me['name']) ?>"><?= e(mb_strtoupper(mb_substr((string) $__me['name'], 0, 1))) ?></button>
        <div class="ad-popm" role="menu" hidden>
          <div class="ad-popm-h"><b><?= e($__me['name']) ?></b><small dir="ltr"><?= e($__me['email']) ?></small><?= sa_chip($__role, 'info') ?></div>
          <?php if (sa_can('activity')): ?><a href="activity.php" role="menuitem"><?= sa_icon('list', 17) ?>سجل النشاط</a><?php endif; ?>
          <a href="<?= e(s_url('index.php')) ?>" target="_blank" rel="noopener" role="menuitem"><?= sa_icon('globe', 17) ?>فتح الموقع</a>
          <a href="logout.php" role="menuitem" class="danger"><?= sa_icon('logout', 17) ?>تسجيل الخروج</a>
        </div>
      </div>
    </header>

    <main class="ad-main" id="ad-main">
      <?php if (!empty($_SESSION['site_flash'])): $__f = $_SESSION['site_flash']; unset($_SESSION['site_flash']); ?>
        <div class="ad-toast t-<?= e($__f['t']) ?>" role="status" data-toast><?= sa_icon($__f['t'] === 'success' ? 'check' : ($__f['t'] === 'danger' ? 'alert' : 'info'), 18, 2.2) ?><span><?= e($__f['m']) ?></span><button type="button" class="ad-ib" data-toast-close aria-label="إغلاق"><?= sa_icon('x', 16) ?></button></div>
      <?php endif; ?>

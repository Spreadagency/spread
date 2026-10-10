<?php
/**
 * Spread AI — الصفحة الرئيسية للموقع الخارجي (التصميم الجديد)
 *   • كل الأقسام من قاعدة بيانات الموقع (site-admin): الترتيب والإظهار من «الصفحة الرئيسية» في لوحة الموقع
 *   • كل قسم في ملف لوحده (site/sections/*.php) — نفس الأقسام بتتعرض في الصفحات الداخلية وبلوكات الـ Page Builder
 *   • «اصنع منشورك الآن» تجربة حقيقية بالذكاء الاصطناعي (public/ajax/trial.php) — وبتتحفظ في الحساب بعد التسجيل
 *     ومرحلة التصميم بتتحقق من الاشتراك الأول (اللي مش مشترك بيشوف شاشة الاشتراك من غير ما يتولّد تصميم)
 *   • الأسعار من باقات المنصة (نظام الدفع الجديد) — «اشترك» ← صفحة الدفع
 */
require_once __DIR__ . '/site/home.php';

s_home_upgrade();
s_track_view('/');
$ctx = s_home_ctx();
extract($ctx, EXTR_SKIP);

$__title = s_setting('seo_home_title') ?: $siteName . ' — ' . (s_setting('tagline') ?: 'فريق تسويق كامل بالذكاء الاصطناعي');
$jsV  = @filemtime(__DIR__ . '/site-assets/js/home-v2.js') ?: 1;
$meta = [
    'title' => $__title,
    'description' => s_setting('seo_home_description') ?: s_setting('tagline', 'فريق تسويق كامل بالذكاء الاصطناعي — من الفكرة للمحتوى للتصميم للنشر.'),
    'og_image' => s_setting('seo_og_image') ?: $img('bot-wave.webp'),
    'canonical' => rtrim(SITE_URL, '/') . '/',
];
$preload = '<link rel="preload" as="image" href="' . e($img('bot-' . $slides[0]['bot'] . '.webp')) . '">';

// القائمة: نفس روابط الرئيسية (أقسام الصفحة) — ولو فيه صفحات داخلية في القائمة بتظهر في منيو الموبايل
$nav = [['الرئيسية', '#s-hero']];
if (in_array('services', $order, true)) $nav[] = ['الخدمات', '#s-services'];
if (in_array('gallery', $order, true) && $gallery) $nav[] = ['التصميمات', '#s-designs'];
if (in_array('steps', $order, true) && $steps) $nav[] = ['إزاي بيشتغل', '#s-steps'];
if (in_array('pricing', $order, true) && $packages) $nav[] = ['الأسعار', '#s-pricing'];
if ($trialOn && in_array('trial', $order, true)) $nav[] = ['اصنع منشورك', '#s-create', true];
$mnav = [['الرئيسية', '#s-hero']];
if ($trialOn) $mnav[] = ['اصنع منشورك', '#s-create'];
$mnav[] = ['الخدمات', '#s-services'];
if ($gallery) $mnav[] = ['التصميمات', '#s-designs'];
$mnav[] = ['إزاي بيشتغل', '#s-steps'];
if ($packages) $mnav[] = ['الأسعار', '#s-pricing'];
foreach (s_menu_pages() as $pg) if (!in_array($pg['slug'], ['create-post', 'pricing', 'designs', 'services'], true)) $mnav[] = [$pg['title'], s_page_url($pg['slug'])];
$mnav[] = ['حساب جديد', $regUrl];
$homeHref = '#s-hero';
$ctaHref = $trialOn ? '#s-create' : $regUrl;

include __DIR__ . '/site/parts/site-head.php';
?>
<body class="hv2">
<a href="#main" class="sr-only">تخطّي للمحتوى</a>

<!-- ═══ الهيدر ═══ -->
<?php include __DIR__ . '/site/parts/site-header.php'; ?>

<main id="main">
<?php foreach ($order as $key) echo s_render_section($key, $ctx); ?>
</main>

<!-- ═══ الفوتر ═══ -->
<?php include __DIR__ . '/site/parts/site-footer.php'; ?>
<?php include __DIR__ . '/site/parts/admin-bar.php'; ?>

<!-- ═══ الروبوت المرافق ═══ -->
<div class="h-comp" id="h-comp" aria-hidden="true">
  <button type="button" id="h-comp-btn" aria-label="روبوت Spread AI — اصنع منشورك" tabindex="-1"><span class="ws-bot" id="h-comp-bot"><img src="<?= e($img('bot-idle.webp')) ?>" alt="" loading="lazy" width="104" height="161"></span></button>
  <span class="h-say ws-glass-l" id="h-say" role="status"></span>
</div>

<script>
window.SPREAD_HOME = <?= json_encode(s_trial_js_config($regUrl, $loginUrl), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>
<script src="<?= e(s_url('site-assets/js/home-v2.js')) ?>?v=<?= $jsV ?>" defer></script>
</body>
</html>

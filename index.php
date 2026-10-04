<?php
/**
 * Spread AI — الصفحة الرئيسية للموقع الخارجي (التصميم الجديد)
 *   • كل الأقسام من قاعدة بيانات الموقع (site-admin): الترتيب والإظهار من «الأقسام والعناوين»
 *   • «اصنع منشورك الآن» تجربة حقيقية بالذكاء الاصطناعي (public/ajax/trial.php) — وبتتحفظ في الحساب بعد التسجيل
 *   • الأسعار من باقات المنصة (نظام الدفع الجديد) — «اشترك» ← صفحة الدفع
 */
require_once __DIR__ . '/site/home.php';

s_home_upgrade();
$D = s_home_defaults();

$loginUrl = s_setting('platform_login_url', PLATFORM_LOGIN);
$regUrl   = s_setting('platform_register_url', PLATFORM_REGISTER);
$siteName = s_setting('site_name', 'Spread AI');
$logo     = s_setting('logo_path') !== '' ? SITE_UPLOAD_URL . '/' . s_setting('logo_path') : s_url('site-assets/img/spread-mark.png');
$img      = fn(string $f) => s_url('site-assets/img/' . $f);

/* ─── البيانات ─── */
$slides = s_all('SELECT * FROM site_slides WHERE is_active = 1 ORDER BY sort_order, id LIMIT 5');
if (!$slides) $slides = $D['slides'];
$bots = ['wave', 'think', 'sit'];
foreach ($slides as $i => &$sl) {
    $sl['bot'] = in_array($sl['bot'] ?? '', $bots, true) ? $sl['bot'] : $bots[$i % 3];
    $sl['tag'] = trim((string) ($sl['tag'] ?? '')) ?: ($D['slides'][$i % 3]['tag']);
}
unset($sl);
$marquee  = array_values(array_filter(array_map('trim', preg_split('/[\r\n•]+/u', s_setting('ribbon_text'))))) ?: $D['marquee'];
$brands   = s_all('SELECT * FROM site_brands WHERE is_active = 1 ORDER BY sort_order, id');
$promos   = s_all('SELECT * FROM site_promos WHERE is_active = 1 AND (starts_at IS NULL OR starts_at <= CURDATE())
                   AND (ends_at IS NULL OR ends_at >= CURDATE()) ORDER BY sort_order, id LIMIT 4');
$problems = s_all('SELECT * FROM site_problems WHERE is_active = 1 ORDER BY sort_order, id');
$solutions= s_all('SELECT * FROM site_solutions WHERE is_active = 1 ORDER BY sort_order, id');
$steps    = s_all('SELECT * FROM site_steps WHERE is_active = 1 ORDER BY sort_order, id');
$services = s_all('SELECT * FROM site_services WHERE is_active = 1 ORDER BY sort_order, id');
$gallery  = s_all('SELECT * FROM site_gallery WHERE is_active = 1 ORDER BY sort_order, id LIMIT 24');
$packages = s_home_packages();
$trialOn  = s_platform_pdo() === null || s_platform_setting('trial_enabled', '1') === '1';

// الأقسام: الترتيب والإظهار من لوحة الموقع
$order = [];
foreach (s_all('SELECT section_key, is_visible, sort_order FROM site_sections ORDER BY sort_order, id') as $r) {
    if ((int) $r['is_visible'] === 1) $order[] = $r['section_key'];
}
if (!$order) $order = s_home_order();
foreach (s_home_order() as $k) if (!in_array($k, $order, true) && !s_one('SELECT id FROM site_sections WHERE section_key = ?', [$k])) $order[] = $k;
$sec = function (string $k, int $i) use ($D): string {
    $s = s_section($k);
    return [(string) ($s['title'] ?: ($D['sections'][$k][0] ?? '')), (string) ($s['subtitle'] ?? ($D['sections'][$k][1] ?? ''))][$i];
};
$secHead = function (string $k, string $chip, string $icon = 'sparkle') use ($sec): string {
    $sub = $sec($k, 1);
    return '<div class="h-sh rv"><span class="ws-chip">' . s_icon($icon, 15) . e($chip) . '</span><h2 class="ws-h2">' . e($sec($k, 0)) . '</h2>'
        . ($sub !== '' ? '<p>' . e($sub) . '</p>' : '') . '</div>';
};
$appUrl = fn(string $u) => preg_match('~^(https?:)?//|^/|^#|^mailto:|^tel:~', $u) ? $u : s_url($u);

// تصنيفات المعرض (حسب «التصنيف» في لوحة الموقع)
$dzCats = ['all' => 'الكل', 'post' => 'بوستات', 'story' => 'ستوريز', 'portrait' => 'بورتريه', 'ba' => 'قبل / بعد', 'logo' => 'لوجوهات'];
$dzMap = function (?string $c): string {
    $c = mb_strtolower(trim((string) $c));
    if ($c === '') return 'post';
    foreach (['story' => ['story', 'ستوري', 'ستوريز', '9:16'], 'portrait' => ['portrait', 'بورتريه', '4:5'], 'logo' => ['logo', 'لوجو', 'لوجوهات'],
              'ba' => ['ba', 'before', 'قبل', 'بعد'], 'post' => ['post', 'بوست', 'بوستات', '1:1']] as $k => $keys) {
        foreach ($keys as $w) if (mb_strpos($c, $w) !== false) return $k;
    }
    return 'post';
};
$gItems = array_map(fn($g) => $g + ['cat' => $dzMap($g['category'] ?? '')], $gallery);
$baPairs = array_values(array_filter($gItems, fn($g) => $g['cat'] === 'ba'));
$usedCats = array_unique(array_column($gItems, 'cat'));
$dzCats = array_filter($dzCats, fn($l, $k) => $k === 'all' || in_array($k, $usedCats, true), ARRAY_FILTER_USE_BOTH);
if (count($baPairs) < 2) unset($dzCats['ba']);

$hasYearly = count(array_filter($packages, fn($p) => $p['yearly'])) > 0;
$hasMonthly = count(array_filter($packages, fn($p) => !$p['yearly'])) > 0;
$hasToggle = $hasYearly && $hasMonthly;

$__title = $siteName . ' — ' . (s_setting('tagline') ?: 'فريق تسويق كامل بالذكاء الاصطناعي');
$cssV = @filemtime(__DIR__ . '/site-assets/css/home-v2.css') ?: 1;
$jsV  = @filemtime(__DIR__ . '/site-assets/js/home-v2.js') ?: 1;

// أيقونات الخدمات/الخطوات الافتراضية لو العنصر من غير أيقونة
$svcIcons = ['brain', 'search', 'bulb', 'image', 'megaphone', 'calendar'];
?><!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= e($__title) ?></title>
<meta name="description" content="<?= e(s_setting('tagline', 'فريق تسويق كامل بالذكاء الاصطناعي — من الفكرة للمحتوى للتصميم للنشر.')) ?>">
<meta name="theme-color" content="#0B1526">
<meta property="og:title" content="<?= e($__title) ?>">
<meta property="og:image" content="<?= e($img('bot-wave.webp')) ?>">
<link rel="icon" href="<?= e($logo) ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&family=Readex+Pro:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="preload" as="image" href="<?= e($img('bot-' . $slides[0]['bot'] . '.webp')) ?>">
<link rel="stylesheet" href="<?= e(s_url('site-assets/css/home-v2.css')) ?>?v=<?= $cssV ?>">
</head>
<body class="hv2">
<a href="#main" class="sr-only">تخطّي للمحتوى</a>

<!-- ═══ الهيدر ═══ -->
<header class="h-head">
  <div class="h-bar ws-glass-l">
    <a href="#s-hero" class="h-logo" aria-label="<?= e($siteName) ?> — الرئيسية">
      <img src="<?= e($logo) ?>" alt="" width="38" height="32">
      <span class="h-logo-t" dir="ltr"><span>Spread <span class="ai-word">AI</span></span></span>
    </a>
    <nav class="h-nav" aria-label="القائمة الرئيسية">
      <a class="ws-nav" href="#s-hero">الرئيسية</a>
      <?php if (in_array('services', $order, true)): ?><a class="ws-nav" href="#s-services">الخدمات</a><?php endif; ?>
      <?php if (in_array('gallery', $order, true) && $gallery): ?><a class="ws-nav" href="#s-designs">التصميمات</a><?php endif; ?>
      <?php if (in_array('steps', $order, true) && $steps): ?><a class="ws-nav" href="#s-steps">إزاي بيشتغل</a><?php endif; ?>
      <?php if (in_array('pricing', $order, true) && $packages): ?><a class="ws-nav" href="#s-pricing">الأسعار</a><?php endif; ?>
      <?php if ($trialOn && in_array('trial', $order, true)): ?><a class="ws-nav hl" href="#s-create">اصنع منشورك</a><?php endif; ?>
    </nav>
    <div class="h-acts">
      <a href="<?= e($loginUrl) ?>" class="ws-nav">تسجيل الدخول</a>
      <a href="<?= e($trialOn ? '#s-create' : $regUrl) ?>" class="ws-btn ws-pri">ابدأ مجانًا</a>
      <button type="button" class="h-menu-btn" id="h-menu-btn" aria-label="القائمة" aria-expanded="false" aria-controls="h-mnav"><?= s_icon('menu', 20, 2) ?></button>
    </div>
  </div>
  <nav class="h-mnav ws-glass-l" id="h-mnav" aria-label="القائمة الرئيسية">
    <a class="ws-nav" href="#s-hero">الرئيسية</a>
    <?php if ($trialOn): ?><a class="ws-nav" href="#s-create">اصنع منشورك</a><?php endif; ?>
    <a class="ws-nav" href="#s-services">الخدمات</a>
    <?php if ($gallery): ?><a class="ws-nav" href="#s-designs">التصميمات</a><?php endif; ?>
    <a class="ws-nav" href="#s-steps">إزاي بيشتغل</a>
    <?php if ($packages): ?><a class="ws-nav" href="#s-pricing">الأسعار</a><?php endif; ?>
    <a class="ws-nav" href="<?= e($regUrl) ?>">حساب جديد</a>
  </nav>
</header>

<main id="main">
<?php foreach ($order as $key):
  switch ($key):

  /* ═══════════ الهيرو ═══════════ */
  case 'hero': ?>
  <section class="h-hero" id="s-hero" data-say="">
    <div class="h-dots" aria-hidden="true"></div>
    <div class="h-hero-in">
      <div class="h-copy">
        <div class="h-slides" aria-live="polite">
          <?php foreach ($slides as $i => $sl): ?>
            <div class="h-slide<?= $i === 0 ? ' on' : '' ?>" data-bot="<?= e($sl['bot']) ?>" aria-hidden="<?= $i === 0 ? 'false' : 'true' ?>"
                 data-c1="<?= e(($sl['cta_text'] ?? '') ?: 'اصنع منشورك الآن') ?>" data-c1u="<?= e(($sl['cta_url'] ?? '') ?: ($trialOn ? '#s-create' : $regUrl)) ?>"
                 data-c2="<?= e(($sl['cta2_text'] ?? '') ?: 'اكتشف المنصة') ?>">
              <span class="ws-chip ws-glass h-tag"><i class="sa-pulse"></i><?= e($sl['tag']) ?></span>
              <<?= $i === 0 ? 'h1' : 'h2' ?>><?= e($sl['title']) ?></<?= $i === 0 ? 'h1' : 'h2' ?>>
              <?php if (!empty($sl['subtitle'])): ?><p><?= e($sl['subtitle']) ?></p><?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
        <div class="h-ctas">
          <a class="ws-btn ws-pri" id="h-c1" href="<?= e(($slides[0]['cta_url'] ?? '') ?: ($trialOn ? '#s-create' : $regUrl)) ?>"><span><?= e(($slides[0]['cta_text'] ?? '') ?: 'اصنع منشورك الآن') ?></span><?= s_icon('arrow', 19, 2.2) ?></a>
          <a class="ws-btn ws-ghost-d" id="h-c2" href="#s-services"><?= e(($slides[0]['cta2_text'] ?? '') ?: 'اكتشف المنصة') ?></a>
        </div>
        <?php if (count($slides) > 1): ?>
        <div class="h-ctl">
          <button type="button" class="ws-glass h-arrow" data-slide="prev" aria-label="الشريحة السابقة"><?= s_icon('arrow-r', 18, 2) ?></button>
          <div class="h-dotsnav">
            <?php foreach ($slides as $i => $sl): ?><button type="button" data-go="<?= $i ?>" aria-label="الشريحة <?= $i + 1 ?>" aria-current="<?= $i === 0 ? 'true' : 'false' ?>"><span></span></button><?php endforeach; ?>
          </div>
          <button type="button" class="ws-glass h-arrow" data-slide="next" aria-label="الشريحة التالية"><?= s_icon('arrow', 18, 2) ?></button>
          <span class="h-no" dir="ltr"><span id="h-no">01</span> / <?= str_pad((string) count($slides), 2, '0', STR_PAD_LEFT) ?></span>
        </div>
        <?php endif; ?>
        <div class="h-counters" id="h-counters">
          <div class="ws-glass"><b data-to="4">4</b><span>أفكار في كل طلب</span></div>
          <div class="ws-glass"><b data-to="6">6</b><span>أنواع تصميم</span></div>
          <div class="ws-glass"><b data-to="<?= max(2, count($steps) ?: 7) ?>"><?= max(2, count($steps) ?: 7) ?></b><span>خطوات من الفكرة للنشر</span></div>
        </div>
        <span class="h-hint"><span class="ws-bob"><?= s_icon('mouse', 16, 1.6) ?></span>حرّك الماوس أو انزل — الروبوت بيتابعك</span>
      </div>

      <div class="h-stage" aria-hidden="true">
        <div class="g ws-glow"></div>
        <svg class="r1 ws-slow" viewBox="0 0 100 100"><defs><linearGradient id="h-rg" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#2EE3CC" stop-opacity=".7"/><stop offset=".5" stop-color="#0C87EF" stop-opacity=".1"/><stop offset="1" stop-color="#B7A6FF" stop-opacity=".6"/></linearGradient></defs><circle cx="50" cy="50" r="49" fill="none" stroke="url(#h-rg)" stroke-width=".35"/><circle cx="50" cy="1" r="1" fill="#2EE3CC"/></svg>
        <svg class="r2 ws-slow-r" viewBox="0 0 100 100"><circle cx="50" cy="50" r="49" fill="none" stroke="rgba(143,184,255,.28)" stroke-width=".45" stroke-dasharray="1.5 3"/><circle cx="99" cy="50" r="1.3" fill="#B7A6FF"/></svg>
        <div class="h-shadow" id="h-shadow"></div>
        <div class="h-bot ws-bot" id="h-bot">
          <?php foreach (array_unique(array_column($slides, 'bot')) as $b): ?>
            <img src="<?= e($img('bot-' . $b . '.webp')) ?>" alt="" data-b="<?= e($b) ?>" class="<?= $b === $slides[0]['bot'] ? 'on' : '' ?>" width="380" height="591" <?= $b === $slides[0]['bot'] ? 'fetchpriority="high"' : 'loading="lazy"' ?>>
          <?php endforeach; ?>
        </div>
        <div class="h-float f1 ws-glass ws-float"><i><?= s_icon('pen', 18) ?></i><span><b>كابشن جاهز</b><small>بصوت براندك</small></span></div>
        <div class="h-float f2 ws-glass ws-float"><i><?= s_icon('image', 18) ?></i><span><b>تصميم 1:1</b><small>بألوان هويتك</small></span></div>
        <div class="h-float f3 ws-glass ws-float"><i><?= s_icon('calendar', 18) ?></i><span><b>اتجدول للنشر</b><small>Instagram · Facebook</small></span></div>
      </div>
    </div>
  </section>
  <?php break;

  /* ═══════════ الشريط المتحرك ═══════════ */
  case 'ribbon': ?>
  <div class="h-marq" aria-label="خدمات <?= e($siteName) ?>">
    <div class="ws-marq">
      <?php for ($r = 0; $r < 2; $r++): foreach ($marquee as $m): ?>
        <span<?= $r ? ' aria-hidden="true"' : '' ?>><span><?= e($m) ?></span><svg width="16" height="16" viewBox="0 0 24 24" fill="#0B1526" aria-hidden="true"><path d="M12 2l2.2 7.8L22 12l-7.8 2.2L12 22l-2.2-7.8L2 12l7.8-2.2z"/></svg></span>
      <?php endforeach; endfor; ?>
    </div>
  </div>
  <?php break;

  /* ═══════════ اصنع منشورك الآن (تجربة حقيقية) ═══════════ */
  case 'trial': if (!$trialOn) break; ?>
  <section class="h-create" id="s-create" data-say="جرّب بنفسك — من غير حساب" data-look="1">
    <div class="h-wrap">
      <?= $secHead('trial', 'جرّبها دلوقتي — من غير حساب') ?>
      <div class="ws-card d-card rv rv-s" id="demo">
        <div class="d-grid">
          <div class="d-main">
            <div class="d-steps" role="list" aria-label="خطوات التجربة">
              <?php foreach (['بياناتك', '4 أفكار', 'المنشور', 'التصميم', 'احفظ'] as $i => $t): ?>
                <div role="listitem" data-s="<?= $i ?>" class="<?= $i === 0 ? 'cur' : '' ?>"><span class="n"><em><?= $i + 1 ?></em><?= s_icon('check', 15, 2.6) ?></span><span class="t"><?= e($t) ?></span><?php if ($i < 4): ?><span class="bar"></span><?php endif; ?></div>
              <?php endforeach; ?>
            </div>

            <!-- ① البيانات -->
            <form class="d-pane on" data-p="form" novalidate>
              <div class="d-h"><h3>عرّفنا على مشروعك</h3><span>3 معلومات بس — والباقي على Spread AI.</span></div>
              <div class="d-two">
                <div class="d-f"><label for="d-biz">اسم النشاط</label><input id="d-biz" class="ws-input" maxlength="200" placeholder="مثلاً: مطعم البيت" autocomplete="organization">
                  <span class="d-err" id="d-biz-err" role="alert" hidden>اكتب اسم النشاط علشان نبني عليه الأفكار</span></div>
                <div class="d-f"><label for="d-aud">مين جمهورك؟ <small>(اختياري)</small></label><input id="d-aud" class="ws-input" maxlength="300" placeholder="مثلاً: عائلات في المنصورة"></div>
              </div>
              <div class="d-f"><span class="d-lbl" id="d-fl">المجال</span>
                <div class="d-chips" role="group" aria-labelledby="d-fl">
                  <?php foreach (['مطعم أو كافيه', 'عيادة أو مركز طبي', 'متجر ملابس', 'أكاديمية أو كورسات', 'خدمات', 'مجال تاني'] as $i => $f): ?>
                    <button type="button" class="ws-pill<?= $i === 0 ? ' f-on' : '' ?>" data-field="<?= e($f) ?>" aria-pressed="<?= $i === 0 ? 'true' : 'false' ?>"><?= e($f) ?></button>
                  <?php endforeach; ?>
                </div>
                <input id="d-other" class="ws-input" maxlength="150" placeholder="اكتب مجالك (مثلاً: محل حلويات)" hidden>
              </div>
              <div class="d-f"><span class="d-lbl" id="d-gl">هدف المنشور</span>
                <div class="d-chips" role="group" aria-labelledby="d-gl">
                  <?php foreach (['زيادة المبيعات', 'تعريف بالبراند', 'عرض خاص', 'تفاعل أكتر'] as $i => $g): ?>
                    <button type="button" class="ws-pill<?= $i === 0 ? ' g-on' : '' ?>" data-goal="<?= e($g) ?>" aria-pressed="<?= $i === 0 ? 'true' : 'false' ?>"><?= e($g) ?></button>
                  <?php endforeach; ?>
                </div>
              </div>
              <p class="d-msg" data-msg hidden role="alert"></p>
              <button type="submit" class="ws-btn ws-pri" data-act="ideas" style="align-self:flex-start">
                <span class="idle">ولّد 4 أفكار</span><?= s_icon('sparkle', 18, 2) ?>
              </button>
            </form>

            <!-- ② الأفكار -->
            <div class="d-pane" data-p="ideas">
              <div class="d-h"><h3>4 أفكار لـ <span data-biz>مشروعك</span></h3><span>اختار الفكرة اللي تعجبك.</span></div>
              <div class="d-ideas" id="d-ideas"></div>
              <p class="d-msg" data-msg hidden role="alert"></p>
              <div class="d-row">
                <button type="button" class="ws-btn ws-pri" data-act="post" hidden>اكتب المنشور<?= s_icon('arrow', 18, 2.2) ?></button>
                <span class="d-pick-hint" data-pick-hint>اختار فكرة الأول ←</span>
                <button type="button" class="ws-btn ws-ghost" data-act="regen"><?= s_icon('shuffle', 17) ?>أفكار تانية</button>
                <button type="button" class="ws-link" data-go="form">تعديل البيانات</button>
              </div>
            </div>

            <!-- ③ المنشور -->
            <div class="d-pane" data-p="post">
              <div class="d-h"><h3>منشورك جاهز ✍️</h3><span>مكتوب بصوت <span data-biz>مشروعك</span> — والأقواس [ ] مكان تفاصيلك.</span></div>
              <div class="d-post"><span class="sa-chip" id="d-post-chip"></span><p id="d-post-text"></p><span class="tags" id="d-post-tags"></span></div>
              <div class="d-row">
                <button type="button" class="ws-btn ws-pri" data-go="design">صمّمه<?= s_icon('arrow', 18, 2.2) ?></button>
                <button type="button" class="ws-btn ws-ghost" data-act="copy">نسخ المنشور</button>
                <button type="button" class="ws-link" data-go="ideas">غيّر الفكرة</button>
              </div>
            </div>

            <!-- ④ التصميم -->
            <div class="d-pane" data-p="design">
              <div class="d-h"><h3>اختار شكل التصميم 🎨</h3><span>المعاينة بتتغير على طول.</span></div>
              <div class="d-tpls" role="group" aria-label="شكل التصميم">
                <button type="button" data-tpl="0" aria-pressed="true"><i style="background:linear-gradient(150deg,#E6FBF7 0%,#DCEBFF 100%)"></i><span>ناعم</span></button>
                <button type="button" data-tpl="1" aria-pressed="false"><i style="background:linear-gradient(150deg,#0B1526 0%,#12305A 100%)"></i><span>جريء</span></button>
                <button type="button" data-tpl="2" aria-pressed="false"><i style="background:#FFFFFF"></i><span>بسيط</span></button>
              </div>
              <div class="d-row">
                <button type="button" class="ws-btn ws-pri" data-go="save">احفظ المنشور<?= s_icon('check', 18, 2.4) ?></button>
                <button type="button" class="ws-link" data-go="post">رجوع للكابشن</button>
              </div>
            </div>

            <!-- ⑤ احفظ -->
            <div class="d-pane" data-p="save">
              <span class="d-done"><?= s_icon('check', 30, 2.6) ?></span>
              <h3>منشورك وهوية <span data-biz>مشروعك</span> جاهزين 🎉</h3>
              <p>سجّل علشان تحفظهم في حسابك — الهوية والمنشور بيتحفظوا من غير ما يتخصم أي Credits.</p>
              <div class="d-row">
                <a class="ws-btn ws-pri" id="d-reg" href="<?= e($regUrl) ?>">إنشاء حساب واحفظ<?= s_icon('arrow', 18, 2.2) ?></a>
                <button type="button" class="ws-btn ws-ghost" data-act="restart">جرّب منشور تاني</button>
              </div>
              <a href="<?= e($loginUrl) ?>" id="d-login" style="font-size:13.5px;font-weight:600">عندك حساب؟ سجّل دخول واحفظ ←</a>
            </div>
          </div>

          <!-- المعاينة الحيّة -->
          <div class="d-prev" aria-label="معاينة المنشور">
            <span class="d-live"><i class="sa-pulse"></i>معاينة حيّة</span>
            <div class="d-phone">
              <div class="d-ph-head"><span class="d-av" id="d-av">S</span><span><b data-biz>مشروعك</b><small>الآن</small></span></div>
              <div class="d-art" id="d-art">
                <div class="ph"><?= s_icon('image', 28, 1.5) ?>التصميم هيظهر هنا</div>
                <div class="sk"><span class="sa-shimmer"></span><span class="sa-shimmer"></span><span class="sa-shimmer"></span></div>
                <div class="ct"><i id="d-ac"></i><b id="d-art-t"></b><small id="d-art-s"><span data-biz>مشروعك</span> · [صورة المنتج]</small></div>
              </div>
              <div class="d-ph-foot">
                <span class="ic"><?= s_icon('heart', 20) . s_icon('chat', 20) . s_icon('send', 20) ?></span>
                <p class="sa-clamp2" id="d-cap"></p>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>
  <?php break;

  /* ═══════════ البراندات ═══════════ */
  case 'brands': if (!$brands) break; ?>
  <section class="h-brands" id="s-brands" data-say="براندات بتثق فينا" data-look="-1">
    <div class="h-wrap">
      <?= $secHead('brands', 'بيثقوا فينا') ?>
      <div class="h-logos rv">
        <div class="ws-marq slow">
          <?php $bl = count($brands) < 6 ? array_merge($brands, $brands, $brands) : $brands;
          for ($r = 0; $r < 2; $r++): foreach ($bl as $b): $src = s_img($b, 'logo_path', 'logo_url'); $tag = $b['link_url'] ? 'a' : 'div'; ?>
            <<?= $tag ?> class="h-logo-i"<?= $b['link_url'] ? ' href="' . e($b['link_url']) . '" target="_blank" rel="noopener"' : '' ?><?= $r ? ' aria-hidden="true" tabindex="-1"' : '' ?>>
              <?php if ($src): ?><img src="<?= e($src) ?>" alt="<?= e($b['name']) ?>" loading="lazy"><?php else: ?><?= s_icon('layers', 18, 1.6) ?><?= e($b['name']) ?><?php endif; ?>
            </<?= $tag ?>>
          <?php endforeach; endfor; ?>
        </div>
      </div>
    </div>
  </section>
  <?php break;

  /* ═══════════ العروض ═══════════ */
  case 'promos': ?>
  <section class="h-offers" id="s-offers" data-say="بص على العروض دي 👀" data-look="1">
    <div class="h-wrap">
      <?= $secHead('promos', 'العروض') ?>
      <?php $oc = ($trialOn ? 1 : 0) + count($promos) + (count($promos) < 2 && s_setting('agency_offer_title') !== '' ? 1 : 0); ?>
      <div class="o-grid" style="--oc:<?= max(1, min(3, $oc)) ?>">
        <?php if ($trialOn): ?>
        <div class="o-card o-dark rv rv-r ws-lift">
          <span class="ws-chip"><?= s_icon('gift', 15) ?>للتجربة</span>
          <h3>أول منشور عليك… والحفظ علينا</h3>
          <p>اصنع منشور كامل بتصميمه، وسجّل — الهوية والمنشور بيتحفظوا من غير ما يتخصم أي Credits.</p>
          <a class="ws-btn ws-pri" href="#s-create">جرّب دلوقتي</a>
        </div>
        <?php endif; ?>
        <?php $pi = 0; foreach ($promos as $p): $pi++; $pimg = s_img($p);
          $code = strtoupper(preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($p['promo_code'] ?? '')));
          $href = $p['link_url'] ?: ($code !== '' ? s_url('packages.php?code=' . $code) : '#s-pricing');
          if ($code !== '' && $p['link_url'] && str_contains($p['link_url'], 'checkout.php') && !str_contains($p['link_url'], 'code=')) $href .= (str_contains($href, '?') ? '&' : '?') . 'code=' . $code;
          $cls = $pi % 2 ? 'o-light rv' : 'o-tint rv rv-l'; ?>
          <div class="o-card <?= $cls ?> ws-lift" style="transition-delay:<?= $pi * .12 ?>s">
            <?php if ($pimg): ?><span class="o-img" style="background-image:url('<?= e($pimg) ?>')" aria-hidden="true"></span><?php endif; ?>
            <span class="ws-chip"><?= s_icon('sparkle', 15) ?><?= e(($p['badge'] ?? '') ?: 'عرض لفترة محدودة') ?></span>
            <h3><?= e($p['title']) ?></h3>
            <?php if ($p['body']): ?><p><?= e($p['body']) ?></p><?php endif; ?>
            <?php if ($code !== ''): ?><span class="o-code">🏷 <?= e($code) ?></span><?php endif; ?>
            <?php if ($p['ends_at']): ?><span class="o-dates">لحد <?= e(date('j/n/Y', strtotime($p['ends_at']))) ?></span><?php endif; ?>
            <a class="ws-btn ws-ghost" href="<?= e($appUrl($href)) ?>"><?= e(($p['btn_text'] ?? '') ?: 'شوف الباقات') ?></a>
          </div>
        <?php endforeach; ?>
        <?php if (count($promos) < 2 && ($at = s_setting('agency_offer_title')) !== ''): ?>
          <div class="o-card o-tint rv rv-l ws-lift" style="transition-delay:.24s">
            <span class="ws-chip"><?= s_icon('layers', 15) ?>للوكالات والفرق</span>
            <h3><?= e($at) ?></h3>
            <?php if ($ab = s_setting('agency_offer_body')): ?><p><?= e($ab) ?></p><?php endif; ?>
            <a class="ws-btn ws-ghost" href="<?= e($appUrl(s_setting('agency_offer_url', '#s-pricing'))) ?>">اعرف أكتر</a>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </section>
  <?php break;

  /* ═══════════ المشاكل ═══════════ */
  case 'problems': if (!$problems) break; ?>
  <section class="h-sec h-problems ws-dark" id="s-problems" data-say="عارف الإحساس ده؟" data-look="-1">
    <div class="h-wrap">
      <div class="h-sh rv"><span class="ws-chip"><?= s_icon('x', 15, 2) ?>المشاكل</span><h2 class="ws-h2"><?= e($sec('problems', 0)) ?></h2>
        <?php if ($sec('problems', 1) !== ''): ?><p class="pm"><?= e($sec('problems', 1)) ?></p><?php endif; ?></div>
      <div class="p-grid">
        <?php $rv = ['rv-r', '', 'rv-s', '', 'rv-l']; foreach ($problems as $i => $p): ?>
          <div class="pc rv <?= $rv[$i % 5] ?>" style="transition-delay:<?= ($i % 5) * .1 ?>s">
            <span class="pi"><?= s_icon($p['icon'], 22) ?></span>
            <span class="tx"><b><?= e($p['title']) ?></b><?php if ($p['body']): ?><span class="pm"><?= e($p['body']) ?></span><?php endif; ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </section>
  <?php break;

  /* ═══════════ الحلول ═══════════ */
  case 'about': if (!$solutions) break; ?>
  <section class="h-sec h-solutions" id="s-solutions" data-say="متقلقش، أنا معاك" data-look="1">
    <div class="h-wrap">
      <?= $secHead('about', 'الحلول') ?>
      <div class="s-grid">
        <?php foreach ($solutions as $i => $s): $simg = s_img($s); $ltr = !preg_match('/\p{Arabic}/u', (string) $s['title']); ?>
          <div class="s-card rv <?= $i % 3 === 0 ? 'rv-r' : ($i % 3 === 2 ? 'rv-l' : '') ?> ws-lift" style="transition-delay:<?= ($i % 3) * .12 ?>s">
            <div class="top"><span class="s-ic"><?= $simg ? '<img src="' . e($simg) . '" alt="" loading="lazy">' : s_icon($s['icon'], 24) ?></span>
              <?php if (!empty($s['instead_of'])): ?><span class="ins"><?= e($s['instead_of']) ?></span><?php endif; ?></div>
            <b<?= $ltr ? ' dir="ltr" style="text-align:right"' : '' ?>><?= e($s['title']) ?></b>
            <?php if ($s['body']): ?><span class="d"><?= e($s['body']) ?></span><?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </section>
  <?php break;

  /* ═══════════ خطوات العمل ═══════════ */
  case 'steps': if (!$steps) break; $G = s_steps_geometry(count($steps)); ?>
  <section class="h-sec h-steps" id="s-steps" data-say="<?= count($steps) ?> خطوات وخلاص" data-look="-1">
    <div class="h-wrap">
      <?= $secHead('steps', 'خطوات العمل') ?>
      <div class="st-desk" id="st-desk">
        <svg viewBox="0 0 1240 420" preserveAspectRatio="none" aria-hidden="true"><defs><linearGradient id="h-sg" x1="1" y1="0" x2="0" y2="0"><stop offset="0" stop-color="#2EE3CC"/><stop offset=".5" stop-color="#0C87EF"/><stop offset="1" stop-color="#9C8CFF"/></linearGradient></defs>
          <path d="<?= $G['desk']['d'] ?>" fill="none" stroke="#E6EDF5" stroke-width="3" stroke-linecap="round" stroke-dasharray="2 10" vector-effect="non-scaling-stroke"/>
          <path class="st-path" d="<?= $G['desk']['d'] ?>" pathLength="1" fill="none" stroke="url(#h-sg)" stroke-width="4" stroke-linecap="round" stroke-dasharray="1 1" style="stroke-dashoffset:1" vector-effect="non-scaling-stroke"/></svg>
        <?php foreach ($steps as $i => $st): [$x, $y] = $G['desk']['pts'][$i]; $up = $y < 200; ?>
          <div class="st-ic rv rv-s" style="left:<?= round($x / 12.4, 3) ?>%;top:<?= round($y / 4.2, 3) ?>%;transition-delay:<?= $i * .08 ?>s"><?= s_icon($st['icon'] ?: ($D['steps'][$i][0] ?? 'sparkle'), 28) ?></div>
          <div class="st-lb rv" style="left:<?= round($x / 12.4, 3) ?>%;<?= $up ? 'bottom:' . round((420 - 48) / 4.2, 3) . '%' : 'top:' . round(364 / 4.2, 3) . '%' ?>;transition-delay:<?= $i * .08 + .1 ?>s">
            <em><?= str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) ?></em><b><?= e($st['title']) ?></b>
          </div>
        <?php endforeach; ?>
      </div>
      <div class="st-mob" id="st-mob" style="height:<?= $G['mob']['h'] ?>px">
        <svg viewBox="0 0 358 <?= $G['mob']['h'] ?>" aria-hidden="true"><defs><linearGradient id="h-sgm" x1="1" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#2EE3CC"/><stop offset=".5" stop-color="#0C87EF"/><stop offset="1" stop-color="#9C8CFF"/></linearGradient></defs>
          <path d="<?= $G['mob']['d'] ?>" fill="none" stroke="#E6EDF5" stroke-width="3" stroke-linecap="round" stroke-dasharray="2 10"/>
          <path class="st-path" d="<?= $G['mob']['d'] ?>" pathLength="1" fill="none" stroke="url(#h-sgm)" stroke-width="4" stroke-linecap="round" stroke-dasharray="1 1" style="stroke-dashoffset:1"/></svg>
        <?php foreach ($steps as $i => $st): [$x, $y] = $G['mob']['pts'][$i]; ?>
          <div class="st-ic rv rv-s" style="left:<?= $x ?>px;top:<?= $y ?>px;transition-delay:<?= $i * .05 ?>s"><?= s_icon($st['icon'] ?: ($D['steps'][$i][0] ?? 'sparkle'), 22) ?></div>
          <div class="st-lb2 rv rv-r" style="top:<?= $y - 22 ?>px;transition-delay:<?= $i * .05 ?>s"><em><?= str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) ?></em><b><?= e($st['title']) ?></b><?php if (!empty($st['body'])): ?><small><?= e($st['body']) ?></small><?php endif; ?></div>
        <?php endforeach; ?>
      </div>
    </div>
  </section>
  <?php break;

  /* ═══════════ الخدمات ═══════════ */
  case 'services': if (!$services) break; ?>
  <section class="h-services" id="s-services" data-say="كل أداة ليها شغلها" data-look="1">
    <div class="h-wrap">
      <?= $secHead('services', 'الخدمات') ?>
      <div class="sv-tabs ws-noscroll">
        <div role="tablist" aria-label="الخدمات" class="ws-glass-l">
          <?php foreach ($services as $i => $s): ?><a role="tab" class="ws-tab" href="#svc-<?= $i ?>" aria-selected="<?= $i === 0 ? 'true' : 'false' ?>" data-svc="<?= $i ?>"><span<?= preg_match('/\p{Arabic}/u', (string) $s['title']) ? '' : ' dir="ltr"' ?>><?= e($s['title']) ?></span></a><?php endforeach; ?>
        </div>
      </div>
      <div>
        <?php foreach ($services as $i => $s): $simg = s_img($s); $ic = $s['icon'] ?: ($svcIcons[$i % 6]); $bl = s_lines($s['bullets'] ?? '');
          $ltr = !preg_match('/\p{Arabic}/u', (string) $s['title']); $link = trim((string) ($s['link_url'] ?? '')); ?>
          <div class="sv-item<?= $i % 2 ? ' alt' : '' ?>" id="svc-<?= $i ?>">
            <div class="sv-txt rv <?= $i % 2 ? 'rv-l' : 'rv-r' ?>">
              <span class="sv-ic"><?= s_icon($ic, 26) ?></span>
              <span class="sv-t"><b<?= $ltr ? ' dir="ltr"' : '' ?>><?= e($s['title']) ?></b><?php if (!empty($s['subtitle'])): ?><span><?= e($s['subtitle']) ?></span><?php endif; ?></span>
              <?php if ($s['body']): ?><p><?= e($s['body']) ?></p><?php endif; ?>
              <?php if ($bl): ?><div class="sv-bl"><?php foreach ($bl as $b): ?><span><i><?= s_icon('check', 14, 2.6) ?></i><?= e($b) ?></span><?php endforeach; ?></div><?php endif; ?>
              <a class="ws-btn ws-ghost" href="<?= e($link !== '' ? $appUrl($link) : $regUrl) ?>"><?= e(($s['link_text'] ?? '') ?: 'جرّب ' . $s['title']) ?><?= s_icon('arrow', 17, 2) ?></a>
            </div>
            <div class="sv-media rv <?= $i % 2 ? 'rv-r' : 'rv-l' ?>">
              <div class="box"><?php if ($simg): ?><img src="<?= e($simg) ?>" alt="<?= e($s['title']) ?>" loading="lazy"><?php else: ?><span class="big"><?= s_icon($ic, 56, 1.4) ?></span><?php endif; ?></div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </section>
  <?php break;

  /* ═══════════ التصميمات ═══════════ */
  case 'gallery': if (!$gItems) break; ?>
  <section class="h-designs" id="s-designs" data-say="دي من شغلنا" data-look="-1">
    <div class="h-wrap">
      <?= $secHead('gallery', 'التصميمات') ?>
      <?php if (count($dzCats) > 2): ?>
      <div class="dz-tabs ws-noscroll rv"><div role="tablist" aria-label="نوع التصميم">
        <?php foreach ($dzCats as $k => $l): ?><button type="button" role="tab" class="ws-pill" data-df="<?= $k ?>" aria-selected="<?= $k === 'all' ? 'true' : 'false' ?>"><?= e($l) ?></button><?php endforeach; ?>
      </div></div>
      <?php endif; ?>
      <div class="dz-grid" id="dz-grid">
        <?php foreach ($gItems as $g): if ($g['cat'] === 'ba') continue; $src = s_img($g); if (!$src) continue; ?>
          <figure class="dz-i sa-in ws-lift" data-c="<?= e($g['cat']) ?>"><img src="<?= e($src) ?>" alt="<?= e($g['title'] ?: 'تصميم من Spread AI') ?>" loading="lazy"><?php if ($g['title']): ?><figcaption><?= e($g['title']) ?></figcaption><?php endif; ?></figure>
        <?php endforeach; ?>
      </div>
      <?php if (isset($dzCats['ba'])): [$bBefore, $bAfter] = [s_img($baPairs[0]), s_img($baPairs[1])]; ?>
      <div class="ba" id="dz-ba" hidden>
        <div class="ba-box" id="ba-box">
          <img src="<?= e($bAfter) ?>" alt="بعد Spread AI" loading="lazy">
          <div class="ba-before"><img class="fill" src="<?= e($bBefore) ?>" alt="قبل" loading="lazy"></div>
          <span class="ba-tag b">قبل</span><span class="ba-tag a">بعد</span>
          <div class="ba-line"><span><?= s_icon('compare', 20, 2) ?></span></div>
          <input type="range" min="0" max="100" value="50" class="ws-range" aria-label="قارن قبل وبعد" id="ba-range">
        </div>
        <small>اسحب الخط علشان تقارن</small>
      </div>
      <?php endif; ?>
    </div>
  </section>
  <?php break;

  /* ═══════════ الأسعار (باقات المنصة) ═══════════ */
  case 'pricing': if (!$packages) break;
    $visible = $hasToggle ? array_values(array_filter($packages, fn($p) => !$p['yearly'])) : $packages;
    $anyFeat = count(array_filter($visible, fn($p) => $p['featured'])) > 0; ?>
  <section class="h-sec h-pricing" id="s-pricing" data-say="اختار اللي يناسبك" data-look="1">
    <div class="h-wrap">
      <?= $secHead('pricing', 'الأسعار') ?>
      <?php if ($hasToggle): ?>
        <div class="pr-toggle rv" role="group" aria-label="مدة الاشتراك">
          <button type="button" data-per="m" aria-pressed="true">شهري</button>
          <button type="button" data-per="y" aria-pressed="false">سنوي <?php if ($yn = s_setting('pricing_yearly_note')): ?><small><?= e($yn) ?></small><?php endif; ?></button>
        </div>
      <?php endif; ?>
      <div class="pr-grid" id="pr-grid" style="--cols:<?= max(1, min(4, count($visible))) ?>">
        <?php $vi = 0; foreach ($packages as $p):
          $isVis = !$hasToggle || !$p['yearly'];
          $feat = $p['featured'] || (!$anyFeat && $isVis && count($visible) >= 3 && $vi === 1);
          if ($isVis) $vi++;
          $badge = $p['badge'] !== '' ? $p['badge'] : ($p['featured'] ? 'الأكثر اختيارًا' : ''); ?>
          <div class="pr-card rv ws-lift<?= $feat ? ' feat' : '' ?>" data-per="<?= $p['yearly'] ? 'y' : 'm' ?>"<?= $isVis ? '' : ' hidden' ?>>
            <?php if ($badge !== ''): ?><span class="ws-chip pr-badge"><?= e($badge) ?></span><?php endif; ?>
            <span class="hd"><b><?= e($p['name']) ?></b><?php if ($p['desc'] !== ''): ?><span><?= e($p['desc']) ?></span><?php endif; ?></span>
            <span class="pr-price"><b><?= is_numeric($p['price']) ? e(number_format((float) $p['price'], fmod((float) $p['price'], 1) ? 2 : 0)) : e($p['price']) ?></b><span>ج.م <?= e($p['period']) ?></span></span>
            <?php if ($p['chip'] !== ''): ?><span class="ws-chip cr"><?= e($p['chip']) ?></span><?php endif; ?>
            <hr>
            <?php if ($p['features']): ?><div class="pr-f"><?php foreach ($p['features'] as $f): ?><span><i><?= s_icon('check', 13, 2.6) ?></i><span<?= preg_match('/\p{Arabic}/u', $f) ? '' : ' dir="ltr"' ?>><?= e($f) ?></span></span><?php endforeach; ?></div><?php endif; ?>
            <?php if ($p['bonus'] > 0): ?><span class="pr-bonus">🎁 +<?= (int) $p['bonus'] ?> كريدت هدية مع الباقة</span><?php endif; ?>
            <a class="ws-btn <?= $feat ? 'ws-pri' : 'ws-ghost' ?>" href="<?= e($p['url']) ?>">اشترك</a>
          </div>
        <?php endforeach; ?>
      </div>
      <?php if (!empty($packages[0]['platform'])): ?>
        <p class="pr-note rv">الدفع بإنستاباي أو فودافون كاش — ارفع إيصال التحويل والباقة بتتفعّل بعد المراجعة. عندك كود خصم؟ هتكتبه في صفحة الدفع.</p>
      <?php endif; ?>
    </div>
  </section>
  <?php break;

  /* ═══════════ CTA ═══════════ */
  case 'cta': ?>
  <section class="h-cta" id="s-cta" data-say="يلا بينا!" data-look="0">
    <div class="cta-box rv rv-s">
      <div class="cta-txt">
        <h2 class="ws-h2"><?= e(s_setting('cta_title', $D['cta'][0])) ?></h2>
        <p><?= e(s_setting('cta_body', $D['cta'][1])) ?></p>
        <div class="row">
          <a class="ws-btn ws-pri" href="<?= e($regUrl) ?>"><?= e(s_setting('cta_btn', $D['cta'][2])) ?><?= s_icon('arrow', 19, 2.2) ?></a>
          <?php if ($trialOn): ?><a class="ws-btn ws-ghost-d" href="#s-create"><?= e($D['cta'][3]) ?></a><?php endif; ?>
        </div>
      </div>
      <div class="cta-bot"><div class="ws-glow"></div><img src="<?= e($img('bot-wave.webp')) ?>" alt="روبوت Spread AI بيسلّم" loading="lazy" width="230" height="357"></div>
    </div>
  </section>
  <?php break;
  endswitch;
endforeach; ?>
</main>

<!-- ═══ الفوتر ═══ -->
<?php $menuPages = s_menu_pages(); ?>
<footer class="h-foot">
  <div class="in">
    <div class="cols">
      <div class="about">
        <a href="#s-hero" class="h-logo"><img src="<?= e($logo) ?>" alt="" width="38" height="32"><span class="h-logo-t" dir="ltr"><span>Spread <span class="ai-word">AI</span></span><small>Create · Plan · Publish</small></span></a>
        <p><?= e(s_setting('footer_about', 'فريق تسويق كامل بالذكاء الاصطناعي — من الفكرة للمحتوى للتصميم للنشر.')) ?></p>
        <?php $soc = ['social_facebook' => 'f', 'social_instagram' => 'IG', 'social_tiktok' => 'TT', 'social_linkedin' => 'in'];
        $socOn = array_filter($soc, fn($k) => s_setting($k) !== '', ARRAY_FILTER_USE_KEY);
        if ($socOn): ?><div class="soc"><?php foreach ($socOn as $k => $l): ?><a href="<?= e(s_setting($k)) ?>" target="_blank" rel="noopener" aria-label="<?= e($k) ?>"><?= e($l) ?></a><?php endforeach; ?></div><?php endif; ?>
      </div>
      <div class="col"><b>المنصة</b>
        <a href="<?= e(s_url('brand-brain.php')) ?>">Brand Brain</a><a href="<?= e(s_url('design-studio.php')) ?>">Design Studio</a>
        <a href="<?= e(s_url('research.php')) ?>">Deep Research</a><a href="<?= e(s_url('campaigns.php')) ?>">الحملات</a>
        <a href="<?= e($loginUrl) ?>">تسجيل الدخول</a>
      </div>
      <div class="col"><b>الموقع</b>
        <?php foreach ($menuPages as $pg): ?><a href="<?= e(s_page_url($pg['slug'])) ?>"><?= e($pg['title']) ?></a><?php endforeach; ?>
        <?php if (!$menuPages): ?><a href="#s-services">الخدمات</a><a href="#s-designs">التصميمات</a><?php endif; ?>
        <a href="#s-pricing">الأسعار</a>
      </div>
      <?php $ce = s_setting('contact_email'); $cw = s_setting('contact_whatsapp'); $cp = s_setting('contact_phone'); $ca = s_setting('contact_address');
      if ($ce || $cw || $cp || $ca): ?>
      <div class="col"><b>تواصل</b>
        <?php if ($ce): ?><a href="mailto:<?= e($ce) ?>" dir="ltr" style="text-align:right"><?= e($ce) ?></a><?php endif; ?>
        <?php if ($cw): ?><a href="https://wa.me/<?= e(preg_replace('/\D/', '', $cw)) ?>" target="_blank" rel="noopener">واتساب: <span dir="ltr"><?= e($cw) ?></span></a><?php endif; ?>
        <?php if ($cp): ?><a href="tel:<?= e(preg_replace('/[^0-9+]/', '', $cp)) ?>" dir="ltr" style="text-align:right"><?= e($cp) ?></a><?php endif; ?>
        <?php if ($ca): ?><span style="font-size:14px"><?= e($ca) ?></span><?php endif; ?>
      </div>
      <?php endif; ?>
    </div>
    <hr>
    <div class="bot"><span><?= e(s_setting('footer_copyright', '© ' . date('Y') . ' Spread AI — كل الحقوق محفوظة')) ?></span>
      <span><?php foreach (['terms' => 'الشروط', 'privacy' => 'الخصوصية'] as $slug => $l): if (s_one('SELECT id FROM site_pages WHERE slug = ? AND is_active = 1', [$slug])): ?><a href="<?= e(s_page_url($slug)) ?>"><?= e($l) ?></a><?php endif; endforeach; ?></span></div>
  </div>
</footer>

<!-- ═══ الروبوت المرافق ═══ -->
<div class="h-comp" id="h-comp" aria-hidden="true">
  <button type="button" id="h-comp-btn" aria-label="روبوت Spread AI — اصنع منشورك" tabindex="-1"><span class="ws-bot" id="h-comp-bot"><img src="<?= e($img('bot-idle.webp')) ?>" alt="" loading="lazy" width="104" height="161"></span></button>
  <span class="h-say ws-glass-l" id="h-say" role="status"></span>
</div>

<script>
window.SPREAD_HOME = <?= json_encode([
    'trialApi' => s_url('ajax/trial.php'),
    'reg' => $regUrl,
    'login' => $loginUrl,
    'tags' => ['مطعم أو كافيه' => '#أكل_بيتي #مطاعم #عروض_اليوم', 'عيادة أو مركز طبي' => '#صحتك_تهمنا #استشارة #عيادة', 'متجر ملابس' => '#ستايل #كولكشن_جديد #موضة',
               'أكاديمية أو كورسات' => '#تعلم #كورسات #مهارات', 'خدمات' => '#خدمات #جودة #ثقة'],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
</script>
<script src="<?= e(s_url('site-assets/js/home-v2.js')) ?>?v=<?= $jsV ?>" defer></script>
</body>
</html>

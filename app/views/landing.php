<?php
/**
 * Landing page template.
 * @var array  $S            Settings::all() (raw — never print secret keys)
 * @var string $csrf
 * @var string $pageViewEventId
 * @var array  $stats, $experience, $steps, $faqs, $branches, $schemas, $siteConfig
 * @var string $canonical
 */
$logo = asset((string) Settings::get('logo'));
$doctorPhoto = asset((string) Settings::get('doctor_photo'));
$ogImage = Settings::get('og_image', '') ? url((string) Settings::get('og_image')) : url((string) Settings::get('doctor_photo'));
$wa = whatsapp_link();
$doctorName = (string) Settings::get('doctor_name');
$doctorTitle = (string) Settings::get('doctor_title');
$iconFor = static fn (?string $i, string $fallback) => preg_match('/^[a-z0-9-]+$/', (string) $i) ? $i : $fallback;
$socials = array_filter([
    'fb' => ['فيسبوك', Settings::get('social_facebook', '')],
    'ig' => ['إنستجرام', Settings::get('social_instagram', '')],
    'yt' => ['يوتيوب', Settings::get('social_youtube', '')],
    'tiktok' => ['تيك توك', Settings::get('social_tiktok', '')],
], static fn ($s) => $s[1] !== '' && preg_match('#^https://#', (string) $s[1]));
?><!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= e(Settings::get('site_title')) ?></title>
<meta name="description" content="<?= e(Settings::get('meta_description')) ?>">
<?php if (Settings::get('meta_keywords', '')): ?><meta name="keywords" content="<?= e(Settings::get('meta_keywords')) ?>"><?php endif; ?>
<meta name="csrf" content="<?= e($csrf) ?>">
<meta name="theme-color" content="<?= e(css_color('color_primary', '#1F73B7')) ?>">
<link rel="canonical" href="<?= e($canonical) ?>">
<meta property="og:type" content="website">
<meta property="og:locale" content="<?= e(Settings::get('locale', 'ar_EG')) ?>">
<meta property="og:url" content="<?= e($canonical) ?>">
<meta property="og:title" content="<?= e(Settings::get('og_title', '') ?: Settings::get('site_title')) ?>">
<meta property="og:description" content="<?= e(Settings::get('og_description', '') ?: Settings::get('meta_description')) ?>">
<meta property="og:image" content="<?= e($ogImage) ?>">
<meta name="twitter:card" content="summary_large_image">
<link rel="icon" href="<?= e(asset((string) (Settings::get('favicon', '') ?: Settings::get('logo')))) ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(asset('assets/css/site.css')) ?>">
<style>:root{--primary:<?= css_color('color_primary', '#1F73B7') ?>;--ink:<?= css_color('color_secondary', '#0E2B45') ?>;--accent:<?= css_color('color_accent', '#C9A24B') ?>}</style>
<link rel="preload" as="image" href="<?= e($logo) ?>">
<?php foreach ($schemas as $schema): ?>
<script type="application/ld+json"><?= json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?></script>
<?php endforeach; ?>
<script>window.SITE_CONFIG = <?= json_encode($siteConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>
<?php require APP_PATH . '/views/partials/tracking_head.php'; ?>
<?php if ($siteConfig['turnstile']): ?><script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script><?php endif; ?>
</head>
<body>
<?php require APP_PATH . '/views/partials/tracking_body.php'; ?>
<?php require APP_PATH . '/views/partials/icons.php'; ?>

<a href="#main" class="sr-only">تخطي للمحتوى</a>

<!-- ======================= HERO ======================= -->
<div class="hero" id="top">
  <svg class="hero-deco" width="600" height="600" viewBox="0 0 600 600" aria-hidden="true"><circle cx="300" cy="300" r="290" fill="#E8F3FB"/><circle cx="300" cy="300" r="200" fill="#F5FAFE"/></svg>

  <header class="site-header">
    <div class="container">
      <a href="#top" class="logo" aria-label="<?= e($doctorName) ?> — الصفحة الرئيسية"><img src="<?= e($logo) ?>" alt="<?= e($doctorName . ' — ' . $doctorTitle) ?>" width="152" height="76"></a>
      <a href="<?= e($wa) ?>" class="btn btn-secondary btn-sm wa-pill" data-wa data-track="whatsapp_click" data-place="header" aria-label="كلّم الدكتور على واتساب">
        <svg class="ic sm"><use href="#i-wa"/></svg><span>واتساب</span>
      </a>
    </div>
  </header>

  <main id="main" class="container hero-grid">
    <section class="hero-copy">
      <?php if (Settings::get('hero_badge', '')): ?><span class="chip"><svg class="ic sm"><use href="#i-spark"/></svg><?= e(Settings::get('hero_badge')) ?></span><?php endif; ?>
      <h1 class="h1"><?= e(Settings::get('hero_title')) ?></h1>
      <p class="lead" style="max-width:500px"><?= e(Settings::get('hero_subtitle')) ?></p>

      <form class="card hero-form" id="leadForm" novalidate>
        <div class="row">
          <div class="field">
            <label class="label" for="f-name">الاسم</label>
            <input id="f-name" name="name" class="input" placeholder="اكتب اسمك" autocomplete="name" maxlength="60" required>
            <span class="msg err" id="e-name" hidden></span>
          </div>
          <div class="field">
            <label class="label" for="f-phone">رقم التواصل</label>
            <input id="f-phone" name="phone" class="input" dir="ltr" inputmode="tel" placeholder="01X XXXX XXXX" autocomplete="tel" maxlength="20" required aria-describedby="h-phone">
            <span class="msg" id="h-phone">رقم موبايل مصري من 11 رقم</span>
          </div>
        </div>
        <label class="check" id="consentWrap">
          <input type="checkbox" id="f-consent" name="consent" required>
          <span class="box" aria-hidden="true"><svg class="ic sm" style="stroke-width:2.6"><use href="#i-check"/></svg></span>
          <span>أوافق على استخدام صورتي لإنشاء المحاكاة — <button type="button" class="link" data-open="privacyModal">سياسة الخصوصية</button></span>
        </label>
        <div class="hp" aria-hidden="true"><label>اتركه فاضي<input name="website" tabindex="-1" autocomplete="off"></label></div>
        <?php if ($siteConfig['turnstile']): ?><div class="cf-turnstile" data-sitekey="<?= e($siteConfig['turnstile']) ?>" data-language="ar" data-appearance="interaction-only"></div><?php endif; ?>
        <p class="msg err" id="formError" role="alert" hidden></p>
        <button type="submit" class="btn btn-primary btn-lg btn-block" id="leadBtn">
          <span><?= e(Settings::get('hero_button')) ?></span>
          <svg class="ic"><use href="#i-arrow"/></svg>
        </button>
        <p class="msg" id="returning" hidden><svg class="ic xs"><use href="#i-info"/></svg>ده نتيجتك اللي عملتها قبل كده</p>
      </form>

      <div class="trust">
        <span class="chip"><svg class="ic sm"><use href="#i-lock"/></svg>صورتك سرّية</span>
        <span class="chip"><svg class="ic sm"><use href="#i-user"/></svg>بدون تسجيل</span>
        <span class="chip"><svg class="ic sm"><use href="#i-clock"/></svg>بياخد 20 ثانية</span>
      </div>
    </section>

    <section class="hero-art" aria-hidden="true">
      <div class="art-card">
        <svg class="art" viewBox="0 0 520 540" preserveAspectRatio="xMidYMid slice">
          <circle cx="260" cy="250" r="210" fill="#fff" opacity=".7"/>
          <circle cx="260" cy="250" r="140" fill="#E8F3FB"/>
          <circle cx="70" cy="80" r="4" fill="#A5CFEE"/><circle cx="460" cy="400" r="5" fill="#A5CFEE"/><circle cx="470" cy="90" r="3" fill="#1F73B7" opacity=".5"/><circle cx="50" cy="360" r="3" fill="#1F73B7" opacity=".4"/>
          <svg x="318" y="64" width="130" height="234" viewBox="0 0 200 360"><g fill="#C3DDF0"><circle cx="100" cy="46" r="27"/><path d="M100 84C128 84 150 98 154 126C160 160 168 188 162 214C158 236 150 262 148 340L112 340L106 252L94 252L88 340L52 340C50 262 42 236 38 214C32 188 40 160 46 126C50 98 72 84 100 84Z"/></g></svg>
          <path d="M312 190C292 150 248 150 226 182" fill="none" stroke="#1F73B7" stroke-width="3" stroke-dasharray="2 10" stroke-linecap="round"/>
          <path d="M236 168L224 184L242 190" fill="none" stroke="#1F73B7" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
          <svg x="72" y="64" width="130" height="234" viewBox="0 0 200 360"><g fill="#1F73B7"><circle cx="100" cy="46" r="27"/><path d="M100 84C122 84 139 98 142 124C146 154 147 180 143 204C140 226 138 262 137 340L109 340L104 246L96 246L91 340L63 340C62 262 60 226 57 204C53 180 54 154 58 124C61 98 78 84 100 84Z"/></g></svg>
        </svg>
        <div class="art-steps">
          <div><strong>1</strong><span>بياناتك</span></div>
          <div><strong>2</strong><span>صورتك</span></div>
          <div><strong>3</strong><span>النتيجة</span></div>
        </div>
      </div>
    </section>
  </main>
</div>

<?php require APP_PATH . '/views/partials/tool.php'; ?>

<!-- ======================= DOCTOR ======================= -->
<section class="section" id="doctor" aria-labelledby="docTitle">
  <div class="container doc-grid">
    <figure class="portrait reveal">
      <div class="portrait-in"><img src="<?= e($doctorPhoto) ?>" alt="<?= e($doctorName) ?>" loading="lazy" width="903" height="1672"></div>
      <figcaption class="portrait-tag"><b><?= e($doctorName) ?></b><span><?= e($doctorTitle) ?></span></figcaption>
    </figure>
    <div style="display:flex;flex-direction:column;gap:24px" class="reveal">
      <div style="display:flex;flex-direction:column;gap:10px">
        <span class="eyebrow">عن الدكتور</span>
        <h2 class="h2" id="docTitle">الدكتور اللي هتتابع معاه</h2>
        <p class="lead"><?= e_nl(Settings::get('doctor_bio')) ?></p>
      </div>
      <?php if ($stats): ?>
      <div class="stats">
        <?php foreach ($stats as $st): ?>
        <div class="card stat"><b class="ltr"><?= e($st['value']) ?></b><span><?= e($st['title']) ?></span></div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
      <?php if ($experience): ?>
      <div style="display:flex;flex-direction:column;gap:16px">
        <h3 class="h3">الخبرة والشهادات</h3>
        <ol class="timeline">
          <?php foreach ($experience as $x): ?>
          <li class="tl"><?php if (trim((string) $x['value']) !== ''): ?><small><?= e($x['value']) ?></small><?php endif; ?><b><?= e($x['title']) ?></b><?php if ($x['body']): ?><span><?= e($x['body']) ?></span><?php endif; ?></li>
          <?php endforeach; ?>
        </ol>
      </div>
      <?php endif; ?>
      <a href="<?= e($wa) ?>" class="btn btn-wa" style="align-self:flex-start" data-wa data-track="whatsapp_click" data-place="doctor"><svg class="ic sm"><use href="#i-wa"/></svg>احجز استشارتك</a>
    </div>
  </div>
</section>

<?php if ($steps): ?>
<!-- ======================= STEPS ======================= -->
<section class="section" style="background:var(--sky-50)" aria-labelledby="stepsTitle">
  <div class="container">
    <div class="section-head reveal">
      <span class="eyebrow">رحلتك مع العملية</span>
      <h2 class="h2" id="stepsTitle"><?= count($steps) ?> خطوات بسيطة</h2>
      <p class="lead">من أول استشارة لحد المتابعة بعد العملية.</p>
    </div>
    <ol class="steps">
      <?php foreach ($steps as $i => $st): ?>
      <li class="card step reveal"><div class="step-top"><span class="iconbox sm"><svg class="ic"><use href="#i-<?= e($iconFor($st['icon'], 'chat')) ?>"/></svg></span><span class="step-num"><?= $i + 1 ?></span></div><h3 class="h3"><?= e($st['title']) ?></h3><p class="muted"><?= e($st['body']) ?></p></li>
      <?php endforeach; ?>
    </ol>
  </div>
</section>
<?php endif; ?>

<?php if ($branches): ?>
<!-- ======================= BRANCHES ======================= -->
<section class="section" id="branches" aria-labelledby="brTitle">
  <div class="container">
    <div class="section-head reveal">
      <span class="eyebrow">الفروع</span>
      <h2 class="h2" id="brTitle">تعالى زورنا</h2>
    </div>
    <div class="br-grid">
      <div class="branches">
        <?php foreach ($branches as $b): ?>
        <article class="card branch reveal">
          <div class="branch-h"><span class="iconbox sm"><svg class="ic"><use href="#i-pin"/></svg></span><div><b><?= e($b['name']) ?></b><?php if ($b['address']): ?><span><?= e($b['address']) ?></span><?php endif; ?></div></div>
          <?php if ($b['working_hours']): ?><p class="hours"><svg class="ic sm"><use href="#i-clock"/></svg>مواعيد العمل: <?= e($b['working_hours']) ?></p><?php endif; ?>
          <div class="branch-btns">
            <?php if ($b['map_url']): ?><a class="btn btn-primary btn-sm" href="<?= e($b['map_url']) ?>" target="_blank" rel="noopener" data-track="directions_click"><svg class="ic sm"><use href="#i-nav"/></svg>اتجاهات</a><?php endif; ?>
            <?php if ($b['phone']): ?><a class="btn btn-secondary btn-sm" href="tel:<?= e(preg_replace('/[^\d+]/', '', (string) $b['phone'])) ?>" data-track="call_click"><svg class="ic sm"><use href="#i-phone"/></svg>اتصل</a><?php endif; ?>
          </div>
        </article>
        <?php endforeach; ?>
      </div>
      <?php $embed = $branches[0]['map_embed'] ?? ''; if ($embed && preg_match('#^https://(www\.)?(google\.[a-z.]+|maps\.google\.[a-z.]+)/#', $embed)): ?>
      <div class="map reveal"><iframe title="خريطة <?= e($branches[0]['name']) ?>" loading="lazy" referrerpolicy="no-referrer-when-downgrade" src="<?= e($embed) ?>"></iframe></div>
      <?php endif; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<?php if ($faqs): ?>
<!-- ======================= FAQ ======================= -->
<section class="section" style="padding-top:0" aria-labelledby="faqTitle">
  <div class="container">
    <div class="section-head reveal">
      <span class="eyebrow">أسئلة شائعة</span>
      <h2 class="h2" id="faqTitle">اللي بيسأل عنه الناس</h2>
    </div>
    <div class="faqs" id="faqs">
      <?php foreach ($faqs as $i => $f): ?>
      <div class="faq<?= $i === 0 ? ' open' : '' ?>">
        <button class="faq-q" aria-expanded="<?= $i === 0 ? 'true' : 'false' ?>" aria-controls="fa<?= $i ?>" id="fq<?= $i ?>"><span><?= e($f['title']) ?></span><svg class="ic sm"><use href="#i-plus"/></svg></button>
        <div class="faq-a" id="fa<?= $i ?>" role="region" aria-labelledby="fq<?= $i ?>"<?= $i === 0 ? '' : ' hidden' ?>><?= e_nl($f['body']) ?></div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<!-- ======================= CTA ======================= -->
<section class="container" aria-labelledby="ctaTitle">
  <div class="cta reveal">
    <svg class="deco" width="320" height="320" viewBox="0 0 320 320" aria-hidden="true"><circle cx="160" cy="160" r="150" fill="none" stroke="#fff" stroke-width="2"/><circle cx="160" cy="160" r="95" fill="none" stroke="#fff" stroke-width="2"/><circle cx="160" cy="160" r="40" fill="none" stroke="#fff" stroke-width="2"/></svg>
    <div style="position:relative;display:flex;flex-direction:column;gap:8px">
      <h2 class="h2" id="ctaTitle" style="font-size:clamp(26px,4vw,36px)">ابدأ رحلتك النهارده</h2>
      <p>كلّم الدكتور على واتساب واحجز استشارتك، وهو هيحدد لو العملية مناسبة ليك.</p>
    </div>
    <a href="<?= e($wa) ?>" class="btn btn-wa btn-lg" data-wa data-track="whatsapp_click" data-place="cta"><svg class="ic"><use href="#i-wa"/></svg>احجز استشارتك على واتساب</a>
  </div>
</section>

<!-- ======================= FOOTER ======================= -->
<footer class="site-footer">
  <div class="container">
    <div class="foot-grid">
      <div class="foot-brand">
        <img src="<?= e($logo) ?>" alt="" width="128" height="64" loading="lazy">
        <b><?= e($doctorName) ?></b>
        <span><?= e($doctorTitle) ?></span>
      </div>
      <p class="disclaimer"><?= e(Settings::get('footer_disclaimer')) ?></p>
      <div class="foot-links">
        <button class="link" data-open="privacyModal">سياسة الخصوصية</button>
        <?php if ($socials): ?>
        <div class="socials">
          <?php foreach ($socials as $icon => [$label, $href]): ?>
          <a href="<?= e($href) ?>" target="_blank" rel="noopener" aria-label="<?= e($label) ?>"><svg class="ic sm"><use href="#i-<?= e($icon) ?>"/></svg></a>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>
    <div class="foot-bottom">
      <span>© <?= date('Y') ?> <?= e($doctorName) ?> — جميع الحقوق محفوظة</span>
      <span class="made-by">صنعت بواسطة <a href="https://spreadagency.net" target="_blank" rel="noopener">Spread</a> · <a href="https://spreadagency.net" target="_blank" rel="noopener" class="ltr">Spreadagency.net</a></span>
    </div>
  </div>
</footer>

<!-- Sticky WhatsApp (mobile) -->
<div class="sticky-wa" id="stickyWa">
  <a href="<?= e($wa) ?>" class="btn btn-wa btn-block" data-wa data-track="whatsapp_click" data-place="sticky"><svg class="ic"><use href="#i-wa"/></svg>اسأل الدكتور على واتساب</a>
</div>

<!-- Privacy modal -->
<div class="modal" id="privacyModal" role="dialog" aria-modal="true" aria-labelledby="pvTitle" hidden>
  <div class="card modal-box">
    <div class="modal-h"><h2 class="h3" id="pvTitle">سياسة الخصوصية</h2><button class="x" data-close aria-label="إغلاق"><svg class="ic sm"><use href="#i-x"/></svg></button></div>
    <div class="muted" style="display:flex;flex-direction:column;gap:10px">
      <?php foreach (preg_split('/\R+/u', trim((string) Settings::get('privacy_text'))) as $para): ?>
      <p><?= e($para) ?></p>
      <?php endforeach; ?>
    </div>
    <button class="btn btn-primary" data-close>تمام، فهمت</button>
  </div>
</div>

<!-- Share modal -->
<div class="modal" id="shareModal" role="dialog" aria-modal="true" aria-labelledby="shTitle" hidden>
  <div class="card modal-box">
    <div class="modal-h"><h2 class="h3" id="shTitle">شارك المحاكاة</h2><button class="x" data-close aria-label="إغلاق"><svg class="ic sm"><use href="#i-x"/></svg></button></div>
    <p class="muted small">اللينك بيعرض صورة "بعد" بس واسمك الأول — رقمك مش بيظهر أبدًا.</p>
    <div class="share-grid">
      <a class="btn btn-ghost" id="shWa" target="_blank" rel="noopener" style="color:var(--wa)"><svg class="ic"><use href="#i-wa"/></svg>واتساب</a>
      <a class="btn btn-ghost" id="shFb" target="_blank" rel="noopener"><svg class="ic"><use href="#i-fb"/></svg>فيسبوك</a>
      <button class="btn btn-ghost" id="shCopy"><svg class="ic"><use href="#i-copy"/></svg>انسخ اللينك</button>
    </div>
  </div>
</div>

<script src="<?= e(asset('assets/js/site.js')) ?>" defer></script>
</body>
</html>

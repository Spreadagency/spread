<?php
/**
 * Spread AI — الصفحة الرئيسية للموقع التعريفي
 * كل الأقسام بتتقرأ من قاعدة بيانات الموقع (منفصلة عن المنصة)
 */
require_once __DIR__ . '/site/functions.php';

$slides   = s_all('SELECT * FROM site_slides WHERE is_active = 1 ORDER BY sort_order, id LIMIT 6');
$brands   = s_all('SELECT * FROM site_brands WHERE is_active = 1 ORDER BY sort_order, id');
$promos   = s_all('SELECT * FROM site_promos WHERE is_active = 1
                   AND (starts_at IS NULL OR starts_at <= CURDATE())
                   AND (ends_at IS NULL OR ends_at >= CURDATE())
                   ORDER BY sort_order, id LIMIT 4');
$problems = s_all('SELECT * FROM site_problems WHERE is_active = 1 ORDER BY sort_order, id');
$solutions= s_all('SELECT * FROM site_solutions WHERE is_active = 1 ORDER BY sort_order, id');
$steps    = s_all('SELECT * FROM site_steps WHERE is_active = 1 ORDER BY sort_order, id');
$services = s_all('SELECT * FROM site_services WHERE is_active = 1 ORDER BY sort_order, id');
$gallery  = s_all('SELECT * FROM site_gallery WHERE is_active = 1 ORDER BY sort_order, id LIMIT 12');
$packages = s_all('SELECT * FROM site_packages WHERE is_active = 1 ORDER BY sort_order, id');

$loginUrl = s_setting('platform_login_url', PLATFORM_LOGIN);
$regUrl   = s_setting('platform_register_url', PLATFORM_REGISTER);

$__pageDesc = s_setting('tagline');
include __DIR__ . '/site/header.php';
?>

<?php if (s_visible('hero')): ?>
<!-- ═══════════ الهيرو + سلايدر الخدمات ═══════════ -->
<section class="hero">
  <div class="wrap hero-grid">
    <div>
      <div class="eyebrow" style="animation:fadeUp .8s .15s both"><?= e(s_setting('tagline')) ?></div>
      <h1>
        <?php
          $lines = s_lines(s_setting('hero_title', "محتوى وتصميمات\nبالذكاء الاصطناعي"));
          foreach ($lines as $i => $ln):
        ?>
          <span class="ln"><span style="animation-delay:<?= 0.25 + $i * 0.12 ?>s<?= $i === count($lines) - 1 ? ';color:var(--blue)' : '' ?>"><?= e($ln) ?></span></span>
        <?php endforeach; ?>
      </h1>
      <p class="hero-sub"><?= e(s_setting('hero_subtitle')) ?></p>
      <div class="hero-actions">
        <a href="<?= e($regUrl) ?>" class="btn-pill btn-blue btn-lg"><span>ابدأ مجانًا</span><span>←</span></a>
        <a href="<?= e($loginUrl) ?>" class="btn-pill btn-outline btn-lg">دخول للمنصة</a>
      </div>
    </div>

    <div class="hero-stage" id="heroStage" data-parallax="18">
      <?php if ($slides): ?>
        <?php foreach ($slides as $i => $sl): $img = s_img($sl); ?>
          <div class="hs <?= ['a','b','c'][min($i,2)] ?>" style="<?= $i > 2 ? 'display:none' : '' ?>">
            <?php if ($img): ?>
              <img src="<?= e($img) ?>" alt="<?= e($sl['title']) ?>" loading="<?= $i === 0 ? 'eager' : 'lazy' ?>"
                   onerror="this.closest('.hs').classList.add('noimg');this.remove()">
            <?php else: ?>
              <div class="hs-fallback" style="--i:<?= $i ?>"></div>
            <?php endif; ?>
            <div class="hs-cap">
              <b><?= e($sl['title']) ?></b>
              <?php if ($sl['subtitle']): ?><span><?= e($sl['subtitle']) ?></span><?php endif; ?>
              <?php if (!empty($sl['cta_text']) && !empty($sl['cta_url'])): ?>
                <a href="<?= e($sl['cta_url']) ?>" class="btn-pill btn-light" style="margin-top:10px;padding:8px 15px;font-size:12.5px"><?= e($sl['cta_text']) ?> ←</a>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
        <?php if (count($slides) > 1): ?>
          <button class="stage-nav" id="stageNext" type="button" aria-label="الشريحة التالية">
            <span>←</span><span><?= e(s_section('hero')['title'] ?: 'الخدمات') ?></span><span>→</span>
          </button>
        <?php endif; ?>
      <?php else: ?>
        <!-- مفيش شرائح: بلوك واحد بس بتدرّج -->
        <div class="hs a">
          <div class="hs-fallback" style="--i:0"></div>
          <div class="hs-cap"><b>أضف صور السلايدر من لوحة التحكم</b><span>site-admin ← السلايدر</span></div>
        </div>
      <?php endif; ?>
  </div>
</section>
<?php endif; ?>

<?php if (s_visible('ribbon')): ?>
<!-- ═══════════ الشريط المتحرك ═══════════ -->
<?php
  // النص بيتكرر عشان يغطي المسار كله أثناء الحركة
  $ribbonText = trim(s_setting('ribbon_text', 'SPREAD AI • محتوى بالذكاء الاصطناعي • تصميمات •'));
  $ribbonRepeat = trim(str_repeat($ribbonText . '   ', 4));
?>
<div class="ribbon-wrap" aria-hidden="true">
  <!-- direction:ltr مهم جدًا: الـ RTL بيمنع توزيع النص على المسار -->
  <svg viewBox="0 0 1600 240" preserveAspectRatio="none" direction="ltr" style="direction:ltr">
    <defs>
      <linearGradient id="rg" x1="0" y1="0" x2="1" y2="0">
        <stop offset="0%" stop-color="var(--blue)"></stop>
        <stop offset="55%" stop-color="var(--turq)"></stop>
        <stop offset="100%" stop-color="var(--sky)"></stop>
      </linearGradient>
      <path id="ribbonPath" d="M-140,172 C220,38 560,272 900,128 C1210,2 1430,192 1760,66"></path>
    </defs>

    <use href="#ribbonPath" fill="none" stroke="url(#rg)" stroke-width="72" stroke-linecap="round"></use>

    <text fill="#FFFFFF" font-family="Almarai, sans-serif" font-weight="800"
          font-size="30" letter-spacing="1.5" dominant-baseline="middle" direction="ltr">
      <textPath href="#ribbonPath" startOffset="0%"><?= e($ribbonRepeat) ?><animate attributeName="startOffset" from="0%" to="-45%" dur="26s" repeatCount="indefinite"></animate></textPath>
    </text>
  </svg>
</div>
<?php endif; ?>

<?php if (s_visible('trial')): ?>
<!-- ═══════════ اصنع منشورك الآن (تجربة تفاعلية) ═══════════ -->
<section class="sec" id="make-post">
  <div class="wrap">
    <?php $sec = s_section('trial'); ?>
    <div class="sec-head rv">
      <div class="eyebrow">جرّبها دلوقتي · مجانًا</div>
      <h2><?= e($sec['title'] ?: 'اصنع منشورك الآن') ?></h2>
      <p><?= e($sec['subtitle'] ?: 'اكتب معلومتين عن بيزنسك، واستلم أفكار ومنشور حقيقي في أقل من دقيقة — من غير تسجيل.') ?></p>
    </div>
    <div class="rv">
      <?php $trialCompact = true; include __DIR__ . '/site/parts/trial-widget.php'; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<?php if (s_visible('brands') && $brands): ?>
<!-- ═══════════ العلامات التجارية ═══════════ -->
<section class="sec" style="padding-top:0">
  <div class="wrap">
    <?php $sec = s_section('brands'); ?>
    <p class="rv" style="text-align:center;color:var(--dim);font-size:13px;letter-spacing:.16em;margin:0 0 26px"><?= e($sec['title'] ?: 'بيثقوا فينا') ?></p>
    <div class="brands-row rv">
      <?php foreach ($brands as $b): $lg = s_img($b, 'logo_path', 'logo_url'); ?>
        <?php if ($b['link_url']): ?><a href="<?= e($b['link_url']) ?>" target="_blank" rel="noopener"><?php endif; ?>
          <?php if ($lg): ?>
            <img class="b" src="<?= e($lg) ?>" alt="<?= e($b['name']) ?>" loading="lazy">
          <?php else: ?>
            <span class="brand-name"><?= e($b['name']) ?></span>
          <?php endif; ?>
        <?php if ($b['link_url']): ?></a><?php endif; ?>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<?php if (s_visible('promos') && $promos): ?>
<!-- ═══════════ الإعلانات والعروض ═══════════ -->
<section class="sec" id="promos">
  <div class="wrap">
    <?php $sec = s_section('promos'); ?>
    <div class="sec-head rv">
      <div class="eyebrow">OFFERS</div>
      <h2><?= e($sec['title'] ?: 'عروض حالية') ?></h2>
      <?php if ($sec['subtitle']): ?><p><?= e($sec['subtitle']) ?></p><?php endif; ?>
    </div>
    <div class="grid <?= count($promos) > 2 ? 'g2' : 'g2' ?>">
      <?php foreach ($promos as $p): $img = s_img($p); $tag = $p['link_url'] ? 'a' : 'div'; ?>
        <<?= $tag ?> class="promo rv" <?= $p['link_url'] ? 'href="' . e($p['link_url']) . '"' : '' ?>>
          <?php if ($img): ?><img src="<?= e($img) ?>" alt="<?= e($p['title']) ?>" loading="lazy"><?php endif; ?>
          <div class="ov">
            <b><?= e($p['title']) ?></b>
            <?php if ($p['body']): ?><p><?= e($p['body']) ?></p><?php endif; ?>
          </div>
        </<?= $tag ?>>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<?php if (s_visible('problems') && $problems): ?>
<!-- ═══════════ المشاكل — القسم بيتحول للأسود عند دخوله ═══════════ -->
<section class="sec shift" id="problems" style="border-radius:clamp(24px,3vw,40px);margin-inline:clamp(10px,2vw,26px);padding-inline:clamp(18px,3vw,50px)">
  <div class="wrap">
    <?php $sec = s_section('problems'); ?>
    <div class="sec-head rv">
      <div class="eyebrow">THE PROBLEM</div>
      <h2><?= e($sec['title'] ?: 'إيه اللي بيوقفك؟') ?></h2>
      <?php if ($sec['subtitle']): ?><p><?= e($sec['subtitle']) ?></p><?php endif; ?>
    </div>

    <?php
      // كل بطاقة بتيجي من اتجاه مختلف بدوران خفيف (زي التصميم المرجعي)
      $from = [['-70,20,-4','-2deg'], ['70,26,4','2deg'], ['0,-64,3','-1.5deg'], ['0,72,-3','1.5deg'], ['-60,40,3','2deg'], ['60,-30,-3','-1.5deg']];
    ?>
    <div class="grid g2">
      <?php foreach ($problems as $i => $p): $f = $from[$i % count($from)]; ?>
        <div class="pc dcard" data-from="<?= e($f[0]) ?>" style="--r:<?= e($f[1]) ?>">
          <?php if ($p['icon']): ?><span class="ic"><?= e($p['icon']) ?></span><?php endif; ?>
          <div style="min-width:0">
            <b><?= e($p['title']) ?></b>
            <?php if ($p['body']): ?><p><?= e($p['body']) ?></p><?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <!-- أرقام بعدّاد -->
    <div class="stats rv" style="margin-top:clamp(34px,5vh,58px);text-align:center">
      <div><div class="ctr" data-to="<?= (int) s_setting('stat1_num', '312') ?>" data-suffix="<?= e(s_setting('stat1_suffix', '')) ?>">0</div><small><?= e(s_setting('stat1_label', 'منشور اتعمل')) ?></small></div>
      <div><div class="ctr" data-to="<?= (int) s_setting('stat2_num', '50') ?>" data-suffix="<?= e(s_setting('stat2_suffix', 'ك+')) ?>">0</div><small><?= e(s_setting('stat2_label', 'وصول للجمهور')) ?></small></div>
      <div><div class="ctr" data-to="<?= (int) s_setting('stat3_num', '5') ?>" data-suffix="<?= e(s_setting('stat3_suffix', 'د')) ?>">0</div><small><?= e(s_setting('stat3_label', 'من الفكرة للنشر')) ?></small></div>
    </div>
  </div>
</section>
<?php endif; ?>

<?php if (s_visible('about') && $solutions): ?>
<!-- ═══════════ عن المنصة والحلول ═══════════ -->
<section class="sec" id="about">
  <div class="wrap">
    <?php $sec = s_section('about'); ?>
    <div class="sec-head rv">
      <div class="eyebrow">THE SOLUTION</div>
      <h2><?= e($sec['title'] ?: 'إحنا بنحلها إزاي؟') ?></h2>
      <?php if ($sec['subtitle']): ?><p><?= e($sec['subtitle']) ?></p><?php endif; ?>
    </div>

    <div class="grid g2">
      <?php foreach ($solutions as $s): $img = s_img($s); ?>
        <div class="card rv" style="display:flex;gap:18px;align-items:flex-start">
          <?php if ($img): ?>
            <img src="<?= e($img) ?>" alt="<?= e($s['title']) ?>" loading="lazy"
                 style="width:96px;height:96px;flex:none;border-radius:14px;object-fit:cover">
          <?php elseif ($s['icon']): ?>
            <div style="width:56px;height:56px;flex:none;border-radius:14px;display:grid;place-items:center;font-size:24px;background:linear-gradient(135deg,var(--blue),var(--turq));color:#fff"><?= e($s['icon']) ?></div>
          <?php endif; ?>
          <div style="min-width:0">
            <h3><?= e($s['title']) ?></h3>
            <?php if ($s['body']): ?><p><?= e($s['body']) ?></p><?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<?php if (s_visible('steps') && $steps): ?>
<!-- ═══════════ خطوات العمل ═══════════ -->
<section class="sec" id="steps" style="background:var(--beige);border-radius:clamp(24px,3vw,40px);margin-inline:clamp(10px,2vw,26px)">
  <div class="wrap">
    <?php $sec = s_section('steps'); ?>
    <div class="sec-head rv">
      <div class="eyebrow">HOW IT WORKS</div>
      <h2><?= e($sec['title'] ?: 'إزاي بتشتغل؟') ?></h2>
      <?php if ($sec['subtitle']): ?><p><?= e($sec['subtitle']) ?></p><?php endif; ?>
    </div>
    <div class="grid g3">
      <?php $sfrom = [['-60,26,-3','-2deg'], ['60,30,3','2deg'], ['0,-52,2','-1.5deg'], ['0,58,-2','1.5deg'], ['-50,40,3','2deg'], ['50,-34,-3','-1.5deg']]; ?>
      <?php foreach ($steps as $i => $st): $f = $sfrom[$i % 6]; ?>
        <div class="step card pc" data-from="<?= e($f[0]) ?>" style="--r:<?= e($f[1]) ?>">
          <div class="num"><?= $i + 1 ?></div>
          <?php if ($st['icon']): ?><span class="ico"><?= e($st['icon']) ?></span><?php endif; ?>
          <h3><?= e($st['title']) ?></h3>
          <?php if ($st['body']): ?><p><?= e($st['body']) ?></p><?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<?php if (s_visible('services') && $services): ?>
<!-- ═══════════ الخدمات — تبويبات لاصقة بتتبع التمرير ═══════════ -->
<section class="sec" id="services">
  <div class="wrap">
    <?php $sec = s_section('services'); ?>
    <div class="sec-head rv">
      <div class="eyebrow">SERVICES</div>
      <h2><?= e($sec['title'] ?: 'خدماتنا') ?></h2>
      <?php if ($sec['subtitle']): ?><p><?= e($sec['subtitle']) ?></p><?php endif; ?>
    </div>

    <div class="svc-wrap">
      <div class="svc-grid">
        <!-- العمود اللاصق -->
        <div class="svc-sticky">
          <div class="tabs-row">
            <?php foreach ($services as $i => $sv): ?>
              <button type="button" class="tab<?= $i === 0 ? ' on' : '' ?>" data-tab="<?= $i ?>"><?= e($sv['title']) ?></button>
            <?php endforeach; ?>
          </div>
          <div class="svc-panels">
            <?php foreach ($services as $i => $sv): $img = s_img($sv); ?>
              <div class="st<?= $i === 0 ? ' act' : '' ?>" data-panel="<?= $i ?>">
                <div class="svc-visual cs">
                  <?php if ($img): ?>
                    <img src="<?= e($img) ?>" alt="<?= e($sv['title']) ?>" loading="lazy">
                  <?php else: ?>
                    <div class="svc-fallback" style="--i:<?= $i ?>">
                      <span class="big-ico"><?= e($sv['icon'] ?: '✦') ?></span>
                      <b><?= e($sv['title']) ?></b>
                    </div>
                  <?php endif; ?>
                  <div class="svc-tag">
                    <span>0<?= $i + 1 ?></span>
                    <a href="<?= e($regUrl) ?>" class="btn-pill btn-light" style="padding:9px 16px;font-size:12.5px">جرّبها ←</a>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>

        <!-- علامات التمرير اللي بتبدّل التبويبات -->
        <div class="svc-marks">
          <?php foreach ($services as $i => $sv): ?>
            <div data-mark="<?= $i ?>" style="display:flex;align-items:center;padding-inline:clamp(0px,2vw,20px)">
              <div class="rv">
                <div class="eyebrow" style="margin-bottom:8px">0<?= $i + 1 ?></div>
                <h3 style="margin:0 0 10px;font-size:clamp(20px,2.6vw,30px);font-weight:800"><?= e($sv['title']) ?></h3>
                <?php if ($sv['body']): ?><p style="margin:0;color:var(--dim);max-width:42ch"><?= e($sv['body']) ?></p><?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>
</section>
<?php endif; ?>

<?php if (s_visible('gallery') && $gallery): ?>
<!-- ═══════════ التصميمات — خط متعرج + بطاقات بحركة ═══════════ -->
<section class="sec" id="gallery">
  <div class="wrap">
    <?php $sec = s_section('gallery'); ?>
    <div class="sec-head rv">
      <div class="eyebrow">OUR WORK</div>
      <h2><?= e($sec['title'] ?: 'شغل اتعمل بالمنصة') ?></h2>
      <?php if ($sec['subtitle']): ?><p><?= e($sec['subtitle']) ?></p><?php endif; ?>
    </div>

    <svg class="squig rv" viewBox="0 0 1200 220" style="max-height:120px;margin-bottom:-30px" aria-hidden="true">
      <path d="M20,150 C180,20 320,210 480,110 C640,10 760,200 920,120 C1030,66 1120,140 1190,90"
            fill="none" stroke="var(--turq)" stroke-width="4" stroke-linecap="round"></path>
    </svg>

    <?php $gfrom = [['-50,30,-5','-3deg'], ['50,34,5','3deg'], ['0,-56,3','-2deg'], ['0,64,-3','2deg']]; ?>
    <div class="gal">
      <?php foreach ($gallery as $i => $g): $img = s_img($g); if (!$img) continue; $f = $gfrom[$i % 4]; ?>
        <figure class="pc cs" data-from="<?= e($f[0]) ?>" style="--r:<?= e($f[1]) ?>">
          <img src="<?= e($img) ?>" alt="<?= e($g['title'] ?: 'تصميم') ?>" loading="lazy" data-lb="<?= e($img) ?>" style="cursor:zoom-in">
          <?php if ($g['title']): ?><figcaption><?= e($g['title']) ?></figcaption><?php endif; ?>
        </figure>
      <?php endforeach; ?>
    </div>

    <div style="text-align:center;margin-top:38px" class="rv">
      <a href="<?= e(s_page_url('designs')) ?>" class="btn-pill btn-outline btn-lg">شوف كل التصميمات ←</a>
      <a href="<?= e($regUrl) ?>" class="btn-pill btn-blue btn-lg">اعمل تصميمك دلوقتي</a>
    </div>
  </div>
</section>
<?php endif; ?>

<?php if (s_visible('pricing') && $packages): ?>
<!-- ═══════════ الأسعار والعروض ═══════════ -->
<section class="sec" id="pricing">
  <div class="wrap">
    <?php $sec = s_section('pricing'); ?>
    <div class="sec-head rv">
      <div class="eyebrow">PRICING</div>
      <h2><?= e($sec['title'] ?: 'الباقات') ?></h2>
      <?php if ($sec['subtitle']): ?><p><?= e($sec['subtitle']) ?></p><?php endif; ?>
    </div>
    <div class="grid g3">
      <?php foreach ($packages as $pk): $feats = s_lines($pk['features']); ?>
        <div class="price-card rv <?= $pk['is_featured'] ? 'feat' : '' ?>">
          <?php if ($pk['badge']): ?><span class="badge-top"><?= e($pk['badge']) ?></span><?php endif; ?>
          <h3 style="margin:0;font-size:20px"><?= e($pk['name']) ?></h3>
          <?php if ($pk['description']): ?><p style="margin:0;color:var(--dim);font-size:13.5px"><?= e($pk['description']) ?></p><?php endif; ?>
          <div>
            <span class="amount"><?= e($pk['price']) ?></span>
            <?php if ($pk['period']): ?><span class="period"> <?= e($pk['period']) ?></span><?php endif; ?>
          </div>
          <?php if ($feats): ?>
            <ul><?php foreach ($feats as $f): ?><li><?= e($f) ?></li><?php endforeach; ?></ul>
          <?php endif; ?>
          <a href="<?= e($pk['cta_url'] ?: $regUrl) ?>" class="btn-pill <?= $pk['is_featured'] ? 'btn-blue' : 'btn-outline' ?>" style="justify-content:center;margin-top:auto">
            <?= e($pk['cta_text'] ?: 'ابدأ دلوقتي') ?>
          </a>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<?php if (s_visible('cta')): ?>
<!-- ═══════════ جاهز نبدأ ═══════════ -->
<section class="sec" id="cta">
  <div class="wrap">
    <div class="cta-box rv">
      <h2><?= e(s_setting('cta_title', 'جاهز نبدأ؟')) ?></h2>
      <p><?= e(s_setting('cta_body')) ?></p>
      <div class="cta-actions">
        <a href="<?= e($regUrl) ?>" class="btn-pill btn-light btn-lg"><span><?= e(s_setting('cta_btn', 'ابدأ مجانًا')) ?></span><span>←</span></a>
        <a href="<?= e($loginUrl) ?>" class="btn-pill btn-ghost-d btn-lg">عندي حساب</a>
      </div>
    </div>
  </div>
</section>
<?php endif; ?>

<script src="<?= e(s_url('site-assets/js/orb.js')) ?>?v=<?= @filemtime(__DIR__ . '/site-assets/js/orb.js') ?: time() ?>" defer></script>
<script src="<?= e(s_url('site-assets/js/trial.js')) ?>?v=<?= @filemtime(__DIR__ . '/site-assets/js/trial.js') ?: time() ?>" defer></script>
<?php include __DIR__ . '/site/footer.php'; ?>

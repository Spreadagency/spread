<?php
/**
 * قسم «hero» — نفس قسم الرئيسية (بيتعرض في الرئيسية والصفحات الداخلية وبلوكات الـ Page Builder)
 *
 * الشكل الافتراضي: «ابدأ حملتك» — مربع لينك (صفحة فيسبوك · إنستجرام · موقع) ← Spread AI بيقرا اللينك
 * ويعرض اللي اكتشفه عن النشاط ← «سجّل وكمّل» (الحساب الجديد أو الدخول بياخد ?trial= ← المعلومات بتتحفظ في الهوية — trial_claim).
 * القراية والحفظ نفس مسار «اصنع منشورك» (ajax/trial.php action=analyze — من غير كريدت، بحد لكل IP).
 * السلايدر القديم لسه موجود: إعداد الموقع hero_style = slides بيرجّعه.
 */
if (s_setting('hero_style', 'link') !== 'slides'):
    // الترجمة: كل نص ليه نسخة إنجليزي اختيارية في إعدادات الموقع (نفس المفتاح + _en)، وإلا ترجمة النص العربي من القاموس
    $hxTag = __v(s_setting('hero_link_tag', '') ?: (string) ($slides[0]['tag'] ?? '') ?: 'فريق تسويق كامل بالذكاء الاصطناعي', s_setting('hero_link_tag_en'));
    $hxT1 = __v(s_setting('hero_link_title_a', 'ابدأ حملتك'), s_setting('hero_link_title_a_en'));
    $hxT2 = __v(s_setting('hero_link_title_b', 'الإعلانية'), s_setting('hero_link_title_b_en'));
    $hxT3 = __v(s_setting('hero_link_title_c', 'الآن'), s_setting('hero_link_title_c_en'));
    $hxSub = __v(s_setting('hero_link_sub', 'حط لينك صفحتك أو موقعك، وSpread AI هيفهم نشاطك وسوقك وجمهورك ويجهّزلك أول حملة.'), s_setting('hero_link_sub_en'));
?>
  <?= i18n_js(['اعمل حساب مجاني ←', 'Spread AI بيقرا صفحتك…', 'ابدأ حملتك الآن', 'مشكلة في الاتصال — جرّب تاني', 'نشاطك', 'عن النشاط', 'الخدمات', 'الجمهور', 'التواصل', 'المصدر', 'ملاحظة', 'هنكمّل باقي التفاصيل معاك في خطوتين جوه المنصة.', 'لقيت حاجات حلوة عن نشاطك 👇', 'حط لينك صفحتك أو موقعك الأول', 'بقرا صفحتك… لحظة 🔍', 'معرفناش نقرا الصفحة — اكتب اسم نشاطك ومجاله وهنكمّل.', 'اكتبلي اسم نشاطك 👇', 'ابعتلي اللينك 👇', 'حصلت مشكلة — جرّب تاني']) ?>
  <section class="h-hero hx" id="s-hero" data-say="" data-reg="<?= e($regUrl) ?>" data-login="<?= e($loginUrl) ?>" data-on="<?= $trialOn ? '1' : '0' ?>">
    <div class="h-dots" aria-hidden="true"></div>
    <div class="hx-in">
      <span class="ws-chip hx-tag"><i class="sa-pulse"></i><?= e($hxTag) ?></span>
      <h1 class="hx-h"><?= e($hxT1) ?> <span class="hx-grad"><?= e($hxT2) ?></span> <?= e($hxT3) ?></h1>
      <p class="hx-sub"><?= e($hxSub) ?></p>

      <div class="hx-stage" aria-hidden="true">
        <div class="hx-glow"></div>
        <div class="h-shadow" id="h-shadow"></div>
        <div class="h-bot ws-bot hx-bot" id="h-bot">
          <?php foreach (['wave', 'think', 'sit'] as $b): ?>
            <img src="<?= e($img('bot-' . $b . '.webp')) ?>" alt="" data-b="<?= $b ?>" class="<?= $b === 'wave' ? 'on' : '' ?>" width="380" height="591" <?= $b === 'wave' ? 'fetchpriority="high"' : 'loading="lazy"' ?>>
          <?php endforeach; ?>
        </div>
        <span class="hx-bubble" id="hx-bubble"><?= e(__('ابعتلي اللينك 👇')) ?></span>
      </div>

      <div class="hx-card" id="hx-card">
        <!-- ① اللينك -->
        <form class="hx-form" id="hx-form" action="<?= e($regUrl) ?>" method="get" novalidate data-v="link">
          <div class="hx-row">
            <label class="hx-url">
              <span class="sr-only"><?= e(__('لينك صفحتك أو موقعك')) ?></span>
              <span class="hx-url-ic" aria-hidden="true"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.5.5l3-3a5 5 0 0 0-7-7l-1.7 1.7"/><path d="M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7 7l1.7-1.7"/></svg></span>
              <input id="hx-url" type="text" inputmode="url" dir="ltr" maxlength="500" autocomplete="url" placeholder="<?= e(__('حط لينك صفحتك أو موقعك هنا')) ?>">
            </label>
            <button type="submit" class="ws-btn ws-pri hx-go" id="hx-go"><span><?= e(__('ابدأ حملتك الآن')) ?></span><?= s_icon('sparkle', 18, 2) ?></button>
          </div>
          <div class="hx-manual" id="hx-manual" hidden>
            <p class="hx-note" id="hx-manual-note"></p>
            <div class="hx-two">
              <input id="hx-biz" class="ws-input" maxlength="200" placeholder="<?= e(__('اسم النشاط — مثلًا: عيادة د. منى')) ?>" autocomplete="organization" aria-label="<?= e(__('اسم النشاط')) ?>">
              <input id="hx-ind" class="ws-input" maxlength="150" placeholder="<?= e(__('المجال (اختياري) — مثلًا: أسنان')) ?>" aria-label="<?= e(__('المجال')) ?>">
            </div>
          </div>
          <p class="hx-msg" id="hx-msg" role="alert" hidden></p>
          <div class="hx-try"><span><?= e(__('جرّب بـ:')) ?></span>
            <button type="button" data-ex="https://www.facebook.com/"><?= e(__('صفحة فيسبوك')) ?></button>
            <button type="button" data-ex="https://www.instagram.com/"><?= e(__('إنستجرام')) ?></button>
            <button type="button" data-ex="https://"><?= e(__('موقعك')) ?></button>
          </div>
        </form>

        <!-- ② اللي Spread AI اكتشفه -->
        <div class="hx-found" id="hx-found" data-v="found" hidden aria-live="polite">
          <div class="hx-found-top">
            <span class="hx-av" id="hx-av" aria-hidden="true"></span>
            <div class="hx-id"><b id="hx-name"></b><small id="hx-indus"></small></div>
            <span class="hx-ok"><?= s_icon('check', 15, 2.6) ?><?= e(__('اتعرّفنا عليه')) ?></span>
          </div>
          <ul class="hx-facts" id="hx-facts"></ul>
          <p class="hx-save"><?= s_icon('sparkle', 15, 2) ?><?= e(__('المعلومات دي هتتحفظ في هويتك أول ما تسجّل — وتقدر تعدّلها بعدين.')) ?></p>
          <div class="hx-acts">
            <a class="ws-btn ws-pri" id="hx-reg" href="<?= e($regUrl) ?>"><span><?= e(__('سجّل وكمّل حملتك')) ?></span><?= s_icon('arrow', 18, 2.2) ?></a>
            <a class="hx-login" id="hx-login" href="<?= e($loginUrl) ?>"><?= e(__('عندي حساب — تسجيل الدخول')) ?></a>
            <button type="button" class="hx-again" id="hx-again"><?= e(__('لينك تاني')) ?></button>
          </div>
        </div>
      </div>

      <ul class="hx-ticks">
        <li><?= s_icon('check', 15, 2.6) ?><?= e(__('من غير كارت')) ?></li>
        <li><?= s_icon('check', 15, 2.6) ?><?= e(__('أقل من دقيقتين')) ?></li>
        <li><?= s_icon('check', 15, 2.6) ?><?= e(__('بالعربي وبلهجتك')) ?></li>
      </ul>
    </div>
  </section>
<?php return; endif; ?>
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
          <button type="button" class="ws-glass h-arrow" data-slide="prev" aria-label="<?= e(__('الشريحة السابقة')) ?>"><?= s_icon('arrow-r', 18, 2) ?></button>
          <div class="h-dotsnav">
            <?php foreach ($slides as $i => $sl): ?><button type="button" data-go="<?= $i ?>" aria-label="الشريحة <?= $i + 1 ?>" aria-current="<?= $i === 0 ? 'true' : 'false' ?>"><span></span></button><?php endforeach; ?>
          </div>
          <button type="button" class="ws-glass h-arrow" data-slide="next" aria-label="<?= e(__('الشريحة التالية')) ?>"><?= s_icon('arrow', 18, 2) ?></button>
          <span class="h-no" dir="ltr"><span id="h-no">01</span> / <?= str_pad((string) count($slides), 2, '0', STR_PAD_LEFT) ?></span>
        </div>
        <?php endif; ?>
        <div class="h-counters" id="h-counters">
          <div class="ws-glass"><b data-to="4">4</b><span><?= e(__('أفكار في كل طلب')) ?></span></div>
          <div class="ws-glass"><b data-to="6">6</b><span><?= e(__('أنواع تصميم')) ?></span></div>
          <div class="ws-glass"><b data-to="<?= max(2, count($steps) ?: 7) ?>"><?= max(2, count($steps) ?: 7) ?></b><span><?= e(__('خطوات من الفكرة للنشر')) ?></span></div>
        </div>
        <span class="h-hint"><span class="ws-bob"><?= s_icon('mouse', 16, 1.6) ?></span><?= e(__('حرّك الماوس أو انزل — الروبوت بيتابعك')) ?></span>
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
        <div class="h-float f1 ws-glass ws-float"><i><?= s_icon('pen', 18) ?></i><span><b><?= e(__('كابشن جاهز')) ?></b><small><?= e(__('بصوت براندك')) ?></small></span></div>
        <div class="h-float f2 ws-glass ws-float"><i><?= s_icon('image', 18) ?></i><span><b><?= e(__('تصميم 1:1')) ?></b><small><?= e(__('بألوان هويتك')) ?></small></span></div>
        <div class="h-float f3 ws-glass ws-float"><i><?= s_icon('calendar', 18) ?></i><span><b><?= e(__('اتجدول للنشر')) ?></b><small>Instagram · Facebook</small></span></div>
      </div>
    </div>
  </section>

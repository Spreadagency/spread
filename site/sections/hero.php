<?php
/** قسم «hero» — نفس قسم الرئيسية (بيتعرض في الرئيسية والصفحات الداخلية وبلوكات الـ Page Builder) */
?>
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

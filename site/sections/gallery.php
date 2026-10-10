<?php
/** قسم «gallery» — نفس قسم الرئيسية (بيتعرض في الرئيسية والصفحات الداخلية وبلوكات الـ Page Builder) */
if (!$gItems) return;
?>
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

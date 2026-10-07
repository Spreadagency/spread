<?php
/** قسم «about» — نفس قسم الرئيسية (بيتعرض في الرئيسية والصفحات الداخلية وبلوكات الـ Page Builder) */
if (!$solutions) return;
?>
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

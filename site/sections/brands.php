<?php
/** قسم «brands» — نفس قسم الرئيسية (بيتعرض في الرئيسية والصفحات الداخلية وبلوكات الـ Page Builder) */
if (!$brands) return;
?>
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

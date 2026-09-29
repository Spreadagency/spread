<?php
/** جزء صفحة الأسعار */
$packages = s_all('SELECT * FROM site_packages WHERE is_active = 1 ORDER BY sort_order, id');
$promos   = s_all('SELECT * FROM site_promos WHERE is_active = 1
                   AND (starts_at IS NULL OR starts_at <= CURDATE())
                   AND (ends_at IS NULL OR ends_at >= CURDATE()) ORDER BY sort_order, id LIMIT 2');
?>
<section class="sec" style="padding-top:0">
  <div class="wrap">
    <?php if ($promos): ?>
      <div class="grid g2" style="margin-bottom:40px">
        <?php foreach ($promos as $p): $img = s_img($p); $tag = $p['link_url'] ? 'a' : 'div'; ?>
          <<?= $tag ?> class="promo rv" <?= $p['link_url'] ? 'href="' . e($p['link_url']) . '"' : '' ?>>
            <?php if ($img): ?><img src="<?= e($img) ?>" alt="<?= e($p['title']) ?>" loading="lazy"><?php endif; ?>
            <div class="ov"><b><?= e($p['title']) ?></b><?php if ($p['body']): ?><p><?= e($p['body']) ?></p><?php endif; ?></div>
          </<?= $tag ?>>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <div class="grid g3">
      <?php $pf = [['-56,28,-3','-2deg'], ['0,-50,2','0deg'], ['56,32,3','2deg']]; ?>
      <?php foreach ($packages as $pi => $pk): $feats = s_lines($pk['features']); $f = $pf[$pi % 3]; ?>
        <div class="price-card pc <?= $pk['is_featured'] ? 'feat' : '' ?>" data-from="<?= e($f[0]) ?>" style="--r:<?= e($f[1]) ?>">
          <?php if ($pk['badge']): ?><span class="badge-top"><?= e($pk['badge']) ?></span><?php endif; ?>
          <h3 style="margin:0;font-size:20px"><?= e($pk['name']) ?></h3>
          <?php if ($pk['description']): ?><p style="margin:0;color:var(--dim);font-size:13.5px"><?= e($pk['description']) ?></p><?php endif; ?>
          <div><span class="amount"><?= e($pk['price']) ?></span><?php if ($pk['period']): ?><span class="period"> <?= e($pk['period']) ?></span><?php endif; ?></div>
          <?php if ($feats): ?><ul><?php foreach ($feats as $f): ?><li><?= e($f) ?></li><?php endforeach; ?></ul><?php endif; ?>
          <a href="<?= e($pk['cta_url'] ?: $regUrl) ?>" class="btn-pill <?= $pk['is_featured'] ? 'btn-blue' : 'btn-outline' ?>" style="justify-content:center;margin-top:auto"><?= e($pk['cta_text'] ?: 'ابدأ دلوقتي') ?></a>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

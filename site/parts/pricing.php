<?php
/** جزء صفحة الأسعار — نفس باقات المنصة اللي في الرئيسية (نظام الدفع الجديد)، وباقات الموقع احتياطي */
require_once dirname(__DIR__) . '/home.php';
$packages = s_home_packages();
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
      <?php foreach ($packages as $pi => $pk): $feats = $pk['features']; $f = $pf[$pi % 3]; ?>
        <div class="price-card pc <?= $pk['featured'] ? 'feat' : '' ?>" data-from="<?= e($f[0]) ?>" style="--r:<?= e($f[1]) ?>">
          <?php if ($pk['badge']): ?><span class="badge-top"><?= e($pk['badge']) ?></span><?php endif; ?>
          <h3 style="margin:0;font-size:20px"><?= e($pk['name']) ?></h3>
          <?php if ($pk['desc']): ?><p style="margin:0;color:var(--dim);font-size:13.5px"><?= e($pk['desc']) ?></p><?php endif; ?>
          <div><span class="amount"><?= e(is_numeric($pk['price']) ? number_format((float) $pk['price']) . ' ج.م' : $pk['price']) ?></span><?php if ($pk['period']): ?><span class="period"> <?= e($pk['period']) ?></span><?php endif; ?></div>
          <?php if ($pk['chip']): ?><p style="margin:0;font-size:13px;font-weight:600"><?= e($pk['chip']) ?></p><?php endif; ?>
          <?php if ($feats): ?><ul><?php foreach ($feats as $ft): ?><li><?= e($ft) ?></li><?php endforeach; ?></ul><?php endif; ?>
          <?php if ($pk['bonus']): ?><p style="margin:0;font-size:13px">🎁 +<?= (int) $pk['bonus'] ?> كريدت هدية</p><?php endif; ?>
          <a href="<?= e($pk['url']) ?>" class="btn-pill <?= $pk['featured'] ? 'btn-blue' : 'btn-outline' ?>" style="justify-content:center;margin-top:auto">اشترك</a>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

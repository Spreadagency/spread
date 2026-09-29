<?php
/** جزء صفحة احنا مين */
$solutions = s_all('SELECT * FROM site_solutions WHERE is_active = 1 ORDER BY sort_order, id');
$steps     = s_all('SELECT * FROM site_steps WHERE is_active = 1 ORDER BY sort_order, id');
$brands    = s_all('SELECT * FROM site_brands WHERE is_active = 1 ORDER BY sort_order, id');
?>
<?php if ($solutions): ?>
<section class="sec" style="padding-top:0">
  <div class="wrap">
    <div class="sec-head rv"><h2>ليه Spread AI؟</h2></div>
    <div class="grid g2">
      <?php $af = [['-60,26,-3','-2deg'], ['60,30,3','2deg'], ['0,-52,2','-1.5deg'], ['0,58,-2','1.5deg'], ['-50,40,3','2deg'], ['50,-34,-3','-1.5deg']]; ?>
      <?php foreach ($solutions as $ai => $s): $img = s_img($s); $f = $af[$ai % 6]; ?>
        <div class="card pc" data-from="<?= e($f[0]) ?>" style="--r:<?= e($f[1]) ?>;display:flex;gap:18px;align-items:flex-start">
          <?php if ($img): ?>
            <img src="<?= e($img) ?>" alt="<?= e($s['title']) ?>" loading="lazy" style="width:96px;height:96px;flex:none;border-radius:14px;object-fit:cover">
          <?php elseif ($s['icon']): ?>
            <div style="width:56px;height:56px;flex:none;border-radius:14px;display:grid;place-items:center;font-size:24px;background:linear-gradient(135deg,var(--blue),var(--turq));color:#fff"><?= e($s['icon']) ?></div>
          <?php endif; ?>
          <div style="min-width:0"><h3><?= e($s['title']) ?></h3><?php if ($s['body']): ?><p><?= e($s['body']) ?></p><?php endif; ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<?php if ($steps): ?>
<section class="sec" style="background:var(--beige);border-radius:clamp(24px,3vw,40px);margin-inline:clamp(10px,2vw,26px)">
  <div class="wrap">
    <div class="sec-head rv"><h2>رحلتك معانا</h2></div>
    <div class="grid g3">
      <?php $stf = [['-60,26,-3','-2deg'], ['60,30,3','2deg'], ['0,-52,2','-1.5deg'], ['0,58,-2','1.5deg'], ['-50,40,3','2deg'], ['50,-34,-3','-1.5deg']]; ?>
      <?php foreach ($steps as $i => $st): $f = $stf[$i % 6]; ?>
        <div class="step card pc" data-from="<?= e($f[0]) ?>" style="--r:<?= e($f[1]) ?>">
          <div class="num"><?= $i + 1 ?></div>
          <h3><?= e($st['title']) ?></h3>
          <?php if ($st['body']): ?><p><?= e($st['body']) ?></p><?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<?php if ($brands): ?>
<section class="sec">
  <div class="wrap">
    <div class="sec-head rv"><h2>بيثقوا فينا</h2></div>
    <div class="brands-row rv">
      <?php foreach ($brands as $b): $lg = s_img($b, 'logo_path', 'logo_url'); ?>
        <?php if ($lg): ?><img class="b" src="<?= e($lg) ?>" alt="<?= e($b['name']) ?>" loading="lazy">
        <?php else: ?><span class="brand-name"><?= e($b['name']) ?></span><?php endif; ?>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

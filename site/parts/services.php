<?php
/** جزء صفحة الخدمات */
$services = s_all('SELECT * FROM site_services WHERE is_active = 1 ORDER BY sort_order, id');
$steps    = s_all('SELECT * FROM site_steps WHERE is_active = 1 ORDER BY sort_order, id');
?>
<section class="sec" style="padding-top:0">
  <div class="wrap">
    <div class="grid g3">
      <?php $sf = [['-60,26,-3','-2deg'], ['60,30,3','2deg'], ['0,-52,2','-1.5deg'], ['0,58,-2','1.5deg'], ['-50,40,3','2deg'], ['50,-34,-3','-1.5deg']]; ?>
      <?php foreach ($services as $si => $sv): $img = s_img($sv); $f = $sf[$si % 6]; ?>
        <div class="card pc cs" data-from="<?= e($f[0]) ?>" style="--r:<?= e($f[1]) ?>">
          <?php if ($img): ?>
            <img src="<?= e($img) ?>" alt="<?= e($sv['title']) ?>" loading="lazy" style="width:100%;height:160px;object-fit:cover;border-radius:14px;margin-bottom:14px">
          <?php elseif ($sv['icon']): ?><span class="ico"><?= e($sv['icon']) ?></span><?php endif; ?>
          <h3><?= e($sv['title']) ?></h3>
          <?php if ($sv['body']): ?><p><?= e($sv['body']) ?></p><?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<?php if ($steps): ?>
<section class="sec" style="background:var(--beige);border-radius:clamp(24px,3vw,40px);margin-inline:clamp(10px,2vw,26px)">
  <div class="wrap">
    <div class="sec-head rv"><h2>إزاي بنشتغل</h2></div>
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

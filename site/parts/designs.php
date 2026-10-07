<?php
/** جزء صفحة التصميمات */
$cats = s_all("SELECT DISTINCT category FROM site_gallery WHERE is_active = 1 AND category IS NOT NULL AND category != '' ORDER BY category");
$activeCat = trim((string) ($_GET['c'] ?? ''));
if ($activeCat !== '') {
    $items = s_all('SELECT * FROM site_gallery WHERE is_active = 1 AND category = ? ORDER BY sort_order, id', [$activeCat]);
} else {
    $items = s_all('SELECT * FROM site_gallery WHERE is_active = 1 ORDER BY sort_order, id');
}
?>
<section class="sec" style="padding-top:0">
  <div class="wrap">
    <?php if ($cats): ?>
      <div style="display:flex;gap:8px;flex-wrap:wrap;justify-content:center;margin-bottom:30px" class="rv">
        <a href="?p=designs" class="btn-pill <?= $activeCat === '' ? 'btn-blue' : 'btn-outline' ?>">الكل</a>
        <?php foreach ($cats as $c): ?>
          <a href="?p=designs&c=<?= urlencode($c['category']) ?>" class="btn-pill <?= $activeCat === $c['category'] ? 'btn-blue' : 'btn-outline' ?>"><?= e($c['category']) ?></a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if ($items): ?>
      <div class="gal">
        <?php $gf = [['-50,30,-5','-3deg'], ['50,34,5','3deg'], ['0,-56,3','-2deg'], ['0,64,-3','2deg']]; ?>
        <?php foreach ($items as $gi => $g): $img = s_img($g); if (!$img) continue; $f = $gf[$gi % 4]; ?>
          <figure class="pc cs" data-from="<?= e($f[0]) ?>" style="--r:<?= e($f[1]) ?>">
            <img src="<?= e($img) ?>" alt="<?= e($g['title'] ?: 'تصميم') ?>" loading="lazy" data-lb="<?= e($img) ?>" style="cursor:zoom-in">
            <?php if ($g['title']): ?><figcaption><?= e($g['title']) ?></figcaption><?php endif; ?>
          </figure>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <p style="text-align:center;color:var(--dim)">لسه مفيش تصميمات معروضة.</p>
    <?php endif; ?>
  </div>
</section>

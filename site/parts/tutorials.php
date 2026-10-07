<?php
/** جزء صفحة الشرح — فيديوهات */
$videos = s_all('SELECT * FROM site_videos WHERE is_active = 1 ORDER BY sort_order, id');
?>
<section class="sec" style="padding-top:0">
  <div class="wrap">
    <?php if ($videos): ?>
      <div class="grid g2">
        <?php $vf = [['-56,26,-3','-2deg'], ['56,30,3','2deg'], ['0,-50,2','-1.5deg'], ['0,56,-2','1.5deg']]; ?>
        <?php foreach ($videos as $vi => $v): $thumb = s_img($v, 'thumb_path', 'thumb_url'); $f = $vf[$vi % 4]; ?>
          <div class="card pc" data-from="<?= e($f[0]) ?>" style="--r:<?= e($f[1]) ?>;padding:0;overflow:hidden">
            <div class="vid">
              <iframe src="<?= e(s_video_embed($v['video_url'])) ?>" title="<?= e($v['title']) ?>"
                      loading="lazy" allowfullscreen
                      allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"></iframe>
            </div>
            <div style="padding:18px 20px">
              <?php if ($v['category']): ?>
                <div class="eyebrow" style="margin-bottom:6px"><?= e($v['category']) ?></div>
              <?php endif; ?>
              <h3 style="margin:0 0 6px;font-size:18px"><?= e($v['title']) ?></h3>
              <?php if ($v['description']): ?><p style="margin:0;color:var(--dim);font-size:14px"><?= e($v['description']) ?></p><?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <p style="text-align:center;color:var(--dim)">فيديوهات الشرح هتتضاف قريبًا.</p>
    <?php endif; ?>
  </div>
</section>

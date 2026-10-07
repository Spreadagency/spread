<?php
/** صفحة «اصنع منشورك الآن» الداخلية */
?>
<section class="sec" style="padding-top:0">
  <div class="wrap">
    <?php include __DIR__ . '/trial-widget.php'; ?>
  </div>
</section>

<section class="sec">
  <div class="wrap">
    <div class="sec-head rv"><h2>وبعدين؟</h2><p>لما تسجّل، كل اللي عملته هنا بيتنقل معاك</p></div>
    <div class="grid g3">
      <?php foreach ([
        ['◈','هويتك اتسجّلت','اسم بيزنسك ومجالك وجمهورك وخدماتك — كلها اتحفظت وهتلاقيها جاهزة في حسابك.'],
        ['✎','منشورك محفوظ','المنشور اللي اتولد هنا بيتنقل لحسابك وتقدر تعدّله وتنشره.'],
        ['🎨','التصميم والنشر','اعمل تصميم بألوان هويتك، واربط صفحتك وانشر تلقائيًا في معاده.'],
      ] as $i => $c): ?>
        <div class="card pc" data-from="<?= ['-56,26,-3','0,-50,2','56,30,3'][$i] ?>" style="--r:<?= ['-2deg','0deg','2deg'][$i] ?>">
          <span class="ico"><?= $c[0] ?></span>
          <h3><?= e($c[1]) ?></h3>
          <p><?= e($c[2]) ?></p>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<script src="<?= e(s_url('site-assets/js/orb.js')) ?>?v=<?= @filemtime(dirname(dirname(__DIR__)) . '/site-assets/js/orb.js') ?: time() ?>" defer></script>
<script src="<?= e(s_url('site-assets/js/trial.js')) ?>?v=<?= @filemtime(dirname(dirname(__DIR__)) . '/site-assets/js/trial.js') ?: time() ?>" defer></script>

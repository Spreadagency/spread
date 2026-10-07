<?php
/** قسم «problems» — نفس قسم الرئيسية (بيتعرض في الرئيسية والصفحات الداخلية وبلوكات الـ Page Builder) */
if (!$problems) return;
?>
  <section class="h-sec h-problems ws-dark" id="s-problems" data-say="عارف الإحساس ده؟" data-look="-1">
    <div class="h-wrap">
      <div class="h-sh rv"><span class="ws-chip"><?= s_icon('x', 15, 2) ?>المشاكل</span><h2 class="ws-h2"><?= e($sec('problems', 0)) ?></h2>
        <?php if ($sec('problems', 1) !== ''): ?><p class="pm"><?= e($sec('problems', 1)) ?></p><?php endif; ?></div>
      <div class="p-grid">
        <?php $rv = ['rv-r', '', 'rv-s', '', 'rv-l']; foreach ($problems as $i => $p): ?>
          <div class="pc rv <?= $rv[$i % 5] ?>" style="transition-delay:<?= ($i % 5) * .1 ?>s">
            <span class="pi"><?= s_icon($p['icon'], 22) ?></span>
            <span class="tx"><b><?= e($p['title']) ?></b><?php if ($p['body']): ?><span class="pm"><?= e($p['body']) ?></span><?php endif; ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </section>

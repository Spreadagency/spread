<?php
/** قسم «ribbon» — نفس قسم الرئيسية (بيتعرض في الرئيسية والصفحات الداخلية وبلوكات الـ Page Builder) */
?>
  <div class="h-marq" aria-label="خدمات <?= e($siteName) ?>">
    <div class="ws-marq">
      <?php for ($r = 0; $r < 2; $r++): foreach ($marquee as $m): ?>
        <span<?= $r ? ' aria-hidden="true"' : '' ?>><span><?= e($m) ?></span><svg width="16" height="16" viewBox="0 0 24 24" fill="#0B1526" aria-hidden="true"><path d="M12 2l2.2 7.8L22 12l-7.8 2.2L12 22l-2.2-7.8L2 12l7.8-2.2z"/></svg></span>
      <?php endforeach; endfor; ?>
    </div>
  </div>

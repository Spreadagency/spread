<?php
/** قسم «pricing» — نفس قسم الرئيسية (بيتعرض في الرئيسية والصفحات الداخلية وبلوكات الـ Page Builder) */
if (!$packages) return;
    $visible = $hasToggle ? array_values(array_filter($packages, fn($p) => !$p['yearly'])) : $packages;
    $anyFeat = count(array_filter($visible, fn($p) => $p['featured'])) > 0;
?>
  <section class="h-sec h-pricing" id="s-pricing" data-say="<?= e(__('اختار اللي يناسبك')) ?>" data-look="1">
    <div class="h-wrap">
      <?= $secHead('pricing', 'الأسعار') ?>
      <?php if ($hasToggle): ?>
        <div class="pr-toggle rv" role="group" aria-label="<?= e(__('مدة الاشتراك')) ?>">
          <button type="button" data-per="m" aria-pressed="true"><?= e(__('شهري')) ?></button>
          <button type="button" data-per="y" aria-pressed="false"><?= e(__('سنوي')) ?> <?php if ($yn = s_setting('pricing_yearly_note')): ?><small><?= e($yn) ?></small><?php endif; ?></button>
        </div>
      <?php endif; ?>
      <div class="pr-grid" id="pr-grid" style="--cols:<?= max(1, min(4, count($visible))) ?>">
        <?php $vi = 0; foreach ($packages as $p):
          $isVis = !$hasToggle || !$p['yearly'];
          $feat = $p['featured'] || (!$anyFeat && $isVis && count($visible) >= 3 && $vi === 1);
          if ($isVis) $vi++;
          $badge = $p['badge'] !== '' ? $p['badge'] : ($p['featured'] ? 'الأكثر اختيارًا' : ''); ?>
          <div class="pr-card rv ws-lift<?= $feat ? ' feat' : '' ?>" data-per="<?= $p['yearly'] ? 'y' : 'm' ?>"<?= $isVis ? '' : ' hidden' ?>>
            <?php if ($badge !== ''): ?><span class="ws-chip pr-badge"><?= e(__($badge)) ?></span><?php endif; ?>
            <span class="hd"><b><?= e(__($p['name'])) ?></b><?php if ($p['desc'] !== ''): ?><span><?= e(__((string) ($p['desc']))) ?></span><?php endif; ?></span>
            <span class="pr-price"><b><?= is_numeric($p['price']) ? e(number_format((float) $p['price'], fmod((float) $p['price'], 1) ? 2 : 0)) : e($p['price']) ?></b><span><?= e(__('ج.م')) ?> <?= e(__((string) ($p['period']))) ?></span></span>
            <?php if ($p['chip'] !== ''): ?><span class="ws-chip cr"><?= e(__((string) ($p['chip']))) ?></span><?php endif; ?>
            <hr>
            <?php if ($p['features']): ?><div class="pr-f"><?php foreach ($p['features'] as $f): ?><span><i><?= s_icon('check', 13, 2.6) ?></i><span<?= preg_match('/\p{Arabic}/u', $f) ? '' : ' dir="ltr"' ?>><?= e(__($f)) ?></span></span><?php endforeach; ?></div><?php endif; ?>
            <?php if ($p['bonus'] > 0): ?><span class="pr-bonus">🎁 +<?= (int) $p['bonus'] ?> <?= e(__('كريدت هدية مع الباقة')) ?></span><?php endif; ?>
            <a class="ws-btn <?= $feat ? 'ws-pri' : 'ws-ghost' ?>" href="<?= e($p['url']) ?>"><?= e(__('اشترك')) ?></a>
          </div>
        <?php endforeach; ?>
      </div>
      <?php if (!empty($packages[0]['platform'])): ?>
        <p class="pr-note rv"><?= e(__('الدفع بإنستاباي أو فودافون كاش — ارفع إيصال التحويل والباقة بتتفعّل بعد المراجعة. عندك كود خصم؟ هتكتبه في صفحة الدفع.')) ?></p>
      <?php endif; ?>
    </div>
  </section>

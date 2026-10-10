<?php
/** قسم «promos» — نفس قسم الرئيسية (بيتعرض في الرئيسية والصفحات الداخلية وبلوكات الـ Page Builder) */
?>
  <section class="h-offers" id="s-offers" data-say="بص على العروض دي 👀" data-look="1">
    <div class="h-wrap">
      <?= $secHead('promos', 'العروض') ?>
      <?php $oc = ($trialOn ? 1 : 0) + count($promos) + (count($promos) < 2 && s_setting('agency_offer_title') !== '' ? 1 : 0); ?>
      <div class="o-grid" style="--oc:<?= max(1, min(3, $oc)) ?>">
        <?php if ($trialOn): ?>
        <div class="o-card o-dark rv rv-r ws-lift">
          <span class="ws-chip"><?= s_icon('gift', 15) ?>للتجربة</span>
          <h3>أول منشور عليك… والحفظ علينا</h3>
          <p>اصنع منشور كامل بتصميمه، وسجّل — الهوية والمنشور بيتحفظوا من غير ما يتخصم أي Credits.</p>
          <a class="ws-btn ws-pri" href="#s-create">جرّب دلوقتي</a>
        </div>
        <?php endif; ?>
        <?php $pi = 0; foreach ($promos as $p): $pi++; $pimg = s_img($p);
          $code = strtoupper(preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($p['promo_code'] ?? '')));
          $href = $p['link_url'] ?: ($code !== '' ? s_url('packages.php?code=' . $code) : '#s-pricing');
          if ($code !== '' && $p['link_url'] && str_contains($p['link_url'], 'checkout.php') && !str_contains($p['link_url'], 'code=')) $href .= (str_contains($href, '?') ? '&' : '?') . 'code=' . $code;
          $cls = $pi % 2 ? 'o-light rv' : 'o-tint rv rv-l'; ?>
          <div class="o-card <?= $cls ?> ws-lift" style="transition-delay:<?= $pi * .12 ?>s">
            <?php if ($pimg): ?><span class="o-img" style="background-image:url('<?= e($pimg) ?>')" aria-hidden="true"></span><?php endif; ?>
            <span class="ws-chip"><?= s_icon('sparkle', 15) ?><?= e(($p['badge'] ?? '') ?: 'عرض لفترة محدودة') ?></span>
            <h3><?= e($p['title']) ?></h3>
            <?php if ($p['body']): ?><p><?= e($p['body']) ?></p><?php endif; ?>
            <?php if ($code !== ''): ?><span class="o-code">🏷 <?= e($code) ?></span><?php endif; ?>
            <?php if ($p['ends_at']): ?><span class="o-dates">لحد <?= e(date('j/n/Y', strtotime($p['ends_at']))) ?></span><?php endif; ?>
            <a class="ws-btn ws-ghost" href="<?= e($appUrl($href)) ?>"><?= e(($p['btn_text'] ?? '') ?: 'شوف الباقات') ?></a>
          </div>
        <?php endforeach; ?>
        <?php if (count($promos) < 2 && ($at = s_setting('agency_offer_title')) !== ''): ?>
          <div class="o-card o-tint rv rv-l ws-lift" style="transition-delay:.24s">
            <span class="ws-chip"><?= s_icon('layers', 15) ?>للوكالات والفرق</span>
            <h3><?= e($at) ?></h3>
            <?php if ($ab = s_setting('agency_offer_body')): ?><p><?= e($ab) ?></p><?php endif; ?>
            <a class="ws-btn ws-ghost" href="<?= e($appUrl(s_setting('agency_offer_url', '#s-pricing'))) ?>">اعرف أكتر</a>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </section>

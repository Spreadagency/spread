<?php
/** قسم «steps» — نفس قسم الرئيسية (بيتعرض في الرئيسية والصفحات الداخلية وبلوكات الـ Page Builder) */
if (!$steps) return; $G = s_steps_geometry(count($steps));
?>
  <section class="h-sec h-steps" id="s-steps" data-say="<?= count($steps) ?> خطوات وخلاص" data-look="-1">
    <div class="h-wrap">
      <?= $secHead('steps', 'خطوات العمل') ?>
      <div class="st-desk" id="st-desk">
        <svg viewBox="0 0 1240 420" preserveAspectRatio="none" aria-hidden="true"><defs><linearGradient id="h-sg" x1="1" y1="0" x2="0" y2="0"><stop offset="0" stop-color="#2EE3CC"/><stop offset=".5" stop-color="#0C87EF"/><stop offset="1" stop-color="#9C8CFF"/></linearGradient></defs>
          <path d="<?= $G['desk']['d'] ?>" fill="none" stroke="#E6EDF5" stroke-width="3" stroke-linecap="round" stroke-dasharray="2 10" vector-effect="non-scaling-stroke"/>
          <path class="st-path" d="<?= $G['desk']['d'] ?>" pathLength="1" fill="none" stroke="url(#h-sg)" stroke-width="4" stroke-linecap="round" stroke-dasharray="1 1" style="stroke-dashoffset:1" vector-effect="non-scaling-stroke"/></svg>
        <?php foreach ($steps as $i => $st): [$x, $y] = $G['desk']['pts'][$i]; $up = $y < 200; ?>
          <div class="st-ic rv rv-s" style="left:<?= round($x / 12.4, 3) ?>%;top:<?= round($y / 4.2, 3) ?>%;transition-delay:<?= $i * .08 ?>s"><?= s_icon($st['icon'] ?: ($D['steps'][$i][0] ?? 'sparkle'), 28) ?></div>
          <div class="st-lb rv" style="left:<?= round($x / 12.4, 3) ?>%;<?= $up ? 'bottom:' . round((420 - 48) / 4.2, 3) . '%' : 'top:' . round(364 / 4.2, 3) . '%' ?>;transition-delay:<?= $i * .08 + .1 ?>s">
            <em><?= str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) ?></em><b><?= e($st['title']) ?></b>
          </div>
        <?php endforeach; ?>
      </div>
      <div class="st-mob" id="st-mob" style="height:<?= $G['mob']['h'] ?>px">
        <svg viewBox="0 0 358 <?= $G['mob']['h'] ?>" aria-hidden="true"><defs><linearGradient id="h-sgm" x1="1" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#2EE3CC"/><stop offset=".5" stop-color="#0C87EF"/><stop offset="1" stop-color="#9C8CFF"/></linearGradient></defs>
          <path d="<?= $G['mob']['d'] ?>" fill="none" stroke="#E6EDF5" stroke-width="3" stroke-linecap="round" stroke-dasharray="2 10"/>
          <path class="st-path" d="<?= $G['mob']['d'] ?>" pathLength="1" fill="none" stroke="url(#h-sgm)" stroke-width="4" stroke-linecap="round" stroke-dasharray="1 1" style="stroke-dashoffset:1"/></svg>
        <?php foreach ($steps as $i => $st): [$x, $y] = $G['mob']['pts'][$i]; ?>
          <div class="st-ic rv rv-s" style="left:<?= $x ?>px;top:<?= $y ?>px;transition-delay:<?= $i * .05 ?>s"><?= s_icon($st['icon'] ?: ($D['steps'][$i][0] ?? 'sparkle'), 22) ?></div>
          <div class="st-lb2 rv rv-r" style="top:<?= $y - 22 ?>px;transition-delay:<?= $i * .05 ?>s"><em><?= str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) ?></em><b><?= e($st['title']) ?></b><?php if (!empty($st['body'])): ?><small><?= e($st['body']) ?></small><?php endif; ?></div>
        <?php endforeach; ?>
      </div>
    </div>
  </section>

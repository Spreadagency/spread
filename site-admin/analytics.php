<?php
/** التحليلات — الزيارات (عدّاد الموقع) + قمع «اصنع منشورك» والتسجيلات والاشتراكات والإيرادات (من المنصة) */
require_once __DIR__ . '/metrics.php';
sa_require_perm('analytics');

$days = in_array((int) ($_GET['days'] ?? 30), [7, 30, 90], true) ? (int) $_GET['days'] : 30;
$M = sm_metrics($days);
$from = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));
$top = s_all('SELECT path, SUM(views) v FROM site_pageviews WHERE day >= ? GROUP BY path ORDER BY v DESC LIMIT 12', [$from]);
$topMax = $top ? max(array_map(fn($r) => (int) $r['v'], $top)) : 1;
$rate = function (?float $a, ?float $b): string { return ($a === null || $b === null || $b <= 0) ? '—' : round($a / $b * 100, 1) . '%'; };

$__t = 'التحليلات';
include __DIR__ . '/layout.php';
?>
<div class="ad-ph sa-in">
  <div class="ad-ph-t"><span class="ad-ph-ic"><?= sa_icon('chart', 22) ?></span><div><h1 class="ad-h1">التحليلات</h1><span class="ad-en" dir="ltr">Analytics</span></div></div>
  <nav class="ad-seg" aria-label="الفترة"><?php foreach ([7 => '7 أيام', 30 => '30 يوم', 90 => '3 شهور'] as $d => $l): ?><a class="ad-tab<?= $d === $days ? ' on' : '' ?>" href="?days=<?= $d ?>"><?= e($l) ?></a><?php endforeach; ?></nav>
</div>
<p class="ad-lead">أرقام حقيقية: الزيارات من عدّاد الموقع الداخلي (من غير كوكيز)، والباقي من قاعدة المنصة. النسبة جنب كل رقم = مقارنة بنفس الفترة اللي قبلها.</p>
<?php if (s_setting('track_views', '1') !== '1'): ?><div class="ad-alert warn"><?= sa_icon('alert', 18) ?><span>عدّاد الزيارات متوقف من الإعدادات ← متقدم.</span></div><?php endif; ?>

<div class="ad-stats">
  <?php foreach (['visits', 'trials', 'posts', 'signups', 'claims', 'subs', 'revenue'] as $k) echo sm_stat_card($k, $M[$k]); ?>
  <div class="ad-stat"><small>Conversion Rate (زيارة ← اشتراك)</small><span class="v"><b><?= e($rate($M['subs'][3], $M['visits'][3])) ?></b></span><span class="ad-hint">تجربة ← تسجيل: <?= e($rate($M['claims'][3], $M['trials'][3])) ?></span></div>
</div>

<section class="ad-card">
  <div class="ad-card-h"><h3>النشاط اليومي</h3></div>
  <div class="ad-chart" style="height:280px"><?= sm_line_chart($M, ['visits', 'trials', 'posts', 'signups', 'claims', 'subs'], $days) ?></div>
  <div class="ad-legend"><?php foreach (['visits', 'trials', 'posts', 'signups', 'claims', 'subs'] as $k): if ($M[$k][3] === null) continue; ?><span><i style="background:<?= e($M[$k][1]) ?>"></i><?= e($M[$k][0]) ?></span><?php endforeach; ?></div>
</section>

<div class="ad-grid ad-g2" style="margin-top:18px">
  <section class="ad-card">
    <div class="ad-card-h"><h3>أكتر الصفحات زيارة</h3></div>
    <?php if (!$top): ?><p class="ad-hint" style="margin:0">لسه مفيش زيارات متسجّلة في الفترة دي.</p><?php endif; ?>
    <div class="ad-funnel">
      <?php foreach ($top as $t): ?>
        <div class="ad-funnel-r"><span dir="ltr" style="text-align:right;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($t['path']) ?></span><span class="ad-meter"><span style="width:<?= max(2, round($t['v'] / $topMax * 100)) ?>%"></span></span><b><?= number_format((int) $t['v']) ?></b></div>
      <?php endforeach; ?>
    </div>
  </section>
  <section class="ad-card">
    <div class="ad-card-h"><h3>قمع «اصنع منشورك»</h3></div>
    <?php $steps = [['visits', 'زيارات'], ['trials', 'ولّدوا أفكار'], ['posts', 'خدوا منشور'], ['claims', 'سجّلوا'], ['subs', 'اشتركوا']]; $mx = max(1, (float) $M['visits'][3], (float) ($M['trials'][3] ?? 0)); ?>
    <div class="ad-funnel">
      <?php foreach ($steps as $i => [$k, $l]): $v = $M[$k][3]; $prev = $i ? $M[$steps[$i - 1][0]][3] : null; ?>
        <div class="ad-funnel-r"><span><?= e($l) ?><?php if ($i): ?> <small class="ad-hint"><?= e($rate($v, $prev)) ?></small><?php endif; ?></span><span class="ad-meter"><span style="width:<?= $v === null ? 0 : max(2, min(100, round($v / $mx * 100))) ?>%"></span></span><b><?= $v === null ? '—' : number_format($v) ?></b></div>
      <?php endforeach; ?>
    </div>
  </section>
</div>
<?php include __DIR__ . '/layout-end.php'; ?>

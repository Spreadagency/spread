<?php
require_once __DIR__ . '/metrics.php';
sa_require();

$days = (int) ($_GET['days'] ?? 30);
if (!in_array($days, [7, 30, 90], true)) $days = 30;
$M = sm_metrics($days);
$counts = [
    ['image', 'تصميمات المعرض', 'site_gallery', 'gallery.php', 'designs'],
    ['quote', 'آراء العملاء', 'site_testimonials', 'testimonials.php', 'testimonials'],
    ['file', 'الصفحات', 'site_pages', 'pages.php', 'pages'],
    ['gift', 'العروض', 'site_promos', 'promos.php', 'offers'],
    ['video', 'الفيديوهات', 'site_videos', 'videos.php', 'videos'],
    ['help', 'الأسئلة الشائعة', 'site_faqs', 'faq.php', 'faq'],
];
$hidden = s_all('SELECT label_ar FROM site_sections WHERE is_visible = 0');
$drafts = (int) (s_one("SELECT COUNT(*) c FROM site_pages WHERE status = 'draft'")['c'] ?? 0);
$feed = sa_can('activity') ? s_all('SELECT * FROM site_activity_log WHERE action NOT IN ("login","logout") ORDER BY id DESC LIMIT 6') : [];
$hour = (int) date('G');
$greet = $hour < 12 ? 'صباح الخير' : ($hour < 18 ? 'مساء النور' : 'مساء الخير');

$__t = 'لوحة التحكم';
include __DIR__ . '/layout.php';
?>
<div class="ad-ph sa-in">
  <div><h1 class="ad-h1"><?= e($greet) ?>، <?= e(explode(' ', (string) $__me['name'])[0]) ?> 👋</h1>
    <p class="ad-lead" style="margin:4px 0 0">نظرة عامة على الموقع · آخر تحديث: <?= e(date('j/n · g:i')) ?> <?= date('A') === 'AM' ? 'ص' : 'م' ?></p></div>
  <nav class="ad-seg" aria-label="الفترة">
    <?php foreach ([7 => '7 أيام', 30 => '30 يوم', 90 => '3 شهور'] as $d => $l): ?>
      <a class="ad-tab<?= $d === $days ? ' on' : '' ?>" href="?days=<?= $d ?>"<?= $d === $days ? ' aria-current="page"' : '' ?>><?= e($l) ?></a>
    <?php endforeach; ?>
  </nav>
</div>

<?php if (!sm_platform_ok()): ?>
  <div class="ad-alert info" style="margin-top:16px"><?= sa_icon('info', 18) ?><span>أرقام التجربة والتسجيلات والاشتراكات بتيجي من قاعدة المنصة — مش متصلة دلوقتي (راجع <code>includes/config.php</code>). الزيارات بتتعد من الموقع نفسه.</span></div>
<?php endif; ?>

<div class="ad-stats">
  <?= sm_stat_card('visits', $M['visits'], sa_can('analytics') ? 'analytics.php' : '') ?>
  <?= sm_stat_card('trials', $M['trials'], sa_can('leads') ? 'leads.php' : '') ?>
  <?= sm_stat_card('signups', $M['signups']) ?>
  <?= sm_stat_card('subs', $M['subs']) ?>
</div>

<div class="ad-split wide-side">
  <section class="ad-card" aria-labelledby="act-h">
    <div class="ad-card-h"><h3 id="act-h">نشاط الموقع <small class="ad-en" style="display:inline">آخر <?= $days ?> يوم</small></h3>
      <?php if (sa_can('analytics')): ?><a href="analytics.php?days=<?= $days ?>" class="ad-btn ad-soft sm">التحليلات<?= sa_icon('chev-l', 15) ?></a><?php endif; ?></div>
    <div class="ad-chart"><?= sm_line_chart($M, ['visits', 'trials', 'posts', 'signups', 'subs'], $days) ?></div>
    <div class="ad-legend">
      <?php foreach (['visits', 'trials', 'posts', 'signups', 'subs'] as $k): if ($M[$k][3] === null) continue; ?>
        <span><i style="background:<?= e($M[$k][1]) ?>"></i><?= e($M[$k][0]) ?></span>
      <?php endforeach; ?>
    </div>
  </section>

  <div style="display:flex;flex-direction:column;gap:18px">
    <section class="ad-card">
      <div class="ad-card-h"><h3>إجراءات سريعة</h3></div>
      <div class="ad-quick">
        <?php foreach ([['pages', 'page-edit.php', 'إضافة صفحة'], ['offers', 'promos.php?new=1', 'إضافة عرض'], ['testimonials', 'testimonials.php?new=1', 'إضافة رأي'],
                        ['content', 'services.php?new=1', 'إضافة خدمة'], ['designs', 'gallery.php?new=1', 'إضافة تصميم'], ['homepage', 'homepage.php', 'تعديل الرئيسية']] as [$p, $u, $l]):
          if (!sa_can($p)) continue; ?>
          <a href="<?= e($u) ?>"><?= sa_icon('plus', 16, 2.2) ?><?= e($l) ?></a>
        <?php endforeach; ?>
      </div>
    </section>
    <?php if (sa_can('activity')): ?>
    <section class="ad-card">
      <div class="ad-card-h"><h3>آخر النشاطات</h3><a href="activity.php" style="font-size:13px;font-weight:600">الكل</a></div>
      <?php if (!$feed): ?><p class="ad-hint" style="margin:0">لسه مفيش تعديلات — أي تعديل في اللوحة هيظهر هنا.</p><?php endif; ?>
      <div class="ad-feed">
        <?php foreach ($feed as $f): ?>
          <div class="ad-feed-i"><span class="dot"></span><div><b><?= e($f['summary']) ?></b><small><?= e(sa_ago($f['created_at'])) ?> · <?= e($f['admin_name'] ?? '—') ?></small></div></div>
        <?php endforeach; ?>
      </div>
    </section>
    <?php endif; ?>
  </div>
</div>

<section class="ad-card" style="margin-top:18px">
  <div class="ad-card-h"><h3>الـ Funnel باختصار</h3><span class="ad-hint">زيارة ← تجربة ← منشور ← تسجيل ← اشتراك (آخر <?= $days ?> يوم)</span></div>
  <?php $fv = [['visits', 'الزيارات'], ['trials', 'جرّبوا Create Post'], ['posts', 'خدوا منشور'], ['claims', 'سجّلوا من التجربة'], ['subs', 'اشتركوا']];
  $top = max(1, (float) ($M['visits'][3] ?? 0), (float) ($M['trials'][3] ?? 0)); ?>
  <div class="ad-funnel">
    <?php foreach ($fv as [$k, $l]): $v = $M[$k][3]; ?>
      <div class="ad-funnel-r"><span><?= e($l) ?></span><span class="ad-meter"><span style="width:<?= $v === null ? 0 : max(2, min(100, round($v / $top * 100))) ?>%"></span></span><b><?= $v === null ? '—' : number_format($v) ?></b></div>
    <?php endforeach; ?>
  </div>
</section>

<div class="ad-stats" style="grid-template-columns:repeat(auto-fit,minmax(170px,1fr))">
  <?php foreach ($counts as [$ic, $lb, $tbl, $lnk, $perm]): $n = (int) (s_one("SELECT COUNT(*) c FROM `{$tbl}`")['c'] ?? 0); ?>
    <<?= sa_can($perm) ? 'a href="' . e($lnk) . '"' : 'div' ?> class="ad-stat">
      <small style="display:flex;align-items:center;gap:8px"><?= sa_icon($ic, 16) ?><?= e($lb) ?></small>
      <span class="v"><b><?= $n ?></b></span>
    </<?= sa_can($perm) ? 'a' : 'div' ?>>
  <?php endforeach; ?>
</div>

<?php if ($hidden || $drafts): ?>
  <div class="ad-alert warn"><?= sa_icon('alert', 18) ?><span>
    <?php if ($hidden): ?>أقسام مخفية من الرئيسية: <b><?= e(implode('، ', array_column($hidden, 'label_ar'))) ?></b> — <a href="homepage.php">تحكّم فيها</a>. <?php endif; ?>
    <?php if ($drafts): ?>عندك <b><?= $drafts ?></b> صفحة مسودة لسه متنشرتش — <a href="pages.php">راجعها</a>.<?php endif; ?>
  </span></div>
<?php endif; ?>
<?php include __DIR__ . '/layout-end.php'; ?>

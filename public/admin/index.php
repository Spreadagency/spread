<?php
declare(strict_types=1);

/** Dashboard: KPIs, leads per day, campaigns, funnel, API usage, latest leads. */
require dirname(__DIR__, 2) . '/app/admin/bootstrap.php';

$user = admin_require('view');
$range = array_key_exists($_GET['range'] ?? '', RANGE_OPTIONS) ? $_GET['range'] : '30d';
[$from, $to, $days] = range_bounds($range);
$off = tz_offset();

// Previous period of the same length, for the deltas.
$span = strtotime($to . ' UTC') - strtotime($from . ' UTC');
$pFrom = gmdate('Y-m-d H:i:s', strtotime($from . ' UTC') - $span);
$pTo = $from;

function kpis(string $from, string $to): array
{
    return [
        'visitors' => (int) q_value("SELECT COUNT(DISTINCT ip) FROM events WHERE type = 'page_view' AND created_at BETWEEN ? AND ?", [$from, $to]),
        'leads' => (int) q_value('SELECT COUNT(*) FROM leads WHERE created_at BETWEEN ? AND ?', [$from, $to]),
        'generated' => (int) q_value("SELECT COUNT(*) FROM generations WHERE status = 'done' AND completed_at BETWEEN ? AND ?", [$from, $to]),
        'failed' => (int) q_value("SELECT COUNT(*) FROM generations WHERE status IN ('failed','rejected') AND created_at BETWEEN ? AND ?", [$from, $to]),
        'wa' => (int) q_value("SELECT COUNT(*) FROM events WHERE type = 'whatsapp_click' AND created_at BETWEEN ? AND ?", [$from, $to]),
        'shares' => (int) q_value("SELECT COUNT(*) FROM events WHERE type = 'share' AND created_at BETWEEN ? AND ?", [$from, $to]),
    ];
}

/** date(Y-m-d in site tz) => count */
function daily(string $table, string $col, string $where, array $params, string $off, string $count = 'COUNT(*)'): array
{
    $rows = q("SELECT DATE(CONVERT_TZ($col, '+00:00', ?)) d, $count c FROM $table WHERE $where GROUP BY d", array_merge([$off], $params))->fetchAll();
    return array_column($rows, 'c', 'd');
}

$k = kpis($from, $to);
$p = $range === 'all' ? null : kpis($pFrom, $pTo);
$delta = static function (string $key) use ($k, $p): ?array {
    if ($p === null) {
        return null;
    }
    if ($p[$key] < 10) {
        return null; // too little history for a meaningful %
    }
    $d = ($k[$key] - $p[$key]) / $p[$key] * 100;
    return [($d >= 0 ? '+' : '') . number_format($d, 1) . '%', $d >= 0 ? 'up' : 'down'];
};
$conv = $k['visitors'] ? $k['leads'] / $k['visitors'] * 100 : 0;
$waRate = $k['leads'] ? $k['wa'] / $k['leads'] * 100 : 0;

// Daily series (last N days, max 90)
$nDays = min(90, max(1, $days));
$dates = [];
for ($i = $nDays - 1; $i >= 0; $i--) {
    $dates[] = (new DateTimeImmutable("today -$i days"))->format('Y-m-d');
}
$seriesFrom = day_start_utc('today -' . ($nDays - 1) . ' days');
$leadsDaily = daily('leads', 'created_at', 'created_at >= ?', [$seriesFrom], $off);
$visDaily = daily('events', 'created_at', "type = 'page_view' AND created_at >= ?", [$seriesFrom], $off, 'COUNT(DISTINCT ip)');
$genDaily = daily('generations', 'completed_at', "status = 'done' AND completed_at >= ?", [$seriesFrom], $off);
$waDaily = daily('events', 'created_at', "type = 'whatsapp_click' AND created_at >= ?", [$seriesFrom], $off);
$pick = static fn (array $m) => array_map(static fn ($d) => (int) ($m[$d] ?? 0), $dates); // oldest first: drawn right→left (RTL)
$lineSeries = array_map(static fn ($d) => ['d' => (new DateTimeImmutable($d))->format('j/n'), 'v' => (int) ($leadsDaily[$d] ?? 0)], $dates);

// Campaigns
$campaigns = q("SELECT COALESCE(NULLIF(utm_campaign, ''), '—') c, COUNT(*) n FROM leads WHERE created_at BETWEEN ? AND ? GROUP BY c ORDER BY n DESC LIMIT 8", [$from, $to])->fetchAll();
$campMax = max(1, (int) ($campaigns[0]['n'] ?? 1));

// Funnel (distinct people)
$funnel = [
    ['زيارة', $k['visitors']],
    ['ليد', $k['leads']],
    ['رفع صورة', (int) q_value('SELECT COUNT(DISTINCT lead_id) FROM generations WHERE created_at BETWEEN ? AND ?', [$from, $to])],
    ['توليد', (int) q_value("SELECT COUNT(DISTINCT lead_id) FROM generations WHERE status = 'done' AND completed_at BETWEEN ? AND ?", [$from, $to])],
    ['واتساب', (int) q_value("SELECT COUNT(DISTINCT lead_id) FROM events WHERE type = 'whatsapp_click' AND lead_id IS NOT NULL AND created_at BETWEEN ? AND ?", [$from, $to])],
];

// API usage today
$today = day_start_utc();
$api = q_row("SELECT SUM(status = 'done') done, SUM(status = 'failed') failed, SUM(status = 'rejected') rejected, SUM(status = 'processing') processing, AVG(CASE WHEN status = 'done' THEN duration_ms END) avg_ms
              FROM generations WHERE started_at >= ?", [$today]) ?: [];
$used = (int) ($api['done'] ?? 0) + (int) ($api['processing'] ?? 0);
$cap = Settings::int('limit_daily_global', 300);
$geminiReady = Settings::get('gemini_api_key', '') !== '';

$latest = q("SELECT l.*, (SELECT g.status FROM generations g WHERE g.lead_id = l.id ORDER BY g.status = 'done' DESC, g.id DESC LIMIT 1) gen
             FROM leads l ORDER BY l.id DESC LIMIT 10")->fetchAll();

$hour = (int) date('G');
$greet = $hour < 12 ? 'صباح الخير' : 'مساء الخير';

admin_page_start('لوحة التحكم', 'index.php', ['range' => $range]);
?>
<div class="page-head">
  <div><h2 class="greet"><?= e($greet) ?> يا <?= e(first_name((string) $user['name'])) ?></h2><p>ملخص الحملة — <?= e(RANGE_OPTIONS[$range]) ?></p></div>
  <div class="row"><a class="btn btn-secondary" href="../" target="_blank" rel="noopener"><?= ic('globe', 'sm') ?>افتح الصفحة</a><a class="btn btn-primary" href="leads.php?export=1&amp;range=<?= e($range) ?>"><?= ic('excel', 'sm') ?>تصدير المسجلين</a></div>
</div>

<?php if (!$geminiReady && AdminAuth::can('owner')): ?>
<div class="banner"><span class="bi"><?= ic('alert') ?></span><div class="small" style="flex:1"><b style="color:var(--ink)">مفتاح Gemini مش متضاف.</b> الزوار هيقدروا يسجلوا، بس التوليد مش هيشتغل.</div><a class="btn btn-gold btn-sm" href="integrations.php">ضيف المفتاح</a></div>
<?php endif; ?>

<section class="kpis" aria-label="المؤشرات">
<?php
$cards = [
    ['زوار', number_format($k['visitors']), $delta('visitors'), 'eye', '', $pick($visDaily), '#1F73B7', ''],
    ['ليدز', number_format($k['leads']), $delta('leads'), 'users', '', $pick($leadsDaily), '#1F73B7', ''],
    ['صور اتولدت', number_format($k['generated']), $delta('generated'), 'image', '', $pick($genDaily), '#1F73B7', $k['failed'] ? $k['failed'] . ' فشلت / اترفضت' : ''],
    ['ضغطات واتساب', number_format($k['wa']), $delta('wa'), 'wa', 'green', $pick($waDaily), '#137A4B', $k['shares'] ? $k['shares'] . ' مشاركة' : ''],
    ['نسبة التحويل', number_format($conv, 1) . '%', null, 'pct', 'gold', null, '#C9A24B', 'واتساب ÷ ليدز: ' . number_format($waRate, 1) . '%'],
];
foreach ($cards as $i => [$label, $val, $d, $icon, $tone, $ser, $color, $sub]): ?>
  <div class="card kpi <?= $i === 4 ? 'accent' : '' ?>">
    <div class="top"><span class="ico <?= $tone ?>"><?= ic($icon) ?></span><?php if ($d): ?><span class="delta <?= $d[1] ?>"><?= ic($d[1] === 'up' ? 'up' : 'dn', 'sm') ?><span class="ltr"><?= e($d[0]) ?></span></span><?php endif; ?></div>
    <span class="lab"><?= e($label) ?></span><span class="val ltr" style="text-align:right"><?= e($val) ?></span>
    <?= $ser !== null ? spark($ser, $color) : '<span class="small muted">' . e($sub) . '</span>' ?>
    <?php if ($ser !== null && $sub): ?><span class="small muted"><?= e($sub) ?></span><?php endif; ?>
  </div>
<?php endforeach; ?>
</section>

<section class="grid g-2-1">
  <div class="card"><div class="card-h"><div><h2>الليدز في اليوم</h2><p>آخر <?= $nDays ?> يوم</p></div></div>
    <div class="card-b"><div class="chart-box"><?= array_sum(array_column($lineSeries, 'v')) ? svg_line_chart($lineSeries, 'عدد الليدز في اليوم') : empty_block('spark', 'لسه مفيش ليدز', 'أول ما الإعلانات تشتغل الرسم هيظهر هنا.') ?></div></div></div>
  <div class="card"><div class="card-h"><div><h2>القمع (Funnel)</h2><p>أشخاص مميزين — من الزيارة لواتساب</p></div></div>
    <div class="card-b"><div class="funnel">
      <?php foreach ($funnel as $i => [$l, $v]): $pct = $i ? ($funnel[$i - 1][1] ? $v / $funnel[$i - 1][1] * 100 : 0) : 100; ?>
      <div class="fn-row"><span class="lab"><?= e($l) ?></span><span class="fn-bar"><i style="width:<?= max(2, $funnel[0][1] ? min(100, $v / $funnel[0][1] * 100) : 0) ?>%"></i></span><span class="pct"><b class="ltr"><?= number_format($v) ?></b><small class="ltr"><?= round($pct) ?>%</small></span></div>
      <?php endforeach; ?>
    </div></div></div>
</section>

<section class="grid g-1-1">
  <div class="card"><div class="card-h"><div><h2>الليدز حسب الحملة</h2><p>من <span class="ltr mono">utm_campaign</span></p></div></div><div class="card-b">
    <?php if (!$campaigns): ?><?= empty_block('target', 'مفيش بيانات حملات', 'تأكد إن روابط الإعلانات فيها utm_campaign.') ?><?php else: ?>
    <div class="stack" style="gap:14px">
      <?php foreach ($campaigns as $i => $c): ?>
      <a class="stack-8 camp-row" style="gap:6px" href="leads.php?<?= e(http_build_query(['campaign' => $c['c'], 'range' => $range])) ?>"><div class="row between small"><span class="ltr mono" style="color:var(--ink);font-weight:600"><?= e($c['c']) ?></span><span class="muted"><b style="color:var(--ink)"><?= (int) $c['n'] ?></b> ليد</span></div>
      <div class="bar" style="height:12px"><i style="width:<?= round($c['n'] / $campMax * 100) ?>%;<?= $i === 0 ? 'background:var(--gold)' : '' ?>"></i></div></a>
      <?php endforeach; ?>
    </div><?php endif; ?>
  </div></div>
  <div class="card"><div class="card-h"><div><h2>استهلاك الـ API النهارده</h2><p>Gemini · الحد اليومي <?= $cap ?> صورة</p></div><?= $geminiReady ? '<span class="status ok"><i></i>متضاف</span>' : '<span class="status err"><i></i>مش متضاف</span>' ?></div>
    <div class="card-b stack">
      <div class="row between"><span class="ltr" style="font-size:32px;font-weight:700;color:var(--ink)"><?= $used ?> <span class="muted" style="font-size:16px">/ <?= $cap ?></span></span><span class="badge b-gold"><?= $cap ? round($used / $cap * 100) : 0 ?>% من الحد</span></div>
      <div class="bar gold" style="height:12px"><i style="width:<?= min(100, $cap ? round($used / $cap * 100) : 0) ?>%"></i></div>
      <div class="grid g3" style="gap:12px">
        <div class="card mini"><span class="small muted">نجحت</span><b class="ltr" style="color:var(--success)"><?= (int) ($api['done'] ?? 0) ?></b></div>
        <div class="card mini"><span class="small muted">فشلت</span><b class="ltr" style="color:var(--danger)"><?= (int) ($api['failed'] ?? 0) ?></b></div>
        <div class="card mini"><span class="small muted">اترفضت</span><b class="ltr" style="color:var(--warn)"><?= (int) ($api['rejected'] ?? 0) ?></b></div>
      </div>
      <p class="small muted">متوسط وقت التوليد <b class="ltr" style="color:var(--ink)"><?= $api['avg_ms'] ? number_format($api['avg_ms'] / 1000, 1) . 's' : '—' ?></b> · العدّاد بيتصفر 12 بالليل (<?= e(date_default_timezone_get()) ?>)</p>
    </div></div>
</section>

<section class="card">
  <div class="card-h"><div><h2>آخر 10 مسجلين</h2></div><a href="leads.php" class="btn btn-ghost btn-sm">كل المسجلين <?= ic('left', 'sm') ?></a></div>
  <?php if (!$latest): ?>
    <?= empty_block('users', 'لسه مفيش مسجلين', 'أول ما حد يسجل اسمه ورقمه في الصفحة هيظهر هنا على طول.', '<a class="btn btn-secondary" href="../" target="_blank">' . ic('globe', 'sm') . 'جرّب الصفحة بنفسك</a>') ?>
  <?php else: ?>
  <div class="table-wrap"><table class="t"><thead><tr><th>الاسم</th><th>الرقم</th><th class="hide-tab">التاريخ</th><th class="hide-tab">المصدر / الحملة</th><th>الصورة</th><th>الحالة</th></tr></thead><tbody>
  <?php foreach ($latest as $l): ?>
    <tr data-lead="<?= (int) $l['id'] ?>"><td><span class="name"><span class="avatar sm"><?= e(initials($l['name'])) ?></span><?= e($l['name']) ?></span></td><td><?= phone_cell($l['phone'], $l['name']) ?></td><td class="muted hide-tab"><?= e(local_dt($l['created_at'])) ?></td><td class="hide-tab"><span class="src"><span><?= e($l['utm_source'] ?: '—') ?></span><small class="ltr"><?= e($l['utm_campaign'] ?: '') ?></small></span></td><td><?= gen_badge($l['gen']) ?></td><td><?= status_badge($l['status']) ?></td></tr>
  <?php endforeach; ?>
  </tbody></table></div>
  <?php endif; ?>
</section>
<?php
admin_page_end();

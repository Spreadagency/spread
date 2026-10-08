<?php
declare(strict_types=1);

/** المسجلين: table, filters, bulk actions, CSV/Excel export. Row click opens the drawer (lead.php). */
require dirname(__DIR__, 2) . '/app/admin/bootstrap.php';

$user = admin_require('view');

/* ---------------- filters ---------------- */
$f = [
    'q' => trim((string) ($_GET['q'] ?? '')),
    'status' => array_key_exists($_GET['status'] ?? '', STATUS_LABELS) ? $_GET['status'] : '',
    'campaign' => trim((string) ($_GET['campaign'] ?? '')),
    'img' => !empty($_GET['img']) ? '1' : '',
    'wa' => !empty($_GET['wa']) ? '1' : '',
    'range' => array_key_exists($_GET['range'] ?? '', RANGE_OPTIONS) ? $_GET['range'] : 'all',
];

function lead_where(array $f): array
{
    $w = ['1=1'];
    $p = [];
    if ($f['q'] !== '') {
        $digits = preg_replace('/\D/', '', strtr($f['q'], ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']));
        if ($digits !== '' && strlen($digits) >= 3) {
            $w[] = '(l.name LIKE ? OR l.phone LIKE ?)';
            $p[] = '%' . $f['q'] . '%';
            $p[] = '%' . ltrim(preg_replace('/^(0020|20)/', '0', $digits), '') . '%';
        } else {
            $w[] = 'l.name LIKE ?';
            $p[] = '%' . $f['q'] . '%';
        }
    }
    if ($f['status'] !== '') {
        $w[] = 'l.status = ?';
        $p[] = $f['status'];
    }
    if ($f['campaign'] !== '') {
        if ($f['campaign'] === '—') {
            $w[] = "(l.utm_campaign IS NULL OR l.utm_campaign = '')";
        } else {
            $w[] = 'l.utm_campaign = ?';
            $p[] = $f['campaign'];
        }
    }
    if ($f['img']) {
        $w[] = "EXISTS (SELECT 1 FROM generations g WHERE g.lead_id = l.id AND g.status = 'done')";
    }
    if ($f['wa']) {
        $w[] = "EXISTS (SELECT 1 FROM events e WHERE e.lead_id = l.id AND e.type = 'whatsapp_click')";
    }
    if ($f['range'] !== 'all') {
        [$from, $to] = range_bounds($f['range']);
        $w[] = 'l.created_at BETWEEN ? AND ?';
        $p[] = $from;
        $p[] = $to;
    }
    return [implode(' AND ', $w), $p];
}

const LEAD_SELECT = "SELECT l.*,
    (SELECT g.status FROM generations g WHERE g.lead_id = l.id ORDER BY g.status = 'done' DESC, g.id DESC LIMIT 1) gen,
    (SELECT g.share_token FROM generations g WHERE g.lead_id = l.id AND g.status = 'done' ORDER BY g.id DESC LIMIT 1) token,
    EXISTS (SELECT 1 FROM events e WHERE e.lead_id = l.id AND e.type = 'whatsapp_click') wa
  FROM leads l";

/* ---------------- export (GET with filters, or POST bulk with ids) ---------------- */
function export_csv(array $rows): never
{
    admin_log('leads_export', count($rows) . ' rows');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="leads-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel shows Arabic correctly
    fputcsv($out, ['#', 'الاسم', 'الرقم', 'التاريخ', 'المصدر', 'الوسيط', 'الحملة', 'المحتوى', 'الكلمة', 'الصورة', 'ضغط واتساب', 'الحالة', 'ملاحظات', 'لينك النتيجة']);
    foreach ($rows as $l) {
        fputcsv($out, [
            $l['id'], $l['name'], "\t" . $l['phone'], local_dt($l['created_at'], 'Y-m-d H:i'), $l['utm_source'], $l['utm_medium'], $l['utm_campaign'], $l['utm_content'], $l['utm_term'],
            GEN_LABELS[$l['gen'] ?: 'none'] ?? $l['gen'], $l['wa'] ? 'نعم' : 'لا', STATUS_LABELS[$l['status']] ?? $l['status'], $l['notes'], $l['token'] ? share_url($l['token']) : '',
        ]);
    }
    fclose($out);
    exit;
}

if (!empty($_GET['export'])) {
    [$where, $params] = lead_where($f);
    export_csv(q(LEAD_SELECT . " WHERE $where ORDER BY l.id DESC LIMIT 50000", $params)->fetchAll());
}

/* ---------------- bulk actions ---------------- */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = (string) ($_POST['bulk'] ?? '');
    admin_post($action === 'export' ? 'view' : 'edit');
    $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['ids'] ?? [])))));
    if (!$ids) {
        flash('err', 'اختار مسجلين الأول.');
        redirect(back_url('leads.php'));
    }
    $in = implode(',', array_fill(0, count($ids), '?'));
    if ($action === 'export') {
        export_csv(q(LEAD_SELECT . " WHERE l.id IN ($in) ORDER BY l.id DESC", $ids)->fetchAll());
    } elseif (str_starts_with($action, 'status:') && array_key_exists(substr($action, 7), STATUS_LABELS)) {
        $st = substr($action, 7);
        q("UPDATE leads SET status = ?, updated_at = ? WHERE id IN ($in)", array_merge([$st, utc_now()], $ids));
        admin_log('leads_bulk_status', $st . ': ' . implode(',', $ids));
        flash('ok', 'اتغيرت حالة ' . count($ids) . ' مسجلين إلى: ' . STATUS_LABELS[$st]);
    } elseif ($action === 'delete') {
        foreach (q("SELECT original_path, result_path FROM generations WHERE lead_id IN ($in)", $ids)->fetchAll() as $g) {
            ImageService::deleteFile(ORIGINALS_PATH, $g['original_path']);
            if ($g['result_path']) {
                ImageService::deleteFile(RESULTS_PATH, $g['result_path']);
                @unlink(ImageService::ogPath($g['result_path']));
            }
        }
        q("DELETE FROM events WHERE lead_id IN ($in)", $ids);
        q("DELETE FROM leads WHERE id IN ($in)", $ids); // generations cascade
        admin_log('leads_bulk_delete', implode(',', $ids));
        flash('ok', 'اتمسح ' . count($ids) . ' مسجلين وصورهم.');
    }
    redirect(back_url('leads.php'));
}

/* ---------------- list ---------------- */
[$where, $params] = lead_where($f);
$total = (int) q_value("SELECT COUNT(*) FROM leads l WHERE $where", $params);
$per = 25;
$pages = max(1, (int) ceil($total / $per));
$page = max(1, min($pages, (int) ($_GET['page'] ?? 1)));
$rows = q(LEAD_SELECT . " WHERE $where ORDER BY l.id DESC LIMIT $per OFFSET " . (($page - 1) * $per), $params)->fetchAll();
$counts = array_column(q('SELECT status, COUNT(*) n FROM leads GROUP BY status')->fetchAll(), 'n', 'status');
$campaignList = q("SELECT DISTINCT COALESCE(NULLIF(utm_campaign, ''), '—') c FROM leads ORDER BY c")->fetchAll(PDO::FETCH_COLUMN);
$query = array_filter($f, static fn ($v) => $v !== '' && $v !== 'all');
$filtered = (bool) array_diff_key($query, ['range' => 1]) || $f['range'] !== 'all';
$canEdit = AdminAuth::can('edit');
$openId = (int) ($_GET['open'] ?? 0);

admin_page_start('المسجلين', 'leads.php');
?>
<div class="page-head"><div><p><b class="ltr" style="color:var(--ink)"><?= number_format(array_sum($counts)) ?></b> مسجل · <b style="color:var(--primary-700)"><?= (int) ($counts['new'] ?? 0) ?></b> جديد محتاجين تواصل</p></div>
  <a class="btn btn-secondary" href="?<?= e(http_build_query($query + ['export' => 1])) ?>"><?= ic('excel', 'sm') ?>Export Excel</a></div>

<div class="grid status-cards">
  <?php foreach (STATUS_LABELS as $k => $l): $on = $f['status'] === $k; ?>
  <a class="card status-card<?= $on ? ' on' : '' ?>" href="?<?= e(http_build_query(['status' => $on ? null : $k] + array_diff_key($query, ['status' => 1, 'page' => 1]))) ?>"><div class="row between"><?= status_badge($k) ?><b class="ltr"><?= (int) ($counts[$k] ?? 0) ?></b></div></a>
  <?php endforeach; ?>
</div>

<section class="card">
  <form class="toolbar" method="get" id="filters">
    <label class="search"><span class="sr-only">بحث</span><?= ic('search') ?><input name="q" placeholder="ابحث بالاسم أو الرقم" value="<?= e($f['q']) ?>"></label>
    <div class="chips">
      <select class="select fchip<?= $f['status'] ? ' on' : '' ?>" name="status" data-autosubmit aria-label="الحالة"><option value="">الحالة: الكل</option><?php foreach (STATUS_LABELS as $k => $l): ?><option value="<?= $k ?>"<?= $f['status'] === $k ? ' selected' : '' ?>>الحالة: <?= e($l) ?></option><?php endforeach; ?></select>
      <select class="select fchip<?= $f['campaign'] ? ' on' : '' ?>" name="campaign" data-autosubmit aria-label="الحملة"><option value="">الحملة: الكل</option><?php foreach ($campaignList as $c): ?><option value="<?= e($c) ?>"<?= $f['campaign'] === $c ? ' selected' : '' ?>><?= e($c) ?></option><?php endforeach; ?></select>
      <select class="select fchip<?= $f['range'] !== 'all' ? ' on' : '' ?>" name="range" data-autosubmit aria-label="المدة"><?php foreach (RANGE_OPTIONS as $k => $l): ?><option value="<?= $k ?>"<?= $f['range'] === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
      <label class="fchip<?= $f['img'] ? ' on' : '' ?>"><input type="checkbox" name="img" value="1" class="sr-only" data-autosubmit<?= $f['img'] ? ' checked' : '' ?>><?= ic('image', 'sm') ?>عنده صورة</label>
      <label class="fchip<?= $f['wa'] ? ' on' : '' ?>"><input type="checkbox" name="wa" value="1" class="sr-only" data-autosubmit<?= $f['wa'] ? ' checked' : '' ?>><?= ic('wa', 'sm') ?>ضغط واتساب</label>
      <?php if ($filtered || $f['q'] !== ''): ?><a class="btn btn-sm btn-ghost" href="leads.php">مسح الفلاتر</a><?php endif; ?>
    </div>
  </form>

  <form method="post" id="bulkForm">
    <?= csrf_field() ?>
    <div class="bulkbar" id="bulkBar" hidden role="region" aria-label="إجراءات جماعية">
      <b><span id="selCount">0</span> محددين</b>
      <?php if ($canEdit): ?>
      <select class="select bulk-select" name="bulk" id="bulkAction" aria-label="إجراء">
        <option value="">اختار إجراء…</option>
        <?php foreach (STATUS_LABELS as $k => $l): ?><option value="status:<?= $k ?>">غيّر الحالة إلى: <?= e($l) ?></option><?php endforeach; ?>
        <option value="export">Export المحددين</option>
        <option value="delete">امسح المحددين</option>
      </select>
      <button class="btn btn-sm btn-secondary" type="submit" id="bulkGo">تنفيذ</button>
      <?php else: ?>
      <button class="btn btn-sm btn-secondary" type="submit" name="bulk" value="export"><?= ic('excel', 'sm') ?>Export</button>
      <?php endif; ?>
      <span style="flex:1"></span><button class="btn btn-sm btn-ghost" type="button" style="color:#fff" id="bulkClear">إلغاء التحديد</button>
    </div>

  <?php if (!$rows): ?>
    <?= $filtered || $f['q'] !== ''
        ? empty_block('search', 'مفيش نتايج', 'مفيش مسجلين بالفلاتر دي. جرّب تشيل فلتر أو تغيّر كلمة البحث.', '<a class="btn btn-secondary" href="leads.php">مسح الفلاتر</a>')
        : empty_block('users', 'لسه مفيش مسجلين', 'أول ما حد يسجل في الصفحة هيظهر هنا. اتأكد إن الإعلانات شغالة واللينك صح.', '<a class="btn btn-secondary" href="../" target="_blank">' . ic('globe', 'sm') . 'افتح الصفحة</a>') ?>
  <?php else: ?>
    <div class="table-wrap"><table class="t"><thead><tr>
      <th class="w-check"><input type="checkbox" class="cbx" id="selAll" aria-label="حدد الكل"></th>
      <th>الاسم</th><th>الرقم</th><th class="hide-tab">التاريخ</th><th class="hide-tab">المصدر / الحملة</th><th>الصورة</th><th>الحالة</th><th class="hide-tab">ملاحظات</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $l): ?>
        <tr data-lead="<?= (int) $l['id'] ?>">
          <td><input type="checkbox" class="cbx" name="ids[]" value="<?= (int) $l['id'] ?>" data-sel aria-label="حدد <?= e($l['name']) ?>"></td>
          <td><span class="name"><span class="avatar sm"><?= e(initials($l['name'])) ?></span><?= e($l['name']) ?><?= $l['wa'] ? ' <span title="ضغط واتساب" style="color:var(--wa)">' . ic('wa', 'sm') . '</span>' : '' ?></span></td>
          <td><?= phone_cell($l['phone'], $l['name']) ?></td>
          <td class="muted hide-tab"><?= e(local_dt($l['created_at'])) ?></td>
          <td class="hide-tab"><span class="src"><span><?= e($l['utm_source'] ?: 'مباشر') ?></span><small class="ltr"><?= e($l['utm_campaign'] ?: '') ?></small></span></td>
          <td><?= gen_badge($l['gen']) ?></td>
          <td><?= status_badge($l['status']) ?></td>
          <td class="hide-tab"><span class="note" style="display:block"><?= $l['notes'] ? e(mb_strimwidth($l['notes'], 0, 40, '…')) : '<span style="color:var(--muted-2)">—</span>' ?></span></td>
          <td><button type="button" class="btn btn-icon btn-ghost" data-open-lead="<?= (int) $l['id'] ?>" aria-label="تفاصيل"><?= ic('left', 'sm') ?></button></td>
        </tr>
      <?php endforeach; ?>
      </tbody></table></div>
    <div class="pager"><span>عرض <span class="ltr"><?= ($page - 1) * $per + 1 ?>–<?= min($page * $per, $total) ?></span> من <span class="ltr"><?= $total ?></span></span><?= pager($page, $pages, $query) ?></div>
  <?php endif; ?>
  </form>
</section>
<?php if ($openId): ?><span hidden data-autoopen="<?= $openId ?>"></span><?php endif; ?>
<?php
admin_page_end();

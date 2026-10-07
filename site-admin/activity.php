<?php
/** سجل النشاط — كل تعديل في لوحة الموقع بيتسجّل هنا تلقائيًا */
require_once __DIR__ . '/auth.php';
sa_require_perm('activity');

$secLabels = sa_perm_sections() + ['system' => 'النظام'];
$actLabels = sa_action_labels();
$per = 40;
$page = max(1, (int) ($_GET['page'] ?? 1));
$f = ['section' => (string) ($_GET['section'] ?? ''), 'action' => (string) ($_GET['action'] ?? ''), 'admin' => (int) ($_GET['admin'] ?? 0), 'q' => trim((string) ($_GET['q'] ?? ''))];
$w = []; $p = [];
if (isset($secLabels[$f['section']])) { $w[] = 'section = ?'; $p[] = $f['section']; }
if (isset($actLabels[$f['action']])) { $w[] = 'action = ?'; $p[] = $f['action']; }
if ($f['admin']) { $w[] = 'admin_id = ?'; $p[] = $f['admin']; }
if ($f['q'] !== '') { $w[] = 'summary LIKE ?'; $p[] = '%' . $f['q'] . '%'; }
$where = $w ? 'WHERE ' . implode(' AND ', $w) : '';
$total = (int) (s_one("SELECT COUNT(*) c FROM site_activity_log {$where}", $p)['c'] ?? 0);
$pages = max(1, (int) ceil($total / $per));
$rows = s_all("SELECT * FROM site_activity_log {$where} ORDER BY id DESC LIMIT {$per} OFFSET " . (($page - 1) * $per), $p);
$admins = s_all('SELECT id, name FROM site_admins ORDER BY name');
$tone = ['create' => 'ok', 'update' => 'info', 'delete' => 'danger', 'toggle' => 'warn', 'reorder' => 'off', 'publish' => 'ok', 'unpublish' => 'warn',
         'duplicate' => 'info', 'settings' => 'violet', 'login' => 'off', 'logout' => 'off', 'upload' => 'ok', 'role' => 'violet'];

$__t = 'سجل النشاط';
include __DIR__ . '/layout.php';
echo sa_page_head('list', 'سجل النشاط', 'Activity Log', 'كل تعديل في اللوحة بيتسجّل هنا تلقائيًا — مين عمل إيه وإمتى.');
$sel = function (string $name, array $opts, $cur, string $all): string {
    $h = '<select class="ad-filter" name="' . $name . '" onchange="this.form.submit()" aria-label="' . e($all) . '"><option value="">' . e($all) . '</option>';
    foreach ($opts as $k => $l) $h .= '<option value="' . e((string) $k) . '"' . ((string) $cur === (string) $k ? ' selected' : '') . '>' . e($l) . '</option>';
    return $h . '</select>';
};
?>
<form class="ad-bar" method="GET">
  <div class="ad-search"><span><?= sa_icon('search', 17) ?></span><input type="search" name="q" value="<?= e($f['q']) ?>" placeholder="ابحث في السجل..." aria-label="ابحث في السجل"></div>
  <?= $sel('section', $secLabels, $f['section'], 'كل الأقسام') ?>
  <?= $sel('action', $actLabels, $f['action'], 'كل الإجراءات') ?>
  <?= $sel('admin', array_column($admins, 'name', 'id'), $f['admin'] ?: '', 'كل المستخدمين') ?>
  <span class="ad-count"><b><?= $total ?></b> عملية</span>
</form>
<?php if (!$rows): ?>
  <?= sa_empty('list', 'مفيش عمليات', 'أي تعديل في اللوحة هيظهر هنا.') ?>
<?php else: ?>
<div class="ad-table-w"><table class="ad-table">
  <thead><tr><th>المستخدم</th><th>الإجراء</th><th>التفاصيل</th><th>القسم</th><th>التاريخ</th><th>IP</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr>
      <td class="t-first" data-l="المستخدم"><span class="t-main"><?= e($r['admin_name'] ?? '—') ?></span></td>
      <td class="t-chip" data-l="الإجراء"><?= sa_chip($actLabels[$r['action']] ?? $r['action'], $tone[$r['action']] ?? 'off') ?></td>
      <td data-l="التفاصيل"><?= e($r['summary']) ?></td>
      <td data-l="القسم" class="t-num"><?= e($secLabels[$r['section']] ?? $r['section']) ?></td>
      <td data-l="التاريخ" class="t-num" title="<?= e($r['created_at']) ?>"><?= e(sa_ago($r['created_at'])) ?></td>
      <td data-l="IP" class="t-num t-hide-m" dir="ltr"><?= e($r['ip'] ?: '—') ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<?= sa_pager($page, $pages, array_filter($f)) ?>
<?php endif; ?>
<?php include __DIR__ . '/layout-end.php'; ?>

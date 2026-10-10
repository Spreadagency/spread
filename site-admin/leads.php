<?php
/** العملاء المحتملين — اللي جرّبوا «اصنع منشورك» على الموقع (من قاعدة المنصة · قراءة بس) */
require_once __DIR__ . '/metrics.php';
sa_require_perm('leads');

$per = 30;
$page = max(1, (int) ($_GET['page'] ?? 1));
$q = trim((string) ($_GET['q'] ?? ''));
$st = (string) ($_GET['st'] ?? '');
$w = []; $p = [];
if ($q !== '') { $w[] = '(business_name LIKE ? OR industry LIKE ? OR goal LIKE ?)'; array_push($p, "%{$q}%", "%{$q}%", "%{$q}%"); }
if ($st === 'claimed') $w[] = 'claimed_user_id IS NOT NULL';
if ($st === 'post') $w[] = 'generated_text IS NOT NULL AND claimed_user_id IS NULL';
if ($st === 'ideas') $w[] = 'generated_text IS NULL';
$where = $w ? 'WHERE ' . implode(' AND ', $w) : '';
$ok = sm_platform_ok();
$total = $ok ? (int) (s_platform_all("SELECT COUNT(*) n FROM trial_sessions {$where}", $p)[0]['n'] ?? 0) : 0;
$pages = max(1, (int) ceil($total / $per));
$rows = $ok ? s_platform_all("SELECT t.id, t.business_name, t.industry, t.audience, t.goal, t.generated_text IS NOT NULL AS has_post, t.claimed_user_id, t.claimed_at, t.created_at, u.name AS uname, u.email AS uemail
    FROM trial_sessions t LEFT JOIN users u ON u.id = t.claimed_user_id {$where} ORDER BY t.id DESC LIMIT {$per} OFFSET " . (($page - 1) * $per), $p) : [];

$__t = 'العملاء المحتملين';
include __DIR__ . '/layout.php';
echo sa_page_head('users', 'العملاء المحتملين', 'Leads', 'كل اللي جرّبوا «اصنع منشورك الآن» على الموقع — بيانات مشروعهم، ووصلوا لحد فين، ومين سجّل منهم.');
if (!$ok) {
    echo sa_empty('link', 'اربط المنصة', 'العملاء المحتملين بيتسجّلوا في قاعدة المنصة (trial_sessions) — اتأكد إن includes/config.php فيه بيانات قاعدتها.');
    include __DIR__ . '/layout-end.php';
    exit;
}
?>
<form class="ad-bar" method="GET">
  <div class="ad-search"><span><?= sa_icon('search', 17) ?></span><input type="search" name="q" value="<?= e($q) ?>" placeholder="ابحث باسم النشاط أو المجال..." aria-label="ابحث"></div>
  <select class="ad-filter" name="st" onchange="this.form.submit()" aria-label="المرحلة">
    <?php foreach (['' => 'كل المراحل', 'ideas' => 'وقفوا عند الأفكار', 'post' => 'خدوا منشور ومسجّلوش', 'claimed' => 'سجّلوا'] as $k => $l): ?><option value="<?= $k ?>"<?= $st === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
  </select>
  <span class="ad-count"><b><?= number_format($total) ?></b> محاولة</span>
</form>
<?php if (!$rows): ?>
  <?= sa_empty('users', 'مفيش محاولات', 'أول ما حد يجرّب «اصنع منشورك» هيظهر هنا.') ?>
<?php else: ?>
<div class="ad-table-w"><table class="ad-table">
  <thead><tr><th>النشاط</th><th>المجال</th><th>الهدف</th><th>المرحلة</th><th>الحساب</th><th>التاريخ</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr>
      <td class="t-first" data-l="النشاط"><span class="t-main"><?= e($r['business_name'] ?: '—') ?></span><?php if ($r['audience']): ?><span class="t-sub"><?= e(mb_substr($r['audience'], 0, 60)) ?></span><?php endif; ?></td>
      <td data-l="المجال"><?= e($r['industry'] ?: '—') ?></td>
      <td data-l="الهدف"><?= e($r['goal'] ?: '—') ?></td>
      <td class="t-chip" data-l="المرحلة"><?= $r['claimed_user_id'] ? sa_chip('سجّل', 'ok') : ($r['has_post'] ? sa_chip('خد منشور', 'info') : sa_chip('أفكار', 'off')) ?></td>
      <td data-l="الحساب"><?= $r['claimed_user_id'] ? e($r['uname'] ?: '') . '<span class="t-sub" dir="ltr" style="text-align:right">' . e($r['uemail'] ?: '') . '</span>' : '<span class="t-num">—</span>' ?></td>
      <td data-l="التاريخ" class="t-num" title="<?= e($r['created_at']) ?>"><?= e(sa_ago($r['created_at'])) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<?= sa_pager($page, $pages, array_filter(['q' => $q, 'st' => $st])) ?>
<?php endif; ?>
<?php include __DIR__ . '/layout-end.php'; ?>

<?php
/**
 * Spread AI — الأدمن: المدفوعات (المرحلة 10)
 * كل الطلبات · في الانتظار · قيد المراجعة · معتمدة · مرفوضة · ملغية — + الباقات وطرق الدفع
 */
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/credits.php';
require_once __DIR__ . '/../includes/billing.php';

require_admin();
require_admin_can('view_users');
billing_ready();

$tab = $_GET['status'] ?? 'pending';
$statuses = billing_statuses();
if ($tab !== 'all' && !isset($statuses[$tab])) $tab = 'pending';
$q = trim((string) ($_GET['q'] ?? ''));

$where = [];
$params = [];
if ($tab !== 'all') { $where[] = 'r.status = ?'; $params[] = $tab; }
if ($q !== '') {
    $where[] = '(u.name LIKE ? OR u.email LIKE ? OR r.contact_phone LIKE ? OR r.transaction_ref LIKE ? OR r.id = ?)';
    array_push($params, "%$q%", "%$q%", "%$q%", "%$q%", (int) $q);
}
$rows = db_all('SELECT r.*, u.name AS user_name, u.email AS user_email FROM payment_requests r JOIN users u ON u.id = r.user_id'
    . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY r.status IN ("pending","under_review") DESC, r.id DESC LIMIT 200', $params);
$counts = billing_counts();
$sumApproved = (float) (db_one('SELECT COALESCE(SUM(final_amount),0) s FROM payment_requests WHERE status = "approved" AND reviewed_at >= ?', [date('Y-m-01')])['s'] ?? 0);

$active = 'payments';
$page_title = 'المدفوعات';
include __DIR__ . '/../templates/admin-header.php';
?>
<div class="app">
    <?php include __DIR__ . '/../templates/admin-sidebar.php'; ?>
    <main class="main">
        <div class="page-head with-actions">
            <div>
                <h1>المدفوعات 💳</h1>
                <div class="sub">طلبات الاشتراك بالتحويل — راجع الإيصال واعتمد، والباقة والكريدت بيتفعّلوا تلقائيًا. معتمد الشهر ده: <b><?= number_format($sumApproved, 0) ?> جنيه</b></div>
            </div>
            <div style="display:flex;gap:8px;flex-wrap:wrap">
                <a href="<?= url('admin/packages.php') ?>" class="btn ghost sm">الباقات</a>
                <a href="<?= url('admin/payment-methods.php') ?>" class="btn ghost sm">طرق الدفع</a>
            </div>
        </div>
        <?= render_flash() ?>

        <div class="seg" style="margin-bottom:14px;flex-wrap:wrap">
            <a href="?status=all"><button type="button" class="<?= $tab === 'all' ? 'on' : '' ?>">كل الطلبات (<?= array_sum($counts) ?>)</button></a>
            <?php foreach ($statuses as $k => [$lbl]): ?>
                <a href="?status=<?= $k ?>"><button type="button" class="<?= $tab === $k ? 'on' : '' ?>"><?= e($lbl) ?> (<?= (int) $counts[$k] ?>)</button></a>
            <?php endforeach; ?>
        </div>
        <form method="GET" style="display:flex;gap:8px;margin-bottom:14px;max-width:520px">
            <input type="hidden" name="status" value="<?= e($tab) ?>">
            <input class="input" name="q" value="<?= e($q) ?>" placeholder="اسم · إيميل · موبايل · رقم العملية · رقم الطلب">
            <button class="btn ghost">بحث</button>
        </form>

        <div class="table-wrap">
            <table class="tbl">
                <thead><tr><th>#</th><th>العميل</th><th>الباقة</th><th>المبلغ</th><th>الطريقة</th><th>الإيصال</th><th>الوقت</th><th>الحالة</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($rows as $r): $s = $statuses[$r['status']]; ?>
                    <tr>
                        <td><?= (int) $r['id'] ?></td>
                        <td><b><?= e($r['user_name']) ?></b><br><small class="sub" dir="ltr"><?= e($r['user_email']) ?> · <?= e((string) $r['contact_phone']) ?></small></td>
                        <td><?= e($r['plan_name']) ?><?= $r['promo_code'] ? '<br><small class="chip" dir="ltr">' . e($r['promo_code']) . '</small>' : '' ?></td>
                        <td><b><?= number_format((float) $r['final_amount'], 0) ?></b> ج<?= (float) $r['discount_amount'] > 0 ? '<br><small class="sub"><s>' . number_format((float) $r['original_amount'], 0) . '</s></small>' : '' ?></td>
                        <td><?= e($r['method_label']) ?></td>
                        <td><?= $r['proof_path'] ? '<a href="' . e(billing_proof_url((int) $r['id'], true)) . '" target="_blank" rel="noopener">[عرض]</a>' : '—' ?></td>
                        <td><small><?= e(fmt_date($r['created_at'], true)) ?></small></td>
                        <td><span class="chip" style="color:<?= e($s[1]) ?>;border-color:<?= e($s[1]) ?>"><?= e($s[0]) ?></span><?= $r['info_request'] && in_array($r['status'], ['pending', 'under_review'], true) ? '<br><small class="sub">مستني رد العميل</small>' : '' ?></td>
                        <td><a href="<?= url('admin/payment-view.php?id=' . (int) $r['id']) ?>" class="btn sm">فتح</a></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?><tr><td colspan="9" class="sub" style="text-align:center;padding:24px">مفيش طلبات هنا</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </main>
</div>
<?php include __DIR__ . '/../templates/admin-footer.php'; ?>

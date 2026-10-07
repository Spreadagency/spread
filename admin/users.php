<?php
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/credits.php';
require_once __DIR__ . '/../includes/admin-ui.php';

require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_user') {
    require_csrf();
    require_admin_can('delete_user');
    $uid = (int) ($_POST['user_id'] ?? 0);
    $target = db_one('SELECT id, name, email FROM users WHERE id = ?', [$uid]);
    if (!$target) {
        flash_set('danger', 'العميل غير موجود');
        redirect('admin/users.php');
    }
    try {
        require_once __DIR__ . '/../includes/account.php';
        account_purge($uid);   // نفس المسح اللي بيحصل بعد مهلة «حذف الحساب» من الإعدادات
        admin_log('delete_user', 'user', $uid, $target['email']);
        flash_set('success', 'تم حذف «' . $target['name'] . '» وكل بياناته نهائيًا');
    } catch (\Throwable $e) {
        flash_set('danger', 'تعذّر الحذف: ' . $e->getMessage());
    }
    redirect('admin/users.php');
}

// 8-ب: قواعد مؤشرات صحة العميل
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'health_rules') {
    require_csrf();
    require_admin_can('site_settings');
    set_setting('health_inactive_days', (string) max(1, min(90, (int) ($_POST['health_inactive_days'] ?? 7))));
    set_setting('health_usage_high', (string) max(10, min(100, (int) ($_POST['health_usage_high'] ?? 85))));
    set_setting('health_renew_days', (string) max(1, min(30, (int) ($_POST['health_renew_days'] ?? 3))));
    admin_log('health_rules', 'settings', null, null);
    flash_set('success', 'قواعد المؤشرات اتحفظت ✓');
    redirect('admin/users.php');
}

// Quick verify action from list
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'quick_verify') {
    require_csrf();
    $uid = (int) ($_POST['uid'] ?? 0);
    db_run('UPDATE users SET email_verified_at = NOW() WHERE id = ? AND email_verified_at IS NULL', [$uid]);
    db_run('DELETE FROM email_verifications WHERE user_id = ?', [$uid]);
    admin_log('verify_email_manual', 'user', $uid, 'تفعيل سريع من القائمة');
    flash_set('success', 'تم تفعيل البريد ✓');
    redirect('admin/users.php' . (!empty($_GET) ? '?' . http_build_query($_GET) : ''));
}

$q = trim($_GET['q'] ?? '');
$status = $_GET['status'] ?? '';
$health = (string) ($_GET['h'] ?? '');

$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;

$where = '1=1';
$params = [];
if ($q !== '') {
    $where .= ' AND (u.name LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)';
    $params[] = "%$q%"; $params[] = "%$q%"; $params[] = "%$q%";
}
if ($status === 'active') $where .= " AND u.status = 'active'";
if ($status === 'inactive') $where .= " AND u.status IN ('inactive', 'blocked')";
if ($status === 'unverified') $where .= ' AND u.email_verified_at IS NULL';

// 8-ب: الباقة والاستهلاك ومؤشرات الصحة (بقواعد معلنة)
$plansOn = function_exists('plans_ready') && plans_ready();
$rInact = max(1, (int) get_setting('health_inactive_days', 7));
$rHigh = max(1, (int) get_setting('health_usage_high', 85));
$rRenew = max(1, (int) get_setting('health_renew_days', 3));
$hasCamp = (bool) db_one("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'campaigns'");

$inner = "SELECT u.*,
        (SELECT COUNT(*) FROM contents WHERE user_id = u.id) contents_count,
        COALESCE(w.balance, 0) balance, w.expires_at w_exp,
        GREATEST(COALESCE((SELECT MAX(created_at) FROM contents WHERE user_id = u.id), '1970-01-01'),
                 COALESCE((SELECT MAX(created_at) FROM credit_transactions WHERE user_id = u.id AND action_type = 'consume'), '1970-01-01')) last_act,
        " . ($plansOn ? "(SELECT p.name FROM user_plans p WHERE p.user_id = u.id AND p.status = 'active' AND p.ends_at > NOW() ORDER BY p.id DESC LIMIT 1) plan_name,
        (SELECT p.ends_at FROM user_plans p WHERE p.user_id = u.id AND p.status = 'active' AND p.ends_at > NOW() ORDER BY p.id DESC LIMIT 1) plan_ends,
        COALESCE((SELECT p.starts_at FROM user_plans p WHERE p.user_id = u.id AND p.status = 'active' AND p.ends_at > NOW() ORDER BY p.id DESC LIMIT 1),"
        : "NULL plan_name, NULL plan_ends, COALESCE(") . "
                 (SELECT MAX(created_at) FROM credit_transactions t WHERE t.user_id = u.id AND t.action_type = 'add' AND COALESCE(t.reference_type, '') <> 'refund'), u.created_at) cyc_start,
        " . ($hasCamp ? "EXISTS(SELECT 1 FROM campaigns c WHERE c.user_id = u.id)" : '1') . " has_camp
     FROM users u LEFT JOIN credit_wallets w ON w.user_id = u.id WHERE $where {IDS}";
$mid = "SELECT x.*,
        GREATEST(0, COALESCE((SELECT SUM(IF(t.action_type = 'consume', t.amount, 0)) - SUM(IF(t.action_type = 'add' AND t.reference_type = 'refund', t.amount, 0))
                  FROM credit_transactions t WHERE t.user_id = x.id AND t.created_at >= x.cyc_start), 0)) cyc_used,
        COALESCE(x.plan_ends, x.w_exp) renew_at
     FROM ($inner) x";
$outer = "SELECT z.*, IF(z.cyc_used + z.balance > 0, LEAST(100, ROUND(z.cyc_used / (z.cyc_used + z.balance) * 100)), IF(z.balance <= 0 AND z.cyc_used > 0, 100, 0)) use_pct FROM ($mid) z";
$hWhere = [
    'inactive'   => "last_act < DATE_SUB(NOW(), INTERVAL $rInact DAY)",
    'near_limit' => "use_pct >= $rHigh",
    'renew'      => "renew_at IS NOT NULL AND renew_at > NOW() AND renew_at <= DATE_ADD(NOW(), INTERVAL $rRenew DAY) AND (plan_name IS NOT NULL OR balance > 0)",
    'no_campaign'=> 'has_camp = 0',
];
$hMeta = [
    'inactive'    => ['مهدد بالإلغاء', 'chip-coral', 'مفيش نشاط من ' . $rInact . ' أيام'],
    'near_limit'  => ['قرّب يخلص', 'chip-amber', 'استهلك ' . $rHigh . '% أو أكتر'],
    'renew'       => ['التجديد قريب', 'chip-sky', 'فاضل ' . $rRenew . ' أيام أو أقل'],
    'no_campaign' => ['مابدأش حملة', 'chip-line', 'مخلّصش رحلته الأولى'],
];
if (!isset($hWhere[$health])) $health = '';

// عدّاد واحد لكل الفلاتر والإجمالي (استعلام تقيل واحد بدل ستة)
$outerAll = str_replace('{IDS}', '', $outer);
$aggSel = 'COUNT(*) total';
foreach ($hWhere as $hk => $hw) $aggSel .= ", SUM($hw) `$hk`";
$agg = [];
try { $agg = db_one("SELECT $aggSel FROM ($outerAll) f", $params) ?: []; } catch (\Throwable $e) { $agg = []; }
$hCounts = [];
foreach ($hWhere as $hk => $hw) $hCounts[$hk] = (int) ($agg[$hk] ?? 0);
$total = $health ? $hCounts[$health] : (int) ($agg['total'] ?? 0);
$totalPages = max(1, (int) ceil($total / $perPage));

if ($health) {
    $users = db_all("SELECT * FROM ($outerAll) f WHERE {$hWhere[$health]} ORDER BY id DESC LIMIT $perPage OFFSET $offset", $params);
} else {
    // من غير فلتر صحة: هات أرقام الصفحة الأول، واحسب الاستهلاك ليهم بس
    $ids = array_map('intval', array_column(db_all("SELECT u.id FROM users u WHERE $where ORDER BY u.id DESC LIMIT $perPage OFFSET $offset", $params), 'id'));
    $users = $ids ? db_all('SELECT * FROM (' . str_replace('{IDS}', 'AND u.id IN (' . implode(',', $ids) . ')', $outer) . ') f ORDER BY id DESC', $params) : [];
}

// المؤشرات الكاملة (بما فيها حصص الخدمات) للصفوف الظاهرة بس
$sig = [];
if ($plansOn && function_exists('customer_signals')) {
    foreach ($users as $u) {
        try { $sig[(int) $u['id']] = customer_signals((int) $u['id']); } catch (\Throwable $e) { $sig[(int) $u['id']] = []; }
    }
}
$sigChip = ['bad' => 'chip-coral', 'warn' => 'chip-amber', 'info' => 'chip-sky'];
$qsWith = function (array $over) { $qs = array_merge($_GET, $over); unset($qs['page']); $qs = array_filter($qs, fn($v) => $v !== '' && $v !== null); return url('admin/users.php' . ($qs ? '?' . http_build_query($qs) : '')); };
$crShow = function_exists('credits_show_numbers') ? credits_show_numbers() : true;

$active = 'users';
$page_title = 'العملاء';
include __DIR__ . '/../templates/admin-header.php';
?>
<div class="app">
    <?php include __DIR__ . '/../templates/admin-sidebar.php'; ?>
    <main class="main">
        <div class="topbar"><button class="menu-toggle" onclick="toggleSidebar()">☰</button></div>
        <?= render_flash() ?>

        <div class="page-head with-actions">
            <div>
                <h1>العملاء <span class="a2-muted" style="font-size:16px">· <?= a2n($total) ?></span></h1>
                <div class="sub">Customers · الباقة والاستهلاك ومؤشرات المتابعة لكل عميل</div>
            </div>
            <div class="a2-row" style="gap:8px">
                <?php if (admin_can('site_settings')): ?><button type="button" class="btn ghost" onclick="document.getElementById('hrules').classList.toggle('open')">⚙ قواعد المؤشرات</button><?php endif; ?>
                <a href="<?= url('admin/users-export.php') ?>" class="btn soft">📊 تصدير Excel</a>
            </div>
        </div>

        <?php if (admin_can('site_settings')): ?>
        <form method="post" class="card u-rules" id="hrules">
            <?= csrf_field() ?><input type="hidden" name="action" value="health_rules">
            <div class="a2-h"><h3>قواعد مؤشرات العميل</h3><span class="a2-muted">بتتطبق على القائمة وملف العميل</span></div>
            <div class="a2-grid a2-g3" style="gap:12px">
                <div><label class="a2-lbl">«مهدد بالإلغاء» لو مفيش نشاط من (يوم)</label><input class="input" type="number" name="health_inactive_days" min="1" max="90" value="<?= $rInact ?>" style="width:100%"></div>
                <div><label class="a2-lbl">«قرّب يخلص» لو استهلك (%)</label><input class="input" type="number" name="health_usage_high" min="10" max="100" value="<?= $rHigh ?>" style="width:100%"></div>
                <div><label class="a2-lbl">«التجديد قريب» لو فاضل (يوم)</label><input class="input" type="number" name="health_renew_days" min="1" max="30" value="<?= $rRenew ?>" style="width:100%"></div>
            </div>
            <div style="margin-top:12px"><button class="btn" type="submit">حفظ</button></div>
        </form>
        <?php endif; ?>

        <?php if ($plansOn): ?>
        <div class="a2-grid a2-g4 u-hcards">
            <?php foreach ($hMeta as $hk => [$hl, $hc, $hd]): ?>
                <a class="a2-kpi u-hcard <?= $health === $hk ? 'on' : '' ?>" href="<?= $qsWith(['h' => $health === $hk ? '' : $hk]) ?>">
                    <small><span class="chip <?= $hc ?>"><?= e($hl) ?></span></small>
                    <b><?= a2n($hCounts[$hk] ?? 0) ?></b><em><?= e($hd) ?></em>
                </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <form method="GET" class="card u-filter">
            <?php if ($health): ?><input type="hidden" name="h" value="<?= e($health) ?>"><?php endif; ?>
            <input type="text" name="q" class="input" placeholder="ابحث بالاسم أو الإيميل أو الموبايل…" value="<?= e($q) ?>">
            <select name="status" class="input">
                <option value="">كل الحالات</option>
                <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>مفعّل</option>
                <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>معطّل</option>
                <option value="unverified" <?= $status === 'unverified' ? 'selected' : '' ?>>لم يفعّل البريد</option>
            </select>
            <button class="btn">بحث</button>
            <?php if ($q !== '' || $status !== '' || $health !== ''): ?><a class="btn ghost" href="<?= url('admin/users.php') ?>">مسح</a><?php endif; ?>
        </form>

        <div class="card" style="padding:0">
            <div class="a2-tw" style="border:0"><table class="a2-tbl u-tbl">
                <thead><tr>
                    <th>العميل</th><th>الباقة</th><th style="min-width:130px">الاستهلاك</th><th>التجديد</th><th>المؤشرات</th><th>آخر نشاط</th><th></th>
                </tr></thead>
                <tbody>
                <?php foreach ($users as $u): $uid = (int) $u['id']; $p = (int) $u['use_pct']; $la = strtotime((string) $u['last_act']); ?>
                    <tr>
                        <td>
                            <a class="a2-row" style="gap:10px;flex-wrap:nowrap;color:inherit" href="<?= url('admin/user-view.php?id=' . $uid) ?>">
                                <span class="avi" style="background:<?= e(color_from_string($u['email'])) ?>;flex-shrink:0"><?= e(initials($u['name'])) ?></span>
                                <span style="min-width:0"><b><?= e($u['name']) ?></b>
                                    <?php if (empty($u['email_verified_at'])): ?> <span class="chip chip-amber">لم يفعّل</span><?php elseif ($u['status'] !== 'active'): ?> <span class="chip chip-coral">معطّل</span><?php endif; ?>
                                    <span class="a2-muted" style="display:block;font-size:12px" dir="ltr"><?= e($u['email']) ?></span></span>
                            </a>
                        </td>
                        <td><?= $u['plan_name'] ? '<b>' . e($u['plan_name']) . '</b>' : '<span class="a2-muted">' . ((int) $u['balance'] > 0 ? 'رصيد بدون باقة' : '—') . '</span>' ?>
                            <div class="a2-muted" style="font-size:12px"><?= a2n($u['balance']) ?> كريدت · <?= (int) $u['contents_count'] ?> محتوى</div></td>
                        <td><div class="a2-row" style="gap:8px;flex-wrap:nowrap"><div class="a2-bar <?= $p >= 90 ? 'bad' : ($p >= 70 ? 'warn' : '') ?>" style="flex:1;min-width:60px"><i style="width:<?= $p ?>%"></i></div><small><?= $p ?>%</small></div></td>
                        <td class="a2-muted" style="white-space:nowrap"><?= $u['renew_at'] ? e(fmt_date(substr((string) $u['renew_at'], 0, 10))) : '—' ?></td>
                        <td><div class="u-sig">
                            <?php foreach ($sig[$uid] ?? [] as [$sk, $sl, $slv, $sw]): ?><span class="chip <?= $sigChip[$slv] ?? 'chip-line' ?>" title="<?= e($sw) ?>"><?= e($sl) ?></span><?php endforeach; ?>
                            <?php if (empty($sig[$uid])): ?><span class="a2-muted">—</span><?php endif; ?>
                        </div></td>
                        <td class="a2-muted" style="white-space:nowrap"><?= $la > 86400 ? e(time_ago($u['last_act'])) : 'لسه' ?></td>
                        <td style="white-space:nowrap">
                            <?php if (empty($u['email_verified_at'])): ?>
                                <form method="POST" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="quick_verify"><input type="hidden" name="uid" value="<?= $uid ?>">
                                    <button class="btn sm soft" title="تفعيل البريد" onclick="return confirm('تفعيل بريد <?= e($u['email']) ?>؟')">✓ تفعيل</button></form>
                            <?php endif; ?>
                            <a href="<?= url('admin/user-view.php?id=' . $uid) ?>" class="btn sm">الملف</a>
                            <?php if (admin_can('impersonate')): ?><a href="<?= url('admin/impersonate.php?user_id=' . $uid) ?>" class="btn sm ghost" title="ادخل بحسابه وساعده">👁</a><?php endif; ?>
                            <?php if (admin_can('delete_user')): ?>
                                <form method="POST" style="display:inline" onsubmit="return confirm('حذف «<?= e($u['name']) ?>» نهائيًا؟\n\nهيتمسح معاه: المحتوى، التصميمات، الخطط، الهوية، الرصيد، والاتصالات.\nالإجراء ده مش ممكن التراجع عنه.')">
                                    <?= csrf_field() ?><input type="hidden" name="action" value="delete_user"><input type="hidden" name="user_id" value="<?= $uid ?>">
                                    <button class="btn sm danger" title="حذف نهائي">🗑</button></form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($users)): ?><tr><td colspan="7"><div class="a2-empty">مفيش عملاء مطابقين</div></td></tr><?php endif; ?>
                </tbody>
            </table></div>
        </div>

        <?php if ($totalPages > 1): ?>
            <div class="pagination">
                <?php $qs = $_GET; for ($pn = 1; $pn <= $totalPages; $pn++): $qs['page'] = $pn; ?>
                    <a href="?<?= e(http_build_query($qs)) ?>" class="<?= $pn === $page ? 'active' : '' ?>"><?= $pn ?></a>
                <?php endfor; ?>
            </div>
        <?php endif; ?>
    </main>
</div>
<?php include __DIR__ . '/../templates/admin-footer.php'; ?>

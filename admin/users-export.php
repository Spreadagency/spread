<?php
/**
 * Spread AI v2 — الأدمن: تصدير بيانات العملاء
 * فلترة بالتاريخ والحالة → معاينة → تحميل Excel أو CSV
 */
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/credits.php';
require_once __DIR__ . '/../includes/xlsx.php';

require_admin();
require_admin_can('view_users');

/* ─── الفلاتر ─── */
$from     = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['from'] ?? '')) ? $_GET['from'] : '';
$to       = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['to'] ?? '')) ? $_GET['to'] : '';
$status   = in_array($_GET['status'] ?? '', ['active', 'inactive'], true) ? $_GET['status'] : '';
$approval = in_array($_GET['approval'] ?? '', ['approved', 'pending', 'rejected'], true) ? $_GET['approval'] : '';
$verified = in_array($_GET['verified'] ?? '', ['yes', 'no'], true) ? $_GET['verified'] : '';
$credits  = $_GET['credits'] ?? '';           // has | none
$hasPhone = !empty($_GET['has_phone']);
$q        = trim((string) ($_GET['q'] ?? ''));
$sort     = in_array($_GET['sort'] ?? '', ['created_desc', 'created_asc', 'credits_desc', 'name'], true) ? $_GET['sort'] : 'created_desc';

/* اختصارات المدة */
$quick = $_GET['quick'] ?? '';
if ($quick === 'today')      { $from = $to = date('Y-m-d'); }
elseif ($quick === 'week')   { $from = date('Y-m-d', strtotime('-7 days'));  $to = date('Y-m-d'); }
elseif ($quick === 'month')  { $from = date('Y-m-01'); $to = date('Y-m-d'); }
elseif ($quick === 'last30') { $from = date('Y-m-d', strtotime('-30 days')); $to = date('Y-m-d'); }

$w = ['1=1'];
$p = [];
if ($from)     { $w[] = 'DATE(u.created_at) >= ?'; $p[] = $from; }
if ($to)       { $w[] = 'DATE(u.created_at) <= ?'; $p[] = $to; }
if ($status)   { $w[] = 'u.status = ?';            $p[] = $status; }
if ($approval) { $w[] = 'u.approval_status = ?';   $p[] = $approval; }
if ($verified === 'yes') { $w[] = 'u.email_verified_at IS NOT NULL'; }
if ($verified === 'no')  { $w[] = 'u.email_verified_at IS NULL'; }
if ($hasPhone) { $w[] = 'u.phone IS NOT NULL AND u.phone != ""'; }
if ($q !== '') {
    $w[] = '(u.name LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)';
    array_push($p, "%{$q}%", "%{$q}%", "%{$q}%");
}
$having = '';
if ($credits === 'has')  { $having = 'HAVING balance > 0'; }
if ($credits === 'none') { $having = 'HAVING balance = 0'; }

$orderMap = [
    'created_desc' => 'u.created_at DESC',
    'created_asc'  => 'u.created_at ASC',
    'credits_desc' => 'balance DESC',
    'name'         => 'u.name ASC',
];

$sql = 'SELECT u.id, u.name, u.email, u.phone, u.status, u.approval_status,
               u.email_verified_at, u.created_at, u.referral_code,
               COALESCE((SELECT balance FROM credit_wallets WHERE user_id = u.id), 0) AS balance,
               (SELECT expires_at FROM credit_wallets WHERE user_id = u.id) AS credit_expires,
               (SELECT COUNT(*) FROM contents WHERE user_id = u.id) AS posts,
               (SELECT business_name FROM brand_profiles WHERE user_id = u.id LIMIT 1) AS business
        FROM users u
        WHERE ' . implode(' AND ', $w) . ' ' . $having . '
        ORDER BY ' . $orderMap[$sort];

/* ─── التصدير ─── */
$export = $_GET['export'] ?? '';
if ($export === 'xlsx' || $export === 'csv') {
    $rows = db_all($sql . ' LIMIT 20000', $p);

    $headers = ['#', 'الاسم', 'البريد الإلكتروني', 'رقم الموبايل', 'اسم النشاط',
                'الكريدت', 'صلاحية الكريدت', 'عدد المنشورات', 'كود الدعوة',
                'الحالة', 'مفعّل', 'تاريخ الاشتراك', 'الساعة'];

    $data = [];
    foreach ($rows as $i => $r) {
        $data[] = [
            $i + 1,
            $r['name'],
            $r['email'],
            // بيتخزن كنص (inlineStr) فالصفر الأول محفوظ ومفيش صيغة علمية
            (string) ($r['phone'] ?? ''),
            $r['business'] ?? '',
            (int) $r['balance'],
            $r['credit_expires'] ? date('Y-m-d', strtotime($r['credit_expires'])) : '',
            (int) $r['posts'],
            $r['referral_code'] ?? '',
            ['approved' => 'معتمد', 'pending' => 'قيد المراجعة', 'rejected' => 'مرفوض'][$r['approval_status']] ?? $r['approval_status'],
            $r['email_verified_at'] ? 'نعم' : 'لا',
            date('Y-m-d', strtotime($r['created_at'])),
            date('H:i', strtotime($r['created_at'])),
        ];
    }

    $name = 'عملاء-سبريد-' . ($from ?: 'الكل') . ($to ? '-الى-' . $to : '') . '-' . date('Ymd-Hi');
    admin_log('export_users', 'users', null, count($data) . ' صف · ' . $export);

    if ($export === 'csv') {
        csv_download($name, $headers, $data);
    }
    xlsx_download($name, $headers, $data, [
        'sheet'   => 'العملاء',
        'widths'  => [5, 24, 30, 18, 24, 10, 15, 12, 13, 14, 8, 14, 8],
        'numeric' => [1, 6, 8],
    ]);
}

/* ─── المعاينة ─── */
$preview = db_all($sql . ' LIMIT 50', $p);
$totalRow = db_one('SELECT COUNT(*) c FROM (' . $sql . ') t', $p);
$total = (int) ($totalRow['c'] ?? 0);
$sumCredits = 0;
foreach (db_all($sql . ' LIMIT 20000', $p) as $r) {
    $sumCredits += (int) $r['balance'];
}

$qs = array_filter([
    'from' => $from, 'to' => $to, 'status' => $status, 'approval' => $approval,
    'verified' => $verified, 'credits' => $credits, 'has_phone' => $hasPhone ? 1 : null,
    'q' => $q, 'sort' => $sort,
]);

$active = 'users';
$page_title = 'تصدير بيانات العملاء';
include __DIR__ . '/../templates/admin-header.php';
?>

<div class="app">
    <?php include __DIR__ . '/../templates/admin-sidebar.php'; ?>
    <main class="main">
        <div class="page-head">
            <h1>تصدير بيانات العملاء 📊</h1>
            <div class="sub">فلتر باللي انت عايزه، شوف المعاينة، ونزّل الملف</div>
        </div>

        <?= render_flash() ?>

        <div class="card">
            <div class="card-head"><h3>الفلاتر</h3></div>
            <form method="GET">
                <div style="display:flex;gap:7px;flex-wrap:wrap;margin-bottom:14px">
                    <?php foreach ([
                        'today'  => 'النهاردة',
                        'week'   => 'آخر ٧ أيام',
                        'month'  => 'الشهر ده',
                        'last30' => 'آخر ٣٠ يوم',
                    ] as $k => $lbl): ?>
                        <a href="?quick=<?= $k ?>" class="btn ghost sm"><?= e($lbl) ?></a>
                    <?php endforeach; ?>
                    <a href="?" class="btn ghost sm">الكل</a>
                </div>

                <div class="field-row">
                    <div class="field">
                        <label>من تاريخ</label>
                        <input type="date" name="from" class="input" value="<?= e($from) ?>">
                    </div>
                    <div class="field">
                        <label>إلى تاريخ</label>
                        <input type="date" name="to" class="input" value="<?= e($to) ?>">
                    </div>
                    <div class="field">
                        <label>حالة الحساب</label>
                        <select name="status" class="input">
                            <option value="">الكل</option>
                            <option value="active"   <?= $status === 'active' ? 'selected' : '' ?>>نشط</option>
                            <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>موقوف</option>
                        </select>
                    </div>
                    <div class="field">
                        <label>الاعتماد</label>
                        <select name="approval" class="input">
                            <option value="">الكل</option>
                            <option value="approved" <?= $approval === 'approved' ? 'selected' : '' ?>>معتمد</option>
                            <option value="pending"  <?= $approval === 'pending' ? 'selected' : '' ?>>قيد المراجعة</option>
                            <option value="rejected" <?= $approval === 'rejected' ? 'selected' : '' ?>>مرفوض</option>
                        </select>
                    </div>
                </div>

                <div class="field-row">
                    <div class="field">
                        <label>تفعيل الإيميل</label>
                        <select name="verified" class="input">
                            <option value="">الكل</option>
                            <option value="yes" <?= $verified === 'yes' ? 'selected' : '' ?>>مفعّل</option>
                            <option value="no"  <?= $verified === 'no' ? 'selected' : '' ?>>غير مفعّل</option>
                        </select>
                    </div>
                    <div class="field">
                        <label>الكريدت</label>
                        <select name="credits" class="input">
                            <option value="">الكل</option>
                            <option value="has"  <?= $credits === 'has' ? 'selected' : '' ?>>عنده رصيد</option>
                            <option value="none" <?= $credits === 'none' ? 'selected' : '' ?>>رصيده صفر</option>
                        </select>
                    </div>
                    <div class="field">
                        <label>الترتيب</label>
                        <select name="sort" class="input">
                            <option value="created_desc" <?= $sort === 'created_desc' ? 'selected' : '' ?>>الأحدث اشتراكًا</option>
                            <option value="created_asc"  <?= $sort === 'created_asc' ? 'selected' : '' ?>>الأقدم اشتراكًا</option>
                            <option value="credits_desc" <?= $sort === 'credits_desc' ? 'selected' : '' ?>>الأعلى رصيدًا</option>
                            <option value="name"         <?= $sort === 'name' ? 'selected' : '' ?>>الاسم أبجديًا</option>
                        </select>
                    </div>
                    <div class="field">
                        <label>بحث</label>
                        <input type="text" name="q" class="input" value="<?= e($q) ?>" placeholder="اسم / إيميل / موبايل">
                    </div>
                </div>

                <label style="display:flex;gap:8px;align-items:center;font-weight:400;margin-bottom:14px;cursor:pointer">
                    <input type="checkbox" name="has_phone" value="1" <?= $hasPhone ? 'checked' : '' ?> style="width:auto">
                    <span>اللي عندهم رقم موبايل بس <span class="sub" style="font-size:12px">(مفيد لحملات الواتساب)</span></span>
                </label>

                <button class="btn">🔍 عرض النتائج</button>
                <a href="<?= url('admin/users.php') ?>" class="btn ghost">رجوع للمستخدمين</a>
            </form>
        </div>

        <div class="card" style="background:var(--primary-soft);border-color:rgba(15,60,201,.25)">
            <div style="display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap">
                <div>
                    <b style="font-size:17px"><?= number_format($total) ?> عميل</b>
                    <?php if ($from || $to): ?>
                        <span class="sub"> · من <?= e($from ?: 'البداية') ?> إلى <?= e($to ?: 'النهاية') ?></span>
                    <?php endif; ?>
                    <div class="sub" style="font-size:13px;margin-top:3px">
                        إجمالي الكريدت عندهم: <b><?= number_format($sumCredits) ?></b> ◇
                    </div>
                </div>
                <div style="display:flex;gap:8px;flex-wrap:wrap">
                    <?php if ($total > 0): ?>
                        <a href="?<?= http_build_query($qs + ['export' => 'xlsx']) ?>" class="btn">⬇ تحميل Excel</a>
                        <a href="?<?= http_build_query($qs + ['export' => 'csv']) ?>" class="btn ghost">⬇ CSV</a>
                    <?php else: ?>
                        <span class="sub">مفيش نتائج للتصدير</span>
                    <?php endif; ?>
                </div>
            </div>
            <?php if ($total > 20000): ?>
                <p class="sub" style="margin:10px 0 0;color:#a06c1e">
                    ⚠️ التصدير هيشمل أول 20,000 صف — ضيّق المدة عشان تاخدهم على دفعات.
                </p>
            <?php endif; ?>
        </div>

        <div class="card">
            <div class="card-head">
                <h3>معاينة (أول <?= min(50, $total) ?> من <?= number_format($total) ?>)</h3>
            </div>
            <?php if (!$preview): ?>
                <p class="sub">مفيش عملاء مطابقين للفلاتر دي</p>
            <?php else: ?>
            <div class="table-wrap">
            <table class="table">
                <thead><tr>
                    <th>#</th><th>الاسم</th><th>الإيميل</th><th>الموبايل</th>
                    <th>النشاط</th><th>الكريدت</th><th>منشورات</th><th>الحالة</th><th>تاريخ الاشتراك</th>
                </tr></thead>
                <tbody>
                <?php foreach ($preview as $i => $r): ?>
                    <tr>
                        <td><?= $i + 1 ?></td>
                        <td><b><?= e($r['name']) ?></b></td>
                        <td dir="ltr" style="font-size:12.5px;text-align:start"><?= e($r['email']) ?></td>
                        <td dir="ltr" style="font-size:12.5px;text-align:start"><?= e($r['phone'] ?: '—') ?></td>
                        <td style="font-size:12.5px"><?= e($r['business'] ?: '—') ?></td>
                        <td><span class="chip chip-primary">◇ <?= (int) $r['balance'] ?></span></td>
                        <td><?= (int) $r['posts'] ?></td>
                        <td>
                            <span class="chip"><?= ['approved' => 'معتمد', 'pending' => 'مراجعة', 'rejected' => 'مرفوض'][$r['approval_status']] ?? '' ?></span>
                            <?= $r['email_verified_at'] ? '' : '<span class="chip" style="font-size:10px">غير مفعّل</span>' ?>
                        </td>
                        <td class="sub" style="font-size:12px;white-space:nowrap"><?= e(fmt_date($r['created_at'], true)) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <p class="sub" style="font-size:12.5px;margin-top:10px">
                الملف المُصدَّر بيحتوي على أعمدة أكتر: صلاحية الكريدت · كود الدعوة · تفعيل الإيميل.
            </p>
            <?php endif; ?>
        </div>
    </main>
</div>

<?php include __DIR__ . '/../templates/admin-footer.php'; ?>

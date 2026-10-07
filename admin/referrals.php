<?php
/** Spread AI v2 — الأدمن: تقرير الإحالات + الاعتماد اليدوي */
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/credits.php';
require_once __DIR__ . '/../includes/offers.php';

require_admin();
require_admin_can('manage_packages');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    decode_b64_fields();
    $act = $_POST['action'] ?? '';
    $ids = array_map('intval', (array) ($_POST['ids'] ?? []));
    if (!$ids && !empty($_POST['referral_id'])) $ids = [(int) $_POST['referral_id']];

    if ($act === 'approve' && $ids) {
        $ok = 0;
        foreach ($ids as $rid) {
            $r = referral_reward($rid, current_admin()['id']);
            if ($r['ok']) $ok++;
        }
        admin_log('approve_referrals', 'referral', null, count($ids) . ' → ' . $ok);
        flash_set($ok ? 'success' : 'danger', $ok ? "تم اعتماد وصرف {$ok} إحالة ✓" : 'مفيش إحالة اتصرفت — راجع الأسباب');
    }

    if ($act === 'reject' && $ids) {
        $reason = mb_substr(trim((string) ($_POST['reason'] ?? 'رفض إداري')), 0, 191);
        foreach ($ids as $rid) {
            db_run('UPDATE referrals SET status = "rejected", reject_reason = ? WHERE id = ? AND status != "rewarded"', [$reason, $rid]);
        }
        flash_set('success', 'تم رفض ' . count($ids) . ' إحالة');
    }
    redirect('admin/referrals.php?tab=' . urlencode($_POST['tab'] ?? 'pending'));
}

$tab = in_array($_GET['tab'] ?? '', ['pending', 'qualified', 'rewarded', 'rejected'], true) ? $_GET['tab'] : 'pending';

$rows = db_all(
    'SELECT r.*, o.code, o.referrer_credits,
            ru.name AS referrer_name, ru.email AS referrer_email, ru.signup_ip AS referrer_ip,
            eu.name AS referee_name, eu.email AS referee_email, eu.created_at AS referee_joined,
            eu.email_verified_at, eu.approval_status
     FROM referrals r
     LEFT JOIN offers o ON o.id = r.offer_id
     LEFT JOIN users ru ON ru.id = r.referrer_user_id
     LEFT JOIN users eu ON eu.id = r.referee_user_id
     WHERE r.status = ? ORDER BY r.id DESC LIMIT 300', [$tab]
);

// استعلام واحد لكل الحالات بدل أربعة
$counts = ['pending' => 0, 'qualified' => 0, 'rewarded' => 0, 'rejected' => 0];
foreach (db_all('SELECT status, COUNT(*) c FROM referrals GROUP BY status') as $__c) {
    $counts[$__c['status']] = (int) $__c['c'];
}

/* عدّ الإحالات المتقاربة زمنيًا لكل مُحيل — استعلام واحد بدل واحد لكل صف (N+1)
   بنجيب كل إحالات المُحيلين الظاهرين ونعدّ في PHP */
$burst = [];
if ($rows) {
    $refIds = array_values(array_unique(array_map(fn($r) => (int) $r['referrer_user_id'], $rows)));
    $ph = implode(',', array_fill(0, count($refIds), '?'));
    $all = db_all("SELECT referrer_user_id, UNIX_TIMESTAMP(created_at) ts FROM referrals
                   WHERE referrer_user_id IN ({$ph})", $refIds);
    $byRef = [];
    foreach ($all as $a) {
        $byRef[(int) $a['referrer_user_id']][] = (int) $a['ts'];
    }
    foreach ($rows as $r) {
        $rid = (int) $r['referrer_user_id'];
        $t = strtotime((string) $r['created_at']);
        $n = 0;
        foreach ($byRef[$rid] ?? [] as $ts) {
            if (abs($ts - $t) <= 1800) $n++;
        }
        $burst[(int) $r['id']] = $n;
    }
}

$top = db_all(
    'SELECT u.id, u.name, u.email, u.referral_code,
            COUNT(*) total,
            SUM(r.status = "rewarded") rewarded,
            COALESCE(SUM(CASE WHEN r.status = "rewarded" THEN o.referrer_credits ELSE 0 END), 0) credits
     FROM referrals r
     JOIN users u ON u.id = r.referrer_user_id
     LEFT JOIN offers o ON o.id = r.offer_id
     GROUP BY u.id ORDER BY rewarded DESC, total DESC LIMIT 20'
);

$tabLabels = ['pending' => '⏳ قيد الانتظار', 'qualified' => '✔ مستحقة', 'rewarded' => '🎉 مصروفة', 'rejected' => '✕ مرفوضة'];

$active = 'referrals';
$page_title = 'الإحالات';
include __DIR__ . '/../templates/admin-header.php';
?>
<div class="app">
    <?php include __DIR__ . '/../templates/admin-sidebar.php'; ?>
    <main class="main">
        <div class="page-head">
            <h1>الإحالات 🔗</h1>
            <div class="sub">متابعة كل إحالة من التسجيل للمكافأة</div>
        </div>
        <?= render_flash() ?>

        <div class="seg" style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:18px">
            <?php foreach ($tabLabels as $k => $lbl): ?>
                <a href="?tab=<?= $k ?>" style="text-decoration:none">
                    <button type="button" class="<?= $tab === $k ? 'on' : '' ?>"><?= e($lbl) ?> (<?= $counts[$k] ?>)</button>
                </a>
            <?php endforeach; ?>
        </div>

        <div class="card">
            <div class="card-head"><h3><?= e($tabLabels[$tab]) ?></h3></div>
            <?php if (!$rows): ?>
                <p class="sub">مفيش إحالات في القسم ده</p>
            <?php else: ?>
            <form method="POST" data-safe-post>
                <?= csrf_field() ?>
                <input type="hidden" name="tab" value="<?= e($tab) ?>">
                <div class="table-wrap">
                <table class="table">
                    <thead><tr>
                        <?php if ($tab !== 'rewarded'): ?><th style="width:28px"><input type="checkbox" onclick="document.querySelectorAll('.rid').forEach(c=>c.checked=this.checked)"></th><?php endif; ?>
                        <th>المُحيل</th><th>المُحال</th><th>الكود</th><th>مكافأة</th><th>تنبيهات</th><th>التاريخ</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($rows as $r):
                        $flags = [];
                        if (!empty($r['signup_ip']) && $r['signup_ip'] === ($r['referrer_ip'] ?? null)) $flags[] = '⚠ نفس الـ IP';
                        if (empty($r['email_verified_at'])) $flags[] = '⚠ إيميل غير مفعّل';
                        $sameHour = $burst[(int) $r['id']] ?? 0;
                        if ($sameHour > 3) $flags[] = '⚠ ' . $sameHour . ' إحالات في ساعة';
                    ?>
                        <tr>
                            <?php if ($tab !== 'rewarded'): ?><td><input type="checkbox" class="rid" name="ids[]" value="<?= (int) $r['id'] ?>"></td><?php endif; ?>
                            <td><b><?= e($r['referrer_name'] ?? '—') ?></b><div class="sub" style="font-size:11px"><?= e($r['referrer_email'] ?? '') ?></div></td>
                            <td><?= e($r['referee_name'] ?? '—') ?><div class="sub" style="font-size:11px"><?= e($r['referee_email'] ?? '') ?></div></td>
                            <td><code dir="ltr"><?= e($r['code'] ?? '') ?></code></td>
                            <td><b><?= (int) ($r['referrer_credits'] ?? 0) ?></b> ◇</td>
                            <td style="font-size:11.5px;color:#a06c1e"><?= $flags ? e(implode(' · ', $flags)) : '—' ?></td>
                            <td class="sub" style="font-size:11.5px">
                                <?= e(fmt_date($r['created_at'])) ?>
                                <?php if ($r['rewarded_at']): ?><br>صُرفت: <?= e(fmt_date($r['rewarded_at'])) ?><?php endif; ?>
                                <?php if ($r['reject_reason']): ?><br><span style="color:#c0392b"><?= e($r['reject_reason']) ?></span><?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <?php if ($tab !== 'rewarded'): ?>
                    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:end;margin-top:14px">
                        <button name="action" value="approve" class="btn">✔ اعتماد وصرف المختار</button>
                        <div class="field" style="margin:0;flex:1;min-width:180px">
                            <input type="text" name="reason" class="input" placeholder="سبب الرفض (اختياري)">
                        </div>
                        <button name="action" value="reject" class="btn ghost" style="color:#c0392b">✕ رفض المختار</button>
                    </div>
                <?php endif; ?>
            </form>
            <?php endif; ?>
        </div>

        <div class="card">
            <div class="card-head"><h3>🏆 أفضل المُحيلين</h3></div>
            <?php if (!$top): ?><p class="sub">لسه مفيش إحالات</p><?php else: ?>
            <div class="table-wrap">
            <table class="table">
                <thead><tr><th>#</th><th>المُحيل</th><th>الكود</th><th>إجمالي</th><th>مصروفة</th><th>الكريدت المكتسب</th></tr></thead>
                <tbody>
                <?php foreach ($top as $i => $t): ?>
                    <tr>
                        <td><?= $i + 1 ?></td>
                        <td><b><?= e($t['name']) ?></b><div class="sub" style="font-size:11px"><?= e($t['email']) ?></div></td>
                        <td><code dir="ltr"><?= e($t['referral_code'] ?? '') ?></code></td>
                        <td><?= (int) $t['total'] ?></td>
                        <td><b><?= (int) $t['rewarded'] ?></b></td>
                        <td><?= number_format((int) $t['credits']) ?> ◇</td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>
        </div>
    </main>
</div>
<?php include __DIR__ . '/../templates/admin-footer.php'; ?>

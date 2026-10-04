<?php
/**
 * Spread AI v2 — الأدمن: الموافقة على العملاء الجدد (Phase 6)
 */
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/credits.php';
require_once __DIR__ . '/../includes/mailer.php';

require_admin();
require_admin_can('approve_users');
$admin = current_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    // ── تشغيل/إيقاف الموافقة اليدوية ──
    if ($action === 'toggle_manual') {
        $new = get_setting('manual_approval', '0') === '1' ? '0' : '1';
        set_setting('manual_approval', $new);
        admin_log('toggle_manual_approval', 'settings', null, $new);
        flash_set('success', $new === '1'
            ? 'تم تفعيل الموافقة اليدوية — أي حساب جديد هيستنى موافقتك'
            : 'تم الإيقاف — الحسابات الجديدة بتتفعل تلقائيًا بعد تأكيد الإيميل');
        redirect('admin/approvals.php');
    }

    $userId = (int) ($_POST['user_id'] ?? 0);
    $u = db_one('SELECT * FROM users WHERE id = ?', [$userId]);
    if (!$u) {
        flash_set('danger', 'المستخدم غير موجود');
        redirect('admin/approvals.php');
    }

    // ── موافقة ──
    if ($action === 'approve') {
        db_run('UPDATE users SET approval_status = "approved", approved_at = NOW(), status = "active" WHERE id = ?', [$userId]);
        // استحقاق مكافأة المُحيل (لو الحساب جاي من إحالة)
        referral_on_activation($userId);
        admin_log('approve_user', 'user', $userId, $u['email']);

        // إيميل ترحيب (فشله مش بيوقف الموافقة)
        try {
            $html = build_email_template(
                'أهلًا بيك في ' . APP_NAME . ' 🎉',
                'تم تفعيل حسابك بنجاح يا ' . e($u['name']) . '! تقدر دلوقتي تسجل دخول، تكمّل هوية براندك، وتبدأ تولّد محتوى وتصميمات بالذكاء الاصطناعي.',
                'ابدأ الآن',
                url('login.php')
            );
            send_mail($u['email'], 'تم تفعيل حسابك — ' . APP_NAME, $html);
        } catch (Throwable $e) {
        }

        flash_set('success', 'تمت الموافقة على ' . e($u['name']) . ' وإرسال إيميل الترحيب ✓');
        redirect('admin/approvals.php');
    }

    // ── رفض ──
    if ($action === 'reject') {
        db_run('UPDATE users SET approval_status = "rejected" WHERE id = ?', [$userId]);
        admin_log('reject_user', 'user', $userId, $u['email']);
        flash_set('info', 'تم رفض الحساب — المستخدم مش هيقدر يسجل دخول');
        redirect('admin/approvals.php');
    }

    // ── إرجاع لقائمة الانتظار ──
    if ($action === 'reset') {
        db_run('UPDATE users SET approval_status = "pending", approved_at = NULL WHERE id = ?', [$userId]);
        admin_log('reset_approval', 'user', $userId, $u['email']);
        flash_set('info', 'تم إرجاع الحساب لقائمة الانتظار');
        redirect('admin/approvals.php');
    }
}

$manualOn = get_setting('manual_approval', '0') === '1';
$pending = db_all(
    'SELECT u.*, (SELECT COUNT(*) FROM contents c WHERE c.user_id = u.id) AS posts
     FROM users u WHERE u.approval_status = "pending" ORDER BY u.created_at ASC'
);
$recent = db_all(
    'SELECT * FROM users WHERE approval_status IN ("approved","rejected") AND approved_at IS NOT NULL
     ORDER BY approved_at DESC LIMIT 10'
);
$rejected = db_all('SELECT * FROM users WHERE approval_status = "rejected" ORDER BY updated_at DESC LIMIT 10');

$active = 'approvals';
$page_title = 'الموافقات';
include __DIR__ . '/../templates/admin-header.php';
?>

<div class="app">
    <?php include __DIR__ . '/../templates/admin-sidebar.php'; ?>
    <main class="main">
        <div class="page-head with-actions">
            <div>
                <h1>الموافقات ✅ <?php if ($pending): ?><span class="chip chip-primary"><?= count($pending) ?> في الانتظار</span><?php endif; ?></h1>
                <div class="sub">تفعيل يدوي للعملاء الجدد قبل ما يقدروا يستخدموا المنصة</div>
            </div>
            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="toggle_manual">
                <button type="submit" class="btn <?= $manualOn ? '' : 'btn-primary' ?>">
                    <?= $manualOn ? '⏸ إيقاف الموافقة اليدوية' : '▶ تفعيل الموافقة اليدوية' ?>
                </button>
            </form>
        </div>

        <?= render_flash() ?>

        <div class="alert <?= $manualOn ? 'success' : 'warning' ?>">
            <?= $manualOn
                ? '✓ الموافقة اليدوية <b>مفعّلة</b> — أي تسجيل جديد بيدخل قائمة الانتظار لحد ما توافق عليه (بعد تأكيد الإيميل).'
                : '⚠ الموافقة اليدوية <b>متوقفة</b> — الحسابات بتتفعل تلقائيًا بمجرد تأكيد الإيميل.' ?>
        </div>

        <!-- في الانتظار -->
        <div class="card" style="margin-top:20px">
            <div class="card-head"><h3>في انتظار الموافقة (<?= count($pending) ?>)</h3></div>
            <?php if (!$pending): ?>
                <p class="sub">مفيش حسابات مستنية — كله متظبط 🎉</p>
            <?php else: ?>
                <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>الاسم</th><th>الإيميل</th><th>الإيميل مؤكد؟</th><th>سجّل</th><th>إجراء</th></tr></thead>
                    <tbody>
                    <?php foreach ($pending as $p): ?>
                        <tr>
                            <td><b><?= e($p['name']) ?></b></td>
                            <td><?= e($p['email']) ?></td>
                            <td><?= $p['email_verified_at'] ? '✓ مؤكد' : '<span class="sub">لسه</span>' ?></td>
                            <td class="sub"><?= time_ago($p['created_at']) ?></td>
                            <td style="white-space:nowrap">
                                <form method="POST" style="display:inline">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="approve">
                                    <input type="hidden" name="user_id" value="<?= $p['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-primary">✓ موافقة + إيميل ترحيب</button>
                                </form>
                                <form method="POST" style="display:inline" onsubmit="return confirm('رفض الحساب؟')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="reject">
                                    <input type="hidden" name="user_id" value="<?= $p['id'] ?>">
                                    <button type="submit" class="btn btn-sm" style="color:#c0392b">✕ رفض</button>
                                </form>
                                <a href="<?= url('admin/user-view.php?id=' . $p['id']) ?>" class="btn btn-sm">عرض</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            <?php endif; ?>
        </div>

        <div class="split split-flex" style="--c1:1fr;--c2:1fr;gap:20px;margin-top:20px;align-items:flex-start">
            <div class="card">
                <div class="card-head"><h3>آخر الموافقات</h3></div>
                <?php if (!$recent): ?><p class="sub">لسه مفيش</p><?php endif; ?>
                <?php foreach ($recent as $r): ?>
                    <div style="display:flex;justify-content:space-between;padding:7px 0;border-bottom:1px dashed #eee;font-size:13px">
                        <span><b><?= e($r['name']) ?></b> <span class="sub"><?= e($r['email']) ?></span></span>
                        <span class="sub"><?= time_ago($r['approved_at']) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="card">
                <div class="card-head"><h3>المرفوضين</h3></div>
                <?php if (!$rejected): ?><p class="sub">مفيش حسابات مرفوضة</p><?php endif; ?>
                <?php foreach ($rejected as $r): ?>
                    <div style="display:flex;justify-content:space-between;align-items:center;padding:7px 0;border-bottom:1px dashed #eee;font-size:13px">
                        <span><b><?= e($r['name']) ?></b> <span class="sub"><?= e($r['email']) ?></span></span>
                        <form method="POST">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="reset">
                            <input type="hidden" name="user_id" value="<?= $r['id'] ?>">
                            <button type="submit" class="btn btn-sm">↩ إرجاع للانتظار</button>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

    </main>
</div>

<?php include __DIR__ . '/../templates/admin-footer.php'; ?>

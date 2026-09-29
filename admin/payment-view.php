<?php
/**
 * Spread AI — الأدمن: تفاصيل طلب الدفع (المرحلة 10)
 * بيانات العميل · الباقة · المبلغ · الطريقة · الإيصال · الأوقات · المراجِع · الحالة · الملاحظات · سجل النشاط
 * الإجراءات: بدء المراجعة · اعتماد · رفض · طلب معلومات إضافية · ملاحظة داخلية
 */
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/credits.php';
require_once __DIR__ . '/../includes/billing.php';

require_admin();
require_admin_can('view_users');
billing_ready();
$admin = current_admin();
$id = (int) ($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    decode_b64_fields();
    $action = (string) ($_POST['action'] ?? '');
    $aid = (int) $admin['id'];
    if (in_array($action, ['approve', 'reject', 'info', 'review'], true)) require_admin_can('add_credits');
    $res = ['ok' => true];
    switch ($action) {
        case 'review':  $res = billing_mark_review($id, $aid) ? ['ok' => true] : ['ok' => false, 'error' => 'الطلب مش في الانتظار']; $msg = 'بدأت المراجعة'; break;
        case 'approve': $res = billing_approve($id, $aid, trim((string) ($_POST['note'] ?? ''))); $msg = 'اتعتمد الدفع ✓ — الباقة اتفعّلت والكريدت اتضاف للمحفظة، والعميل اتبعتله إشعار'; break;
        case 'reject':  $res = billing_reject($id, $aid, (string) ($_POST['reason'] ?? '')); $msg = 'اترفض الطلب — العميل اتبعتله السبب'; break;
        case 'info':    $res = billing_request_info($id, $aid, (string) ($_POST['message'] ?? '')); $msg = 'اتبعت طلب المعلومات للعميل'; break;
        case 'note':
            $n = trim((string) ($_POST['note'] ?? ''));
            if ($n !== '') { billing_event($id, 'admin', $aid, 'note', null, null, $n); $msg = 'اتسجلت الملاحظة'; }
            else { $res = ['ok' => false, 'error' => 'اكتب الملاحظة']; }
            break;
        default: $res = ['ok' => false, 'error' => 'عملية غير معروفة'];
    }
    if ($res['ok'] && in_array($action, ['reject', 'info', 'review'], true)) admin_log('payment_' . $action, 'payment_request', $id);
    flash_set($res['ok'] ? 'success' : 'danger', $res['ok'] ? $msg : ($res['error'] ?? 'تعذّر'));
    redirect('admin/payment-view.php?id=' . $id);
}

$r = billing_request($id);
if (!$r) {
    flash_set('danger', 'الطلب مش موجود');
    redirect('admin/payments.php');
}
$events = billing_events($id);
$promo = $r['promo_json'] ? (json_decode($r['promo_json'], true) ?: []) : [];
$s = billing_statuses()[$r['status']];
$open = in_array($r['status'], ['pending', 'under_review'], true);
$prev = (int) (db_one('SELECT COUNT(*) n FROM payment_requests WHERE user_id = ? AND status = "approved"', [$r['user_id']])['n'] ?? 0);
$isImg = $r['proof_path'] && !preg_match('/\.pdf$/i', (string) $r['proof_path']);
$dupRef = $r['transaction_ref'] ? db_one('SELECT id FROM payment_requests WHERE transaction_ref = ? AND id <> ? AND status IN ("pending","under_review","approved") LIMIT 1', [$r['transaction_ref'], $id]) : null;

$active = 'payments';
$page_title = 'طلب دفع #' . $id;
include __DIR__ . '/../templates/admin-header.php';
?>
<div class="app">
    <?php include __DIR__ . '/../templates/admin-sidebar.php'; ?>
    <main class="main">
        <div class="page-head with-actions">
            <div>
                <a href="<?= url('admin/payments.php') ?>" class="text-mute" style="font-size:13px">← المدفوعات</a>
                <h1>طلب دفع #<?= $id ?> <span class="chip" style="color:<?= e($s[1]) ?>;border-color:<?= e($s[1]) ?>;font-size:14px"><?= e($s[0]) ?> · <?= e($s[2]) ?></span></h1>
            </div>
        </div>
        <?= render_flash() ?>
        <?php if ($dupRef): ?><div class="alert warning">⚠️ نفس رقم العملية موجود في طلب تاني <a href="<?= url('admin/payment-view.php?id=' . (int) $dupRef['id']) ?>">#<?= (int) $dupRef['id'] ?></a> — راجع قبل الاعتماد.</div><?php endif; ?>
        <?php if ($r['info_request'] && $open): ?><div class="alert info">مستني رد العميل على: «<?= e($r['info_request']) ?>»</div><?php endif; ?>

        <div class="split split-flex" style="--c1:1.4fr;--c2:1fr;gap:20px;align-items:flex-start">
            <div>
                <div class="card">
                    <div class="card-head"><h3>البيانات</h3></div>
                    <table class="tbl kv">
                        <tr><th>العميل</th><td><a href="<?= url('admin/user-view.php?id=' . (int) $r['user_id']) ?>"><b><?= e($r['user_name']) ?></b></a> · #<?= (int) $r['user_id'] ?> <?= $prev ? '<span class="chip">' . $prev . ' دفعة سابقة معتمدة</span>' : '<span class="chip">أول دفعة</span>' ?></td></tr>
                        <tr><th>الإيميل</th><td dir="ltr"><?= e($r['contact_email'] ?: $r['user_email']) ?></td></tr>
                        <tr><th>الموبايل</th><td dir="ltr"><?= e($r['contact_phone'] ?: (string) $r['user_phone']) ?></td></tr>
                        <tr><th>الباقة</th><td><b><?= e($r['plan_name']) ?></b> · <?= (int) $r['credits'] ?> كريدت<?= (int) $r['bonus_credits'] ? ' + 🎁 ' . (int) $r['bonus_credits'] : '' ?> · <?= (int) $r['validity_days'] ?> يوم<?= (int) $r['extra_days'] ? ' + ' . (int) $r['extra_days'] . ' يوم' : '' ?></td></tr>
                        <tr><th>المبلغ</th><td><b style="font-size:18px"><?= number_format((float) $r['final_amount'], 2) ?> <?= e($r['currency']) ?></b>
                            <?php if ((float) $r['discount_amount'] > 0): ?> <small class="sub">(الأصلي <?= number_format((float) $r['original_amount'], 2) ?> − خصم <?= number_format((float) $r['discount_amount'], 2) ?>)</small><?php endif; ?></td></tr>
                        <?php if ($r['promo_code']): ?><tr><th>كود الخصم</th><td><span class="chip" dir="ltr"><?= e($r['promo_code']) ?></span> <?= e($promo['label'] ?? '') ?></td></tr><?php endif; ?>
                        <tr><th>طريقة الدفع</th><td><?= e($r['method_label']) ?></td></tr>
                        <tr><th>رقم العملية</th><td dir="ltr"><?= e((string) $r['transaction_ref']) ?: '—' ?></td></tr>
                        <tr><th>وقت الطلب</th><td><?= e(fmt_date($r['created_at'], true)) ?></td></tr>
                        <tr><th>وقت التحويل</th><td><?= $r['transferred_at'] ? e(fmt_date($r['transferred_at'], true)) : '—' ?></td></tr>
                        <tr><th>المراجِع</th><td><?= e((string) $r['reviewer_name']) ?: '—' ?><?= $r['reviewed_at'] ? ' · ' . e(fmt_date($r['reviewed_at'], true)) : '' ?></td></tr>
                        <?php if ($r['user_note']): ?><tr><th>ملاحظة العميل</th><td><?= nl2br(e($r['user_note'])) ?></td></tr><?php endif; ?>
                        <?php if ($r['admin_note']): ?><tr><th>ملاحظة الاعتماد</th><td><?= nl2br(e($r['admin_note'])) ?></td></tr><?php endif; ?>
                        <?php if ($r['reject_reason']): ?><tr><th>سبب الرفض</th><td><?= e($r['reject_reason']) ?></td></tr><?php endif; ?>
                        <?php if ($r['user_plan_id']): ?><tr><th>الاشتراك</th><td>دورة #<?= (int) $r['user_plan_id'] ?> — <a href="<?= url('admin/user-view.php?id=' . (int) $r['user_id'] . '&tab=usage') ?>">ملف العميل ←</a></td></tr><?php endif; ?>
                    </table>
                </div>

                <div class="card mt-20">
                    <div class="card-head"><h3>سجل النشاط</h3></div>
                    <ol class="pv-hist">
                        <?php foreach ($events as $ev): ?>
                        <li><b><?= e(billing_action_label($ev['action'])) ?></b>
                            <?php if ($ev['from_status'] || $ev['to_status']): ?><small class="chip"><?= e(billing_status_label((string) $ev['from_status'])) ?> ← <?= e(billing_status_label((string) $ev['to_status'])) ?></small><?php endif; ?>
                            <small class="sub"> · <?= e(fmt_date($ev['created_at'], true)) ?> · <?= $ev['actor_type'] === 'admin' ? e((string) $ev['admin_name']) : ($ev['actor_type'] === 'user' ? 'العميل' : 'النظام') ?></small>
                            <?php if ($ev['note']): ?><div class="sub" style="white-space:pre-wrap"><?= e($ev['note']) ?></div><?php endif; ?>
                        </li>
                        <?php endforeach; ?>
                    </ol>
                    <form method="POST" data-safe-post style="display:flex;gap:8px;margin-top:10px">
                        <?= csrf_field() ?><input type="hidden" name="action" value="note">
                        <input class="input" name="note" placeholder="ملاحظة داخلية (مش بتظهر للعميل)">
                        <button class="btn ghost sm">إضافة</button>
                    </form>
                </div>
            </div>

            <div>
                <div class="card">
                    <div class="card-head"><h3>الإيصال</h3><?php if ($r['proof_path']): ?><a href="<?= e(billing_proof_url($id, true)) ?>" target="_blank" rel="noopener" class="btn ghost sm">فتح ↗</a><?php endif; ?></div>
                    <?php if ($isImg): ?>
                        <a href="<?= e(billing_proof_url($id, true)) ?>" target="_blank" rel="noopener"><img src="<?= e(billing_proof_url($id, true)) ?>" alt="إيصال التحويل" style="width:100%;border-radius:12px;border:1px solid var(--line)"></a>
                    <?php elseif ($r['proof_path']): ?>
                        <p>📄 الإيصال ملف PDF — <a href="<?= e(billing_proof_url($id, true)) ?>" target="_blank" rel="noopener">افتحه</a></p>
                    <?php else: ?>
                        <p class="sub">مفيش إيصال (باقة مجانية بكود عرض).</p>
                    <?php endif; ?>
                </div>

                <?php if ($open && admin_can('add_credits')): ?>
                <div class="card mt-20">
                    <div class="card-head"><h3>الإجراء</h3></div>
                    <?php if ($r['status'] === 'pending'): ?>
                        <form method="POST" data-safe-post style="margin-bottom:12px"><?= csrf_field() ?><input type="hidden" name="action" value="review">
                            <button class="btn ghost full">🔎 بدء المراجعة (Under Review)</button></form>
                    <?php endif; ?>
                    <form method="POST" data-safe-post onsubmit="return confirm('تأكيد الدفع وتفعيل الباقة وإضافة <?= (int) $r['credits'] + (int) $r['bonus_credits'] ?> كريدت؟')">
                        <?= csrf_field() ?><input type="hidden" name="action" value="approve">
                        <input class="input" name="note" placeholder="ملاحظة الاعتماد (اختياري)" style="margin-bottom:8px">
                        <button class="btn full" style="background:#10A8A0;border-color:#10A8A0">✓ Approve — اعتماد وتفعيل</button>
                    </form>
                    <hr style="border:0;border-top:1px dashed var(--line);margin:16px 0">
                    <form method="POST" data-safe-post>
                        <?= csrf_field() ?><input type="hidden" name="action" value="info">
                        <textarea class="textarea" name="message" rows="2" placeholder="مثلًا: الإيصال مش واضح — ابعت صورة أوضح فيها رقم العملية"></textarea>
                        <button class="btn ghost full" style="margin-top:6px">❓ Request More Information</button>
                    </form>
                    <hr style="border:0;border-top:1px dashed var(--line);margin:16px 0">
                    <form method="POST" data-safe-post onsubmit="return confirm('رفض الطلب؟ العميل هيشوف السبب.')">
                        <?= csrf_field() ?><input type="hidden" name="action" value="reject">
                        <input class="input" name="reason" placeholder="سبب الرفض (العميل هيشوفه)" required style="margin-bottom:8px">
                        <button class="btn ghost full" style="color:#E0475B;border-color:#E0475B">✕ Reject — رفض</button>
                    </form>
                </div>
                <?php elseif ($open): ?>
                    <div class="card mt-20"><p class="sub">الاعتماد والرفض محتاجين صلاحية «إضافة كريدت».</p></div>
                <?php endif; ?>
            </div>
        </div>
    </main>
</div>
<style>
.tbl.kv th { width: 130px; text-align: start; color: var(--mute); font-weight: 500; }
.pv-hist { list-style: none; margin: 0; padding: 0; display: grid; gap: 10px; }
.pv-hist li { padding: 10px 12px; border-radius: 12px; background: var(--surface-2, #f5f7fb); border-inline-start: 3px solid var(--primary, #0C87EF); }
</style>
<?php include __DIR__ . '/../templates/admin-footer.php'; ?>

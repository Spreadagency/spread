<?php
/**
 * Spread AI — طلبات الدفع للعميل (المرحلة 10)
 * القائمة · تفاصيل الطلب بحالته وسجله · الرد على «طلب معلومات إضافية» · الإلغاء
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/credits.php';
require_once __DIR__ . '/../includes/billing.php';

require_login();
$user = current_user();
$uid = (int) $user['id'];
billing_ready();

$openId = (int) ($_GET['id'] ?? 0);
$req = $openId ? db_one('SELECT * FROM payment_requests WHERE id = ? AND user_id = ?', [$openId, $uid]) : null;
$list = db_all('SELECT id, plan_name, final_amount, method_label, status, created_at, info_request FROM payment_requests WHERE user_id = ? ORDER BY id DESC LIMIT 50', [$uid]);
$events = $req ? billing_events((int) $req['id']) : [];
$promo = $req && $req['promo_json'] ? (json_decode($req['promo_json'], true) ?: []) : [];
$st = billing_statuses();
// إشعارات الطلب ده بتتقري لما يفتحه
if ($req) {
    try { db_run('UPDATE user_notifications SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL AND url LIKE ?', [$uid, 'payments.php?id=' . (int) $req['id'] . '%']); } catch (\Throwable $e) {}
}

$active = 'packages';
$page_title = 'طلبات الدفع';
$use_app = true;
include __DIR__ . '/../templates/header.php';
?>
<div class="app">
    <?php include __DIR__ . '/../templates/sidebar.php'; ?>
    <main class="main">
        <?php include __DIR__ . '/../templates/topbar.php'; ?>
        <?= render_flash() ?>

        <div class="page-head with-actions">
            <div>
                <h1>طلبات الدفع</h1>
                <div class="sub">حالة كل طلب اشتراك — والباقة بتتفعّل أول ما الطلب يتعتمد.</div>
            </div>
            <a href="<?= url('packages.php') ?>" class="btn sm">＋ اشترك في باقة</a>
        </div>

        <?php if ($req): $s = $st[$req['status']] ?? ['', '#888']; ?>
        <?php if (!empty($_GET['sent'])): ?>
            <div class="alert success">✓ وصلنا طلبك — هنراجع التحويل ونفعّل الباقة، وهيوصلك إشعار أول ما تتفعّل.</div>
        <?php endif; ?>
        <section class="card bl-req">
            <div class="bl-req-head">
                <div>
                    <small class="sub">طلب #<?= (int) $req['id'] ?> · <?= e(fmt_date($req['created_at'], true)) ?></small>
                    <h2><?= e($req['plan_name']) ?></h2>
                </div>
                <span class="bl-st" style="--st:<?= e($s[1]) ?>"><?= e($s[0]) ?></span>
            </div>

            <?php if ($req['info_request'] && in_array($req['status'], ['pending', 'under_review'], true)): ?>
            <div class="bl-info">
                <b>⚠️ محتاجين منك:</b>
                <p><?= nl2br(e($req['info_request'])) ?></p>
                <textarea class="textarea" id="rp-note" rows="2" maxlength="1000" placeholder="اكتب ردك هنا"></textarea>
                <label class="btn ghost sm bl-file">📎 إيصال جديد (اختياري)<input type="file" id="rp-proof" accept="image/jpeg,image/png,image/webp,application/pdf" hidden></label>
                <button class="btn sm" onclick="respond(<?= (int) $req['id'] ?>, this)">إرسال الرد</button>
            </div>
            <?php endif; ?>
            <?php if ($req['status'] === 'rejected' && $req['reject_reason']): ?>
                <div class="alert danger">سبب الرفض: <?= e($req['reject_reason']) ?> — <a href="<?= url('checkout.php?package=' . (int) $req['package_id']) ?>">ابعت طلب جديد ←</a></div>
            <?php endif; ?>
            <?php if ($req['status'] === 'approved'): ?>
                <div class="alert success">🎉 الباقة اتفعّلت واتضاف <?= (int) $req['credits'] + (int) $req['bonus_credits'] ?> كريدت لمحفظتك. <a href="<?= url('credits.php') ?>">المحفظة ←</a></div>
            <?php endif; ?>

            <div class="bl-req-grid">
                <div><span>السعر الأصلي</span><b><?= number_format((float) $req['original_amount'], 2) ?> ج</b></div>
                <?php if ((float) $req['discount_amount'] > 0): ?><div><span>خصم<?= $req['promo_code'] ? ' (' . e($req['promo_code']) . ')' : '' ?></span><b>−<?= number_format((float) $req['discount_amount'], 2) ?> ج</b></div><?php endif; ?>
                <div class="total"><span>المدفوع</span><b><?= number_format((float) $req['final_amount'], 2) ?> ج</b></div>
                <div><span>طريقة الدفع</span><b><?= e($req['method_label']) ?></b></div>
                <div><span>الكريدت</span><b><?= (int) $req['credits'] ?><?= (int) $req['bonus_credits'] ? ' + 🎁 ' . (int) $req['bonus_credits'] : '' ?></b></div>
                <div><span>المدة</span><b><?= (int) $req['validity_days'] + (int) $req['extra_days'] ?> يوم</b></div>
                <?php if ($req['transaction_ref']): ?><div><span>رقم العملية</span><b dir="ltr"><?= e($req['transaction_ref']) ?></b></div><?php endif; ?>
                <?php if ($req['transferred_at']): ?><div><span>وقت التحويل</span><b><?= e(fmt_date($req['transferred_at'], true)) ?></b></div><?php endif; ?>
                <?php if ($req['proof_path']): ?><div><span>الإيصال</span><b><a href="<?= e(billing_proof_url((int) $req['id'])) ?>" target="_blank" rel="noopener">عرض ↗</a></b></div><?php endif; ?>
            </div>

            <h3 class="bl-h3">سجل الطلب</h3>
            <ol class="bl-timeline">
                <?php foreach ($events as $ev): if ($ev['action'] === 'note' && $ev['actor_type'] === 'system') continue; ?>
                <li><b><?= e(billing_action_label($ev['action'])) ?></b>
                    <small class="sub"><?= e(fmt_date($ev['created_at'], true)) ?><?= $ev['actor_type'] === 'admin' ? ' · فريق Spread' : '' ?></small>
                    <?php if ($ev['note'] && in_array($ev['action'], ['info_requested', 'rejected', 'user_responded', 'credits_added', 'plan_started'], true)): ?><p><?= nl2br(e($ev['note'])) ?></p><?php endif; ?>
                </li>
                <?php endforeach; ?>
            </ol>

            <?php if (in_array($req['status'], ['pending', 'under_review'], true)): ?>
                <button class="btn ghost sm bl-cancel" onclick="cancelReq(<?= (int) $req['id'] ?>, this)">إلغاء الطلب</button>
            <?php endif; ?>
        </section>
        <?php endif; ?>

        <section class="card">
            <h3 class="bl-h3">كل الطلبات</h3>
            <?php if (!$list): ?>
                <p class="sub">لسه مابعتش أي طلب دفع. <a href="<?= url('packages.php') ?>">شوف الباقات ←</a></p>
            <?php else: ?>
            <div class="bl-list">
                <?php foreach ($list as $r): $s = $st[$r['status']] ?? ['', '#888']; ?>
                <a href="<?= url('payments.php?id=' . (int) $r['id']) ?>" class="bl-list-row<?= $req && (int) $req['id'] === (int) $r['id'] ? ' on' : '' ?>">
                    <span><b>#<?= (int) $r['id'] ?> · <?= e($r['plan_name']) ?></b><small class="sub"><?= e(fmt_date($r['created_at'], true)) ?> · <?= e($r['method_label']) ?></small></span>
                    <span class="bl-list-end"><b><?= number_format((float) $r['final_amount'], 0) ?> ج</b>
                        <span class="bl-st" style="--st:<?= e($s[1]) ?>"><?= $r['info_request'] && in_array($r['status'], ['pending', 'under_review'], true) ? 'محتاج ردك' : e($s[0]) ?></span></span>
                </a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </section>
    </main>
</div>
<script>
async function cancelReq(id, btn) {
    if (!confirm('تلغي طلب الدفع ده؟')) return;
    btn.disabled = true;
    const r = await SpreadAPI.post('billing', 'cancel', { id });
    if (!r.ok) { btn.disabled = false; return showToast(r.error, 'danger'); }
    location.reload();
}
async function respond(id, btn) {
    const note = document.getElementById('rp-note').value.trim(), f = document.getElementById('rp-proof').files[0];
    if (!note && !f) return showToast('اكتب ردك أو ارفع إيصال جديد', 'danger');
    btn.disabled = true; btn.textContent = 'بنبعت…';
    const fd = new FormData();
    fd.append('action', 'respond'); fd.append('id', id); fd.append('note', note);
    if (f) fd.append('proof', f);
    let d;
    try {
        const res = await fetch('<?= url('api/billing.php') ?>', { method: 'POST', body: fd, credentials: 'same-origin',
            headers: { 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content, 'X-Requested-With': 'XMLHttpRequest' } });
        d = await res.json();
    } catch (e) { d = { ok: false, error: 'خطأ في الاتصال' }; }
    if (!d.ok) { btn.disabled = false; btn.textContent = 'إرسال الرد'; return showToast(d.error, 'danger'); }
    location.reload();
}
</script>
<?php include __DIR__ . '/../templates/footer.php'; ?>

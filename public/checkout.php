<?php
/**
 * Spread AI — صفحة الدفع (المرحلة 10)
 * الباقة ← كود الخصم (بيتحسب من السيرفر) ← طريقة الدفع وبيانات التحويل ← رفع الإيصال ← «إرسال طلب الدفع»
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/credits.php';
require_once __DIR__ . '/../includes/billing.php';

require_login();
$user = current_user();
$uid = (int) $user['id'];

$pkg = billing_package((int) ($_GET['package'] ?? 0));
if (!$pkg) {
    flash_set('warning', 'اختار باقة الأول');
    redirect('packages.php');
}
$dup = billing_ready() ? db_one('SELECT id FROM payment_requests WHERE user_id = ? AND package_id = ? AND status IN ("pending","under_review") LIMIT 1', [$uid, $pkg['id']]) : null;
if ($dup) {
    flash_set('info', 'عندك طلب لنفس الباقة لسه بيتراجع — تابعه من هنا');
    redirect('payments.php?id=' . (int) $dup['id']);
}
$methods = billing_methods();
$crShow = !function_exists('credits_show_numbers') || credits_show_numbers();
$price = (float) $pkg['price_egp'];
$pkgBonus = (int) ($pkg['bonus_credits'] ?? 0);
$initCode = strtoupper(preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($_GET['code'] ?? '')));

$active = 'packages';
$page_title = 'الدفع — ' . $pkg['name'];
$use_app = true;   // SpreadAPI (حساب كود الخصم)
include __DIR__ . '/../templates/header.php';
?>
<div class="app">
    <?php include __DIR__ . '/../templates/sidebar.php'; ?>
    <main class="main">
        <?php include __DIR__ . '/../templates/topbar.php'; ?>
        <?= render_flash() ?>

        <div class="page-head">
            <a href="<?= url('packages.php') ?>" class="text-mute" style="font-size:13px">← كل الباقات</a>
            <h1>الدفع</h1>
            <div class="sub">حوّل المبلغ وارفع صورة الإيصال — الباقة بتتفعّل بعد ما نراجع التحويل.</div>
        </div>

        <form id="co" class="bl-checkout" onsubmit="return coSubmit(event)" novalidate>
            <div class="bl-co-main">

                <!-- ① الباقة -->
                <section class="card bl-co-sec">
                    <h3>① الباقة</h3>
                    <div class="bl-co-plan">
                        <div><b><?= e($pkg['name']) ?></b>
                            <small class="sub"><?= $crShow ? (int) $pkg['credits'] . ' كريدت · ' : '' ?><?= (int) ($pkg['validity_days'] ?? 30) ?> يوم<?= $pkgBonus ? ' · 🎁 +' . $pkgBonus . ' كريدت هدية' : '' ?></small></div>
                        <b class="bl-co-price"><?= number_format($price, 0) ?> <small>جنيه</small></b>
                    </div>
                </section>

                <!-- ② كود الخصم -->
                <section class="card bl-co-sec">
                    <h3>② كود الخصم <small class="sub">(اختياري)</small></h3>
                    <div class="bl-promo">
                        <input class="input" id="co-code" dir="ltr" maxlength="40" placeholder="SPREAD50" value="<?= e($initCode) ?>" autocomplete="off" style="text-transform:uppercase">
                        <button type="button" class="btn ghost" id="co-apply" onclick="coApply()">تطبيق</button>
                    </div>
                    <div id="co-promo-msg" class="bl-promo-msg" role="status" aria-live="polite"></div>
                </section>

                <!-- ③ طريقة الدفع -->
                <section class="card bl-co-sec" id="co-pay">
                    <h3>③ طريقة الدفع</h3>
                    <?php if (!$methods): ?>
                        <p class="sub">مفيش طرق دفع متاحة دلوقتي — كلّم الدعم.</p>
                    <?php endif; ?>
                    <div class="bl-methods">
                        <?php foreach ($methods as $i => $m): ?>
                        <label class="bl-method">
                            <input type="radio" name="method" value="<?= e($m['mkey']) ?>" <?= $i === 0 ? 'checked' : '' ?> onchange="coMethod()">
                            <span class="bl-method-body">
                                <b><?= e($m['label']) ?></b>
                                <span class="bl-method-details">
                                    <?php if ($m['account']): ?>
                                        <span class="bl-acc"><span dir="ltr" id="acc-<?= (int) $m['id'] ?>"><?= e($m['account']) ?></span>
                                            <button type="button" class="btn ghost sm" onclick="coCopy('acc-<?= (int) $m['id'] ?>', this)">📋 نسخ</button></span>
                                    <?php endif; ?>
                                    <?php if ($m['account_name']): ?><small class="sub">باسم: <?= e($m['account_name']) ?></small><?php endif; ?>
                                    <?php if ($m['instructions']): ?><small class="sub"><?= nl2br(e($m['instructions'])) ?></small><?php endif; ?>
                                    <?php if ($m['pay_link']): ?><a class="btn soft sm" href="<?= e($m['pay_link']) ?>" target="_blank" rel="noopener">ادفع من اللينك ↗</a><?php endif; ?>
                                </span>
                            </span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </section>

                <!-- ④ الإيصال -->
                <section class="card bl-co-sec" id="co-proof-sec">
                    <h3>④ إثبات التحويل</h3>
                    <label class="bl-drop" id="co-drop">
                        <input type="file" id="co-proof" accept="image/jpeg,image/png,image/webp,application/pdf" hidden onchange="coProof(this)">
                        <span id="co-drop-txt">📎 <b>ارفع صورة إيصال التحويل</b><small class="sub">JPG · PNG · WebP · PDF — حد أقصى 6 ميجا</small></span>
                        <img id="co-drop-img" alt="" hidden>
                    </label>
                    <div class="field-row">
                        <div class="field"><label>رقم العملية (اختياري)</label>
                            <input class="input" id="co-ref" dir="ltr" maxlength="120" placeholder="Transaction ID / رقم المرجع"></div>
                        <div class="field"><label>وقت التحويل</label>
                            <input class="input" id="co-at" type="datetime-local"></div>
                    </div>
                </section>

                <!-- ⑤ بيانات التواصل -->
                <section class="card bl-co-sec">
                    <h3>⑤ بياناتك</h3>
                    <div class="field-row">
                        <div class="field"><label>الاسم</label><input class="input" value="<?= e($user['name']) ?>" disabled></div>
                        <div class="field"><label>الإيميل</label><input class="input" dir="ltr" value="<?= e($user['email']) ?>" disabled></div>
                        <div class="field"><label>الموبايل <span class="req">*</span></label>
                            <input class="input" id="co-phone" dir="ltr" type="tel" maxlength="20" value="<?= e((string) ($user['phone'] ?? '')) ?>" placeholder="01xxxxxxxxx"></div>
                    </div>
                    <div class="field"><label>ملاحظة (اختياري)</label>
                        <textarea class="textarea" id="co-note" rows="2" maxlength="1000" placeholder="أي تفاصيل تساعدنا نراجع التحويل بسرعة"></textarea></div>
                </section>
            </div>

            <!-- الملخص -->
            <aside class="card bl-co-sum">
                <h3>الملخص</h3>
                <div class="bl-sum-row"><span>الباقة</span><b><?= e($pkg['name']) ?></b></div>
                <div class="bl-sum-row"><span>السعر الأصلي</span><b id="s-orig"><?= number_format($price, 2) ?> ج</b></div>
                <div class="bl-sum-row disc" id="s-disc-row" hidden><span id="s-disc-lbl">خصم</span><b id="s-disc">−0</b></div>
                <div class="bl-sum-total"><span>الإجمالي</span><b id="s-final"><?= number_format($price, 2) ?> ج</b></div>
                <?php if ($crShow): ?><div class="bl-sum-row"><span>الكريدت</span><b><?= (int) $pkg['credits'] ?></b></div><?php endif; ?>
                <div class="bl-sum-row bonus" id="s-bonus-row" <?= $pkgBonus ? '' : 'hidden' ?>><span>🎁 هدية</span><b id="s-bonus">+<?= $pkgBonus ?> كريدت</b></div>
                <div class="bl-sum-row" id="s-days-row" hidden><span>⏳ أيام زيادة</span><b id="s-days"></b></div>
                <div class="bl-sum-row"><span>المدة</span><b id="s-validity"><?= (int) ($pkg['validity_days'] ?? 30) ?> يوم</b></div>
                <button type="submit" class="btn full lg" id="co-submit" <?= $methods ? '' : 'disabled' ?>>إرسال طلب الدفع</button>
                <p class="sub bl-sum-note">الباقة مش هتتفعّل تلقائيًا — بنراجع التحويل ونبعتلك إشعار أول ما تتفعّل.</p>
            </aside>
        </form>
    </main>
</div>

<script>
const CO = { pkg: <?= (int) $pkg['id'] ?>, price: <?= json_encode($price) ?>, bonus: <?= $pkgBonus ?>, days: <?= (int) ($pkg['validity_days'] ?? 30) ?>, code: '', final: <?= json_encode($price) ?> };
const csrf = () => document.querySelector('meta[name="csrf-token"]').content;
const fmt = n => Number(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ج';

async function coApply() {
    const inp = document.getElementById('co-code'), msg = document.getElementById('co-promo-msg'), btn = document.getElementById('co-apply');
    const code = inp.value.trim().toUpperCase();
    if (!code) { coReset(); msg.textContent = ''; return; }
    btn.disabled = true; btn.textContent = '…';
    const method = (document.querySelector('input[name=method]:checked') || {}).value || '';
    const r = await SpreadAPI.post('billing', 'quote', { package_id: CO.pkg, code: code, method: method });
    btn.disabled = false; btn.textContent = 'تطبيق';
    if (!r.ok) {
        coReset();
        msg.className = 'bl-promo-msg bad';
        msg.textContent = r.error || 'الكود غير صحيح أو منتهي';
        return;
    }
    const q = r.quote;
    CO.code = q.code; CO.final = q.final;
    msg.className = 'bl-promo-msg ok';
    msg.innerHTML = '✓ تم تطبيق الكود <b dir="ltr">' + q.code + '</b> — ' + q.label;
    document.getElementById('s-disc-row').hidden = !(q.discount > 0);
    document.getElementById('s-disc').textContent = '−' + fmt(q.discount);
    document.getElementById('s-final').textContent = fmt(q.final);
    document.getElementById('s-bonus-row').hidden = !(q.bonus_credits > 0);
    document.getElementById('s-bonus').textContent = '+' + q.bonus_credits + ' كريدت';
    document.getElementById('s-days-row').hidden = !(q.extra_days > 0);
    document.getElementById('s-days').textContent = '+' + q.extra_days + ' يوم';
    document.getElementById('s-validity').textContent = (CO.days + q.extra_days) + ' يوم';
    coFree(q.final <= 0);
}
function coReset() {
    CO.code = ''; CO.final = CO.price;
    document.getElementById('s-disc-row').hidden = true;
    document.getElementById('s-final').textContent = fmt(CO.price);
    document.getElementById('s-bonus-row').hidden = !(CO.bonus > 0);
    document.getElementById('s-bonus').textContent = '+' + CO.bonus + ' كريدت';
    document.getElementById('s-days-row').hidden = true;
    document.getElementById('s-validity').textContent = CO.days + ' يوم';
    coFree(false);
}
// باقة مجانية بالكود: مفيش تحويل ولا إيصال
function coFree(free) {
    document.getElementById('co-pay').hidden = free;
    document.getElementById('co-proof-sec').hidden = free;
}
function coMethod() { if (CO.code) coApply(); }
function coCopy(id, btn) {
    navigator.clipboard.writeText(document.getElementById(id).textContent.trim()).then(() => { btn.textContent = '✓ اتنسخ'; setTimeout(() => btn.textContent = '📋 نسخ', 1500); });
}
function coProof(inp) {
    const f = inp.files[0], img = document.getElementById('co-drop-img'), txt = document.getElementById('co-drop-txt');
    if (!f) return;
    if (f.size > 6 * 1024 * 1024) { showToast('صورة الإيصال أكبر من 6 ميجا', 'danger'); inp.value = ''; return; }
    if (f.type.startsWith('image/')) { img.src = URL.createObjectURL(f); img.hidden = false; txt.hidden = true; }
    else { img.hidden = true; txt.hidden = false; txt.innerHTML = '📄 <b>' + f.name.replace(/[<>&]/g, '') + '</b><small class="sub">اضغط للتغيير</small>'; }
}
document.getElementById('co-code').addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); coApply(); } });

async function coSubmit(e) {
    e.preventDefault();
    const free = CO.final <= 0;
    const method = (document.querySelector('input[name=method]:checked') || {}).value || '';
    const proof = document.getElementById('co-proof').files[0];
    const phone = document.getElementById('co-phone').value.trim();
    if (!free && !method) return showToast('اختار طريقة الدفع', 'danger'), false;
    if (!free && !proof) return showToast('ارفع صورة إيصال التحويل', 'danger'), false;
    if (phone.replace(/\D/g, '').length < 8) return showToast('اكتب رقم موبايلك', 'danger'), false;
    const btn = document.getElementById('co-submit');
    btn.disabled = true; btn.textContent = 'بنبعت الطلب…';
    const fd = new FormData();
    fd.append('action', 'submit'); fd.append('package_id', CO.pkg); fd.append('method', free ? '' : method);
    fd.append('promo_code', CO.code); fd.append('transaction_ref', document.getElementById('co-ref').value.trim());
    fd.append('transferred_at', document.getElementById('co-at').value); fd.append('note', document.getElementById('co-note').value.trim());
    fd.append('phone', phone);
    if (proof && !free) fd.append('proof', proof);
    let d = null;
    try {
        const res = await fetch('<?= url('api/billing.php') ?>', { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-CSRF-Token': csrf(), 'X-Requested-With': 'XMLHttpRequest' } });
        d = await res.json();
    } catch (err) { d = { ok: false, error: 'خطأ في الاتصال — جرّب تاني' }; }
    if (!d || !d.ok) {
        btn.disabled = false; btn.textContent = 'إرسال طلب الدفع';
        showToast((d && d.error) || 'تعذّر إرسال الطلب', 'danger');
        if (d && d.existing) setTimeout(() => location.href = '<?= url('payments.php?id=') ?>' + d.existing, 1200);
        return false;
    }
    location.href = d.redirect;
    return false;
}
<?php if ($initCode !== ''): ?>document.addEventListener('DOMContentLoaded', () => setTimeout(coApply, 300));<?php endif; ?>
</script>
<?php include __DIR__ . '/../templates/footer.php'; ?>

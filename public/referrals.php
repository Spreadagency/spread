<?php
/** Spread AI v2 — صفحة العميل: اربح كريدت */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/credits.php';
require_once __DIR__ . '/../includes/offers.php';

require_login();
$user = current_user();

if (get_setting('referral_enabled', '1') !== '1') {
    flash_set('warning', 'نظام الدعوات موقوف حاليًا');
    redirect('dashboard.php');
}

$offer = ensure_user_referral_offer((int) $user['id']);
if (!$offer) {
    flash_set('danger', 'تعذّر تجهيز كود الدعوة — كلّم الدعم');
    redirect('dashboard.php');
}

$link = rtrim(APP_URL, '/') . '/register.php?ref=' . urlencode($offer['code']);

$stats = db_one(
    'SELECT COUNT(*) total,
            SUM(status = "rewarded") rewarded,
            SUM(status IN ("pending","qualified")) waiting
     FROM referrals WHERE referrer_user_id = ?', [$user['id']]
) ?: [];

$earned = (int) (db_one(
    'SELECT COALESCE(SUM(credits_given), 0) c FROM offer_redemptions
     WHERE user_id = ? AND role = "referrer" AND status = "granted"', [$user['id']])['c'] ?? 0);

$list = db_all(
    'SELECT r.*, u.name, u.phone, u.email FROM referrals r
     LEFT JOIN users u ON u.id = r.referee_user_id
     WHERE r.referrer_user_id = ? ORDER BY r.id DESC LIMIT 25', [$user['id']]
);

$waText = "جرّب Spread AI — منصة المحتوى والتصميم بالذكاء الاصطناعي 🎨\nسجّل من اللينك ده وهتلاقي رصيد هدية في حسابك:\n" . $link;

$statusLabels = [
    'pending'   => ['⏳ في انتظار التفعيل', 'var(--mute)'],
    'qualified' => ['✔ مستحقة — قيد المراجعة', '#a06c1e'],
    'rewarded'  => ['🎉 اتصرفت', '#2a7d5f'],
    'rejected'  => ['✕ مرفوضة', '#c0392b'],
];

$active = 'referrals';
$__crNum = !function_exists('credits_show_numbers') || credits_show_numbers();
$page_title = $__crNum ? 'اربح كريدت' : 'ادعُ واكسب';
include __DIR__ . '/../templates/header.php';
?>
<div class="app">
    <?php include __DIR__ . '/../templates/sidebar.php'; ?>
    <main class="main">
        <?php include __DIR__ . '/../templates/topbar.php'; ?>

        <div class="page-head">
            <h1><?= $__crNum ? 'اربح كريدت' : 'ادعُ واكسب' ?> 🎁</h1>
            <div class="sub">ادعي أصحابك — وكل واحد يفعّل حسابه تاخد رصيد</div>
        </div>

        <?= render_flash() ?>

        <div class="card" style="background:linear-gradient(135deg,var(--primary),#14B8A6);color:#fff;border:0">
            <div style="font-size:13px;opacity:.9;margin-bottom:6px">كود الدعوة بتاعك</div>
            <div style="font-family:Almarai,sans-serif;font-weight:800;font-size:clamp(30px,7vw,46px);letter-spacing:.12em" dir="ltr">
                <?= e($offer['code']) ?>
            </div>
            <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:16px">
                <button class="btn" style="background:#fff;color:var(--primary-ink)" onclick="cp('<?= e($link) ?>', this)">📋 نسخ اللينك</button>
                <a class="btn" style="background:rgba(255,255,255,.2);color:#fff"
                   href="https://wa.me/?text=<?= rawurlencode($waText) ?>" target="_blank" rel="noopener">💬 شارك واتساب</a>
                <a class="btn" style="background:rgba(255,255,255,.2);color:#fff"
                   href="https://www.facebook.com/sharer/sharer.php?u=<?= rawurlencode($link) ?>" target="_blank" rel="noopener">📘 فيسبوك</a>
            </div>
            <div style="margin-top:12px;font-size:12.5px;opacity:.92" dir="ltr"><?= e($link) ?></div>
        </div>

        <div class="auto-grid" style="margin:18px 0">
            <?php foreach ([
                ['👥', 'اللي سجلوا بكودك', (int) ($stats['total'] ?? 0)],
                ['✅', 'اللي فعّلوا', (int) ($stats['rewarded'] ?? 0)],
                ['⏳', 'في الانتظار', (int) ($stats['waiting'] ?? 0)],
            ] + ($__crNum ? [3 => ['◇', 'كريدت كسبته', $earned]] : []) as [$ic, $lbl, $v]): ?>
                <div class="card" style="margin:0;display:flex;gap:12px;align-items:center">
                    <span style="font-size:25px"><?= $ic ?></span>
                    <span><b style="font-size:22px;font-family:Almarai"><?= number_format($v) ?></b>
                        <br><span style="font-size:12.5px;color:var(--mute)"><?= e($lbl) ?></span></span>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="card" style="background:var(--primary-soft)">
            <b>إزاي بتشتغل؟</b>
            <p style="margin:8px 0 0;font-size:14px;line-height:1.95">
                <?php if (!$__crNum): ?>
                ابعت اللينك لأي حد · لما يسجّل بيه هياخد <b>رصيد هدية</b> يجرّب بيه فورًا ·
                ولما يفعّل حسابه تاخد انت <b>رصيد إضافي</b> على باقتك.
                <br>صلاحية الهدية <b><?= $offer['credit_validity_days'] !== null ? (int) $offer['credit_validity_days'] : (int) get_setting('offer_default_validity_days', 30) ?> يوم</b> من وقت ما تستلمها.
                <?php else: ?>
                ابعت اللينك لأي حد · لما يسجّل بيه هياخد <b><?= (int) $offer['referee_credits'] ?> كريدت</b> هدية فورًا ·
                ولما يفعّل حسابه تاخد انت <b><?= (int) $offer['referrer_credits'] ?> كريدت</b>.
                <br>صلاحية الكريدت <b><?= $offer['credit_validity_days'] !== null ? (int) $offer['credit_validity_days'] : (int) get_setting('offer_default_validity_days', 30) ?> يوم</b> من وقت ما تستلمه.
            <?php endif; ?>
            </p>
        </div>

        <div class="card" style="margin-top:18px">
            <div class="card-head"><h3>دعواتك</h3></div>
            <?php if (!$list): ?>
                <p class="sub">لسه محدش سجّل بكودك — ابعت اللينك وابدأ 🚀</p>
            <?php else: ?>
            <div class="table-wrap">
            <table class="table">
                <thead><tr><th>الشخص</th><th>التاريخ</th><th>الحالة</th></tr></thead>
                <tbody>
                <?php foreach ($list as $r):
                    // خصوصية: أول حرف + آخر 3 أرقام
                    $nm = mb_substr((string) ($r['name'] ?? '؟'), 0, 1) . '***';
                    $tail = $r['phone'] ? mb_substr(preg_replace('/\D/', '', $r['phone']), -3) : '';
                    [$lbl, $col] = $statusLabels[$r['status']] ?? ['—', 'var(--mute)'];
                ?>
                    <tr>
                        <td><?= e($nm . ($tail ? ' ' . $tail : '')) ?></td>
                        <td class="sub" style="font-size:12.5px"><?= e(fmt_date($r['created_at'])) ?></td>
                        <td style="color:<?= $col ?>;font-size:13px;font-weight:600"><?= e($lbl) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>
        </div>
    </main>
</div>

<script>
function cp(t, btn) {
    const done = () => { const o = btn.textContent; btn.textContent = '✓ اتنسخ!'; setTimeout(() => btn.textContent = o, 1600); };
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(t).then(done).catch(() => fb(t, done));
    } else { fb(t, done); }
    function fb(text, cb) {
        const ta = document.createElement('textarea');
        ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0';
        document.body.appendChild(ta); ta.select();
        try { document.execCommand('copy'); cb(); } catch (e) {}
        document.body.removeChild(ta);
    }
}
</script>
<?php include __DIR__ . '/../templates/footer.php'; ?>

<?php
/**
 * Spread AI v2 — الأدمن: اختبار العرض
 * تدخل كود + مستخدم → يقولك مؤهل ولا لأ والسبب بالتفصيل
 */
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/credits.php';
require_once __DIR__ . '/../includes/offers.php';

require_admin();
require_admin_can('manage_packages');

$code = strtoupper(trim((string) ($_GET['code'] ?? $_POST['code'] ?? '')));
$who  = trim((string) ($_POST['who'] ?? ''));
$role = ($_POST['role'] ?? 'referee') === 'referrer' ? 'referrer' : 'referee';
$result = null;
$targetUser = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $code !== '') {
    require_csrf();
    decode_b64_fields();
    $code = strtoupper(trim((string) $_POST['code']));
    $who  = trim((string) ($_POST['who'] ?? ''));

    if ($who !== '') {
        $targetUser = ctype_digit($who)
            ? db_one('SELECT * FROM users WHERE id = ?', [(int) $who])
            : db_one('SELECT * FROM users WHERE email = ? OR phone = ? ORDER BY id LIMIT 1', [$who, $who]);
    }

    if ($who !== '' && !$targetUser) {
        $result = ['ok' => false, 'reason' => 'USER_NOT_FOUND', 'message' => 'المستخدم ده مش موجود', 'checks' => []];
    } else {
        // مستخدم افتراضي = زائر جديد
        $u = $targetUser ?: ['id' => 0, 'identity_hash' => null, 'phone' => null, 'email' => null];
        $idh = $targetUser
            ? ($targetUser['identity_hash'] ?: identity_hash_for($targetUser['phone'] ?? null, $targetUser['email'] ?? null))
            : null;
        $result = offer_eligibility($code, $u, ['role' => $role, 'identity_hash' => $idh]);
    }
}

$stateIcon = ['ok' => '✅', 'fail' => '❌', 'off' => '➖', 'skip' => '⏭'];
$ruleLabels = [
    'is_active' => 'العرض مفعّل', 'period' => 'داخل الفترة الزمنية', 'max_uses' => 'سقف الاستخدامات',
    'max_uses_per_day' => 'السقف اليومي', 'lists' => 'القوائم (سماح/منع)', 'new_users_only' => 'حسابات جديدة فقط',
    'never_purchased' => 'لم يشترِ من قبل', 'allowed_packages' => 'الباقات المسموحة',
    'once_per_user' => 'مرة واحدة لكل مستخدم', 'once_per_identity' => 'مرة واحدة لكل موبايل/إيميل',
    'once_per_group' => 'مرة واحدة لكل مجموعة', 'self_referral' => 'ليست إحالة ذاتية',
    'block_same_ip' => 'ليس نفس الـ IP', 'max_per_referrer' => 'حد إحالات المُحيل',
];

$active = 'offers';
$page_title = 'اختبار عرض';
include __DIR__ . '/../templates/admin-header.php';
?>

<div class="app">
    <?php include __DIR__ . '/../templates/admin-sidebar.php'; ?>
    <main class="main">
        <div class="page-head">
            <h1>اختبار العرض 🔧</h1>
            <div class="sub">اعرف بالظبط ليه العرض شغّال أو مرفوض لمستخدم معيّن — قبل ما العميل يكلّمك</div>
        </div>

        <?= render_flash() ?>

        <div class="card">
            <form method="POST" data-safe-post>
                <?= csrf_field() ?>
                <div class="field-row">
                    <div class="field">
                        <label>كود العرض <span class="req">*</span></label>
                        <input type="text" name="code" class="input" dir="ltr" required data-no-encode="1"
                               value="<?= e($code) ?>" style="text-transform:uppercase;font-weight:700" autofocus>
                    </div>
                    <div class="field">
                        <label>المستخدم (ID أو إيميل أو موبايل)</label>
                        <input type="text" name="who" class="input" dir="ltr" data-no-encode="1"
                               value="<?= e($who) ?>" placeholder="سيبه فاضي = زائر جديد">
                    </div>
                    <div class="field">
                        <label>الدور</label>
                        <select name="role" class="input">
                            <option value="referee"  <?= $role === 'referee' ? 'selected' : '' ?>>مستخدم جديد (referee)</option>
                            <option value="referrer" <?= $role === 'referrer' ? 'selected' : '' ?>>صاحب الكود (referrer)</option>
                        </select>
                    </div>
                </div>
                <button class="btn">🔍 افحص</button>
            </form>
        </div>

        <?php if ($result !== null): ?>
            <?php $offer = $result['offer'] ?? null; ?>
            <div class="card" style="border:2px solid <?= $result['ok'] ? '#2a7d5f' : '#c0392b' ?>;
                 background:<?= $result['ok'] ? '#eefaf4' : '#fdecea' ?>">
                <?php if ($result['ok']):
                    $cr = $role === 'referrer' ? (int) $offer['referrer_credits'] : (int) $offer['referee_credits'];
                    $val = $offer['credit_validity_days'] !== null ? (int) $offer['credit_validity_days'] : (int) get_setting('offer_default_validity_days', 30);
                ?>
                    <b style="font-size:16px;color:#1e6b52">✅ مؤهل</b>
                    <p style="margin:8px 0 0;font-size:14.5px">
                        هياخد <b><?= $cr ?> كريدت</b> بصلاحية <b><?= $val ?> يوم</b>
                        <?php if ($cr === 0): ?><br><span style="color:#a06c1e">⚠️ لكن قيمة الكريدت للدور ده صفر — مش هياخد حاجة فعليًا</span><?php endif; ?>
                    </p>
                <?php else: ?>
                    <b style="font-size:16px;color:#a3312a">❌ مرفوض</b>
                    <p style="margin:8px 0 0;font-size:14.5px">
                        السبب: <code style="font-weight:700"><?= e($result['reason']) ?></code><br>
                        <span style="font-size:13.5px"><?= e($result['message']) ?></span>
                    </p>
                <?php endif; ?>

                <?php if ($targetUser): ?>
                    <div class="sub" style="margin-top:10px;font-size:12.5px">
                        المستخدم: <b><?= e($targetUser['name']) ?></b> (#<?= (int) $targetUser['id'] ?>) ·
                        <?= e($targetUser['email']) ?> ·
                        رصيده: <?= credits_balance((int) $targetUser['id']) ?> ◇
                    </div>
                <?php endif; ?>
            </div>

            <?php if (!empty($result['checks'])): ?>
            <div class="card">
                <div class="card-head"><h3>تفاصيل كل شرط</h3></div>
                <div class="table-wrap">
                <table class="table">
                    <thead><tr><th style="width:34px"></th><th>الشرط</th><th>تفاصيل</th></tr></thead>
                    <tbody>
                    <?php foreach ($result['checks'] as $c): ?>
                        <tr>
                            <td style="font-size:16px"><?= $stateIcon[$c['state']] ?? '' ?></td>
                            <td><?= e($ruleLabels[$c['rule']] ?? $c['rule']) ?></td>
                            <td class="sub" style="font-size:12.5px">
                                <?= $c['detail'] !== '' ? e($c['detail']) : ($c['state'] === 'off' ? 'غير مفعّل' : '') ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <p class="sub" style="font-size:12px;margin-top:8px">✅ عدّى · ❌ رفض · ➖ الشرط مقفول · ⏭ مش قابل للفحص دلوقتي</p>
            </div>
            <?php endif; ?>

            <?php if ($offer):
                $prev = db_all('SELECT r.*, u.name FROM offer_redemptions r LEFT JOIN users u ON u.id = r.user_id
                                WHERE r.offer_id = ? ORDER BY r.id DESC LIMIT 8', [$offer['id']]);
            ?>
            <div class="card">
                <div class="card-head"><h3>آخر استخدامات «<?= e($offer['code']) ?>»</h3></div>
                <?php if (!$prev): ?><p class="sub">لسه محدش استخدمه</p><?php else: ?>
                <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>المستخدم</th><th>الدور</th><th>كريدت</th><th>الحالة</th><th>التاريخ</th></tr></thead>
                    <tbody>
                    <?php foreach ($prev as $p): ?>
                        <tr style="<?= $p['status'] === 'granted' ? '' : 'opacity:.55' ?>">
                            <td><?= e($p['name'] ?? '#' . $p['user_id']) ?></td>
                            <td><?= $p['role'] === 'referrer' ? 'مُحيل' : 'جديد' ?></td>
                            <td><?= (int) $p['credits_given'] ?> ◇</td>
                            <td><span class="chip <?= $p['status'] === 'granted' ? 'chip-primary' : '' ?>"><?= $p['status'] === 'granted' ? 'ممنوح' : 'مسترجع' ?></span></td>
                            <td class="sub" style="font-size:12px"><?= e(fmt_date($p['created_at'], true)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        <?php endif; ?>
    </main>
</div>

<?php include __DIR__ . '/../templates/admin-footer.php'; ?>

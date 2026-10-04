<?php
/** Spread AI v2 — الأدمن: إعدادات العروض والأفلييت */
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/credits.php';
require_once __DIR__ . '/../includes/offers.php';

require_admin();
require_admin_can('manage_packages');

$fields = [
    'referral_enabled'            => ['تشغيل نظام الإحالات', 'bool', '1', 'لو قفلته، الأكواد مش هتشتغل خالص'],
    'referral_default_reward'     => ['مكافأة المُحيل (كريدت)', 'int', '100', 'بتتطبق على الأكواد الجديدة'],
    'referral_welcome_bonus'      => ['هدية المستخدم الجديد', 'int', '100', 'بتتصرف فور التسجيل بالكود'],
    'referral_trigger'            => ['لحظة استحقاق المُحيل', 'trigger', 'on_activation', ''],
    'referral_cookie_days'        => ['مدة صلاحية الكوكي (يوم)', 'int', '30', 'الفترة اللي تفضل فيها الإحالة منسوبة للمُحيل'],
    'offer_default_validity_days' => ['صلاحية الكريدت الافتراضية (يوم)', 'int', '30', ''],
    'referral_max_per_user'       => ['أقصى إحالات لكل مستخدم', 'int', '20', 'صفر = بلا حد'],
    'referral_block_same_ip'      => ['منع الإحالة من نفس الـ IP', 'bool', '1', 'حماية أساسية ضد الإحالة الذاتية'],
    'offers_allow_stacking'       => ['السماح بجمع العروض', 'bool', '0', 'الأفضل يفضل مقفول'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    decode_b64_fields();

    if (($_POST['action'] ?? '') === 'backfill') {
        $users = db_all('SELECT id FROM users WHERE referral_code IS NULL OR referral_code = "" LIMIT 500');
        $n = 0;
        foreach ($users as $u) { if (ensure_user_referral_offer((int) $u['id'])) $n++; }
        admin_log('backfill_referral_codes', 'offer', null, (string) $n);
        flash_set('success', "تم توليد أكواد لـ {$n} مستخدم");
        redirect('admin/offer-settings.php');
    }

    foreach ($fields as $key => [$lbl, $type]) {
        if ($type === 'bool') {
            set_setting($key, !empty($_POST[$key]) ? '1' : '0');
        } elseif ($type === 'int') {
            set_setting($key, (string) max(0, (int) ($_POST[$key] ?? 0)));
        } elseif ($type === 'trigger') {
            $v = in_array($_POST[$key] ?? '', ['on_register', 'on_activation', 'on_first_payment'], true) ? $_POST[$key] : 'on_activation';
            set_setting($key, $v);
        }
    }
    admin_log('update_offer_settings', 'settings');
    flash_set('success', 'تم حفظ الإعدادات ✓');
    redirect('admin/offer-settings.php');
}

$noCode = (int) (db_one('SELECT COUNT(*) c FROM users WHERE referral_code IS NULL OR referral_code = ""')['c'] ?? 0);

$active = 'offers';
$page_title = 'إعدادات الأفلييت';
include __DIR__ . '/../templates/admin-header.php';
?>
<div class="app">
    <?php include __DIR__ . '/../templates/admin-sidebar.php'; ?>
    <main class="main">
        <div class="page-head">
            <h1>إعدادات العروض والأفلييت ⚙️</h1>
            <div class="sub">القيم دي بتتطبق على الأكواد الجديدة — العروض الموجودة ليها إعداداتها الخاصة</div>
        </div>
        <?= render_flash() ?>

        <?php if ($noCode > 0): ?>
            <div class="card" style="background:#fdf6e3;border-color:#f0c36d">
                فيه <b><?= $noCode ?></b> مستخدم لسه مالهمش كود دعوة.
                <form method="POST" style="display:inline;margin-inline-start:8px">
                    <?= csrf_field() ?><input type="hidden" name="action" value="backfill">
                    <button class="btn sm">🔧 ولّد أكواد لهم</button>
                </form>
            </div>
        <?php endif; ?>

        <form method="POST" data-safe-post>
            <?= csrf_field() ?>
            <div class="card">
                <div class="card-head"><h3>الإعدادات العامة</h3></div>
                <div class="row" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:14px">
                    <?php foreach ($fields as $key => [$lbl, $type, $def, $hint]): $val = get_setting($key, $def); ?>
                        <div class="field">
                            <label><?= e($lbl) ?></label>
                            <?php if ($type === 'bool'): ?>
                                <label style="display:flex;gap:8px;align-items:center;font-weight:400;cursor:pointer">
                                    <input type="checkbox" name="<?= e($key) ?>" value="1" <?= $val === '1' ? 'checked' : '' ?> style="width:auto"> مفعّل
                                </label>
                            <?php elseif ($type === 'trigger'): ?>
                                <select name="<?= e($key) ?>" class="input">
                                    <option value="on_register"      <?= $val === 'on_register' ? 'selected' : '' ?>>فور التسجيل</option>
                                    <option value="on_activation"    <?= $val === 'on_activation' ? 'selected' : '' ?>>عند تفعيل الحساب ✅</option>
                                    <option value="on_first_payment" <?= $val === 'on_first_payment' ? 'selected' : '' ?>>عند أول اشتراك</option>
                                </select>
                            <?php else: ?>
                                <input type="number" name="<?= e($key) ?>" class="input" min="0" value="<?= e($val) ?>">
                            <?php endif; ?>
                            <?php if ($hint): ?><div class="field-help"><?= e($hint) ?></div><?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                <button class="btn">💾 حفظ</button>
            </div>
        </form>
    </main>
</div>
<?php include __DIR__ . '/../templates/admin-footer.php'; ?>

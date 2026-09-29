<?php
/**
 * Spread AI — الأدمن: طرق الدفع (المرحلة 10)
 * إنستاباي · فودافون كاش · أي طريقة تانية — الرقم/العنوان · اسم الحساب · لينك الدفع · التعليمات · محتاج إيصال؟
 */
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/credits.php';
require_once __DIR__ . '/../includes/billing.php';

require_admin();
require_admin_can('manage_packages');
billing_ready();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    decode_b64_fields();
    $action = (string) ($_POST['action'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);
    if ($action === 'save') {
        $label = mb_substr(trim((string) ($_POST['label'] ?? '')), 0, 80);
        $key = preg_replace('/[^a-z0-9_]/', '', mb_strtolower(trim((string) ($_POST['mkey'] ?? ''))));
        $link = trim((string) ($_POST['pay_link'] ?? ''));
        if ($link !== '' && !preg_match('#^https?://#i', $link)) $link = 'https://' . ltrim($link, '/');
        if ($label === '' || ($id === 0 && $key === '')) {
            flash_set('danger', 'اكتب اسم الطريقة ومفتاحها');
        } else {
            $vals = [$label, mb_substr(trim((string) ($_POST['account'] ?? '')), 0, 190) ?: null, mb_substr(trim((string) ($_POST['account_name'] ?? '')), 0, 120) ?: null,
                     $link !== '' ? mb_substr($link, 0, 500) : null, mb_substr(trim((string) ($_POST['instructions'] ?? '')), 0, 2000) ?: null,
                     !empty($_POST['needs_proof']) ? 1 : 0, !empty($_POST['is_active']) ? 1 : 0, (int) ($_POST['sort_order'] ?? 0)];
            try {
                if ($id) {
                    db_run('UPDATE payment_methods SET label = ?, account = ?, account_name = ?, pay_link = ?, instructions = ?, needs_proof = ?, is_active = ?, sort_order = ? WHERE id = ?', array_merge($vals, [$id]));
                } else {
                    $id = db_insert('INSERT INTO payment_methods (label, account, account_name, pay_link, instructions, needs_proof, is_active, sort_order, mkey) VALUES (?,?,?,?,?,?,?,?,?)', array_merge($vals, [$key]));
                }
                admin_log('payment_method_save', 'payment_method', $id, $label);
                flash_set('success', 'اتحفظت طريقة الدفع ✓');
            } catch (\Throwable $e) {
                flash_set('danger', str_contains($e->getMessage(), 'uq_pm_key') ? 'المفتاح ده مستخدم' : 'تعذّر الحفظ');
            }
        }
    } elseif ($action === 'toggle' && $id) {
        db_run('UPDATE payment_methods SET is_active = 1 - is_active WHERE id = ?', [$id]);
    }
    redirect('admin/payment-methods.php');
}

$methods = billing_methods(false);
$edit = !empty($_GET['edit']) ? db_one('SELECT * FROM payment_methods WHERE id = ?', [(int) $_GET['edit']]) : null;

$active = 'payments';
$page_title = 'طرق الدفع';
include __DIR__ . '/../templates/admin-header.php';
?>
<div class="app">
    <?php include __DIR__ . '/../templates/admin-sidebar.php'; ?>
    <main class="main">
        <div class="page-head">
            <a href="<?= url('admin/payments.php') ?>" class="text-mute" style="font-size:13px">← المدفوعات</a>
            <h1>طرق الدفع</h1>
            <div class="sub">اللي بيظهر للعميل في صفحة الدفع — بيانات التحويل وتعليماته.</div>
        </div>
        <?= render_flash() ?>

        <div class="card" id="form">
            <div class="card-head"><h3><?= $edit ? 'تعديل: ' . e($edit['label']) : 'طريقة جديدة' ?></h3><?php if ($edit): ?><a href="<?= url('admin/payment-methods.php') ?>" class="btn ghost sm">إلغاء</a><?php endif; ?></div>
            <form method="POST" data-safe-post>
                <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">
                <div class="field-row">
                    <div class="field"><label>الاسم <span class="req">*</span></label><input class="input" name="label" required value="<?= e($edit['label'] ?? '') ?>" placeholder="InstaPay · إنستاباي"></div>
                    <div class="field"><label>المفتاح</label><input class="input" name="mkey" dir="ltr" data-no-encode="1" value="<?= e($edit['mkey'] ?? '') ?>" <?= $edit ? 'disabled' : 'required' ?> placeholder="instapay"></div>
                    <div class="field"><label>الرقم / العنوان</label><input class="input" name="account" dir="ltr" value="<?= e($edit['account'] ?? '') ?>" placeholder="name@instapay أو 010xxxxxxxx"></div>
                    <div class="field"><label>باسم</label><input class="input" name="account_name" value="<?= e($edit['account_name'] ?? '') ?>"></div>
                </div>
                <div class="field-row">
                    <div class="field"><label>لينك الدفع المباشر (اختياري)</label><input class="input" name="pay_link" dir="ltr" value="<?= e($edit['pay_link'] ?? '') ?>" placeholder="https://ipn.eg/…"></div>
                    <div class="field"><label>الترتيب</label><input class="input" type="number" name="sort_order" value="<?= (int) ($edit['sort_order'] ?? 0) ?>"></div>
                </div>
                <div class="field"><label>التعليمات للعميل</label><textarea class="textarea" name="instructions" rows="2"><?= e($edit['instructions'] ?? '') ?></textarea></div>
                <label style="display:inline-flex;gap:8px;align-items:center;margin-inline-end:18px"><input type="checkbox" name="needs_proof" value="1" <?= ($edit['needs_proof'] ?? 1) ? 'checked' : '' ?> style="width:auto"> لازم إيصال</label>
                <label style="display:inline-flex;gap:8px;align-items:center"><input type="checkbox" name="is_active" value="1" <?= ($edit['is_active'] ?? 1) ? 'checked' : '' ?> style="width:auto"> مفعّلة</label>
                <div style="margin-top:12px"><button class="btn">حفظ</button></div>
            </form>
        </div>

        <div class="table-wrap mt-20">
            <table class="tbl">
                <thead><tr><th>الطريقة</th><th>الرقم / العنوان</th><th>إيصال</th><th>الحالة</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($methods as $m): ?>
                    <tr style="<?= $m['is_active'] ? '' : 'opacity:.55' ?>">
                        <td><b><?= e($m['label']) ?></b><br><small class="sub" dir="ltr"><?= e($m['mkey']) ?></small></td>
                        <td dir="ltr"><?= e((string) $m['account']) ?: '—' ?></td>
                        <td><?= $m['needs_proof'] ? '✓' : '—' ?></td>
                        <td><?= $m['is_active'] ? 'مفعّلة' : 'موقوفة' ?></td>
                        <td style="display:flex;gap:6px">
                            <a class="btn ghost sm" href="?edit=<?= (int) $m['id'] ?>#form">تعديل</a>
                            <form method="POST" data-safe-post><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int) $m['id'] ?>"><button class="btn ghost sm"><?= $m['is_active'] ? 'إيقاف' : 'تشغيل' ?></button></form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </main>
</div>
<?php include __DIR__ . '/../templates/admin-footer.php'; ?>

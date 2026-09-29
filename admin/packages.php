<?php
/**
 * Spread AI v2 — الأدمن: إدارة الباقات
 * كل باقة: الكريدت · السعر · مدة الصلاحية · المميزات · شارة · تمييز
 */
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/credits.php';

require_admin();
require_admin_can('manage_packages');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    decode_b64_fields();
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id = (int) ($_POST['pkg_id'] ?? 0);
        $data = [
            'name'          => mb_substr(trim($_POST['name'] ?? ''), 0, 120),
            'credits'       => max(1, (int) ($_POST['credits'] ?? 1)),
            'price_egp'     => max(0, (float) ($_POST['price_egp'] ?? 0)),
            'validity_days' => max(1, min(3650, (int) ($_POST['validity_days'] ?? 30))),
            'description'   => mb_substr(trim($_POST['description'] ?? ''), 0, 500) ?: null,
            'badge'         => mb_substr(trim($_POST['badge'] ?? ''), 0, 60) ?: null,
            'features'      => mb_substr(trim($_POST['features'] ?? ''), 0, 2000) ?: null,
            'is_featured'   => !empty($_POST['is_featured']) ? 1 : 0,
            'is_active'     => !empty($_POST['is_active']) ? 1 : 0,
            'order_num'     => (int) ($_POST['order_num'] ?? 0),
        ];
        // 8-ب: حصص الباقة في الدورة (0 = مفتوح في حدود الكريدت)
        if (function_exists('plans_ready') && plans_ready()) {
            $q = [];
            foreach (array_keys(plan_units()) as $u) $q[$u] = max(0, min(100000, (int) ($_POST['q_' . $u] ?? 0)));
            $data['quotas_json'] = json_encode($q);
        }
        if ($data['name'] === '') {
            flash_set('danger', 'اكتب اسم الباقة');
            redirect('admin/packages.php');
        }

        if ($id > 0) {
            $set = [];
            $vals = [];
            foreach ($data as $k => $v) {
                $set[] = "`{$k}` = ?";
                $vals[] = $v;
            }
            $vals[] = $id;
            db_run('UPDATE credit_packages SET ' . implode(', ', $set) . ' WHERE id = ?', $vals);
            admin_log('update_package', 'package', $id, $data['name']);
            flash_set('success', 'تم تحديث الباقة ✓');
        } else {
            db_insert(
                'INSERT INTO credit_packages (`' . implode('`, `', array_keys($data)) . '`) VALUES (' . implode(', ', array_fill(0, count($data), '?')) . ')',
                array_values($data)
            );
            admin_log('add_package', 'package', null, $data['name']);
            flash_set('success', 'تم إضافة الباقة ✓');
        }
        redirect('admin/packages.php');
    }

    // 8-ب: طريقة عرض الاستهلاك للعميل + تطبيق الحصص
    if ($action === 'display') {
        if (!admin_can('site_settings')) {
            flash_set('danger', 'طريقة العرض للعملاء بيغيّرها الأدمن الكامل بس');
            redirect('admin/packages.php');
        }
        set_setting('credits_display', ($_POST['credits_display'] ?? '') === 'visible' ? 'visible' : 'percent');
        set_setting('quotas_enforced', !empty($_POST['quotas_enforced']) ? '1' : '0');
        set_setting('upgrade_whatsapp_msg', mb_substr(trim((string) ($_POST['upgrade_whatsapp_msg'] ?? '')), 0, 300));
        admin_log('plans_display_settings', 'settings', null, json_encode(['display' => $_POST['credits_display'] ?? '', 'enforced' => !empty($_POST['quotas_enforced'])]));
        flash_set('success', 'اتحفظ ✓');
        redirect('admin/packages.php');
    }

    if ($action === 'toggle') {
        db_run('UPDATE credit_packages SET is_active = 1 - is_active WHERE id = ?', [(int) $_POST['pkg_id']]);
        redirect('admin/packages.php');
    }

    if ($action === 'delete') {
        db_run('DELETE FROM credit_packages WHERE id = ?', [(int) $_POST['pkg_id']]);
        admin_log('delete_package', 'package', (int) $_POST['pkg_id']);
        flash_set('success', 'تم حذف الباقة');
        redirect('admin/packages.php');
    }
}

$packages = db_all('SELECT * FROM credit_packages ORDER BY order_num, id');
$editId = (int) ($_GET['edit'] ?? 0);
$editing = $editId ? db_one('SELECT * FROM credit_packages WHERE id = ?', [$editId]) : null;

$active = 'packages';
$page_title = 'الباقات';
include __DIR__ . '/../templates/admin-header.php';
?>

<div class="app">
    <?php include __DIR__ . '/../templates/admin-sidebar.php'; ?>
    <main class="main">
        <div class="page-head">
            <h1>الباقات والأسعار</h1>
            <div class="sub">Plans &amp; Pricing · الحصص الشهرية (منشورات · تصميمات · نشر · أبحاث · فيديو) + الكريدت الداخلي والسعر والصلاحية</div>
        </div>

        <?= render_flash() ?>

        <?php $__plansOn = function_exists('plans_ready') && plans_ready(); ?>
        <?php if ($__plansOn): ?>
        <form method="POST" class="card" style="margin-bottom:20px" data-safe-post>
            <?= csrf_field() ?><input type="hidden" name="action" value="display">
            <div class="card-head"><h3>العميل بيشوف إيه؟</h3></div>
            <div class="field-row">
                <div class="field">
                    <label>عرض الاستهلاك</label>
                    <select name="credits_display" class="input">
                        <option value="percent" <?= get_setting('credits_display', 'percent') !== 'visible' ? 'selected' : '' ?>>نسبة % من باقة الشهر + الحصص (من غير أرقام كريدت)</option>
                        <option value="visible" <?= get_setting('credits_display', 'percent') === 'visible' ? 'selected' : '' ?>>أرقام الكريدت زي الأول</option>
                    </select>
                    <div class="field-help">الكريدت بيفضل وحدة المحاسبة الداخلية — إنت بتشوفه دايمًا في الأدمن.</div>
                </div>
                <div class="field">
                    <label>رسالة واتساب «رقّي باقتك»</label>
                    <input type="text" name="upgrade_whatsapp_msg" class="input" value="<?= e((string) get_setting('upgrade_whatsapp_msg', '')) ?>">
                    <div class="field-help">بتتبعت على رقم الواتساب العائم.</div>
                </div>
            </div>
            <label style="display:flex;align-items:center;gap:8px;margin-bottom:12px"><input type="checkbox" name="quotas_enforced" value="1" <?= get_setting('quotas_enforced', '1') === '1' ? 'checked' : '' ?>> لما حصة تخلص، الخدمة دي تقف برسالة ترقية (غير كده الحصص للعرض بس والكريدت هو الحد)</label>
            <button class="btn sm" <?= admin_can('site_settings') ? '' : 'disabled title="للأدمن الكامل بس"' ?>>حفظ</button>
        </form>
        <?php endif; ?>

        <div class="card" style="margin-bottom:20px">
            <div class="card-head"><h3><?= $editing ? 'تعديل: ' . e($editing['name']) : 'باقة جديدة' ?></h3></div>
            <form method="POST" data-safe-post>
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="pkg_id" value="<?= (int) ($editing['id'] ?? 0) ?>">

                <div class="field-row">
                    <div class="field">
                        <label>اسم الباقة <span class="req">*</span></label>
                        <input type="text" name="name" class="input" required value="<?= e($editing['name'] ?? '') ?>" placeholder="الباقة الفضية">
                    </div>
                    <div class="field">
                        <label>عدد الكريدت</label>
                        <input type="number" name="credits" class="input" min="1" required value="<?= (int) ($editing['credits'] ?? 50) ?>">
                    </div>
                    <div class="field">
                        <label>السعر (جنيه)</label>
                        <input type="number" name="price_egp" class="input" min="0" step="0.01" required value="<?= e($editing['price_egp'] ?? '0') ?>">
                    </div>
                    <div class="field">
                        <label>مدة الصلاحية (يوم)</label>
                        <input type="number" name="validity_days" class="input" min="1" max="3650" required value="<?= (int) ($editing['validity_days'] ?? 30) ?>">
                        <div class="field-help" style="font-size:11px">الرصيد بينتهي بعدها</div>
                    </div>
                    <div class="field">
                        <label>الترتيب</label>
                        <input type="number" name="order_num" class="input" value="<?= (int) ($editing['order_num'] ?? 0) ?>">
                    </div>
                </div>

                <div class="field-row">
                    <div class="field">
                        <label>وصف قصير</label>
                        <input type="text" name="description" class="input" value="<?= e($editing['description'] ?? '') ?>" placeholder="مناسبة للبيزنس الصغير">
                    </div>
                    <div class="field">
                        <label>شارة (اختياري)</label>
                        <input type="text" name="badge" class="input" value="<?= e($editing['badge'] ?? '') ?>" placeholder="الأكثر طلبًا">
                    </div>
                </div>

                <?php if ($__plansOn): $__eq = plan_quotas_decode($editing['quotas_json'] ?? null); ?>
                <div class="field">
                    <label>حصص الدورة (0 = مفتوح في حدود الكريدت)</label>
                    <div class="field-row" style="margin:0">
                        <?php foreach (plan_units() as $__u => [$__e, $__l]): ?>
                            <div class="field" style="margin:0">
                                <input type="number" name="q_<?= $__u ?>" class="input a2-q" data-u="<?= $__u ?>" min="0" max="100000" value="<?= (int) ($__eq[$__u] ?? 0) ?>" aria-label="<?= e($__l) ?>">
                                <div class="field-help"><?= $__e ?> <?= e($__l) ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="field-help" id="a2-qsum"></div>
                </div>
                <?php endif; ?>

                <div class="field">
                    <label>المميزات — ميزة في كل سطر</label>
                    <textarea name="features" class="textarea" rows="6" placeholder="50 منشور بالذكاء الاصطناعي&#10;25 تصميم احترافي&#10;خطة محتوى شهرية&#10;ربط صفحة فيسبوك ونشر تلقائي&#10;دعم فني بالواتساب"><?= e($editing['features'] ?? '') ?></textarea>
                </div>

                <div style="display:flex;gap:20px;flex-wrap:wrap;margin-bottom:14px">
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
                        <input type="checkbox" name="is_active" <?= ($editing['is_active'] ?? 1) ? 'checked' : '' ?>> ظاهرة للعملاء
                    </label>
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
                        <input type="checkbox" name="is_featured" <?= ($editing['is_featured'] ?? 0) ? 'checked' : '' ?>> باقة مميزة (تظهر بإطار بارز)
                    </label>
                </div>

                <button class="btn"><?= $editing ? '💾 حفظ التعديلات' : '＋ إضافة الباقة' ?></button>
                <?php if ($editing): ?>
                    <a href="<?= url('admin/packages.php') ?>" class="btn ghost">إلغاء</a>
                <?php endif; ?>
            </form>
        </div>

        <div class="auto-grid">
            <?php foreach ($packages as $p):
                $feats = array_values(array_filter(array_map('trim', preg_split('/[\r\n]+/', (string) $p['features']))));
            ?>
                <div class="card" style="<?= $p['is_active'] ? '' : 'opacity:.55;' ?><?= $p['is_featured'] ? 'border:2px solid var(--primary)' : '' ?>">
                    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px">
                        <div>
                            <b style="font-size:16px"><?= e($p['name']) ?></b>
                            <?php if ($p['badge']): ?><span class="chip chip-primary" style="font-size:10px"><?= e($p['badge']) ?></span><?php endif; ?>
                            <?php if ($p['description']): ?><div class="sub" style="font-size:12px"><?= e($p['description']) ?></div><?php endif; ?>
                        </div>
                        <span class="chip"><?= $p['is_active'] ? 'ظاهرة' : 'مخفية' ?></span>
                    </div>

                    <div style="margin:12px 0;font-size:15px">
                        <b style="color:var(--primary-ink)"><?= (int) $p['credits'] ?> ◇</b>
                        بـ <b><?= number_format((float) $p['price_egp'], 0) ?></b> جنيه
                        <div class="sub" style="font-size:12px">صلاحية <?= (int) ($p['validity_days'] ?: 30) ?> يوم</div>
                        <?php if (function_exists('plan_quotas_decode')): $__pq = array_filter(plan_quotas_decode($p['quotas_json'] ?? null)); ?>
                            <?php if ($__pq): ?><div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:8px"><?php foreach ($__pq as $__k => $__n): ?><span class="chip chip-line"><?= plan_units()[$__k][0] ?> <?= (int) $__n ?> <?= e(plan_units()[$__k][1]) ?></span><?php endforeach; ?></div><?php endif; ?>
                        <?php endif; ?>
                    </div>

                    <?php if ($feats): ?>
                        <ul style="margin:0 18px 12px;font-size:12.5px;line-height:1.9">
                            <?php foreach (array_slice($feats, 0, 6) as $f): ?><li><?= e($f) ?></li><?php endforeach; ?>
                        </ul>
                    <?php endif; ?>

                    <div style="display:flex;gap:6px;flex-wrap:wrap">
                        <a href="?edit=<?= $p['id'] ?>" class="btn sm">✎ تعديل</a>
                        <form method="POST" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="pkg_id" value="<?= $p['id'] ?>"><button class="btn ghost sm"><?= $p['is_active'] ? 'إخفاء' : 'إظهار' ?></button></form>
                        <form method="POST" style="display:inline" onsubmit="return confirm('حذف الباقة؟')"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="pkg_id" value="<?= $p['id'] ?>"><button class="btn ghost sm" style="color:#c0392b">🗑</button></form>
                    </div>
                </div>
            <?php endforeach; ?>
            <?php if (!$packages): ?><p class="sub">مفيش باقات — أضف أول باقة 👆</p><?php endif; ?>
        </div>

    </main>
</div>

<script>
// تقدير الكريدت اللي الحصص محتاجاه (حصة × تكلفة العملية) — علشان الكريدت والحصص يمشوا مع بعض
(function () {
    var cost = <?= json_encode(['posts' => cost_for('content_generation_cost'), 'designs' => cost_for('content_design_cost'), 'publishes' => 0,
        'research' => (int) get_setting('research_cost_medium', 4), 'videos' => cost_for('content_generation_cost')]) ?>;
    var box = document.getElementById('a2-qsum'), cr = document.querySelector('input[name=credits]');
    if (!box) return;
    function calc() {
        var t = 0; document.querySelectorAll('.a2-q').forEach(function (i) { t += (parseInt(i.value, 10) || 0) * (cost[i.dataset.u] || 0); });
        box.textContent = 'الحصص دي محتاجة حوالي ' + t + ' كريدت (بتكلفة العمليات الحالية' + (cr ? '، والباقة فيها ' + cr.value + ')' : ')') + (cr && t > +cr.value ? ' ⚠ الكريدت أقل من الحصص' : '');
    }
    document.querySelectorAll('.a2-q').forEach(function (i) { i.addEventListener('input', calc); });
    if (cr) cr.addEventListener('input', calc);
    calc();
})();
</script>
<?php include __DIR__ . '/../templates/admin-footer.php'; ?>

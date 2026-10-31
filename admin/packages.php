<?php
/**
 * Spread AI v2 — الأدمن: إدارة الباقات
 * كل باقة: الكريدت · السعر · مدة الصلاحية · المميزات · شارة · تمييز
 */
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/credits.php';
require_once __DIR__ . '/../includes/billing.php';   // 10: هدية الباقة
require_once __DIR__ . '/../includes/assistants.php'; // المرحلة 7: المساعدين في الباقة

require_admin();
require_admin_can('manage_packages');

/** حفظ مدد الباقة من الفورم (صف لكل مدة · «امسح» بيخفيها لو عليها اشتراكات) */
function pkg_admin_save_prices(int $pkgId): void
{
    $ids = (array) ($_POST['pp_id'] ?? []);
    foreach ($ids as $k => $pid) {
        $pid = (int) $pid;
        $label = mb_substr(trim((string) ($_POST['pp_label'][$k] ?? '')), 0, 60);
        $days = max(1, min(3650, (int) ($_POST['pp_days'][$k] ?? 30)));
        $row = [$label !== '' ? $label : pkg_duration_text($days), $days, max(0, (float) ($_POST['pp_price'][$k] ?? 0)),
                ($_POST['pp_compare'][$k] ?? '') !== '' ? max(0, (float) $_POST['pp_compare'][$k]) : null,
                max(0, (int) ($_POST['pp_credits'][$k] ?? 0)), max(0, (int) ($_POST['pp_designs'][$k] ?? 0)), max(0, (int) ($_POST['pp_research'][$k] ?? 0)),
                !empty($_POST['pp_active'][$k]) ? 1 : 0, (int) $k];
        $del = !empty($_POST['pp_del'][$k]);
        if ($pid > 0) {
            if ($del) {
                $used = db_one('SELECT id FROM user_plans WHERE price_id = ? LIMIT 1', [$pid]) || db_one('SELECT id FROM payment_requests WHERE price_id = ? LIMIT 1', [$pid]);
                $used ? db_run('UPDATE package_prices SET is_active = 0 WHERE id = ? AND package_id = ?', [$pid, $pkgId]) : db_run('DELETE FROM package_prices WHERE id = ? AND package_id = ?', [$pid, $pkgId]);
                continue;
            }
            db_run('UPDATE package_prices SET label = ?, days = ?, price_egp = ?, compare_egp = ?, credits = ?, designs = ?, research = ?, is_active = ?, sort_order = ? WHERE id = ? AND package_id = ?', array_merge($row, [$pid, $pkgId]));
        } elseif (!$del && ((float) ($_POST['pp_price'][$k] ?? 0) > 0 || (int) ($_POST['pp_credits'][$k] ?? 0) > 0)) {
            db_insert('INSERT INTO package_prices (label, days, price_egp, compare_egp, credits, designs, research, is_active, sort_order, package_id) VALUES (?,?,?,?,?,?,?,?,?,?)', array_merge($row, [$pkgId]));
        }
    }
}

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
        // مميزات الباقة: النشر المباشر (بيتفتح تلقائيًا مع الاشتراك) · العلامة المائية على التصميمات
        if (function_exists('package_flags_ensure') && package_flags_ensure()) {
            $data['allow_publish'] = !empty($_POST['allow_publish']) ? 1 : 0;
            $data['watermark'] = !empty($_POST['watermark']) ? 1 : 0;
        }
        // 10: هدية الباقة (Bonus) — بتتضاف مع الاعتماد
        if (function_exists('billing_ready') && billing_ready()) {
            $data['bonus_credits'] = max(0, min(100000, (int) ($_POST['bonus_credits'] ?? 0)));
            $data['bonus_note'] = mb_substr(trim($_POST['bonus_note'] ?? ''), 0, 190) ?: null;
        }
        // الباقات الجديدة: الحد الأسبوعي · مستوى الجودة · البحث
        $__v2 = function_exists('pkg_v2_ready') && pkg_v2_ready();
        if ($__v2) {
            $data['weekly_credits'] = max(0, min(1000000, (int) ($_POST['weekly_credits'] ?? 0)));
            $data['weekly_designs'] = max(0, min(100000, (int) ($_POST['weekly_designs'] ?? 0)));
            $data['quality_tier'] = isset(pkg_tiers()[$_POST['quality_tier'] ?? '']) ? $_POST['quality_tier'] : 'standard';
            $data['allow_research'] = !empty($_POST['allow_research']) ? 1 : 0;
        }
        // المرحلة 7: المساعدين في الباقة
        if (function_exists('as_ready') && as_ready()) {
            $data['assistant_support'] = !empty($_POST['assistant_support']) ? 1 : 0;
            $data['assistant_agent'] = !empty($_POST['assistant_agent']) ? 1 : 0;
        }
        // المرحلة 6: خصم الفيديو للباقة (% من سعر الفيديو — الفيديو بيتدفع بالفلوس)
        if (vp_ready()) $data['video_discount_pct'] = round(max(0, min(100, (float) ($_POST['video_discount_pct'] ?? 0))), 2);
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
            if ($__v2) pkg_admin_save_prices($id);
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
            $newId = (int) db_insert(
                'INSERT INTO credit_packages (`' . implode('`, `', array_keys($data)) . '`) VALUES (' . implode(', ', array_fill(0, count($data), '?')) . ')',
                array_values($data)
            );
            if ($__v2) pkg_admin_save_prices($newId);
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
        if (array_key_exists('publish_whatsapp', $_POST)) set_setting('publish_whatsapp', mb_substr(preg_replace('/\D+/', '', (string) $_POST['publish_whatsapp']), 0, 20));
        admin_log('plans_display_settings', 'settings', null, json_encode(['display' => $_POST['credits_display'] ?? '', 'enforced' => !empty($_POST['quotas_enforced'])]));
        flash_set('success', 'اتحفظ ✓');
        redirect('admin/packages.php');
    }

    // الإضافات: تصميمات زيادة · كريدت مساعد
    if ($action === 'addon_save' && function_exists('pkg_v2_ready') && pkg_v2_ready()) {
        $aid = (int) ($_POST['addon_id'] ?? 0);
        $a = ['kind' => ($_POST['kind'] ?? '') === 'credits' ? 'credits' : 'designs', 'name' => mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 100),
              'amount' => max(1, min(100000, (int) ($_POST['amount'] ?? 1))), 'price_egp' => max(0, (float) ($_POST['price_egp'] ?? 0)),
              'validity_days' => max(1, min(365, (int) ($_POST['validity_days'] ?? 30))), 'description' => mb_substr(trim((string) ($_POST['description'] ?? '')), 0, 255) ?: null,
              'is_active' => !empty($_POST['is_active']) ? 1 : 0, 'sort_order' => (int) ($_POST['sort_order'] ?? 0)];
        if ($a['name'] === '') $a['name'] = $a['kind'] === 'designs' ? $a['amount'] . ' تصميم' : $a['amount'] . ' كريدت';
        if ($aid > 0) db_run('UPDATE addon_packs SET kind = ?, name = ?, amount = ?, price_egp = ?, validity_days = ?, description = ?, is_active = ?, sort_order = ? WHERE id = ?', array_merge(array_values($a), [$aid]));
        else db_insert('INSERT INTO addon_packs (kind, name, amount, price_egp, validity_days, description, is_active, sort_order) VALUES (?,?,?,?,?,?,?,?)', array_values($a));
        admin_log('addon_save', 'addon_pack', $aid ?: null, $a['name']);
        flash_set('success', 'اتحفظت الإضافة ✓');
        redirect('admin/packages.php#addons');
    }
    if ($action === 'addon_delete' && function_exists('pkg_v2_ready') && pkg_v2_ready()) {
        db_run('UPDATE addon_packs SET is_active = 0 WHERE id = ?', [(int) ($_POST['addon_id'] ?? 0)]);   // بتتخفي بس — الطلبات القديمة بتفضل مربوطة بيها
        admin_log('addon_hide', 'addon_pack', (int) ($_POST['addon_id'] ?? 0));
        redirect('admin/packages.php#addons');
    }
    // باقة التسجيل (الحسابات الجديدة) — بتتغير حسب العروض
    if ($action === 'signup' && function_exists('pkg_v2_ready') && pkg_v2_ready()) {
        $sp = (int) ($_POST['signup_package_id'] ?? 0);
        $spp = (int) ($_POST['signup_price_id'] ?? 0);
        set_setting('signup_package_id', $sp > 0 && db_one('SELECT id FROM credit_packages WHERE id = ?', [$sp]) ? (string) $sp : '');
        set_setting('signup_package_price_id', $spp > 0 && db_one('SELECT id FROM package_prices WHERE id = ? AND package_id = ?', [$spp, $sp]) ? (string) $spp : '');
        admin_log('signup_package', 'settings', null, $sp . '/' . $spp);
        flash_set('success', $sp ? 'باقة التسجيل اتحفظت ✓ — هتتفعّل لكل حساب جديد' : 'رجعنا للكريدت الترحيبي العادي');
        redirect('admin/packages.php#signup');
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
$__v2 = function_exists('pkg_v2_ready') && pkg_v2_ready();
$addons = $__v2 ? addon_packs(false) : [];
$editAddon = $__v2 && !empty($_GET['addon']) ? addon_pack((int) $_GET['addon'], false) : null;
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
                <div class="field">
                    <label>📲 رقم واتساب النشر اليدوي</label>
                    <input type="text" name="publish_whatsapp" class="input" dir="ltr" value="<?= e((string) get_setting('publish_whatsapp', '')) ?>" placeholder="2010xxxxxxxx">
                    <div class="field-help">العميل اللي باقته مافيهاش نشر مباشر بيبعت حملته على الرقم ده علشان فريقك ينشرها. فاضي = رقم الدعم أو الواتساب العائم.</div>
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
                <div class="field-row">
                    <div class="field">
                        <label>🎁 كريدت هدية مع الباقة</label>
                        <input type="number" name="bonus_credits" class="input" min="0" value="<?= (int) ($editing['bonus_credits'] ?? 0) ?>">
                    </div>
                    <div class="field">
                        <label>🎁 وصف الهدية (اختياري)</label>
                        <input type="text" name="bonus_note" class="input" maxlength="190" value="<?= e($editing['bonus_note'] ?? '') ?>" placeholder="مثلًا: جلسة استشارة مجانية">
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

                <?php if ($__v2): $__prices = $editing ? pkg_prices((int) $editing['id'], false) : []; ?>
                <div class="field-row">
                    <div class="field">
                        <label>⏱ حد الكريدت في الأسبوع</label>
                        <input type="number" name="weekly_credits" class="input" min="0" value="<?= (int) ($editing['weekly_credits'] ?? 0) ?>">
                        <div class="field-help">0 = من غير حد. تراكمي: اللي ماتصرفش في أسبوع بيتصرف بعدين (مفيد للـ 3 شهور والسنة)</div>
                    </div>
                    <div class="field">
                        <label>⏱ حد التصميمات في الأسبوع</label>
                        <input type="number" name="weekly_designs" class="input" min="0" value="<?= (int) ($editing['weekly_designs'] ?? 0) ?>">
                        <div class="field-help">0 = من غير حد · تراكمي برضه</div>
                    </div>
                    <div class="field">
                        <label>⭐ مستوى الجودة</label>
                        <select name="quality_tier" class="input">
                            <?php foreach (pkg_tiers() as $__k => $__l): ?><option value="<?= e($__k) ?>" <?= ($editing['quality_tier'] ?? 'standard') === $__k ? 'selected' : '' ?>><?= e($__l) ?></option><?php endforeach; ?>
                        </select>
                        <div class="field-help">الموديلات وسرعتها لكل مستوى بتتحدد في «مستويات الجودة»</div>
                    </div>
                    <?php if (as_ready()): ?>
                    <div class="field">
                        <label>🤖 المساعدين</label>
                        <label style="display:flex;align-items:center;gap:8px"><input type="checkbox" name="assistant_support" value="1" <?= (int) ($editing['assistant_support'] ?? 1) ? 'checked' : '' ?>> 🛟 الدعم (بيحل مشاكل العميل)</label>
                        <label style="display:flex;align-items:center;gap:8px"><input type="checkbox" name="assistant_agent" value="1" <?= (int) ($editing['assistant_agent'] ?? 0) ? 'checked' : '' ?>> 🤖 الوكيل (بينفّذ الحملات مكانه)</label>
                        <div class="field-help">الإعدادات والرسوم من <a href="<?= url('admin/assistants.php') ?>">المساعدين</a></div>
                    </div>
                    <?php endif; ?>
                    <?php if (vp_ready()): ?>
                    <div class="field">
                        <label>🎬 خصم الفيديو %</label>
                        <input type="number" name="video_discount_pct" class="input" min="0" max="100" step="0.5" value="<?= e((string) (float) ($editing['video_discount_pct'] ?? 0)) ?>">
                        <div class="field-help">من سعر الفيديو (الأسعار من <a href="<?= url('admin/video-types.php') ?>">أنواع الفيديو</a>) · 0 = من غير خصم</div>
                    </div>
                    <?php endif; ?>
                    <div class="field">
                        <label>🔬 البحث العميق</label>
                        <label style="display:flex;align-items:center;gap:8px"><input type="checkbox" name="allow_research" value="1" <?= (int) ($editing['allow_research'] ?? 1) ? 'checked' : '' ?>> في الباقة (غير كده بيختفي من عند العميل)</label>
                    </div>
                </div>

                <div class="field">
                    <label>⏳ المدد والأسعار — العميل بيختار منها (الكريدت والتصميمات والأبحاث بينزلوا مرة واحدة أول المدة · 0 تصميمات/أبحاث = مفتوح)</label>
                    <div class="table-wrap">
                    <table class="table">
                        <thead><tr><th>الاسم</th><th>الأيام</th><th>السعر</th><th>قبل الخصم</th><th>الكريدت</th><th>التصميمات</th><th>الأبحاث</th><th>ظاهرة</th><th>امسح</th></tr></thead>
                        <tbody>
                        <?php foreach (array_merge($__prices, [null, null]) as $__i => $__p): ?>
                            <tr>
                                <td><input type="hidden" name="pp_id[<?= $__i ?>]" value="<?= (int) ($__p['id'] ?? 0) ?>"><input type="text" name="pp_label[<?= $__i ?>]" class="input" style="min-width:90px" value="<?= e($__p['label'] ?? '') ?>" placeholder="<?= $__p ? '' : ($__i === count($__prices) ? 'شهري' : 'سنة') ?>"></td>
                                <td><input type="number" name="pp_days[<?= $__i ?>]" class="input" style="width:80px" min="1" value="<?= (int) ($__p['days'] ?? ($__i === count($__prices) ? 30 : 365)) ?>"></td>
                                <td><input type="number" name="pp_price[<?= $__i ?>]" class="input" style="width:100px" min="0" step="0.01" value="<?= e((string) ($__p['price_egp'] ?? '')) ?>"></td>
                                <td><input type="number" name="pp_compare[<?= $__i ?>]" class="input" style="width:100px" min="0" step="0.01" value="<?= e((string) ($__p['compare_egp'] ?? '')) ?>"></td>
                                <td><input type="number" name="pp_credits[<?= $__i ?>]" class="input" style="width:90px" min="0" value="<?= e((string) ($__p['credits'] ?? '')) ?>"></td>
                                <td><input type="number" name="pp_designs[<?= $__i ?>]" class="input" style="width:90px" min="0" value="<?= e((string) ($__p['designs'] ?? '')) ?>"></td>
                                <td><input type="number" name="pp_research[<?= $__i ?>]" class="input" style="width:80px" min="0" value="<?= e((string) ($__p['research'] ?? '')) ?>"></td>
                                <td><input type="checkbox" name="pp_active[<?= $__i ?>]" value="1" <?= !$__p || (int) $__p['is_active'] ? 'checked' : '' ?>></td>
                                <td><?php if ($__p): ?><input type="checkbox" name="pp_del[<?= $__i ?>]" value="1" aria-label="امسح المدة"><?php endif; ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                    <div class="field-help">صف فاضي (من غير سعر ولا كريدت) مابيتحفظش. مدة عليها اشتراكات بتتخفي بدل ما تتمسح. لو مفيش مدد، العميل بيشتري بالسعر والمدة اللي فوق.</div>
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
                <?php if (function_exists('package_flags_ensure') && package_flags_ensure()): ?>
                <div style="display:flex;gap:20px;flex-wrap:wrap;margin-bottom:14px;padding:10px 12px;border-radius:12px;background:var(--surface-2)">
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
                        <input type="checkbox" name="allow_publish" <?= ($editing['allow_publish'] ?? 0) ? 'checked' : '' ?>> 📤 فيها نشر مباشر <small class="text-mute">(بيتفتح تلقائيًا مع الاشتراك ويتقفل لما الباقة تخلص)</small>
                    </label>
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
                        <input type="checkbox" name="watermark" <?= ($editing['watermark'] ?? 1) ? 'checked' : '' ?>> 💧 العلامة المائية على التصميمات <small class="text-mute">(لو العلامة مفعّلة في إعدادات المنصة)</small>
                    </label>
                </div>
                <?php endif; ?>

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
                    <?php if (array_key_exists('allow_publish', $p)): ?>
                    <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:8px">
                        <span class="chip <?= (int) $p['allow_publish'] ? 'chip-mint' : '' ?>"><?= (int) $p['allow_publish'] ? '📤 نشر مباشر' : 'من غير نشر مباشر' ?></span>
                        <span class="chip"><?= (int) $p['watermark'] ? '💧 بعلامة مائية' : 'من غير علامة مائية' ?></span>
                    </div>
                    <?php endif; ?>

                    <div style="margin:12px 0;font-size:15px">
                        <b style="color:var(--primary-ink)"><?= (int) $p['credits'] ?> ◇</b>
                        بـ <b><?= number_format((float) $p['price_egp'], 0) ?></b> جنيه
                        <div class="sub" style="font-size:12px">صلاحية <?= (int) ($p['validity_days'] ?: 30) ?> يوم</div>
                        <?php if ($__v2): ?>
                            <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:8px">
                                <?php foreach (pkg_prices((int) $p['id']) as $__pp): ?><span class="chip chip-line">⏳ <?= e($__pp['label']) ?> · <?= number_format((float) $__pp['price_egp'], 0) ?> ج · <?= (int) $__pp['credits'] ?> ◇<?= (int) $__pp['designs'] ? ' · ' . (int) $__pp['designs'] . ' 🎨' : '' ?></span><?php endforeach; ?>
                                <span class="chip"><?= e(pkg_tiers()[$p['quality_tier'] ?? 'standard'] ?? '') ?></span>
                                <?php if ((int) ($p['weekly_credits'] ?? 0) || (int) ($p['weekly_designs'] ?? 0)): ?><span class="chip">⏱ أسبوعيًا <?= (int) $p['weekly_credits'] ?> ◇ / <?= (int) $p['weekly_designs'] ?> 🎨</span><?php endif; ?>
                                <?php if (!(int) ($p['allow_research'] ?? 1)): ?><span class="chip">من غير بحث</span><?php endif; ?>
                            </div>
                        <?php endif; ?>
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

        <?php if ($__v2): ?>
        <div class="split split-2" style="margin-top:20px">
            <div class="card" id="addons">
                <div class="card-head"><h3>➕ الإضافات (الترقية من غير باقة جديدة)</h3></div>
                <p class="sub" style="font-size:12.5px">بتتشتري مع باقة شغالة بس · التصميمات الزيادة لحد آخر الباقة · الكريدت المساعد صالح «أيام الاستخدام» — ولو الباقة خلصت بيقف ويكمل مع الباقة الجديدة. والحد الأسبوعي بيزيد بقيمتها.</p>
                <form method="POST" data-safe-post>
                    <?= csrf_field() ?><input type="hidden" name="action" value="addon_save"><input type="hidden" name="addon_id" value="<?= (int) ($editAddon['id'] ?? 0) ?>">
                    <div class="field-row">
                        <div class="field"><label>النوع</label><select name="kind" class="input"><option value="designs" <?= ($editAddon['kind'] ?? '') === 'designs' ? 'selected' : '' ?>>🎨 تصميمات زيادة</option><option value="credits" <?= ($editAddon['kind'] ?? '') === 'credits' ? 'selected' : '' ?>>◇ كريدت مساعد</option></select></div>
                        <div class="field"><label>الكمية</label><input type="number" name="amount" class="input" min="1" value="<?= (int) ($editAddon['amount'] ?? 10) ?>"></div>
                        <div class="field"><label>السعر (جنيه)</label><input type="number" name="price_egp" class="input" min="0" step="0.01" value="<?= e((string) ($editAddon['price_egp'] ?? '0')) ?>"></div>
                        <div class="field"><label>أيام الاستخدام (الكريدت)</label><input type="number" name="validity_days" class="input" min="1" max="365" value="<?= (int) ($editAddon['validity_days'] ?? 30) ?>"></div>
                    </div>
                    <div class="field-row">
                        <div class="field"><label>الاسم (اختياري)</label><input type="text" name="name" class="input" maxlength="100" value="<?= e($editAddon['name'] ?? '') ?>"></div>
                        <div class="field"><label>وصف</label><input type="text" name="description" class="input" maxlength="255" value="<?= e($editAddon['description'] ?? '') ?>"></div>
                        <div class="field"><label>الترتيب</label><input type="number" name="sort_order" class="input" value="<?= (int) ($editAddon['sort_order'] ?? 0) ?>"></div>
                    </div>
                    <label style="display:flex;align-items:center;gap:8px;margin-bottom:10px"><input type="checkbox" name="is_active" value="1" <?= ($editAddon['is_active'] ?? 1) ? 'checked' : '' ?>> ظاهرة للعملاء</label>
                    <button class="btn sm"><?= $editAddon ? '💾 حفظ' : '＋ إضافة' ?></button><?php if ($editAddon): ?> <a href="<?= url('admin/packages.php#addons') ?>" class="btn ghost sm">إلغاء</a><?php endif; ?>
                </form>
                <?php if ($addons): ?>
                <div class="table-wrap" style="margin-top:14px"><table class="table">
                    <thead><tr><th>الإضافة</th><th>السعر</th><th>الحالة</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($addons as $__a): ?>
                        <tr><td><?= e(addon_label($__a)) ?><?= $__a['kind'] === 'credits' ? ' <small class="text-mute">· ' . (int) $__a['validity_days'] . ' يوم</small>' : '' ?></td><td class="num"><?= number_format((float) $__a['price_egp'], 0) ?> ج</td>
                            <td><span class="chip"><?= (int) $__a['is_active'] ? 'ظاهرة' : 'مخفية' ?></span></td>
                            <td><a href="?addon=<?= (int) $__a['id'] ?>#addons" class="btn ghost sm">✎</a>
                                <?php if ((int) $__a['is_active']): ?><form method="POST" style="display:inline" data-safe-post><?= csrf_field() ?><input type="hidden" name="action" value="addon_delete"><input type="hidden" name="addon_id" value="<?= (int) $__a['id'] ?>"><button class="btn ghost sm">إخفاء</button></form><?php endif; ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
                <?php endif; ?>
            </div>

            <div class="card" id="signup">
                <div class="card-head"><h3>🎁 باقة التسجيل (أول مرة)</h3></div>
                <p class="sub" style="font-size:12.5px">بتتفعّل مجانًا لكل حساب جديد بكريدتها وحصصها ومدتها — غيّرها حسب العروض. فاضي = الكريدت الترحيبي العادي (<?= (int) STARTER_CREDITS ?> كريدت).</p>
                <form method="POST" data-safe-post>
                    <?= csrf_field() ?><input type="hidden" name="action" value="signup">
                    <div class="field"><label>الباقة</label>
                        <select name="signup_package_id" class="input"><option value="">— الكريدت الترحيبي العادي —</option>
                            <?php foreach ($packages as $__p): ?><option value="<?= (int) $__p['id'] ?>" <?= (string) get_setting('signup_package_id', '') === (string) $__p['id'] ? 'selected' : '' ?>><?= e($__p['name']) ?><?= (int) $__p['is_active'] ? '' : ' (مخفية)' ?></option><?php endforeach; ?>
                        </select></div>
                    <div class="field"><label>المدة</label>
                        <select name="signup_price_id" class="input"><option value="">أول مدة في الباقة</option>
                            <?php foreach ($packages as $__p): foreach (pkg_prices((int) $__p['id'], false) as $__pp): ?><option value="<?= (int) $__pp['id'] ?>" <?= (string) get_setting('signup_package_price_id', '') === (string) $__pp['id'] ? 'selected' : '' ?>><?= e($__p['name'] . ' — ' . $__pp['label'] . ' (' . (int) $__pp['credits'] . ' كريدت · ' . (int) $__pp['days'] . ' يوم)') ?></option><?php endforeach; endforeach; ?>
                        </select></div>
                    <button class="btn sm">حفظ</button>
                </form>
                <?php if ($__sp = pkg_signup_package()): ?><p class="sub" style="margin-top:10px">دلوقتي: <b><?= e($__sp['name']) ?></b> · <?= (int) $__sp['credits'] ?> كريدت · <?= e($__sp['duration_label']) ?></p><?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

    </main>
</div>

<script>
// تقدير الكريدت اللي الحصص محتاجاه (حصة × تكلفة العملية) — علشان الكريدت والحصص يمشوا مع بعض
(function () {
    var cost = <?= json_encode(['posts' => cost_for('content_generation_cost'), 'designs' => design_credit_cost(), 'publishes' => 0,
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

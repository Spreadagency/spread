<?php
/**
 * Spread AI — الأدمن: أنواع الفيديو وأسعارها (المرحلة 6)
 * كل نوع: الاسم والوصف · المدد بأسعارها بالجنيه ومدة التنفيذ · لينكات أمثلة («شوف فيديوهات زيه»)
 * خصم الفيديو لكل باقة بيتحدد من «الباقات». الفيديو بيتدفع بالفلوس — مالوش كريدت.
 */
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/video-pricing.php';

require_admin();
require_admin_can('manage_packages');

if (!vp_ready()) {
    flash_set('warning', 'شغّل ترحيل 2026_video_pricing الأول (فحص التحديث)');
    redirect('admin/migrations.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    decode_b64_fields();
    $act = (string) ($_POST['action'] ?? '');

    if ($act === 'save') {
        $id = (int) ($_POST['id'] ?? 0);
        $name = mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 120);
        if ($name === '') {
            flash_set('danger', 'اكتب اسم نوع الفيديو');
            redirect('admin/video-types.php' . ($id ? '?id=' . $id : '?id=new') . '#edit');
        }
        $durs = [];
        foreach ((array) ($_POST['d_label'] ?? []) as $i => $l) {
            $durs[] = ['label' => $l, 'seconds' => $_POST['d_seconds'][$i] ?? 0, 'price_egp' => $_POST['d_price'][$i] ?? 0, 'days' => $_POST['d_days'][$i] ?? 0];
        }
        $durs = vp_clean_durations($durs);
        $samples = [];
        foreach ((array) ($_POST['s_url'] ?? []) as $i => $u) $samples[] = ['title' => $_POST['s_title'][$i] ?? '', 'url' => $u];
        $samples = vp_clean_samples($samples);
        $vals = [$name, mb_substr(trim((string) ($_POST['description'] ?? '')), 0, 500) ?: null,
                 json_encode($durs, JSON_UNESCAPED_UNICODE), json_encode($samples, JSON_UNESCAPED_UNICODE),
                 !empty($_POST['is_active']) ? 1 : 0, (int) ($_POST['sort_order'] ?? 0)];
        if ($id > 0 && vp_type($id, false)) {
            db_run('UPDATE video_types SET name = ?, description = ?, durations_json = ?, samples_json = ?, is_active = ?, sort_order = ? WHERE id = ?', array_merge($vals, [$id]));
        } else {
            $id = (int) db_insert('INSERT INTO video_types (name, description, durations_json, samples_json, is_active, sort_order) VALUES (?,?,?,?,?,?)', $vals);
        }
        admin_log('video_type_save', 'video_type', $id, $name . ' · ' . count($durs) . ' مدد');
        flash_set($durs ? 'success' : 'warning', $durs ? 'اتحفظ ✓' : 'اتحفظ — بس مفيش مدة بسعر، فالنوع ده مش هيظهر للعميل');
        redirect('admin/video-types.php?id=' . $id . '#edit');
    }

    if ($act === 'toggle') {
        $id = (int) ($_POST['id'] ?? 0);
        db_run('UPDATE video_types SET is_active = 1 - is_active WHERE id = ?', [$id]);
        admin_log('video_type_toggle', 'video_type', $id);
        redirect('admin/video-types.php');
    }

    if ($act === 'settings' && admin_can('site_settings')) {
        set_setting('video_orders_enabled', !empty($_POST['video_orders_enabled']) ? '1' : '0');
        admin_log('video_orders_enabled', 'settings', null, !empty($_POST['video_orders_enabled']) ? '1' : '0');
        flash_set('success', 'اتحفظ ✓');
        redirect('admin/video-types.php');
    }
    redirect('admin/video-types.php');
}

$types = vp_types(false);
$editId = ($_GET['id'] ?? '') === 'new' ? 0 : (int) ($_GET['id'] ?? 0);
$t = $editId ? ($types[$editId] ?? null) : null;
$showForm = ($_GET['id'] ?? '') === 'new' || $t || !$types;
$enabled = get_setting('video_orders_enabled', '1') === '1';
$stats = [];
foreach (db_all('SELECT type_id, COUNT(*) n, SUM(final_egp) egp FROM video_orders WHERE status = "paid" GROUP BY type_id') as $r) $stats[(int) $r['type_id']] = $r;
$pkgDisc = db_all('SELECT name, video_discount_pct FROM credit_packages WHERE video_discount_pct > 0 ORDER BY id');

$active = 'video-types';
$page_title = 'أنواع الفيديو وأسعارها';
include __DIR__ . '/../templates/admin-header.php';
?>
<div class="app">
    <?php include __DIR__ . '/../templates/admin-sidebar.php'; ?>
    <main class="main">
        <div class="page-head">
            <h1>💰 أنواع الفيديو وأسعارها</h1>
            <div class="sub">العميل بيختار النوع والمدة بعد ما يعتمد السكريبت ← بيشوف السعر بعد خصم باقته ← يدفع ← الطلب يوصل <a href="<?= url('admin/video-requests.php') ?>">طلبات الفيديو</a>.</div>
        </div>
        <?= render_flash() ?>

        <?php if (!$enabled || !vp_types()): ?>
            <div class="alert alert-warning">طلب الفيديو بالدفع <b>مش شغال</b> دلوقتي — <?= !$enabled ? 'متقفل من الإعدادات تحت' : 'مفيش نوع متفعّل بمدة وسعر' ?>. العميل بيطلب على واتساب زي الأول.</div>
        <?php endif; ?>

        <div class="card" style="margin-bottom:20px">
            <div class="card-head" style="display:flex;align-items:center;gap:8px"><h3 style="margin-inline-end:auto">الأنواع</h3><a class="btn sm" href="?id=new#edit">＋ نوع جديد</a></div>
            <?php if (!$types): ?><p class="sub" style="padding:12px">لسه مفيش أنواع — ابدأ بأول نوع (مثلًا: ريلز تصوير · موشن جرافيك · فيديو إعلاني).</p><?php else: ?>
            <div class="table-wrap"><table class="table">
                <thead><tr><th>النوع</th><th>المدد والأسعار</th><th>الأمثلة</th><th>اتباع</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($types as $id => $r): $sx = $stats[$id] ?? null; ?>
                    <tr<?= (int) $r['is_active'] ? '' : ' style="opacity:.55"' ?>>
                        <td><b><?= e($r['name']) ?></b><?= (int) $r['is_active'] ? '' : ' <span class="chip chip-line">متقفل</span>' ?><div class="sub" style="font-size:11.5px"><?= e((string) $r['description']) ?></div></td>
                        <td style="font-size:12.5px"><?php foreach ($r['durations'] as $d): ?><div><?= e($d['label']) ?> — <b><?= e(vp_money((float) $d['price_egp'])) ?></b><?= $d['days'] ? ' <span class="text-mute">· ' . (int) $d['days'] . ' يوم</span>' : '' ?></div><?php endforeach; ?><?= $r['durations'] ? '' : '<span class="text-mute">مفيش أسعار</span>' ?></td>
                        <td class="num"><?= count($r['samples']) ?></td>
                        <td class="num"><?= $sx ? (int) $sx['n'] . ' · ' . e(vp_money((float) $sx['egp'])) : '—' ?></td>
                        <td style="white-space:nowrap"><a class="btn ghost sm" href="?id=<?= $id ?>#edit">✎</a>
                            <form method="POST" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= $id ?>"><button class="btn ghost sm"><?= (int) $r['is_active'] ? 'إيقاف' : 'تفعيل' ?></button></form></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <?php endif; ?>
        </div>

        <?php if ($showForm): $durs = $t['durations'] ?? []; $smp = $t['samples'] ?? []; ?>
        <div class="card" id="edit" style="margin-bottom:20px">
            <div class="card-head"><h3><?= $t ? 'تعديل: ' . e($t['name']) : 'نوع جديد' ?></h3></div>
            <form method="POST" data-safe-post>
                <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int) ($t['id'] ?? 0) ?>">
                <div class="field-row">
                    <div class="field"><label>الاسم <span class="req">*</span></label><input name="name" class="input" maxlength="120" required value="<?= e((string) ($t['name'] ?? '')) ?>" placeholder="ريلز تصوير في المكان"></div>
                    <div class="field"><label>الترتيب</label><input type="number" name="sort_order" class="input" value="<?= (int) ($t['sort_order'] ?? 0) ?>"></div>
                </div>
                <div class="field"><label>الوصف (العميل بيشوفه)</label><input name="description" class="input" maxlength="500" value="<?= e((string) ($t['description'] ?? '')) ?>" placeholder="مصوّر محترف بيجيلك + مونتاج + موسيقى + ترجمة"></div>

                <div class="field">
                    <label>⏱ المدد والأسعار (بالجنيه — قبل خصم الباقة) · الصف من غير اسم أو سعر بيتشال</label>
                    <div class="table-wrap"><table class="table">
                        <thead><tr><th>المدة (اللي العميل يشوفه)</th><th style="width:110px">بالثواني</th><th style="width:130px">السعر</th><th style="width:120px">التسليم (يوم)</th></tr></thead>
                        <tbody>
                        <?php for ($i = 0; $i < VP_MAX_DURATIONS; $i++): $d = $durs[$i] ?? null; ?>
                            <tr><td><input name="d_label[]" class="input" maxlength="60" value="<?= e((string) ($d['label'] ?? '')) ?>" placeholder="<?= $i === 0 ? '30 ثانية' : '' ?>"></td>
                                <td><input type="number" name="d_seconds[]" class="input" min="0" max="3600" value="<?= $d ? (int) $d['seconds'] : '' ?>"></td>
                                <td><input type="number" name="d_price[]" class="input" min="0" step="0.01" value="<?= $d ? e((string) $d['price_egp']) : '' ?>"></td>
                                <td><input type="number" name="d_days[]" class="input" min="0" max="90" value="<?= $d ? (int) $d['days'] : '' ?>"></td></tr>
                        <?php endfor; ?>
                        </tbody>
                    </table></div>
                </div>

                <div class="field">
                    <label>🎞 «شوف فيديوهات زيه» — لينكات أمثلة (يوتيوب · إنستجرام · تيك توك · درايف)</label>
                    <div class="table-wrap"><table class="table">
                        <thead><tr><th style="width:35%">العنوان</th><th>اللينك</th></tr></thead>
                        <tbody>
                        <?php for ($i = 0; $i < VP_MAX_SAMPLES; $i++): $s = $smp[$i] ?? null; ?>
                            <tr><td><input name="s_title[]" class="input" maxlength="120" value="<?= e((string) ($s['title'] ?? '')) ?>" placeholder="<?= $i === 0 ? 'ريلز لعيادة أسنان' : '' ?>"></td>
                                <td><input name="s_url[]" class="input" dir="ltr" maxlength="500" value="<?= e((string) ($s['url'] ?? '')) ?>" placeholder="https://"></td></tr>
                        <?php endfor; ?>
                        </tbody>
                    </table></div>
                </div>
                <label style="display:flex;align-items:center;gap:8px;margin-bottom:12px"><input type="checkbox" name="is_active" value="1" <?= !$t || (int) $t['is_active'] ? 'checked' : '' ?>> متفعّل (بيظهر للعميل)</label>
                <button class="btn">💾 حفظ</button>
            </form>
        </div>
        <?php endif; ?>

        <div class="split split-2">
            <div class="card">
                <div class="card-head"><h3>🏷 خصم الفيديو في الباقات</h3></div>
                <?php if ($pkgDisc): ?>
                    <ul style="margin:0;padding-inline-start:18px;line-height:2"><?php foreach ($pkgDisc as $p): ?><li><?= e($p['name']) ?> — <b><?= (float) $p['video_discount_pct'] ?>%</b></li><?php endforeach; ?></ul>
                <?php else: ?><p class="sub">مفيش باقة عليها خصم فيديو.</p><?php endif; ?>
                <p class="field-help">الخصم بيتحدد لكل باقة من <a href="<?= url('admin/packages.php') ?>">الباقات</a> — والعميل بياخد خصم باقته وقت ما اشترك.</p>
            </div>
            <?php if (admin_can('site_settings')): ?>
            <div class="card">
                <div class="card-head"><h3>⚙️ الإعدادات</h3></div>
                <form method="POST">
                    <?= csrf_field() ?><input type="hidden" name="action" value="settings">
                    <label style="display:flex;align-items:center;gap:8px;margin-bottom:10px"><input type="checkbox" name="video_orders_enabled" value="1" <?= $enabled ? 'checked' : '' ?>> طلب الفيديو بالدفع شغال</label>
                    <p class="field-help">مقفول (أو مفيش أنواع) = العميل بيطلب على واتساب زي الأول من غير سعر.</p>
                    <button class="btn sm">حفظ</button>
                </form>
            </div>
            <?php endif; ?>
        </div>
    </main>
</div>
<?php include __DIR__ . '/../templates/admin-footer.php'; ?>

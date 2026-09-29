<?php
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/prompt-builder.php';

require_admin();
require_admin_can('ai_settings');

$admin = current_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    decode_b64_fields();
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $type = $_POST['prompt_type'] ?? 'content';
        $title = trim($_POST['title'] ?? '');
        $text = trim($_POST['prompt_text'] ?? '');

        // Get latest version number
        $latest = db_one('SELECT MAX(version) AS v FROM prompts WHERE prompt_type = ?', [$type]);
        $newVersion = ((int) ($latest['v'] ?? 0)) + 1;

        // Deactivate all
        db_run('UPDATE prompts SET is_active = 0 WHERE prompt_type = ?', [$type]);

        // Insert new active version
        $id = db_insert_row('prompts', [
            'prompt_type' => $type,
            'title'       => $title ?: 'الإصدار ' . $newVersion,
            'prompt_text' => $text,
            'version'     => $newVersion,
            'is_active'   => 1,
            'created_by'  => $admin['id'],
        ]);

        admin_log('save_prompt', 'prompt', $id, "type=$type version=$newVersion");
        flash_set('success', 'تم حفظ إصدار جديد وتفعيله ✓');
        redirect('admin/prompts.php?type=' . $type);
    }

    if ($action === 'add_design_template') {
        $title = trim($_POST['dt_title'] ?? '');
        $snippet = trim($_POST['dt_snippet'] ?? '');
        if ($title && $snippet) {
            db_insert(
                'INSERT INTO design_templates (title, prompt_snippet, mode, sort_order) VALUES (?, ?, ?, ?)',
                [mb_substr($title, 0, 180), mb_substr($snippet, 0, 3000),
                 in_array($_POST['dt_mode'] ?? '', ['any','from_content','before_after','free','personal'], true) ? $_POST['dt_mode'] : 'any',
                 (int) ($_POST['dt_sort'] ?? 0)]
            );
            admin_log('add_design_template', 'design_template', null, $title);
            flash_set('success', 'تم إضافة قالب التصميم ✓');
        }
        redirect('admin/prompts.php');
    }

    if ($action === 'toggle_design_template') {
        db_run('UPDATE design_templates SET is_active = 1 - is_active WHERE id = ?', [(int) $_POST['dt_id']]);
        redirect('admin/prompts.php');
    }

    if ($action === 'delete_design_template') {
        db_run('DELETE FROM design_templates WHERE id = ?', [(int) $_POST['dt_id']]);
        admin_log('delete_design_template', 'design_template', (int) $_POST['dt_id']);
        flash_set('success', 'تم حذف القالب');
        redirect('admin/prompts.php');
    }

    if ($action === 'activate') {
        $id = (int) ($_POST['id'] ?? 0);
        $p = db_one('SELECT * FROM prompts WHERE id = ?', [$id]);
        if ($p) {
            db_run('UPDATE prompts SET is_active = 0 WHERE prompt_type = ?', [$p['prompt_type']]);
            db_run('UPDATE prompts SET is_active = 1 WHERE id = ?', [$id]);
            admin_log('activate_prompt', 'prompt', $id);
            flash_set('success', 'تم تفعيل الإصدار');
        }
        redirect('admin/prompts.php?type=' . ($p['prompt_type'] ?? 'content'));
    }
}

$activeType = $_GET['type'] ?? 'content';
$current = db_one('SELECT * FROM prompts WHERE prompt_type = ? AND is_active = 1', [$activeType]);
$designTemplates = db_all('SELECT * FROM design_templates ORDER BY sort_order, id');
$history = db_all('SELECT * FROM prompts WHERE prompt_type = ? ORDER BY version DESC LIMIT 20', [$activeType]);

$active = 'prompts';
$page_title = 'إدارة الـ Prompts';
include __DIR__ . '/../templates/admin-header.php';
?>

<div class="app">
    <?php include __DIR__ . '/../templates/admin-sidebar.php'; ?>
    <main class="main">
        <div class="topbar">
            <button class="menu-toggle" onclick="toggleSidebar()">☰</button>
            <div style="flex:1"></div>
            <span class="badge-admin">ADMIN</span>
        </div>

        <?= render_flash() ?>

        <div class="page-head">
            <h1>إدارة الـ Prompts ✎</h1>
            <div class="sub">التحكم في القوالب اللي بيستخدمها الذكاء الاصطناعي</div>
        </div>

        <!-- Type tabs -->
        <div class="seg" style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:14px">
            <?php foreach (prompt_types() as $ptKey => [$ptLabel, $ptDesc]): ?>
                <a href="?type=<?= e($ptKey) ?>" style="text-decoration:none">
                    <button type="button" class="<?= $activeType === $ptKey ? 'on' : '' ?>" title="<?= e($ptDesc) ?>"><?= e($ptLabel) ?></button>
                </a>
            <?php endforeach; ?>
        </div>
        <?php $ptInfo = prompt_types()[$activeType] ?? null; ?>
        <?php if ($ptInfo): ?>
            <p class="sub" style="margin-bottom:16px">
                <?= e($ptInfo[1]) ?>
                <?php if ($activeType !== 'content'): ?>
                    — <b>سيبه فاضي</b> عشان يستخدم البرومبت المدمج في الكود.
                <?php endif; ?>
            </p>
        <?php endif; ?>

        <div class="split split-flex" style="--c1:1.6fr;--c2:1fr;gap:20px;align-items:flex-start">

            <!-- Editor -->
            <div class="card">
                <div class="card-head">
                    <h3>القالب الحالي
                        <?php if ($current): ?>
                            <span class="chip chip-mint">v<?= $current['version'] ?> (نشط)</span>
                        <?php else: ?>
                            <span class="chip chip-amber">لا يوجد قالب نشط</span>
                        <?php endif; ?>
                    </h3>
                </div>

                <form method="POST" data-safe-post>
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="prompt_type" value="<?= e($activeType) ?>">

                    <div class="field">
                        <label>عنوان الإصدار (اختياري)</label>
                        <input type="text" name="title" class="input" placeholder="مثال: تحسين النبرة المصرية">
                    </div>

                    <div class="field">
                        <label>نص القالب</label>
                        <textarea name="prompt_text" class="textarea auto code-area" rows="20" required dir="ltr" style="font-family:'JetBrains Mono', monospace;font-size:12.5px;line-height:1.7;direction:rtl"><?= e($current['prompt_text'] ?? '') ?></textarea>
                        <div class="field-help">
                            استخدم {brand_name}، {industry}، {audience}، {tone}، {content_type}، {platform}، {length}، {extra_notes} كمتغيرات. خروج النص لازم يحتوي على [CONTENT][/CONTENT] [HASHTAGS][/HASHTAGS] [CTA][/CTA].
                        </div>
                    </div>

                    <button class="btn">حفظ كإصدار جديد + تفعيل</button>
                </form>
            </div>

            <!-- Versions history -->
            <div class="card">
                <div class="card-head"><h3>الإصدارات السابقة</h3></div>

                <?php if (empty($history)): ?>
                    <div class="text-mute" style="font-size:12.5px;text-align:center;padding:20px">لا توجد إصدارات</div>
                <?php else: ?>
                    <div style="display:grid;gap:8px">
                        <?php foreach ($history as $p): ?>
                            <div style="background:var(--surface-2);border-radius:12px;padding:12px;border:1px solid <?= $p['is_active'] ? 'var(--primary)' : 'var(--line)' ?>">
                                <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px">
                                    <span class="chip chip-primary">v<?= $p['version'] ?></span>
                                    <?php if ($p['is_active']): ?>
                                        <span class="chip chip-mint">نشط</span>
                                    <?php endif; ?>
                                    <span style="margin-inline-start:auto;font-size:11px;color:var(--mute)"><?= e(time_ago($p['created_at'])) ?></span>
                                </div>
                                <div style="font-size:12.5px;color:var(--ink-2)"><?= e($p['title'] ?? 'بدون عنوان') ?></div>
                                <div style="font-size:11px;color:var(--mute);margin-top:4px"><?= e(str_limit($p['prompt_text'], 100)) ?></div>

                                <?php if (!$p['is_active']): ?>
                                    <form method="POST" data-safe-post style="margin-top:8px">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="activate">
                                        <input type="hidden" name="id" value="<?= $p['id'] ?>">
                                        <button class="btn ghost sm" onclick="return confirm('تفعيل الإصدار v<?= $p['version'] ?>؟')">إعادة تفعيل</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- قوالب برومبت التصميم (استوديو التصميم) -->
        <div class="card" style="margin-top:20px">
            <div class="card-head"><h3>🎨 قوالب برومبت التصميم</h3></div>
            <p class="sub" style="margin-bottom:12px">القوالب دي بتظهر للعملاء في «استوديو التصميم» — العميل يختارها والـ snippet بيتضاف على برومبت التصميم.</p>

            <form method="POST" data-safe-post style="display:grid;grid-template-columns:1fr 1.8fr 0.8fr 0.5fr auto;gap:8px;align-items:end;margin-bottom:16px;padding-bottom:14px;border-bottom:1px dashed var(--line)">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="add_design_template">
                <div class="field"><label>اسم القالب</label><input type="text" name="dt_title" class="input" placeholder="ستايل فاخر" required></div>
                <div class="field"><label>الـ Snippet (إنجليزي مفضّل)</label><input type="text" name="dt_snippet" class="input" placeholder="Luxury style: gold accents, elegant serif..." required></div>
                <div class="field"><label>الوضع</label>
                    <select name="dt_mode" class="input">
                        <?php foreach (['any' => 'كل الأوضاع', 'from_content' => 'من محتوى', 'before_after' => 'قبل/بعد', 'free' => 'برومبت حر', 'personal' => 'صورة شخصية'] as $k => $v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="field"><label>الترتيب</label><input type="number" name="dt_sort" class="input" value="0"></div>
                <button type="submit" class="btn">+ إضافة</button>
            </form>

            <div class="table-wrap">

            <table class="table">
                <thead><tr><th>القالب</th><th>الوضع</th><th>الحالة</th><th></th></tr></thead>
                <tbody>
                <?php $dtModes = ['any' => 'كل الأوضاع', 'from_content' => 'من محتوى', 'before_after' => 'قبل/بعد', 'free' => 'برومبت حر', 'personal' => 'صورة شخصية']; foreach ($designTemplates as $dt): ?>
                    <tr style="<?= $dt['is_active'] ? '' : 'opacity:.5' ?>">
                        <td><b><?= e($dt['title']) ?></b><div class="sub" style="font-size:12px"><?= e(mb_substr($dt['prompt_snippet'], 0, 90)) ?>...</div></td>
                        <td><?= e($dtModes[$dt['mode']] ?? $dt['mode']) ?></td>
                        <td><span class="chip <?= $dt['is_active'] ? 'chip-primary' : '' ?>"><?= $dt['is_active'] ? 'ظاهر' : 'مخفي' ?></span></td>
                        <td style="white-space:nowrap">
                            <form method="POST" data-safe-post style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="toggle_design_template"><input type="hidden" name="dt_id" value="<?= $dt['id'] ?>"><button class="btn btn-sm sm"><?= $dt['is_active'] ? 'إخفاء' : 'إظهار' ?></button></form>
                            <form method="POST" data-safe-post style="display:inline" onsubmit="return confirm('حذف القالب؟')"><?= csrf_field() ?><input type="hidden" name="action" value="delete_design_template"><input type="hidden" name="dt_id" value="<?= $dt['id'] ?>"><button class="btn btn-sm sm" style="color:#c0392b">🗑</button></form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
                </div>
        </div>

    </main>
</div>

<?php include __DIR__ . '/../templates/admin-footer.php'; ?>

<?php
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/prompt-builder.php';

require_admin();
require_admin_can('ai_settings');

$admin = current_admin();
prompt_seed_library();   // البرومبتات اللي كانت في الكود بتظهر هنا وتتعدّل

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

    // مكتبة v1: استيراد كنسخ جديدة مفعّلة (القديمة بتفضل في الإصدارات وتترجع بزرار)
    if ($action === 'pack_import') {
        $types = ($_POST['scope'] ?? 'all') === 'all' ? array_keys(prompt_pack_status()) : [(string) ($_POST['prompt_type'] ?? '')];
        $done = prompt_pack_import($types);
        if (!empty($_POST['eval_v1'])) set_setting('campaign_eval_cats', implode("\n", prompt_pack_eval_criteria()));
        admin_log('prompt_pack_import', 'prompt', null, 'v1: ' . implode(',', $done));
        flash_set('success', $done ? 'اتستورد ' . count($done) . ' برومبت من مكتبة v1 وبقوا مفعّلين ✓ — النسخ القديمة موجودة في «الإصدارات السابقة»' : 'كل البرومبتات دي مستوردة خلاص');
        redirect('admin/prompts.php?type=' . urlencode((string) ($_POST['back'] ?? 'content')));
    }

    if ($action === 'engine_settings') {
        set_setting('prompt_dna_mode', in_array($_POST['dna_mode'] ?? '', ['off', 'lexicon', 'full'], true) ? $_POST['dna_mode'] : 'lexicon');
        set_setting('design_prompt_writer', !empty($_POST['design_writer']) ? '1' : '0');
        admin_log('prompt_engine_settings', 'settings', null, 'dna=' . get_setting('prompt_dna_mode') . ' writer=' . get_setting('design_prompt_writer'));
        flash_set('success', 'اتحفظت الإعدادات ✓');
        redirect('admin/prompts.php?type=' . urlencode((string) ($_POST['back'] ?? 'content')));
    }

    if ($action === 'eval_criteria') {
        $cats = array_slice(array_values(array_filter(array_map('trim', preg_split('/\R/u', (string) ($_POST['cats'] ?? ''))))), 0, 10);
        if (count($cats) < 3) { flash_set('danger', 'اكتب 3 معايير على الأقل (سطر لكل معيار)'); redirect('admin/prompts.php?type=campaign_eval'); }
        set_setting('campaign_eval_cats', implode("\n", array_map(fn($c) => mb_substr($c, 0, 60), $cats)));
        set_setting('campaign_eval_ready', (string) max(50, min(95, (int) ($_POST['ready'] ?? 80))));
        admin_log('eval_criteria', 'settings', null, implode('، ', $cats));
        flash_set('success', 'اتحفظت معايير التقييم ✓');
        redirect('admin/prompts.php?type=campaign_eval');
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
if (!isset(prompt_types()[$activeType])) $activeType = 'content';
$current = db_one('SELECT * FROM prompts WHERE prompt_type = ? AND is_active = 1', [$activeType]);
$packStatus = prompt_pack_status();
$packCount = array_count_values($packStatus);
$curText = (string) ($current['prompt_text'] ?? '');
$isFull = prompt_is_full($curText);

// معاينة بالبيانات الحقيقية لعميل (المتغيرات الخاصة بالمهمة بتتملى وقت التشغيل)
$pvUser = null; $pvText = '';
if (!empty($_GET['pv'])) {
    $q = trim((string) $_GET['pv']);
    $pvUser = ctype_digit($q) ? db_one('SELECT id, name, email FROM users WHERE id = ?', [(int) $q]) : db_one('SELECT id, name, email FROM users WHERE email = ?', [$q]);
    if ($pvUser && $curText !== '') {
        $agents = ['plan_ideas' => 'ideas', 'campaign_ideas' => 'ideas', 'campaign_plan_eval' => 'ideas', 'agent_content_insights' => 'ideas', 'design_base' => 'design',
                   'visual_identity' => 'design', 'visual_analysis' => 'design', 'design_idea' => 'design', 'brand_summary' => 'brand', 'brand_agent' => 'brand',
                   'source_summary' => 'brand', 'agent_brand' => 'brand', 'agent_personas' => 'brand'];
        $agent = $agents[$activeType] ?? (str_starts_with($activeType, 'agent_') ? substr($activeType, 6) : 'copy');
        $agent = ['copy' => 'copy', 'ideas' => 'ideas', 'design' => 'design', 'video' => 'video', 'schedule' => 'schedule', 'brand' => 'brand'][$agent] ?? 'copy';
        $base = prompt_brand_vars((int) $pvUser['id'], null, $agent);
        $ph = [];
        foreach (prompt_vars_help() as $k => $d) if (!isset($base[$k]) || trim((string) $base[$k]) === '') $ph[$k] = '⟨' . $d . ' — بيتملى وقت التشغيل⟩';
        $isAddon = str_starts_with($activeType, 'agent_') && $activeType !== 'agent_brand' && $activeType !== 'agent_personas' && $activeType !== 'agent_content_insights';
        $pvText = prompt_apply($curText, array_filter($base, fn($v) => trim((string) $v) !== '') + $ph,
            $isAddon ? ['addon' => true] : ['strip_output' => $activeType !== 'content', 'dna' => in_array($activeType, ['content', 'plan_produce', 'campaign_content', 'quick_post', 'ai_edit', 'campaign_improve'], true)]);
    }
}
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

        <!-- مكتبة v1 (Egyptian Content DNA) -->
        <div class="card" style="margin-bottom:16px">
            <div class="card-head"><h3>📦 مكتبة البرومبتات v1 — Egyptian Content DNA</h3>
                <span class="chip chip-mint">مستورد <?= (int) ($packCount['v1'] ?? 0) ?></span>
                <span class="chip chip-primary">اتعدّل <?= (int) ($packCount['edited'] ?? 0) ?></span>
                <span class="chip chip-amber">قديم <?= (int) ($packCount['old'] ?? 0) ?></span></div>
            <p class="sub" style="margin:0 0 10px;line-height:1.9">
                26 برومبت كامل (كتابة · أفكار · حملات · تقييم · تحسين · هوية · تصميم · Agents) بالـ <b>متغيرات</b> — الموقع بيملا كل متغير بالبيانات الحقيقية للعميل
                (الهوية كاملة · الخدمات والأسعار · العملاء المثاليين · الألوان · ذاكرة البراند · تعليقات العميل · الفكرة · المنشور…).
                قسم «شكل المخرجات» بيتشال تلقائيًا في المهام اللي الكود بيقرا فيها شكل معيّن وبيتحط مكانه الشكل الصح — فالتعديل هنا مايكسرش أي حاجة.
                الاستيراد بيعمل <b>إصدار جديد</b> لكل نوع — القديم بيفضل في «الإصدارات السابقة» وترجعه بزرار.
            </p>
            <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center">
                <form method="POST" data-safe-post style="display:flex;gap:10px;align-items:center;flex-wrap:wrap" onsubmit="return confirm('استيراد كل برومبتات v1 كإصدارات جديدة مفعّلة؟')">
                    <?= csrf_field() ?><input type="hidden" name="action" value="pack_import"><input type="hidden" name="scope" value="all"><input type="hidden" name="back" value="<?= e($activeType) ?>">
                    <label style="font-size:12.5px;display:flex;gap:6px;align-items:center"><input type="checkbox" name="eval_v1" value="1" checked> ومعايير التقييم بتاعة v1 (Spread Content Score)</label>
                    <button class="btn" <?= ($packCount['old'] ?? 0) || ($packCount['edited'] ?? 0) ? '' : 'disabled' ?>>⬇ استيراد الكل</button>
                </form>
                <form method="POST" data-safe-post style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-inline-start:auto">
                    <?= csrf_field() ?><input type="hidden" name="action" value="engine_settings"><input type="hidden" name="back" value="<?= e($activeType) ?>">
                    <label style="font-size:12.5px">🇪🇬 مرجع اللغة المصرية في برومبتات الكتابة:</label>
                    <select name="dna_mode" class="input" style="max-width:220px">
                        <?php foreach (['lexicon' => 'القاموس والأمثلة بس (موصى بيه)', 'full' => 'الطبقة كاملة + القاموس', 'off' => 'من غير مرجع'] as $k => $v): ?>
                            <option value="<?= $k ?>" <?= prompt_dna_mode() === $k ? 'selected' : '' ?>><?= e($v) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <label style="font-size:12.5px;display:flex;gap:6px;align-items:center" title="لما «أساس برومبت التصميم» يبقى برومبت كامل: الـ AI يكتب برومبت الصورة من كل التفاصيل قبل التصميم (طلب نصي زيادة صغير)">
                        <input type="checkbox" name="design_writer" value="1" <?= get_setting('design_prompt_writer', '1') === '1' ? 'checked' : '' ?>> كاتب برومبت التصميم</label>
                    <button class="btn ghost sm">حفظ</button>
                </form>
            </div>
        </div>

        <!-- Type tabs -->
        <div class="seg" style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:14px">
            <?php foreach (prompt_types() as $ptKey => [$ptLabel, $ptDesc]): ?>
                <a href="?type=<?= e($ptKey) ?>" style="text-decoration:none">
                    <button type="button" class="<?= $activeType === $ptKey ? 'on' : '' ?>" title="<?= e($ptDesc) ?>"><?= e($ptLabel) ?><?= ($packStatus[$ptKey] ?? '') === 'v1' ? ' ✓' : (($packStatus[$ptKey] ?? '') === 'edited' ? ' ✎' : '') ?></button>
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
                            <?php if ($isFull): ?>
                                <b>برومبت كامل (بالمتغيرات)</b> — كل متغير بيتملى بالبيانات الحقيقية وقت التشغيل.
                                <?= $activeType === 'content' ? 'شكل الرد بتاعه (التاجات) هو اللي الكود بيقراه — خليه زي ما هو.' : 'قسم «## شكل المخرجات» بيتشال تلقائيًا والكود بيحط الشكل اللي بيقراه — تقدر تسيبه أو تشيله.' ?>
                                <?php if (str_starts_with($activeType, 'agent_') && !in_array($activeType, ['agent_brand', 'agent_personas', 'agent_content_insights'], true)): ?>
                                    <br>ده Agent بيتحط فوق برومبت المهمة: بياخد منه أقسام «إنت Agent…» و«مهمتك» و«القواعد» و«ذاكرة البراند» بس (الباقي موجود في برومبت المهمة).
                                <?php endif; ?>
                                <?php preg_match_all('/\{([a-z_]+)\}/', $curText, $__vm); $__used = array_values(array_unique(array_intersect($__vm[1], array_keys(prompt_vars_help())))); ?>
                                <?php if ($__used): ?><div style="margin-top:6px;display:flex;flex-wrap:wrap;gap:4px">
                                    <?php foreach ($__used as $__v): ?><span class="chip" title="<?= e(prompt_vars_help()[$__v]) ?>" dir="ltr">{<?= e($__v) ?>}</span><?php endforeach; ?></div><?php endif; ?>
                                <details style="margin-top:6px"><summary style="cursor:pointer">كل المتغيرات المتاحة</summary>
                                    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:4px 12px;margin-top:6px">
                                    <?php foreach (prompt_vars_help() as $__k => $__d): ?><div><code dir="ltr">{<?= e($__k) ?>}</code> <?= e($__d) ?></div><?php endforeach; ?></div></details>
                            <?php elseif (isset(prompt_pack()[$activeType])): ?>
                                <div style="margin-bottom:6px">فيه نسخة كاملة من مكتبة v1 للنوع ده.
                                    <button type="submit" form="pack-one" class="btn ghost sm">⬇ استيراد نسخة v1 للنوع ده</button></div>
                            <?php endif; ?>
                            <?php $__lib = prompt_library()[$activeType] ?? null; if ($isFull): ?>
                            <?php elseif ($__lib): ?>
                                المتغيرات المتاحة: <b dir="ltr"><?= e($__lib[2]) ?></b> — {data} مكان البيانات (الهوية · الفكرة · المحتوى) ولو شلته بتتحط بعد التعليمات.
                                شكل الرد اللي الكود بيقراه بيتضاف تلقائيًا في الآخر، فمش محتاج تكتبه.
                                <?php if (trim((string) ($current['prompt_text'] ?? '')) !== trim($__lib[3])): ?><br>النص الأصلي محفوظ — لو مسحت البرومبت بيرجع له تلقائيًا.<?php endif; ?>
                            <?php else: ?>
                            استخدم {brand_name}، {industry}، {audience}، {tone}، {content_type}، {platform}، {length}، {extra_notes} كمتغيرات. خروج النص لازم يحتوي على [CONTENT][/CONTENT] [HASHTAGS][/HASHTAGS] [CTA][/CTA].
                            <?php endif; ?>
                        </div>
                    </div>

                    <button class="btn">حفظ كإصدار جديد + تفعيل</button>
                </form>
                <form method="POST" id="pack-one" data-safe-post style="display:none"><?= csrf_field() ?><input type="hidden" name="action" value="pack_import">
                    <input type="hidden" name="scope" value="one"><input type="hidden" name="prompt_type" value="<?= e($activeType) ?>"><input type="hidden" name="back" value="<?= e($activeType) ?>"></form>

                <!-- معاينة بالبيانات الحقيقية -->
                <div style="margin-top:18px;padding-top:14px;border-top:1px solid var(--line)">
                    <h4 style="margin:0 0 6px">👁 معاينة البرومبت بالبيانات الحقيقية لعميل</h4>
                    <form method="GET" style="display:flex;gap:8px;flex-wrap:wrap">
                        <input type="hidden" name="type" value="<?= e($activeType) ?>">
                        <input type="text" name="pv" class="input" placeholder="إيميل العميل أو رقمه" value="<?= e((string) ($_GET['pv'] ?? '')) ?>" style="max-width:260px">
                        <button class="btn ghost sm">معاينة</button>
                    </form>
                    <?php if (!empty($_GET['pv']) && !$pvUser): ?><p class="sub" style="color:#c0392b">العميل ده مش موجود</p><?php endif; ?>
                    <?php if ($pvUser): ?>
                        <p class="sub" style="margin:8px 0">العميل: <b><?= e($pvUser['name'] ?: $pvUser['email']) ?></b> — <?= number_format(mb_strlen($pvText)) ?> حرف (~<?= number_format((int) ceil(mb_strlen($pvText) / 2.6)) ?> توكن).
                            <?= $isFull ? '' : 'البرومبت ده تعليمات قديمة: الكود بيحط البيانات وشكل الرد بعده.' ?></p>
                        <pre style="white-space:pre-wrap;direction:rtl;max-height:520px;overflow:auto;background:var(--surface-2);border:1px solid var(--line);border-radius:12px;padding:12px;font-size:12px;line-height:1.8"><?= e($pvText) ?></pre>
                        <?php $__ml = prompt_learnings((int) $pvUser['id']); $__fb = prompt_feedback_text((int) $pvUser['id']); ?>
                        <details><summary style="cursor:pointer;font-size:12.5px">🧠 ذاكرة البراند للعميل ده (<?= count($__ml) ?> درس)</summary>
                            <pre style="white-space:pre-wrap;direction:rtl;font-size:12px"><?= e(prompt_learnings_text((int) $pvUser['id']) ?: 'لسه مفيش دروس') ?>

تعليقات العميل الأخيرة:
<?= e($__fb ?: 'مفيش') ?></pre></details>
                    <?php endif; ?>
                </div>
                <?php if ($activeType === 'campaign_eval'): ?>
                <form method="POST" data-safe-post style="margin-top:18px;padding-top:14px;border-top:1px solid var(--line)">
                    <?= csrf_field() ?><input type="hidden" name="action" value="eval_criteria">
                    <h4 style="margin:0 0 6px">معايير التقييم ودرجة النجاح</h4>
                    <div class="field"><label>المعايير (سطر لكل معيار — بالترتيب)</label>
                        <textarea name="cats" class="textarea" rows="7"><?= e(implode("\n", eval_criteria())) ?></textarea></div>
                    <div class="field"><label>درجة النجاح (البوست بيبقى «جاهز» من الدرجة دي)</label>
                        <input type="number" name="ready" class="input" min="50" max="95" value="<?= eval_ready_score() ?>" style="max-width:120px"></div>
                    <button class="btn ghost">حفظ المعايير</button>
                </form>
                <?php endif; ?>
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

<?php
/**
 * Spread AI v2 — الأدمن: برومبت لكل نوع + تسعير الأفكار بالشرائح
 */
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/credits.php';
require_once __DIR__ . '/../includes/prompt-builder.php';

require_admin();
require_admin_can('ai_settings');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    decode_b64_fields();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_prompt') {
        $id = (int) $_POST['tp_id'];
        db_run('UPDATE type_prompts SET prompt_text = ?, is_active = ? WHERE id = ?', [
            mb_substr(trim($_POST['prompt_text'] ?? ''), 0, 5000) ?: null,
            !empty($_POST['is_active']) ? 1 : 0,
            $id,
        ]);
        admin_log('save_type_prompt', 'type_prompt', $id);
        flash_set('success', 'تم حفظ البرومبت ✓');
        redirect('admin/type-prompts.php?cat=' . urlencode($_POST['cat'] ?? 'content'));
    }

    if ($action === 'add_type') {
        $cat = in_array($_POST['category'] ?? '', ['content', 'design', 'plan'], true) ? $_POST['category'] : 'content';
        $key = preg_replace('/[^a-z0-9_]/', '', mb_strtolower(trim($_POST['type_key'] ?? '')));
        $label = mb_substr(trim($_POST['label_ar'] ?? ''), 0, 120);
        if ($key !== '' && $label !== '') {
            try {
                db_insert('INSERT INTO type_prompts (category, type_key, label_ar, prompt_text, sort_order) VALUES (?, ?, ?, ?, ?)',
                    [$cat, $key, $label, mb_substr(trim($_POST['prompt_text'] ?? ''), 0, 5000) ?: null, (int) ($_POST['sort_order'] ?? 99)]);
                flash_set('success', 'تم إضافة النوع ✓');
            } catch (\Throwable $e) {
                flash_set('danger', 'المفتاح ده موجود بالفعل في نفس الفئة');
            }
        }
        redirect('admin/type-prompts.php?cat=' . $cat);
    }

    if ($action === 'delete_type') {
        db_run('DELETE FROM type_prompts WHERE id = ?', [(int) $_POST['tp_id']]);
        flash_set('success', 'تم الحذف');
        redirect('admin/type-prompts.php?cat=' . urlencode($_POST['cat'] ?? 'content'));
    }

    if ($action === 'save_tiers') {
        $mins = $_POST['min_ideas'] ?? [];
        $maxs = $_POST['max_ideas'] ?? [];
        $creds = $_POST['credits'] ?? [];
        $ids = $_POST['tier_id'] ?? [];
        foreach ($ids as $i => $tid) {
            $tid = (int) $tid;
            $mn = max(1, (int) ($mins[$i] ?? 1));
            $mx = max($mn, (int) ($maxs[$i] ?? $mn));
            $cr = max(0, (int) ($creds[$i] ?? 0));
            if ($tid > 0) {
                db_run('UPDATE idea_pricing_tiers SET min_ideas = ?, max_ideas = ?, credits = ? WHERE id = ?', [$mn, $mx, $cr, $tid]);
            }
        }
        // شريحة جديدة
        if (!empty($_POST['new_max'])) {
            $mn = max(1, (int) $_POST['new_min']);
            $mx = max($mn, (int) $_POST['new_max']);
            db_insert('INSERT INTO idea_pricing_tiers (min_ideas, max_ideas, credits, label_ar) VALUES (?, ?, ?, ?)',
                [$mn, $mx, max(0, (int) $_POST['new_credits']), "من {$mn} إلى {$mx} فكرة"]);
        }
        admin_log('save_idea_tiers', 'settings');
        flash_set('success', 'تم حفظ شرائح تسعير الأفكار ✓');
        redirect('admin/type-prompts.php?cat=plan');
    }

    if ($action === 'delete_tier') {
        db_run('DELETE FROM idea_pricing_tiers WHERE id = ?', [(int) $_POST['tier_id']]);
        redirect('admin/type-prompts.php?cat=plan');
    }
}

$cat = in_array($_GET['cat'] ?? '', ['content', 'design', 'plan'], true) ? $_GET['cat'] : 'content';
$items = type_prompts_list($cat);
$tiers = idea_pricing_tiers();

$catLabels = [
    'content' => ['✎ أنواع المحتوى', 'برومبت مخصص لكل نوع منشور — بيتضاف على البرومبت الأساسي'],
    'design'  => ['🎨 أنواع التصميم', 'برومبت مخصص لكل نوع تصميم (إنجليزي مفضّل)'],
    'plan'    => ['🗓 الخطة والأفكار', 'برومبت الخطة + تسعير الأفكار حسب العدد'],
];

$active = 'type-prompts';
$page_title = 'برومبت الأنواع';
include __DIR__ . '/../templates/admin-header.php';
?>

<div class="app">
    <?php include __DIR__ . '/../templates/admin-sidebar.php'; ?>
    <main class="main">
        <div class="page-head">
            <h1>برومبت مخصص لكل نوع ✎</h1>
            <div class="sub"><?= e($catLabels[$cat][1]) ?></div>
        </div>

        <?= render_flash() ?>

        <div class="seg" style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:18px">
            <?php foreach ($catLabels as $k => $v): ?>
                <a href="?cat=<?= $k ?>" style="text-decoration:none">
                    <button type="button" class="<?= $cat === $k ? 'on' : '' ?>"><?= e($v[0]) ?></button>
                </a>
            <?php endforeach; ?>
        </div>

        <div class="card" style="margin-bottom:18px;background:var(--primary-soft)">
            💡 <b>إزاي بيشتغل:</b> البرومبت هنا <b>بيتضاف</b> على البرومبت الأساسي — مش بيستبدله.
            سيب أي نوع فاضي = يشتغل بالبرومبت العام عادي.
        </div>

        <?php if ($cat === 'plan'): ?>
            <div class="card" style="margin-bottom:18px">
                <div class="card-head"><h3>◇ تكلفة الأفكار حسب العدد</h3></div>
                <p class="sub" style="margin-bottom:12px">كل ما العميل يطلب أفكار أكتر، التكلفة تختلف. النظام بيختار الشريحة اللي العدد بيقع فيها.</p>
                <form method="POST">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save_tiers">
                    <div class="table-wrap">
                    <table class="table">
                        <thead><tr><th>من</th><th>إلى</th><th>الكريدت</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($tiers as $t): ?>
                            <tr>
                                <td><input type="hidden" name="tier_id[]" value="<?= $t['id'] ?>"><input type="number" name="min_ideas[]" class="input" value="<?= (int) $t['min_ideas'] ?>" min="1" style="width:85px;padding:6px"></td>
                                <td><input type="number" name="max_ideas[]" class="input" value="<?= (int) $t['max_ideas'] ?>" min="1" style="width:85px;padding:6px"></td>
                                <td><input type="number" name="credits[]" class="input" value="<?= (int) $t['credits'] ?>" min="0" style="width:85px;padding:6px"> ◇</td>
                                <td>
                                    <button type="submit" form="del-tier-<?= $t['id'] ?>" class="btn ghost sm" style="color:#c0392b">🗑</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                            <tr style="background:var(--surface-2)">
                                <td><input type="number" name="new_min" class="input" placeholder="31" min="1" style="width:85px;padding:6px"></td>
                                <td><input type="number" name="new_max" class="input" placeholder="50" min="1" style="width:85px;padding:6px"></td>
                                <td><input type="number" name="new_credits" class="input" placeholder="8" min="0" style="width:85px;padding:6px"> ◇</td>
                                <td class="sub" style="font-size:11px">شريحة جديدة</td>
                            </tr>
                        </tbody>
                    </table>
                    </div>
                    <button class="btn" style="margin-top:10px">💾 حفظ الشرائح</button>
                </form>
                <?php foreach ($tiers as $t): ?>
                    <form id="del-tier-<?= $t['id'] ?>" method="POST" style="display:none" onsubmit="return confirm('حذف الشريحة؟')">
                        <?= csrf_field() ?><input type="hidden" name="action" value="delete_tier"><input type="hidden" name="tier_id" value="<?= $t['id'] ?>">
                    </form>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div style="display:grid;gap:16px">
            <?php foreach ($items as $it): ?>
                <div class="card" style="<?= $it['is_active'] ? '' : 'opacity:.6' ?>">
                    <form method="POST" data-safe-post>
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="save_prompt">
                        <input type="hidden" name="tp_id" value="<?= $it['id'] ?>">
                        <input type="hidden" name="cat" value="<?= e($cat) ?>">

                        <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:10px">
                            <div>
                                <b style="font-size:15px"><?= e($it['label_ar']) ?></b>
                                <span class="chip" style="font-size:10px" dir="ltr"><?= e($it['type_key']) ?></span>
                                <?php if (trim((string) $it['prompt_text']) === ''): ?>
                                    <span class="chip" style="font-size:10px">فاضي — بيستخدم العام</span>
                                <?php else: ?>
                                    <span class="chip chip-primary" style="font-size:10px">مخصص ✓</span>
                                <?php endif; ?>
                            </div>
                            <label style="display:flex;align-items:center;gap:6px;font-size:12px;cursor:pointer">
                                <input type="checkbox" name="is_active" <?= $it['is_active'] ? 'checked' : '' ?>> مفعّل
                            </label>
                        </div>

                        <textarea name="prompt_text" class="textarea" rows="4"
                                  placeholder="<?= $cat === 'design' ? 'e.g. Bold promotional style: large discount number, urgency badge, vivid contrast…' : 'مثال: ركّز على المشكلة اللي بيواجهها العميل وقدّم الحل في 3 نقط، وابدأ بسؤال يشد الانتباه.' ?>"><?= e($it['prompt_text'] ?? '') ?></textarea>

                        <div style="display:flex;gap:8px;margin-top:10px">
                            <button class="btn sm">💾 حفظ</button>
                            <button type="submit" form="del-tp-<?= $it['id'] ?>" class="btn ghost sm" style="color:#c0392b">🗑 حذف النوع</button>
                        </div>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>

        <?php foreach ($items as $it): ?>
            <form id="del-tp-<?= $it['id'] ?>" method="POST" style="display:none" onsubmit="return confirm('حذف النوع ده نهائيًا؟')">
                <?= csrf_field() ?><input type="hidden" name="action" value="delete_type">
                <input type="hidden" name="tp_id" value="<?= $it['id'] ?>"><input type="hidden" name="cat" value="<?= e($cat) ?>">
            </form>
        <?php endforeach; ?>

        <div class="card" style="margin-top:18px">
            <div class="card-head"><h3>＋ إضافة نوع جديد</h3></div>
            <form method="POST" data-safe-post style="display:grid;grid-template-columns:1fr 1fr auto;gap:10px;align-items:end">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="add_type">
                <input type="hidden" name="category" value="<?= e($cat) ?>">
                <div class="field"><label>الاسم بالعربي</label><input type="text" name="label_ar" class="input" required placeholder="محتوى موسمي"></div>
                <div class="field"><label>المفتاح (إنجليزي بدون مسافات)</label><input type="text" name="type_key" class="input" dir="ltr" required placeholder="seasonal" data-no-encode="1"></div>
                <button class="btn">＋ إضافة</button>
                <div class="field" style="grid-column:1/-1">
                    <label>البرومبت (اختياري دلوقتي)</label>
                    <textarea name="prompt_text" class="textarea" rows="2"></textarea>
                </div>
            </form>
        </div>

    </main>
</div>

<?php include __DIR__ . '/../templates/admin-footer.php'; ?>

<?php
/**
 * الأدمن — مكتبة التعلّم (Brand Kit)
 * طرق كتابة · قوالب Hooks · قواعد · بنك مواضيع · مراجع ← الـ AI بيطلع منها مواضيع وزوايا
 * بتدخل في بريف كل حملة وكل بوست (طريقة الكتابة بتتختار بالتناوب · القواعد دايمًا · المواضيع اقتراحات للعملاء)
 */
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/brand-kit.php';

require_admin();
require_admin_can('ai_settings');
bk_ensure_schema();
$kinds = bk_learning_kinds();
$agentsAll = ['ideas' => 'الأفكار', 'copy' => 'الكتابة', 'design' => 'التصميم', 'video' => 'الفيديو', 'schedule' => 'الجدولة'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    decode_b64_fields();
    $back = 'admin/brand-learning.php' . (!empty($_POST['back']) ? '?' . preg_replace('/[^a-z0-9_=&]/i', '', (string) $_POST['back']) : '');

    if (!empty($_POST['save'])) {
        $id = (int) ($_POST['id'] ?? 0);
        $kind = array_key_exists($_POST['kind'] ?? '', $kinds) ? $_POST['kind'] : 'reference';
        $title = mb_substr(trim((string) ($_POST['title'] ?? '')), 0, 200);
        $body = mb_substr(trim((string) ($_POST['body'] ?? '')), 0, 60000);
        $url = mb_substr(trim((string) ($_POST['source_url'] ?? '')), 0, 500);
        // «اقرأ اللينك»: لو فيه لينك ومفيش نص — بنقرا الصفحة (بأمان)
        if ($url !== '' && ($body === '' || !empty($_POST['fetch']))) {
            require_once __DIR__ . '/../includes/brand-brain.php';
            $u = preg_match('#^https?://#i', $url) ? $url : 'https://' . $url;
            $v = safe_http_validate($u);
            if ($v['ok']) {
                $r = safe_http_get($u, 1500000, 15);
                if ($r['ok'] && $r['body'] !== '') {
                    $pg = brand_parse_html($r['body'], $r['final_url']);
                    $txt = trim(implode("\n", array_filter([$pg['title'], $pg['description'], implode("\n", $pg['headings']), $pg['text']])));
                    if ($txt !== '') { $body = mb_substr(($body !== '' ? $body . "\n\n" : '') . $txt, 0, 60000); $title = $title ?: mb_substr($pg['title'], 0, 200); }
                } else {
                    flash_set('warning', 'مقدرناش نقرا اللينك — الصق النص بنفسك');
                }
            } else {
                flash_set('warning', 'اللينك مش مقبول: ' . $v['error']);
            }
        }
        if ($title === '') { flash_set('danger', 'اكتب عنوان'); redirect($back); }
        $agents = implode(',', array_values(array_intersect((array) ($_POST['agents'] ?? []), array_keys($agentsAll))));
        $f = [$kind, $title, mb_substr(trim((string) ($_POST['summary'] ?? '')), 0, 500) ?: null, $body ?: null, $url ?: null,
              (int) ($_POST['sector_template_id'] ?? 0) ?: null, $agents ?: null, !empty($_POST['is_active']) ? 1 : 0];
        if ($id) {
            db_run('UPDATE brand_learning SET kind=?, title=?, summary=?, body=?, source_url=?, sector_template_id=?, agents=?, is_active=? WHERE id=?', array_merge($f, [$id]));
            admin_log('update_learning', 'brand_learning', $id, $title);
        } else {
            $id = db_insert('INSERT INTO brand_learning (kind, title, summary, body, source_url, sector_template_id, agents, is_active, created_by) VALUES (?,?,?,?,?,?,?,?,?)',
                array_merge($f, [(int) (current_admin()['id'] ?? 0) ?: null]));
            admin_log('create_learning', 'brand_learning', $id, $title);
        }
        // استخراج مواضيع فورًا لو اتطلب
        if (!empty($_POST['extract_now'])) {
            $x = bk_learning_extract((int) $id);
            flash_set($x['ok'] ? 'success' : 'warning', $x['ok'] ? "اتحفظ ✓ — وطلع منه {$x['added']} موضوع" : 'اتحفظ ✓ — بس الاستخراج: ' . $x['error']);
        } else {
            flash_set('success', 'اتحفظ ✓');
        }
        redirect('admin/brand-learning.php?view=' . $id);
    }
    if (!empty($_POST['extract'])) {
        $x = bk_learning_extract((int) $_POST['extract']);
        flash_set($x['ok'] ? 'success' : 'warning', $x['ok'] ? "طلع {$x['added']} موضوع جديد ✓" : $x['error']);
        admin_log('extract_learning', 'brand_learning', (int) $_POST['extract'], (string) $x['added']);
        redirect('admin/brand-learning.php?view=' . (int) $_POST['extract']);
    }
    if (!empty($_POST['delete'])) {
        db_run('DELETE FROM learning_topics WHERE learning_id = ?', [(int) $_POST['delete']]);
        db_run('DELETE FROM brand_learning WHERE id = ?', [(int) $_POST['delete']]);
        admin_log('delete_learning', 'brand_learning', (int) $_POST['delete'], '');
        flash_set('success', 'اتحذف');
        redirect('admin/brand-learning.php');
    }
    if (!empty($_POST['topic_add'])) {
        $lid = (int) ($_POST['learning_id'] ?? 0) ?: null;
        $sec = (int) ($_POST['sector_template_id'] ?? 0) ?: null;
        if ($lid) $sec = db_one('SELECT sector_template_id FROM brand_learning WHERE id = ?', [$lid])['sector_template_id'] ?? $sec;
        $n = 0;
        foreach (preg_split('/\R/u', (string) ($_POST['titles'] ?? '')) as $t) {
            $t = mb_substr(trim($t), 0, 255);
            if ($t === '') continue;
            db_insert('INSERT INTO learning_topics (learning_id, sector_template_id, title, angle, pillar) VALUES (?,?,?,?,?)',
                [$lid, $sec, $t, mb_substr(trim((string) ($_POST['angle'] ?? '')), 0, 100) ?: null, mb_substr(trim((string) ($_POST['pillar'] ?? '')), 0, 100) ?: null]);
            $n++;
        }
        flash_set('success', "اتضاف {$n} موضوع");
        redirect($back);
    }
    if (!empty($_POST['topic_toggle'])) {
        db_run('UPDATE learning_topics SET is_active = 1 - is_active WHERE id = ?', [(int) $_POST['topic_toggle']]);
        redirect($back);
    }
    if (!empty($_POST['topic_delete'])) {
        db_run('DELETE FROM learning_topics WHERE id = ?', [(int) $_POST['topic_delete']]);
        redirect($back);
    }
}

$sectors = bk_sectors(false);
$secName = array_column($sectors, 'name', 'id');
$fKind = array_key_exists($_GET['kind'] ?? '', $kinds) ? $_GET['kind'] : '';
$fSec = (int) ($_GET['sector'] ?? 0);
$where = [];
$p = [];
if ($fKind) { $where[] = 'l.kind = ?'; $p[] = $fKind; }
if ($fSec) { $where[] = 'l.sector_template_id = ?'; $p[] = $fSec; }
$items = db_all('SELECT l.*, (SELECT COUNT(*) FROM learning_topics t WHERE t.learning_id = l.id) topics FROM brand_learning l'
    . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY l.kind, l.id DESC', $p);
$editing = !empty($_GET['edit']) ? db_one('SELECT * FROM brand_learning WHERE id = ?', [(int) $_GET['edit']]) : null;
$view = !empty($_GET['view']) ? db_one('SELECT * FROM brand_learning WHERE id = ?', [(int) $_GET['view']]) : null;
$viewTopics = $view ? db_all('SELECT * FROM learning_topics WHERE learning_id = ? ORDER BY id DESC', [$view['id']]) : [];
$freeTopics = db_all('SELECT t.*, s.name sector FROM learning_topics t LEFT JOIN sector_templates s ON s.id = t.sector_template_id WHERE t.learning_id IS NULL ORDER BY t.id DESC LIMIT 100');
$counts = ['items' => count($items), 'topics' => (int) (db_one('SELECT COUNT(*) n FROM learning_topics WHERE is_active = 1')['n'] ?? 0),
           'used' => (int) (db_one('SELECT COALESCE(SUM(times_used),0) n FROM learning_topics')['n'] ?? 0)];
$newKind = array_key_exists($_GET['new'] ?? '', $kinds) ? $_GET['new'] : 'method';
$fv = $editing ?: ['kind' => $newKind, 'title' => '', 'summary' => '', 'body' => '', 'source_url' => '', 'sector_template_id' => null, 'agents' => $newKind === 'rules' ? 'ideas,copy' : 'copy', 'is_active' => 1, 'id' => 0];

$active = 'brand-learning';
$page_title = 'مكتبة التعلّم';
include __DIR__ . '/../templates/admin-header.php';
?>
<div class="app">
    <?php include __DIR__ . '/../templates/admin-sidebar.php'; ?>
    <main class="main">
        <?php if (is_file(__DIR__ . '/../templates/admin-topbar.php')) include __DIR__ . '/../templates/admin-topbar.php'; ?>
        <div class="page-head">
            <h1>مكتبة التعلّم 📚</h1>
            <div class="sub">سجّل طرق الكتابة والـ Hooks والقواعد والمراجع اللي الـ AI يتعلم منها — وطلّع منها مواضيع بتدخل في بريف كل حملة وبوست.</div>
        </div>
        <?= render_flash() ?>

        <div class="kpis" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:16px">
            <div class="card compact"><div class="text-mute" style="font-size:12px">عناصر في المكتبة</div><b style="font-size:22px"><?= $counts['items'] ?></b></div>
            <div class="card compact"><div class="text-mute" style="font-size:12px">مواضيع مفعّلة</div><b style="font-size:22px"><?= $counts['topics'] ?></b></div>
            <div class="card compact"><div class="text-mute" style="font-size:12px">مرات استخدام المواضيع</div><b style="font-size:22px"><?= $counts['used'] ?></b></div>
        </div>

        <div class="card" style="margin-bottom:16px">
            <div class="card-head"><h3>إزاي بتأثر على المحتوى</h3></div>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:10px;font-size:13px">
                <?php foreach ($kinds as $k => [$l, $d]): ?>
                    <a href="?new=<?= $k ?>#form" class="card compact" style="text-decoration:none;color:inherit"><b><?= e($l) ?></b><div class="text-mute" style="margin-top:4px"><?= e($d) ?></div><span class="chip chip-mint" style="margin-top:8px">+ إضافة</span></a>
                <?php endforeach; ?>
            </div>
        </div>

        <div style="display:grid;grid-template-columns:minmax(0,1fr) 420px;gap:20px;align-items:start" id="bl-grid">
            <div>
                <?php if ($view): ?>
                <div class="card" style="margin-bottom:16px">
                    <div class="card-head"><h3><?= e($kinds[$view['kind']][0]) ?>: <?= e($view['title']) ?></h3>
                        <div style="display:flex;gap:6px"><a class="btn ghost sm" href="?edit=<?= (int) $view['id'] ?>#form">تعديل</a><a class="btn ghost sm" href="?">إغلاق</a></div></div>
                    <?php if ($view['summary']): ?><p class="text-mute" style="margin:0 0 8px"><?= e($view['summary']) ?></p><?php endif; ?>
                    <div style="white-space:pre-wrap;font-size:13px;line-height:1.8;max-height:260px;overflow:auto;background:var(--surface-2,#F7FAFD);border-radius:12px;padding:12px"><?= e(mb_substr((string) $view['body'], 0, 6000)) ?></div>
                    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:12px">
                        <form method="POST"><?= csrf_field() ?><button class="btn sm" name="extract" value="<?= (int) $view['id'] ?>">✨ طلّع مواضيع وزوايا بالـ AI</button></form>
                        <span class="text-mute" style="font-size:12px"><?= $view['extracted_at'] ? 'آخر استخراج: ' . e($view['extracted_at']) : 'لسه ما اتطلعش منه مواضيع' ?></span>
                    </div>
                    <h4 style="margin:16px 0 8px">المواضيع (<?= count($viewTopics) ?>)</h4>
                    <?php if ($viewTopics): ?>
                    <div class="table-wrap"><table class="table"><thead><tr><th>الموضوع</th><th>الزاوية</th><th>الاتجاه</th><th>استُخدم</th><th></th></tr></thead><tbody>
                    <?php foreach ($viewTopics as $t): ?>
                        <tr style="<?= $t['is_active'] ? '' : 'opacity:.5' ?>"><td><?= e($t['title']) ?></td><td><?= e((string) $t['angle']) ?></td><td><?= e((string) $t['pillar']) ?></td><td><?= (int) $t['times_used'] ?></td>
                            <td style="white-space:nowrap"><form method="POST" style="display:inline"><?= csrf_field() ?><input type="hidden" name="back" value="view=<?= (int) $view['id'] ?>">
                                <button class="btn ghost sm" name="topic_toggle" value="<?= (int) $t['id'] ?>"><?= $t['is_active'] ? 'إيقاف' : 'تفعيل' ?></button>
                                <button class="btn ghost sm danger" name="topic_delete" value="<?= (int) $t['id'] ?>">حذف</button></form></td></tr>
                    <?php endforeach; ?>
                    </tbody></table></div>
                    <?php endif; ?>
                    <form method="POST" data-safe-post style="margin-top:10px"><?= csrf_field() ?><input type="hidden" name="learning_id" value="<?= (int) $view['id'] ?>"><input type="hidden" name="back" value="view=<?= (int) $view['id'] ?>">
                        <textarea name="titles" class="textarea" rows="2" placeholder="مواضيع يدوي — سطر لكل موضوع"></textarea>
                        <button class="btn ghost sm mt-10" name="topic_add" value="1">+ إضافة مواضيع</button></form>
                </div>
                <?php endif; ?>

                <div class="card">
                    <div class="card-head"><h3>المكتبة</h3>
                        <form method="GET" style="display:flex;gap:6px">
                            <select name="kind" class="select" onchange="this.form.submit()"><option value="">كل الأنواع</option><?php foreach ($kinds as $k => [$l]): ?><option value="<?= $k ?>" <?= $fKind === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
                            <select name="sector" class="select" onchange="this.form.submit()"><option value="0">كل القطاعات</option><?php foreach ($sectors as $s): ?><option value="<?= (int) $s['id'] ?>" <?= $fSec === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?></select>
                        </form>
                    </div>
                    <div class="table-wrap"><table class="table">
                        <thead><tr><th>النوع</th><th>العنوان</th><th>القطاع</th><th>الوكلاء</th><th>مواضيع</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($items as $it): ?>
                            <tr style="<?= $it['is_active'] ? '' : 'opacity:.55' ?>">
                                <td><span class="chip"><?= e($kinds[$it['kind']][0]) ?></span></td>
                                <td><a href="?view=<?= (int) $it['id'] ?>"><b><?= e($it['title']) ?></b></a><div class="text-mute" style="font-size:12px"><?= e((string) $it['summary']) ?></div></td>
                                <td style="font-size:12.5px"><?= $it['sector_template_id'] ? e($secName[$it['sector_template_id']] ?? '—') : 'كل القطاعات' ?></td>
                                <td style="font-size:12px"><?= e(implode('، ', array_map(fn($a) => $agentsAll[$a] ?? $a, array_filter(explode(',', (string) $it['agents']))))) ?: 'الكل' ?></td>
                                <td><?= (int) $it['topics'] ?></td>
                                <td style="white-space:nowrap"><a class="btn ghost sm" href="?edit=<?= (int) $it['id'] ?>#form">تعديل</a>
                                    <form method="POST" style="display:inline" onsubmit="return confirm('حذف العنصر ومواضيعه؟')"><?= csrf_field() ?><button class="btn ghost sm danger" name="delete" value="<?= (int) $it['id'] ?>">حذف</button></form></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$items): ?><tr><td colspan="6" class="text-mute">مفيش عناصر لسه</td></tr><?php endif; ?>
                        </tbody>
                    </table></div>
                </div>

                <div class="card" style="margin-top:16px">
                    <div class="card-head"><h3>بنك مواضيع حر (لقطاع أو للكل)</h3></div>
                    <form method="POST" data-safe-post style="display:grid;gap:8px"><?= csrf_field() ?>
                        <textarea name="titles" class="textarea" rows="3" placeholder="سطر لكل موضوع"></textarea>
                        <div style="display:flex;gap:8px;flex-wrap:wrap">
                            <select name="sector_template_id" class="select"><option value="0">كل القطاعات</option><?php foreach ($sectors as $s): ?><option value="<?= (int) $s['id'] ?>"><?= e($s['name']) ?></option><?php endforeach; ?></select>
                            <input name="angle" class="input" placeholder="الزاوية (اختياري)" style="max-width:160px"><input name="pillar" class="input" placeholder="الاتجاه (اختياري)" style="max-width:160px">
                            <button class="btn sm" name="topic_add" value="1">+ إضافة</button>
                        </div>
                    </form>
                    <?php if ($freeTopics): ?>
                    <div style="display:flex;flex-wrap:wrap;gap:6px;margin-top:12px">
                        <?php foreach ($freeTopics as $t): ?>
                            <form method="POST" style="display:inline"><?= csrf_field() ?><span class="chip <?= $t['is_active'] ? 'chip-sky' : '' ?>"><?= e($t['title']) ?><?= $t['sector'] ? ' · ' . e($t['sector']) : '' ?>
                                <button class="btn ghost sm" style="padding:0 4px;min-height:0" name="topic_delete" value="<?= (int) $t['id'] ?>" aria-label="حذف">×</button></span></form>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card" id="form">
                <div class="card-head"><h3><?= $editing ? 'تعديل' : 'إضافة للمكتبة' ?></h3></div>
                <form method="POST" data-safe-post>
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int) $fv['id'] ?>">
                    <div class="field"><label>النوع</label>
                        <select name="kind" class="select"><?php foreach ($kinds as $k => [$l, $d]): ?><option value="<?= $k ?>" <?= $fv['kind'] === $k ? 'selected' : '' ?>><?= e($l) ?> — <?= e($d) ?></option><?php endforeach; ?></select></div>
                    <div class="field"><label>العنوان</label><input name="title" class="input" value="<?= e($fv['title']) ?>" placeholder="مثلًا: AIDA · قصة العميل · قواعد المحتوى الطبي"></div>
                    <div class="field"><label>ملخص في سطر</label><input name="summary" class="input" value="<?= e((string) $fv['summary']) ?>"></div>
                    <div class="field"><label>المحتوى (الطريقة / القواعد / الـ Hooks / نص المرجع)</label>
                        <textarea name="body" class="textarea" rows="10"><?= e((string) $fv['body']) ?></textarea>
                        <div class="field-help">ده اللي الـ AI بيقراه حرفيًا: الطريقة بتتحط في بريف البوست · القواعد في كل برومبت للوكلاء المختارين.</div></div>
                    <div class="field"><label>لينك مرجع (اختياري)</label><input name="source_url" class="input" dir="ltr" value="<?= e((string) $fv['source_url']) ?>" placeholder="https://…">
                        <label style="display:flex;gap:6px;align-items:center;margin-top:6px;font-weight:400"><input type="checkbox" name="fetch" value="1"> اقرأ نص اللينك وضيفه للمحتوى</label></div>
                    <div class="field"><label>القطاع</label>
                        <select name="sector_template_id" class="select"><option value="0">كل القطاعات</option><?php foreach ($sectors as $s): ?><option value="<?= (int) $s['id'] ?>" <?= (int) $fv['sector_template_id'] === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?></select></div>
                    <div class="field"><label>الوكلاء اللي بيستخدموه</label>
                        <div style="display:flex;gap:12px;flex-wrap:wrap">
                        <?php $ag = array_filter(explode(',', (string) $fv['agents'])); foreach ($agentsAll as $k => $l): ?>
                            <label style="display:flex;gap:4px;align-items:center;font-weight:400"><input type="checkbox" name="agents[]" value="<?= $k ?>" <?= in_array($k, $ag, true) ? 'checked' : '' ?>> <?= e($l) ?></label>
                        <?php endforeach; ?></div>
                        <div class="field-help">فاضي = كل الوكلاء.</div></div>
                    <label style="display:flex;gap:6px;align-items:center;margin:8px 0"><input type="checkbox" name="is_active" value="1" <?= $fv['is_active'] ? 'checked' : '' ?>> مفعّل</label>
                    <label style="display:flex;gap:6px;align-items:center;margin:8px 0"><input type="checkbox" name="extract_now" value="1" <?= $editing ? '' : 'checked' ?>> طلّع مواضيع منه بالـ AI بعد الحفظ</label>
                    <button class="btn full" name="save" value="1">حفظ</button>
                    <?php if ($editing): ?><a class="btn ghost full mt-10" href="?">إلغاء</a><?php endif; ?>
                </form>
            </div>
        </div>
    </main>
</div>
<style>@media (max-width:1100px){#bl-grid{grid-template-columns:1fr !important}}</style>
<?php include __DIR__ . '/../templates/admin-footer.php'; ?>

<?php
/**
 * Spread AI — الأدمن: Skills التخصصات (المرحلة H2)
 * رفع ملف MD لكل تخصص ← نسخة (مسودة) ← حالات اختبار ← اختبار رجوع ← تفعيل · الرجوع لنسخة قديمة أو للعام بضغطة
 * الـ Skill الشغالة بتدخل كل مراحل المحرك للبراندات اللي تخصصها ده (كل مرحلة بالأقسام اللي تخصها بس).
 */
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/admin-ui.php';
require_once __DIR__ . '/../includes/ce-lab.php';

require_admin();
require_admin_can('ai_settings');
$ready = ce_skills_ready() && ce_prompts_ready();
$admin = current_admin();
$aid = (int) ($admin['id'] ?? 0);
$sid = (int) ($_GET['id'] ?? 0);
$back = fn(int $id = 0, string $extra = '') => redirect('admin/ce-skills.php' . ($id ? '?id=' . $id . $extra : ''));

/* ─── تحميل ملف (قالب أو نسخة) ─── */
if ($ready && isset($_GET['download'])) {
    if ($_GET['download'] === 'template') {
        $name = 'skill-template.md';
        $body = ce_skill_template();
    } else {
        $v = db_one('SELECT v.*, s.skey FROM ce_skill_versions v JOIN ce_skills s ON s.id = v.skill_id WHERE v.id = ?', [(int) $_GET['download']]);
        if (!$v) { http_response_code(404); exit('مش موجود'); }
        $name = $v['skey'] . '-v' . (int) $v['version'] . '.md';
        $body = (string) $v['body_md'];
    }
    header('Content-Type: text/markdown; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('X-Content-Type-Options: nosniff');
    echo $body;
    exit;
}

if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    decode_b64_fields();
    $act = (string) ($_POST['act'] ?? '');
    $sidP = (int) ($_POST['skill_id'] ?? 0);

    if ($act === 'upload') {
        // النص من الصندوق (الواجهة بتقرا الملف فيه) — أو الملف نفسه لو الـ JS مش شغال
        $md = (string) ($_POST['md'] ?? '');
        if (trim($md) === '' && !empty($_FILES['file']['tmp_name']) && (int) ($_FILES['file']['error'] ?? 1) === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo((string) $_FILES['file']['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['md', 'markdown', 'txt'], true)) { flash_set('danger', 'ارفع ملف .md'); $back($sidP); }
            if ((int) $_FILES['file']['size'] > CE_SKILL_MAX_BYTES) { flash_set('danger', 'الملف كبير'); $back($sidP); }
            $md = (string) file_get_contents($_FILES['file']['tmp_name']);
        }
        if (trim($md) === '') { flash_set('danger', 'اختار ملف MD أو الصق النص'); $back($sidP); }
        $r = ce_skill_save_md($md, $sidP ?: null, (string) ($_POST['notes'] ?? ''), $sidP && !empty($_POST['from_editor']) ? 'edit' : 'upload', $aid);
        if (!$r['ok']) { flash_set('danger', $r['error']); $back((int) ($r['skill_id'] ?? $sidP)); }
        admin_log('ce_skill_upload', 'ce_skill', $r['skill_id'], 'v' . $r['version']);
        flash_set('success', 'اتحفظت نسخة v' . $r['version'] . ' (مسودة) ✓' . ($r['warnings'] ? ' — ملاحظات: ' . implode(' · ', $r['warnings']) : ''));
        $back((int) $r['skill_id'], '&v=' . $r['id']);
    }
    if ($act === 'samples') {
        $added = ce_skill_import_samples($aid);
        admin_log('ce_skill_samples', 'ce_skill', null, implode('، ', $added));
        flash_set($added ? 'success' : 'info', $added ? 'اتضافت (مسودات): ' . implode('، ', $added) : 'كل الأمثلة موجودة قبل كده');
        $back();
    }
    if ($act === 'meta' && $sidP) {
        $name = mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 100);
        if ($name === '') { flash_set('danger', 'اكتب الاسم'); $back($sidP); }
        db_run('UPDATE ce_skills SET name = ?, match_words = ?, is_enabled = ? WHERE id = ?', [$name, mb_substr(trim((string) ($_POST['match'] ?? '')), 0, 500) ?: null, !empty($_POST['is_enabled']) ? 1 : 0, $sidP]);
        admin_log('ce_skill_meta', 'ce_skill', $sidP, $name);
        flash_set('success', 'اتحفظ ✓');
        $back($sidP);
    }
    if ($act === 'activate' && $sidP) {
        $vid = (int) ($_POST['version_id'] ?? 0);
        $r = ce_skill_activate($sidP, $vid, $aid, !empty($_POST['ack']));
        if (!$r['ok']) { flash_set(!empty($r['code']) ? 'warning' : 'danger', $r['error']); $back($sidP, $vid ? '&v=' . $vid . (($r['code'] ?? '') === 'warn' ? '&confirm=1' : '') : ''); }
        admin_log($vid === 0 ? 'ce_skill_off' : (!empty($r['rollback']) ? 'ce_skill_rollback' : 'ce_skill_activate'), 'ce_skill', $sidP, 'version ' . $vid);
        flash_set('success', $vid === 0 ? 'الـ Skill وقفت — البراندات دي بترجع للبرومبت العام' : 'النسخة بقت شغالة ✓');
        $back($sidP, $vid ? '&v=' . $vid : '');
    }
    if (in_array($act, ['case_toggle', 'case_delete'], true) && $sidP) {
        $cid = (int) ($_POST['case_id'] ?? 0);
        if ($act === 'case_delete') db_run('DELETE FROM ce_golden_cases WHERE id = ? AND skill_id = ?', [$cid, $sidP]);
        else db_run('UPDATE ce_golden_cases SET is_active = 1 - is_active WHERE id = ? AND skill_id = ?', [$cid, $sidP]);
        admin_log($act === 'case_delete' ? 'ce_case_delete' : 'ce_case_toggle', 'ce_golden_case', $cid);
        $back($sidP);
    }
    $back($sidP);
}

$page_title = 'Skills التخصصات';
$active = 'ce-skills';
include __DIR__ . '/../templates/admin-header.php';
$skill = $ready && $sid ? db_one('SELECT * FROM ce_skills WHERE id = ?', [$sid]) : null;
$runLabel = ['running' => 'شغال', 'passed' => 'عدّى', 'warn' => 'تحذير', 'failed' => 'فشل', 'error' => 'خطأ'];
$runChip = ['passed' => 'chip-mint', 'warn' => 'chip-amber', 'failed' => 'chip-coral', 'error' => 'chip-coral', 'running' => 'chip-sky'];
?>
<div class="app">
    <?php include __DIR__ . '/../templates/admin-sidebar.php'; ?>
    <main class="main ce-adm">
        <div class="topbar"><button class="menu-toggle" onclick="toggleSidebar()">☰</button></div>
        <?= render_flash() ?>
        <div class="page-head">
            <h1>Skills التخصصات 🧠</h1>
            <div class="sub">ملف MD لكل تخصص بخبرة المجال (معرفة · قواعد · ممنوعات · زوايا · أمثلة · تصميم). البراند اللي تخصصه ده بياخدها في كل مراحل المحرك — كل مرحلة بالأقسام اللي تخصها بس.</div>
        </div>
<?php if (!$ready): ?>
        <div class="alert warning">شغّل ترحيلات «2026_ce_lab» و«2026_ce_skills» من <a href="<?= e(url('admin/migrations.php')) ?>">الترحيلات</a> الأول.</div>
<?php elseif (!$skill): /* ═══════════ القايمة ═══════════ */
    $skills = db_all('SELECT s.*, (SELECT version FROM ce_skill_versions v WHERE v.skill_id = s.id AND v.is_active = 1 LIMIT 1) active_v,
                             (SELECT COUNT(*) FROM ce_skill_versions v WHERE v.skill_id = s.id) n_vers,
                             (SELECT COUNT(*) FROM ce_golden_cases g WHERE g.skill_id = s.id AND g.is_active = 1) n_cases
                      FROM ce_skills s ORDER BY s.name');
    $samples = array_filter(ce_skill_samples(), fn($x) => $x['key'] !== '' && !in_array($x['key'], array_column($skills, 'skey'), true));
?>
        <div class="ce-fgrid" style="grid-template-columns:minmax(0,2fr) minmax(0,1fr)">
            <section class="card">
                <div class="a2-h"><h3>ارفع Skill (ملف MD)</h3><span class="a2-grow"></span>
                    <a class="btn sm ghost" href="<?= e(url('admin/ce-skills.php?download=template')) ?>">⬇ القالب</a></div>
                <form method="post" enctype="multipart/form-data" data-safe-post class="ce-skill-up">
                    <?= csrf_field() ?><input type="hidden" name="act" value="upload">
                    <label class="ce-f"><span>الملف (.md)</span><input type="file" name="file" accept=".md,.markdown,.txt,text/markdown,text/plain" class="form-control" data-md-target="md-new"></label>
                    <label class="ce-f"><span>أو الصق النص</span><textarea id="md-new" name="md" class="form-control" rows="8" dir="auto" spellcheck="false" placeholder="---&#10;key: dental&#10;name: عيادات الأسنان&#10;match: أسنان, تقويم, زراعة&#10;---&#10;## المعرفة&#10;…"></textarea></label>
                    <label class="ce-f"><span>ملاحظات النسخة</span><input class="form-control" name="notes" maxlength="500" placeholder="اتغيّر إيه"></label>
                    <p class="a2-muted">لو المفتاح (key) موجود، الملف بيتحفظ كنسخة جديدة للـ Skill دي. النسخة الجديدة مسودة لحد ما تعدّي اختبار الرجوع وتفعّلها.</p>
                    <button class="btn">ارفع واحفظ كنسخة</button>
                </form>
            </section>
            <section class="card">
                <div class="a2-h"><h3>الأقسام اللي بنقراها</h3></div>
                <ul class="ce-skill-map">
                    <?php foreach (CE_SKILL_SECTIONS as $k => [$label, $aliases]): if ($k === 'extra') continue;
                        $st = array_keys(array_filter(CE_SKILL_STAGE_MAP, fn($l) => in_array($k, $l, true))); ?>
                        <li><b>## <?= e($aliases[0]) ?></b> <small class="a2-muted">← <?= e(implode(' · ', array_map(fn($s) => CE_PROMPT_STAGES[$s], $st))) ?><?= in_array($k, CE_SKILL_LIST_SECTIONS, true) ? ' · بنود (- …)' : '' ?></small></li>
                    <?php endforeach; ?>
                    <li><b>أي قسم تاني</b> <small class="a2-muted">← الأفكار · الكتابة</small></li>
                </ul>
                <?php if ($samples): ?>
                <form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="samples">
                    <button class="btn ghost">استورد الأمثلة (<?= count($samples) ?>): <?= e(implode('، ', array_column($samples, 'name'))) ?></button></form>
                <?php endif; ?>
            </section>
        </div>

        <section class="card">
            <div class="a2-h"><h3>الـ Skills</h3></div>
            <div class="a2-tw"><table class="a2-tbl"><thead><tr><th>التخصص</th><th>المفتاح</th><th>كلمات التعرّف</th><th>الشغالة</th><th>النسخ</th><th>الحالات</th><th></th></tr></thead><tbody>
            <?php foreach ($skills as $s): ?>
                <tr><td><b><?= e($s['name']) ?></b><?= (int) $s['is_enabled'] ? '' : ' <span class="chip chip-line">موقوفة</span>' ?></td>
                    <td dir="ltr"><code><?= e($s['skey']) ?></code></td>
                    <td class="a2-muted"><?= e(mb_strimwidth((string) $s['match_words'], 0, 70, '…')) ?></td>
                    <td><?= $s['active_v'] ? '<span class="chip chip-mint">v' . (int) $s['active_v'] . '</span>' : '<span class="chip chip-amber">مفيش</span>' ?></td>
                    <td class="num"><?= (int) $s['n_vers'] ?></td><td class="num"><?= (int) $s['n_cases'] ?></td>
                    <td><a class="btn sm ghost" href="<?= e(url('admin/ce-skills.php?id=' . (int) $s['id'])) ?>">افتح</a></td></tr>
            <?php endforeach; ?>
            <?php if (!$skills): ?><tr><td colspan="7" class="a2-muted">مفيش Skills لسه — ارفع ملف أو استورد الأمثلة.</td></tr><?php endif; ?>
            </tbody></table></div>
        </section>
<?php else: /* ═══════════ Skill واحدة ═══════════ */
    $versions = db_all('SELECT v.*, a.name author FROM ce_skill_versions v LEFT JOIN admin_users a ON a.id = v.created_by WHERE v.skill_id = ? ORDER BY v.version DESC', [$sid]);
    $cur = null;
    foreach ($versions as $v) if ((int) $v['id'] === (int) ($_GET['v'] ?? 0)) $cur = $v;
    if (!$cur) foreach ($versions as $v) if ((int) $v['is_active']) $cur = $v;
    $cur = $cur ?: ($versions[0] ?? null);
    $parsed = $cur ? ce_skill_parse((string) $cur['body_md']) : null;
    $curSkill = $cur ? ce_skill_version_load((int) $cur['id']) : null;
    $cases = ce_lab_skill_cases($sid);
    $runs = db_all('SELECT * FROM ce_regression_runs WHERE kind = "skill" AND skill_id = ? ORDER BY id DESC LIMIT 15', [$sid]);
    $lastRun = [];
    foreach ($runs as $r) if (!isset($lastRun[(int) $r['candidate_skill_version_id']])) $lastRun[(int) $r['candidate_skill_version_id']] = $r;
    $activeV = null;
    foreach ($versions as $v) if ((int) $v['is_active']) $activeV = $v;
    $brands = 0;
    foreach (db_all('SELECT dna_json FROM ce_brand_dna LIMIT 2000') as $r) {
        $sk = ce_skill_for_dna(json_decode((string) $r['dna_json'], true) ?: []);
        if ($sk && $sk['id'] === $sid) $brands++;
    }
?>
        <p><a href="<?= e(url('admin/ce-skills.php')) ?>">→ كل الـ Skills</a></p>
        <div id="skl" data-api="<?= e(url('admin/ce-lab-api.php')) ?>" data-csrf="<?= e(csrf_token()) ?>" data-skill="<?= $sid ?>"></div>
        <div class="ce-kpis">
            <div class="ce-f"><span>الشغالة</span><b><?= $activeV ? 'v' . (int) $activeV['version'] : 'مفيش (البرومبت العام)' ?></b></div>
            <div class="ce-f"><span>براندات بتستخدمها</span><b><?= $brands ?></b></div>
            <div class="ce-f"><span>حالات الاختبار</span><b><?= count(array_filter($cases, fn($c) => (int) $c['is_active'])) ?></b></div>
            <div class="ce-f"><span>النسخ</span><b><?= count($versions) ?></b></div>
        </div>

        <div class="ce-fgrid" style="grid-template-columns:minmax(0,1fr) minmax(0,1fr)">
            <section class="card">
                <div class="a2-h"><h3><?= e($skill['name']) ?> <code dir="ltr"><?= e($skill['skey']) ?></code></h3></div>
                <form method="post" data-safe-post>
                    <?= csrf_field() ?><input type="hidden" name="act" value="meta"><input type="hidden" name="skill_id" value="<?= $sid ?>">
                    <label class="ce-f"><span>الاسم</span><input class="form-control" name="name" maxlength="100" value="<?= e($skill['name']) ?>"></label>
                    <label class="ce-f"><span>كلمات التعرّف (بفاصلة) — بنقارنها بـ «مجال» البراند</span><input class="form-control" name="match" maxlength="500" value="<?= e((string) $skill['match_words']) ?>"></label>
                    <label class="ce-check"><input type="hidden" name="is_enabled" value="0"><input type="checkbox" name="is_enabled" value="1" <?= (int) $skill['is_enabled'] ? 'checked' : '' ?>> <span>متاحة للعملاء</span></label>
                    <button class="btn sm">احفظ</button>
                </form>
            </section>
            <section class="card">
                <div class="a2-h"><h3>حالات الاختبار</h3><span class="a2-grow"></span>
                    <button class="btn sm" type="button" data-skl-seed>+ حالات للـ Skill (أفكار · كتابة · ناقد)</button></div>
                <p class="a2-muted">اختبار الرجوع بيشغّل كل حالة مرتين: بالنسخة الشغالة (أو من غير Skill) وبالجديدة. وتقدر تحفظ أي تجربة من الـ <a href="<?= e(url('admin/ce-lab.php')) ?>">Playground</a> كحالة للـ Skill دي.</p>
                <div class="a2-tw"><table class="a2-tbl"><thead><tr><th>المرحلة</th><th>الحالة</th><th>أقل درجة</th><th></th></tr></thead><tbody>
                <?php foreach ($cases as $c): ?>
                    <tr<?= (int) $c['is_active'] ? '' : ' class="a2-muted"' ?>><td><?= e(CE_PROMPT_STAGES[$c['stage']] ?? $c['stage']) ?></td><td><?= e($c['name']) ?></td><td class="num"><?= (float) $c['min_score'] ?></td>
                        <td class="ce-row-btns"><form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="case_toggle"><input type="hidden" name="skill_id" value="<?= $sid ?>"><input type="hidden" name="case_id" value="<?= (int) $c['id'] ?>">
                            <button class="btn sm ghost"><?= (int) $c['is_active'] ? 'وقّف' : 'شغّل' ?></button></form>
                            <form method="post" onsubmit="return confirm('تمسح الحالة دي؟')"><?= csrf_field() ?><input type="hidden" name="act" value="case_delete"><input type="hidden" name="skill_id" value="<?= $sid ?>"><input type="hidden" name="case_id" value="<?= (int) $c['id'] ?>">
                            <button class="btn sm ghost">امسح</button></form></td></tr>
                <?php endforeach; ?>
                <?php if (!$cases): ?><tr><td colspan="4" class="a2-muted">مفيش حالات — اعمل حالات قبل أول اختبار.</td></tr><?php endif; ?>
                </tbody></table></div>
            </section>
        </div>

        <section class="card">
            <div class="a2-h"><h3>النسخ</h3><span class="a2-grow"></span>
                <?php if ($activeV): ?><form method="post" onsubmit="return confirm('توقف الـ Skill؟ البراندات دي هترجع للبرومبت العام.')"><?= csrf_field() ?><input type="hidden" name="act" value="activate"><input type="hidden" name="skill_id" value="<?= $sid ?>"><input type="hidden" name="version_id" value="0">
                    <button class="btn sm ghost">وقّف الـ Skill (رجوع للعام)</button></form><?php endif; ?></div>
            <div class="a2-tw"><table class="a2-tbl"><thead><tr><th>النسخة</th><th>المصدر</th><th>ملاحظات</th><th>بواسطة</th><th>التاريخ</th><th>اختبار الرجوع</th><th></th></tr></thead><tbody>
            <?php foreach ($versions as $v): $lr = $lastRun[(int) $v['id']] ?? null; ?>
                <tr<?= $cur && (int) $cur['id'] === (int) $v['id'] ? ' class="on"' : '' ?>>
                    <td><b>v<?= (int) $v['version'] ?></b> <?= (int) $v['is_active'] ? '<span class="chip chip-mint">شغالة</span>' : ($v['activated_at'] ? '<span class="chip chip-line">اتفعّلت قبل كده</span>' : '<span class="chip chip-line">مسودة</span>') ?></td>
                    <td><?= e(['upload' => 'رفع ملف', 'edit' => 'تعديل', 'sample' => 'مثال'][$v['source']] ?? $v['source']) ?></td>
                    <td><?= e((string) $v['notes']) ?></td><td><?= e((string) ($v['author'] ?? '—')) ?></td><td class="num"><?= e(substr((string) $v['created_at'], 0, 16)) ?></td>
                    <td><?= $lr ? '<span class="chip ' . ($runChip[$lr['status']] ?? '') . '">' . e($runLabel[$lr['status']] ?? $lr['status']) . '</span>' . ($lr['delta'] !== null ? ' <small class="num">' . e((string) $lr['delta']) . '</small>' : '') : '—' ?></td>
                    <td class="ce-row-btns">
                        <a class="btn sm ghost" href="<?= e(url('admin/ce-skills.php?id=' . $sid . '&v=' . (int) $v['id'])) ?>">اعرض</a>
                        <a class="btn sm ghost" href="<?= e(url('admin/ce-skills.php?download=' . (int) $v['id'])) ?>">⬇ MD</a>
                        <?php if (!(int) $v['is_active']): ?>
                            <?php if (!$v['activated_at']): ?><button class="btn sm ghost" type="button" data-skl-run="<?= (int) $v['id'] ?>">اختبار رجوع</button><?php endif; ?>
                            <form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="activate"><input type="hidden" name="skill_id" value="<?= $sid ?>"><input type="hidden" name="version_id" value="<?= (int) $v['id'] ?>">
                                <?php if (!empty($_GET['confirm']) && $cur && (int) $cur['id'] === (int) $v['id']): ?><label class="ce-check"><input type="checkbox" name="ack" value="1" required> <span>متأكد رغم التحذير</span></label><?php endif; ?>
                                <button class="btn sm"><?= $v['activated_at'] ? 'ارجع لها' : 'فعّل' ?></button></form>
                        <?php endif; ?>
                        <a class="btn sm ghost" href="<?= e(url('admin/ce-lab.php?skill_version=' . (int) $v['id'])) ?>">جرّبها في الـ Playground</a>
                    </td></tr>
            <?php endforeach; ?>
            </tbody></table></div>
            <div id="skl-run" class="ce-skill-run" hidden></div>
        </section>

        <?php if ($cur && $parsed): ?>
        <div class="ce-fgrid" style="grid-template-columns:minmax(0,1fr) minmax(0,1fr)">
            <section class="card">
                <div class="a2-h"><h3>v<?= (int) $cur['version'] ?> — الأقسام</h3></div>
                <?php if ($parsed['warnings']): ?><div class="alert warning"><?= e(implode(' · ', $parsed['warnings'])) ?></div><?php endif; ?>
                <div class="a2-tw"><table class="a2-tbl"><thead><tr><th>القسم</th><th>الحجم</th><th>بنود</th><th>بيروح لـ</th></tr></thead><tbody>
                <?php foreach ($parsed['sections'] as $k => $s): $st = array_keys(array_filter(CE_SKILL_STAGE_MAP, fn($l) => in_array($k, $l, true))); ?>
                    <tr><td><b><?= e(CE_SKILL_SECTIONS[$k][0]) ?></b><?= $s['title'] !== CE_SKILL_SECTIONS[$k][0] ? ' <small class="a2-muted">(' . e($s['title']) . ')</small>' : '' ?></td>
                        <td class="num"><?= mb_strlen($s['text']) ?> حرف</td><td class="num"><?= in_array($k, CE_SKILL_LIST_SECTIONS, true) ? count($s['items']) : '—' ?></td>
                        <td class="a2-muted"><?= e(implode(' · ', array_map(fn($x) => CE_PROMPT_STAGES[$x], $st))) ?></td></tr>
                <?php endforeach; ?>
                </tbody></table></div>
                <h4>اللي بيتضاف لكل مرحلة</h4>
                <?php foreach (array_keys(CE_PROMPT_STAGES) as $stg): $blk = ce_skill_block($stg, $curSkill); if ($blk === '') continue; ?>
                    <details class="ce-skill-blk"><summary><?= e(CE_PROMPT_STAGES[$stg]) ?> <small class="a2-muted">~<?= (int) ceil(mb_strlen($blk) / 3.2) ?> توكن</small></summary><pre dir="auto"><?= e(trim($blk)) ?></pre></details>
                <?php endforeach; ?>
            </section>
            <section class="card">
                <div class="a2-h"><h3>عدّل وحفظ كنسخة جديدة</h3></div>
                <form method="post" data-safe-post>
                    <?= csrf_field() ?><input type="hidden" name="act" value="upload"><input type="hidden" name="skill_id" value="<?= $sid ?>"><input type="hidden" name="from_editor" value="1">
                    <textarea id="md-ed" name="md" class="form-control ce-skill-ed" rows="24" dir="auto" spellcheck="false"><?= e((string) $cur['body_md']) ?></textarea>
                    <label class="ce-f"><span>ملاحظات النسخة</span><input class="form-control" name="notes" maxlength="500"></label>
                    <div class="ce-row-btns"><button class="btn">احفظ كنسخة جديدة</button>
                        <label class="btn ghost">ارفع ملف بدلها<input type="file" accept=".md,.markdown,.txt" hidden data-md-target="md-ed"></label></div>
                </form>
            </section>
        </div>
        <?php endif; ?>
<?php endif; ?>
    </main>
</div>
<script>
(function () {
  // الملف ← الصندوق (عشان النص يتبعت مشفّر ومايتوقفش من جدار الحماية)
  document.querySelectorAll('input[type=file][data-md-target]').forEach(function (inp) {
    inp.addEventListener('change', function () {
      var f = inp.files && inp.files[0]; if (!f) return;
      if (f.size > <?= CE_SKILL_MAX_BYTES ?>) { alert('الملف كبير'); inp.value = ''; return; }
      var r = new FileReader();
      r.onload = function () {
        var ta = document.getElementById(inp.dataset.mdTarget) || inp.closest('form').querySelector('textarea[name=md]');
        ta.value = String(r.result || ''); inp.value = '';
      };
      r.readAsText(f, 'utf-8');
    });
  });
  var box = document.getElementById('skl'); if (!box) return;
  var API = box.dataset.api, CSRF = box.dataset.csrf, SKILL = +box.dataset.skill;
  function b64(o) { return btoa(unescape(encodeURIComponent(JSON.stringify(o)))); }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  async function post(a, data) {
    var fd = new FormData(); fd.append('csrf', CSRF); fd.append('a', a); fd.append('p', b64(data || {}));
    try { var r = await fetch(API, { method: 'POST', body: fd, credentials: 'same-origin' }); return await r.json(); }
    catch (e) { return { ok: false, error: 'مشكلة في الاتصال' }; }
  }
  var out = document.getElementById('skl-run');
  var label = { running: 'شغال', passed: 'عدّى', warn: 'تحذير', failed: 'فشل', error: 'خطأ' };
  function show(run) {
    out.hidden = false;
    var rows = (run.items || []).map(function (x) {
      if (x.skipped) return '<tr><td colspan="5" class="a2-muted">حالة اتشالت</td></tr>';
      return '<tr><td>' + esc(x.name) + '</td><td class="num">' + esc(x.base.score) + '</td><td class="num">' + esc(x.new.score) + '</td><td class="num">' + esc(x.min) + '</td><td class="a2-muted">' + esc((x.new.issues || []).join(' · ') || x.new.error || '') + '</td></tr>';
    }).join('');
    out.innerHTML = '<div class="a2-h"><h3>اختبار رجوع #' + run.id + '</h3><span class="a2-grow"></span><span class="chip">' + esc(label[run.status] || run.status) + ' · ' + run.done + '/' + run.total + '</span></div>'
      + (run.status !== 'running' ? '<p>المتوسط: ' + esc(run.avg_base) + ' ← ' + esc(run.avg_new) + ' (' + esc(run.delta) + ')' + (run.note ? ' — ' + esc(run.note) : '') + ' · التكلفة $' + esc(run.cost_usd) + '</p>' : '')
      + '<div class="a2-tw"><table class="a2-tbl"><thead><tr><th>الحالة</th><th>الشغالة</th><th>الجديدة</th><th>أقل درجة</th><th>ملاحظات</th></tr></thead><tbody>' + rows + '</tbody></table></div>';
  }
  document.querySelectorAll('[data-skl-run]').forEach(function (b) {
    b.addEventListener('click', async function () {
      b.disabled = true;
      var r = await post('skill_regression_start', { skill_id: SKILL, version_id: +b.dataset.sklRun });
      if (!r.ok) { alert(r.error); b.disabled = false; return; }
      var run = { id: r.id, status: 'running', done: 0, total: r.total, items: [] };
      show(run);
      while (run.status === 'running') {
        var s = await post('regression_step', { id: r.id });
        if (!s.ok) { alert(s.error); break; }
        run = s.run; show(run);
      }
      setTimeout(function () { location.reload(); }, 1500);
    });
  });
  var seed = document.querySelector('[data-skl-seed]');
  if (seed) seed.addEventListener('click', async function () {
    seed.disabled = true; seed.textContent = 'بنعمل الحالات…';
    var r = await post('skill_seed', { skill_id: SKILL });
    if (!r.ok) { alert(r.error); seed.disabled = false; return; }
    location.reload();
  });
})();
</script>
<?php include __DIR__ . '/../templates/admin-footer.php'; ?>

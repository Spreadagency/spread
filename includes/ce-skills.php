<?php
/**
 * Spread AI — محرك المحتوى v3 · Skills التخصصات (المرحلة H2)
 *
 * الـ Skill = ملف Markdown لتخصص واحد (عيادات أسنان · مطاعم …) بيرفعه الأدمن:
 *   ---                         ← بيانات الملف (اختياري لو بترفع نسخة لـ Skill موجودة)
 *   key: dental                 مفتاح بالإنجليزي
 *   name: عيادات الأسنان
 *   match: أسنان, dental, تقويم  كلمات بنعرف بيها التخصص من «مجال» البراند
 *   ---
 *   ## المعرفة  ## القواعد  ## الممنوعات  ## الزوايا  ## أمثلة Hooks  ## أمثلة بوستات
 *   ## الناقد  ## التصميم  ## ممنوع في الصورة  ## أسئلة البريف
 * كل قسم بيروح للمراحل اللي محتاجاه بس (CE_SKILL_STAGE_MAP) — بيتضاف في آخر برومبت النظام بتاع المرحلة.
 * «الممنوعات» و«ممنوع في الصورة» كمان بيتشيّكوا في الكود (مخالفات الناقد + الـ negative بتاع الصورة).
 * نسخة الـ Skill مابتتفعّلش غير بعد اختبار رجوع (ce-lab.php) — زي البرومبتات بالظبط.
 */

/** الأقسام: المفتاح ← [العنوان في الواجهة, عناوين بنقبلها في الملف] */
const CE_SKILL_SECTIONS = [
    'knowledge' => ['المعرفة', ['المعرفة', 'معرفة المجال', 'عن المجال', 'knowledge']],
    'rules' => ['القواعد', ['القواعد', 'قواعد المجال', 'rules']],
    'forbidden' => ['الممنوعات', ['الممنوعات', 'كلمات ممنوعة', 'وعود ممنوعة', 'ممنوع', 'forbidden']],
    'angles' => ['الزوايا والأعمدة', ['الزوايا', 'الأعمدة', 'زوايا المحتوى', 'angles', 'pillars']],
    'hooks' => ['أمثلة Hooks', ['أمثلة hooks', 'أمثلة الـ hooks', 'hooks', 'أمثلة البدايات']],
    'examples' => ['أمثلة بوستات', ['أمثلة بوستات', 'أمثلة محتوى', 'أمثلة', 'examples']],
    'critic' => ['تعليمات الناقد', ['الناقد', 'معايير الناقد', 'تعليمات الناقد', 'المراجعة', 'critic']],
    'visual' => ['التصميم والصور', ['التصميم', 'الصور', 'الشكل', 'visual', 'design']],
    'visual_avoid' => ['ممنوع في الصورة', ['ممنوع في الصورة', 'ممنوع في الصور', 'ممنوع في التصميم', 'visual avoid', 'visual_avoid']],
    'brief_questions' => ['أسئلة البريف', ['أسئلة البريف', 'أسئلة الحملة', 'أسئلة', 'brief questions', 'brief_questions']],
    'extra' => ['أقسام تانية', []],
];

/** كل مرحلة بتاخد أقسام إيه (عشان التوكنز ماتزيدش) */
const CE_SKILL_STAGE_MAP = [
    'ce_brand_dna' => ['knowledge', 'rules'],
    'ce_matrix' => ['angles', 'rules'],
    'ce_plan_critic' => ['angles', 'rules'],
    'ce_final_qa' => ['rules'],
    'ce_ideation' => ['knowledge', 'hooks', 'rules', 'extra'],
    'ce_selection' => ['hooks', 'rules'],
    'ce_writer' => ['knowledge', 'rules', 'forbidden', 'hooks', 'examples', 'extra'],
    'ce_critic' => ['rules', 'forbidden', 'critic'],
    'ce_design_prompt' => ['visual', 'visual_avoid'],
    'ce_vision_qa' => ['visual_avoid'],
];

/** أقسام بنقرا منها قايمة (- بند) للكود */
const CE_SKILL_LIST_SECTIONS = ['forbidden', 'visual_avoid', 'brief_questions'];
const CE_SKILL_MAX_BYTES = 120000;
const CE_SKILL_SECTION_CHARS = 1500;   // أقصى طول للقسم جوه برومبت مرحلة
const CE_SKILL_BLOCK_CHARS = 4000;     // أقصى طول للـ Skill كلها في برومبت مرحلة

function ce_skills_ready(): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        db_one('SELECT id FROM ce_skills LIMIT 1');
        return $ok = true;
    } catch (\Throwable $e) {
        return $ok = false;
    }
}

/* ═══════════════════ قراية ملف الـ MD ═══════════════════ */

function ce_skill_norm(string $s): string
{
    $s = mb_strtolower(trim($s));
    $s = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $s);
    $s = strtr($s, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ى' => 'ي', 'ة' => 'ه']);
    $s = preg_replace('/[^\p{L}\p{N}\s_]+/u', ' ', $s);
    return trim(preg_replace('/\s+/u', ' ', $s));
}

/** عنوان القسم ← مفتاحه (الأطول الأول عشان «ممنوع في الصورة» مايتقريش «ممنوع») */
function ce_skill_heading_key(string $title): ?string
{
    static $aliases = null;
    if ($aliases === null) {
        $aliases = [];
        foreach (CE_SKILL_SECTIONS as $k => [, $list]) foreach ($list as $a) $aliases[] = [ce_skill_norm($a), $k];
        usort($aliases, fn($x, $y) => mb_strlen($y[0]) <=> mb_strlen($x[0]));
    }
    $t = ce_skill_norm($title);
    foreach ($aliases as [$a, $k]) {
        if ($t === $a || str_starts_with($t, $a . ' ')) return $k;
    }
    return null;
}

/**
 * @return array{meta: array, sections: array, warnings: string[], errors: string[]}
 *   sections[key] = ['title' => …, 'text' => …, 'items' => [...]]
 */
function ce_skill_parse(string $md): array
{
    $md = str_replace(["\r\n", "\r"], "\n", $md);
    $md = preg_replace('/^\xEF\xBB\xBF/', '', $md);                       // BOM
    $md = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $md);   // control chars
    $md = preg_replace('/<!--.*?-->/s', '', $md);                       // تعليقات HTML (شرح القالب)
    $out = ['meta' => [], 'sections' => [], 'warnings' => [], 'errors' => []];
    if (strlen($md) > CE_SKILL_MAX_BYTES) {
        $out['errors'][] = 'الملف كبير (أقصى ' . (int) (CE_SKILL_MAX_BYTES / 1000) . ' KB)';
        return $out;
    }
    if (!mb_check_encoding($md, 'UTF-8')) {
        $out['errors'][] = 'الملف لازم يكون UTF-8';
        return $out;
    }
    // بيانات الملف (front matter)
    if (preg_match('/^---\n(.*?)\n---\n?/s', $md, $m)) {
        foreach (explode("\n", $m[1]) as $line) {
            if (!preg_match('/^\s*([A-Za-z_]+)\s*:\s*(.*)$/u', $line, $kv)) continue;
            $out['meta'][strtolower($kv[1])] = trim($kv[2], " \t\"'");
        }
        $md = substr($md, strlen($m[0]));
    }
    // العنوان الرئيسي (# …) لو مفيش name
    if (preg_match('/^#\s+(.+)$/mu', $md, $h) && empty($out['meta']['name'])) $out['meta']['name'] = trim($h[1]);
    $md = preg_replace('/^#\s+.+$/mu', '', $md, 1);

    $parts = preg_split('/^##\s+(.+)$/mu', $md, -1, PREG_SPLIT_DELIM_CAPTURE);
    $intro = trim((string) array_shift($parts));
    $add = function (string $key, string $title, string $text) use (&$out) {
        $text = trim(preg_replace("/\n{3,}/", "\n\n", $text));
        if ($text === '') {
            $out['warnings'][] = 'قسم «' . $title . '» فاضي';
            return;
        }
        if (isset($out['sections'][$key])) {
            $out['sections'][$key]['text'] .= "\n\n" . $text;
        } else {
            $out['sections'][$key] = ['title' => $title, 'text' => $text, 'items' => []];
        }
    };
    if ($intro !== '') $add('extra', 'مقدمة', $intro);
    for ($i = 0; $i + 1 < count($parts); $i += 2) {
        $title = trim($parts[$i], " #\t");
        $key = ce_skill_heading_key($title);
        if ($key === null) {
            $out['warnings'][] = 'قسم «' . $title . '» مش من الأقسام المعروفة — هيروح للأفكار والكتابة كمعلومة عامة';
            $add('extra', $title, '### ' . $title . "\n" . $parts[$i + 1]);
            continue;
        }
        $add($key, $title, $parts[$i + 1]);
    }
    foreach ($out['sections'] as $k => &$s) {
        if (in_array($k, CE_SKILL_LIST_SECTIONS, true)) {
            preg_match_all('/^\s*(?:[-*•]|\d+[.)])\s+(.+)$/mu', $s['text'], $mm);
            $s['items'] = array_values(array_unique(array_filter(array_map(fn($x) => mb_substr(trim($x), 0, 200), $mm[1]))));
            if (!$s['items']) $out['warnings'][] = 'قسم «' . $s['title'] . '» مفيهوش بنود (- بند) — الكود مش هيقدر يتشيّك عليه';
        }
        if (mb_strlen($s['text']) > CE_SKILL_SECTION_CHARS) $out['warnings'][] = 'قسم «' . $s['title'] . '» طويل — هيتقص لـ ' . CE_SKILL_SECTION_CHARS . ' حرف جوه البرومبت';
    }
    unset($s);
    if (!array_diff_key($out['sections'], ['extra' => 1])) $out['errors'][] = 'مفيش ولا قسم معروف (## المعرفة · ## القواعد · ## الممنوعات …) — شوف القالب';
    return $out;
}

/* ═══════════════════ الحفظ والنسخ ═══════════════════ */

function ce_skill_key_clean(string $k): string
{
    return substr(preg_replace('/[^a-z0-9_-]+/', '', strtolower(trim($k))), 0, 40);
}

/** حفظ ملف MD كنسخة جديدة (مسودة). $skillId = null ← من المفتاح اللي في الملف (أو Skill جديدة) */
function ce_skill_save_md(string $md, ?int $skillId, string $notes, string $source, ?int $adminId): array
{
    if (!ce_skills_ready()) return ['ok' => false, 'error' => 'شغّل ترحيل 2026_ce_skills الأول'];
    $p = ce_skill_parse($md);
    if ($p['errors']) return ['ok' => false, 'error' => implode(' · ', $p['errors']), 'warnings' => $p['warnings']];
    $meta = $p['meta'];
    $skill = $skillId ? db_one('SELECT * FROM ce_skills WHERE id = ?', [$skillId]) : null;
    if ($skillId && !$skill) return ['ok' => false, 'error' => 'الـ Skill مش موجودة'];
    $key = ce_skill_key_clean((string) ($meta['key'] ?? ''));
    if ($skill && $key !== '' && $key !== $skill['skey']) return ['ok' => false, 'error' => 'المفتاح في الملف (' . $key . ') غير مفتاح الـ Skill دي (' . $skill['skey'] . ')'];
    if (!$skill && $key !== '') $skill = db_one('SELECT * FROM ce_skills WHERE skey = ?', [$key]);
    $name = mb_substr(trim(strip_tags((string) ($meta['name'] ?? ''))), 0, 100);
    $match = mb_substr(trim(strip_tags((string) ($meta['match'] ?? ''))), 0, 500);
    if (!$skill) {
        if ($key === '' || $name === '') return ['ok' => false, 'error' => 'Skill جديدة: لازم أول الملف يبقى فيه key و name (شوف القالب)'];
        $skillId = (int) db_insert('INSERT INTO ce_skills (skey, name, match_words, created_by) VALUES (?,?,?,?)', [$key, $name, $match ?: null, $adminId]);
        $skill = db_one('SELECT * FROM ce_skills WHERE id = ?', [$skillId]);
    } else {
        $skillId = (int) $skill['id'];
        if ($name !== '' || $match !== '') db_run('UPDATE ce_skills SET name = COALESCE(NULLIF(?, ""), name), match_words = COALESCE(NULLIF(?, ""), match_words) WHERE id = ?', [$name, $match, $skillId]);
    }
    $body = trim(str_replace("\r\n", "\n", $md));
    $last = db_one('SELECT body_md FROM ce_skill_versions WHERE skill_id = ? ORDER BY version DESC LIMIT 1', [$skillId]);
    if ($last && trim((string) $last['body_md']) === $body) return ['ok' => false, 'error' => 'الملف ده هو نفس آخر نسخة', 'skill_id' => $skillId];
    $v = (int) (db_one('SELECT COALESCE(MAX(version), 0) n FROM ce_skill_versions WHERE skill_id = ?', [$skillId])['n'] ?? 0) + 1;
    $id = (int) db_insert('INSERT INTO ce_skill_versions (skill_id, version, body_md, sections_json, notes, source, created_by) VALUES (?,?,?,?,?,?,?)', [
        $skillId, $v, $body, json_encode($p['sections'], JSON_UNESCAPED_UNICODE), mb_substr(trim($notes), 0, 500) ?: null,
        in_array($source, ['upload', 'edit', 'sample'], true) ? $source : 'upload', $adminId,
    ]);
    return ['ok' => true, 'skill_id' => $skillId, 'id' => $id, 'version' => $v, 'warnings' => $p['warnings'], 'new_skill' => !$last];
}

/** تفعيل نسخة (بعد اختبار رجوع عدّى) · 0 = إيقاف الـ Skill (المحرك يرجع للعام) · نسخة اتفعّلت قبل كده ← من غير اختبار */
function ce_skill_activate(int $skillId, int $versionId, ?int $adminId, bool $ackWarn = false): array
{
    if ($versionId === 0) {
        db_run('UPDATE ce_skill_versions SET is_active = 0 WHERE skill_id = ?', [$skillId]);
        ce_skill_cache_bust();
        return ['ok' => true, 'rollback' => true];
    }
    $v = db_one('SELECT * FROM ce_skill_versions WHERE id = ? AND skill_id = ?', [$versionId, $skillId]);
    if (!$v) return ['ok' => false, 'error' => 'النسخة مش موجودة'];
    $rollback = !empty($v['activated_at']);
    if (!$rollback) {
        $run = db_one('SELECT * FROM ce_regression_runs WHERE kind = "skill" AND skill_id = ? AND candidate_skill_version_id = ? ORDER BY id DESC LIMIT 1', [$skillId, $versionId]);
        if (!$run || !in_array($run['status'], ['passed', 'warn'], true)) return ['ok' => false, 'code' => 'regression', 'error' => 'لازم النسخة تعدّي اختبار الرجوع الأول'];
        if ($run['status'] === 'warn' && !$ackWarn) return ['ok' => false, 'code' => 'warn', 'error' => 'الاختبار فيه تحذير — أكّد إنك عايز تفعّلها'];
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        db_run('UPDATE ce_skill_versions SET is_active = 0 WHERE skill_id = ?', [$skillId]);
        db_run('UPDATE ce_skill_versions SET is_active = 1, activated_at = COALESCE(activated_at, NOW()), activated_by = ? WHERE id = ?', [$adminId, $versionId]);
        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        return ['ok' => false, 'error' => 'مقدرناش نفعّلها'];
    }
    ce_skill_cache_bust();
    return ['ok' => true, 'rollback' => $rollback];
}

/* ═══════════════════ الـ Skills الشغالة واختيارها للبراند ═══════════════════ */

function ce_skill_cache_bust(): void
{
    ce_skill_active_list(true);
}

/** شكل الـ Skill اللي المحرك بيستخدمه */
function ce_skill_row_out(array $s, array $v): array
{
    return ['id' => (int) $s['id'], 'skey' => $s['skey'], 'name' => $s['name'], 'match' => (string) $s['match_words'],
            'version_id' => (int) $v['id'], 'version' => (int) $v['version'], 'sections' => json_decode((string) $v['sections_json'], true) ?: []];
}

/** الـ Skills المتاحة للعملاء (شغالة ومفعّلة) — مفتاح ← Skill */
function ce_skill_active_list(bool $fresh = false): array
{
    static $cache = null;
    if ($fresh) $cache = null;
    if ($cache !== null) return $cache;
    if (!ce_skills_ready()) return $cache = [];
    $cache = [];
    $rows = db_all('SELECT s.*, v.id vid, v.version vver, v.sections_json FROM ce_skills s JOIN ce_skill_versions v ON v.skill_id = s.id AND v.is_active = 1 WHERE s.is_enabled = 1 ORDER BY s.name');
    foreach ($rows as $s) $cache[$s['skey']] = ce_skill_row_out($s, ['id' => $s['vid'], 'version' => $s['vver'], 'sections_json' => $s['sections_json']]);
    return $cache;
}

/** نسخة معيّنة (حتى لو مش شغالة — للحملات اللي بدأت بيها وللـ Playground) */
function ce_skill_version_load(int $versionId): ?array
{
    if ($versionId <= 0 || !ce_skills_ready()) return null;
    $v = db_one('SELECT * FROM ce_skill_versions WHERE id = ?', [$versionId]);
    $s = $v ? db_one('SELECT * FROM ce_skills WHERE id = ?', [(int) $v['skill_id']]) : null;
    return $s ? ce_skill_row_out($s, $v) : null;
}

/** التخصص من «مجال» البراند بكلمات التعرّف — الكلمة الأطول اللي اتلاقت بتكسب */
function ce_skill_detect(string $sector): ?array
{
    $t = ' ' . ce_skill_norm($sector) . ' ';
    if (trim($t) === '') return null;
    $best = null;
    $bestLen = 0;
    foreach (ce_skill_active_list() as $s) {
        $words = array_merge([$s['name']], explode(',', $s['match']));
        foreach ($words as $w) {
            $w = ce_skill_norm($w);
            if (mb_strlen($w) < 3) continue;
            if (str_contains($t, $w) && mb_strlen($w) > $bestLen) {
                $best = $s;
                $bestLen = mb_strlen($w);
            }
        }
    }
    return $best;
}

/** الـ Skill بتاعة البراند: اختيار العميل (brand.skill = مفتاح · none = عام · فاضي = تلقائي من المجال) */
function ce_skill_for_dna(array $dna): ?array
{
    $choice = (string) ($dna['brand']['skill'] ?? '');
    if ($choice === 'none') return null;
    $list = ce_skill_active_list();
    if ($choice !== '' && isset($list[$choice])) return $list[$choice];
    return ce_skill_detect((string) ($dna['brand']['sector'] ?? ''));
}

/** الحملة بتفضل على نسخة الـ Skill اللي بدأت بيها (0 = من غير Skill) — null = لسه ماتربطتش */
function ce_skill_for_campaign(array $c, array $dna): ?array
{
    if (array_key_exists('skill_version_id', $c) && $c['skill_version_id'] !== null) {
        return (int) $c['skill_version_id'] > 0 ? ce_skill_version_load((int) $c['skill_version_id']) : null;
    }
    return ce_skill_for_dna($dna);
}

function ce_skill_bind_campaign(int $cid, ?array $skill): void
{
    if (!ce_skills_ready()) return;
    try {
        db_run('UPDATE ce_campaigns SET skill_version_id = ? WHERE id = ?', [$skill ? (int) $skill['version_id'] : 0, $cid]);
    } catch (\Throwable $e) {}
}

/* ═══════════════════ الـ Skill جوه المراحل ═══════════════════ */

/** الجزء اللي بيتضاف في آخر برومبت النظام للمرحلة دي (فاضي لو مفيش أقسام ليها) */
function ce_skill_block(string $stage, ?array $skill): string
{
    if (!$skill || empty(CE_SKILL_STAGE_MAP[$stage])) return '';
    $parts = [];
    foreach (CE_SKILL_STAGE_MAP[$stage] as $k) {
        $s = $skill['sections'][$k] ?? null;
        if (!$s || trim((string) $s['text']) === '') continue;
        $txt = mb_strlen($s['text']) > CE_SKILL_SECTION_CHARS ? mb_substr($s['text'], 0, CE_SKILL_SECTION_CHARS) . '…' : $s['text'];
        $parts[] = '[' . CE_SKILL_SECTIONS[$k][0] . "]\n" . $txt;
    }
    if (!$parts) return '';
    $body = implode("\n\n", $parts);
    if (mb_strlen($body) > CE_SKILL_BLOCK_CHARS) $body = mb_substr($body, 0, CE_SKILL_BLOCK_CHARS) . '…';
    return "\n\nSECTOR SKILL — «" . $skill['name'] . "» (خبرة المجال: التزم بيها. لو اتعارضت مع ملف الهوية أو قيود الحملة، الهوية والقيود بيكسبوا):\n" . $body;
}

/** بنود قسم قايمة (للكود) */
function ce_skill_items(?array $skill, string $key): array
{
    return $skill ? array_values((array) ($skill['sections'][$key]['items'] ?? [])) : [];
}

/** أسئلة البريف (≤ 5) */
function ce_skill_questions(?array $skill): array
{
    return array_slice(array_map(fn($q) => mb_substr($q, 0, 160), ce_skill_items($skill, 'brief_questions')), 0, 5);
}

/* ═══════════════════ الأمثلة والقالب ═══════════════════ */

function ce_skill_samples_dir(): string
{
    return __DIR__ . '/ce-skills';
}

/** ملفات الأمثلة اللي مع المنصة (ماعدا القالب) */
function ce_skill_samples(): array
{
    $out = [];
    foreach (glob(ce_skill_samples_dir() . '/*.md') ?: [] as $f) {
        if (str_starts_with(basename($f), '_')) continue;
        $p = ce_skill_parse((string) file_get_contents($f));
        $out[] = ['file' => basename($f), 'key' => ce_skill_key_clean((string) ($p['meta']['key'] ?? '')), 'name' => (string) ($p['meta']['name'] ?? basename($f))];
    }
    return $out;
}

/** استيراد الأمثلة اللي مفتاحها مش موجود (كمسودات — محتاجة اختبار وتفعيل) */
function ce_skill_import_samples(?int $adminId): array
{
    $added = [];
    foreach (ce_skill_samples() as $s) {
        if ($s['key'] === '' || db_one('SELECT id FROM ce_skills WHERE skey = ?', [$s['key']])) continue;
        $r = ce_skill_save_md((string) file_get_contents(ce_skill_samples_dir() . '/' . $s['file']), null, 'مثال مع المنصة', 'sample', $adminId);
        if ($r['ok']) $added[] = $s['name'];
    }
    return $added;
}

function ce_skill_template(): string
{
    return (string) @file_get_contents(ce_skill_samples_dir() . '/_template.md');
}

<?php
/**
 * Spread AI — محرك المحتوى v3 · Brand DNA (المرحلة D)
 *
 * ملف هوية مختصر (≤ ~1200 توكن) بيتبني مرة من مركز الهوية (Brand Kit) ويتحدّث لما الهوية تتغير.
 * هو بس اللي بيدخل برومبتات المحرك — مش الوثائق الكاملة — وبيتحط في أول البرومبت ثابت (prefix)
 * عشان المزودين اللي بيدعموا الـ caching يحسبوه أرخص.
 *
 * البناء على مرحلتين:
 *   1) بالقواعد: الحقول المنظّمة من الـ Kit (الخدمات · العملاء · الصوت · الألوان · الشخصيات) — من غير AI
 *   2) بالـ AI (ce_brand_dna): يكتب الـ positioning ونقط التميّز والإثباتات والقيود ويكمّل المخاوف والاعتراضات الناقصة
 * لو الـ AI مش متاح، الملف بيتحفظ من القواعد بس — المحرك بيشتغل برضه.
 * الحقول اللي العميل عدّلها بإيده محفوظة في edited_json — إعادة البناء مابتغيّرهاش.
 */
require_once __DIR__ . '/ce-jobs.php';
require_once __DIR__ . '/brand-kit.php';

ce_job_register('brand_dna', 'ce_dna_job', 'بناء الـ Brand DNA');

const CE_DNA_KEYS = ['brand', 'positioning', 'usp', 'proof_assets', 'services', 'personas', 'tone', 'visual', 'cta', 'compliance', 'golden_example_ids', 'winning_patterns'];
const CE_DNA_PRIORITIES = ['high' => 'أولوية عالية', 'medium' => 'متوسطة', 'low' => 'قليلة'];
const CE_DNA_FORMALITY = ['formal' => 'رسمي', 'balanced' => 'متوازن', 'friendly' => 'ودود وقريب'];
const CE_DNA_PLATFORMS = ['facebook' => 'فيسبوك', 'instagram' => 'إنستجرام', 'tiktok' => 'تيك توك', 'whatsapp' => 'واتساب', 'linkedin' => 'لينكدإن'];
const CE_DNA_SEASONS = ['طول السنة', 'الصيف', 'الشتا', 'الربيع', 'الخريف', 'الدراسة', 'الإجازة', 'رمضان', 'العيد', 'الجمعة البيضا'];
const CE_DNA_DIALECTS = ['egyptian', 'gulf', 'levantine', 'msa', 'mixed'];

/* ═══════════════════ القراءة والحفظ ═══════════════════ */

function ce_dna_row(int $uid): ?array
{
    return db_one('SELECT * FROM ce_brand_dna WHERE user_id = ?', [$uid]) ?: null;
}

function ce_dna_get(int $uid): ?array
{
    $r = ce_dna_row($uid);
    return $r ? (json_decode((string) $r['dna_json'], true) ?: null) : null;
}

/** حفظ نسخة جديدة (النسخة اللي قبلها بتتحفظ في prev_json للرجوع) */
function ce_dna_save(int $uid, array $dna, string $by, ?int $jobId = null, ?string $sourceHash = null, ?array $edited = null): array
{
    $dna = ce_dna_trim(ce_dna_clean($uid, $dna));
    $json = json_encode($dna, JSON_UNESCAPED_UNICODE);
    $tokens = ce_tokens_est($json);
    $old = ce_dna_row($uid);
    if ($old) {
        // prev_json الأول: MySQL بينفّذ الـ SET بالترتيب، فلو dna_json اتكتب الأول prev_json هياخد الجديد
        db_run('UPDATE ce_brand_dna SET version = version + 1, prev_json = dna_json, dna_json = ?, tokens_est = ?, built_by = ?, job_id = COALESCE(?, job_id),
                source_hash = COALESCE(?, source_hash), edited_json = COALESCE(?, edited_json), built_at = IF(? <> "user", NOW(), built_at) WHERE user_id = ?',
            [$json, $tokens, $by, $jobId, $sourceHash, $edited !== null ? json_encode(array_values($edited)) : null, $by, $uid]);
    } else {
        db_insert('INSERT INTO ce_brand_dna (user_id, dna_json, tokens_est, built_by, job_id, source_hash, edited_json, built_at) VALUES (?,?,?,?,?,?,?,NOW())',
            [$uid, $json, $tokens, $by, $jobId, $sourceHash, json_encode(array_values($edited ?? []))]);
    }
    return ['dna' => $dna, 'tokens' => $tokens];
}

/** الحقول اللي العميل عدّلها بإيده */
function ce_dna_edited(int $uid): array
{
    $r = ce_dna_row($uid);
    return $r ? (json_decode((string) ($r['edited_json'] ?? ''), true) ?: []) : [];
}

/** تعديل العميل: بنحفظ الحقول اللي اتغيرت بس ونعلّمها «معدّلة يدويًا» */
function ce_dna_user_save(int $uid, array $in): array
{
    $cur = ce_dna_get($uid) ?? ce_dna_from_kit($uid);
    $clean = ce_dna_clean($uid, $in + $cur);
    $edited = ce_dna_edited($uid);
    foreach (CE_DNA_KEYS as $k) {
        if (array_key_exists($k, $in) && json_encode($clean[$k] ?? null, JSON_UNESCAPED_UNICODE) !== json_encode($cur[$k] ?? null, JSON_UNESCAPED_UNICODE)) {
            $edited[] = $k;
        }
    }
    return ce_dna_save($uid, $clean, 'user', null, null, array_values(array_unique($edited)));
}

/** الرجوع للنسخة اللي قبلها */
function ce_dna_undo(int $uid): bool
{
    $r = ce_dna_row($uid);
    if (!$r || empty($r['prev_json'])) return false;
    db_run('UPDATE ce_brand_dna SET dna_json = ?, prev_json = ?, tokens_est = ?, version = version + 1, built_by = "user" WHERE user_id = ?',
        [$r['prev_json'], $r['dna_json'], ce_tokens_est((string) $r['prev_json']), $uid]);
    return true;
}

/** بصمة مصدر الهوية — لو اتغيرت يبقى الـ DNA محتاج يتحدّث */
function ce_dna_source_hash(int $uid): string
{
    $kit = bk_get($uid);
    return sha1(json_encode([
        (int) ($kit['version'] ?? 0),
        array_map(fn($s) => [$s['id'], $s['name'], $s['priority'], $s['seasons'], $s['is_active']], bk_services($uid)),
        array_map(fn($p) => [$p['id'], $p['updated_at'] ?? ''], bk_personas($uid, false)),
        array_map(fn($c) => [$c['id'], $c['is_active'], count($c['images'] ?? [])], bk_characters($uid)),
    ]));
}

/** حالة الـ DNA للواجهة: مش موجود · محدّث · محتاج تحديث */
function ce_dna_state(int $uid): array
{
    $r = ce_dna_row($uid);
    if (!$r) return ['exists' => false, 'stale' => true];
    return [
        'exists' => true, 'version' => (int) $r['version'], 'tokens' => (int) $r['tokens_est'], 'built_by' => $r['built_by'],
        'built_at' => $r['built_at'], 'stale' => $r['source_hash'] !== ce_dna_source_hash($uid), 'can_undo' => !empty($r['prev_json']),
        'edited' => json_decode((string) ($r['edited_json'] ?? ''), true) ?: [],
    ];
}

/* ═══════════════════ البناء بالقواعد (من غير AI) ═══════════════════ */

function ce_dna_from_kit(int $uid): array
{
    $kit = bk_get($uid);
    $d = $kit['data'] ?? [];
    $bp = function_exists('bk_brand_profile') ? (bk_brand_profile($uid) ?? []) : [];
    $str = fn($v, int $n = 200) => mb_substr(trim((string) $v), 0, $n);
    $list = fn($v, int $n = 6, int $len = 80) => array_slice(array_values(array_filter(array_map(fn($x) => mb_substr(trim((string) $x), 0, $len), is_array($v) ? $v : bk_lines((string) $v)), fn($x) => $x !== '')), 0, $n);

    $personas = bk_personas($uid);
    $services = [];
    foreach (bk_services($uid, true) as $s) {
        $obj = [];
        foreach ($personas as $p) {
            if (in_array((int) $s['id'], array_map('intval', (array) ($p['service_ids'] ?? [])), true)) $obj = array_merge($obj, (array) ($p['objections'] ?? []));
        }
        $seasons = array_values(array_filter((array) bk_arr($s['seasons'] ?? [])));
        $services[] = [
            'id' => (int) $s['id'], 'name' => $str($s['name'], 80),
            'priority' => isset(CE_DNA_PRIORITIES[$s['priority'] ?? '']) ? $s['priority'] : 'medium',
            'season' => $seasons ? implode('، ', array_slice($seasons, 0, 3)) : 'طول السنة',
            'objections' => $list(array_unique(array_merge($obj, (array) ($d['audience']['objections'] ?? []))), 3),
        ];
    }

    $platforms = (array) ($d['content']['platforms'] ?? []);
    $per = [];
    foreach ($personas as $p) {
        $ch = (array) ($p['channels'] ?? []);
        $per[] = [
            'id' => (int) $p['id'], 'name' => $str($p['name'], 60),
            'pain' => $list($p['pains'] ?? [], 3), 'fears' => $list($p['objections'] ?? [], 3), 'desires' => $list($p['desires'] ?? [], 3),
            'platform' => (string) ($ch[0] ?? $platforms[0] ?? 'facebook'),
        ];
    }

    $v = (array) ($d['voice'] ?? []);
    $ff = (int) ($v['formal_friendly'] ?? 50);
    $colors = [];
    foreach (['primary', 'secondary', 'accent', 'neutral'] as $k) {
        $c = (string) ($d['colors'][$k] ?? '');
        if (preg_match('/^#[0-9a-f]{6}$/i', $c)) $colors[] = strtoupper($c);
    }
    $chars = [];
    foreach (bk_characters($uid, true) as $c) {
        $chars[] = ['id' => (int) $c['id'], 'name' => $str($c['name'], 60),
                    'ref_image_ids' => array_slice(array_map(fn($i) => (int) $i['id'], (array) ($c['images'] ?? [])), 0, 4)];
    }
    $photo = array_filter([(string) ($d['_visual']['style'] ?? ''), (string) ($d['visuals']['kind'] ?? ''), (string) ($d['visuals']['light'] ?? '')]);

    $patterns = [];
    foreach ((array) ($d['_insights']['best_posts'] ?? []) as $b) {
        if (!empty($b['type'])) $patterns[] = mb_substr($b['type'] . (!empty($b['why']) ? ' — ' . $b['why'] : ''), 0, 100);
    }
    foreach ((array) ($d['_learnings'] ?? []) as $l) {
        $t = is_array($l) ? (string) ($l['t'] ?? $l['text'] ?? '') : (string) $l;
        if ($t !== '') $patterns[] = mb_substr($t, 0, 100);
    }

    $golden = [];
    try {
        foreach (db_all('SELECT id FROM contents WHERE user_id = ? AND (feedback = 1 OR publish_status = "published") ORDER BY id DESC LIMIT 3', [$uid]) as $r) $golden[] = (int) $r['id'];
    } catch (\Throwable $e) {}

    $cta = (array) ($d['cta'] ?? []);
    return [
        'brand' => [
            'name' => $str($d['business']['name'] ?? ($bp['business_name'] ?? ''), 80),
            'sector' => $str($d['business']['sector'] ?? ($bp['industry'] ?? ''), 80),
            'location' => $str($d['business']['location'] ?? '', 80),
        ],
        'positioning' => $str($d['business']['description'] ?? ($bp['description'] ?? ''), 220),
        'usp' => $list($d['business']['usp'] ?? '', 4),
        'proof_assets' => [],
        'services' => $services,
        'personas' => $per,
        'tone' => [
            'dialect' => bk_dialect_key((string) ($v['dialect'] ?? 'egyptian')),
            'formality' => $ff >= 70 ? 'friendly' : ($ff <= 35 ? 'formal' : 'balanced'),
            'emoji' => !empty($v['emoji']),
            'do_words' => $list($v['words_use'] ?? [], 8, 40),
            'dont_words' => $list(array_merge((array) ($v['words_avoid'] ?? []), (array) ($d['forbidden_topics'] ?? [])), 10, 40),
        ],
        'visual' => [
            'colors_hex' => $colors,
            // خطوط التصميمات اللي البراند اختارها (لحد 3) — غير كده الخط العربي والإنجليزي من الهوية
            'fonts' => array_values(array_filter(array_map('strval', (array) ($d['typography']['design_fonts'] ?? [])))) ?: array_values(array_filter([(string) ($d['typography']['ar'] ?? ''), (string) ($d['typography']['en'] ?? '')])),
            'photo_style' => $str(implode(' · ', $photo), 160),
            'avoid' => $list($d['design_style']['avoid'] ?? [], 5, 60),
            'characters' => $chars,
        ],
        'cta' => ['type' => (string) ($cta['type'] ?? 'whatsapp'), 'text' => $str($cta['text'] ?? '', 120)],
        'compliance' => $list($d['forbidden_topics'] ?? [], 5, 100),
        'golden_example_ids' => $golden,
        'winning_patterns' => array_slice(array_values(array_unique($patterns)), 0, 5),
    ];
}

/* ═══════════════════ التنضيف والتقصير ═══════════════════ */

/** أنواع وأطوال ثابتة + التأكد إن الخدمات والعملاء والشخصيات بتوع العميل ده */
function ce_dna_clean(int $uid, array $in): array
{
    $s = fn($v, int $n = 200) => mb_substr(trim(strip_tags((string) (is_scalar($v) ? $v : ''))), 0, $n);
    $l = function ($v, int $n = 6, int $len = 80) use ($s) {
        $out = [];
        foreach ((array) (is_array($v) ? $v : []) as $x) {
            $x = $s($x, $len);
            if ($x !== '' && !in_array($x, $out, true)) $out[] = $x;
            if (count($out) >= $n) break;
        }
        return $out;
    };
    $svcIds = array_map(fn($x) => (int) $x['id'], bk_services($uid));
    $perIds = array_map(fn($x) => (int) $x['id'], bk_personas($uid, false));
    $chIds = array_map(fn($x) => (int) $x['id'], bk_characters($uid));

    $services = [];
    foreach ((array) ($in['services'] ?? []) as $x) {
        if (!is_array($x) || !in_array((int) ($x['id'] ?? 0), $svcIds, true)) continue;
        $services[] = ['id' => (int) $x['id'], 'name' => $s($x['name'] ?? '', 80),
            'priority' => isset(CE_DNA_PRIORITIES[$x['priority'] ?? '']) ? $x['priority'] : 'medium',
            'season' => $s($x['season'] ?? 'طول السنة', 60) ?: 'طول السنة', 'objections' => $l($x['objections'] ?? [], 3)];
    }
    $personas = [];
    foreach ((array) ($in['personas'] ?? []) as $x) {
        if (!is_array($x) || !in_array((int) ($x['id'] ?? 0), $perIds, true)) continue;
        $personas[] = ['id' => (int) $x['id'], 'name' => $s($x['name'] ?? '', 60), 'pain' => $l($x['pain'] ?? [], 3),
            'fears' => $l($x['fears'] ?? [], 3), 'desires' => $l($x['desires'] ?? [], 3),
            'platform' => isset(CE_DNA_PLATFORMS[$x['platform'] ?? '']) ? $x['platform'] : 'facebook'];
    }
    $t = (array) ($in['tone'] ?? []);
    $vis = (array) ($in['visual'] ?? []);
    $chars = [];
    foreach ((array) ($vis['characters'] ?? []) as $c) {
        if (!is_array($c) || !in_array((int) ($c['id'] ?? 0), $chIds, true)) continue;
        $chars[] = ['id' => (int) $c['id'], 'name' => $s($c['name'] ?? '', 60), 'ref_image_ids' => array_slice(array_map('intval', (array) ($c['ref_image_ids'] ?? [])), 0, 4)];
    }
    $b = (array) ($in['brand'] ?? []);
    $cta = (array) ($in['cta'] ?? []);
    return [
        'brand' => ['name' => $s($b['name'] ?? '', 80), 'sector' => $s($b['sector'] ?? '', 80), 'location' => $s($b['location'] ?? '', 80)],
        'positioning' => $s($in['positioning'] ?? '', 260),
        'usp' => $l($in['usp'] ?? [], 4, 100),
        'proof_assets' => $l($in['proof_assets'] ?? [], 5, 100),
        'services' => $services,
        'personas' => $personas,
        'tone' => [
            'dialect' => in_array($t['dialect'] ?? '', CE_DNA_DIALECTS, true) ? $t['dialect'] : 'egyptian',
            'formality' => isset(CE_DNA_FORMALITY[$t['formality'] ?? '']) ? $t['formality'] : 'balanced',
            'emoji' => !empty($t['emoji']),
            'do_words' => $l($t['do_words'] ?? [], 8, 40), 'dont_words' => $l($t['dont_words'] ?? [], 10, 40),
        ],
        'visual' => [
            'colors_hex' => array_values(array_slice(array_filter(array_map(fn($c) => strtoupper((string) $c), (array) ($vis['colors_hex'] ?? [])), fn($c) => (bool) preg_match('/^#[0-9A-F]{6}$/', $c)), 0, 6)),
            'fonts' => $l($vis['fonts'] ?? [], 3, 40), 'photo_style' => $s($vis['photo_style'] ?? '', 160),
            'avoid' => $l($vis['avoid'] ?? [], 5, 60), 'characters' => $chars,
        ],
        'cta' => ['type' => $s($cta['type'] ?? 'whatsapp', 20), 'text' => $s($cta['text'] ?? '', 120)],
        'compliance' => $l($in['compliance'] ?? [], 6, 120),
        'golden_example_ids' => array_slice(array_values(array_unique(array_map('intval', (array) ($in['golden_example_ids'] ?? [])))), 0, 5),
        'winning_patterns' => $l($in['winning_patterns'] ?? [], 5, 100),
    ];
}

/** تقصير لحد الميزانية (ce_dna_max_tokens) — بنشيل الأقل أهمية الأول */
function ce_dna_trim(array $dna): array
{
    $max = max(400, ce_setting_int('ce_dna_max_tokens', 1200));
    $size = fn(array $x) => ce_tokens_est(json_encode($x, JSON_UNESCAPED_UNICODE));
    for ($step = 0; $step < 9 && $size($dna) > $max; $step++) {
        switch ($step) {
            case 0: $dna['winning_patterns'] = array_slice($dna['winning_patterns'], 0, 3); break;
            case 1: $dna['proof_assets'] = array_slice($dna['proof_assets'], 0, 3); break;
            case 2:
                foreach ($dna['personas'] as &$p) { foreach (['pain', 'fears', 'desires'] as $k) $p[$k] = array_slice($p[$k], 0, 2); }
                unset($p);
                break;
            case 3:
                foreach ($dna['services'] as &$x) $x['objections'] = array_slice($x['objections'], 0, 2);
                unset($x);
                break;
            case 4: $dna['tone']['do_words'] = array_slice($dna['tone']['do_words'], 0, 4); break;
            case 5: $dna['visual']['avoid'] = array_slice($dna['visual']['avoid'], 0, 2); break;
            case 6: $dna['personas'] = array_slice($dna['personas'], 0, 4); break;
            case 7: $dna['services'] = array_slice($dna['services'], 0, 6); break;
            case 8: $dna['positioning'] = mb_substr($dna['positioning'], 0, 160); break;
        }
    }
    return $dna;
}

/* ═══════════════════ الـ prefix الثابت للبرومبتات ═══════════════════ */

/**
 * ملف الهوية كبداية ثابتة للبرومبت — نفس النص بالظبط في كل نداء لنفس العميل (مفيش تواريخ ولا ترتيب متغير)
 * عشان الـ prompt caching يشتغل. لو مفيش DNA متحفظ بيتبني بالقواعد على الطاير.
 */
function ce_dna_prefix(int $uid): string
{
    $dna = ce_dna_get($uid) ?? ce_dna_trim(ce_dna_clean($uid, ce_dna_from_kit($uid)));
    return "## BRAND_PROFILE (ملف هوية البراند — التزم بيه حرفيًا)\n" . json_encode($dna, JSON_UNESCAPED_UNICODE) . "\n## END_BRAND_PROFILE";
}

/* ═══════════════════ البناء بالـ AI ═══════════════════ */

function ce_dna_system_prompt(): string
{
    return "أنت استراتيجي براند. هتاخد بيانات هوية نشاط (منظمة + نصوص طويلة) وترجّع JSON بس، مختصر جدًا، بالعربي، بنفس لهجة البراند.\n"
        . "المفاتيح المطلوبة بالظبط:\n"
        . "{\"positioning\": \"جملة واحدة (≤ 25 كلمة): البراند ده لمين وبيحل إيه وبيختلف في إيه\",\n"
        . " \"usp\": [\"≤ 4 نقط تميّز حقيقية من البيانات\"],\n"
        . " \"proof_assets\": [\"≤ 5 إثباتات موجودة فعلًا: سنين خبرة، شهادات، أرقام، آراء عملاء — ماتألفش أي رقم\"],\n"
        . " \"compliance\": [\"≤ 5 قيود لازم المحتوى يلتزم بيها (قوانين الإعلان في المجال، وعود ممنوعة)\"],\n"
        . " \"winning_patterns\": [\"≤ 5 أنماط محتوى نجحت أو مناسبة\"],\n"
        . " \"persona_fears\": {\"<id العميل>\": [\"≤ 3 مخاوف\"]},\n"
        . " \"service_objections\": {\"<id الخدمة>\": [\"≤ 3 اعتراضات\"]}}\n"
        . "قواعد: ماتخترعش معلومات مش موجودة · سيب القايمة فاضية لو مفيش بيانات · مفيش أي نص برّه الـ JSON.";
}

/** المصادر الطويلة اللي بتتلخّص (مابتدخلش البرومبتات بعد كده) */
function ce_dna_sources_text(int $uid): string
{
    $kit = bk_get($uid);
    $d = $kit['data'] ?? [];
    $bp = function_exists('bk_brand_profile') ? (bk_brand_profile($uid) ?? []) : [];
    $parts = [];
    foreach ([['وصف النشاط', $d['business']['description'] ?? ''], ['ملخص الهوية', $bp['ai_summary'] ?? ''], ['الجمهور', $d['audience']['description'] ?? ''],
              ['ملاحظات', $bp['notes'] ?? ''], ['قواعد التصميم', $bp['design_rules'] ?? ''], ['ملخص تحليل المحتوى', $d['_insights']['summary'] ?? '']] as [$k, $v]) {
        $v = trim((string) $v);
        if ($v !== '') $parts[] = "$k: " . mb_substr($v, 0, 1200);
    }
    try {
        $facts = db_all('SELECT field, value FROM brand_facts WHERE user_id = ? AND status IN ("applied","approved") ORDER BY id DESC LIMIT 25', [$uid]);
        if ($facts) $parts[] = "حقائق متأكد منها:\n" . implode("\n", array_map(fn($f) => '- ' . $f['field'] . ': ' . mb_substr((string) $f['value'], 0, 200), $facts));
    } catch (\Throwable $e) {}
    return implode("\n\n", $parts);
}

/** دمج رد الـ AI على ملف القواعد — الحقول المنظّمة من الـ Kit بتفضل زي ما هي، الـ AI بيكمّل الفاضي بس */
function ce_dna_merge_ai(array $dna, array $ai): array
{
    if (!empty($ai['positioning']) && is_string($ai['positioning'])) $dna['positioning'] = $ai['positioning'];
    foreach (['usp', 'proof_assets', 'compliance', 'winning_patterns'] as $k) {
        if (!empty($ai[$k]) && is_array($ai[$k])) $dna[$k] = array_values(array_unique(array_merge($dna[$k] ?? [], $ai[$k])));
    }
    foreach ($dna['personas'] as &$p) {
        $f = $ai['persona_fears'][(string) $p['id']] ?? null;
        if (!$p['fears'] && is_array($f)) $p['fears'] = $f;
    }
    unset($p);
    foreach ($dna['services'] as &$s) {
        $o = $ai['service_objections'][(string) $s['id']] ?? null;
        if (!$s['objections'] && is_array($o)) $s['objections'] = $o;
    }
    unset($s);
    return $dna;
}

/**
 * الشغلانة: 3 خطوات — تجميع بالقواعد ← تكميل بالـ AI ← تقصير وحفظ
 * الحقول اللي العميل عدّلها بإيده بتتاخد من النسخة الحالية زي ما هي.
 */
function ce_dna_job(array $job, array &$state): array
{
    $uid = (int) $job['user_id'];
    $step = (int) ($state['step'] ?? 0);

    if ($step === 0) {
        $state['dna'] = ce_dna_clean($uid, ce_dna_from_kit($uid));
        $state['hash'] = ce_dna_source_hash($uid);
        $state['step'] = 1;
        return ['done' => false, 'progress' => 25, 'stage' => 'collect', 'label' => 'جمعنا بيانات هويتك… بنكتب الملخص'];
    }

    if ($step === 1) {
        $src = ce_dna_sources_text($uid);
        $state['cost_usd'] = 0.0;
        if ($src !== '' || $state['dna']['services'] || $state['dna']['personas']) {
            $user = "CE:brand_dna\nالبيانات المنظّمة:\n" . json_encode($state['dna'], JSON_UNESCAPED_UNICODE) . "\n\nالنصوص:\n" . ($src ?: '(مفيش)');
            $r = ce_ai_json('ce_brand_dna', ce_dna_system_prompt(), $user, ['user_id' => $uid, 'ref_type' => 'ce_job', 'ref_id' => (int) $job['id']], 1200, 0.3);
            $state['cost_usd'] = $r['cost_usd'];
            $state['ai'] = $r['ok'] ? 'ok' : $r['error'];
            if ($r['ok']) {
                $state['dna'] = ce_dna_merge_ai($state['dna'], $r['data']);
            } elseif ($r['error'] !== 'gateway_off' && (int) $job['attempts'] + 1 < (int) $job['max_attempts']) {
                throw new RuntimeException('ce_brand_dna: ' . $r['error']); // محاولة تانية بعد شوية
            }
            // الـ AI مش متاح أو فشل آخر مرة ← بنكمّل بملف القواعد
        }
        $state['step'] = 2;
        return ['done' => false, 'progress' => 75, 'stage' => 'ai', 'label' => 'بنراجع الملف ونختصره…'];
    }

    // الحقول المعدّلة يدويًا بتفضل زي ما هي
    $cur = ce_dna_get($uid);
    $edited = ce_dna_edited($uid);
    $dna = $state['dna'];
    if ($cur) foreach ($edited as $k) if (array_key_exists($k, $cur)) $dna[$k] = $cur[$k];
    $saved = ce_dna_save($uid, $dna, ($state['ai'] ?? '') === 'ok' ? 'ai' : 'rules', (int) $job['id'], (string) ($state['hash'] ?? ''), $edited);
    return ['done' => true, 'result' => ['tokens' => $saved['tokens'], 'ai' => $state['ai'] ?? 'skipped'],
            'used_credits' => ce_usd_to_credits((float) ($state['cost_usd'] ?? 0))];
}

/** بدء بناء/تحديث الـ DNA (مرة واحدة لنفس نسخة الهوية — الضغط مرتين مابيعملش شغلانتين) */
function ce_dna_build(int $uid, bool $force = false): array
{
    $active = ce_job_active_for($uid, 'brand_dna');
    if ($active) return ['ok' => true, 'job' => $active, 'existing' => true];
    $hash = ce_dna_source_hash($uid);
    $idem = 'dna:' . $uid . ':' . substr($hash, 0, 16) . ($force ? ':' . time() : '');
    return ce_job_create($uid, 'brand_dna', [], [
        'ref_type' => 'brand_dna', 'ref_id' => $uid, 'idem_key' => $idem,
        'credits' => max(0, ce_setting_int('ce_dna_cost', 0)), 'note' => 'بناء الـ Brand DNA', 'max_attempts' => 3,
    ]);
}

/** خيارات الواجهة (القوائم المنسدلة والـ chips) */
function ce_dna_options(int $uid): array
{
    return [
        'priorities' => CE_DNA_PRIORITIES, 'formality' => CE_DNA_FORMALITY, 'platforms' => CE_DNA_PLATFORMS, 'seasons' => CE_DNA_SEASONS,
        'dialects' => array_combine(CE_DNA_DIALECTS, array_map('bk_dialect_label', CE_DNA_DIALECTS)),
        'max_tokens' => max(400, ce_setting_int('ce_dna_max_tokens', 1200)),
        'characters' => array_map(fn($c) => ['id' => (int) $c['id'], 'name' => $c['name'], 'type' => $c['type'],
            'images' => array_map(fn($i) => ['id' => (int) $i['id'], 'url' => url((string) $i['image_path'])], (array) ($c['images'] ?? []))], bk_characters($uid, true)),
    ];
}

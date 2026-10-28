<?php
/**
 * Spread AI — محرك المحتوى v3 · الأفكار ← الاختيار ← الكتابة ← مراجعة الجودة (المرحلة F)
 *
 * لكل خانة في الخطة المعتمدة:
 *   1) الأفكار (ce_ideation): 3 أفكار كل واحدة من دافع مختلف + قايمة الـ Hooks اللي اتقالت قبل كده
 *      ← كل فكرة شبه فكرة قديمة (تشابه > 0.85 بالـ n-grams) بتترفض
 *   2) الاختيار (ce_selection): الناقد بيدّي كل فكرة درجة ← الأعلى بتتختار (لازم ≥ 75، وإلا أفكار جديدة مرة)
 *   3) الكتابة (ce_writer): بوست (3 Hooks + نص + CTA) · كاروسيل (5-8 شرائح) · ريلز (مشاهد) + Design Brief
 *      ← التحقق من الشكل في الكود (ولو ناقص: إعادة مرة بالخطأ نفسه)
 *   4) الجودة (ce_critic، من مزود غير الكاتب لو متاح): 10 معايير بالأوزان، لكل معيار اقتباس + درجة + إصلاح
 *      ← PASS: الإجمالي ≥ 85 ومفيش معيار < 70 ومفيش مخالفة · غير كده: تحسين للأجزاء الضعيفة بس (لحد جولتين)
 *      ← لسه مش عادية: «محتاج مراجعة يدوية» وبتتحفظ أحسن نسخة
 * وفي الآخر مراجعة نهائية للحملة كلها بنفس معايير بوابة الخطة + تقرير للعميل (الدرجات + أهم 3 ملاحظات).
 */
require_once __DIR__ . '/ce-plan.php';

ce_job_register('campaign_write', 'ce_write_job', 'كتابة الحملة', 'ce_write_cancel_cost');

const CE_TRIGGERS = ['curiosity' => 'فضول', 'reassurance' => 'طمأنة', 'identity' => 'هوية وانتماء', 'proof' => 'إثبات', 'humor' => 'خفة دم'];
const CE_QA_WEIGHTS = [
    'hook' => 15, 'audience_fit' => 10, 'retention' => 10, 'value' => 10, 'emotion' => 10,
    'shareability' => 8, 'trust' => 12, 'producibility' => 7, 'cta_business' => 10, 'icp_fit' => 8,
];
const CE_QA_LABELS = [
    'hook' => 'الـ Hook', 'audience_fit' => 'مناسب للجمهور', 'retention' => 'بيشد للآخر', 'value' => 'القيمة', 'emotion' => 'المشاعر',
    'shareability' => 'قابل للمشاركة', 'trust' => 'الثقة', 'producibility' => 'سهل التنفيذ', 'cta_business' => 'الـ CTA والبيزنس', 'icp_fit' => 'العميل المثالي',
];
const CE_BRIEF_FIELDS = ['layout', 'focal_subject', 'characters_refs', 'colors_hex', 'headline', 'subline', 'text_safe_zone', 'mood', 'lighting', 'camera_angle', 'aspect_ratio'];
const CE_FORBIDDEN_CLAIMS = ['مضمون 100', 'نتيجة مضمونة', 'نتايج مضمونة', 'ضمان 100', '100% مضمون', 'أرخص سعر في مصر'];

/* ═══════════════════ الإعدادات ═══════════════════ */

/** ترحيل 2026_ce_content متشغّل؟ */
function ce_write_ready(): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        db_one('SELECT id FROM ce_ideas LIMIT 1');
        db_one('SELECT id FROM ce_contents LIMIT 1');
        db_one('SELECT id FROM ce_content_versions LIMIT 1');
        db_one('SELECT final_qa_json FROM ce_campaigns LIMIT 1');
        return $ok = true;
    } catch (\Throwable $e) {
        return $ok = false;
    }
}

function ce_qa_weights(): array
{
    $w = CE_QA_WEIGHTS;
    $over = json_decode((string) get_setting('ce_qa_weights', ''), true);
    if (is_array($over) && array_sum(array_map('intval', array_intersect_key($over, $w))) === 100) {
        foreach ($w as $k => $_) $w[$k] = max(0, (int) ($over[$k] ?? $w[$k]));
    }
    return $w;
}

function ce_qa_rules(): array
{
    return [
        'pass_total' => max(0, min(100, ce_setting_float('ce_qa_pass_total', 85))),
        'min_criterion' => max(0, min(100, ce_setting_float('ce_qa_min_criterion', 70))),
        'max_rounds' => max(0, min(4, ce_setting_int('ce_qa_max_rounds', 2))),
        'select_min' => max(0, min(100, ce_setting_float('ce_select_min', 75))),
        'dedup' => max(0.5, min(1.0, ce_setting_float('ce_dedup_threshold', 0.85))),
        'other_provider' => ce_setting_int('ce_critic_other_provider', 1) === 1,
    ];
}

/* ═══════════════════ منع التكرار (n-grams) ═══════════════════ */

/** تطبيع النص العربي للمقارنة: من غير تشكيل ولا علامات، والألف/الياء/التاء المربوطة موحّدة */
function ce_text_norm(string $s): string
{
    $s = mb_strtolower($s);
    $s = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $s);
    $s = strtr($s, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ى' => 'ي', 'ة' => 'ه', 'ؤ' => 'و', 'ئ' => 'ي']);
    $s = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $s);
    return trim(preg_replace('/\s+/u', ' ', $s));
}

/** تشابه Dice على حروف ثلاثية (0..1) — بديل الـ embeddings لما مش متاحة */
function ce_similarity(string $a, string $b): float
{
    $grams = function (string $s): array {
        $s = ' ' . ce_text_norm($s) . ' ';
        $out = [];
        $n = mb_strlen($s);
        for ($i = 0; $i < $n - 2; $i++) $out[mb_substr($s, $i, 3)] = true;
        return $out;
    };
    $A = $grams($a);
    $B = $grams($b);
    if (!$A || !$B) return 0.0;
    return round(2 * count(array_intersect_key($A, $B)) / (count($A) + count($B)), 3);
}

/** الأفكار والـ Hooks اللي اتقالت قبل كده: أفكار الحملة دي كلها + المختارة من حملات العميل التانية + آخر محتوى في المكتبة */
function ce_used_hooks(int $uid, int $cid, int $exceptSlot = 0): array
{
    $out = [];
    foreach (db_all('SELECT title, hook FROM ce_ideas WHERE user_id = ? AND (campaign_id = ? OR selected = 1) AND slot_id <> ? ORDER BY id DESC LIMIT 120', [$uid, $cid, $exceptSlot]) as $r) {
        $out[] = $r['hook'];
        $out[] = $r['title'];
    }
    try {
        foreach (db_all('SELECT generated_text FROM contents WHERE user_id = ? AND generated_text IS NOT NULL ORDER BY id DESC LIMIT 30', [$uid]) as $r) {
            $line = trim((string) strtok((string) $r['generated_text'], "\n"));
            if ($line !== '') $out[] = mb_substr($line, 0, 120);
        }
    } catch (\Throwable $e) {}
    return array_values(array_unique(array_filter($out)));
}

/* ═══════════════════ القراءة ═══════════════════ */

function ce_content_row(int $slotId): ?array
{
    return db_one('SELECT * FROM ce_contents WHERE slot_id = ?', [$slotId]) ?: null;
}

/** النص الكامل للمحتوى (للتحقق من الاقتباسات والكلمات الممنوعة) */
function ce_content_text(array $body): string
{
    $parts = [];
    array_walk_recursive($body, function ($v, $k) use (&$parts) {
        if (is_string($v) && !in_array($k, ['layout', 'text_safe_zone', 'aspect_ratio', 'camera_angle', 'lighting'], true)) $parts[] = $v;
    });
    return implode("\n", $parts);
}

/* ═══════════════════ البرومبتات ═══════════════════ */

function ce_slot_text(array $slot, array $ctx): string
{
    return json_encode([
        'order' => $slot['position'] + 1, 'date' => $slot['date'], 'funnel_stage' => $slot['funnel'], 'pillar' => CE_PILLARS[$slot['pillar']] ?? $slot['pillar'],
        'service' => $ctx['svc_names'][$slot['service_id']] ?? '', 'persona' => $ctx['per_names'][$slot['persona_id']] ?? '',
        'format' => $slot['format'], 'angle' => $ctx['angles'][$slot['angle']]['label'] ?? $slot['angle'],
        'angle_safeguard' => $ctx['angles'][$slot['angle']]['safeguard'] ?? null, 'cta_level' => $slot['cta_level'], 'objective' => $slot['objective'],
    ], JSON_UNESCAPED_UNICODE);
}

function ce_ideation_system(): string
{
    return ce_prompt_sys('ce_ideation');
}

function ce_selection_system(): string
{
    return ce_prompt_sys('ce_selection');
}

function ce_writer_system(array $ctx): string
{
    return ce_prompt_sys('ce_writer', ce_prompt_vars('ce_writer', $ctx));
}

function ce_critic_system(): string
{
    return ce_prompt_sys('ce_critic');
}

/* ═══════════════════ رسائل المستخدم (نفسها في المحرك والـ Playground) ═══════════════════ */

function ce_ideation_user(array $ctx, array $slot, array $used, string $note = ''): string
{
    return ce_dna_prefix((int) $ctx['uid']) . "\n\nCE:ideation SLOT_N=" . ($slot['position'] + 1) . "\n<campaign>\n" . ce_plan_brief_text($ctx) . "\n</campaign>\nSLOT: " . ce_slot_text($slot, $ctx) . "\n"
        . 'ALREADY USED HOOKS/IDEAS: ' . json_encode(array_slice($used, 0, 60), JSON_UNESCAPED_UNICODE)
        . ($note !== '' ? "\nالأفكار اللي فاتت كانت ضعيفة: " . $note : '');
}

/** $cands: [['trigger_type','title','hook','core_insight'], …] */
function ce_selection_user(array $ctx, array $slot, array $cands): string
{
    $list = [];
    foreach (array_values($cands) as $k => $i) $list[] = ($k + 1) . '. [' . ($i['trigger_type'] ?? ($i['trigger'] ?? '-')) . '] ' . ($i['title'] ?? '') . ' — Hook: ' . ($i['hook'] ?? '') . ' — ' . ($i['core_insight'] ?? '');
    return ce_dna_prefix((int) $ctx['uid']) . "\n\nCE:selection\nSLOT: " . ce_slot_text($slot, $ctx) . "\n\nالأفكار:\n" . implode("\n", $list);
}

/** $idea: title · hook · core_insight · why · risk — $revision: [fixes[], current_body_json] · $werr: أخطاء الشكل من الرد اللي فات */
function ce_writer_user(array $ctx, array $slot, array $idea, array $golden = [], ?array $revision = null, array $werr = []): string
{
    $chan = CE_CHANNELS[$ctx['brief']['channel'] ?? ''][0] ?? 'واتساب';
    $user = ce_dna_prefix((int) $ctx['uid']) . "\n\nCE:writer FORMAT=" . $slot['format']
        . "\n<campaign>\n" . ce_plan_brief_text($ctx) . "\n</campaign>\n<slot>" . ce_slot_text($slot, $ctx) . "</slot>\n"
        . '<idea>' . json_encode(['title' => $idea['title'] ?? '', 'hook' => $idea['hook'] ?? '', 'insight' => $idea['core_insight'] ?? '', 'why' => $idea['why'] ?? '', 'risk' => $idea['risk'] ?? ''], JSON_UNESCAPED_UNICODE) . "</idea>\n"
        . 'الشكل: ' . $slot['format'] . ' · الـ CTA: ' . $slot['cta_level'] . ' على ' . $chan . (!empty($ctx['dna']['cta']['text']) ? ' (الـ CTA الأساسي: ' . $ctx['dna']['cta']['text'] . ')' : '')
        . ($golden ? "\nأمثلة بنفس المستوى (قلّد الجودة مش المحتوى):\n- " . implode("\n- ", $golden) : '');
    if ($revision) {
        $user .= "\n\nREVISION: Fix ONLY these criteria. Keep everything else unchanged:\n- " . implode("\n- ", $revision[0])
            . "\n\nالنسخة الحالية:\n" . (string) $revision[1];
    }
    if ($werr) $user .= "\n\nالرد اللي فات كان فيه أخطاء — صلّحها: " . implode(' · ', $werr);
    return $user;
}

function ce_critic_user(array $ctx, array $slot, array $body, array $codeFlags = []): string
{
    return ce_dna_prefix((int) $ctx['uid']) . "\n\nCE:critic\n<slot>" . ce_slot_text($slot, $ctx) . "</slot>\n<content>" . json_encode($body, JSON_UNESCAPED_UNICODE) . '</content>'
        . ($codeFlags ? "\nمخالفات لقاها الكود: " . implode(' · ', $codeFlags) : '');
}

/** $lines: سطر لكل قطعة (المرحلة · الشكل · الخدمة · الـ Hook · الـ CTA · الجودة) */
function ce_final_qa_user(array $ctx, array $lines): string
{
    return ce_dna_prefix((int) $ctx['uid']) . "\n\nCE:final_qa\n<campaign>\n" . ce_plan_brief_text($ctx) . "\n</campaign>\n\nالمحتوى النهائي:\n" . implode("\n", $lines);
}

/* ═══════════════════ التحقق من شكل المحتوى ═══════════════════ */

/**
 * بيصلّح الحاجات البسيطة بالكود (العنوان ≤ 7 كلمات · ألوان البراند · المقاس) ويرجّع الأخطاء اللي محتاجة إعادة.
 * @return array{0: array, 1: string[]}
 */
function ce_content_check(array $body, string $format, array $ctx, array $brief): array
{
    $err = [];
    $str = fn($v) => is_string($v) ? trim($v) : '';
    if ($format === 'post') {
        $hooks = array_values(array_filter(array_map($str, (array) ($body['hooks'] ?? []))));
        if (count($hooks) < 3) $err[] = 'لازم 3 Hooks مختلفين (لقيت ' . count($hooks) . ')';
        $body['hooks'] = array_slice($hooks, 0, 3);
        if ($str($body['body'] ?? '') === '') $err[] = 'النص (body) فاضي';
    } elseif ($format === 'carousel') {
        $slides = [];
        foreach ((array) ($body['slides'] ?? []) as $i => $sl) {
            $t = is_array($sl) ? $str($sl['text'] ?? '') : $str($sl);
            if ($t !== '') $slides[] = ['n' => count($slides) + 1, 'text' => $t];
        }
        if (count($slides) < 5 || count($slides) > 8) $err[] = 'الكاروسيل لازم من 5 لـ 8 شرائح (لقيت ' . count($slides) . ')';
        foreach ($slides as $sl) if (count(preg_split('/\s+/u', $sl['text'])) > 24) { $err[] = 'شريحة ' . $sl['n'] . ' أطول من 20 كلمة'; break; }
        $body['slides'] = array_slice($slides, 0, 8);
    } else {
        $scenes = [];
        foreach ((array) ($body['scenes'] ?? []) as $sc) {
            if (!is_array($sc)) continue;
            $scenes[] = ['time' => $str($sc['time'] ?? ''), 'visual' => $str($sc['visual'] ?? ''), 'on_screen_text' => $str($sc['on_screen_text'] ?? ''), 'voiceover' => $str($sc['voiceover'] ?? '')];
        }
        if (count($scenes) < 2) $err[] = 'السكريبت لازم مشهدين على الأقل';
        foreach ($scenes as $i => $sc) if ($sc['time'] === '' || ($sc['visual'] === '' && $sc['voiceover'] === '')) { $err[] = 'المشهد ' . ($i + 1) . ' ناقص الوقت أو المحتوى'; break; }
        $last = end($scenes);
        if ($last && preg_match('/(\d+)\s*s?\s*$/u', (string) $last['time'], $m) && (int) $m[1] > 45) $err[] = 'الريلز أطول من 45 ثانية';
        $body['scenes'] = $scenes;
    }
    if ($str($body['cta'] ?? '') === '') $err[] = 'الـ CTA فاضي';

    // Design Brief: الناقص بيتكمّل من الهوية، والعنوان بيتقصّ لـ 7 كلمات
    $db = is_array($body['design_brief'] ?? null) ? $body['design_brief'] : [];
    $missing = array_diff(CE_BRIEF_FIELDS, array_keys(array_filter($db, fn($v) => $v !== '' && $v !== null && $v !== [])));
    if (count($missing) > 4) $err[] = 'الـ design_brief ناقص: ' . implode(', ', $missing);
    $dna = $ctx['dna'];
    if (empty($db['colors_hex'])) $db['colors_hex'] = $dna['visual']['colors_hex'] ?? [];
    // المقاس ثابت حسب الشكل: بوست 1:1 · كاروسيل 4:5 · ريلز 9:16
    $db['aspect_ratio'] = ['post' => '1:1', 'carousel' => '4:5', 'reel' => '9:16'][$format] ?? '1:1';
    if (empty($db['characters_refs']) && !empty($brief['character_id'])) {
        foreach ($dna['visual']['characters'] ?? [] as $c) if ((int) $c['id'] === (int) $brief['character_id']) $db['characters_refs'] = [$c['name']];
    }
    $words = preg_split('/\s+/u', trim((string) ($db['headline'] ?? '')), -1, PREG_SPLIT_NO_EMPTY);
    if (count($words) > 7) $db['headline'] = implode(' ', array_slice($words, 0, 7));
    foreach (CE_BRIEF_FIELDS as $f) if (!array_key_exists($f, $db)) $db[$f] = in_array($f, ['characters_refs', 'colors_hex'], true) ? [] : '';
    $body['design_brief'] = $db;
    return [$body, $err];
}

/** مخالفات بالكود: كلمات ممنوعة · وعود مضمونة · ممنوعات الـ Skill · استثناءات الحملة */
function ce_content_flags(array $body, array $ctx, array $brief): array
{
    $text = ce_text_norm(ce_content_text($body));
    $flags = [];
    foreach (array_merge($ctx['dna']['tone']['dont_words'] ?? [], CE_FORBIDDEN_CLAIMS, ce_skill_items($ctx['skill'] ?? null, 'forbidden')) as $w) {
        $n = ce_text_norm((string) $w);
        if ($n !== '' && mb_strlen($n) >= 3 && str_contains($text, $n)) $flags[] = 'فيه كلمة/وعد ممنوع: «' . $w . '»';
    }
    foreach ($brief['exclusions'] ?? [] as $x) {
        $n = ce_text_norm((string) $x);
        if ($n !== '' && mb_strlen($n) >= 4 && str_contains($text, $n)) $flags[] = 'الحملة دي من غير: «' . $x . '»';
    }
    return array_values(array_unique($flags));
}

/** الدرجة والحكم من رد الناقد — الحكم بيتحسب في الكود (مش بنصدّق verdict الموديل) */
function ce_qa_score(array $critic, array $body, array $codeFlags): array
{
    $r = ce_qa_rules();
    $w = ce_qa_weights();
    $text = ce_text_norm(ce_content_text($body));
    $crit = [];
    $sum = 0.0;
    $min = 100.0;
    foreach ($w as $k => $wt) {
        $c = (array) ($critic['criteria'][$k] ?? []);
        $score = isset($c['score']) && is_numeric($c['score']) ? max(0, min(100, (float) $c['score'])) : 0.0;
        $ev = mb_substr(trim((string) ($c['evidence'] ?? '')), 0, 300);
        $evN = ce_text_norm($ev);
        $crit[$k] = ['score' => $score, 'evidence' => $ev, 'evidence_ok' => $evN !== '' && str_contains($text, mb_substr($evN, 0, 40)),
                     'fix' => mb_substr(trim((string) ($c['fix'] ?? '')), 0, 300)];
        $sum += $score * $wt;
        $min = min($min, $score);
    }
    $total = round($sum / max(1, array_sum($w)), 1);
    $flags = array_values(array_unique(array_merge($codeFlags, array_map(fn($x) => mb_substr((string) $x, 0, 200), array_filter((array) ($critic['compliance_flags'] ?? []), 'is_string')))));
    $pass = $total >= $r['pass_total'] && $min >= $r['min_criterion'] && !$codeFlags && strtoupper((string) ($critic['verdict'] ?? '')) !== 'REJECT';
    return ['criteria' => $crit, 'total' => $total, 'min' => $min, 'verdict' => $pass ? 'PASS' : 'REVISE', 'compliance_flags' => $flags,
            'code_flags' => $codeFlags, 'brand_voice_match' => isset($critic['brand_voice_match']) ? (float) $critic['brand_voice_match'] : null];
}

/** الإصلاحات المطلوبة للجولة الجاية: المعايير اللي < 80 + المخالفات */
function ce_qa_fixes(array $qa): array
{
    $fixes = [];
    foreach ($qa['criteria'] as $k => $c) {
        if ($c['score'] < 80) $fixes[] = CE_QA_LABELS[$k] . ' (' . $c['score'] . '): ' . ($c['fix'] ?: 'حسّنه');
    }
    foreach ($qa['code_flags'] as $f) $fixes[] = 'شيل: ' . $f;
    return $fixes;
}

/* ═══════════════════ ناقد من مزود تاني ═══════════════════ */

/** أول موديل في سلسلة الناقد من مزود غير الكاتب (لو الإعداد مفعّل ومتاح) */
function ce_critic_target(?string $writerProvider): ?array
{
    if (!$writerProvider || !ce_qa_rules()['other_provider']) return null;
    require_once __DIR__ . '/ai-gateway.php';
    try {
        foreach (ai_gw_chain('ce_critic') as $c) {
            if ($c['p']['name'] !== $writerProvider) return ['p' => $c['p']['name'], 'm' => $c['m']];
        }
        // مفيش في سلسلة الناقد ← أي مزود شغال تاني بموديله الافتراضي
        foreach (ai_gw_providers() as $p) {
            if ($p['name'] !== $writerProvider && !empty($p['allow_customer_data'])) {
                $m = ai_gw_default_model($p, 'text');
                if ($m !== '') return ['p' => $p['name'], 'm' => $m];
            }
        }
    } catch (\Throwable $e) {}
    return null;
}

/* ═══════════════════ الشغلانة ═══════════════════ */

/**
 * الحالة: mode (all | slot) · queue (أرقام الخانات) · i · phase (ideate · select · write · critic · final)
 *         iround (جولة الأفكار) · qround (جولة التحسين) · wretry · cost_usd · forced_idea · done_pieces
 */
function ce_write_job(array $job, array &$state): array
{
    $uid = (int) $job['user_id'];
    $c = db_one('SELECT * FROM ce_campaigns WHERE id = ? AND user_id = ?', [(int) $job['ref_id'], $uid]);
    if (!$c) return ['fail' => 'الحملة مش موجودة'];
    $ctx = ce_plan_ctx($uid, $c);
    $brief = $ctx['brief'];
    $rules = ce_qa_rules();
    $state += ['mode' => 'all', 'queue' => [], 'i' => 0, 'phase' => 'ideate', 'iround' => 1, 'qround' => 0, 'wretry' => 0, 'cost_usd' => 0.0, 'done_pieces' => 0];
    $aiCtx = ['user_id' => $uid, 'ref_type' => 'ce_campaign', 'ref_id' => (int) $c['id'], 'campaign_id' => (int) $c['id'], 'skill' => $ctx['skill'] ?? null];
    $n = count($state['queue']);
    $prog = function (float $sub) use ($state, $n) { return (int) max(1, min(98, round(100 * ($state['i'] + $sub) / max(1, $n + ($state['mode'] === 'all' ? 0.5 : 0))))); };

    $finish = function () use (&$state) {
        return ['done' => true, 'result' => ['pieces' => $state['done_pieces'], 'final' => $state['final'] ?? null], 'used_credits' => ce_write_cancel_cost($state)];
    };

    // ─── المراجعة النهائية للحملة كلها ───
    if ($state['phase'] === 'final' || $state['i'] >= $n) {
        if ($state['mode'] === 'all') {
            $slots = ce_plan_slots_load((int) $c['id']);
            $rows = [];
            foreach (db_all('SELECT x.*, i.hook FROM ce_contents x LEFT JOIN ce_ideas i ON i.id = x.idea_id WHERE x.campaign_id = ? ', [(int) $c['id']]) as $r) $rows[(int) $r['slot_id']] = $r;
            $lines = [];
            foreach ($slots as $s) {
                $x = $rows[$s['id']] ?? null;
                $b = $x ? (json_decode((string) $x['body_json'], true) ?: []) : [];
                $lines[] = ($s['position'] + 1) . '. ' . $s['funnel'] . ' · ' . $s['format'] . ' · ' . ($ctx['svc_names'][$s['service_id']] ?? '') . ' · Hook: '
                    . mb_substr((string) ($x['hook'] ?? ($b['hooks'][0] ?? '')), 0, 100) . ' · CTA: ' . mb_substr((string) ($b['cta'] ?? ''), 0, 80) . ' · QA ' . ($x['qa_score'] ?? '-');
            }
            $v = ce_plan_validate($slots, $ctx);
            $r = ce_ai_json('ce_final_qa', ce_prompt_sys('ce_final_qa'), ce_final_qa_user($ctx, $lines), $aiCtx, 1500, 0.1);
            $state['cost_usd'] += $r['cost_usd'];
            $comb = ce_plan_combine($v['code'], $r['ok'] ? ($r['data']['scores'] ?? null) : null);
            $notes = [];
            foreach ((array) ($r['data']['notes'] ?? []) as $nt) if (is_string($nt) && trim($nt) !== '') $notes[] = mb_substr(trim($nt), 0, 240);
            if (!$notes) foreach ((array) ($r['data']['fixes'] ?? []) as $f) if (is_array($f) && !empty($f['fix'])) $notes[] = mb_substr((string) $f['fix'], 0, 240);
            $counts = db_one('SELECT SUM(status = "pass") p, SUM(status = "manual") m, SUM(status = "failed") f, AVG(qa_score) a FROM ce_contents WHERE campaign_id = ?', [(int) $c['id']]);
            $state['final'] = ['score' => $comb['score'], 'dims' => $comb['dims'], 'notes' => array_slice($notes, 0, 3), 'summary' => mb_substr((string) ($r['data']['summary'] ?? ''), 0, 500),
                               'pass' => (int) $counts['p'], 'manual' => (int) $counts['m'], 'failed' => (int) $counts['f'], 'avg_qa' => $counts['a'] !== null ? round((float) $counts['a'], 1) : null, 'at' => date('Y-m-d H:i')];
            db_run('UPDATE ce_campaigns SET status = "done", final_qa_json = ? WHERE id = ?', [json_encode($state['final'], JSON_UNESCAPED_UNICODE), (int) $c['id']]);
        }
        return $finish();
    }

    $slotId = (int) $state['queue'][$state['i']];
    $slot = null;
    foreach (ce_plan_slots_load((int) $c['id']) as $s) if ($s['id'] === $slotId) $slot = $s;
    $next = function () use (&$state) {
        $state['i']++;
        $state['phase'] = $state['i'] >= count($state['queue']) ? 'final' : 'ideate';
        $state['iround'] = 1; $state['qround'] = 0; $state['wretry'] = 0;
        unset($state['last_qa'], $state['writer_provider'], $state['forced_idea']);
    };
    if (!$slot) { $next(); return ['done' => false, 'progress' => $prog(0), 'label' => 'خانة اتشالت من الخطة — بنكمّل']; }
    $label = 'الخانة ' . ($slot['position'] + 1) . ' من ' . count(ce_plan_slots_load((int) $c['id']));
    db_run('INSERT INTO ce_contents (campaign_id, slot_id, user_id, format, status) VALUES (?,?,?,?, "writing")
            ON DUPLICATE KEY UPDATE status = IF(status IN ("pass","manual") AND ? = 0, status, "writing"), format = VALUES(format)',
        [(int) $c['id'], $slotId, $uid, $slot['format'], $state['phase'] === 'ideate' ? 1 : 0]);

    // لو العميل اختار فكرة بنفسه (تبديل الفكرة) ← على الكتابة على طول
    if ($state['phase'] === 'ideate' && !empty($state['forced_idea'])) {
        db_run('UPDATE ce_ideas SET selected = (id = ?) WHERE slot_id = ?', [(int) $state['forced_idea'], $slotId]);
        db_run('UPDATE ce_contents SET idea_id = ? WHERE slot_id = ?', [(int) $state['forced_idea'], $slotId]);
        $state['phase'] = 'write';
    }

    // ─── 1) الأفكار ───
    if ($state['phase'] === 'ideate') {
        $used = ce_used_hooks($uid, (int) $c['id'], $slotId);
        $user = ce_ideation_user($ctx, $slot, $used, (string) ($state['idea_note'] ?? ''));
        $r = ce_ai_json('ce_ideation', ce_ideation_system(), $user, $aiCtx, 1800, 0.9);
        $state['cost_usd'] += $r['cost_usd'];
        $ideas = $r['ok'] ? (array) ($r['data']['ideas'] ?? (array_is_list($r['data']) ? $r['data'] : [])) : [];
        if (!$ideas) {
            if ($r['error'] !== 'gateway_off' && (int) $job['attempts'] + 1 < (int) $job['max_attempts']) throw new RuntimeException('ce_ideation: ' . $r['error']);
            db_run('UPDATE ce_contents SET status = "failed" WHERE slot_id = ?', [$slotId]);
            $next();
            return ['done' => false, 'progress' => $prog(1), 'label' => $label . ': مقدرناش نجيب أفكار — اتعلّمت للمراجعة'];
        }
        $fresh = 0;
        foreach (array_slice($ideas, 0, 3) as $idea) {
            if (!is_array($idea)) continue;
            $title = mb_substr(trim((string) ($idea['title'] ?? '')), 0, 250);
            $hook = mb_substr(trim((string) ($idea['hook'] ?? '')), 0, 250);
            if ($title === '' && $hook === '') continue;
            $sim = 0.0;
            foreach ($used as $u) $sim = max($sim, ce_similarity($hook . ' ' . $title, (string) $u), ce_similarity($hook, (string) $u));
            $dup = $sim > $rules['dedup'];
            if (!$dup) $fresh++;
            $trig = strtolower((string) ($idea['trigger'] ?? ''));
            db_insert('INSERT INTO ce_ideas (campaign_id, slot_id, user_id, round, trigger_type, title, hook, core_insight, why, risk, similarity, is_dup) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)', [
                (int) $c['id'], $slotId, $uid, $state['iround'], isset(CE_TRIGGERS[$trig]) ? $trig : null, $title ?: $hook, $hook ?: $title,
                mb_substr((string) ($idea['core_insight'] ?? ''), 0, 500), mb_substr((string) ($idea['why_this_persona_cares'] ?? $idea['why'] ?? ''), 0, 500),
                mb_substr((string) ($idea['risk'] ?? ''), 0, 250), $sim, $dup ? 1 : 0,
            ]);
            $used[] = $hook;
        }
        if (!$fresh && $state['iround'] < 2) {
            $state['iround']++;
            $state['idea_note'] = 'كلها شبه أفكار اتقالت قبل كده — هات زوايا مختلفة تمامًا';
            return ['done' => false, 'progress' => $prog(0.15), 'label' => $label . ': الأفكار شبه القديمة — بنجيب أفكار جديدة'];
        }
        $state['phase'] = 'select';
        return ['done' => false, 'progress' => $prog(0.25), 'label' => $label . ': جبنا الأفكار… بنختار أحسنها'];
    }

    // ─── 2) الاختيار ───
    if ($state['phase'] === 'select') {
        $cands = db_all('SELECT * FROM ce_ideas WHERE slot_id = ? AND round = ? AND is_dup = 0 ORDER BY id', [$slotId, $state['iround']])
            ?: db_all('SELECT * FROM ce_ideas WHERE slot_id = ? AND round = ? ORDER BY id', [$slotId, $state['iround']]);
        $r = ce_ai_json('ce_selection', ce_selection_system(), ce_selection_user($ctx, $slot, $cands), $aiCtx, 600, 0.1);
        $state['cost_usd'] += $r['cost_usd'];
        $best = null;
        foreach ((array) ($r['data']['scores'] ?? []) as $sc) {
            $k = (int) ($sc['idx'] ?? 0) - 1;
            if (!isset($cands[$k]) || !is_numeric($sc['score'] ?? null)) continue;
            $score = max(0, min(100, (float) $sc['score']));
            db_run('UPDATE ce_ideas SET score = ?, score_note = ? WHERE id = ?', [$score, mb_substr((string) ($sc['note'] ?? ''), 0, 250), (int) $cands[$k]['id']]);
            if (!$best || $score > $best[1]) $best = [$cands[$k], $score];
        }
        if (!$best) $best = [$cands[0], null]; // الناقد مش متاح ← أول فكرة جديدة
        if ($best[1] !== null && $best[1] < $rules['select_min'] && $state['iround'] < 2) {
            $state['iround']++;
            $state['idea_note'] = 'أحسن فكرة جابت ' . $best[1] . ' بس — محتاجين Hook أقوى ومناسب أكتر للعميل';
            $state['phase'] = 'ideate';
            return ['done' => false, 'progress' => $prog(0.3), 'label' => $label . ': الأفكار مش قوية كفاية — بنجيب غيرها'];
        }
        db_run('UPDATE ce_ideas SET selected = (id = ?) WHERE slot_id = ?', [(int) $best[0]['id'], $slotId]);
        db_run('UPDATE ce_contents SET idea_id = ? WHERE slot_id = ?', [(int) $best[0]['id'], $slotId]);
        $state['phase'] = 'write';
        return ['done' => false, 'progress' => $prog(0.4), 'label' => $label . ': اخترنا الفكرة… بنكتب'];
    }

    $content = ce_content_row($slotId);
    $idea = $content && $content['idea_id'] ? db_one('SELECT * FROM ce_ideas WHERE id = ?', [(int) $content['idea_id']]) : null;
    if (!$idea) { $state['phase'] = 'ideate'; return ['done' => false, 'progress' => $prog(0.1), 'label' => $label]; }

    // ─── 3) الكتابة (أو التحسين) ───
    if ($state['phase'] === 'write') {
        $golden = [];
        foreach (array_slice($ctx['dna']['golden_example_ids'] ?? [], 0, 2) as $gid) {
            $g = db_one('SELECT generated_text FROM contents WHERE id = ? AND user_id = ?', [(int) $gid, $uid]);
            if ($g && trim((string) $g['generated_text']) !== '') $golden[] = mb_substr((string) $g['generated_text'], 0, 500);
        }
        $revision = $state['qround'] > 0 && !empty($state['last_qa']) ? [ce_qa_fixes($state['last_qa']), (string) $content['body_json']] : null;
        $user = ce_writer_user($ctx, $slot, $idea, $golden, $revision, (array) ($state['werr'] ?? []));
        $r = ce_ai_json('ce_writer', ce_writer_system($ctx), $user, $aiCtx, $slot['format'] === 'carousel' ? 2600 : 2000, 0.7);
        $state['cost_usd'] += $r['cost_usd'];
        if (!$r['ok']) {
            if ($r['error'] !== 'gateway_off' && (int) $job['attempts'] + 1 < (int) $job['max_attempts']) throw new RuntimeException('ce_writer: ' . $r['error']);
            db_run('UPDATE ce_contents SET status = "failed" WHERE slot_id = ?', [$slotId]);
            $next();
            return ['done' => false, 'progress' => $prog(1), 'label' => $label . ': الكتابة ماتمتش — اتعلّمت للمراجعة'];
        }
        [$body, $err] = ce_content_check($r['data'], $slot['format'], $ctx, $brief);
        if ($err && $state['wretry'] < 1) {
            // إعادة مرة واحدة والأخطاء نفسها في البرومبت
            $state['wretry']++;
            $state['werr'] = $err;
            return ['done' => false, 'progress' => $prog(0.5), 'label' => $label . ': الشكل ناقص — بنعيد الكتابة'];
        }
        unset($state['werr']);
        $state['wretry'] = 0;
        $state['writer_provider'] = $r['provider'];
        $ver = (int) (db_one('SELECT MAX(version) v FROM ce_content_versions WHERE content_id = ?', [(int) $content['id']])['v'] ?? 0) + 1;
        db_run('UPDATE ce_contents SET body_json = ?, version = ?, writer = ? WHERE slot_id = ?',
            [json_encode($body, JSON_UNESCAPED_UNICODE), $ver, mb_substr(($r['provider'] ?? '') . ' · ' . ($r['model'] ?? ''), 0, 120), $slotId]);
        db_run('INSERT INTO ce_content_versions (content_id, version, body_json) VALUES (?,?,?)', [(int) $content['id'], $ver, json_encode($body, JSON_UNESCAPED_UNICODE)]);
        if ($err) error_log('[ce_writer] slot ' . $slotId . ' accepted with issues: ' . implode(' · ', $err));
        $state['phase'] = 'critic';
        return ['done' => false, 'progress' => $prog(0.65), 'label' => $label . ($state['qround'] ? ': حسّنا النسخة… بنراجعها' : ': اتكتبت… بنراجع الجودة')];
    }

    // ─── 4) مراجعة الجودة ───
    $body = json_decode((string) $content['body_json'], true) ?: [];
    $codeFlags = ce_content_flags($body, $ctx, $brief);
    $target = ce_critic_target($state['writer_provider'] ?? null);
    $critUser = ce_critic_user($ctx, $slot, $body, $codeFlags);
    $r = ce_ai_json('ce_critic', ce_critic_system(), $critUser, $aiCtx + ($target ? ['only' => $target] : []), 2200, 0.1);
    $state['cost_usd'] += $r['cost_usd'];
    if (!$r['ok'] && $target && $r['error'] !== 'gateway_off') {
        // المزود التاني مش متاح دلوقتي ← السلسلة العادية للناقد
        $r = ce_ai_json('ce_critic', ce_critic_system(), $critUser, $aiCtx, 2200, 0.1);
        $state['cost_usd'] += $r['cost_usd'];
    }
    if (!$r['ok']) {
        if ($r['error'] !== 'gateway_off' && (int) $job['attempts'] + 1 < (int) $job['max_attempts']) throw new RuntimeException('ce_critic: ' . $r['error']);
        db_run('UPDATE ce_contents SET status = "manual", qa_json = ? WHERE slot_id = ?', [json_encode(['error' => 'الناقد مش متاح', 'code_flags' => $codeFlags], JSON_UNESCAPED_UNICODE), $slotId]);
        $state['done_pieces']++;
        $next();
        return ['done' => false, 'progress' => $prog(1), 'label' => $label . ': محتاجة مراجعة يدوية'];
    }
    $qa = ce_qa_score($r['data'], $body, $codeFlags);
    $qa['round'] = $state['qround'];
    $qa['critic'] = trim(($r['provider'] ?? '') . ' · ' . ($r['model'] ?? ''), ' ·');
    $qa['same_provider'] = $r['provider'] !== null && $r['provider'] === ($state['writer_provider'] ?? null);
    db_run('UPDATE ce_content_versions SET qa_score = ?, qa_json = ?, verdict = ? WHERE content_id = ? AND version = ?',
        [$qa['total'], json_encode($qa, JSON_UNESCAPED_UNICODE), $qa['verdict'], (int) $content['id'], (int) $content['version']]);
    db_run('UPDATE ce_contents SET qa_score = ?, qa_json = ?, rounds = ?, critic = ? WHERE slot_id = ?',
        [$qa['total'], json_encode($qa, JSON_UNESCAPED_UNICODE), $state['qround'], mb_substr($qa['critic'], 0, 120), $slotId]);

    if ($qa['verdict'] === 'PASS') {
        db_run('UPDATE ce_contents SET status = "pass" WHERE slot_id = ?', [$slotId]);
        $state['done_pieces']++;
        $next();
        return ['done' => false, 'progress' => $prog(1), 'label' => $label . ': عدّت الجودة ✓ (' . $qa['total'] . ')'];
    }
    if ($state['qround'] < $rules['max_rounds']) {
        $state['qround']++;
        $state['last_qa'] = $qa;
        $state['phase'] = 'write';
        return ['done' => false, 'progress' => $prog(0.8), 'label' => $label . ': ' . $qa['total'] . ' — بنحسّن الأجزاء الضعيفة (جولة ' . $state['qround'] . ')'];
    }
    // خلصت الجولات ← أحسن نسخة + «محتاج مراجعة يدوية»
    $best = db_one('SELECT * FROM ce_content_versions WHERE content_id = ? AND qa_score IS NOT NULL ORDER BY qa_score DESC, version DESC LIMIT 1', [(int) $content['id']]);
    if ($best && (int) $best['version'] !== (int) $content['version']) {
        db_run('UPDATE ce_contents SET body_json = ?, qa_score = ?, qa_json = ?, version = ? WHERE slot_id = ?', [$best['body_json'], $best['qa_score'], $best['qa_json'], (int) $best['version'], $slotId]);
    }
    db_run('UPDATE ce_contents SET status = "manual" WHERE slot_id = ?', [$slotId]);
    $state['done_pieces']++;
    $next();
    return ['done' => false, 'progress' => $prog(1), 'label' => $label . ': محتاجة مراجعة يدوية بعد ' . $rules['max_rounds'] . ' جولات'];
}

/* ═══════════════════ العمليات ═══════════════════ */

/** الإلغاء في النص: القطع اللي خلصت بتتحسب (بالحد الأدنى أو التكلفة الفعلية) والباقي بيرجع */
function ce_write_cancel_cost(array $state): int
{
    $min = max(0, ce_setting_int('ce_min_credits_piece', 1));
    return max($min * (int) ($state['done_pieces'] ?? 0), ce_usd_to_credits((float) ($state['cost_usd'] ?? 0)));
}

/** كريدت الكتابة: الأفكار + الاختيار + الكتابة + الجودة (× معامل التحسين) + المراجعة النهائية — مش أقل من الحد الأدنى للقطعة */
function ce_write_hold_credits(array $c, ?int $pieces = null): int
{
    $e = json_decode((string) ($c['estimate_json'] ?? ''), true) ?: [];
    $all = max(1, (int) ($e['pieces'] ?? 1));
    $pieces = $pieces ?? $all;
    $usd = 0.0;
    foreach ((array) ($e['stages'] ?? []) as $s) {
        if (in_array($s['key'], ['ce_ideation', 'ce_selection', 'ce_writer', 'ce_critic'], true)) $usd += (float) $s['usd'] * $pieces / $all;
        if ($s['key'] === 'ce_final_qa' && $pieces === $all) $usd += (float) $s['usd'];
    }
    return max($pieces * max(0, ce_setting_int('ce_min_credits_piece', 1)), ce_usd_to_credits($usd));
}

/** ابدأ الكتابة بعد اعتماد الخطة (حجز + شغلانة على كل الخانات بالترتيب) */
function ce_write_start(int $uid, int $cid): array
{
    if (!ce_write_ready()) return ['ok' => false, 'code' => 'not_ready', 'error' => 'الكتابة لسه مش متركّبة — محتاج ترحيل 2026_ce_content'];
    $c = ce_campaign_get($cid, $uid);
    if (!$c) return ['ok' => false, 'code' => 'not_found', 'error' => 'الحملة مش موجودة'];
    $active = ce_job_active_for($uid, 'campaign_write', 'ce_campaign', $cid);
    if ($active) return ['ok' => true, 'job' => $active, 'existing' => true];
    if ($c['status'] !== 'plan_ok') return ['ok' => false, 'code' => 'state', 'error' => 'اعتمد الخطة الأول'];
    $st = db()->prepare('UPDATE ce_campaigns SET status = "writing" WHERE id = ? AND user_id = ? AND status = "plan_ok"');
    $st->execute([$cid, $uid]);
    if ($st->rowCount() !== 1) {
        $active = ce_job_active_for($uid, 'campaign_write', 'ce_campaign', $cid);
        return $active ? ['ok' => true, 'job' => $active, 'existing' => true] : ['ok' => false, 'code' => 'state', 'error' => 'حصل تغيير في نفس اللحظة — حدّث الصفحة'];
    }
    $queue = array_map(fn($s) => $s['id'], ce_plan_slots_load($cid));
    $r = ce_job_create($uid, 'campaign_write', ['mode' => 'all', 'queue' => $queue], [
        'ref_type' => 'ce_campaign', 'ref_id' => $cid, 'credits' => ce_write_hold_credits($c),
        'idem_key' => 'write:' . $cid . ':' . bin2hex(random_bytes(6)), 'note' => 'كتابة الحملة: ' . mb_substr((string) $c['title'], 0, 80), 'max_attempts' => 4,
    ]);
    if (!$r['ok']) {
        db_run('UPDATE ce_campaigns SET status = "plan_ok" WHERE id = ? AND status = "writing"', [$cid]);
        return $r;
    }
    db_run('UPDATE ce_campaigns SET job_id = ?, write_hold_id = ? WHERE id = ?', [(int) $r['job']['id'], $r['job']['hold_id'], $cid]);
    return $r;
}

/** تبديل الفكرة (أو إعادة كتابة نفس الفكرة) لخانة واحدة — شغلانة صغيرة بحجز قطعة واحدة */
function ce_write_slot(int $uid, int $cid, int $slotId, int $ideaId): array
{
    if (!ce_write_ready()) return ['ok' => false, 'code' => 'not_ready', 'error' => 'الكتابة لسه مش متركّبة — محتاج ترحيل 2026_ce_content'];
    $c = ce_campaign_get($cid, $uid);
    if (!$c) return ['ok' => false, 'code' => 'not_found', 'error' => 'الحملة مش موجودة'];
    if (!in_array($c['status'], ['writing', 'done'], true)) return ['ok' => false, 'code' => 'state', 'error' => 'الحملة لسه ماتكتبتش'];
    if (ce_job_active_for($uid, 'campaign_write', 'ce_campaign', $cid)) return ['ok' => false, 'code' => 'busy', 'error' => 'فيه كتابة شغالة على الحملة دي — استنى لما تخلص'];
    $idea = db_one('SELECT * FROM ce_ideas WHERE id = ? AND slot_id = ? AND campaign_id = ? AND user_id = ?', [$ideaId, $slotId, $cid, $uid]);
    if (!$idea) return ['ok' => false, 'code' => 'not_found', 'error' => 'الفكرة مش موجودة'];
    $r = ce_job_create($uid, 'campaign_write', ['mode' => 'slot', 'queue' => [$slotId], 'forced_idea' => $ideaId], [
        'ref_type' => 'ce_campaign', 'ref_id' => $cid, 'credits' => ce_write_hold_credits($c, 1),
        'idem_key' => 'wslot:' . $slotId . ':' . $ideaId . ':' . bin2hex(random_bytes(4)), 'note' => 'إعادة كتابة خانة', 'max_attempts' => 3,
    ]);
    if ($r['ok']) db_run('UPDATE ce_contents SET status = "writing" WHERE slot_id = ?', [$slotId]);
    return $r;
}

/** «اعتمدها زي ما هي» للخانة اللي محتاجة مراجعة يدوية */
function ce_content_accept(int $uid, int $cid, int $slotId): bool
{
    $st = db()->prepare('UPDATE ce_contents SET status = "pass" WHERE slot_id = ? AND campaign_id = ? AND user_id = ? AND status = "manual"');
    $st->execute([$slotId, $cid, $uid]);
    return $st->rowCount() === 1;
}

/** كل اللي الواجهة محتاجاه: الخانات + الأفكار + المحتوى + الجودة + التقرير النهائي */
function ce_write_view(int $uid, array $c): array
{
    $cid = (int) $c['id'];
    if (!ce_write_ready()) return ['slots' => [], 'job' => null, 'final' => null, 'qa_labels' => CE_QA_LABELS, 'qa_weights' => ce_qa_weights(), 'triggers' => CE_TRIGGERS, 'rules' => ce_qa_rules(), 'write_credits' => null, 'not_ready' => true];
    if ($c['status'] === 'writing' && !ce_job_active_for($uid, 'campaign_write', 'ce_campaign', $cid)) {
        // الشغلانة وقفت بالفشل أو الإلغاء ← الحملة «اتكتبت جزئيًا» (العميل يقدر يكمّل خانة خانة)
        db_run('UPDATE ce_campaigns SET status = "done" WHERE id = ? AND status = "writing"', [$cid]);
        $c['status'] = 'done';
    }
    $ideas = [];
    foreach (db_all('SELECT * FROM ce_ideas WHERE campaign_id = ? ORDER BY round, id', [$cid]) as $i) {
        $ideas[(int) $i['slot_id']][] = ['id' => (int) $i['id'], 'trigger' => $i['trigger_type'], 'title' => $i['title'], 'hook' => $i['hook'], 'insight' => $i['core_insight'],
            'why' => $i['why'], 'score' => $i['score'] !== null ? (float) $i['score'] : null, 'note' => $i['score_note'], 'dup' => (bool) $i['is_dup'],
            'similarity' => $i['similarity'] !== null ? (float) $i['similarity'] : null, 'selected' => (bool) $i['selected'], 'round' => (int) $i['round']];
    }
    $contents = [];
    foreach (db_all('SELECT * FROM ce_contents WHERE campaign_id = ?', [$cid]) as $x) {
        $contents[(int) $x['slot_id']] = ['status' => $x['status'], 'body' => json_decode((string) $x['body_json'], true) ?: null, 'version' => (int) $x['version'],
            'qa' => json_decode((string) $x['qa_json'], true) ?: null, 'qa_score' => $x['qa_score'] !== null ? (float) $x['qa_score'] : null, 'rounds' => (int) $x['rounds'], 'idea_id' => $x['idea_id'] ? (int) $x['idea_id'] : null];
    }
    $job = db_one('SELECT * FROM ce_jobs WHERE kind = "campaign_write" AND ref_type = "ce_campaign" AND ref_id = ? ORDER BY id DESC LIMIT 1', [$cid]);
    $slots = [];
    foreach (ce_plan_slots_load($cid) as $s) {
        $slots[] = $s + ['ideas' => $ideas[$s['id']] ?? [], 'content' => $contents[$s['id']] ?? null];
    }
    return [
        'slots' => $slots, 'job' => $job ? ce_job_public($job) : null,
        'final' => json_decode((string) ($c['final_qa_json'] ?? ''), true) ?: null,
        'qa_labels' => CE_QA_LABELS, 'qa_weights' => ce_qa_weights(), 'triggers' => CE_TRIGGERS, 'rules' => ce_qa_rules(),
        'write_credits' => $c['status'] === 'plan_ok' ? ce_write_hold_credits($c) : null,
    ];
}

<?php
/**
 * Spread AI — محرك المحتوى v3 · الـ Playground + حالات الاختبار + اختبار الرجوع (المرحلة H)
 *
 * الـ Playground: مرحلة + براند (عميل حقيقي للقراءة بس أو البراند التجريبي) + نسخة برومبت أو مسودة + 1-3 موديلات جنب بعض.
 *   ← المدخلات بتتبني بنفس دوال المحرك (رسالة المستخدم بالظبط) والأدمن يقدر يعدّلها
 *   ← كل نداء sandbox: مابيكتبش في بيانات أي عميل · مابيخصمش كريدت · بيتسجل في ai_runs بـ sandbox=1
 * حالات الاختبار (Golden Set): رسالة ثابتة + متغيرات + أقل درجة (أو المتوقع للنقاد).
 * اختبار الرجوع: كل حالة بتتشغّل بالنسخة الحالية والجديدة (أو الموديل الحالي والجديد) وبتتقارن —
 *   لو المتوسط نزل أكتر من 3 ← تحذير · لو رد بقى بايظ أو تحت أقل درجة ← فشل.
 */
require_once __DIR__ . '/ce-design.php';
require_once __DIR__ . '/ai-models.php';

/** ترتيب الـ Pipeline الكامل */
const CE_LAB_PIPELINE = ['ce_matrix', 'ce_plan_critic', 'ce_ideation', 'ce_selection', 'ce_writer', 'ce_critic', 'ce_design_prompt', 'ce_vision_qa'];
/** أقصى توكنز وحرارة لكل مرحلة في الـ Playground (زي المحرك) */
const CE_LAB_LIMITS = [
    'ce_brand_dna' => [1200, 0.3], 'ce_matrix' => [3500, 0.4], 'ce_plan_critic' => [1500, 0.1], 'ce_ideation' => [1800, 0.9], 'ce_selection' => [600, 0.1],
    'ce_writer' => [2600, 0.7], 'ce_critic' => [2200, 0.1], 'ce_final_qa' => [1500, 0.1], 'ce_design_prompt' => [700, 0.5], 'ce_vision_qa' => [600, 0.1],
];

/* ═══════════════════ البراندات والموديلات ═══════════════════ */

/** البراندات المتاحة: التجريبي + العملاء اللي عندهم Brand DNA (للقراءة بس) */
function ce_lab_brands(): array
{
    $out = [['uid' => 0, 'name' => 'براند تجريبي — عيادة أسنان']];
    try {
        foreach (db_all('SELECT d.user_id, u.name, u.email FROM ce_brand_dna d JOIN users u ON u.id = d.user_id ORDER BY d.updated_at DESC LIMIT 60') as $r) {
            $out[] = ['uid' => (int) $r['user_id'], 'name' => $r['name'] . ' · ' . $r['email']];
        }
    } catch (\Throwable $e) {}
    return $out;
}

/** الموديلات المناسبة للمرحلة (من سجل الموديلات) — 'provider|model' */
function ce_lab_models(string $stage): array
{
    $route = db_one('SELECT capability FROM ai_task_routes WHERE task = ?', [$stage]);
    $cap = (string) ($route['capability'] ?? 'text');
    $out = [];
    foreach (aim_list(['enabled' => 1]) as $m) {
        if (!aim_fits($m, $cap, $stage === 'ce_vision_qa')) continue;
        $out[] = ['key' => $m['provider_name'] . '|' . $m['model'], 'label' => $m['provider_name'] . ' · ' . ($m['label'] ?: $m['model']), 'tier' => $m['tier']];
    }
    return $out;
}

/** الموديل الأساسي الحالي للمرحلة ('provider|model') */
function ce_lab_current_model(string $stage): ?string
{
    require_once __DIR__ . '/ai-gateway.php';
    try {
        $c = ai_gw_chain($stage, ['vision' => $stage === 'ce_vision_qa']);
        return $c ? $c[0]['p']['name'] . '|' . $c[0]['m'] : null;
    } catch (\Throwable $e) {
        return null;
    }
}

/* ═══════════════════ المدخلات (بنفس دوال المحرك) ═══════════════════ */

/** حملة افتراضية (مش متخزنة) */
function ce_lab_ctx(int $uid, string $format = 'post'): array
{
    $brief = ['goal' => 'awareness', 'all_services' => 1, 'persona_ids' => [], 'formats' => ['post' => 3, 'carousel' => 1, 'reel' => 1],
              'channel' => 'whatsapp', 'boldness' => 'medium', 'exclusions' => [], 'days' => 30];
    return ce_plan_ctx($uid, ['id' => 0, 'title' => 'حملة تجريبية', 'brief_json' => json_encode($brief), 'start_date' => date('Y-m-d', strtotime('+1 day'))]);
}

/** خانة للتجربة: من خطة مرحلة سابقة (لو موجودة) أو من الخطة بالقواعد — بالشكل المطلوب */
function ce_lab_slot(array $ctx, string $format, array $prev): array
{
    $slots = [];
    if (!empty($prev['ce_matrix']['slots']) && is_array($prev['ce_matrix']['slots'])) $slots = ce_plan_repair(ce_plan_normalize($prev['ce_matrix']['slots'], $ctx), $ctx);
    if (!$slots) $slots = ce_plan_build_rules($ctx);
    foreach ($slots as $i => $s) if ($s['format'] === $format) return $s + ['position' => $i, 'id' => 0];
    $s = $slots[0] ?? ['position' => 0, 'date' => date('Y-m-d'), 'funnel' => 'tofu', 'pillar' => 'education', 'service_id' => $ctx['services'][0] ?? 0,
                       'persona_id' => $ctx['personas'][0] ?? 0, 'angle' => 'faq', 'cta_level' => 'soft', 'objective' => 'توعية'];
    return ['format' => $format, 'id' => 0] + $s;
}

const CE_LAB_SAMPLE_IDEAS = [
    ['trigger' => 'curiosity', 'title' => 'هو التبييض بيضر المينا؟', 'hook' => 'هو التبييض فعلًا بيضر المينا ولا ده كلام؟', 'core_insight' => 'الخوف من الضرر بيمنع الناس', 'why' => 'عايزة نتيجة من غير ألم', 'risk' => 'قليل'],
    ['trigger' => 'proof', 'title' => '2000 حالة تبييض', 'hook' => '2000 ابتسامة في سنة — دي حكايتهم', 'core_insight' => 'الأرقام بتطمّن', 'why' => 'محتاجة دليل', 'risk' => 'لازم أرقام حقيقية'],
    ['trigger' => 'humor', 'title' => 'الشاي والقهوة', 'hook' => 'كوباية الشاي التالتة بتقول لسنانك إيه؟', 'core_insight' => 'عادات يومية بتلوّن الأسنان', 'why' => 'قريب من يومهم', 'risk' => 'ماتبقاش سخرية'],
];

/** محتوى تجريبي لكل شكل (لو مفيش ناتج كتابة قبل كده) */
function ce_lab_sample_body(string $format): array
{
    $db = ['layout' => 'صورة يمين ونص شمال', 'focal_subject' => 'ابتسامة', 'characters_refs' => [], 'colors_hex' => ['#0C87EF'], 'headline' => 'ابتسامتك تستاهل',
           'subline' => 'احجز النهارده', 'text_safe_zone' => 'الثلث اللي تحت', 'mood' => 'مطمّن', 'lighting' => 'نهار ناعم', 'camera_angle' => 'مستوى العين', 'aspect_ratio' => CE_FORMAT_ASPECT[$format] ?? '1:1'];
    if ($format === 'carousel') return ['slides' => array_map(fn($i) => ['n' => $i, 'text' => 'شريحة ' . $i . ': نصيحة عن الأسنان'], range(1, 6)), 'caption' => 'احفظ البوست', 'cta' => 'ابعتلنا على واتساب', 'design_brief' => $db];
    if ($format === 'reel') return ['scenes' => [['time' => '0-2s', 'visual' => 'دكتور بيبتسم', 'on_screen_text' => 'ابتسامتك تستاهل', 'voiceover' => 'ابتسامتك تستاهل']], 'caption' => 'ريلز', 'cta' => 'احجز', 'design_brief' => $db];
    return ['hooks' => ['ابتسامتك تستاهل', 'سنانك بتوجعك بالليل؟', 'مش لازم تستحمل الألم'], 'body' => 'سطر أول\nسطر تاني', 'cta' => 'احجز على واتساب', 'hashtags' => ['#اسنان'], 'design_brief' => $db];
}

/** صورة تجريبية للمراجعة (data URI) */
function ce_lab_sample_image(): string
{
    $im = imagecreatetruecolor(640, 640);
    for ($y = 0; $y < 640; $y++) imageline($im, 0, $y, 640, $y, imagecolorallocate($im, (int) (150 - 90 * $y / 640), (int) (200 - 90 * $y / 640), 235));
    imagefilledellipse($im, 320, 240, 260, 260, imagecolorallocate($im, 248, 206, 168));
    ob_start();
    imagejpeg($im, null, 80);
    imagedestroy($im);
    return 'data:image/jpeg;base64,' . base64_encode((string) ob_get_clean());
}

/**
 * رسالة المستخدم لمرحلة — بنفس دوال المحرك — من البراند ومخرجات المراحل اللي قبلها (لو موجودة)
 * @return array{user:string, images:array, meta:array, vars:array}
 */
function ce_lab_input(string $stage, int $uid, string $format = 'post', array $prev = []): array
{
    $format = isset(CE_FORMAT_ASPECT[$format]) ? $format : 'post';
    $ctx = ce_lab_ctx($uid, $format);
    $slot = ce_lab_slot($ctx, $format, $prev);
    $meta = ['uid' => $uid, 'format' => $format, 'slot' => $slot];
    $ideas = !empty($prev['ce_ideation']['ideas']) && is_array($prev['ce_ideation']['ideas']) ? array_values($prev['ce_ideation']['ideas']) : CE_LAB_SAMPLE_IDEAS;
    $body = !empty($prev['ce_writer']) && is_array($prev['ce_writer']) ? $prev['ce_writer'] : ce_lab_sample_body($format);
    $images = [];
    switch ($stage) {
        case 'ce_brand_dna':
            $dna = $uid ? ce_dna_clean($uid, ce_dna_from_kit($uid)) : ce_demo_dna();
            $user = ce_dna_user($dna, $uid ? ce_dna_sources_text($uid) : 'عيادة أسنان عائلية في المنصورة · مواعيد بالليل · دكتور خبرة 15 سنة');
            break;
        case 'ce_matrix':
            $user = ce_plan_matrix_user($ctx, [], []);
            break;
        case 'ce_plan_critic':
            $slots = !empty($prev['ce_matrix']['slots']) ? ce_plan_repair(ce_plan_normalize($prev['ce_matrix']['slots'], $ctx), $ctx) : ce_plan_build_rules($ctx);
            $user = ce_plan_critic_user($ctx, $slots, ce_plan_validate($slots, $ctx)['issues']);
            break;
        case 'ce_ideation':
            $user = ce_ideation_user($ctx, $slot, $uid ? ce_used_hooks($uid, 0) : []);
            break;
        case 'ce_selection':
            $user = ce_selection_user($ctx, $slot, $ideas);
            break;
        case 'ce_writer':
            // الفكرة: الأعلى درجة من الاختيار (لو اتعمل) — غير كده أول فكرة
            $pick = $ideas[0];
            foreach ((array) ($prev['ce_selection']['scores'] ?? []) as $sc) {
                if (isset($ideas[(int) ($sc['idx'] ?? 0) - 1]) && (!isset($best) || (float) $sc['score'] > $best)) { $best = (float) $sc['score']; $pick = $ideas[(int) $sc['idx'] - 1]; }
            }
            $user = ce_writer_user($ctx, $slot, ['title' => $pick['title'] ?? '', 'hook' => $pick['hook'] ?? '', 'core_insight' => $pick['core_insight'] ?? '', 'why' => $pick['why_this_persona_cares'] ?? ($pick['why'] ?? ''), 'risk' => $pick['risk'] ?? '']);
            break;
        case 'ce_critic':
            $user = ce_critic_user($ctx, $slot, $body, ce_content_flags($body, $ctx, $ctx['brief']));
            $meta['body'] = $body;
            break;
        case 'ce_final_qa':
            $lines = [];
            foreach (ce_plan_build_rules($ctx) as $i => $s) $lines[] = ($i + 1) . '. ' . $s['funnel'] . ' · ' . $s['format'] . ' · ' . ($ctx['svc_names'][$s['service_id']] ?? '') . ' · Hook: ' . (CE_LAB_SAMPLE_IDEAS[$i % 3]['hook']) . ' · CTA: احجز · QA 88';
            $user = ce_final_qa_user($ctx, $lines);
            break;
        case 'ce_design_prompt':
            $zone = ce_zone_from_text((string) ($body['design_brief']['text_safe_zone'] ?? ''));
            $user = ce_design_prompt_user($ctx, $slot, $body, ['frame' => 1, 'aspect' => CE_FORMAT_ASPECT[$format]], $zone, '');
            break;
        case 'ce_vision_qa':
            $prompt = (string) ($prev['ce_design_prompt']['prompt'] ?? 'A bright modern dental clinic, smiling patient, soft light');
            $user = ce_vision_user((array) ($body['design_brief'] ?? []), (array) ($ctx['dna']['visual']['colors_hex'] ?? []), $prompt);
            $images = [ce_lab_sample_image()];
            $meta['body'] = $body;
            break;
        default:
            $user = '';
    }
    return ['user' => $user, 'images' => $images, 'meta' => $meta, 'vars' => ce_prompt_vars($stage, $ctx),
            'skill' => !empty($ctx['skill']) ? ['version_id' => $ctx['skill']['version_id'], 'name' => $ctx['skill']['name'], 'version' => $ctx['skill']['version']] : null];
}

/* ═══════════════════ التشغيل (sandbox) ═══════════════════ */

/**
 * نداء واحد: برومبت نظام جاهز + رسالة + موديل (اختياري) — من غير ما يلمس بيانات عميل أو كريدت
 * @return array ok · data · text · error · tokens_in · tokens_out · cost_usd · ms · provider · model
 */
function ce_lab_call(string $stage, string $system, string $user, array $images = [], ?string $model = null): array
{
    [$max, $temp] = CE_LAB_LIMITS[$stage] ?? [1500, 0.3];
    $ctx = ['sandbox' => true, 'feature' => 'playground', 'ref_type' => 'ce_lab'];
    if ($model && str_contains($model, '|')) {
        [$p, $m] = explode('|', $model, 2);
        $ctx['only'] = ['p' => $p, 'm' => $m];
    }
    $t0 = microtime(true);
    $r = ce_ai_json($stage, $system, $user, $ctx, $max, $temp, $images);
    return [
        'ok' => $r['ok'], 'data' => $r['data'], 'text' => mb_substr((string) $r['text'], 0, 20000), 'error' => $r['ok'] ? null : $r['error'],
        'tokens_in' => (int) ($r['usage']['in'] ?? 0), 'tokens_out' => (int) ($r['usage']['out'] ?? 0), 'cost_usd' => round((float) $r['cost_usd'], 6),
        'ms' => (int) round((microtime(true) - $t0) * 1000), 'provider' => $r['provider'], 'model' => $r['model'],
    ];
}

/** برومبت النظام لنسخة (0 = v0 · null = الشغالة · نص = مسودة) */
function ce_lab_system(string $stage, $version, array $vars): string
{
    if (is_string($version)) return ce_prompt_render($version, $vars);
    if ($version === 0) return ce_prompt_render(ce_prompt_default($stage), $vars);
    if ($version) {
        $v = db_one('SELECT body FROM ce_prompt_versions WHERE id = ? AND stage = ?', [(int) $version, $stage]);
        if ($v) return ce_prompt_render((string) $v['body'], $vars);
    }
    return ce_prompt_render(ce_prompt_body($stage), $vars);
}

/* ═══════════════════ التقييم ═══════════════════ */

/** الرد سليم بالشكل المطلوب؟ ← قايمة المشاكل */
function ce_lab_check(string $stage, ?array $d, array $meta = []): array
{
    if (!is_array($d)) return ['الرد مش JSON'];
    $err = [];
    $num = fn($v) => isset($v) && is_numeric($v);
    switch ($stage) {
        case 'ce_brand_dna':
            if (trim((string) ($d['positioning'] ?? '')) === '') $err[] = 'positioning فاضي';
            break;
        case 'ce_matrix':
            if (empty($d['slots']) || !is_array($d['slots'])) $err[] = 'مفيش slots';
            break;
        case 'ce_plan_critic':
        case 'ce_final_qa':
            foreach (['balance', 'non_repetition', 'coverage', 'sequence', 'cta', 'goal_fit'] as $k) if (!$num($d['scores'][$k] ?? null)) { $err[] = 'scores ناقصة'; break; }
            if ($stage === 'ce_final_qa' && empty($d['notes'])) $err[] = 'notes ناقصة';
            break;
        case 'ce_ideation':
            $ideas = (array) ($d['ideas'] ?? []);
            if (count($ideas) < 3) $err[] = 'أقل من 3 أفكار';
            elseif (count(array_unique(array_map(fn($i) => (string) ($i['trigger'] ?? ''), $ideas))) < 3) $err[] = 'الدوافع مكررة';
            break;
        case 'ce_selection':
            if (empty($d['scores']) || !$num($d['scores'][0]['score'] ?? null)) $err[] = 'scores ناقصة';
            break;
        case 'ce_writer':
            $ctx = ce_lab_ctx((int) ($meta['uid'] ?? 0));
            [, $e] = ce_content_check($d, (string) ($meta['format'] ?? 'post'), $ctx, $ctx['brief']);
            $err = array_merge($err, $e);
            break;
        case 'ce_critic':
            foreach (array_keys(CE_QA_WEIGHTS) as $k) if (!$num($d['criteria'][$k]['score'] ?? null)) { $err[] = 'معايير ناقصة'; break; }
            break;
        case 'ce_design_prompt':
            $p = trim((string) ($d['prompt'] ?? ''));
            if (count(preg_split('/\s+/', $p, -1, PREG_SPLIT_NO_EMPTY)) < 15) $err[] = 'البرومبت قصير';
            if (preg_match('/\b(write|written|caption|text saying|typography|letters?)\b/i', $p) && !preg_match('/\bno (text|letters)/i', $p)) $err[] = 'بيطلب نص جوه الصورة';
            break;
        case 'ce_vision_qa':
            if (!$num($d['palette_match'] ?? null) || !$num($d['idea_match'] ?? null) || !in_array(strtoupper((string) ($d['verdict'] ?? '')), ['PASS', 'REGENERATE'], true)) $err[] = 'الحقول ناقصة';
            break;
    }
    return $err;
}

/** الأرقام في رد ناقد (مسطّحة) — للمقارنة بالمتوقع */
function ce_lab_numbers(array $d, string $pre = ''): array
{
    $out = [];
    foreach ($d as $k => $v) {
        $key = $pre === '' ? (string) $k : $pre . '.' . $k;
        if (is_array($v)) $out += ce_lab_numbers($v, $key);
        elseif (is_numeric($v) && !is_string($v) || (is_string($v) && is_numeric($v) && str_contains($key, 'score'))) $out[$key] = (float) $v;
        elseif (is_bool($v)) $out[$key] = $v ? 100.0 : 0.0;
    }
    return $out;
}

/**
 * درجة الرد (0-100):
 *   مراحل الإنتاج: الناقد الشغال بيقيّم (الكتابة ← ناقد الجودة · الأفكار ← ناقد الاختيار · الخطة ← ناقد الخطة) — والباقي فحص الشكل
 *   النقاد: لو فيه «متوقع» ← قرب الأرقام منه · غير كده الشكل سليم = 100
 * @return array{score: float, valid: bool, issues: array, critic: ?array, cost_usd: float}
 */
function ce_lab_score(string $stage, ?array $d, array $meta = [], ?array $expected = null, bool $withCritic = true): array
{
    $issues = ce_lab_check($stage, $d, $meta);
    $valid = !$issues || ($stage === 'ce_writer' && is_array($d) && !array_filter($issues, fn($e) => str_contains($e, 'فاضي')));
    $out = ['score' => 0.0, 'valid' => is_array($d) && $valid, 'issues' => $issues, 'critic' => null, 'cost_usd' => 0.0];
    if (!is_array($d)) return $out;
    $uid = (int) ($meta['uid'] ?? 0);
    $format = (string) ($meta['format'] ?? 'post');
    $ctx = null;
    $slot = (array) ($meta['slot'] ?? []);
    if ($withCritic && in_array($stage, ['ce_writer', 'ce_ideation', 'ce_matrix'], true)) {
        $ctx = ce_lab_ctx($uid, $format);
        if (!$slot) $slot = ce_lab_slot($ctx, $format, []);
    }
    if ($withCritic && $stage === 'ce_writer') {
        [$body] = ce_content_check($d, $format, $ctx, $ctx['brief']);
        $flags = ce_content_flags($body, $ctx, $ctx['brief']);
        $r = ce_lab_call('ce_critic', ce_critic_system(), ce_critic_user($ctx, $slot, $body, $flags));
        $out['cost_usd'] += $r['cost_usd'];
        if ($r['ok']) {
            $qa = ce_qa_score($r['data'], $body, $flags);
            $out['critic'] = ['total' => $qa['total'], 'verdict' => $qa['verdict'], 'min' => $qa['min']];
            $out['score'] = $out['valid'] ? (float) $qa['total'] : round($qa['total'] / 2, 1);
            return $out;
        }
    }
    if ($withCritic && $stage === 'ce_ideation' && !$issues) {
        $r = ce_lab_call('ce_selection', ce_selection_system(), ce_selection_user($ctx, $slot, array_values((array) $d['ideas'])));
        $out['cost_usd'] += $r['cost_usd'];
        if ($r['ok']) {
            $sc = array_map(fn($x) => (float) ($x['score'] ?? 0), (array) ($r['data']['scores'] ?? []));
            $out['critic'] = ['best' => $sc ? max($sc) : 0, 'avg' => $sc ? round(array_sum($sc) / count($sc), 1) : 0];
            $out['score'] = (float) ($sc ? max($sc) : 0);
            return $out;
        }
    }
    if ($withCritic && $stage === 'ce_matrix' && !$issues) {
        // القواعد بتتقاس على رد الموديل زي ما هو · والناقد بيشوف الخطة بالتواريخ (بعد التصليح) ومعاها المخالفات الأصلية
        $slots = ce_plan_normalize((array) $d['slots'], $ctx);
        $v = ce_plan_validate($slots, $ctx);
        $r = ce_lab_call('ce_plan_critic', ce_plan_critic_system(), ce_plan_critic_user($ctx, ce_plan_repair($slots, $ctx), $v['issues']));
        $out['cost_usd'] += $r['cost_usd'];
        $comb = ce_plan_combine($v['code'], $r['ok'] ? ($r['data']['scores'] ?? null) : null);
        $out['critic'] = ['score' => $comb['score'], 'hard' => $v['hard']];
        $out['score'] = max(0, (float) $comb['score'] - 5 * $v['hard']);
        return $out;
    }
    // النقاد: قرب الأرقام من المتوقع
    if ($expected && !in_array($stage, CE_PROMPT_PRODUCTION, true)) {
        $a = ce_lab_numbers($d);
        $b = ce_lab_numbers($expected);
        $common = array_intersect_key($b, $a);
        $diff = $common ? array_sum(array_map(fn($k) => abs($a[$k] - $b[$k]), array_keys($common))) / count($common) : 50;
        $verdictMiss = isset($expected['verdict'], $d['verdict']) && strtoupper((string) $expected['verdict']) !== strtoupper((string) $d['verdict']) ? 25 : 0;
        $out['score'] = round(max(0, 100 - $diff - $verdictMiss - 30 * count($issues)), 1);
        $out['critic'] = ['agreement' => $out['score'], 'compared' => count($common)];
        return $out;
    }
    $out['score'] = $out['valid'] ? max(0, 100 - 20 * count($issues)) : 0.0;
    return $out;
}

/* ═══════════════════ حالات الاختبار ═══════════════════ */

/** حالات المرحلة (من غير حالات الـ Skills — دي بتتختبر مع نسخ الـ Skill بس) */
function ce_lab_cases(string $stage, bool $activeOnly = false): array
{
    return db_all('SELECT * FROM ce_golden_cases WHERE stage = ?' . (ce_skills_ready() ? ' AND skill_id IS NULL' : '') . ($activeOnly ? ' AND is_active = 1' : '') . ' ORDER BY id', [$stage]);
}

/** كل الـ Skills بنسخها (لاختيار الـ Playground) */
function ce_lab_skill_options(): array
{
    if (!ce_skills_ready()) return [];
    $out = [];
    foreach (db_all('SELECT id, skey, name, is_enabled FROM ce_skills ORDER BY name') as $s) {
        $vers = array_map(fn($v) => ['id' => (int) $v['id'], 'version' => (int) $v['version'], 'active' => (int) $v['is_active'] === 1],
                          db_all('SELECT id, version, is_active FROM ce_skill_versions WHERE skill_id = ? ORDER BY version DESC LIMIT 10', [(int) $s['id']]));
        $out[] = ['id' => (int) $s['id'], 'key' => $s['skey'], 'name' => $s['name'], 'enabled' => (int) $s['is_enabled'] === 1, 'versions' => $vers];
    }
    return $out;
}

/** حالات Skill (كل المراحل) */
function ce_lab_skill_cases(int $skillId, bool $activeOnly = false): array
{
    if (!ce_skills_ready()) return [];
    return db_all('SELECT * FROM ce_golden_cases WHERE skill_id = ?' . ($activeOnly ? ' AND is_active = 1' : '') . ' ORDER BY id', [$skillId]);
}

/** «احفظ كحالة اختبار» — للنقاد الرد الحالي بيتحفظ كمتوقع (عشان أي نسخة جديدة تتقاس بيه) */
function ce_lab_case_save(string $stage, array $in, ?int $adminId): array
{
    if (!isset(CE_PROMPT_STAGES[$stage])) return ['ok' => false, 'error' => 'مرحلة مش معروفة'];
    $name = mb_substr(trim((string) ($in['name'] ?? '')), 0, 150) ?: (CE_PROMPT_STAGES[$stage] . ' — ' . date('Y-m-d H:i'));
    $user = (string) ($in['user'] ?? '');
    if (mb_strlen($user) < 10) return ['ok' => false, 'error' => 'الرسالة فاضية'];
    $images = array_values(array_filter((array) ($in['images'] ?? []), fn($u) => is_string($u) && str_starts_with($u, 'data:image/') && strlen($u) < 2_000_000));
    $expected = is_array($in['expected'] ?? null) && !in_array($stage, CE_PROMPT_PRODUCTION, true) ? $in['expected'] : null;
    $min = max(0, min(100, (float) ($in['min_score'] ?? 0)));
    $skillId = (int) ($in['skill_id'] ?? 0) ?: null;
    if ($skillId && (!ce_skills_ready() || !db_one('SELECT id FROM ce_skills WHERE id = ?', [$skillId]))) return ['ok' => false, 'error' => 'الـ Skill مش موجودة'];
    $id = (int) db_insert('INSERT INTO ce_golden_cases (stage, ' . ($skillId ? 'skill_id, ' : '') . 'name, user_text, vars_json, images_json, meta_json, min_score, expected_json, created_by) VALUES (?,' . ($skillId ? '?,' : '') . '?,?,?,?,?,?,?,?)', array_merge([$stage], $skillId ? [$skillId] : [], [
        $name, mb_substr($user, 0, 60000), json_encode((array) ($in['vars'] ?? []), JSON_UNESCAPED_UNICODE),
        $images ? json_encode($images) : null, json_encode((array) ($in['meta'] ?? []), JSON_UNESCAPED_UNICODE), $min,
        $expected ? json_encode($expected, JSON_UNESCAPED_UNICODE) : null, $adminId,
    ]));
    return ['ok' => true, 'id' => $id];
}

/** حالة اختبار من البراند التجريبي (لما المرحلة مافيهاش حالات) — للنقاد بنحفظ رد النسخة الشغالة كمتوقع */
function ce_lab_case_seed(string $stage, ?int $adminId): array
{
    $in = ce_lab_input($stage, 0, 'post');
    $expected = null;
    if (!in_array($stage, CE_PROMPT_PRODUCTION, true)) {
        $r = ce_lab_call($stage, ce_lab_system($stage, null, $in['vars']), $in['user'], $in['images']);
        if (!$r['ok']) return ['ok' => false, 'error' => 'الموديل مارجّعش رد عشان نحفظه كمتوقع: ' . $r['error']];
        $expected = $r['data'];
    }
    return ce_lab_case_save($stage, ['name' => 'البراند التجريبي — ' . CE_PROMPT_STAGES[$stage], 'user' => $in['user'], 'vars' => $in['vars'], 'images' => $in['images'],
                                     'meta' => $in['meta'], 'min_score' => in_array($stage, CE_PROMPT_PRODUCTION, true) ? 60 : 50, 'expected' => $expected], $adminId);
}

/* ═══════════════════ اختبار الرجوع ═══════════════════ */

/**
 * ابدأ اختبار: نسخة برومبت جديدة ($versionId) أو موديل جديد ($model = 'provider|model') — قدام الحالي
 */
function ce_lab_regression_start(string $stage, ?int $versionId, ?string $model, ?int $adminId): array
{
    if (!isset(CE_PROMPT_STAGES[$stage])) return ['ok' => false, 'error' => 'مرحلة مش معروفة'];
    $cases = array_map(fn($c) => (int) $c['id'], ce_lab_cases($stage, true));
    if (!$cases) return ['ok' => false, 'code' => 'no_cases', 'error' => 'مفيش حالات اختبار للمرحلة دي — احفظ واحدة من الـ Playground أو اعمل حالة من البراند التجريبي'];
    if ($versionId) {
        if (!db_one('SELECT id FROM ce_prompt_versions WHERE id = ? AND stage = ?', [$versionId, $stage])) return ['ok' => false, 'error' => 'النسخة مش موجودة'];
    } elseif (!$model || !in_array($model, array_column(ce_lab_models($stage), 'key'), true)) {
        return ['ok' => false, 'error' => 'اختار نسخة أو موديل من السجل'];
    }
    $active = ce_prompt_active($stage);
    $id = (int) db_insert('INSERT INTO ce_regression_runs (stage, kind, candidate_version_id, candidate_model, baseline_version_id, total, results_json, created_by) VALUES (?,?,?,?,?,?,?,?)', [
        $stage, $versionId ? 'prompt' : 'model', $versionId ?: null, $versionId ? null : $model, $active ? (int) $active['id'] : null, count($cases),
        json_encode(['cases' => $cases, 'items' => [], 'baseline_model' => ce_lab_current_model($stage)]), $adminId,
    ]);
    return ['ok' => true, 'id' => $id, 'total' => count($cases)];
}

/** حالة واحدة (بالحالي والجديد) — الواجهة بتنادي لحد ما يخلص */
function ce_lab_regression_step(int $runId): array
{
    $run = db_one('SELECT * FROM ce_regression_runs WHERE id = ?', [$runId]);
    if (!$run) return ['ok' => false, 'error' => 'الاختبار مش موجود'];
    if ($run['status'] !== 'running') return ['ok' => true, 'run' => ce_lab_run_public($run)];
    $res = json_decode((string) $run['results_json'], true) ?: ['cases' => [], 'items' => []];
    $i = count($res['items']);
    $stage = (string) $run['stage'];
    $isSkill = $run['kind'] === 'skill';
    if ($i < count($res['cases'])) {
        $case = db_one('SELECT * FROM ce_golden_cases WHERE id = ?', [(int) $res['cases'][$i]]);
        if (!$case) {
            $res['items'][] = ['case' => (int) $res['cases'][$i], 'skipped' => true];
        } elseif ($isSkill) {
            // نسخة Skill: نفس برومبت المرحلة الشغال — الفرق في جزء الـ Skill بس (الشغالة/من غيرها قدام الجديدة)
            $cs = (string) $case['stage'];
            $vars = (json_decode((string) $case['vars_json'], true) ?: []) + ce_prompt_vars($cs);
            $meta = json_decode((string) $case['meta_json'], true) ?: [];
            $images = json_decode((string) $case['images_json'], true) ?: [];
            $expected = json_decode((string) $case['expected_json'], true) ?: null;
            $sys = ce_lab_system($cs, null, $vars);
            $baseSkill = $run['baseline_skill_version_id'] ? ce_skill_version_load((int) $run['baseline_skill_version_id']) : null;
            $candSkill = ce_skill_version_load((int) $run['candidate_skill_version_id']);
            $b = ce_lab_call($cs, $sys . ce_skill_block($cs, $baseSkill), (string) $case['user_text'], $images);
            $n = ce_lab_call($cs, $sys . ce_skill_block($cs, $candSkill), (string) $case['user_text'], $images);
            $bs = ce_lab_score($cs, $b['ok'] ? $b['data'] : null, $meta, $expected);
            $ns = ce_lab_score($cs, $n['ok'] ? $n['data'] : null, $meta, $expected);
            $res['items'][] = [
                'case' => (int) $case['id'], 'name' => $case['name'], 'stage' => $cs, 'min' => (float) $case['min_score'],
                'base' => ['score' => $bs['score'], 'valid' => $bs['valid'], 'issues' => $bs['issues'], 'error' => $b['error'], 'ms' => $b['ms']],
                'new' => ['score' => $ns['score'], 'valid' => $ns['valid'], 'issues' => $ns['issues'], 'error' => $n['error'], 'ms' => $n['ms'], 'model' => $n['provider'] . ' · ' . $n['model']],
            ];
            db_run('UPDATE ce_regression_runs SET cost_usd = cost_usd + ? WHERE id = ?', [$b['cost_usd'] + $n['cost_usd'] + $bs['cost_usd'] + $ns['cost_usd'], $runId]);
        } else {
            $vars = (json_decode((string) $case['vars_json'], true) ?: []) + ce_prompt_vars($stage);
            $meta = json_decode((string) $case['meta_json'], true) ?: [];
            $images = json_decode((string) $case['images_json'], true) ?: [];
            $expected = json_decode((string) $case['expected_json'], true) ?: null;
            $baseSys = ce_lab_system($stage, $run['baseline_version_id'] ? (int) $run['baseline_version_id'] : 0, $vars);
            $candSys = $run['kind'] === 'prompt' ? ce_lab_system($stage, (int) $run['candidate_version_id'], $vars) : $baseSys;
            $b = ce_lab_call($stage, $baseSys, (string) $case['user_text'], $images, $res['baseline_model'] ?? null);
            $n = ce_lab_call($stage, $candSys, (string) $case['user_text'], $images, $run['kind'] === 'model' ? (string) $run['candidate_model'] : ($res['baseline_model'] ?? null));
            $bs = ce_lab_score($stage, $b['ok'] ? $b['data'] : null, $meta, $expected);
            $ns = ce_lab_score($stage, $n['ok'] ? $n['data'] : null, $meta, $expected);
            $res['items'][] = [
                'case' => (int) $case['id'], 'name' => $case['name'], 'min' => (float) $case['min_score'],
                'base' => ['score' => $bs['score'], 'valid' => $bs['valid'], 'issues' => $bs['issues'], 'error' => $b['error'], 'ms' => $b['ms']],
                'new' => ['score' => $ns['score'], 'valid' => $ns['valid'], 'issues' => $ns['issues'], 'error' => $n['error'], 'ms' => $n['ms'], 'model' => $n['provider'] . ' · ' . $n['model']],
            ];
            db_run('UPDATE ce_regression_runs SET cost_usd = cost_usd + ? WHERE id = ?', [$b['cost_usd'] + $n['cost_usd'] + $bs['cost_usd'] + $ns['cost_usd'], $runId]);
        }
        db_run('UPDATE ce_regression_runs SET done = ?, results_json = ? WHERE id = ?', [count($res['items']), json_encode($res, JSON_UNESCAPED_UNICODE), $runId]);
    }
    if (count($res['items']) >= count($res['cases'])) ce_lab_regression_finish($runId, $res);
    return ['ok' => true, 'run' => ce_lab_run_public(db_one('SELECT * FROM ce_regression_runs WHERE id = ?', [$runId]))];
}

/** النتيجة: فشل لو رد بقى بايظ أو نزل تحت أقل درجة (والحالي كان فوقها) · تحذير لو المتوسط نزل أكتر من الحد · غير كده عدّى */
function ce_lab_regression_finish(int $runId, array $res): void
{
    $items = array_values(array_filter($res['items'], fn($x) => empty($x['skipped'])));
    $maxDrop = max(0, ce_setting_float('ce_regression_max_drop', 3));
    if (!$items) {
        db_run('UPDATE ce_regression_runs SET status = "error", note = ?, finished_at = NOW() WHERE id = ?', ['كل الحالات اتشالت', $runId]);
        return;
    }
    $avgB = round(array_sum(array_map(fn($x) => $x['base']['score'], $items)) / count($items), 1);
    $avgN = round(array_sum(array_map(fn($x) => $x['new']['score'], $items)) / count($items), 1);
    $broke = array_filter($items, fn($x) => ($x['base']['valid'] && !$x['new']['valid']) || ($x['base']['score'] >= $x['min'] && $x['new']['score'] < $x['min']));
    $delta = round($avgN - $avgB, 1);
    $status = $broke ? 'failed' : ($delta < -$maxDrop ? 'warn' : 'passed');
    $note = $broke ? count($broke) . ' حالة بقت بايظة أو تحت أقل درجة' : ($status === 'warn' ? 'المتوسط نزل ' . abs($delta) . ' (أكتر من ' . $maxDrop . ')' : null);
    db_run('UPDATE ce_regression_runs SET status = ?, avg_base = ?, avg_new = ?, delta = ?, note = ?, finished_at = NOW() WHERE id = ?', [$status, $avgB, $avgN, $delta, $note, $runId]);
}

function ce_lab_run_public(array $r): array
{
    $res = json_decode((string) $r['results_json'], true) ?: [];
    return [
        'id' => (int) $r['id'], 'stage' => $r['stage'], 'kind' => $r['kind'],
        'skill_id' => isset($r['skill_id']) ? (int) $r['skill_id'] : null, 'candidate_skill_version_id' => !empty($r['candidate_skill_version_id']) ? (int) $r['candidate_skill_version_id'] : null, 'status' => $r['status'], 'total' => (int) $r['total'], 'done' => (int) $r['done'],
        'candidate_version_id' => $r['candidate_version_id'] ? (int) $r['candidate_version_id'] : null, 'candidate_model' => $r['candidate_model'],
        'baseline_model' => $res['baseline_model'] ?? null, 'avg_base' => $r['avg_base'] !== null ? (float) $r['avg_base'] : null,
        'avg_new' => $r['avg_new'] !== null ? (float) $r['avg_new'] : null, 'delta' => $r['delta'] !== null ? (float) $r['delta'] : null,
        'note' => $r['note'], 'cost_usd' => (float) $r['cost_usd'], 'items' => $res['items'] ?? [], 'created_at' => $r['created_at'],
    ];
}

/** «طبّق الموديل ده على المرحلة»: بعد اختبار رجوع عدّى — الموديل الجديد أساسي والقديم بيبقى بديل */
function ce_lab_model_apply(string $stage, string $model, ?int $adminId, bool $ackWarn = false): array
{
    $run = db_one('SELECT * FROM ce_regression_runs WHERE stage = ? AND kind = "model" AND candidate_model = ? ORDER BY id DESC LIMIT 1', [$stage, $model]);
    if (!$run || !in_array($run['status'], ['passed', 'warn'], true)) return ['ok' => false, 'code' => 'regression', 'error' => 'لازم الموديل يعدّي اختبار الرجوع على المرحلة دي الأول'];
    if ($run['status'] === 'warn' && !$ackWarn) return ['ok' => false, 'code' => 'warn', 'error' => 'الاختبار فيه تحذير — أكّد إنك عايز تطبّقه'];
    [$p, $m] = explode('|', $model, 2);
    $route = db_one('SELECT * FROM ai_task_routes WHERE task = ?', [$stage]);
    if (!$route) return ['ok' => false, 'error' => 'المرحلة مش موجودة في خريطة المراحل'];
    $old = json_decode((string) $route['chain_json'], true) ?: [];
    if (!$old && ($cur = ce_lab_current_model($stage))) { [$cp, $cm] = explode('|', $cur, 2); $old = [['p' => $cp, 'm' => $cm]]; }
    $chain = [['p' => $p, 'm' => $m]];
    foreach ($old as $c) if (($c['p'] ?? '') . '|' . ($c['m'] ?? '') !== $model) $chain[] = ['p' => (string) $c['p'], 'm' => (string) $c['m']];
    db_run('UPDATE ai_task_routes SET chain_json = ?, updated_by = ?, updated_at = NOW() WHERE task = ?', [json_encode(array_slice($chain, 0, 4), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $adminId, $stage]);
    if (function_exists('ai_gw_cache_bust')) ai_gw_cache_bust();
    return ['ok' => true];
}

/* ═══════════════════ Skills التخصصات (H2) ═══════════════════ */

/** اختبار رجوع لنسخة Skill: كل حالات الـ Skill (أي مرحلة) بالنسخة الشغالة (أو من غير Skill) قدام الجديدة */
function ce_lab_skill_regression_start(int $skillId, int $versionId, ?int $adminId): array
{
    $sk = db_one('SELECT * FROM ce_skills WHERE id = ?', [$skillId]);
    if (!$sk) return ['ok' => false, 'error' => 'الـ Skill مش موجودة'];
    if (!db_one('SELECT id FROM ce_skill_versions WHERE id = ? AND skill_id = ?', [$versionId, $skillId])) return ['ok' => false, 'error' => 'النسخة مش موجودة'];
    $cases = array_map(fn($c) => (int) $c['id'], ce_lab_skill_cases($skillId, true));
    if (!$cases) return ['ok' => false, 'code' => 'no_cases', 'error' => 'مفيش حالات اختبار للـ Skill دي — اعمل حالات (زرار «حالات للـ Skill») أو احفظ تجربة من الـ Playground'];
    $active = db_one('SELECT id FROM ce_skill_versions WHERE skill_id = ? AND is_active = 1 LIMIT 1', [$skillId]);
    $id = (int) db_insert('INSERT INTO ce_regression_runs (stage, kind, skill_id, candidate_skill_version_id, baseline_skill_version_id, total, results_json, created_by) VALUES (?,?,?,?,?,?,?,?)', [
        'skill:' . $sk['skey'], 'skill', $skillId, $versionId, $active ? (int) $active['id'] : null, count($cases),
        json_encode(['cases' => $cases, 'items' => []]), $adminId,
    ]);
    return ['ok' => true, 'id' => $id, 'total' => count($cases)];
}

/** براند حقيقي تخصصه الـ Skill دي (عشان الحالات تبقى من نفس المجال) — وإلا البراند التجريبي */
function ce_lab_skill_brand(array $skill): int
{
    foreach (db_all('SELECT user_id, dna_json FROM ce_brand_dna ORDER BY updated_at DESC LIMIT 300') as $r) {
        $dna = json_decode((string) $r['dna_json'], true) ?: [];
        $choice = (string) ($dna['brand']['skill'] ?? '');
        if ($choice === $skill['skey'] || ($choice === '' && ($d = ce_skill_detect((string) ($dna['brand']['sector'] ?? ''))) && $d['skey'] === $skill['skey'])) return (int) $r['user_id'];
    }
    return 0;
}

/** حالات للـ Skill: الأفكار + الكتابة + الناقد (للناقد بنحفظ رد الإعداد الحالي كمتوقع) */
function ce_lab_skill_seed(int $skillId, ?int $adminId): array
{
    $sk = db_one('SELECT * FROM ce_skills WHERE id = ?', [$skillId]);
    if (!$sk) return ['ok' => false, 'error' => 'الـ Skill مش موجودة'];
    $active = db_one('SELECT id FROM ce_skill_versions WHERE skill_id = ? AND is_active = 1 LIMIT 1', [$skillId]);
    $base = $active ? ce_skill_version_load((int) $active['id']) : null;
    $uid = ce_lab_skill_brand(['skey' => $sk['skey']]);
    $brand = $uid ? ('براند #' . $uid) : 'البراند التجريبي';
    $ids = [];
    foreach (['ce_ideation' => 'post', 'ce_writer' => 'carousel', 'ce_critic' => 'post'] as $stage => $format) {
        $in = ce_lab_input($stage, $uid, $format);
        $expected = null;
        if ($stage === 'ce_critic') {
            $r = ce_lab_call($stage, ce_lab_system($stage, null, $in['vars']) . ce_skill_block($stage, $base), $in['user'], $in['images']);
            if (!$r['ok']) return ['ok' => false, 'error' => 'الموديل مارجّعش رد عشان نحفظه كمتوقع: ' . $r['error']];
            $expected = $r['data'];
        }
        $r = ce_lab_case_save($stage, ['name' => $sk['name'] . ' — ' . CE_PROMPT_STAGES[$stage] . ' (' . $brand . ')', 'user' => $in['user'], 'vars' => $in['vars'], 'images' => $in['images'],
                                       'meta' => $in['meta'], 'min_score' => $stage === 'ce_critic' ? 50 : 60, 'expected' => $expected, 'skill_id' => $skillId], $adminId);
        if (!$r['ok']) return $r;
        $ids[] = $r['id'];
    }
    return ['ok' => true, 'ids' => $ids, 'brand_uid' => $uid];
}

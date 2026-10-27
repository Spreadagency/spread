<?php
/**
 * Spread AI — محرك المحتوى v3 · بريف الحملة (Wizard) + تقدير التكلفة (المرحلة D)
 *
 * الـ Wizard كله اختيارات (الكتابة الحرة بس تحت «شيء آخر» وفي الاستثناءات الإضافية).
 * الخدمات والعملاء والشخصيات جايين من الـ Brand DNA.
 * آخر خطوة بتعرض التقدير: التوكنز + الكريدت (للعميل) + الدولار (للأدمن بس).
 *
 * التقدير لكل مرحلة = متوسط توكنز آخر 50 تشغيل ناجح للمرحلة دي (لو فيه 5 على الأقل)، وإلا الافتراضي من البريف
 * × عدد القطع × معامل إعادة الكتابة (1.4 للكاتب والناقد) — والسعر من موديل المرحلة في «خريطة المراحل».
 */
require_once __DIR__ . '/ce-jobs.php';
require_once __DIR__ . '/ce-dna.php';

const CE_GOALS = [
    'new_product' => ['منتج أو خدمة جديدة', '🆕'], 'offer' => ['عرض وخصم', '🎁'], 'sales' => ['زيادة المبيعات', '📈'],
    'awareness' => ['زيادة الوعي بالبراند', '📣'], 'seasonal' => ['موسم أو مناسبة', '🗓'], 'educational' => ['محتوى تعليمي', '🎓'],
    'other' => ['شيء آخر', '✍️'],
];
const CE_FORMATS = ['post' => 'بوست', 'carousel' => 'كاروسيل', 'reel' => 'ريلز / فيديو قصير'];
const CE_CHANNELS = ['whatsapp' => ['واتساب', '💬'], 'call' => ['مكالمة', '📞'], 'booking' => ['حجز أونلاين', '🗓'], 'messages' => ['رسايل الصفحة', '✉️']];
const CE_BOLDNESS = ['safe' => ['آمن', 'كلام هادي ومضمون — مفيش أي مخاطرة'], 'medium' => ['متوسط', 'Hooks لافتة من غير مبالغة'], 'bold' => ['جريء', 'زوايا صادمة وأسلوب قوي — بحدود الالتزام']];
const CE_OFFER_TYPES = ['percent' => 'خصم بنسبة %', 'amount' => 'خصم مبلغ (جنيه)', 'gift' => 'هدية أو خدمة مجانية', 'bundle' => 'باقة بسعر خاص'];
const CE_OFFER_CONDITIONS = ['first_visit' => 'لأول زيارة', 'online' => 'للحجز أونلاين بس', 'limited' => 'لعدد محدود', 'family' => 'للعيلة أو الأصحاب', 'other' => 'شيء آخر'];
const CE_EXCLUSIONS = ['الأسعار', 'مقارنة بالمنافسين', 'صور قبل وبعد', 'الإيموجي', 'الضحك والهزار', 'وعود بنتيجة', 'الكلام عن الخوف والألم', 'صور أشخاص'];
const CE_DAYS = [7, 14, 21, 30, 45, 60];

/** الافتراضي لكل قطعة محتوى (من البريف: 4.2) — الأدمن يقدر يغيّره من إعدادات المحرك */
const CE_TOKEN_DEFAULTS = [
    'ce_ideation' => [2500, 800], 'ce_selection' => [3000, 500], 'ce_writer' => [3500, 1200], 'ce_critic' => [4000, 1200],
    'ce_design_prompt' => [1500, 400], 'ce_vision_qa' => [2000, 300],
    // مرة واحدة للحملة
    'ce_matrix' => [4500, 4000], 'ce_plan_critic' => [5000, 1200], 'ce_final_qa' => [15000, 2000],
];
const CE_STAGE_LABELS = [
    'ce_matrix' => 'خطة المحتوى', 'ce_plan_critic' => 'مراجعة الخطة', 'ce_ideation' => 'الأفكار', 'ce_selection' => 'اختيار الفكرة',
    'ce_writer' => 'الكتابة', 'ce_critic' => 'مراجعة الجودة', 'ce_design_prompt' => 'برومبت التصميم', 'ce_vision_qa' => 'مراجعة الصورة',
    'ce_final_qa' => 'المراجعة النهائية',
];

/* ═══════════════════ خيارات الـ Wizard ═══════════════════ */

function ce_wizard_options(int $uid): array
{
    $dna = ce_dna_get($uid) ?? ce_dna_trim(ce_dna_clean($uid, ce_dna_from_kit($uid)));
    $chars = [];
    foreach (ce_dna_options($uid)['characters'] as $c) {
        $chars[] = $c + ['label' => $c['type'] === 'real_person' ? 'الدكتور / صاحب النشاط' : 'شخصية البراند'];
    }
    return [
        'goals' => array_map(fn($k, $v) => ['key' => $k, 'label' => $v[0], 'icon' => $v[1]], array_keys(CE_GOALS), CE_GOALS),
        'services' => array_map(fn($s) => ['id' => $s['id'], 'name' => $s['name'], 'priority' => $s['priority'], 'season' => $s['season']], $dna['services']),
        'personas' => array_map(fn($p) => ['id' => $p['id'], 'name' => $p['name'], 'pain' => $p['pain'], 'desires' => $p['desires']], $dna['personas']),
        'characters' => $chars,
        'formats' => CE_FORMATS, 'days' => CE_DAYS,
        'offer_types' => CE_OFFER_TYPES, 'offer_conditions' => CE_OFFER_CONDITIONS,
        'channels' => array_map(fn($k, $v) => ['key' => $k, 'label' => $v[0], 'icon' => $v[1]], array_keys(CE_CHANNELS), CE_CHANNELS),
        'boldness' => array_map(fn($k, $v) => ['key' => $k, 'label' => $v[0], 'hint' => $v[1]], array_keys(CE_BOLDNESS), CE_BOLDNESS),
        'exclusions' => array_values(array_unique(array_merge(CE_EXCLUSIONS, $dna['tone']['dont_words'] ?? []))),
        'max_pieces' => max(1, ce_setting_int('ce_max_pieces', 60)),
        'default_channel' => isset(CE_CHANNELS[$dna['cta']['type'] ?? '']) ? $dna['cta']['type'] : 'whatsapp',
    ];
}

/* ═══════════════════ تنضيف البريف والتحقق منه ═══════════════════ */

/** @return array{0: array, 1: array<string,string>} [البريف النضيف, أخطاء لكل خطوة] */
function ce_brief_clean(int $uid, array $in): array
{
    $opt = ce_wizard_options($uid);
    $err = [];
    $s = fn($v, int $n) => mb_substr(trim(strip_tags((string) (is_scalar($v) ? $v : ''))), 0, $n);

    $goal = isset(CE_GOALS[$in['goal'] ?? '']) ? $in['goal'] : '';
    if ($goal === '') $err['goal'] = 'اختار هدف الحملة';
    $goalOther = $goal === 'other' ? $s($in['goal_other'] ?? '', 120) : '';
    if ($goal === 'other' && $goalOther === '') $err['goal'] = 'اكتب الهدف في «شيء آخر»';

    $svcIds = array_map(fn($x) => (int) $x['id'], $opt['services']);
    $allSvc = !empty($in['all_services']);
    $services = $allSvc ? [] : array_values(array_unique(array_filter(array_map('intval', (array) ($in['service_ids'] ?? [])), fn($i) => in_array($i, $svcIds, true))));
    if (!$allSvc && !$services) $err['services'] = 'اختار خدمة أو اتنين (أو «كل الخدمات»)';
    if (count($services) > 2) $err['services'] = 'خدمتين بالكتير — أو اختار «كل الخدمات»';

    $perIds = array_map(fn($x) => (int) $x['id'], $opt['personas']);
    $personas = array_values(array_unique(array_filter(array_map('intval', (array) ($in['persona_ids'] ?? [])), fn($i) => in_array($i, $perIds, true))));
    if ($perIds && !$personas) $err['personas'] = 'اختار عميل واحد على الأقل';

    $chIds = array_map(fn($x) => (int) $x['id'], $opt['characters']);
    $chId = (int) ($in['character_id'] ?? 0);
    $character = in_array($chId, $chIds, true) ? $chId : 0;

    $formats = [];
    foreach (array_keys(CE_FORMATS) as $f) $formats[$f] = max(0, min(30, (int) ($in['formats'][$f] ?? 0)));
    $pieces = array_sum($formats);
    if ($pieces < 1) $err['formats'] = 'حدد عدد قطعة واحدة على الأقل';
    if ($pieces > $opt['max_pieces']) $err['formats'] = 'أقصى عدد في الحملة الواحدة ' . $opt['max_pieces'] . ' قطعة';
    $days = in_array((int) ($in['days'] ?? 0), CE_DAYS, true) ? (int) $in['days'] : 30;

    $offer = null;
    if ($goal === 'offer') {
        $o = (array) ($in['offer'] ?? []);
        $type = isset(CE_OFFER_TYPES[$o['type'] ?? '']) ? $o['type'] : '';
        $value = in_array($type, ['percent', 'amount'], true) ? max(0, min($type === 'percent' ? 90 : 1000000, (int) ($o['value'] ?? 0))) : 0;
        $what = in_array($type, ['gift', 'bundle'], true) ? $s($o['what'] ?? '', 120) : '';
        $cond = isset(CE_OFFER_CONDITIONS[$o['condition'] ?? '']) ? $o['condition'] : '';
        $condOther = $cond === 'other' ? $s($o['condition_other'] ?? '', 120) : '';
        $odays = in_array((int) ($o['days'] ?? 0), CE_DAYS, true) ? (int) $o['days'] : $days;
        if ($type === '') $err['offer'] = 'اختار نوع العرض';
        elseif (in_array($type, ['percent', 'amount'], true) && $value <= 0) $err['offer'] = 'حدد قيمة العرض';
        elseif (in_array($type, ['gift', 'bundle'], true) && $what === '') $err['offer'] = 'اكتب الهدية أو الباقة';
        elseif ($cond === 'other' && $condOther === '') $err['offer'] = 'اكتب شرط العرض في «شيء آخر»';
        $offer = ['type' => $type, 'value' => $value, 'what' => $what, 'days' => $odays, 'condition' => $cond, 'condition_other' => $condOther];
    }

    $channel = isset(CE_CHANNELS[$in['channel'] ?? '']) ? $in['channel'] : '';
    if ($channel === '') $err['channel'] = 'اختار هيتواصلوا معاك إزاي';
    $bold = isset(CE_BOLDNESS[$in['boldness'] ?? '']) ? $in['boldness'] : '';
    if ($bold === '') $err['boldness'] = 'اختار مستوى الجرأة';

    $excl = [];
    foreach ((array) ($in['exclusions'] ?? []) as $x) {
        $x = $s($x, 60);
        if ($x !== '' && !in_array($x, $excl, true)) $excl[] = $x;
        if (count($excl) >= 12) break;
    }

    return [[
        'goal' => $goal, 'goal_other' => $goalOther, 'all_services' => $allSvc, 'service_ids' => $services, 'persona_ids' => $personas,
        'character_id' => $character, 'formats' => $formats, 'days' => $days, 'offer' => $offer, 'channel' => $channel,
        'boldness' => $bold, 'exclusions' => $excl,
    ], $err];
}

/** عنوان تلقائي للحملة من البريف */
function ce_brief_title(int $uid, array $b): string
{
    $opt = ce_wizard_options($uid);
    $g = $b['goal'] === 'other' ? $b['goal_other'] : (CE_GOALS[$b['goal']][0] ?? 'حملة');
    $names = [];
    foreach ($opt['services'] as $s) if (in_array((int) $s['id'], $b['service_ids'], true)) $names[] = $s['name'];
    return mb_substr($g . ($b['all_services'] ? ' — كل الخدمات' : ($names ? ' — ' . implode(' و', $names) : '')), 0, 190);
}

/* ═══════════════════ التقدير ═══════════════════ */

/** متوسط آخر 50 تشغيل ناجح للمرحلة (مش sandbox) — null لو أقل من 5 */
function ce_stage_history(string $task): ?array
{
    try {
        $r = db_one('SELECT COUNT(*) n, AVG(tokens_in) i, AVG(tokens_out) o FROM (
                       SELECT tokens_in, tokens_out FROM ai_runs WHERE task = ? AND status = "ok" AND COALESCE(sandbox, 0) = 0 AND tokens_in > 0
                       ORDER BY id DESC LIMIT 50) t', [$task]);
    } catch (\Throwable $e) {
        return null;
    }
    return $r && (int) $r['n'] >= 5 ? ['in' => (int) round((float) $r['i']), 'out' => (int) round((float) $r['o']), 'n' => (int) $r['n']] : null;
}

/** الافتراضيات (الأدمن يقدر يعدّلها من إعدادات المحرك ← ce_token_defaults) */
function ce_token_defaults(): array
{
    $d = CE_TOKEN_DEFAULTS;
    $over = json_decode((string) get_setting('ce_token_defaults', ''), true);
    if (is_array($over)) foreach ($over as $k => $v) if (isset($d[$k]) && is_array($v) && count($v) === 2) $d[$k] = [max(0, (int) $v[0]), max(0, (int) $v[1])];
    return $d;
}

/** سعر موديل المرحلة (أول موديل في سلسلتها) — ولو مش متسعّر، سعر احتياطي من الإعدادات */
function ce_stage_price(string $task): array
{
    require_once __DIR__ . '/ai-gateway.php';
    require_once __DIR__ . '/usage.php';
    $model = null;
    $prov = null;
    try {
        $chain = function_exists('ai_gw_chain') ? ai_gw_chain($task) : [];
        if ($chain) { $prov = (string) $chain[0]['p']['name']; $model = (string) $chain[0]['m']; }
    } catch (\Throwable $e) {}
    $in = $model ? usage_price($prov, $model, 'in_1m') : null;
    $out = $model ? usage_price($prov, $model, 'out_1m') : null;
    return [
        'model' => $model, 'provider' => $prov, 'priced' => $in !== null && $out !== null,
        'in_1m' => $in['price_usd'] ?? ce_setting_float('ce_fallback_in_1m', 3.0),
        'out_1m' => $out['price_usd'] ?? ce_setting_float('ce_fallback_out_1m', 15.0),
    ];
}

function ce_image_price(): array
{
    require_once __DIR__ . '/ai-gateway.php';
    require_once __DIR__ . '/usage.php';
    $model = null;
    $prov = null;
    try {
        $chain = function_exists('ai_gw_chain') ? ai_gw_chain('design') : [];
        if ($chain) { $prov = (string) $chain[0]['p']['name']; $model = (string) $chain[0]['m']; }
    } catch (\Throwable $e) {}
    $p = $model ? usage_price($prov, $model, 'image') : null;
    return ['model' => $model, 'priced' => $p !== null, 'usd' => $p['price_usd'] ?? ce_setting_float('ce_fallback_image', 0.04)];
}

/**
 * تقدير حملة من البريف.
 * @return array pieces · stages[] · images · tokens_in/out/total · usd · credits · assumptions[]
 */
function ce_estimate(array $brief): array
{
    $f = $brief['formats'] ?? [];
    $post = (int) ($f['post'] ?? 0);
    $car = (int) ($f['carousel'] ?? 0);
    $reel = (int) ($f['reel'] ?? 0);
    $pieces = $post + $car + $reel;
    $visual = $post + $car;               // القطع اللي ليها تصميم (الريلز سكريبت بس)
    $slides = 4;                          // متوسط شرائح الكاروسيل
    $images = $post + $car * $slides;
    $rf = max(1.0, ce_setting_float('ce_revision_factor', 1.4));
    $def = ce_token_defaults();

    $plan = [
        'ce_matrix' => 1, 'ce_plan_critic' => 1,
        'ce_ideation' => $pieces, 'ce_selection' => $pieces, 'ce_writer' => $pieces * $rf, 'ce_critic' => $pieces * $rf,
        'ce_design_prompt' => $visual, 'ce_vision_qa' => $visual,
        'ce_final_qa' => 1,
    ];
    $stages = [];
    $tin = 0;
    $tout = 0;
    $usd = 0.0;
    $unpriced = 0;
    foreach ($plan as $task => $count) {
        if ($count <= 0) continue;
        $h = ce_stage_history($task);
        [$i, $o] = $h ? [$h['in'], $h['out']] : $def[$task];
        $price = ce_stage_price($task);
        if (!$price['priced']) $unpriced++;
        $si = (int) round($i * $count);
        $so = (int) round($o * $count);
        $su = $si / 1e6 * $price['in_1m'] + $so / 1e6 * $price['out_1m'];
        $stages[] = ['key' => $task, 'label' => CE_STAGE_LABELS[$task] ?? $task, 'count' => round($count, 1), 'in' => $si, 'out' => $so,
                     'source' => $h ? 'history' : 'default', 'model' => $price['model'], 'priced' => $price['priced'], 'usd' => round($su, 4)];
        $tin += $si;
        $tout += $so;
        $usd += $su;
    }
    $img = ce_image_price();
    $imgUsd = $images * $img['usd'];
    $usd += $imgUsd;

    $credits = max(ce_usd_to_credits($usd), $pieces * max(0, ce_setting_int('ce_min_credits_piece', 1)));
    $assume = [
        'الكاتب والناقد × ' . $rf . ' (إعادة كتابة محتملة)',
        'الكاروسيل ' . $slides . ' شرائح في المتوسط',
        'الريلز: سكريبت من غير تصميم صورة',
    ];
    if ($unpriced) $assume[] = $unpriced . ' مرحلة موديلها مش متسعّر — اتحسبت بالسعر الاحتياطي';
    if ($images && !$img['priced']) $assume[] = 'موديل الصور مش متسعّر — اتحسب بالسعر الاحتياطي';
    return [
        'pieces' => $pieces, 'stages' => $stages,
        'images' => ['count' => $images, 'usd' => round($imgUsd, 4), 'model' => $img['model'], 'priced' => $img['priced']],
        'tokens_in' => $tin, 'tokens_out' => $tout, 'tokens_total' => $tin + $tout,
        'usd' => round($usd, 4), 'credits' => $credits, 'assumptions' => $assume, 'at' => date('Y-m-d H:i'),
    ];
}

/** التقدير للعميل (من غير دولار ولا أسماء موديلات) */
function ce_estimate_public(array $e): array
{
    return [
        'pieces' => $e['pieces'], 'tokens_total' => $e['tokens_total'], 'tokens_in' => $e['tokens_in'], 'tokens_out' => $e['tokens_out'],
        'credits' => $e['credits'], 'images' => $e['images']['count'],
        'stages' => array_map(fn($s) => ['label' => $s['label'], 'tokens' => $s['in'] + $s['out']], $e['stages']),
    ];
}

/* ═══════════════════ الحملات ═══════════════════ */

function ce_campaign_get(int $id, int $uid): ?array
{
    $c = db_one('SELECT * FROM ce_campaigns WHERE id = ? AND user_id = ?', [$id, $uid]);
    return $c ?: null;
}

function ce_campaign_public(array $c, bool $admin = false): array
{
    $e = json_decode((string) ($c['estimate_json'] ?? ''), true) ?: null;
    return [
        'id' => (int) $c['id'], 'title' => $c['title'], 'status' => $c['status'], 'goal' => $c['goal'],
        'brief' => json_decode((string) ($c['brief_json'] ?? ''), true) ?: null,
        'estimate' => $e ? ($admin ? $e : ce_estimate_public($e)) : null,
        'est_credits' => (int) $c['est_credits'], 'created_at' => $c['created_at'], 'updated_at' => $c['updated_at'],
    ];
}

/** حفظ البريف (جديد أو تعديل مسودة) + التقدير */
function ce_campaign_save(int $uid, array $in, int $id = 0): array
{
    [$brief, $err] = ce_brief_clean($uid, $in);
    $partial = !empty($in['partial']); // حفظ مسودة في النص: الأخطاء مابتمنعش الحفظ
    if ($err && !$partial) return ['ok' => false, 'errors' => $err];
    $est = $err ? null : ce_estimate($brief);
    $dna = ce_dna_row($uid);
    $row = [
        ce_brief_title($uid, $brief), $err ? 'draft' : 'ready', $brief['goal'] ?: null, json_encode($brief, JSON_UNESCAPED_UNICODE),
        $est ? json_encode($est, JSON_UNESCAPED_UNICODE) : null, (int) ($est['credits'] ?? 0), $dna ? (int) $dna['version'] : null,
    ];
    if ($id) {
        $c = ce_campaign_get($id, $uid);
        if (!$c) return ['ok' => false, 'errors' => ['_' => 'الحملة مش موجودة']];
        if (!in_array($c['status'], ['draft', 'ready'], true)) return ['ok' => false, 'errors' => ['_' => 'الحملة بدأت خلاص — مينفعش تتعدّل']];
        db_run('UPDATE ce_campaigns SET title = ?, status = ?, goal = ?, brief_json = ?, estimate_json = ?, est_credits = ?, dna_version = ? WHERE id = ? AND user_id = ?',
            array_merge($row, [$id, $uid]));
    } else {
        $id = (int) db_insert('INSERT INTO ce_campaigns (title, status, goal, brief_json, estimate_json, est_credits, dna_version, user_id) VALUES (?,?,?,?,?,?,?,?)',
            array_merge($row, [$uid]));
    }
    return ['ok' => true, 'campaign' => ce_campaign_get($id, $uid), 'errors' => $err];
}

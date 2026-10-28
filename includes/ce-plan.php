<?php
/**
 * Spread AI — محرك المحتوى v3 · خطة المحتوى (Matrix) + بوابة الخطة (المرحلة E)
 *
 * الـ AI بيبني الخطة (ce_matrix) ← الكود بيظبطها ويتأكد من القواعد الثابتة ← الناقد بيقيّمها (ce_plan_critic)
 * ← لو أقل من 80: إعادة بناء بملاحظات الناقد (لحد جولتين) ← العميل يراجع (يرتّب · يقفل · يعدّل) ويعتمد.
 *
 * القواعد الثابتة (بتتشيك في الكود — مش بالـ AI بس):
 *   - مفيش خانتين ورا بعض بنفس العمود (pillar) أو نفس الزاوية (angle)
 *   - كل خدمة مختارة ليها خانتين على الأقل · كل عميل مختار ليه خانة على الأقل (لو العدد يسمح)
 *   - الـ CTA القوي (hard) في آخر 30% من الحملة بس
 *   - عدد كل شكل (بوست/كاروسيل/ريلز) زي البريف بالظبط
 * الدرجة النهائية لكل معيار = الأقل بين درجة الكود ودرجة الناقد (الكود بيقيس اللي يتقاس، والناقد بيحكم على الباقي).
 */
require_once __DIR__ . '/ce-campaign.php';

ce_job_register('campaign_plan', 'ce_plan_job', 'خطة الحملة');

const CE_PILLARS = ['education' => 'تعليم وتوعية', 'proof' => 'إثبات وثقة', 'story' => 'قصة ومشاعر', 'offer' => 'عرض ودعوة', 'behind' => 'كواليس'];
const CE_FUNNELS = ['tofu' => 'وعي', 'mofu' => 'اهتمام', 'bofu' => 'قرار'];
const CE_CTA_LEVELS = ['soft' => 'خفيف', 'medium' => 'متوسط', 'hard' => 'قوي'];
const CE_FUNNEL_MIX = [
    'awareness' => [50, 35, 15], 'educational' => [60, 30, 10], 'new_product' => [35, 40, 25],
    'sales' => [20, 35, 45], 'offer' => [15, 30, 55], 'seasonal' => [30, 30, 40], 'other' => [35, 35, 30],
];
/** الأعمدة المناسبة لكل مرحلة (بالترتيب) — للبناء بالقواعد والتصليح */
const CE_PILLAR_BY_FUNNEL = ['tofu' => ['education', 'story', 'behind', 'proof'], 'mofu' => ['proof', 'education', 'behind', 'story'], 'bofu' => ['offer', 'proof', 'story']];
const CE_PLAN_WEIGHTS = ['balance' => 15, 'non_repetition' => 20, 'coverage' => 20, 'sequence' => 15, 'cta' => 15, 'goal_fit' => 15];
const CE_PLAN_DIMS = ['balance' => 'التوازن', 'non_repetition' => 'عدم التكرار', 'coverage' => 'التغطية', 'sequence' => 'التسلسل', 'cta' => 'الـ CTA', 'goal_fit' => 'مناسبة الهدف'];
const CE_HARD_CTA_GOALS = ['sales', 'offer', 'new_product', 'seasonal'];

/* ═══════════════════ الإعدادات ═══════════════════ */

function ce_funnel_mix(string $goal): array
{
    $all = CE_FUNNEL_MIX;
    $over = json_decode((string) get_setting('ce_funnel_mix', ''), true);
    if (is_array($over)) foreach ($over as $g => $v) {
        if (isset($all[$g]) && is_array($v) && count($v) === 3 && array_sum(array_map('intval', $v)) === 100) $all[$g] = array_map('intval', $v);
    }
    return array_combine(['tofu', 'mofu', 'bofu'], $all[$goal] ?? $all['other']);
}

function ce_plan_weights(): array
{
    $w = CE_PLAN_WEIGHTS;
    $over = json_decode((string) get_setting('ce_plan_weights', ''), true);
    if (is_array($over) && array_sum(array_map('intval', array_intersect_key($over, $w))) === 100) {
        foreach ($w as $k => $_) $w[$k] = max(0, (int) ($over[$k] ?? $w[$k]));
    }
    return $w;
}

function ce_plan_threshold(): float
{
    return max(0, min(100, ce_setting_float('ce_plan_threshold', 80)));
}

/** مكتبة الزوايا (المفتاح ← الصف) */
function ce_angles(bool $activeOnly = true): array
{
    $out = [];
    try {
        foreach (db_all('SELECT * FROM ce_angles' . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY sort_order, id') as $a) {
            $a['pillar_list'] = array_values(array_filter(array_map('trim', explode(',', (string) $a['pillars']))));
            $out[$a['akey']] = $a;
        }
    } catch (\Throwable $e) {}
    return $out;
}

/* ═══════════════════ سياق الخطة من البريف ═══════════════════ */

function ce_plan_ctx(int $uid, array $c): array
{
    $b = json_decode((string) $c['brief_json'], true) ?: [];
    $dna = ce_dna_get($uid) ?? ce_dna_trim(ce_dna_clean($uid, ce_dna_from_kit($uid)));
    $allSvc = array_map(fn($s) => (int) $s['id'], $dna['services']);
    $services = !empty($b['all_services']) ? $allSvc : array_values(array_intersect(array_map('intval', $b['service_ids'] ?? []), $allSvc));
    if (!$services) $services = array_slice($allSvc, 0, 2);
    $allPer = array_map(fn($p) => (int) $p['id'], $dna['personas']);
    $personas = array_values(array_intersect(array_map('intval', $b['persona_ids'] ?? []), $allPer)) ?: $allPer;
    $formats = [];
    foreach (array_keys(CE_FORMATS) as $f) $formats[$f] = max(0, (int) ($b['formats'][$f] ?? 0));
    $n = array_sum($formats);
    $start = $c['start_date'] ?? null;
    return [
        'uid' => $uid, 'campaign_id' => (int) $c['id'], 'brief' => $b, 'dna' => $dna, 'goal' => (string) ($b['goal'] ?? 'other'),
        'n' => $n, 'formats' => $formats, 'days' => max(1, (int) ($b['days'] ?? 30)),
        'start' => $start ?: date('Y-m-d', strtotime('+1 day')),
        'services' => $services, 'personas' => $personas, 'mix' => ce_funnel_mix((string) ($b['goal'] ?? 'other')),
        'angles' => ce_angles(),
        'svc_names' => array_column(array_map(fn($s) => [$s['id'], $s['name']], $dna['services']), 1, 0),
        'per_names' => array_column(array_map(fn($p) => [$p['id'], $p['name']], $dna['personas']), 1, 0),
        'svc_priority' => array_column(array_map(fn($s) => [$s['id'], $s['priority']], $dna['services']), 1, 0),
    ];
}

/** عدد الخانات لكل مرحلة من النسب (أكبر باقي) */
function ce_funnel_counts(int $n, array $mix): array
{
    $raw = [];
    foreach ($mix as $k => $p) $raw[$k] = $n * $p / 100;
    $out = array_map('floor', $raw);
    $left = $n - array_sum($out);
    $rem = $raw;
    foreach ($rem as $k => $v) $rem[$k] = $v - floor($v);
    arsort($rem);
    foreach (array_keys($rem) as $k) { if ($left <= 0) break; $out[$k]++; $left--; }
    return array_map('intval', $out);
}

/** أقل عدد خانات مطلوب لكل خدمة/عميل (بيقل لو العدد مش مكفّي) */
function ce_plan_mins(array $ctx): array
{
    $ns = count($ctx['services']);
    $np = count($ctx['personas']);
    return [
        'service' => $ns ? min(2, intdiv($ctx['n'], $ns)) : 0,
        'persona' => $np && $ctx['n'] >= $np ? 1 : 0,
    ];
}

function ce_plan_tail_start(int $n): int
{
    return $n - (int) max(1, ceil($n * 0.3));
}

/* ═══════════════════ البناء بالقواعد (احتياطي + تكميل) ═══════════════════ */

/** خطة كاملة بالكود بس — بتراعي كل القواعد. الخانات المقفولة بتفضل في مكانها زي ما هي. */
function ce_plan_build_rules(array $ctx, array $locked = []): array
{
    $n = $ctx['n'];
    $fc = ce_funnel_counts($n, $ctx['mix']);
    $funnels = array_merge(array_fill(0, $fc['tofu'], 'tofu'), array_fill(0, $fc['mofu'], 'mofu'), array_fill(0, $fc['bofu'], 'bofu'));
    // الأشكال متوزعة بالتناوب على قد نسبة كل شكل
    $fleft = $ctx['formats'];
    $formats = [];
    for ($i = 0; $i < $n; $i++) {
        arsort($fleft);
        $k = array_key_first(array_filter($fleft, fn($v) => $v > 0));
        $formats[] = $k ?? 'post';
        if ($k) $fleft[$k]--;
    }
    // الخدمات: الأعلى أولوية الأول
    $svc = $ctx['services'];
    usort($svc, fn($a, $b) => array_search($ctx['svc_priority'][$a] ?? 'medium', ['high', 'medium', 'low']) <=> array_search($ctx['svc_priority'][$b] ?? 'medium', ['high', 'medium', 'low']));
    $slots = [];
    for ($i = 0; $i < $n; $i++) {
        $slots[$i] = $locked[$i] ?? [
            'position' => $i, 'funnel' => $funnels[$i], 'pillar' => CE_PILLAR_BY_FUNNEL[$funnels[$i]][0],
            'service_id' => $svc ? $svc[$i % count($svc)] : null, 'persona_id' => $ctx['personas'] ? $ctx['personas'][$i % count($ctx['personas'])] : null,
            'format' => $formats[$i], 'angle' => '', 'cta_level' => $funnels[$i] === 'tofu' ? 'soft' : 'medium', 'objective' => '',
            'locked' => false, 'source' => 'rules',
        ];
    }
    return ce_plan_repair($slots, $ctx);
}

/* ═══════════════════ تحويل رد الـ AI لخانات ═══════════════════ */

function ce_plan_normalize(array $raw, array $ctx, array $locked = []): array
{
    $byName = fn(array $names, $v) => is_numeric($v) && isset($names[(int) $v]) ? (int) $v
        : (array_search(trim((string) $v), array_map('trim', $names), true) ?: null);
    $angleKey = function ($v) use ($ctx) {
        $v = trim((string) $v);
        if (isset($ctx['angles'][$v])) return $v;
        foreach ($ctx['angles'] as $k => $a) if ($a['label'] === $v) return $k;
        return '';
    };
    $pillarKey = function ($v) {
        $v = strtolower(trim((string) $v));
        if (isset(CE_PILLARS[$v])) return $v;
        $map = ['trust' => 'proof', 'emotion' => 'story', 'behind_the_scenes' => 'behind', 'invitation' => 'offer', 'educational' => 'education'];
        if (isset($map[$v])) return $map[$v];
        $k = array_search($v, CE_PILLARS, true);
        return $k ?: '';
    };
    $out = [];
    $i = 0;
    foreach ($raw as $r) {
        if (!is_array($r)) continue;
        while (isset($locked[$i])) { $out[$i] = $locked[$i]; $i++; }
        if ($i >= $ctx['n']) break;
        $f = strtolower((string) ($r['funnel_stage'] ?? $r['funnel'] ?? ''));
        $fmt = strtolower((string) ($r['format'] ?? ''));
        $cta = strtolower((string) ($r['cta_level'] ?? ''));
        $out[$i] = [
            'position' => $i,
            'funnel' => isset(CE_FUNNELS[$f]) ? $f : 'tofu',
            'pillar' => $pillarKey($r['pillar'] ?? ''),
            'service_id' => $byName($ctx['svc_names'], $r['service_id'] ?? $r['service'] ?? ''),
            'persona_id' => $byName($ctx['per_names'], $r['persona_id'] ?? $r['persona'] ?? ''),
            'format' => isset(CE_FORMATS[$fmt]) ? $fmt : ($fmt === 'video' ? 'reel' : ''),
            'angle' => $angleKey($r['angle'] ?? ''),
            'cta_level' => isset(CE_CTA_LEVELS[$cta]) ? $cta : 'soft',
            'objective' => mb_substr(trim(strip_tags((string) ($r['objective'] ?? ''))), 0, 240),
            'locked' => false, 'source' => 'ai',
        ];
        $i++;
    }
    for (; $i < $ctx['n']; $i++) {
        $out[$i] = $locked[$i] ?? ['position' => $i, 'funnel' => 'mofu', 'pillar' => '', 'service_id' => null, 'persona_id' => null, 'format' => '',
            'angle' => '', 'cta_level' => 'soft', 'objective' => '', 'locked' => false, 'source' => 'rules'];
    }
    ksort($out);
    return array_values($out);
}

/* ═══════════════════ التصليح بالكود ═══════════════════ */

/**
 * بيظبط الخطة على القواعد الثابتة من غير ما يلمس الخانات المقفولة:
 * الأشكال ← المراحل بالترتيب ← تغطية الخدمات والعملاء ← العمود والزاوية مايتكرروش ورا بعض ← مكان الـ CTA القوي ← التواريخ
 */
function ce_plan_repair(array $slots, array $ctx): array
{
    $n = count($slots);
    if (!$n) return $slots;
    $free = fn(int $i) => empty($slots[$i]['locked']);

    // 1) الأشكال: نفس عدد البريف بالظبط
    $need = $ctx['formats'];
    foreach ($slots as $s) if (isset($need[$s['format']]) && $need[$s['format']] > 0) $need[$s['format']]--;
    $over = [];
    $have = array_fill_keys(array_keys(CE_FORMATS), 0);
    foreach ($slots as $i => $s) {
        $f = $s['format'];
        if (!isset($have[$f]) || $have[$f] >= $ctx['formats'][$f]) { $over[] = $i; continue; }
        $have[$f]++;
    }
    foreach ($over as $i) {
        if (!$free($i)) continue;
        $k = array_key_first(array_filter($need, fn($v) => $v > 0));
        if ($k === null) break;
        $slots[$i]['format'] = $k;
        $need[$k]--;
    }

    // 2) المراحل: وعي ← اهتمام ← قرار بالترتيب (الخانات الحرة بس) + العدد قريب من النسب
    $freeIdx = array_values(array_filter(array_keys($slots), $free));
    $rank = ['tofu' => 0, 'mofu' => 1, 'bofu' => 2];
    $target = ce_funnel_counts($n, $ctx['mix']);
    foreach ($slots as $s) if (!empty($s['locked'])) $target[$s['funnel']] = max(0, $target[$s['funnel']] - 1);
    $seq = array_merge(array_fill(0, $target['tofu'], 'tofu'), array_fill(0, $target['mofu'], 'mofu'), array_fill(0, $target['bofu'], 'bofu'));
    $freeSlots = array_map(fn($i) => $slots[$i], $freeIdx);
    usort($freeSlots, fn($a, $b) => [$rank[$a['funnel']], $a['position']] <=> [$rank[$b['funnel']], $b['position']]);
    foreach ($freeIdx as $k => $i) {
        $s = $freeSlots[$k];
        $s['funnel'] = $seq[$k] ?? $s['funnel'];
        $slots[$i] = $s;
    }

    // 3) تغطية الخدمات والعملاء
    $mins = ce_plan_mins($ctx);
    foreach ([['service_id', $ctx['services'], $mins['service']], ['persona_id', $ctx['personas'], $mins['persona']]] as [$field, $pool, $min]) {
        if (!$pool) continue;
        foreach ($slots as $i => $s) if ($free($i) && !in_array($s[$field], $pool, true)) $slots[$i][$field] = null;
        // closure بمرجع: الـ arrow function بتاخد نسخة من $slots وقت تعريفها، فالعدّ كان بيفضل قديم
        $count = function () use (&$slots, $field) { return array_count_values(array_map('strval', array_filter(array_column($slots, $field), fn($v) => $v !== null))); };
        foreach ($slots as $i => $s) {
            if ($s[$field] !== null || !$free($i)) continue;
            $c = $count();
            usort($pool, fn($a, $b) => ($c[(string) $a] ?? 0) <=> ($c[(string) $b] ?? 0));
            $slots[$i][$field] = $pool[0];
        }
        for ($guard = 0; $guard < $n * 2; $guard++) {
            $c = $count();
            $under = array_values(array_filter($pool, fn($x) => ($c[(string) $x] ?? 0) < $min));
            if (!$under) break;
            arsort($c);
            $donor = null;
            foreach (array_keys($c) as $d) if ($c[$d] > $min) { $donor = (int) $d; break; }
            if ($donor === null) break;
            $moved = false;
            for ($i = $n - 1; $i >= 0; $i--) {
                if ($free($i) && (int) $slots[$i][$field] === $donor) { $slots[$i][$field] = $under[0]; $moved = true; break; }
            }
            if (!$moved) break;
        }
    }

    // 4) العمود والزاوية: مايتكرروش ورا بعض
    $angles = $ctx['angles'];
    $angleUse = [];
    foreach ($slots as $s) if ($s['angle'] !== '') $angleUse[$s['angle']] = ($angleUse[$s['angle']] ?? 0) + 1;
    for ($i = 0; $i < $n; $i++) {
        $prev = $slots[$i - 1] ?? null;
        $next = $slots[$i + 1] ?? null;
        $s = &$slots[$i];
        if ($free($i) && (!isset(CE_PILLARS[$s['pillar']]) || ($prev && $s['pillar'] === $prev['pillar']) || ($next && !empty($next['locked']) && $s['pillar'] === $next['pillar']))) {
            $opts = array_merge(CE_PILLAR_BY_FUNNEL[$s['funnel']], array_keys(CE_PILLARS));
            foreach ($opts as $p) if ((!$prev || $p !== $prev['pillar']) && (!$next || empty($next['locked']) || $p !== $next['pillar'])) { $s['pillar'] = $p; break; }
        }
        $fits = fn($k) => !$angles[$k]['pillar_list'] || in_array($s['pillar'], $angles[$k]['pillar_list'], true);
        // الزاوية مستخدمة أكتر من نصيبها العادل في الحملة ← بنبدّلها بأقل زاوية مستخدمة (تنويع)
        $fair = max(1, (int) ceil($n / max(1, count($angles))));
        $bad = !isset($angles[$s['angle']]) || ($prev && $s['angle'] === $prev['angle']) || ($next && !empty($next['locked']) && $s['angle'] === $next['angle'])
            || (($angleUse[$s['angle']] ?? 0) > $fair);
        if ($free($i) && ($bad || !$fits($s['angle']))) {
            $cands = array_filter(array_keys($angles), fn($k) => (!$prev || $k !== $prev['angle']) && (!$next || empty($next['locked']) || $k !== $next['angle']));
            $good = array_values(array_filter($cands, $fits)) ?: array_values($cands);
            usort($good, fn($a, $b) => ($angleUse[$a] ?? 0) <=> ($angleUse[$b] ?? 0));
            if ($good) {
                if ($s['angle'] !== '' && isset($angleUse[$s['angle']])) $angleUse[$s['angle']]--;
                $s['angle'] = $good[0];
                $angleUse[$good[0]] = ($angleUse[$good[0]] ?? 0) + 1;
            }
        }
        unset($s);
    }

    // 5) الـ CTA القوي في آخر 30% بس — وأهداف البيع لازم يبقى فيها واحد على الأقل في الآخر
    $tail = ce_plan_tail_start($n);
    foreach ($slots as $i => $s) {
        if ($free($i) && $s['cta_level'] === 'hard' && $i < $tail) $slots[$i]['cta_level'] = 'medium';
        if ($free($i) && $s['funnel'] === 'tofu' && $s['cta_level'] !== 'soft' && $i < $tail) $slots[$i]['cta_level'] = 'soft';
        if ($free($i) && $s['funnel'] === 'bofu' && $s['cta_level'] === 'soft') $slots[$i]['cta_level'] = 'medium'; // مرحلة القرار من غير دعوة واضحة = خانة ضايعة
    }
    if (in_array($ctx['goal'], CE_HARD_CTA_GOALS, true) && !array_filter(array_slice($slots, $tail), fn($s) => $s['cta_level'] === 'hard')) {
        for ($i = $n - 1; $i >= $tail; $i--) {
            if ($free($i) && $slots[$i]['funnel'] !== 'tofu') { $slots[$i]['cta_level'] = 'hard'; break; }
        }
    }

    // 6) الترتيب والتواريخ والهدف الناقص
    foreach ($slots as $i => &$s) {
        $s['position'] = $i;
        $s['date'] = date('Y-m-d', strtotime($ctx['start'] . ' +' . (int) floor($i * $ctx['days'] / max(1, $n)) . ' day'));
        if (trim((string) $s['objective']) === '') {
            $s['objective'] = mb_substr(CE_PILLARS[$s['pillar']] . ' — ' . ($ctx['angles'][$s['angle']]['label'] ?? '') . ' عن '
                . ($ctx['svc_names'][$s['service_id']] ?? 'الخدمة') . ' لـ ' . ($ctx['per_names'][$s['persona_id']] ?? 'العميل'), 0, 240);
        }
    }
    unset($s);
    return $slots;
}

/* ═══════════════════ التحقق + درجات الكود ═══════════════════ */

/**
 * @return array{issues: array<array{rule:string, slot:?int, msg:string, hard:bool}>, hard:int, code:array<string,float>}
 */
function ce_plan_validate(array $slots, array $ctx): array
{
    $n = count($slots);
    $issues = [];
    $add = function (string $rule, ?int $slot, string $msg, bool $hard = true) use (&$issues) {
        $issues[] = ['rule' => $rule, 'slot' => $slot, 'msg' => $msg, 'hard' => $hard];
    };
    $adj = 0;
    for ($i = 1; $i < $n; $i++) {
        if ($slots[$i]['pillar'] === $slots[$i - 1]['pillar']) { $adj++; $add('adjacent_pillar', $i, 'الخانة ' . ($i + 1) . ' بنفس عمود اللي قبلها (' . (CE_PILLARS[$slots[$i]['pillar']] ?? $slots[$i]['pillar']) . ')'); }
        if ($slots[$i]['angle'] === $slots[$i - 1]['angle']) { $adj++; $add('adjacent_angle', $i, 'الخانة ' . ($i + 1) . ' بنفس زاوية اللي قبلها (' . ($ctx['angles'][$slots[$i]['angle']]['label'] ?? $slots[$i]['angle']) . ')'); }
    }
    foreach ($slots as $i => $s) {
        if (!isset(CE_PILLARS[$s['pillar']])) $add('invalid', $i, 'الخانة ' . ($i + 1) . ': عمود مش معروف');
        if (!isset($ctx['angles'][$s['angle']])) $add('invalid', $i, 'الخانة ' . ($i + 1) . ': زاوية مش في المكتبة');
    }
    $pairs = [];
    $dupPairs = 0;
    foreach ($slots as $s) {
        $k = $s['angle'] . '|' . $s['service_id'] . '|' . $s['persona_id'];
        if (isset($pairs[$k])) $dupPairs++;
        $pairs[$k] = 1;
    }

    // التغطية
    $mins = ce_plan_mins($ctx);
    $req = 0;
    $ok = 0;
    $sc = array_count_values(array_map('strval', array_filter(array_column($slots, 'service_id'), fn($v) => $v !== null)));
    foreach ($ctx['services'] as $sid) {
        if (!$mins['service']) break;
        $req++;
        if (($sc[(string) $sid] ?? 0) >= $mins['service']) $ok++;
        else $add('service_coverage', null, 'خدمة «' . ($ctx['svc_names'][$sid] ?? $sid) . '» ليها أقل من ' . $mins['service'] . ' خانات');
    }
    $pc = array_count_values(array_map('strval', array_filter(array_column($slots, 'persona_id'), fn($v) => $v !== null)));
    foreach ($ctx['personas'] as $pid) {
        if (!$mins['persona']) break;
        $req++;
        if (($pc[(string) $pid] ?? 0) >= $mins['persona']) $ok++;
        else $add('persona_coverage', null, 'العميل «' . ($ctx['per_names'][$pid] ?? $pid) . '» مالوش خانة');
    }

    // الأشكال
    $fmt = array_count_values(array_column($slots, 'format'));
    $fmtOff = 0;
    foreach ($ctx['formats'] as $f => $want) {
        if (($fmt[$f] ?? 0) !== $want) { $fmtOff++; $add('formats', null, (CE_FORMATS[$f] ?? $f) . ': ' . ($fmt[$f] ?? 0) . ' بدل ' . $want); }
    }

    // الـ CTA
    $tail = ce_plan_tail_start($n);
    $hardOut = 0;
    foreach ($slots as $i => $s) if ($s['cta_level'] === 'hard' && $i < $tail) { $hardOut++; $add('cta_position', $i, 'الخانة ' . ($i + 1) . ': CTA قوي قبل آخر 30% من الحملة'); }
    $needHard = in_array($ctx['goal'], CE_HARD_CTA_GOALS, true) && $n >= 3;
    $noHardTail = !array_filter(array_slice($slots, $tail), fn($s) => $s['cta_level'] === 'hard');
    if ($needHard && $noHardTail) $add('cta_missing', null, 'هدف الحملة بيع — ومفيش CTA قوي في آخرها', false);

    // التسلسل: نزول في المرحلة (قرار ← وعي) بيتحسب
    $rank = ['tofu' => 0, 'mofu' => 1, 'bofu' => 2];
    $desc = 0;
    for ($i = 1; $i < $n; $i++) if ($rank[$slots[$i]['funnel']] < $rank[$slots[$i - 1]['funnel']]) $desc++;

    // التوازن: البعد عن نسب المراحل
    $fc = array_count_values(array_column($slots, 'funnel'));
    $dev = 0;
    foreach ($ctx['mix'] as $k => $p) $dev += abs(($n ? ($fc[$k] ?? 0) / $n * 100 : 0) - $p);

    $clamp = fn($v) => (float) max(0, min(100, round($v, 1)));
    $code = [
        'balance' => $clamp(100 - max(0, $dev - 10) * 1.5 - 15 * $fmtOff),   // ±5% لكل مرحلة مسموح
        'non_repetition' => $clamp(100 - 20 * $adj - 5 * $dupPairs),
        'coverage' => $clamp($req ? 100 * $ok / $req : 100),
        'sequence' => $clamp(100 - 25 * $desc),
        'cta' => $clamp(100 - 25 * $hardOut - ($needHard && $noHardTail ? 30 : 0)),
    ];
    return ['issues' => $issues, 'hard' => count(array_filter($issues, fn($x) => $x['hard'])), 'code' => $code];
}

/** الدرجة النهائية: الأقل بين الكود والناقد لكل معيار · مناسبة الهدف من الناقد (أو التوازن لو مفيش ناقد) */
function ce_plan_combine(array $code, ?array $critic): array
{
    $w = ce_plan_weights();
    $final = [];
    foreach (array_keys(CE_PLAN_DIMS) as $d) {
        $c = $code[$d] ?? null;
        $k = $critic !== null && isset($critic[$d]) && is_numeric($critic[$d]) ? max(0, min(100, (float) $critic[$d])) : null;
        if ($d === 'goal_fit') $final[$d] = $k ?? (float) ($code['balance'] ?? 0);
        else $final[$d] = $k === null ? (float) $c : min((float) $c, $k);
    }
    $sum = array_sum($w) ?: 100;
    $score = 0.0;
    foreach ($final as $d => $v) $score += $v * ($w[$d] ?? 0);
    return ['dims' => $final, 'score' => round($score / $sum, 1)];
}

/* ═══════════════════ البرومبتات ═══════════════════ */

function ce_plan_slots_brief(array $slots, array $ctx): string
{
    $lines = [];
    foreach ($slots as $i => $s) {
        $lines[] = ($i + 1) . '. ' . $s['date'] . ' · ' . $s['funnel'] . ' · ' . $s['pillar'] . ' · ' . ($ctx['svc_names'][$s['service_id']] ?? '-') . ' · '
            . ($ctx['per_names'][$s['persona_id']] ?? '-') . ' · ' . $s['format'] . ' · ' . $s['angle'] . ' · CTA ' . $s['cta_level'] . ' · ' . $s['objective'];
    }
    return implode("\n", $lines);
}

function ce_plan_brief_text(array $ctx): string
{
    $b = $ctx['brief'];
    $goal = $b['goal'] === 'other' ? ($b['goal_other'] ?? '') : (CE_GOALS[$b['goal']][0] ?? $b['goal']);
    $offer = '';
    if (!empty($b['offer']['type'])) {
        $o = $b['offer'];
        $offer = 'العرض: ' . (CE_OFFER_TYPES[$o['type']] ?? '') . ' ' . ($o['value'] ?: $o['what']) . ' لمدة ' . $o['days'] . ' يوم · الشرط: '
            . ($o['condition'] === 'other' ? $o['condition_other'] : (CE_OFFER_CONDITIONS[$o['condition']] ?? ''));
    }
    return implode("\n", array_filter([
        'الهدف: ' . $goal, $offer,
        'المدة: ' . $ctx['days'] . ' يوم من ' . $ctx['start'],
        'قناة التواصل: ' . (CE_CHANNELS[$b['channel'] ?? ''][0] ?? ''),
        'مستوى الجرأة: ' . (CE_BOLDNESS[$b['boldness'] ?? ''][0] ?? '') . ' — ' . (CE_BOLDNESS[$b['boldness'] ?? ''][1] ?? ''),
        !empty($b['exclusions']) ? 'ممنوع في الحملة دي: ' . implode('، ', $b['exclusions']) : '',
    ]));
}

function ce_plan_matrix_system(): string
{
    return ce_prompt_sys('ce_matrix');
}

function ce_plan_matrix_user(array $ctx, array $locked, array $fixes): string
{
    $mins = ce_plan_mins($ctx);
    $angles = [];
    foreach ($ctx['angles'] as $k => $a) $angles[] = "$k = {$a['label']}" . ($a['hint'] ? " ({$a['hint']})" : '') . ($a['safeguard'] ? " ⚠ {$a['safeguard']}" : '');
    $svc = [];
    foreach ($ctx['services'] as $id) $svc[] = "$id = " . ($ctx['svc_names'][$id] ?? '') . ' (' . ($ctx['svc_priority'][$id] ?? 'medium') . ')';
    $per = [];
    foreach ($ctx['personas'] as $id) $per[] = "$id = " . ($ctx['per_names'][$id] ?? '');
    $parts = [
        ce_dna_prefix($ctx['uid']),
        "CE:matrix N_SLOTS={$ctx['n']}",
        "<campaign>\n" . ce_plan_brief_text($ctx) . "\n</campaign>",
        'عدد الخانات: ' . $ctx['n'] . ' · الأشكال بالظبط: ' . json_encode($ctx['formats']),
        'نسب المراحل % (tofu/mofu/bofu): ' . json_encode($ctx['mix']),
        'الخدمات (id = الاسم) — أقل عدد خانات لكل خدمة ' . $mins['service'] . ":\n" . implode("\n", $svc),
        "العملاء (id = الاسم):\n" . implode("\n", $per),
        "الأعمدة: " . implode(' · ', array_map(fn($k, $v) => "$k = $v", array_keys(CE_PILLARS), CE_PILLARS)),
        "مكتبة الزوايا:\n" . implode("\n", $angles),
    ];
    if ($locked) {
        $l = [];
        foreach ($locked as $i => $s) $l[] = json_encode(['order' => $i + 1] + array_intersect_key($s, array_flip(['funnel', 'pillar', 'service_id', 'persona_id', 'format', 'angle', 'cta_level', 'objective'])), JSON_UNESCAPED_UNICODE);
        $parts[] = "خانات مقفولة (رجّعها زي ما هي في نفس الترتيب):\n" . implode("\n", $l);
    }
    if ($fixes) $parts[] = "ملاحظات من مراجعة النسخة اللي فاتت — صلّحها:\n- " . implode("\n- ", array_slice($fixes, 0, 15));
    return implode("\n\n", $parts);
}

function ce_plan_critic_system(): string
{
    return ce_prompt_sys('ce_plan_critic');
}

/** رسالة ناقد الخطة (نفسها في المحرك والـ Playground) */
function ce_plan_critic_user(array $ctx, array $slots, array $issues = []): string
{
    return ce_dna_prefix((int) $ctx['uid']) . "\n\nCE:plan_critic\n<campaign>\n" . ce_plan_brief_text($ctx) . "\n</campaign>\n\nنسب المراحل المطلوبة: " . json_encode($ctx['mix'])
        . "\n\nالخطة:\n" . ce_plan_slots_brief($slots, $ctx)
        . ($issues ? "\n\nمخالفات لقاها الكود:\n- " . implode("\n- ", array_column($issues, 'msg')) : '');
}

/* ═══════════════════ التخزين ═══════════════════ */

function ce_plan_slots_load(int $cid): array
{
    $out = [];
    foreach (db_all('SELECT * FROM ce_slots WHERE campaign_id = ? ORDER BY position, id', [$cid]) as $r) {
        $out[] = [
            'id' => (int) $r['id'], 'position' => (int) $r['position'], 'date' => $r['slot_date'], 'funnel' => $r['funnel'], 'pillar' => $r['pillar'],
            'service_id' => $r['service_id'] !== null ? (int) $r['service_id'] : null, 'persona_id' => $r['persona_id'] !== null ? (int) $r['persona_id'] : null,
            'format' => $r['format'], 'angle' => $r['angle'], 'cta_level' => $r['cta_level'], 'objective' => (string) $r['objective'],
            'locked' => (bool) $r['locked'], 'source' => $r['source'],
        ];
    }
    return $out;
}

/**
 * حفظ الخانات مع الحفاظ على أرقامها (id): الخانة اللي ليها id بتتحدّث مكانها، والجديدة بتاخد id من القديمة الفاضية
 * أو بتتضاف، والزيادة بتتمسح. الأرقام الثابتة مهمة: الأفكار والمحتوى في المرحلة F مربوطة بالخانة.
 */
function ce_plan_store(int $cid, int $uid, array $slots): void
{
    $pdo = db();
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        $existing = array_map(fn($r) => (int) $r['id'], db_all('SELECT id FROM ce_slots WHERE campaign_id = ? ORDER BY position, id FOR UPDATE', [$cid]));
        $used = [];
        foreach ($slots as $s) if (!empty($s['id']) && in_array((int) $s['id'], $existing, true)) $used[(int) $s['id']] = true;
        $spare = array_values(array_filter($existing, fn($id) => !isset($used[$id])));
        $keep = [];
        foreach (array_values($slots) as $i => $s) {
            $vals = [$i, $s['date'] ?? null, $s['funnel'], $s['pillar'], $s['service_id'], $s['persona_id'], $s['format'], $s['angle'],
                     $s['cta_level'], mb_substr((string) $s['objective'], 0, 255), !empty($s['locked']) ? 1 : 0, $s['source'] ?? 'ai'];
            $id = !empty($s['id']) && isset($used[(int) $s['id']]) ? (int) $s['id'] : (array_shift($spare) ?? 0);
            if ($id) {
                db_run('UPDATE ce_slots SET position = ?, slot_date = ?, funnel = ?, pillar = ?, service_id = ?, persona_id = ?, format = ?, angle = ?,
                        cta_level = ?, objective = ?, locked = ?, source = ? WHERE id = ? AND campaign_id = ?', array_merge($vals, [$id, $cid]));
            } else {
                $id = (int) db_insert('INSERT INTO ce_slots (position, slot_date, funnel, pillar, service_id, persona_id, format, angle, cta_level, objective, locked, source, campaign_id, user_id)
                                       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)', array_merge($vals, [$cid, $uid]));
            }
            $keep[] = $id;
        }
        if ($spare) db_run('DELETE FROM ce_slots WHERE campaign_id = ? AND id IN (' . implode(',', array_map('intval', $spare)) . ')', [$cid]);
        if ($own) $pdo->commit();
    } catch (\Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function ce_plan_last_eval(int $cid): ?array
{
    $e = db_one('SELECT * FROM ce_plan_evals WHERE campaign_id = ? ORDER BY id DESC LIMIT 1', [$cid]);
    if (!$e) return null;
    return [
        'round' => (int) $e['round'], 'score' => (float) $e['score'], 'passed' => (bool) $e['passed'],
        'scores' => json_decode((string) $e['scores_json'], true) ?: [], 'issues' => json_decode((string) $e['issues_json'], true) ?: [],
        'critic' => json_decode((string) $e['critic_json'], true) ?: null, 'at' => $e['created_at'],
    ];
}

/* ═══════════════════ الشغلانة ═══════════════════ */

/**
 * الحالة: mode (full | gate_only) · round · phase (matrix | gate) · slots · best · fixes · cost_usd · locked
 * كل خطوة = نداء AI واحد (خطة أو تقييم).
 */
function ce_plan_job(array $job, array &$state): array
{
    $uid = (int) $job['user_id'];
    $c = db_one('SELECT * FROM ce_campaigns WHERE id = ? AND user_id = ?', [(int) $job['ref_id'], $uid]);
    if (!$c) return ['fail' => 'الحملة مش موجودة'];
    $ctx = ce_plan_ctx($uid, $c);
    if ($ctx['n'] < 1 || !$ctx['angles']) return ['fail' => 'الحملة مالهاش خانات أو مكتبة الزوايا فاضية'];
    $maxRounds = 1 + max(0, min(4, ce_setting_int('ce_plan_max_rounds', 2)));
    $state += ['mode' => 'full', 'round' => 1, 'phase' => 'matrix', 'fixes' => [], 'cost_usd' => 0.0, 'best' => null, 'locked' => []];
    $locked = [];
    foreach ((array) $state['locked'] as $s) if (isset($s['position']) && $s['position'] < $ctx['n']) $locked[(int) $s['position']] = $s + ['locked' => true];
    $aiCtx = ['user_id' => $uid, 'ref_type' => 'ce_campaign', 'ref_id' => (int) $c['id'], 'campaign_id' => (int) $c['id']];
    $total = $state['mode'] === 'gate_only' ? 1 : $maxRounds * 2;
    $done = ($state['round'] - 1) * 2 + ($state['phase'] === 'gate' ? 1 : 0);

    if ($state['phase'] === 'matrix') {
        $r = ce_ai_json('ce_matrix', ce_plan_matrix_system(), ce_plan_matrix_user($ctx, $locked, $state['fixes']), $aiCtx, min(12000, 1500 + $ctx['n'] * 220), 0.4);
        $state['cost_usd'] += $r['cost_usd'];
        if ($r['ok'] && !empty($r['data']['slots']) && is_array($r['data']['slots'])) {
            $slots = ce_plan_repair(ce_plan_normalize($r['data']['slots'], $ctx, $locked), $ctx);
        } elseif ($r['error'] !== 'gateway_off' && $r['error'] !== null && (int) $job['attempts'] + 1 < (int) $job['max_attempts'] && $state['round'] === 1) {
            throw new RuntimeException('ce_matrix: ' . $r['error']);
        } else {
            $slots = ce_plan_build_rules($ctx, $locked); // الـ AI مش متاح ← خطة بالقواعد (بتعدّي القواعد الثابتة)
        }
        $state['slots'] = $slots;
        $state['phase'] = 'gate';
        return ['done' => false, 'progress' => (int) round(100 * ($done + 1) / ($total + 1)), 'stage' => 'matrix',
                'label' => $state['round'] === 1 ? 'الخطة اتبنت… بنراجعها' : 'بنينا نسخة محسّنة (جولة ' . $state['round'] . ')… بنراجعها'];
    }

    // البوابة
    if ($state['mode'] === 'gate_only' && empty($state['slots'])) $state['slots'] = ce_plan_slots_load((int) $c['id']);
    $slots = $state['slots'];
    $v = ce_plan_validate($slots, $ctx);
    $critic = null;
    $run = null;
    $userMsg = ce_plan_critic_user($ctx, $slots, $v['issues']);
    $r = ce_ai_json('ce_plan_critic', ce_plan_critic_system(), $userMsg, $aiCtx, 1500, 0.1);
    $state['cost_usd'] += $r['cost_usd'];
    if ($r['ok'] && isset($r['data']['scores']) && is_array($r['data']['scores'])) { $critic = $r['data']; $run = $r['run_id']; }
    $comb = ce_plan_combine($v['code'], $critic['scores'] ?? null);
    $passed = $comb['score'] >= ce_plan_threshold() && $v['hard'] === 0;
    $dims = [];
    foreach (CE_PLAN_DIMS as $d => $_) $dims[$d] = ['code' => $v['code'][$d] ?? null, 'critic' => isset($critic['scores'][$d]) ? (float) $critic['scores'][$d] : null, 'final' => $comb['dims'][$d]];
    db_insert('INSERT INTO ce_plan_evals (campaign_id, round, score, passed, scores_json, issues_json, critic_json, run_id) VALUES (?,?,?,?,?,?,?,?)', [
        (int) $c['id'], $state['round'], $comb['score'], $passed ? 1 : 0, json_encode($dims), json_encode($v['issues'], JSON_UNESCAPED_UNICODE),
        $critic ? json_encode(['fixes' => array_slice((array) ($critic['fixes'] ?? []), 0, 8), 'summary' => mb_substr((string) ($critic['summary'] ?? ''), 0, 600)], JSON_UNESCAPED_UNICODE) : null, $run,
    ]);
    if (!$state['best'] || $comb['score'] > $state['best']['score'] || ($passed && !$state['best']['passed'])) {
        $state['best'] = ['score' => $comb['score'], 'passed' => $passed, 'slots' => $slots];
    }

    if ($passed || $state['mode'] === 'gate_only' || $state['round'] >= $maxRounds) {
        $best = $state['best'];
        ce_plan_store((int) $c['id'], $uid, $best['slots']);
        db_run('UPDATE ce_campaigns SET status = "review", plan_score = ?, plan_passed = ? WHERE id = ?', [$best['score'], $best['passed'] ? 1 : 0, (int) $c['id']]);
        $min = max(0, ce_setting_int('ce_plan_min_credits', 1));
        return ['done' => true, 'used_credits' => max($min, ce_usd_to_credits((float) $state['cost_usd'])),
                'result' => ['score' => $best['score'], 'passed' => $best['passed'], 'rounds' => $state['round']]];
    }

    // إعادة بناء بملاحظات الناقد + مخالفات الكود
    $fixes = [];
    foreach ((array) ($critic['fixes'] ?? []) as $f) {
        if (is_array($f) && !empty($f['fix'])) $fixes[] = (isset($f['slot']) ? 'الخانة ' . (int) $f['slot'] . ': ' : '') . mb_substr((string) ($f['issue'] ?? ''), 0, 120) . ' ← ' . mb_substr((string) $f['fix'], 0, 160);
    }
    foreach ($v['issues'] as $i) $fixes[] = $i['msg'];
    foreach ($comb['dims'] as $d => $val) if ($val < 80) $fixes[] = 'ارفع درجة «' . CE_PLAN_DIMS[$d] . '» (' . $val . ')';
    $state['fixes'] = $fixes;
    $state['round']++;
    $state['phase'] = 'matrix';
    return ['done' => false, 'progress' => (int) round(100 * ($done + 1) / ($total + 1)), 'stage' => 'gate',
            'label' => 'الدرجة ' . $comb['score'] . ' — أقل من ' . ce_plan_threshold() . '، بنحسّن الخطة…'];
}

/* ═══════════════════ العمليات (من الـ API) ═══════════════════ */

/** كريدت الخطة اللي بيتحجز: (الخطة + المراجعة) × عدد الجولات الممكنة — والفرق بيرجع بعدها */
function ce_plan_hold_credits(array $c, bool $gateOnly = false): int
{
    $e = json_decode((string) ($c['estimate_json'] ?? ''), true) ?: [];
    $usd = 0.0;
    foreach ((array) ($e['stages'] ?? []) as $s) {
        if ($s['key'] === 'ce_plan_critic' || (!$gateOnly && $s['key'] === 'ce_matrix')) $usd += (float) $s['usd'];
    }
    $rounds = $gateOnly ? 1 : 1 + max(0, ce_setting_int('ce_plan_max_rounds', 2));
    return max(max(0, ce_setting_int('ce_plan_min_credits', 1)), ce_usd_to_credits($usd * $rounds));
}

/**
 * بدء الخطة (أو إعادة بنائها/تقييمها): الحالة بتتغير بشرط (عشان الضغط مرتين مايعملش شغلانتين) ← حجز ← شغلانة
 * $mode: full (أول مرة أو إعادة بناء — المقفول بيفضل) · gate_only (إعادة تقييم بعد تعديل العميل)
 */
function ce_plan_start(int $uid, int $cid, string $mode = 'full'): array
{
    $c = ce_campaign_get($cid, $uid);
    if (!$c) return ['ok' => false, 'code' => 'not_found', 'error' => 'الحملة مش موجودة'];
    $active = ce_job_active_for($uid, 'campaign_plan', 'ce_campaign', $cid);
    if ($active) return ['ok' => true, 'job' => $active, 'existing' => true];
    $from = $mode === 'full' ? ['ready', 'review'] : ['review'];
    if (!in_array($c['status'], $from, true)) return ['ok' => false, 'code' => 'state', 'error' => 'الحملة مش في مرحلة تسمح بده دلوقتي'];
    if (!ce_dna_row($uid)) return ['ok' => false, 'code' => 'no_dna', 'error' => 'ابني ملف الهوية الأول'];

    $st = db()->prepare('UPDATE ce_campaigns SET status = "planning", start_date = COALESCE(start_date, ?) WHERE id = ? AND user_id = ? AND status = ?');
    $st->execute([date('Y-m-d', strtotime('+1 day')), $cid, $uid, $c['status']]);
    if ($st->rowCount() !== 1) {
        $active = ce_job_active_for($uid, 'campaign_plan', 'ce_campaign', $cid);
        return $active ? ['ok' => true, 'job' => $active, 'existing' => true] : ['ok' => false, 'code' => 'state', 'error' => 'حصل تغيير في نفس اللحظة — حدّث الصفحة'];
    }
    $locked = $mode === 'full' ? array_values(array_filter(ce_plan_slots_load($cid), fn($s) => $s['locked'])) : [];
    $state = $mode === 'gate_only' ? ['mode' => 'gate_only', 'phase' => 'gate'] : ['mode' => 'full', 'locked' => $locked];
    $r = ce_job_create($uid, 'campaign_plan', $state, [
        'ref_type' => 'ce_campaign', 'ref_id' => $cid, 'credits' => ce_plan_hold_credits($c, $mode === 'gate_only'),
        'idem_key' => 'plan:' . $cid . ':' . $mode . ':' . bin2hex(random_bytes(6)), 'note' => 'خطة الحملة: ' . mb_substr((string) $c['title'], 0, 80), 'max_attempts' => 3,
    ]);
    if (!$r['ok']) {
        db_run('UPDATE ce_campaigns SET status = ? WHERE id = ? AND status = "planning"', [$c['status'], $cid]);
        return $r;
    }
    db_run('UPDATE ce_campaigns SET job_id = ?, plan_hold_id = ? WHERE id = ?', [(int) $r['job']['id'], $r['job']['hold_id'], $cid]);
    return $r;
}

/** حالة الشغلانة لما بتخلص بالفشل أو الإلغاء ← الحملة ترجع لحالتها (عشان العميل يقدر يبدأ تاني) */
function ce_plan_sync_state(int $uid, array $c): array
{
    if ($c['status'] === 'planning' && !ce_job_active_for($uid, 'campaign_plan', 'ce_campaign', (int) $c['id'])) {
        $back = db_one('SELECT id FROM ce_slots WHERE campaign_id = ? LIMIT 1', [(int) $c['id']]) ? 'review' : 'ready';
        db_run('UPDATE ce_campaigns SET status = ? WHERE id = ? AND status = "planning"', [$back, (int) $c['id']]);
        $c['status'] = $back;
    }
    return $c;
}

/** كل اللي الواجهة محتاجاه عن خطة حملة */
function ce_plan_view(int $uid, array $c): array
{
    $c = ce_plan_sync_state($uid, $c);
    $ctx = ce_plan_ctx($uid, $c);
    $slots = ce_plan_slots_load((int) $c['id']);
    $val = $slots ? ce_plan_validate($slots, $ctx) : null;
    $ev = ce_plan_last_eval((int) $c['id']);
    $critic = null;
    if ($ev) foreach ($ev['scores'] as $d => $x) if ($x['critic'] !== null) $critic[$d] = $x['critic'];
    $now = $val ? ce_plan_combine($val['code'], $critic) : null;
    $job = db_one('SELECT * FROM ce_jobs WHERE kind = "campaign_plan" AND ref_type = "ce_campaign" AND ref_id = ? ORDER BY id DESC LIMIT 1', [(int) $c['id']]);
    return [
        'campaign' => ce_campaign_public($c), 'slots' => $slots,
        'issues' => $val['issues'] ?? [], 'hard' => $val['hard'] ?? 0,
        'score_now' => $now['score'] ?? null, 'dims_now' => $now['dims'] ?? null,
        'eval' => $ev, 'threshold' => ce_plan_threshold(), 'job' => $job ? ce_job_public($job) : null,
        'can_approve' => $c['status'] === 'review' && $now && $now['score'] >= ce_plan_threshold() && ($val['hard'] ?? 1) === 0,
        'labels' => [
            'pillars' => CE_PILLARS, 'funnels' => CE_FUNNELS, 'cta' => CE_CTA_LEVELS, 'formats' => CE_FORMATS, 'dims' => CE_PLAN_DIMS,
            'angles' => array_map(fn($a) => $a['label'], $ctx['angles']),
            'services' => array_intersect_key($ctx['svc_names'], array_flip($ctx['services'])),
            'personas' => array_intersect_key($ctx['per_names'], array_flip($ctx['personas'])),
        ],
        'mix' => $ctx['mix'], 'tail_from' => $slots ? ce_plan_tail_start(count($slots)) : null,
    ];
}

/**
 * تعديل العميل: ترتيب جديد (سحب) + قفل + تعديل خانة من القوائم. بنتحقق من القواعد في الكود ونحسب الدرجة من جديد
 * (درجات الناقد اللي فاتت بتفضل — لو العميل عايز تقييم جديد: «قيّم تاني»).
 */
function ce_plan_save(int $uid, int $cid, array $in): array
{
    $c = ce_campaign_get($cid, $uid);
    if (!$c) return ['ok' => false, 'code' => 'not_found', 'error' => 'الحملة مش موجودة'];
    if (!in_array($c['status'], ['review', 'plan_ok'], true)) return ['ok' => false, 'code' => 'state', 'error' => 'الخطة مش متاحة للتعديل دلوقتي'];
    $ctx = ce_plan_ctx($uid, $c);
    $cur = [];
    foreach (ce_plan_slots_load($cid) as $s) $cur[$s['id']] = $s;
    $ids = array_map(fn($x) => (int) ($x['id'] ?? 0), $in);
    if (count($ids) !== count($cur) || array_diff($ids, array_keys($cur)) || count(array_unique($ids)) !== count($ids)) {
        return ['ok' => false, 'code' => 'input', 'error' => 'الخطة اتغيرت — حدّث الصفحة'];
    }
    $slots = [];
    foreach ($in as $i => $x) {
        $s = $cur[(int) $x['id']];
        foreach (['pillar' => CE_PILLARS, 'funnel' => CE_FUNNELS, 'cta_level' => CE_CTA_LEVELS, 'format' => CE_FORMATS] as $k => $allowed) {
            if (isset($x[$k]) && isset($allowed[$x[$k]])) $s[$k] = $x[$k];
        }
        if (isset($x['angle']) && isset($ctx['angles'][$x['angle']])) $s['angle'] = $x['angle'];
        if (isset($x['service_id']) && in_array((int) $x['service_id'], $ctx['services'], true)) $s['service_id'] = (int) $x['service_id'];
        if (isset($x['persona_id']) && in_array((int) $x['persona_id'], $ctx['personas'], true)) $s['persona_id'] = (int) $x['persona_id'];
        if (isset($x['objective'])) $s['objective'] = mb_substr(trim(strip_tags((string) $x['objective'])), 0, 240);
        $s['locked'] = !empty($x['locked']);
        if (json_encode(array_diff_key($s, ['position' => 1, 'locked' => 1])) !== json_encode(array_diff_key($cur[(int) $x['id']], ['position' => 1, 'locked' => 1]))) $s['source'] = 'user';
        $s['position'] = $i;
        $s['date'] = date('Y-m-d', strtotime($ctx['start'] . ' +' . (int) floor($i * $ctx['days'] / max(1, count($in))) . ' day'));
        $slots[] = $s;
    }
    ce_plan_store($cid, $uid, $slots);
    $view = ce_plan_view($uid, ce_campaign_get($cid, $uid));
    $passed = $view['score_now'] !== null && $view['score_now'] >= ce_plan_threshold() && !$view['hard'];
    db_run('UPDATE ce_campaigns SET status = "review", plan_score = ?, plan_passed = ? WHERE id = ?', [$view['score_now'], $passed ? 1 : 0, $cid]);
    return ['ok' => true, 'view' => ce_plan_view($uid, ce_campaign_get($cid, $uid))];
}

/** اعتماد الخطة: الدرجة ≥ الحد ومفيش مخالفة للقواعد الثابتة */
function ce_plan_approve(int $uid, int $cid): array
{
    $c = ce_campaign_get($cid, $uid);
    if (!$c) return ['ok' => false, 'code' => 'not_found', 'error' => 'الحملة مش موجودة'];
    $v = ce_plan_view($uid, $c);
    if (!$v['can_approve']) {
        return ['ok' => false, 'code' => 'gate', 'error' => $v['hard'] ? 'فيه قواعد متكسّرة في الخطة — صلّحها الأول' : 'درجة الخطة لازم تبقى ' . ce_plan_threshold() . ' أو أكتر — حسّنها أو اطلب تقييم تاني'];
    }
    db_run('UPDATE ce_campaigns SET status = "plan_ok" WHERE id = ? AND user_id = ? AND status = "review"', [$cid, $uid]);
    return ['ok' => true, 'view' => ce_plan_view($uid, ce_campaign_get($cid, $uid))];
}

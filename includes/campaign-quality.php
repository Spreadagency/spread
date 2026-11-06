<?php
/**
 * Spread AI — خطة الحملات · المرحلة 3: الكتابة والتقييم في طلب واحد (docs/PLAN_CAMPAIGNS_V2.md §5)
 *
 *   طلب الكتابة نفسه بيرجّع [SELF_EVAL]{json}[/SELF_EVAL] بعد المنشور (5 معايير من 100)
 *   + فحص بالكود ببلاش: الكلام الممنوع · الـ CTA · الطول وعدد الشرايح · التكرار مع آخر 60 منشور
 *   + الدرجة بيحسبها الكود (مش الموديل) · مخالفة صريحة (ممنوع / مفيش CTA) = مايعدّيش مهما كانت الدرجة
 *   + تحت الحد ← تحسين تلقائي مرة واحدة (campaign_auto_improve_rounds · المستوى بيغيّره بـ qa_rounds) — مش بيتحسب على العميل
 *
 * الطريقة: الإعداد campaign_write_mode = single (الافتراضي) · classic = زي القديم بالظبط (كتابة لوحدها وتقييم بزرار)
 */

/** المعايير الخمسة وأوزانها (نفس أسماء ومقياس CE_QA_WEIGHTS) */
const CAMP_SELF_WEIGHTS = ['hook' => 30, 'audience_fit' => 20, 'value' => 20, 'trust' => 15, 'cta_business' => 15];
const CAMP_SELF_LABELS = ['hook' => 'الـ Hook', 'audience_fit' => 'مناسب للجمهور', 'value' => 'القيمة', 'trust' => 'الثقة', 'cta_business' => 'الـ CTA والبيزنس'];
/** وعود ممنوعة دايمًا (نفس CE_FORBIDDEN_CLAIMS في المحرك) */
const CAMP_FORBIDDEN_CLAIMS = ['مضمون 100', 'نتيجة مضمونة', 'نتايج مضمونة', 'ضمان 100', '100% مضمون', 'أرخص سعر في مصر'];
const CAMP_IMPROVE_MARK = 'تحسين تلقائي';

function campaign_write_mode(): string
{
    return get_setting('campaign_write_mode', 'single') === 'classic' ? 'classic' : 'single';
}

/** حد النجاح: مستوى الجودة في الباقة (qa_pass) ← إعداد المحرك (ce_qa_pass_total) ← 85 */
function campaign_pass_total(int $uid): float
{
    $tq = function_exists('qt_engine') ? qt_engine($uid) : [];
    $v = $tq['qa_pass'] ?? (float) get_setting('ce_qa_pass_total', '85');
    return max(0.0, min(100.0, (float) $v));
}

/** عدد مرات التحسين التلقائي (0..2): المستوى (qa_rounds) ← الإعداد (الافتراضي 1) */
function campaign_auto_rounds(int $uid): int
{
    $tq = function_exists('qt_engine') ? qt_engine($uid) : [];
    $v = $tq['qa_rounds'] ?? (int) get_setting('campaign_auto_improve_rounds', '1');
    return max(0, min(2, (int) $v));
}

/** التعليمات اللي بتتضاف في آخر برومبت الكتابة */
function campaign_self_eval_instructions(): string
{
    $keys = array_keys(CAMP_SELF_WEIGHTS);
    $lines = array_map(fn($k) => '- ' . $k . ': ' . CAMP_SELF_LABELS[$k], $keys);
    return "\n\nبعد المحتوى، قيّم اللي كتبته بصراحة كأنك مراجع صارم مش كاتبه (الدرجة العالية من غير سبب بتبوّظ الحملة):\n"
        . implode("\n", $lines) . "\n"
        . "كل معيار من 100. issues = المشاكل الحقيقية بالظبط (فاضية لو مفيش). fix = التعديل اللي يرفع الدرجة في جملة.\n"
        . "اكتبه في الآخر بالشكل ده بالظبط (JSON في سطر واحد):\n"
        . '[SELF_EVAL]{"criteria":{"hook":0,"audience_fit":0,"value":0,"trust":0,"cta_business":0},"issues":["…"],"fix":"…"}[/SELF_EVAL]';
}

/** التقييم الذاتي من الرد ← null لو ناقص أو مكسور */
function campaign_parse_self_eval(string $response): ?array
{
    if (!preg_match('/\[SELF_EVAL\](.*?)\[\/SELF_EVAL\]/su', $response, $m)) return null;
    $raw = trim($m[1]);
    $raw = preg_replace('/^```(?:json)?|```$/u', '', $raw);
    $j = json_decode(trim((string) $raw), true);
    if (!is_array($j) || !is_array($j['criteria'] ?? null)) return null;
    $crit = [];
    foreach (CAMP_SELF_WEIGHTS as $k => $_) {
        $v = $j['criteria'][$k] ?? null;
        if (is_array($v)) $v = $v['score'] ?? null;
        if (!is_numeric($v)) return null;
        $crit[$k] = max(0, min(100, (int) round((float) $v)));
    }
    $issues = array_values(array_filter(array_map(fn($x) => mb_substr(trim((string) $x), 0, 200), array_filter((array) ($j['issues'] ?? []), 'is_scalar'))));
    return ['criteria' => $crit, 'issues' => array_slice($issues, 0, 6), 'fix' => mb_substr(trim((string) ($j['fix'] ?? '')), 0, 300)];
}

/** الرد من غير بلوك التقييم (علشان مايدخلش في النص) */
function campaign_strip_self_eval(string $response): string
{
    return trim(preg_replace('/\[SELF_EVAL\].*?(\[\/SELF_EVAL\]|$)/su', '', $response));
}

function campaign_text_norm(string $s): string
{
    if (!function_exists('ce_text_norm')) {
        $s = mb_strtolower($s);
        $s = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $s);
        $s = strtr($s, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ى' => 'ي', 'ة' => 'ه', 'ؤ' => 'و', 'ئ' => 'ي']);
        $s = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $s);
        return trim(preg_replace('/\s+/u', ' ', $s));
    }
    return ce_text_norm($s);
}

/** تشابه Dice على حروف ثلاثية — نفس ce_similarity */
function campaign_similarity(string $a, string $b): float
{
    $grams = function (string $s): array {
        $s = ' ' . campaign_text_norm($s) . ' ';
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

/**
 * الفحص بالكود (ببلاش)
 * @param array $p   المحتوى بعد campaign_parse_content
 * @param array $o   fmt · slides · brand · skill · uid · except (content_id الحالي)
 * @return array{flags: string[], notes: string[]}  flags = مخالفة صريحة (مايعدّيش) · notes = ملاحظات للعميل
 */
function campaign_code_check(array $p, array $o): array
{
    $fmt = (string) ($o['fmt'] ?? 'post');
    $brand = (array) ($o['brand'] ?? []);
    $flags = [];
    $notes = [];
    $text = campaign_text_norm(implode("\n", [$p['hook'] ?? '', $p['body'] ?? '', $p['cta'] ?? '', $p['script'] ?? '', $p['slides_raw'] ?? '']));

    // الكلام الممنوع: الهوية («كلمات ممنوعة») + الوعود الممنوعة + ممنوعات الـ Skill
    $avoid = preg_split('/[,،\n]+/u', (string) ($brand['keywords_avoid'] ?? '')) ?: [];
    $skillWords = function_exists('ce_skill_items') ? ce_skill_items($o['skill'] ?? null, 'forbidden') : [];
    foreach (array_merge($avoid, CAMP_FORBIDDEN_CLAIMS, $skillWords) as $w) {
        $n = campaign_text_norm((string) $w);
        if ($n !== '' && mb_strlen($n) >= 3 && str_contains($text, $n)) $flags[] = 'فيه كلمة/وعد ممنوع: «' . trim((string) $w) . '»';
    }
    // الـ CTA
    if (trim((string) ($p['cta'] ?? '')) === '') $flags[] = 'مفيش CTA';

    // الطول
    $words = fn(string $s) => count(preg_split('/\s+/u', trim($s), -1, PREG_SPLIT_NO_EMPTY));
    $bodyW = $words((string) ($p['hook'] ?? '') . ' ' . (string) ($p['body'] ?? ''));
    [$minW, $maxW] = ['post' => [25, 260], 'story' => [5, 70], 'carousel' => [15, 200], 'video' => [10, 150]][$fmt] ?? [25, 260];
    if ($bodyW < $minW) $notes[] = 'النص قصير (' . $bodyW . ' كلمة)';
    if ($bodyW > $maxW) $notes[] = 'النص طويل (' . $bodyW . ' كلمة)';
    if (trim((string) ($p['hook'] ?? '')) === '') $notes[] = 'مفيش Hook منفصل في أول سطر';

    // الشرايح: الحد من carousel_limits() والمطلوب
    if ($fmt === 'carousel') {
        $got = 0;
        foreach (preg_split('/\n+/u', (string) ($p['slides_raw'] ?? '')) as $l) if (trim($l) !== '') $got++;
        [$lo, $hi] = function_exists('carousel_limits') ? array_values(carousel_limits()) : [2, 5];
        $hi = !empty($o['max_slides']) ? min((int) $hi, (int) $o['max_slides']) : (int) $hi;   // حد الباقة بيضيّق (المرحلة 4)
        $want = (int) ($o['slides'] ?? 0);
        if ($got < (int) $lo || $got > $hi) $notes[] = 'عدد الشرايح (' . $got . ') برّه الحد';
        elseif ($want > 0 && $got !== $want) $notes[] = 'عدد الشرايح ' . $got . ' بدل ' . $want;
    }
    if ($fmt === 'video' && trim((string) ($p['script'] ?? '')) === '') $flags[] = 'مفيش سكريبت';

    // التكرار مع آخر 60 منشور للعميل
    $uid = (int) ($o['uid'] ?? 0);
    $hook = trim((string) ($p['hook'] ?? '')) ?: mb_substr(trim((string) ($p['body'] ?? '')), 0, 120);
    if ($uid > 0 && $hook !== '') {
        $thr = max(0.5, min(1.0, (float) get_setting('ce_dedup_threshold', '0.85')));
        try {
            foreach (db_all('SELECT id, generated_text FROM contents WHERE user_id = ? AND id <> ? AND generated_text IS NOT NULL ORDER BY id DESC LIMIT 60', [$uid, (int) ($o['except'] ?? 0)]) as $r) {
                $first = trim((string) strtok((string) $r['generated_text'], "\n"));
                if ($first !== '' && campaign_similarity($hook, $first) >= $thr) { $notes[] = 'البداية شبه منشور قديم (#' . (int) $r['id'] . ')'; break; }
            }
        } catch (\Throwable $e) {}
    }
    return ['flags' => array_values(array_unique($flags)), 'notes' => array_values(array_unique($notes))];
}

/**
 * الدرجة والحكم — الكود بيحسب (مش بنصدّق الموديل)
 * الشكل متوافق مع eval_json القديم (score · cats · labels · comment · issues) + تفاصيل الطريقة الجديدة
 */
function campaign_self_score(?array $se, array $code, int $uid): array
{
    $pass = campaign_pass_total($uid);
    if (!$se) {
        return ['mode' => 'self', 'missing' => true, 'score' => 0, 'cats' => [], 'labels' => [], 'comment' => '',
                'issues' => 'التقييم مارجعش — راجع المنشور بنفسك', 'flags' => $code['flags'], 'notes' => $code['notes'], 'pass' => false, 'pass_total' => $pass];
    }
    $sum = 0;
    foreach (CAMP_SELF_WEIGHTS as $k => $w) $sum += $se['criteria'][$k] * $w;
    $total = (int) round($sum / array_sum(CAMP_SELF_WEIGHTS));
    $ok = $total >= $pass && !$code['flags'];
    $issues = array_merge($code['flags'], $se['issues'], $code['notes']);
    return [
        'mode' => 'self', 'score' => $total, 'cats' => array_values($se['criteria']), 'labels' => array_values(CAMP_SELF_LABELS),
        'criteria' => $se['criteria'], 'comment' => '', 'issues' => mb_substr(implode(' · ', $issues), 0, 500), 'fix' => $se['fix'],
        'flags' => $code['flags'], 'notes' => $code['notes'], 'pass' => $ok, 'pass_total' => $pass,
    ];
}

/** ملاحظات التحسين التلقائي (بتتحط في البرومبت نفسه) */
function campaign_improve_extra(array $p, array $eval, string $fmt): string
{
    $fix = [];
    foreach ($eval['flags'] ?? [] as $f) $fix[] = 'لازم يتصلّح: ' . $f;
    foreach ($eval['criteria'] ?? [] as $k => $v) if ($v < 80) $fix[] = (CAMP_SELF_LABELS[$k] ?? $k) . ' (' . $v . ')';
    foreach ((array) (preg_split('/ · /u', (string) ($eval['issues'] ?? '')) ?: []) as $x) if (trim($x) !== '' && !in_array('لازم يتصلّح: ' . $x, $fix, true)) $fix[] = trim($x);
    if (!empty($eval['fix'])) $fix[] = 'المقترح: ' . $eval['fix'];
    $cur = campaign_compose_text((string) ($p['hook'] ?? ''), (string) ($p['body'] ?? ''));
    return CAMP_IMPROVE_MARK . " — النسخة اللي فاتت أخدت " . (int) ($eval['score'] ?? 0) . "/100 (الحد " . (int) ($eval['pass_total'] ?? 85) . "). اكتبها تاني وصلّح ده بالظبط:\n- "
        . implode("\n- ", array_slice(array_unique($fix), 0, 10)) . "\n\nالنسخة اللي فاتت:\n" . mb_substr($cur, 0, 2500)
        . "\nCTA اللي فات: " . (string) ($p['cta'] ?? '')
        . ($fmt === 'carousel' && !empty($p['slides_raw']) ? "\nالشرائح اللي فاتت:\n" . mb_substr((string) $p['slides_raw'], 0, 2000) : '')
        . ($fmt === 'video' && !empty($p['script']) ? "\nالسكريبت اللي فات:\n" . mb_substr((string) $p['script'], 0, 2000) : '');
}

/**
 * الكتابة + التقييم + التحسين التلقائي
 * @param callable $prompt  fn(string $extra): string — برومبت الكتابة (من غير تعليمات التقييم)
 * @param callable $call    fn(string $prompt, bool $improve): array — طلب الـ AI (نفس شكل campaign_ai)
 * @param callable $parse   fn(string $response): ?array — المحتوى (campaign_parse_content + الممنوعات) أو null لو ناقص
 * @param array    $o       fmt · slides · brand · skill · uid · except · extra (ملاحظة إضافية للطلب الأول) · rounds (اختياري)
 * @return array{ok: bool, p: ?array, ai: array, eval: ?array, calls: int, improved: int, error: ?string}
 */
function campaign_write_with_eval(callable $prompt, callable $call, callable $parse, array $o): array
{
    $uid = (int) ($o['uid'] ?? 0);
    $fmt = (string) ($o['fmt'] ?? 'post');
    $calls = 0;
    $usage = ['in' => 0, 'out' => 0];
    $run = function (string $extra, bool $improve) use ($prompt, $call, $parse, &$calls, &$usage): array {
        $ai = $call($prompt($extra) . campaign_self_eval_instructions(), $improve);
        $calls++;
        $usage['in'] += (int) ($ai['usage']['in'] ?? 0);
        $usage['out'] += (int) ($ai['usage']['out'] ?? 0);
        if (empty($ai['ok'])) return [$ai, null, null];
        $resp = (string) $ai['response'];
        $p = $parse(campaign_strip_self_eval($resp));
        return [$ai, $p, campaign_parse_self_eval($resp)];
    };

    $extra = (string) ($o['extra'] ?? '');
    [$ai, $p, $se] = $run($extra, false);
    if (!$p) return ['ok' => false, 'p' => null, 'ai' => $ai, 'eval' => null, 'calls' => $calls, 'improved' => 0, 'usage' => $usage,
                     'error' => !empty($ai['ok']) ? 'الرد مكانش مظبوط — جرّب تاني' : ($ai['error'] ?? 'تعذّر التوليد')];
    // التقييم مارجعش مظبوط ← إعادة مرة واحدة والخطأ في البرومبت
    if (!$se) {
        [$ai2, $p2, $se2] = $run(trim($extra . "\n\nالرد اللي فات كان ناقص [SELF_EVAL] أو الـ JSON بتاعه مكسور — لازم يرجع كامل بالشكل المطلوب."), false);
        if ($p2 && $se2) [$ai, $p, $se] = [$ai2, $p2, $se2];
    }
    $chk = $o + ['fmt' => $fmt];
    $eval = campaign_self_score($se, campaign_code_check($p, $chk), $uid);

    $improved = 0;
    $rounds = isset($o['rounds']) ? max(0, min(2, (int) $o['rounds'])) : campaign_auto_rounds($uid);
    while (!$eval['pass'] && empty($eval['missing']) && $improved < $rounds) {
        [$aiI, $pI, $seI] = $run(campaign_improve_extra($p, $eval, $fmt), true);
        $improved++;
        if (!$pI || !$seI) continue;
        $evI = campaign_self_score($seI, campaign_code_check($pI, $chk), $uid);
        // النسخة الجديدة بتكسب لو مخالفاتها أقل، أو نفس المخالفات ودرجتها مش أقل
        if (count($evI['flags']) < count($eval['flags']) || (count($evI['flags']) === count($eval['flags']) && $evI['score'] >= $eval['score'])) {
            [$ai, $p, $eval] = [$aiI, $pI, $evI];
        }
    }
    $eval['auto_improved'] = $improved;
    $eval['calls'] = $calls;
    $eval['at'] = date('c');
    $ai['usage'] = $usage;   // كل الطلبات (للسجل)
    return ['ok' => true, 'p' => $p, 'ai' => $ai, 'eval' => $eval, 'calls' => $calls, 'improved' => $improved, 'usage' => $usage, 'error' => null];
}

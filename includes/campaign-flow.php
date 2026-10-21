<?php
/**
 * Spread AI v2 — الحملة كاملة جوه الشاشة (المرحلة ⑥-ب)
 *
 * الأفكار ← المحتوى (منشور + سكريبت فيديو) ← التقييم ← التصميم ← الجدولة ← النشر
 * كل فكرة = صف في campaign_ideas، والمنشور نفسه صف عادي في contents (بيظهر في المكتبة
 * والتصميم والنشر زي أي منشور) — فمفيش نظام موازي.
 *
 * الإنتاج الجماعي بيتعمل من الواجهة فكرة فكرة (طلب لكل واحدة) — مفيش timeout،
 * ومع كل طلب بيبان التقدم («3 من 20»)، ولو واحدة فشلت الباقي بيكمّل.
 *
 * ⚠️ موعد الجدولة بيتخزن في campaign_ideas.plan_at — مش contents.scheduled_at —
 *    لحد ما العميل يضغط «ابدأ النشر». (الكرون القديم بينشر أي scheduled_at من غير صفحة
 *    على الربط العام، فمانحطش مواعيد هناك قبل ما العميل يقرر ينشر.)
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/prompt-builder.php';
require_once __DIR__ . '/source-extract.php';   // get_brand_knowledge()
require_once __DIR__ . '/lifecycle.php';
require_once __DIR__ . '/content-formats.php';   // ⑦-ج Post · Carousel · Video · Story
require_once __DIR__ . '/prompt-library.php';    // البرومبتات من الأدمن (جدول prompts)

const CAMP_IDEAS_BATCH = 5;          // أفكار في كل طلب AI
const CAMP_MAX_IDEAS = 30;
const CAMP_EVAL_READY = 80;          // الافتراضي — القيمة الفعلية eval_ready_score() من الأدمن
const CAMP_EVAL_CATS = ['قوة الفكرة', 'قوة الـ Hook', 'مناسبة الجمهور', 'وضوح الرسالة', 'قوة CTA', 'التوافق مع البراند'];   // القديمة — الفعلية eval_criteria()
const CAMP_FORMATS = ['منشور', 'ريلز', 'ستوري', 'كاروسيل', 'ستوري تفاعلية', 'فيديو'];

/* ═══════════════ الأساس (البريف) ═══════════════ */

/** الأساس اللي الحملة بتتبني عليه (زي التصميم) */
function campaign_flow_bases(): array
{
    return [
        'product'   => 'منتج جديد',
        'offer'     => 'عرض وخصم',
        'sales'     => 'زيادة المبيعات',
        'awareness' => 'زيادة الوعي',
        'season'    => 'موسم أو مناسبة',
        'education' => 'محتوى تعليمي',
        'other'     => 'شيء آخر',
    ];
}

/* ═══════════════ الـ AI ═══════════════ */

/**
 * نداء AI بحد tokens مخصوص (ai_generate ثابت على 1000 — مش كفاية لمنشور + سكريبت)
 * نفس المسارات: الـ Router لو مفعّل ← المفتاح + الموديل الاحتياطي ← التجريبي لو مسموح
 */
function campaign_ai(string $prompt, int $maxTokens, string $task, array $ctx = []): array
{
    $r = campaign_ai_call($prompt, $maxTokens, $task, $ctx);
    // ذاكرة البراند: [LEARNINGS] في الرد بتتحفظ وتتشال قبل القراءة
    if (!empty($r['ok']) && is_string($r['response'] ?? null) && str_contains($r['response'], '[LEARNINGS]')) {
        $r['response'] = prompt_capture_learnings((int) ($ctx['user_id'] ?? 0), $r['response'], $task);
    }
    return $r;
}

function campaign_ai_call(string $prompt, int $maxTokens, string $task, array $ctx = []): array
{
    require_once __DIR__ . '/ai.php';
    if (is_file(__DIR__ . '/smart-ai.php')) require_once __DIR__ . '/smart-ai.php';

    // 8-أ: البوابة الموحدة بالبدائل
    if (function_exists('ai_gw_enabled') && ai_gw_enabled()) {
        ai_log_set_context(['kind' => $task, 'user_id' => $ctx['user_id'] ?? null,
            'reference_type' => $ctx['reference_type'] ?? 'campaign', 'reference_id' => $ctx['reference_id'] ?? null]);
        return ai_gw_generate_text($prompt, [], $maxTokens, (float) ($ctx['temperature'] ?? 0.85), usage_task_for_kind($task));
    }

    if (function_exists('smart_ai_enabled') && smart_ai_enabled()) {
        return smart_ai_generate($prompt, [], $task, $ctx + ['max_tokens' => $maxTokens, 'job_type' => $task]);
    }
    ai_log_set_context(['kind' => $task, 'user_id' => $ctx['user_id'] ?? null,
        'reference_type' => $ctx['reference_type'] ?? 'campaign', 'reference_id' => $ctx['reference_id'] ?? null]);
    if (!ai_has_key()) {
        if (ai_mock_allowed()) {
            return ['ok' => true, 'response' => mock_ai_response($prompt), 'error' => null, 'usage' => ['in' => 0, 'out' => 0], 'model' => 'mock'];
        }
        return ['ok' => false, 'response' => null, 'error' => 'محرك الذكاء الاصطناعي غير مضبوط — كلّم الإدارة', 'usage' => ['in' => 0, 'out' => 0], 'model' => 'unconfigured'];
    }
    $t0 = microtime(true);
    $messages = ai_build_messages($prompt);
    $primary = get_setting('ai_model', AI_MODEL) ?: AI_MODEL;
    $r = ai_call($primary, $messages, (float) ($ctx['temperature'] ?? 0.85), $maxTokens);
    if ($r['ok']) {
        $r['model'] = $primary;
        return ai_log_text_result($prompt, [], $r, $t0, $primary);
    }
    $fallback = get_setting('ai_model_fallback', '');
    if ($fallback && $fallback !== $primary) {
        $r2 = ai_call($fallback, $messages, (float) ($ctx['temperature'] ?? 0.85), $maxTokens);
        if ($r2['ok']) {
            $r2['model'] = $fallback;
            return ai_log_text_result($prompt, [], $r2, $t0, $fallback);
        }
    }
    return ai_log_text_result($prompt, [], ['ok' => false, 'response' => null,
        'error' => 'محرك الذكاء الاصطناعي مشغول حاليًا — جرّب تاني بعد دقيقة', 'usage' => ['in' => 0, 'out' => 0]], $t0, $primary);
}

/** سطور هوية البراند للبرومبتات — + Brand Kit للـ Agent ده (والبريف لو بوست واحد) */
function campaign_brand_block(array $brand, string $agent = 'copy', ?array $brief = null): string
{
    if (!function_exists('bk_brand_block')) require_once __DIR__ . '/brand-kit.php';
    $__u = bk_brand_block($brand, $agent, $brief);
    if ($__u !== null) return $__u;
    $lines = [];
    foreach (['business_name' => 'اسم النشاط', 'industry' => 'المجال', 'description' => 'الوصف', 'audience' => 'الجمهور',
              'services' => 'الخدمات والمنتجات', 'tone' => 'النبرة', 'colors' => 'ألوان البراند',
              'keywords_use' => 'كلمات مفضلة', 'keywords_avoid' => 'كلمات ممنوعة'] as $k => $l) {
        if (!empty($brand[$k])) $lines[] = '- ' . $l . ': ' . mb_substr((string) $brand[$k], 0, 400);
    }
    if (!empty($brand['ai_summary'])) $lines[] = '- ملخص الهوية: ' . mb_substr((string) $brand['ai_summary'], 0, 600);
    $k = !empty($brand['id']) ? get_brand_knowledge((int) $brand['id']) : '';
    $kit = '';
    return "بيانات البراند:\n" . implode("\n", $lines)
        . ($k !== '' ? "\n\nمعلومات موثّقة عن البيزنس (مصدر حقائق — ماتخترعش حاجة تخالفها):\n" . mb_substr($k, 0, 3000) : '')
        . $kit;
}

function campaign_dialect(array $brand): string
{
    return match ($brand['dialect'] ?? 'egyptian') {
        'gulf', 'khaleeji' => 'اللهجة الخليجية البيضاء',
        'msa' => 'العربية الفصحى المبسطة',
        'levantine' => 'اللهجة الشامية',
        default => 'اللهجة المصرية الخفيفة',
    };
}

/** برومبت دفعة أفكار (JSON) — بيتجنب الأفكار اللي طلعت قبل كده · التعليمات من الأدمن (campaign_ideas) */
function campaign_ideas_prompt(array $brand, array $c, int $n, array $avoidTitles, string $plan = ''): string
{
    $basis = campaign_flow_bases()[$c['basis'] ?? ''] ?? (campaign_bases()[$c['basis'] ?? ''] ?? 'تسويق عام');
    $details = trim((string) ($c['topic'] ?? '') . "\n" . (string) ($c['notes'] ?? ''));
    $data = campaign_brand_block($brand, 'ideas') . "\n\n"
        . "أساس الحملة: {$basis}\n"
        . "اسم الحملة: " . ($c['title'] ?? '') . "\n"
        . ($details !== '' ? "تفاصيل من العميل: {$details}\n" : '')
        . ($plan !== '' ? "\n{$plan}\n" : '')
        . ($avoidTitles ? "\nأفكار اتعملت قبل كده في نفس الحملة — ماتكررهاش ولا تقرب منها:\n- " . implode("\n- ", array_slice($avoidTitles, 0, 40)) . "\n" : '');
    $fmt = 'أعد JSON فقط بدون أي كلام قبله أو بعده بالشكل ده بالظبط:' . "\n";
    if (prompt_full('campaign_ideas')) {
        // برومبت كامل (v1): كل البيانات في المتغيرات — والشكل اللي الكود بيقراه تحت
        $vars = ['n' => $n, 'count' => $n, 'dialect' => campaign_dialect($brand), 'formats' => implode('، ', CAMP_FORMATS),
            'brand_identity' => campaign_brand_block($brand, 'ideas'), 'campaign_goal' => $basis, 'campaign_duration' => 'شهر',
            'offer' => $details, 'topic' => (string) ($c['topic'] ?? ''),
            'extra_notes' => trim('اسم الحملة: ' . ($c['title'] ?? '') . ($details !== '' ? "\n" . $details : '') . ($plan !== '' ? "\n\n" . $plan : '')),
            'previous_ideas' => $avoidTitles ? '- ' . implode("\n- ", array_slice($avoidTitles, 0, 40)) : 'مفيش'];
        return prompt_fill('campaign_ideas', $vars, ['uid' => (int) ($brand['user_id'] ?? 0), 'brand' => $brand, 'agent' => 'ideas'])
            . prompt_format_head() . "مطلوب {$n} أفكار بالظبط. formats من القايمة دي بس: " . implode('، ', CAMP_FORMATS) . ".\n" . $fmt
            . '{"ideas":[{"angle":"خلف الكواليس","title":"عنوان قصير","desc":"شرح في جملتين: الرسالة والزاوية","audience":"الجمهور المستهدف بالتحديد","hook":"جملة الـ Hook","formats":["منشور","ريلز"],"service":"","topic":"","pillar":"","persona":""}]}';
    }
    return prompt_fill('campaign_ideas', ['n' => $n, 'dialect' => campaign_dialect($brand), 'formats' => implode('، ', CAMP_FORMATS), 'data' => $data])
        . "\n\nأعد JSON فقط بدون أي كلام قبله أو بعده بالشكل ده بالظبط:\n"
        . '{"ideas":[{"angle":"خلف الكواليس","title":"عنوان قصير","desc":"شرح في جملتين: الرسالة والزاوية","audience":"الجمهور المستهدف بالتحديد","hook":"جملة الـ Hook","formats":["منشور","ريلز"],"service":"","topic":"","pillar":"","persona":""}]}';
}

/** العميل المثالي والشخصيات والـ CTA اللي الفكرة مربوطة بيهم (من إعداد الحملة) — سطور للبرومبت */
function campaign_idea_targets(array $brand, array $idea): string
{
    $b = json_decode((string) ($idea['brief_json'] ?? ''), true) ?: [];
    $uid = (int) ($brand['user_id'] ?? 0);
    $L = [];
    if (!empty($b['persona_id']) && $uid && function_exists('bk_persona') && ($p = bk_persona($uid, (int) $b['persona_id']))) {
        $L[] = "- اكتب للشخص ده بالذات:\n" . bk_persona_text($p, $uid);
    }
    if (!empty($b['character_names'])) $L[] = '- الشخصيات اللي هتظهر في البوست: ' . implode('، ', (array) $b['character_names']);
    if (!empty($b['cta'])) $L[] = '- الـ CTA بتاع الحملة (استخدمه بدل الأساسي): ' . $b['cta'];
    return $L ? implode("\n", $L) . "\n" : '';
}

/** قراءة JSON من رد الـ AI (بيتحمل ```json ونصوص زيادة) */
function campaign_json(string $response): ?array
{
    $s = trim($response);
    if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/s', $s, $m)) {
        $s = $m[1];
    } else {
        $a = strpos($s, '{');
        $b = strrpos($s, '}');
        if ($a !== false && $b !== false && $b > $a) $s = substr($s, $a, $b - $a + 1);
    }
    $j = json_decode($s, true);
    if (!is_array($j)) $j = json_decode(preg_replace('/,\s*([\]}])/', '$1', $s), true);   // فاصلة زيادة قبل القفلة
    return is_array($j) ? $j : null;
}

/**
 * الكائنات الكاملة جوه أول قايمة في الرد — حتى لو الرد اتقطع في النص (حد التوكنز)
 * أو فيه غلطة في فكرة واحدة: بناخد كل فكرة اتقفلت صح ونسيب الباقي
 */
function campaign_json_items(string $response): array
{
    $s = (string) $response;
    $p = preg_match('/"ideas"\s*:\s*\[/u', $s, $m, PREG_OFFSET_CAPTURE) ? $m[0][1] + strlen($m[0][0]) : strpos($s, '[');
    if ($p === false) return [];
    $out = [];
    $depth = 0; $in = false; $esc = false; $start = -1;
    for ($k = (int) $p, $len = strlen($s); $k < $len; $k++) {
        $ch = $s[$k];
        if ($in) {
            if ($esc) $esc = false;
            elseif ($ch === '\\') $esc = true;
            elseif ($ch === '"') $in = false;
            continue;
        }
        if ($ch === '"') { $in = true; continue; }
        if ($ch === '{') { if ($depth === 0) $start = $k; $depth++; continue; }
        if ($ch === '}' && $depth > 0) {
            $depth--;
            if ($depth === 0 && $start >= 0) {
                $o = json_decode(substr($s, $start, $k - $start + 1), true);
                if (is_array($o)) $out[] = $o;
                $start = -1;
            }
            continue;
        }
        if ($ch === ']' && $depth === 0) break;
    }
    return $out;
}

function campaign_parse_ideas(string $response): array
{
    $j = campaign_json($response);
    $list = $j['ideas'] ?? (array_is_list((array) $j) ? $j : null);
    if (!is_array($list) || !$list) $list = campaign_json_items($response);
    $out = [];
    foreach ((array) $list as $i) {
        if (!is_array($i)) continue;
        $title = trim((string) ($i['title'] ?? ''));
        if ($title === '') continue;
        $formats = array_values(array_filter(array_map('trim', (array) ($i['formats'] ?? [])), fn($f) => in_array($f, CAMP_FORMATS, true)));
        $out[] = [
            'angle'    => mb_substr(trim((string) ($i['angle'] ?? '')), 0, 60) ?: 'فكرة',
            'title'    => mb_substr($title, 0, 255),
            'desc'     => mb_substr(trim((string) ($i['desc'] ?? $i['description'] ?? '')), 0, 1000),
            'audience' => mb_substr(trim((string) ($i['audience'] ?? '')), 0, 255),
            'hook'     => mb_substr(trim((string) ($i['hook'] ?? '')), 0, 500),
            'formats'  => $formats ?: ['منشور'],
            'persona'  => mb_substr(trim((string) ($i['persona'] ?? '')), 0, 100),
            'brief'    => array_filter([
                'service' => mb_substr(trim((string) ($i['service'] ?? '')), 0, 150),
                'topic'   => mb_substr(trim((string) ($i['topic'] ?? '')), 0, 255),
                'pillar'  => mb_substr(trim((string) ($i['pillar'] ?? '')), 0, 80),
            ], fn($v) => $v !== ''),
        ];
    }
    return $out;
}

/** حد التوكنز لدفعة أفكار — قابل للتعديل من الأدمن (campaign_ideas_tokens_per_idea) */
function campaign_ideas_tokens(int $n): int
{
    $per = max(300, min(2000, (int) get_setting('campaign_ideas_tokens_per_idea', '700')));
    return max(1800, min(8000, $per * max(1, $n) + 800));
}

/** الشكل الفعلي للفكرة: اختيار العميل ← وإلا أول شكل مقترح */
function campaign_idea_format(array $idea): string
{
    if (!empty($idea['format']) && isset(content_formats()[$idea['format']])) return $idea['format'];
    return content_format_from_labels(array_filter(explode('|', (string) ($idea['formats'] ?? ''))));
}

/**
 * برومبت المحتوى لفكرة حسب الشكل (⑦-ج):
 *   post     → منشور + فكرة تصميم واحدة
 *   story    → نص ستوري قصير + تصميم رأسي
 *   carousel → كابشن + N شرائح (عنوان · نص · فكرة تصميم لكل شريحة) — الأولى غلاف والأخيرة CTA
 *   video    → كابشن + سكريبت مشاهد حسب المدة + فكرة التصوير
 */
function campaign_content_prompt(array $brand, array $c, array $idea, string $extra = '', ?string $format = null, int $slides = 0, string $duration = '30 ثانية', ?array $brief = null, array $task = []): string
{
    $format = $format ?: campaign_idea_format($idea);
    $basis = campaign_flow_bases()[$c['basis'] ?? ''] ?? 'تسويق عام';
    $fmtLabel = content_format_label($format) . ($format === 'carousel' ? " من {$slides} شرائح" : '');
    $data = campaign_brand_block($brand, 'copy', $brief)
        . ($format === 'video' && !empty($brand['user_id']) ? bk_prompt_block((int) $brand['user_id'], 'video') : '') . "\n\n"
        . "الفكرة:\n- الزاوية: {$idea['angle']}\n- العنوان: {$idea['title']}\n"
        . (!empty($idea['description']) ? "- التفاصيل: {$idea['description']}\n" : '')
        . (!empty($idea['audience']) ? "- الجمهور: {$idea['audience']}\n" : '')
        . (!empty($idea['hook']) ? "- Hook مقترح: {$idea['hook']}\n" : '')
        . campaign_idea_targets($brand, $idea)
        . ($extra !== '' ? "\n{$extra}\n" : '');
    $type = $task['type'] ?? 'campaign_content';
    if (prompt_full($type)) {
        // برومبت كامل (v1): الفكرة والهوية في المتغيرات · قواعد التصميم من «فكرة التصميم» · الشكل اللي بيتقري تحت
        $uid = (int) ($brand['user_id'] ?? 0);
        $ideaText = "- الزاوية: {$idea['angle']}\n- العنوان: {$idea['title']}\n- الشكل: {$fmtLabel}\n"
            . (!empty($idea['description']) ? "- التفاصيل: {$idea['description']}\n" : '')
            . (!empty($idea['audience']) ? "- الجمهور: {$idea['audience']}\n" : '')
            . (!empty($idea['hook']) ? "- Hook مقترح: {$idea['hook']}\n" : '')
            . campaign_idea_targets($brand, $idea);
        $vars = ($task['vars'] ?? []) + [
            'campaign' => (string) ($c['title'] ?? ''), 'basis' => $basis, 'format' => $fmtLabel, 'dialect' => campaign_dialect($brand),
            'brand_identity' => campaign_brand_block($brand, 'copy', $brief) . ($format === 'video' && $uid ? bk_prompt_block($uid, 'video') : ''),
            'campaign_concept' => 'حملة «' . ($c['title'] ?? '') . '» — هدفها: ' . $basis . (trim((string) ($c['notes'] ?? '')) !== '' ? "\n" . trim((string) $c['notes']) : ''),
            'campaign_goal' => $basis, 'idea' => $ideaText, 'ideas' => $ideaText, 'topic' => (string) $idea['title'],
            'content_type' => (string) $idea['angle'] . ' — ' . $fmtLabel, 'length' => campaign_format_length($format),
            'extra_notes' => $extra, 'duration' => $duration,
        ];
        return prompt_fill($type, $vars, ['uid' => $uid, 'brand' => $brand, 'agent' => 'copy', 'dna' => true])
            . ($format !== 'video' ? campaign_design_rules($fmtLabel, $uid) : '')
            . prompt_format_head() . "المطلوب محتوى للفكرة دي بس بشكل «{$fmtLabel}». أعد الناتج بالتنسيق ده بالظبط بدون أي تعليق:\n\n"
            . campaign_content_format($format, $slides, $duration) . "\n" . prompt_learnings_ask();
    }
    $head = prompt_fill('campaign_content', ['campaign' => (string) ($c['title'] ?? ''), 'basis' => $basis, 'format' => $fmtLabel, 'dialect' => campaign_dialect($brand), 'data' => $data])
        . ($format !== 'video' ? "\n\n" . prompt_fill('design_idea', ['format' => $fmtLabel]) : '')
        . "\n\nأعد الناتج بالتنسيق ده بالظبط بدون أي تعليق:\n\n";
    return $head . campaign_content_format($format, $slides, $duration);
}

/** الطول المناسب لكل شكل (متغير {length} في البرومبت الكامل) */
function campaign_format_length(string $format): string
{
    return ['video' => 'كابشن قصير 30-80 كلمة + سكريبت', 'carousel' => 'كابشن 40-100 كلمة + نص قصير لكل شريحة', 'story' => 'قصير جدًا 15-40 كلمة'][$format] ?? 'متوسط 80-160 كلمة';
}

/** قواعد فكرة التصميم: من البرومبت الكامل (قسم «قواعد التصميم») — أو التعليمات القديمة */
function campaign_design_rules(string $fmtLabel, int $uid = 0): string
{
    if (!prompt_full('design_idea')) return "\n\n" . prompt_fill('design_idea', ['format' => $fmtLabel]);
    $t = prompt_pick_sections(prompt_render('design_idea', ['format' => $fmtLabel], ['uid' => $uid, 'identity' => false]), ['قواعد التصميم']);
    return $t !== '' ? "\n\n" . $t : '';
}

/** شكل الرد المطلوب لكل نوع (الكود بيقراه) — مشترك بين الحملة والمنشور السريع */
function campaign_content_format(string $format, int $slides = 0, string $duration = '30 ثانية'): string
{
    $head = '';
    $tags = "[CTA]\nجملة Call-to-Action قصيرة ومحددة\n[/CTA]\n\n[HASHTAGS]\n#هاشتاج1 #هاشتاج2 (5-8)\n[/HASHTAGS]\n\n";
    $img = "[IMAGE_PROMPT]\nEnglish image prompt: scene, composition, brand colors, modern social media design. No text instructions unless essential.\n[/IMAGE_PROMPT]";
    if ($format === 'video') {
        return $head
            . "[HOOK]\nأول جملة بتتقال في أول 3 ثواني (سطر واحد)\n[/HOOK]\n\n"
            . "[CONTENT]\nكابشن الفيديو اللي هيتنشر معاه (30-80 كلمة)\n[/CONTENT]\n\n" . $tags
            . "[SCRIPT]\nسكريبت فيديو مدته {$duration} — مشاهد مرقمة بالتوقيت: «مشهد 1 (0–3ث): اللي بيظهر + اللي بيتقال» سطر لكل مشهد\n[/SCRIPT]\n\n"
            . "[SHOOT]\nفكرة التصوير: المكان، الأشخاص، الإضاءة، الأدوات المطلوبة (جملتين)\n[/SHOOT]";
    }
    if ($format === 'carousel') {
        $lines = '';
        for ($k = 1; $k <= $slides; $k++) {
            $lines .= "شريحة {$k} | " . ($k === 1 ? 'عنوان الغلاف (Hook)' : ($k === $slides ? 'عنوان الـ CTA' : 'عنوان الشريحة')) . " | نص قصير سطرين | فكرة تصميم الشريحة\n";
        }
        return $head
            . "[HOOK]\nجملة افتتاحية قوية للكابشن (سطر واحد)\n[/HOOK]\n\n"
            . "[CONTENT]\nكابشن الكاروسيل (40-100 كلمة) بيشجع على التقليب للآخر\n[/CONTENT]\n\n" . $tags
            . "[SLIDES]\n{$lines}[/SLIDES]\n(بالظبط {$slides} سطور — الشرائح متسلسلة وبتحكي قصة واحدة: الأولى غلاف يشد، والأخيرة CTA)\n\n"
            . "[DESIGN_IDEA]\nالهوية البصرية الموحدة لكل الشرائح: الألوان، الخطوط، التكوين الثابت\n[/DESIGN_IDEA]\n\n" . $img;
    }
    if ($format === 'story') {
        return $head
            . "[HOOK]\nجملة قصيرة جدًا تشد في الستوري\n[/HOOK]\n\n"
            . "[CONTENT]\nنص الستوري (15-40 كلمة) — مباشر ومختصر\n[/CONTENT]\n\n" . $tags
            . "[DESIGN_IDEA]\nفكرة تصميم ستوري رأسي 9:16: التكوين، العناصر، النص الظاهر، ومكان زرار/ستيكر التفاعل\n[/DESIGN_IDEA]\n\n" . $img;
    }
    return $head
        . "[HOOK]\nجملة افتتاحية قوية (سطر واحد)\n[/HOOK]\n\n"
        . "[CONTENT]\nباقي نص المنشور بعد الـ Hook (60-150 كلمة، إيموجي معتدل)\n[/CONTENT]\n\n" . $tags
        . "[DESIGN_IDEA]\nفكرة التصميم بالعربي في جملتين: التكوين، العناصر، الألوان، والنص الظاهر\n[/DESIGN_IDEA]\n\n" . $img;
}

/** شرائح الكاروسيل من [SLIDES]: «شريحة 1 | عنوان | نص | فكرة تصميم» */
function campaign_parse_slides(string $block, int $count): array
{
    $out = [];
    foreach (preg_split('/\R/u', $block) as $line) {
        $line = trim($line);
        if ($line === '' || !preg_match('/^(?:[-•*]\s*)?(?:شريحة|سلايد|slide)?\s*(\d{1,2})\s*[|:\-–—.)]\s*(.+)$/iu', $line, $m)) continue;
        $parts = array_map('trim', explode('|', $m[2]));
        $out[(int) $m[1]] = ['title' => $parts[0] ?? '', 'text' => $parts[1] ?? '', 'design' => $parts[2] ?? ''];
    }
    ksort($out);
    return content_slides_clean(array_values($out), $count);
}

function campaign_tag(string $response, string $tag): string
{
    return preg_match('/\[' . $tag . '\](.*?)\[\/' . $tag . '\]/su', $response, $m) ? trim($m[1]) : '';
}

/** @return array{hook:string, body:string, cta:string, tags:string, design:string, script:string, image_prompt:string} */
function campaign_parse_content(string $response): array
{
    $body = campaign_tag($response, 'CONTENT');
    $hook = campaign_tag($response, 'HOOK');
    if ($body === '' && $hook === '') {
        $body = trim(preg_replace('/\[\/?[A-Z_]+\]/', '', $response));
    }
    return [
        'hook' => $hook, 'body' => $body,
        'cta' => campaign_tag($response, 'CTA'), 'tags' => campaign_tag($response, 'HASHTAGS'),
        'design' => campaign_tag($response, 'DESIGN_IDEA'), 'script' => campaign_tag($response, 'SCRIPT'),
        'image_prompt' => campaign_tag($response, 'IMAGE_PROMPT'),
        'slides_raw' => campaign_tag($response, 'SLIDES'), 'shoot' => campaign_tag($response, 'SHOOT'),
    ];
}

/** نص المنشور = الـ Hook + سطر فاضي + الباقي (ده اللي بيتنشر) */
function campaign_compose_text(string $hook, string $body): string
{
    return trim(trim($hook) . "\n\n" . trim($body));
}

/** العكس: أول فقرة = الـ Hook */
function campaign_split_text(string $text): array
{
    $text = trim(str_replace("\r\n", "\n", $text));
    $p = strpos($text, "\n\n");
    if ($p === false) return ['hook' => '', 'body' => $text];
    $hook = trim(substr($text, 0, $p));
    // Hook طويل = مش hook (نص اتكتب في المكتبة من غير فصل)
    if (mb_strlen($hook) > 220) return ['hook' => '', 'body' => $text];
    return ['hook' => $hook, 'body' => trim(substr($text, $p + 2))];
}

/** برومبت التقييم (JSON) — التعليمات والمعايير ودرجة النجاح من الأدمن (campaign_eval) */
function campaign_eval_prompt(array $brand, array $c, array $idea, array $content): string
{
    $cats = eval_criteria();
    $data = campaign_brand_block($brand) . "\n\n"
        . "هدف الحملة: " . (campaign_flow_bases()[$c['basis'] ?? ''] ?? 'تسويق عام') . "\n"
        . "الفكرة: {$idea['title']}" . (!empty($idea['audience']) ? " — الجمهور: {$idea['audience']}" : '') . "\n"
        . campaign_idea_targets($brand, $idea) . "\n"
        . campaign_eval_body($content) . "\n"
        . "CTA: " . (string) ($content['cta'] ?? '');
    $example = implode(',', array_map(fn($k) => 80 + ($k * 3) % 15, array_keys($cats)));
    if (prompt_full('campaign_eval')) {
        return campaign_eval_full($brand, $content, "هدف الحملة: " . (campaign_flow_bases()[$c['basis'] ?? ''] ?? 'تسويق عام') . "\n"
            . "الفكرة: {$idea['title']}" . (!empty($idea['audience']) ? " — الجمهور: {$idea['audience']}" : '') . "\n" . campaign_idea_targets($brand, $idea));
    }
    return prompt_fill('campaign_eval', ['cats' => implode('، ', $cats), 'ready' => eval_ready_score(), 'data' => $data])
        . "\n\ncats = " . count($cats) . " أرقام بنفس ترتيب المعايير. أعد JSON فقط:\n"
        . '{"cats":[' . $example . '],"comment":"...","issues":""}';
}

/** تقييم بالبرومبت الكامل (v1): المنشور في المتغيرات + المعايير ودرجة النجاح من الأدمن في شكل الرد */
function campaign_eval_full(array $brand, array $content, string $notes = ''): string
{
    $cats = eval_criteria();
    $uid = (int) ($brand['user_id'] ?? $content['user_id'] ?? 0);
    $vars = ['cats' => implode('، ', $cats), 'ready' => eval_ready_score(),
        'brand_identity' => campaign_brand_block($brand + ['user_id' => $uid]),
        'post_text' => campaign_eval_body($content), 'post_cta' => (string) ($content['cta'] ?? ''), 'post_hashtags' => (string) ($content['hashtags'] ?? ''),
        'design_description' => (string) ($content['design_direction'] ?? ''), 'content_type' => content_format_label(content_format_key($content['format'] ?? 'post')),
        'extra_notes' => $notes];
    $example = implode(',', array_map(fn($k) => 80 + ($k * 3) % 15, array_keys($cats)));
    return prompt_fill('campaign_eval', $vars, ['uid' => $uid, 'brand' => $brand, 'dna' => false])
        . prompt_format_head()
        . 'قيّم كل معيار من 100 بالترتيب ده: ' . implode('، ', $cats) . ".\n"
        . 'لو المتوسط أقل من ' . eval_ready_score() . " اكتب issues: أهم التحسينات بالظبط (عملية ومحددة، بجمل جاهزة). comment: جملة واحدة بالعامية على نقطة القوة.\n"
        . 'cats = ' . count($cats) . " أرقام بنفس ترتيب المعايير. أعد JSON فقط:\n"
        . '{"cats":[' . $example . '],"comment":"...","issues":""}';
}

/** نص التقييم حسب الشكل: الكاروسيل بشرائحه · الفيديو بسكريبته */
function campaign_eval_body(array $content): string
{
    $f = content_format_key($content['format'] ?? 'post');
    $t = 'المحتوى (' . content_format_label($f) . "):\n" . mb_substr((string) $content['generated_text'], 0, 2500) . "\n";
    if ($f === 'carousel') {
        foreach (content_slides($content) as $sl) $t .= 'شريحة ' . $sl['n'] . ': ' . $sl['title'] . ' — ' . $sl['text'] . "\n";
    }
    if ($f === 'video') {
        $t .= "سكريبت الفيديو:\n" . mb_substr(video_script_text(video_brief($content)), 0, 2500) . "\n";
    }
    return $t;
}

/** @return array{score:int, cats:int[], comment:string, issues:string}|null */
function campaign_parse_eval(string $response): ?array
{
    $j = campaign_json($response);
    if (!$j || empty($j['cats']) || !is_array($j['cats'])) return null;
    $labels = eval_criteria();
    $nc = count($labels);
    $cats = array_map(fn($v) => max(0, min(100, (int) $v)), array_slice(array_values($j['cats']), 0, $nc));
    if (count($cats) < $nc) $cats = array_pad($cats, $nc, (int) round(array_sum($cats) / max(1, count($cats))));
    $score = isset($j['score']) ? max(0, min(100, (int) $j['score'])) : (int) round(array_sum($cats) / $nc);
    return [
        'score' => $score, 'cats' => $cats, 'labels' => $labels,
        'comment' => mb_substr(trim((string) ($j['comment'] ?? '')), 0, 300),
        'issues' => $score < eval_ready_score() ? mb_substr(trim((string) ($j['issues'] ?? '')), 0, 500) : '',
    ];
}

/* ═══════════════ البيانات ═══════════════ */

function campaign_prefs(array $c): array
{
    $p = json_decode((string) ($c['prefs_json'] ?? ''), true);
    return (is_array($p) ? $p : []) + ['platform' => 'facebook', 'hour' => null];
}

/* ═══════════════ إعداد الحملة (المواضيع · العملاء · الشخصيات · المطلوب) ═══════════════ */

/** اللي العميل اختاره في أول الحملة — بيتحفظ في prefs_json.setup */
function campaign_setup(array $c): array
{
    $s = campaign_prefs($c)['setup'] ?? [];
    $s = is_array($s) ? $s : [];
    $cnt = (array) ($s['counts'] ?? []);
    return [
        'services' => array_values(array_map('intval', (array) ($s['services'] ?? []))),
        'topics' => array_values(array_filter(array_map('strval', (array) ($s['topics'] ?? [])))),
        'personas' => array_values(array_map('intval', (array) ($s['personas'] ?? []))),
        'characters' => array_values(array_map('intval', (array) ($s['characters'] ?? []))),
        'counts' => ['post' => (int) ($cnt['post'] ?? 4), 'carousel' => (int) ($cnt['carousel'] ?? 2), 'story' => (int) ($cnt['story'] ?? 0), 'video' => (int) ($cnt['video'] ?? 1)],
        'cta' => (string) ($s['cta'] ?? ''),
        'set' => !empty($s['counts']),
    ];
}

/** تنضيف الإعداد اللي جاي من الواجهة (الملكية بتتشيك) */
function campaign_setup_clean(int $uid, $in): array
{
    $in = is_array($in) ? $in : [];
    $own = fn(string $sql) => array_map('intval', array_column(db_all($sql, [$uid]), 'id'));
    $per = function_exists('bk_personas') ? array_map('intval', array_column(bk_personas($uid), 'id')) : [];
    $chars = $own('SELECT id FROM brand_characters WHERE user_id = ? AND is_active = 1');
    $svcs = $own('SELECT id FROM brand_services WHERE user_id = ? AND is_active = 1');
    $counts = [];
    foreach (['post', 'carousel', 'story', 'video'] as $k) $counts[$k] = max(0, min(15, (int) ($in['counts'][$k] ?? 0)));
    $sum = array_sum($counts);
    if ($sum > CAMP_MAX_IDEAS) foreach ($counts as $k => $v) $counts[$k] = (int) floor($v * CAMP_MAX_IDEAS / $sum);
    return [
        'services' => array_values(array_slice(array_intersect(array_map('intval', (array) ($in['services'] ?? [])), $svcs), 0, 3)),
        'topics' => array_values(array_slice(array_filter(array_map(fn($t) => mb_substr(trim((string) $t), 0, 255), (array) ($in['topics'] ?? []))), 0, 12)),
        'personas' => array_values(array_slice(array_intersect(array_map('intval', (array) ($in['personas'] ?? [])), $per), 0, 6)),
        'characters' => array_values(array_slice(array_intersect(array_map('intval', (array) ($in['characters'] ?? [])), $chars), 0, 4)),
        'counts' => $counts,
        'cta' => mb_substr(trim((string) ($in['cta'] ?? '')), 0, 200),
    ];
}

/** ترتيب الأشكال على الأفكار (متوزّعة مش ورا بعض): 4 منشور + 2 كاروسيل ← منشور، كاروسيل، منشور، منشور، كاروسيل، منشور */
function campaign_format_sequence(array $counts): array
{
    $left = array_filter($counts, fn($v) => $v > 0);
    $total = array_sum($left);
    $seq = [];
    $done = array_fill_keys(array_keys($left), 0);
    for ($i = 0; $i < $total; $i++) {
        $best = null; $bestGap = -INF;
        foreach ($left as $k => $n) {
            if ($done[$k] >= $n) continue;
            $gap = ($i + 1) * $n / $total - $done[$k];   // مين متأخر عن نصيبه أكتر
            if ($gap > $bestGap) { $bestGap = $gap; $best = $k; }
        }
        $seq[] = $best;
        $done[$best]++;
    }
    return $seq;
}

/** نص الخطة للبرومبت (المواضيع · العملاء · الشخصيات · الأشكال) */
function campaign_setup_plan(int $uid, array $setup, int $n, array $formats): string
{
    $lbl = ['post' => 'منشور', 'carousel' => 'كاروسيل', 'story' => 'ستوري', 'video' => 'فيديو'];
    $L = ['خطة الحملة اللي العميل اختارها (التزم بيها):'];
    $L[] = '- أشكال الأفكار دي بالترتيب (formats لكل فكرة = الشكل ده بس): ' . implode('، ', array_map(fn($f) => $lbl[$f] ?? $f, $formats));
    if ($sv = campaign_setup_services($uid, $setup)) {
        $L[] = '- الحملة عن ' . (count($sv) > 1 ? 'الخدمات دي' : 'الخدمة دي') . ' (معلومة عامة لكل الأفكار — service = ' . (count($sv) > 1 ? 'واحدة منهم' : 'الاسم ده') . ' بالظبط، والمواضيع والزوايا من جواها — حتى لو فيه خدمة شهر تانية في الهوية):';
        foreach ($sv as $x) $L[] = '  • ' . $x['name'] . (trim((string) $x['description']) !== '' ? ' — ' . mb_substr(trim((string) $x['description']), 0, 300) : '');
    }
    if ($setup['topics']) $L[] = '- وزّع الأفكار على المواضيع دي (topic = واحد منهم بالظبط): ' . implode('، ', $setup['topics']);
    if ($setup['personas'] && function_exists('bk_persona')) {
        $names = [];
        foreach ($setup['personas'] as $pid) if ($p = bk_persona($uid, $pid)) $names[] = $p['name'] . ($p['pains'] ? ' (وجعه: ' . implode('، ', array_slice($p['pains'], 0, 2)) . ')' : '');
        if ($names) $L[] = '- كل فكرة لعميل مثالي واحد من دول (persona = الاسم بالظبط) ووزّعهم: ' . implode(' | ', $names);
    }
    if ($setup['characters']) {
        $nm = array_column(db_all('SELECT name FROM brand_characters WHERE user_id = ? AND id IN (' . implode(',', array_map('intval', $setup['characters'])) . ')', [$uid]), 'name');
        if ($nm) $L[] = '- الشخصيات اللي هتظهر في الحملة: ' . implode('، ', $nm) . ' — خلّي الأفكار تستخدمهم بشكل طبيعي';
    }
    if ($setup['cta'] !== '') $L[] = '- الـ CTA بتاع الحملة: ' . $setup['cta'];
    return implode("\n", $L);
}

/** الخدمات الرئيسية اللي العميل اختارها للحملة (بالترتيب) */
function campaign_setup_services(int $uid, array $setup): array
{
    if (empty($setup['services']) || !function_exists('bk_services')) return [];
    $by = [];
    foreach (bk_services($uid, true) as $x) $by[(int) $x['id']] = $x;
    return array_values(array_filter(array_map(fn($id) => $by[$id] ?? null, $setup['services'])));
}

/** مين هيظهر في الفكرة رقم $k: الخدمة · العميل المثالي (بالاسم من الـ AI أو بالدور) · الموضوع · الشخصيات */
function campaign_setup_assign(int $uid, array $setup, array $idea, int $k): array
{
    $b = (array) ($idea['brief'] ?? []);
    if ($sv = campaign_setup_services($uid, $setup)) {
        $names = array_column($sv, 'name');
        $want = mb_strtolower(trim((string) ($b['service'] ?? '')));
        $hit = null;
        foreach ($names as $nm) if ($want !== '' && mb_strtolower(trim($nm)) === $want) { $hit = $nm; break; }
        $b['service'] = $hit ?? $names[$k % count($names)];
    }
    if ($setup['personas'] && function_exists('bk_persona')) {
        $pid = 0;
        $want = mb_strtolower(trim((string) ($idea['persona'] ?? '')));
        foreach ($setup['personas'] as $id) {
            $p = bk_persona($uid, $id);
            if ($p && $want !== '' && (str_contains(mb_strtolower($p['name']), $want) || str_contains($want, mb_strtolower($p['name'])))) { $pid = $id; break; }
        }
        $b['persona_id'] = $pid ?: $setup['personas'][$k % count($setup['personas'])];
        if ($p = bk_persona($uid, $b['persona_id'])) $b['persona'] = $p['name'];
    }
    if ($setup['topics'] && (empty($b['topic']) || !in_array($b['topic'], $setup['topics'], true))) $b['topic'] = $setup['topics'][$k % count($setup['topics'])];
    if (!$setup['characters']) $b['no_characters'] = 1;
    if ($setup['characters']) {
        $b['character_ids'] = $setup['characters'];
        $b['character_names'] = array_column(db_all('SELECT name FROM brand_characters WHERE user_id = ? AND id IN (' . implode(',', array_map('intval', $setup['characters'])) . ')', [$uid]), 'name');
    }
    if ($setup['cta'] !== '') $b['cta'] = $setup['cta'];
    return $b;
}

/** اختيارات شاشة الإعداد */
function campaign_setup_options(int $uid): array
{
    require_once __DIR__ . '/uploader.php';
    $topics = [];
    try {
        foreach (db_all('SELECT title FROM brand_topics WHERE user_id = ? AND is_active = 1 ORDER BY times_used, id DESC LIMIT 30', [$uid]) as $t) $topics[] = $t['title'];
    } catch (\Throwable $e) {
    }
    $chars = [];
    foreach (function_exists('bk_characters') ? bk_characters($uid) : [] as $ch) {
        if (!(int) $ch['is_active'] || !$ch['images'] || ($ch['type'] === 'real_person' && !(int) $ch['consent_confirmed'])) continue;
        $chars[] = ['id' => (int) $ch['id'], 'name' => $ch['name'], 'type' => $ch['type'], 'img' => upload_url($ch['images'][0]['image_path'])];
    }
    $kit = function_exists('bk_get') ? bk_get($uid)['data'] : [];
    // الخدمات الرئيسية بس (أسماء) — المواضيع جواها الـ AI بيختارها
    $services = array_map(fn($x) => ['id' => (int) $x['id'], 'name' => (string) $x['name'], 'focus' => (bool) $x['is_focus']],
        function_exists('bk_services') ? bk_services($uid, true) : []);
    return [
        'services' => $services,
        'topics' => $topics,
        'personas' => array_map(fn($p) => ['id' => (int) $p['id'], 'name' => $p['name'], 'emoji' => $p['emoji'] ?: '🙂',
            'hint' => mb_substr(implode(' · ', array_filter([trim(implode('، ', array_filter([$p['age'] ?? '', $p['job'] ?? '']))), $p['pains'][0] ?? ''])), 0, 90),
            'services' => $p['service_ids']], function_exists('bk_personas') ? bk_personas($uid) : []),
        'characters' => $chars,
        'cta' => (string) ($kit['cta']['text'] ?? ''),
    ];
}

/**
 * منشورات الحملة اللي اتعملت قبل ⑥-ب (من «＋ منشور للحملة») — بنلفّها كأفكار
 * علشان كل المراحل تشتغل على نفس القايمة
 */
function campaign_adopt_contents(array $c): void
{
    $orphans = db_all('SELECT c.id, c.generated_text, c.content_type FROM contents c
                       LEFT JOIN campaign_ideas ci ON ci.content_id = c.id
                       WHERE c.campaign_id = ? AND c.user_id = ? AND ci.id IS NULL ORDER BY c.id LIMIT 50',
        [$c['id'], $c['user_id']]);
    if (!$orphans) return;
    $n = (int) (db_one('SELECT COALESCE(MAX(sort_order), 0) m FROM campaign_ideas WHERE campaign_id = ?', [$c['id']])['m'] ?? 0);
    foreach ($orphans as $o) {
        $first = trim(strtok(trim((string) $o['generated_text']) ?: 'منشور', "\n"));
        db_insert('INSERT INTO campaign_ideas (campaign_id, user_id, sort_order, angle, title, selected, content_id, approved)
                   VALUES (?,?,?,?,?,1,?,1)',
            [$c['id'], $c['user_id'], ++$n, 'منشور مضاف', mb_substr($first, 0, 120), $o['id']]);
    }
}

/** التصميمات لكل منشور (الأحدث أولًا) */
function campaign_designs(array $contentIds): array
{
    if (!$contentIds) return [];
    require_once __DIR__ . '/uploader.php';
    $in = implode(',', array_map('intval', $contentIds));
    $out = [];
    $slideCol = formats_ready() ? 'slide_no' : 'NULL slide_no';
    foreach (db_all("SELECT id, content_id, image_path, ratio, {$slideCol} FROM content_designs WHERE content_id IN ($in) ORDER BY id DESC") as $d) {
        $out[(int) $d['content_id']][] = ['id' => (int) $d['id'], 'url' => upload_url($d['image_path']), 'ratio' => (string) ($d['ratio'] ?? '1:1'),
                                          'slide' => (int) ($d['slide_no'] ?? 0), 'image_path' => $d['image_path']];
    }
    return $out;
}

/** تصميمات منشور مجمّعة بالشريحة (نفس شكل content_designs_by_slide) */
function campaign_designs_slides(array $designs): array
{
    $by = [];
    foreach ($designs as $d) $by[$d['slide']][] = ['id' => $d['id'], 'image_path' => $d['image_path'], 'ratio' => $d['ratio']];
    return $by;
}

/** فكرة واحدة بكل مراحلها — للواجهة */
function campaign_idea_api(array $i, ?array $content, array $designs): array
{
    $script = json_decode((string) ($i['script_json'] ?? ''), true) ?: null;
    $eval = json_decode((string) ($i['eval_json'] ?? ''), true) ?: null;
    $co = null;
    if ($content) {
        $sp = campaign_split_text((string) $content['generated_text']);
        $st = content_status($content, (bool) $designs);
        $co = [
            'id' => (int) $content['id'], 'hook' => $sp['hook'], 'body' => $sp['body'],
            'cta' => (string) ($content['cta'] ?? ''), 'tags' => (string) ($content['hashtags'] ?? ''),
            'design_idea' => (string) ($content['design_direction'] ?? ''),
            'rev' => (string) $content['updated_at'],
            'status' => content_status_meta($st),
            'selected_design' => (int) ($content['selected_image_id'] ?? 0),
            'publish' => [
                'status' => (string) ($content['publish_status'] ?? 'draft'),
                'at' => $content['scheduled_at'] ?? null,
                'error' => (string) ($content['publish_error'] ?? ''),
            ],
        ];
    }
    $fmt = $content ? content_format_key($content['format'] ?? 'post') : campaign_idea_format($i);
    $fx = $content ? content_format_api($content, campaign_designs_slides($designs)) : ['format' => $fmt, 'format_label' => content_format_label($fmt)];
    return [
        'format' => $fmt, 'format_label' => content_format_label($fmt),
        'slides_count' => (int) ($content['slides_count'] ?? $i['slides_count'] ?? 0) ?: carousel_limits()[2],
        'slides' => $fx['slides'] ?? [], 'video' => $fx['video'] ?? null, 'progress' => $fx['progress'] ?? null,
        'id' => (int) $i['id'], 'n' => str_pad((string) $i['sort_order'], 2, '0', STR_PAD_LEFT),
        'angle' => (string) ($i['angle'] ?? ''), 'title' => (string) $i['title'],
        'desc' => (string) ($i['description'] ?? ''), 'audience' => (string) ($i['audience'] ?? ''),
        'hook' => (string) ($i['hook'] ?? ''),
        'formats' => array_values(array_filter(explode('|', (string) ($i['formats'] ?? '')))),
        'selected' => (bool) $i['selected'], 'approved' => (bool) $i['approved'],
        'content' => $co,
        // بريف البوست: من الفكرة (خدمة · موضوع · اتجاه) + اللي اتكتب بيه المحتوى + «ليه الـ AI عمل كده»
        'brief' => array_filter(array_merge((array) (json_decode((string) ($i['brief_json'] ?? ''), true) ?: []),
            array_intersect_key((array) (json_decode((string) ($content['brief_json'] ?? ''), true) ?: []), array_flip(['service', 'topic', 'pillar', 'method', 'character', 'hook'])))) ?: null,
        'reasons' => $content ? (json_decode((string) ($content['ai_reasoning'] ?? ''), true) ?: []) : [],
        'script' => $script,
        'eval' => $eval ? $eval + ['ready' => (int) ($eval['score'] ?? 0) >= eval_ready_score()] : null,
        'designs' => $designs,
        'plan' => $i['plan_at'] ? ['at' => substr((string) $i['plan_at'], 0, 16), 'platform' => (string) ($i['plan_platform'] ?: 'facebook')] : null,
    ];
}

/** لوحة الحملة كاملة */
function campaign_board(array $c): array
{
    campaign_adopt_contents($c);
    $ideas = db_all('SELECT * FROM campaign_ideas WHERE campaign_id = ? AND user_id = ? ORDER BY sort_order, id', [$c['id'], $c['user_id']]);
    $cids = array_values(array_filter(array_map(fn($i) => (int) $i['content_id'], $ideas)));
    $contents = [];
    if ($cids) {
        foreach (db_all('SELECT * FROM contents WHERE user_id = ? AND id IN (' . implode(',', $cids) . ')', [$c['user_id']]) as $r) {
            $contents[(int) $r['id']] = $r;
        }
    }
    $designs = campaign_designs($cids);
    return array_map(fn($i) => campaign_idea_api($i, $contents[(int) $i['content_id']] ?? null, $designs[(int) $i['content_id']] ?? []), $ideas);
}

/** أرقام الحملة (للقايمة والمرحلة 6) */
function campaign_flow_counts(int $campaignId): array
{
    try {
        $r = db_one('SELECT COUNT(*) ideas, SUM(ci.selected) selected, SUM(ci.content_id IS NOT NULL AND ci.selected) posts,
                            SUM(ci.script_json IS NOT NULL AND ci.selected) scripts, SUM(ci.eval_score IS NOT NULL AND ci.selected) evaluated,
                            SUM(ci.plan_at IS NOT NULL AND ci.selected) planned, COUNT(DISTINCT DATE(CASE WHEN ci.selected THEN ci.plan_at END)) days,
                            SUM(CASE WHEN ci.selected AND EXISTS (SELECT 1 FROM content_designs d WHERE d.content_id = ci.content_id) THEN 1 ELSE 0 END) designed,
                            SUM(CASE WHEN ci.selected AND c.publish_status = "published" THEN 1 ELSE 0 END) published,
                            SUM(CASE WHEN ci.selected AND c.publish_status IN ("pending","processing","scheduled") THEN 1 ELSE 0 END) queued
                     FROM campaign_ideas ci LEFT JOIN contents c ON c.id = ci.content_id
                     WHERE ci.campaign_id = ?', [$campaignId]) ?: [];
    } catch (\Throwable $e) {
        $r = [];   // قبل ترحيل ⑥-ب
    }
    $out = [];
    foreach (['ideas', 'selected', 'posts', 'scripts', 'evaluated', 'planned', 'days', 'designed', 'published', 'queued'] as $k) {
        $out[$k] = (int) ($r[$k] ?? 0);
    }
    return $out;
}

/* ═══════════════ الجدولة التلقائية ═══════════════ */

/**
 * توزيع المنشورات على الشهر:
 *  • من بكرة (أو أول الشهر لو الحملة لشهر جاي) لآخر شهر الحملة — ولو فاضل أقل من أسبوع: 30 يوم من بكرة
 *  • مسافات متساوية، ومفيش يومين ورا بعض لو فيه مكان
 *  • الساعة: ساعة النشر الافتراضية من الإعدادات، والتفاعلي بالليل والتعليمي الضهر
 * @return int عدد المنشورات اللي اتوزعت
 */
function campaign_auto_distribute(array $c, array $ideaRows, string $platform, bool $onlyUnplanned = false): int
{
    // المعتمد بس (أو كله لو التقييم اتخطى) — نفس اللي بيظهر في مرحلة التصميم والجدولة
    $skip = !empty($c['eval_skipped']);
    // الفيديو مش بيتجدول هنا — بيتنشر بعد التنفيذ اليدوي
    $items = array_values(array_filter($ideaRows, fn($i) => $i['selected'] && $i['content_id'] && ($skip || $i['approved'])
        && (!$onlyUnplanned || !$i['plan_at']) && ($i['cformat'] ?? $i['format'] ?? '') !== 'video'));
    if (!$items) return 0;

    $tomorrow = strtotime('tomorrow');
    $month = preg_match('/^\d{4}-\d{2}$/', (string) ($c['period_month'] ?? '')) ? $c['period_month'] : date('Y-m');
    $start = max($tomorrow, strtotime($month . '-01'));
    $end = strtotime(date('Y-m-t', strtotime($month . '-01')) . ' 23:59:59');
    if ($end - $start < 7 * 86400) {
        $start = $tomorrow;
        $end = $tomorrow + 29 * 86400;
    }
    $days = (int) floor(($end - $start) / 86400) + 1;
    $n = count($items);
    $step = $days / $n;

    $baseHour = function_exists('default_publish_hour') ? default_publish_hour() : 21;
    $done = 0;
    foreach ($items as $k => $i) {
        $idx = $step >= 1 ? min($days - 1, (int) floor($k * $step + ($step - 1) / 2)) : ($k % $days);   // أقل من 1 = أكتر من منشور في اليوم
        $day = $start + $idx * 86400;
        $angle = (string) $i['angle'];
        $hour = str_contains($angle, 'تعليمي') ? 13 : (str_contains($angle, 'تفاعل') ? 21 : $baseHour);
        // لو أكتر من منشور في نفس اليوم: نبعّد الساعات
        if ($step < 1) $hour = [13, 17, 21][intdiv($k, $days) % 3];
        $at = date('Y-m-d', $day) . ' ' . str_pad((string) $hour, 2, '0', STR_PAD_LEFT) . ':00:00';
        db_run('UPDATE campaign_ideas SET plan_at = ?, plan_platform = ? WHERE id = ?', [$at, $platform, $i['id']]);
        $done++;
    }
    return $done;
}

/** كل بيانات شاشة الحملة (للصفحة أول ما تفتح وللـ API) */
function campaign_board_payload(array $c, int $uid): array
{
    require_once __DIR__ . '/social.php';
    require_once __DIR__ . '/brand-brain.php';
    require_once __DIR__ . '/credits.php';
    $brand = brand_for_user($uid);
    $h = brand_health($brand);
    $pages = [];
    foreach (user_connections($uid) as $cn) {
        $pages[] = ['id' => (int) $cn['id'], 'name' => (string) $cn['page_name'], 'avatar' => (string) ($cn['page_avatar_url'] ?? ''),
                    'ig' => !empty($cn['ig_user_id'])];
    }
    return [
        'campaign' => campaign_to_api($c) + ['eval_skipped' => !empty($c['eval_skipped']), 'period_month' => (string) $c['period_month'],
                                             'prefs' => campaign_prefs($c)],
        'setup'    => campaign_setup($c),
        'setup_options' => campaign_setup_options($uid),
        'eval_labels' => eval_criteria(), 'eval_ready' => eval_ready_score(),
        'plan_eval' => campaign_prefs($c)['plan_eval'] ?? null,
        'ideas'    => campaign_board($c),
        // Brand Kit: بريف الحملة (بيتبني مع أول دفعة أفكار)
        'brief'    => json_decode((string) ($c['brief_json'] ?? ''), true) ?: null,
        'counts'   => campaign_flow_counts((int) $c['id']),
        'bases'    => campaign_flow_bases(),
        'stages'   => campaign_stages(),
        'health'   => ['pct' => $h['pct'], 'gate' => $h['gate'], 'unlocked' => $h['unlocked']],
        'costs'    => [
            'balance' => credits_balance($uid),
            'content' => cost_for('content_generation_cost'),
            'regen'   => cost_for('content_regeneration_cost'),
            'design'  => cost_for('content_design_cost'),
            'eval'    => (int) get_setting('campaign_eval_cost', 0),
            'ideas'   => array_combine(range(1, CAMP_MAX_IDEAS), array_map('idea_credits_for', range(1, CAMP_MAX_IDEAS))),
            'plan_eval' => max(0, (int) get_setting('campaign_plan_eval_cost', '1')),
            'ai_edit' => cost_for('ai_edit_cost'),
        ],
        'publish'  => ['allowed' => feature_allows($uid), 'pages' => $pages, 'hour' => default_publish_hour(),
                       'plan' => function_exists('plan_flags') ? (plan_flags($uid)['name'] ?: '') : '',
                       'wa' => function_exists('publish_whatsapp_number') ? publish_whatsapp_number() : '',
                       'packages' => url('packages.php'), 'who' => (string) ($brand['business_name'] ?? '')],
        'limits'   => array_combine(['min', 'max', 'def'], carousel_limits()),
        'design_styles' => array_map(fn($r) => ['id' => (int) $r['id'], 'name' => $r['name']],
            db_all('SELECT id, name FROM style_presets WHERE kind = "design_style" AND is_active = 1 ORDER BY sort_order, id LIMIT 12')),
        'brand'    => ['colors' => array_slice(array_values(array_filter(preg_split('/[\s,،]+/u', (string) ($brand['colors'] ?? '')), fn($x) => preg_match('/^#[0-9a-f]{3,8}$/i', $x))), 0, 4),
                       'logo' => !empty($brand['logo_path'])],
    ];
}

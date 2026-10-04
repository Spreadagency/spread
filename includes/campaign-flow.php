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

const CAMP_IDEAS_BATCH = 5;          // أفكار في كل طلب AI
const CAMP_MAX_IDEAS = 30;
const CAMP_EVAL_READY = 80;          // من 80 = «محتوى جاهز»
const CAMP_EVAL_CATS = ['قوة الفكرة', 'قوة الـ Hook', 'مناسبة الجمهور', 'وضوح الرسالة', 'قوة CTA', 'التوافق مع البراند'];
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

/** سطور هوية البراند للبرومبتات */
function campaign_brand_block(array $brand): string
{
    $lines = [];
    foreach (['business_name' => 'اسم النشاط', 'industry' => 'المجال', 'description' => 'الوصف', 'audience' => 'الجمهور',
              'services' => 'الخدمات والمنتجات', 'tone' => 'النبرة', 'colors' => 'ألوان البراند',
              'keywords_use' => 'كلمات مفضلة', 'keywords_avoid' => 'كلمات ممنوعة'] as $k => $l) {
        if (!empty($brand[$k])) $lines[] = '- ' . $l . ': ' . mb_substr((string) $brand[$k], 0, 400);
    }
    if (!empty($brand['ai_summary'])) $lines[] = '- ملخص الهوية: ' . mb_substr((string) $brand['ai_summary'], 0, 600);
    $k = !empty($brand['id']) ? get_brand_knowledge((int) $brand['id']) : '';
    return "بيانات البراند:\n" . implode("\n", $lines)
        . ($k !== '' ? "\n\nمعلومات موثّقة عن البيزنس (مصدر حقائق — ماتخترعش حاجة تخالفها):\n" . mb_substr($k, 0, 3000) : '');
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

/** برومبت دفعة أفكار (JSON) — بيتجنب الأفكار اللي طلعت قبل كده */
function campaign_ideas_prompt(array $brand, array $c, int $n, array $avoidTitles): string
{
    $basis = campaign_flow_bases()[$c['basis'] ?? ''] ?? (campaign_bases()[$c['basis'] ?? ''] ?? 'تسويق عام');
    $details = trim((string) ($c['topic'] ?? '') . "\n" . (string) ($c['notes'] ?? ''));
    $fm = implode('، ', CAMP_FORMATS);
    return "أنت استراتيجي حملات سوشيال ميديا محترف. مطلوب {$n} أفكار لحملة شهرية واحدة متماسكة.\n\n"
        . campaign_brand_block($brand) . "\n\n"
        . "أساس الحملة: {$basis}\n"
        . "اسم الحملة: " . ($c['title'] ?? '') . "\n"
        . ($details !== '' ? "تفاصيل من العميل: {$details}\n" : '')
        . ($avoidTitles ? "\nأفكار اتعملت قبل كده في نفس الحملة — ماتكررهاش ولا تقرب منها:\n- " . implode("\n- ", array_slice($avoidTitles, 0, 40)) . "\n" : '')
        . "\nقواعد:\n"
        . "- كل فكرة زاوية مختلفة (خلف الكواليس، عرض وقيمة، تفاعل، موسم ومناسبة، آراء العملاء، تعليمي، قصة، سؤال شائع…)\n"
        . "- محددة وقابلة للتنفيذ فورًا ومربوطة بالبيزنس الحقيقي — مش عناوين عامة\n"
        . "- الـ Hook جملة افتتاحية قوية بـ " . campaign_dialect($brand) . "\n"
        . "- formats من القايمة دي بس: {$fm}\n\n"
        . "أعد JSON فقط بدون أي كلام قبله أو بعده بالشكل ده بالظبط:\n"
        . '{"ideas":[{"angle":"خلف الكواليس","title":"عنوان قصير","desc":"شرح في جملتين: الرسالة والزاوية","audience":"الجمهور المستهدف بالتحديد","hook":"جملة الـ Hook","formats":["منشور","ريلز"]}]}';
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
    return is_array($j) ? $j : null;
}

function campaign_parse_ideas(string $response): array
{
    $j = campaign_json($response);
    $out = [];
    foreach ((array) ($j['ideas'] ?? []) as $i) {
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
        ];
    }
    return $out;
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
function campaign_content_prompt(array $brand, array $c, array $idea, string $extra = '', ?string $format = null, int $slides = 0, string $duration = '30 ثانية'): string
{
    $format = $format ?: campaign_idea_format($idea);
    $basis = campaign_flow_bases()[$c['basis'] ?? ''] ?? 'تسويق عام';
    $head = "أنت كاتب محتوى تسويقي محترف. اكتب محتوى كامل لفكرة من حملة «" . ($c['title'] ?? '') . "» (أساسها: {$basis}).\n"
        . 'شكل المحتوى: ' . content_format_label($format) . ($format === 'carousel' ? " من {$slides} شرائح" : '') . "\n\n"
        . campaign_brand_block($brand) . "\n\n"
        . "الفكرة:\n- الزاوية: {$idea['angle']}\n- العنوان: {$idea['title']}\n"
        . (!empty($idea['description']) ? "- التفاصيل: {$idea['description']}\n" : '')
        . (!empty($idea['audience']) ? "- الجمهور: {$idea['audience']}\n" : '')
        . (!empty($idea['hook']) ? "- Hook مقترح: {$idea['hook']}\n" : '')
        . ($extra !== '' ? "\n{$extra}\n" : '')
        . "\nاللهجة: " . campaign_dialect($brand) . "\n"
        . "أعد الناتج بالتنسيق ده بالظبط بدون أي تعليق:\n\n";
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

/** برومبت التقييم (JSON) */
function campaign_eval_prompt(array $brand, array $c, array $idea, array $content): string
{
    $cats = implode('، ', CAMP_EVAL_CATS);
    return "أنت مراجع محتوى تسويقي صارم ومنصف. قيّم المنشور ده قبل ما يتصمم.\n\n"
        . campaign_brand_block($brand) . "\n\n"
        . "هدف الحملة: " . (campaign_flow_bases()[$c['basis'] ?? ''] ?? 'تسويق عام') . "\n"
        . "الفكرة: {$idea['title']}" . (!empty($idea['audience']) ? " — الجمهور: {$idea['audience']}" : '') . "\n\n"
        . campaign_eval_body($content) . "\n"
        . "CTA: " . (string) ($content['cta'] ?? '') . "\n\n"
        . "قيّم كل معيار من 100 بالترتيب ده: {$cats}.\n"
        . "لو المتوسط أقل من " . CAMP_EVAL_READY . " اكتب issues: إيه بالظبط اللي محتاج يتحسن (جملتين، عملي ومحدد).\n"
        . "comment: تعليق قصير (جملة واحدة) بالعامية على نقطة القوة.\n"
        . "أعد JSON فقط:\n"
        . '{"cats":[90,88,85,84,82,92],"comment":"...","issues":""}';
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
    $cats = array_map(fn($v) => max(0, min(100, (int) $v)), array_slice(array_values($j['cats']), 0, 6));
    if (count($cats) < 6) $cats = array_pad($cats, 6, (int) round(array_sum($cats) / max(1, count($cats))));
    $score = isset($j['score']) ? max(0, min(100, (int) $j['score'])) : (int) round(array_sum($cats) / 6);
    return [
        'score' => $score, 'cats' => $cats,
        'comment' => mb_substr(trim((string) ($j['comment'] ?? '')), 0, 300),
        'issues' => $score < CAMP_EVAL_READY ? mb_substr(trim((string) ($j['issues'] ?? '')), 0, 500) : '',
    ];
}

/* ═══════════════ البيانات ═══════════════ */

function campaign_prefs(array $c): array
{
    $p = json_decode((string) ($c['prefs_json'] ?? ''), true);
    return (is_array($p) ? $p : []) + ['platform' => 'facebook', 'hour' => null];
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
        'script' => $script,
        'eval' => $eval ? $eval + ['ready' => (int) ($eval['score'] ?? 0) >= CAMP_EVAL_READY] : null,
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
        'ideas'    => campaign_board($c),
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
            'ideas'   => array_combine([5, 10, 15, 20], array_map('idea_credits_for', [5, 10, 15, 20])),
        ],
        'publish'  => ['allowed' => feature_allows($uid), 'pages' => $pages, 'hour' => default_publish_hour()],
        'limits'   => array_combine(['min', 'max', 'def'], carousel_limits()),
        'brand'    => ['colors' => array_slice(array_values(array_filter(preg_split('/[\s,،]+/u', (string) ($brand['colors'] ?? '')), fn($x) => preg_match('/^#[0-9a-f]{3,8}$/i', $x))), 0, 4),
                       'logo' => !empty($brand['logo_path'])],
    ];
}

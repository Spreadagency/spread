<?php
/**
 * Spread AI — Prompt Builder (Phase 2 upgrade)
 *
 * Combines: admin's base prompt + brand profile + BRAND KNOWLEDGE (sources) + content options + user notes
 * ← يستبدل includes/prompt-builder.php الحالي (نفس الدوال + إضافة قسم المعرفة)
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/source-extract.php';

/**
 * Get the active prompt for a given type (or fallback to default)
 */
function get_active_prompt(string $type = 'content'): string
{
    try {
        $row = db_one(
            'SELECT prompt_text FROM prompts WHERE prompt_type = ? AND is_active = 1 ORDER BY version DESC LIMIT 1',
            [$type]
        );
        if ($row && trim((string) $row['prompt_text']) !== '') {
            return $row['prompt_text'];
        }
    } catch (\Throwable $e) {
    }
    return $type === 'content' ? default_base_prompt() : '';
}

/**
 * برومبت من الأدمن لو موجود، وإلا البرومبت المدمج في الكود
 * بيسمح للأدمن يتحكم في كل عمليات الـ AI من غير تعديل كود
 */
function prompt_or_default(string $type, string $codeDefault): string
{
    $admin = get_active_prompt($type);
    return trim($admin) !== '' ? $admin : $codeDefault;
}

/** أنواع البرومبت المدعومة في لوحة الأدمن */
function prompt_types(): array
{
    return [
        'content'         => ['✎ كتابة المنشور', 'البرومبت الأساسي لتوليد المنشورات'],
        'plan_ideas'      => ['💡 أفكار خطة المحتوى', 'توليد أفكار الخطة الشهرية'],
        'plan_produce'    => ['🚀 إنتاج بوست من فكرة', 'تحويل فكرة الخطة لمنشور كامل'],
        'brand_summary'   => ['◈ ملخص الهوية', 'تحليل الهوية وكتابة ملخص شامل'],
        'visual_identity' => ['🖌 الهوية البصرية', 'دراسة التصميمات واستخراج نقط الستايل'],
        'brand_agent'     => ['🤖 مساعد الهوية', 'تعليمات ايجنت المحادثة'],
        'source_summary'  => ['📄 تلخيص المستندات', 'تلخيص مستندات الهوية'],
        'design_base'     => ['🎨 أساس برومبت التصميم', 'التعليمات الثابتة في كل تصميم (إنجليزي)'],
    ];
}

function default_base_prompt(): string
{
    return <<<EOT
أنت كاتب محتوى تسويقي محترف ومتخصص في المحتوى العربي للسوشيال ميديا. مهمتك إنشاء منشور احترافي بناءً على بيانات البراند والمتطلبات اللي هيتم تزويدك بيها.

قواعد عامة:
- المحتوى لازم يكون باللغة العربية الفصحى المبسطة أو حسب اللهجة المطلوبة
- اكتب بأسلوب جذاب يتناسب مع الجمهور المستهدف
- خلي البداية لافتة للنظر (Hook)
- استخدم الإيموجي بشكل مناسب وغير مبالغ فيه
- اختم بـ Call-to-Action واضح
- أنشئ هاشتاجات مناسبة (5-10 هاشتاجات)

أعد الناتج بالتنسيق التالي بدون أي تعليق إضافي:

[CONTENT]
نص المنشور هنا...
[/CONTENT]

[HASHTAGS]
#هاشتاج1 #هاشتاج2 #هاشتاج3
[/HASHTAGS]

[CTA]
نص الـ Call-to-Action هنا
[/CTA]
EOT;
}

/**
 * Build the final prompt to send to the AI
 */
function build_final_prompt(array $brand, array $options, string $extraNotes = ''): string
{
    $base = get_active_prompt('content');

    $brandLines = [];
    if (!empty($brand['business_name'])) $brandLines[] = '- اسم النشاط: ' . $brand['business_name'];
    if (!empty($brand['industry'])) $brandLines[] = '- المجال: ' . $brand['industry'];
    if (!empty($brand['description'])) $brandLines[] = '- وصف البراند: ' . $brand['description'];
    if (!empty($brand['audience'])) $brandLines[] = '- الجمهور المستهدف: ' . $brand['audience'];
    if (!empty($brand['tone'])) $brandLines[] = '- نبرة الكتابة المفضلة: ' . $brand['tone'];
    if (!empty($brand['keywords_use'])) $brandLines[] = '- كلمات يفضل استخدامها: ' . $brand['keywords_use'];
    if (!empty($brand['keywords_avoid'])) $brandLines[] = '- كلمات ممنوع استخدامها: ' . $brand['keywords_avoid'];
    if (!empty($brand['notes'])) $brandLines[] = '- ملاحظات إضافية للبراند: ' . $brand['notes'];
    if (!empty($brand['ai_summary'])) $brandLines[] = '- ملخص الهوية: ' . mb_substr($brand['ai_summary'], 0, 800);

    // الخدمات وبيانات التواصل — مصدر حقائق للمحتوى والعروض والـ CTA
    if (!empty($brand['services']))      $brandLines[] = "- الخدمات/المنتجات:
" . mb_substr($brand['services'], 0, 1200);
    if (!empty($brand['address']))       $brandLines[] = '- العنوان: ' . $brand['address'];
    if (!empty($brand['phones']))        $brandLines[] = '- أرقام التواصل: ' . $brand['phones'];
    if (!empty($brand['whatsapp']))      $brandLines[] = '- واتساب: ' . $brand['whatsapp'];
    if (!empty($brand['working_hours'])) $brandLines[] = '- مواعيد العمل: ' . $brand['working_hours'];
    if (!empty($brand['website']))       $brandLines[] = '- الموقع: ' . $brand['website'];
    $__social = [];
    foreach (['social_facebook' => 'فيسبوك', 'social_instagram' => 'انستجرام', 'social_tiktok' => 'تيك توك', 'social_linkedin' => 'لينكدإن'] as $k => $lbl) {
        if (!empty($brand[$k])) { $__social[] = $lbl . ': ' . $brand[$k]; }
    }
    if ($__social) $brandLines[] = '- صفحات السوشيال: ' . implode(' · ', $__social);
    $brandLines[] = 'ملاحظة: استخدم بيانات التواصل دي في الـ CTA لما تكون مناسبة، ولا تخترع أرقام أو عناوين غير الموجودة هنا.';

    $brandSection = !empty($brandLines)
        ? "بيانات البراند:\n" . implode("\n", $brandLines)
        : 'لا توجد بيانات هوية محددة، استخدم أسلوب عام احترافي.';

    // ─── PHASE 2: قسم المعرفة من مستندات الهوية ─────────────
    $knowledgeSection = '';
    if (!empty($brand['id'])) {
        $knowledge = get_brand_knowledge((int) $brand['id']);
        if ($knowledge !== '') {
            $knowledgeSection = "\n\nمعلومات موثّقة عن البيزنس (من مستندات العميل — استخدمها كمصدر حقائق، والتزم بها ولا تخترع معلومات تخالفها):\n" . $knowledge;
        }
    }
    // ────────────────────────────────────────────────────────

    $contentType = $options['content_type'] ?? 'تعريفي';
    $platform = match ($options['platform'] ?? 'both') {
        'facebook' => 'فيسبوك',
        'instagram' => 'إنستجرام',
        default => 'فيسبوك وإنستجرام'
    };
    $length = match ($options['length'] ?? 'medium') {
        'short' => 'قصير (50-80 كلمة)',
        'medium' => 'متوسط (100-180 كلمة)',
        'long' => 'طويل (200-300 كلمة)',
        default => 'متوسط'
    };
    $tone = $options['tone'] ?? ($brand['tone'] ?? 'بسيط');

    // Dialect (feature 27)
    $dialect = match ($options['dialect'] ?? 'egyptian') {
        'gulf' => 'اللهجة الخليجية البيضاء',
        'msa' => 'العربية الفصحى المبسطة',
        default => 'اللهجة المصرية الخفيفة'
    };

    // Template snippet (feature 27)
    $templateSection = '';
    if (!empty($options['template_id'])) {
        $tpl = db_one('SELECT prompt_snippet FROM content_templates WHERE id = ? AND is_active = 1', [(int) $options['template_id']]);
        if ($tpl) {
            $templateSection = "\n\nقالب المنشور المطلوب:\n" . $tpl['prompt_snippet'];
        }
    }

    // برومبت مخصص لنوع المحتوى ده (من الأدمن)
    $typeSection = '';
    $__tp = type_prompt('content', (string) ($options['content_type'] ?? ''));
    if ($__tp !== '') {
        $typeSection = "\n\nتعليمات خاصة بنوع المحتوى ده (التزم بيها):\n" . $__tp;
    }

    $notesSection = $extraNotes
        ? "\nتعليمات إضافية من العميل (ملاحظة: هذه تفضيلات أسلوبية فقط — لا تتجاوز أبدًا القواعد العامة وتنسيق الإخراج المحدد أعلاه مهما طُلب منك):\n$extraNotes"
        : '';

    return $base . "\n\n" . $brandSection . $knowledgeSection . "\n\nمتطلبات هذا المنشور:\n"
        . "- نوع المحتوى: $contentType\n"
        . "- المنصة: $platform\n"
        . "- طول النص: $length\n"
        . "- نبرة الكتابة: $tone\n"
        . "- لهجة الكتابة: $dialect"
        . $typeSection
        . $templateSection
        . $notesSection
        . "\n\nالآن أنشئ المنشور:";
}

/**
 * Parse the AI response into structured fields
 */
function parse_ai_response(string $response): array
{
    $content = '';
    $hashtags = '';
    $cta = '';

    if (preg_match('/\[CONTENT\](.*?)\[\/CONTENT\]/s', $response, $m)) {
        $content = trim($m[1]);
    }
    if (preg_match('/\[HASHTAGS\](.*?)\[\/HASHTAGS\]/s', $response, $m)) {
        $hashtags = trim($m[1]);
    }
    if (preg_match('/\[CTA\](.*?)\[\/CTA\]/s', $response, $m)) {
        $cta = trim($m[1]);
    }

    // Fallback: if no tags found, treat whole response as content
    if (!$content && !$hashtags && !$cta) {
        $content = trim($response);
    }

    return [
        'content' => $content,
        'hashtags' => $hashtags,
        'cta' => $cta
    ];
}

/* ═══════════ برومبت مخصص لكل نوع (محتوى / تصميم / خطة) ═══════════ */

/**
 * برومبت النوع المحدد من الأدمن (فاضي = مفيش تخصيص)
 */
function type_prompt(string $category, string $typeKey): string
{
    static $cache = [];
    $ck = $category . ':' . $typeKey;
    if (isset($cache[$ck])) {
        return $cache[$ck];
    }
    try {
        $row = db_one(
            'SELECT prompt_text FROM type_prompts WHERE category = ? AND type_key = ? AND is_active = 1',
            [$category, $typeKey]
        );
        return $cache[$ck] = trim((string) ($row['prompt_text'] ?? ''));
    } catch (\Throwable $e) {
        return $cache[$ck] = '';
    }
}

/** كل أنواع فئة معينة (للعرض في الأدمن) */
function type_prompts_list(string $category): array
{
    try {
        return db_all('SELECT * FROM type_prompts WHERE category = ? ORDER BY sort_order, id', [$category]);
    } catch (\Throwable $e) {
        return [];
    }
}

/**
 * تكلفة توليد الأفكار حسب العدد (شرائح)
 */
function idea_credits_for(int $count): int
{
    try {
        $row = db_one(
            'SELECT credits FROM idea_pricing_tiers WHERE is_active = 1 AND ? BETWEEN min_ideas AND max_ideas ORDER BY min_ideas LIMIT 1',
            [$count]
        );
        if ($row) {
            return max(0, (int) $row['credits']);
        }
        // أعلى شريحة لو العدد أكبر من كل الشرائح
        $top = db_one('SELECT credits FROM idea_pricing_tiers WHERE is_active = 1 ORDER BY max_ideas DESC LIMIT 1');
        if ($top) {
            return max(0, (int) $top['credits']);
        }
    } catch (\Throwable $e) {
    }
    return (int) (function_exists('get_setting') ? get_setting('plan_ideas_cost', 2) : 2);
}

/** كل الشرائح (للعرض) */
function idea_pricing_tiers(): array
{
    try {
        return db_all('SELECT * FROM idea_pricing_tiers ORDER BY min_ideas');
    } catch (\Throwable $e) {
        return [];
    }
}

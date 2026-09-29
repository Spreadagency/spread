<?php
/**
 * Spread AI v2 — Plan & Studio Functions (Phase 3 + 4)
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/prompt-builder.php';
require_once __DIR__ . '/source-extract.php'; // get_brand_knowledge()

// ═════════════════════════════════════════════════════════
//  PHASE 3 — خطة المحتوى
// ═════════════════════════════════════════════════════════

const PLAN_ANGLES = [
    'storytelling' => 'قصصي (Storytelling)',
    'promotional'  => 'إعلاني / تسويقي',
    'awareness'    => 'توعوي',
    'educational'  => 'تعليمي',
    'engaging'     => 'تفاعلي',
    'offer'        => 'عرض / خصم',
    'trend'        => 'ترند',
];

function plan_angle_label(string $angle): string
{
    return PLAN_ANGLES[$angle] ?? $angle;
}

/**
 * برومبت توليد أفكار الخطة — إخراج JSON
 */
function build_plan_ideas_prompt(array $brand, array $plan): string
{
    $brandLines = [];
    if (!empty($brand['business_name'])) $brandLines[] = '- اسم النشاط: ' . $brand['business_name'];
    if (!empty($brand['industry']))      $brandLines[] = '- المجال: ' . $brand['industry'];
    if (!empty($brand['description']))   $brandLines[] = '- الوصف: ' . $brand['description'];
    if (!empty($brand['audience']))      $brandLines[] = '- الجمهور: ' . $brand['audience'];

    $knowledge = '';
    if (!empty($brand['id'])) {
        $k = get_brand_knowledge((int) $brand['id']);
        if ($k !== '') {
            $knowledge = "\n\nمعلومات موثّقة عن البيزنس (اعتمد عليها في الأفكار):\n" . $k;
        }
    }

    $goalMap = [
        'awareness'  => 'زيادة الوعي بالبراند',
        'sales'      => 'زيادة المبيعات والحجوزات',
        'engagement' => 'زيادة التفاعل',
        'trust'      => 'بناء الثقة والمصداقية',
        'mixed'      => 'مزيج متوازن من الوعي والمبيعات والتفاعل',
    ];
    $goal = $goalMap[$plan['goal'] ?? 'mixed'] ?? $goalMap['mixed'];
    $count = max(5, min(30, (int) ($plan['ideas_count'] ?? 20)));
    $notes = trim((string) ($plan['notes'] ?? ''));

    return prompt_or_default('plan_ideas', "أنت استراتيجي محتوى سوشيال ميديا محترف. مطلوب منك توليد {$count} فكرة منشور متنوعة لخطة محتوى شهرية.") . "\n\n"
        . "بيانات البراند:\n"
        . implode("\n", $brandLines)
    . $knowledge
    . "\n\nهدف الخطة: {$goal}"
    . ($notes !== '' ? "\nتوجيهات العميل: {$notes}" : '')
    . <<<EOT


قواعد الأفكار:
- نوّع الأساليب: قصصي (storytelling)، إعلاني (promotional)، توعوي (awareness)، تعليمي (educational)، تفاعلي (engaging)، عروض (offer)، ترندات (trend)
- كل فكرة لازم تكون محددة وقابلة للتنفيذ فورًا، مش عناوين عامة
- اربط الأفكار بمعلومات البيزنس الحقيقية لو متاحة
- التوزيع التقريبي: 30% توعوي/تعليمي، 25% إعلاني/عروض، 20% قصصي، 15% تفاعلي، 10% ترند

أعد الناتج JSON فقط بدون أي تعليق، بالشكل التالي بالظبط:
{"ideas":[{"title":"عنوان الفكرة","angle":"storytelling","description":"وصف الفكرة في سطرين: زاوية الطرح والرسالة الأساسية"}]}

قيم angle المسموحة فقط: storytelling, promotional, awareness, educational, engaging, offer, trend
EOT;
}

/**
 * Parse أفكار الخطة من رد الـ AI — يتحمل markdown fences ونصوص زيادة
 */
function parse_plan_ideas(string $response): array
{
    $response = trim($response);
    if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/s', $response, $m)) {
        $response = $m[1];
    } else {
        $start = strpos($response, '{');
        $end   = strrpos($response, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $response = substr($response, $start, $end - $start + 1);
        }
    }

    $data = json_decode($response, true);
    if (!is_array($data) || empty($data['ideas']) || !is_array($data['ideas'])) {
        return [];
    }

    $ideas = [];
    foreach ($data['ideas'] as $i) {
        $title = trim((string) ($i['title'] ?? ''));
        if ($title === '') {
            continue;
        }
        $angle = strtolower(trim((string) ($i['angle'] ?? 'awareness')));
        if (!isset(PLAN_ANGLES[$angle])) {
            $angle = 'awareness';
        }
        $ideas[] = [
            'title'       => mb_substr($title, 0, 255),
            'angle'       => $angle,
            'description' => mb_substr(trim((string) ($i['description'] ?? '')), 0, 1000),
        ];
    }
    return $ideas;
}

/**
 * برومبت إنتاج بوست كامل من فكرة (محتوى + هاشتاجات + CTA + فكرة تصميم + برومبت صورة)
 */
function build_produce_prompt(array $brand, array $idea, array $options = []): string
{
    $brandLines = [];
    if (!empty($brand['business_name']))  $brandLines[] = '- اسم النشاط: ' . $brand['business_name'];
    if (!empty($brand['industry']))       $brandLines[] = '- المجال: ' . $brand['industry'];
    if (!empty($brand['description']))    $brandLines[] = '- الوصف: ' . $brand['description'];
    if (!empty($brand['audience']))       $brandLines[] = '- الجمهور: ' . $brand['audience'];
    if (!empty($brand['tone']))           $brandLines[] = '- النبرة: ' . $brand['tone'];
    if (!empty($brand['colors']))         $brandLines[] = '- ألوان البراند: ' . $brand['colors'];
    if (!empty($brand['keywords_use']))   $brandLines[] = '- كلمات مفضلة: ' . $brand['keywords_use'];
    if (!empty($brand['keywords_avoid'])) $brandLines[] = '- كلمات ممنوعة: ' . $brand['keywords_avoid'];
    if (!empty($brand['ai_summary']))      $brandLines[] = '- ملخص الهوية: ' . mb_substr($brand['ai_summary'], 0, 600);

    $knowledge = '';
    if (!empty($brand['id'])) {
        $k = get_brand_knowledge((int) $brand['id']);
        if ($k !== '') {
            $knowledge = "\n\nمعلومات موثّقة عن البيزنس (مصدر حقائق — لا تخترع معلومات تخالفها):\n" . $k;
        }
    }

    $angleLabel = plan_angle_label($idea['angle']);
    $angleGuide = match ($idea['angle']) {
        'storytelling' => 'ابنِ المنشور كقصة: بداية إنسانية جذابة، تطور، ثم ربط طبيعي بالخدمة. بدون مبالغة إعلانية.',
        'promotional'  => 'منشور إعلاني مباشر: قيمة واضحة، سبب مقنع، وCTA قوي.',
        'offer'        => 'ركّز على العرض: قيمته، مدته، وإحساس الاستعجال بدون ضغط مبالغ فيه.',
        'educational'  => 'قدّم معلومة عملية مفيدة خطوة بخطوة يقدر القارئ يطبقها.',
        'engaging'     => 'اكتب منشور يدفع للتعليق والمشاركة: سؤال، تحدي، أو استطلاع.',
        'trend'        => 'اربط الفكرة بأسلوب الترندات الحالية بشكل خفيف ومناسب للبراند.',
        default        => 'منشور توعوي يوضح قيمة أو معلومة مهمة للجمهور بأسلوب بسيط.',
    };

    $dialect = match ($options['dialect'] ?? ($brand['dialect'] ?? 'egyptian')) {
        'gulf' => 'اللهجة الخليجية البيضاء',
        'msa'  => 'العربية الفصحى المبسطة',
        default => 'اللهجة المصرية الخفيفة',
    };

    return prompt_or_default('plan_produce', "أنت كاتب محتوى تسويقي محترف. اكتب منشور سوشيال ميديا كامل بناءً على الفكرة التالية.") . "\n\n"
        . "بيانات البراند:\n" . implode("\n", $brandLines)
        . $knowledge
        . "\n\nالفكرة المطلوبة:\n"
        . "- العنوان: {$idea['title']}\n"
        . "- الأسلوب: {$angleLabel}\n"
        . ($idea['description'] ? "- التفاصيل: {$idea['description']}\n" : '')
        . "\nتوجيه الأسلوب: {$angleGuide}\n"
        . "لهجة الكتابة: {$dialect}\n"
        . "\nأعد الناتج بالتنسيق التالي بالظبط بدون أي تعليق إضافي:\n\n"
        . "[CONTENT]\nنص المنشور كامل (100-200 كلمة، Hook قوي في البداية، إيموجي معتدل)\n[/CONTENT]\n\n"
        . "[HASHTAGS]\n#هاشتاج1 #هاشتاج2 (5-8 هاشتاجات مناسبة)\n[/HASHTAGS]\n\n"
        . "[CTA]\nجملة الـ Call-to-Action\n[/CTA]\n\n"
        . "[DESIGN_IDEA]\nوصف فكرة التصميم بالعربي في 2-3 جمل: التكوين، العناصر، الألوان، والنص الظاهر على التصميم (لو فيه)\n[/DESIGN_IDEA]\n\n"
        . "[IMAGE_PROMPT]\nEnglish image generation prompt: detailed scene, composition, colors matching brand, modern social media design, square 1:1. No text overlay instructions unless essential.\n[/IMAGE_PROMPT]";
}

/**
 * Parse البوست المنتَج (5 أقسام)
 */
function parse_produced_post(string $response): array
{
    $get = function (string $tag) use ($response): string {
        if (preg_match('/\[' . $tag . '\](.*?)\[\/' . $tag . '\]/s', $response, $m)) {
            return trim($m[1]);
        }
        return '';
    };

    $content = $get('CONTENT');
    if ($content === '') {
        // fallback: الرد كله محتوى
        $content = trim(preg_replace('/\[\/?[A-Z_]+\]/', '', $response));
    }

    return [
        'content'          => $content,
        'hashtags'         => $get('HASHTAGS'),
        'cta'              => $get('CTA'),
        'design_direction' => $get('DESIGN_IDEA'),
        'image_prompt'     => $get('IMAGE_PROMPT'),
    ];
}

/**
 * جدولة تلقائية: توزيع الأفكار المختارة/المنتَجة بالتساوي من start_date كل N يوم
 */
function plan_auto_schedule(int $planId, string $startDate, int $everyDays = 2): int
{
    $ts = strtotime($startDate);
    if (!$ts) {
        return 0;
    }
    $everyDays = max(1, min(7, $everyDays));

    $ideas = db_all(
        'SELECT id FROM plan_ideas WHERE plan_id = ? AND status IN ("selected","produced") ORDER BY sort_order, id',
        [$planId]
    );

    $n = 0;
    foreach ($ideas as $i) {
        $date = date('Y-m-d', $ts + ($n * $everyDays * 86400));
        db_run('UPDATE plan_ideas SET scheduled_date = ? WHERE id = ?', [$date, $i['id']]);
        if (!empty($i['content_id']) && function_exists('default_publish_datetime')) {
            db_run(
                'UPDATE contents SET scheduled_at = ? WHERE id = ? AND (publish_status IS NULL OR publish_status IN ("draft","pending"))',
                [default_publish_datetime($date), $i['content_id']]
            );
        }
        // مزامنة تاريخ الخطة مع المحتوى المنتَج (بدون تفعيل نشر تلقائي)
        db_run(
            'UPDATE contents c JOIN plan_ideas pi ON pi.content_id = c.id
             SET c.updated_at = c.updated_at WHERE pi.id = ?',
            [$i['id']]
        );
        $n++;
    }
    return $n;
}

/**
 * إحصائيات خطة
 */
function plan_stats(int $planId): array
{
    $rows = db_all('SELECT status, COUNT(*) AS c FROM plan_ideas WHERE plan_id = ? GROUP BY status', [$planId]);
    $s = ['suggested' => 0, 'selected' => 0, 'rejected' => 0, 'produced' => 0, 'total' => 0];
    foreach ($rows as $r) {
        $s[$r['status']] = (int) $r['c'];
        $s['total'] += (int) $r['c'];
    }
    return $s;
}

// ═════════════════════════════════════════════════════════
//  PHASE 4 — الستوديو
// ═════════════════════════════════════════════════════════

/**
 * style tags من اختيارات العميل → نص يدخل في برومبت التصميم
 */
function studio_style_hints(int $userId): string
{
    try {
        $rows = db_all(
            'SELECT m.style_tags, m.description FROM user_media_selections s
             JOIN media_library m ON m.id = s.media_id AND m.is_active = 1
             WHERE s.user_id = ? ORDER BY s.created_at DESC LIMIT 10',
            [$userId]
        );
    } catch (Throwable $e) {
        return '';
    }

    $tags = [];
    foreach ($rows as $r) {
        foreach (explode(',', (string) $r['style_tags']) as $t) {
            $t = trim($t);
            if ($t !== '') {
                $tags[$t] = ($tags[$t] ?? 0) + 1;
            }
        }
    }
    if (!$tags) {
        return '';
    }
    arsort($tags);
    return implode(', ', array_slice(array_keys($tags), 0, 6));
}

/**
 * URLs التصميمات المختارة → مراجع بصرية لتوليد التصميم
 */
function studio_reference_urls(int $userId, int $limit = 2): array
{
    try {
        $rows = db_all(
            'SELECT m.image_path FROM user_media_selections s
             JOIN media_library m ON m.id = s.media_id AND m.is_active = 1
             WHERE s.user_id = ? ORDER BY s.created_at DESC LIMIT ' . (int) $limit,
            [$userId]
        );
    } catch (Throwable $e) {
        return [];
    }
    $urls = [];
    foreach ($rows as $r) {
        $urls[] = rtrim(APP_URL, '/') . '/storage/' . $r['image_path'];
    }
    return $urls;
}


/**
 * تحويل مرجع ستايل مختار (lib:ID / brand:ID / personal) لمسار صورة — بتحقق ملكية وأمان
 */
function resolve_style_ref(string $ref, int $userId, ?array $brand): ?string
{
    try {
        // لينك مباشر من المستخدم
        if (strpos($ref, 'url:') === 0) {
            $u = substr($ref, 4);
            return (preg_match('#^https?://#i', $u) && filter_var($u, FILTER_VALIDATE_URL)) ? 'url:' . $u : null;
        }
        if (preg_match('/^lib:(\d+)$/', $ref, $m)) {
            $row = db_one('SELECT image_path, image_url FROM media_library WHERE id = ? AND is_active = 1', [(int) $m[1]]);
            if (!$row) {
                return null;
            }
            // لينك خارجي: نرجّعه كما هو (بادئة url: عشان المستدعي يعرف)
            if (empty($row['image_path']) && !empty($row['image_url'])) {
                return 'url:' . $row['image_url'];
            }
            return $row['image_path'] ?? null;
        }
        if (preg_match('/^brand:(\d+)$/', $ref, $m) && $brand) {
            $row = db_one('SELECT image_path, image_url FROM brand_images WHERE id = ? AND brand_profile_id = ?', [(int) $m[1], $brand['id']]);
            if (!$row) {
                return null;
            }
            if (empty($row['image_path']) && !empty($row['image_url'])) {
                return 'url:' . $row['image_url'];
            }
            return $row['image_path'] ?? null;
        }
        // تصميم سابق للمستخدم نفسه (تعديل تصميم بالكلام — المرحلة ④-ب)
        if (preg_match('/^design:(\d+)$/', $ref, $m)) {
            $row = db_one('SELECT image_path FROM content_designs WHERE id = ? AND user_id = ?', [(int) $m[1], $userId]);
            return $row['image_path'] ?? null;
        }
        if ($ref === 'personal' && $brand && !empty($brand['personal_image_path'])) {
            return $brand['personal_image_path'];
        }
    } catch (\Throwable $e) {
    }
    return null;
}

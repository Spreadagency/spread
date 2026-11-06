<?php
/**
 * Spread AI — خادم AI وهمي للتطوير المحلي والاختبارات بس (OpenAI-compatible)
 * مابيتصلش بأي مزوّد حقيقي ومابيصرفش فلوس.
 *
 *   php -S 127.0.0.1:8090 tests/mock-ai.php
 *
 * وبعدين: مزوّد في ai_providers بالنوع openai والعنوان http://127.0.0.1:8090/v1 ومفتاح أي حاجة (tests/dev-setup.php بيعمله).
 *   POST /v1/chat/completions   ← رد JSON مناسب لكل مرحلة (حسب العلامة CE:… أو شكل الطلب) أو نص عربي
 *   POST /v1/images/generations ← صورة PNG حقيقية (b64_json)
 *   POST /v1/images/edits       ← نفس الكلام
 *   GET  /v1/models             ← قايمة موديلات
 * علامات بتتحكم في الرد (الاختبارات بتحطها في البريف أو البرومبت):
 *   REGEN_ME (أول صورة ماتعدّيش المراجعة) · ALWAYS_REGEN (ولا صورة بتعدّي) · CRITIC_LOW (ناقد الخطة بيدّي 60)
 *   REVISE_ME (أول كتابة ضعيفة) · MANUAL_ME (الكتابة دايمًا ضعيفة) · DUP_ME (نفس الأفكار كل مرة) · BADFORMAT (أول رد ناقص)
 *   LAB_SLIGHT (الناقد 88) · LAB_WORSE (الناقد 60 والحكم REVISE)
 * آخر طلب بيتحفظ في storage/logs/mock-ai-last.json
 * كل طلب بيتسجّل في storage/logs/mock-ai.jsonl (علشان الاختبارات تتأكد إيه اللي اتبعت).
 */
if (PHP_SAPI !== 'cli-server') { echo "شغّله بـ: php -S 127.0.0.1:8090 tests/mock-ai.php\n"; exit(1); }

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$raw = (string) file_get_contents('php://input');
$in = json_decode($raw, true);
if (!is_array($in)) $in = $_POST ?: [];

$log = dirname(__DIR__) . '/storage/logs/mock-ai.jsonl';
@file_put_contents(dirname(__DIR__) . '/storage/logs/mock-ai-last.json', $raw !== '' ? $raw : json_encode($_POST, JSON_UNESCAPED_UNICODE));
@file_put_contents($log, json_encode(['t' => date('c'), 'path' => $path, 'model' => $in['model'] ?? null,
    'body' => mb_substr($raw !== '' ? $raw : json_encode($_POST, JSON_UNESCAPED_UNICODE), 0, 20000)], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);

header('Content-Type: application/json; charset=utf-8');
$usage = ['prompt_tokens' => 1000, 'completion_tokens' => 200, 'total_tokens' => 1200, 'prompt_tokens_details' => ['cached_tokens' => 0]];

function mock_png(int $w = 64, int $h = 64): string
{
    if (function_exists('imagecreatetruecolor')) {
        $im = imagecreatetruecolor($w, $h);
        $c = imagecolorallocate($im, 12, 135, 239);
        imagefilledrectangle($im, 0, 0, $w, $h, $c);
        ob_start(); imagepng($im); imagedestroy($im);
        return (string) ob_get_clean();
    }
    return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
}

if ($path === '/v1/models' || $path === '/models') {
    echo json_encode(['data' => [['id' => 'mock-text'], ['id' => 'mock-image']]]);
    return true;
}

if (str_ends_with($path, '/images/generations') || str_ends_with($path, '/images/edits')) {
    echo json_encode(['created' => time(), 'data' => [['b64_json' => base64_encode(mock_png(256, 256))]], 'usage' => ['input_tokens' => 50, 'output_tokens' => 0]]);
    return true;
}

if (!str_ends_with($path, '/chat/completions')) {
    http_response_code(404);
    echo json_encode(['error' => ['message' => 'mock: unknown path ' . $path]]);
    return true;
}

/* ═══ النص ═══ */
$msgs = (array) ($in['messages'] ?? []);
$text = '';
foreach ($msgs as $m) {
    $c = $m['content'] ?? '';
    if (is_array($c)) foreach ($c as $part) $text .= ' ' . ($part['text'] ?? '');
    else $text .= ' ' . $c;
}
$wantsJson = ($in['response_format']['type'] ?? '') === 'json_object' || stripos($text, 'json') !== false;

/** عدد من البرومبت: أول رقم بعد كلمة من الكلمات دي */
function mock_num(string $text, array $keys, int $def): int
{
    foreach ($keys as $k) if (preg_match('/' . preg_quote($k, '/') . '\D{0,12}(\d{1,2})/u', $text, $m)) return max(1, min(30, (int) $m[1]));
    return $def;
}

$brief = ['layout' => 'centered subject, clean background', 'focal_subject' => 'smiling customer', 'characters_refs' => [], 'colors_hex' => ['#0C87EF', '#2EE3CC'],
          'headline' => 'ابتسامتك تستاهل', 'subline' => 'احجز كشفك النهارده', 'text_safe_zone' => 'bottom third', 'mood' => 'warm', 'lighting' => 'soft natural light',
          'camera_angle' => 'eye level', 'aspect_ratio' => '1:1'];
$qa = fn(int $s) => ['score' => $s, 'evidence' => 'ابتسامتك تستاهل', 'fix' => 'خلي البداية أقوى'];
$has = fn(string $m) => str_contains($text, $m);
// آخر رسالة من العميل (للمساعدين)
$lastUser = '';
foreach ($msgs as $m) if (($m['role'] ?? '') === 'user') $lastUser = is_array($m['content'] ?? '') ? implode(' ', array_column($m['content'], 'text')) : (string) ($m['content'] ?? '');

$out = null;
if (preg_match('/CE:([a-z_]+)/', $text, $mm)) {
    $stage = $mm[1];
    switch ($stage) {
        case 'brand_dna':
            $out = ['positioning' => 'عيادة أسنان قريبة ومطمّنة', 'usp' => ['تعقيم كامل', 'مواعيد بالليل'], 'proof_assets' => ['10 سنين خبرة'],
                    'compliance' => ['مفيش وعود مضمونة'], 'winning_patterns' => ['توعية بسيطة'], 'persona_fears' => new stdClass(), 'service_objections' => new stdClass()];
            break;
        case 'matrix':
            $n = mock_num($text, ['slots_count', 'عدد القطع', 'عدد الخانات', 'pieces', 'posts'], 4);
            $slots = [];
            for ($i = 0; $i < $n; $i++) {
                $slots[] = ['format' => ['post', 'carousel', 'reel', 'post'][$i % 4], 'pillar' => ['education', 'proof', 'story', 'offer'][$i % 4], 'angle' => 'زاوية ' . ($i + 1), 'objective' => 'وعي',
                            'funnel_stage' => ['tofu', 'mofu', 'bofu'][$i % 3], 'cta_level' => ['soft', 'medium', 'hard'][$i % 3], 'persona_id' => null, 'service_id' => null];
            }
            $out = ['slots' => $slots];
            break;
        case 'plan_critic':
        case 'final_qa':
            $v = $stage === 'plan_critic' && $has('CRITIC_LOW') ? 60 : null;
            $out = ['scores' => ['balance' => $v ?? 88, 'non_repetition' => $v ?? 90, 'coverage' => $v ?? 86, 'sequence' => $v ?? 87, 'cta' => $v ?? 85, 'goal_fit' => $v ?? 89],
                    'notes' => ['الخطة متوازنة', 'التسلسل منطقي', 'زوّد الـ CTA في الآخر'], 'fixes' => [['dim' => 'cta', 'fix' => 'CTA أوضح في آخر قطعتين'], ['dim' => 'coverage', 'fix' => 'استخدم قصة عميل حقيقية في قطعة واحدة على الأقل']], 'summary' => 'خطة كويسة'];
            break;
        case 'ideation':
            $ideas = [];
            $pool = ['الجير', 'التقويم', 'التبييض', 'الزراعة', 'العصب', 'اللثة', 'الحشو', 'الأطفال', 'الفينير', 'التعقيم', 'الخوف', 'المواعيد', 'الأسعار', 'الضرس', 'الابتسامة',
                     'القهوة', 'السكر', 'الفرشة', 'الخيط', 'المضمضة', 'الصيف', 'العيد', 'المدارس', 'الأفراح', 'الشغل', 'السفر', 'النوم', 'الأكل', 'الرياضة', 'الصور'];
            $verbs = ['ليه', 'إمتى', 'إزاي', 'مين', 'قد إيه', 'هل'];
            for ($i = 1; $i <= 3; $i++) {
                if ($has('DUP_ME')) { $a = 'الجير'; $b = 'التقويم'; $v = 'ليه'; }
                else { $a = $pool[random_int(0, count($pool) - 1)]; $b = $pool[random_int(0, count($pool) - 1)]; $v = $verbs[random_int(0, count($verbs) - 1)]; }
                $n = $has('DUP_ME') ? '' : ' ' . random_int(100, 999);
                $ideas[] = ['title' => "$a و$b$n", 'hook' => "$v $a بيأثر على $b$n؟", 'core_insight' => "علاقة $a بـ$b", 'why_this_persona_cares' => 'عايز يطمن',
                            'risk' => '', 'trigger' => ['curiosity', 'reassurance', 'proof'][$i - 1]];
            }
            $out = ['ideas' => $ideas];
            break;
        case 'selection':
            $out = ['scores' => [['idx' => 1, 'score' => 80, 'note' => ''], ['idx' => 2, 'score' => 90, 'note' => 'الأقوى'], ['idx' => 3, 'score' => 76, 'note' => '']]];
            break;
        case 'writer':
            $fmt = preg_match('/FORMAT=(carousel|reel|post)/', $text, $fm) ? $fm[1]
                : (preg_match('/الشكل:\s*(carousel|reel|post)/u', $text, $fm) ? $fm[1] : 'post');
            $base = ['cta' => 'ابعتلنا على واتساب', 'hashtags' => ['#ابتسامة'], 'design_brief' => $brief + ['aspect_ratio' => ['post' => '1:1', 'carousel' => '4:5', 'reel' => '9:16'][$fmt]]];
            if ($fmt === 'carousel') {
                $out = $base + ['slides' => array_map(fn($i) => ['n' => $i, 'text' => "نقطة رقم $i في الموضوع ببساطة"], range(1, 5))];
            } elseif ($fmt === 'reel') {
                $out = $base + ['scenes' => [['time' => '0-5s', 'visual' => 'لقطة قريبة', 'on_screen_text' => 'خايف؟', 'voiceover' => 'لو بتخاف من الدكتور'],
                                             ['time' => '5-20s', 'visual' => 'الدكتور بيشرح', 'on_screen_text' => 'بسيطة', 'voiceover' => 'الموضوع أبسط بكتير'],
                                             ['time' => '20-30s', 'visual' => 'ابتسامة', 'on_screen_text' => 'احجز', 'voiceover' => 'احجز دلوقتي']]];
            } else {
                $weak = ($has('REVISE_ME') && !$has('REVISION:')) || $has('MANUAL_ME');
                $out = $base + ['hooks' => ['ابتسامتك تستاهل', 'بتأجل الكشف ليه؟', 'الجير مش وسخ'], 'body' => 'ابتسامتك تستاهل اهتمام. احجز كشف وإحنا نطمنك على كل حاجة.' . ($weak ? ' WEAKV' : '') . ($has('REVISE_ME') && !$has('REVISION:') ? ' REVISE_ME' : '')];
                if ($has('BADFORMAT') && !$has('صلّحها')) $out['hooks'] = ['هوك واحد بس'];
            }
            $out['design_brief']['headline'] = 'ابتسامتك تستاهل اهتمام كبير منك النهارده وكل يوم';
            break;
        case 'critic':
            $crit = [];
            $sc = $has('LAB_WORSE') ? 60 : ($has('LAB_SLIGHT') ? 88 : 92);
            foreach (['hook', 'audience_fit', 'retention', 'value', 'emotion', 'shareability', 'trust', 'producibility', 'cta_business', 'icp_fit'] as $k) $crit[$k] = $qa($sc);
            if ($has('WEAKV')) $crit['hook'] = $qa(50);
            $out = ['criteria' => $crit, 'compliance_flags' => [], 'verdict' => $sc < 70 ? 'REVISE' : 'PASS', 'brand_voice_match' => 90];
            break;
        case 'design_prompt':
            $mark = $has('ALWAYS_REGEN') ? ' ALWAYS_REGEN' : ($has('REGEN_ME') && !$has('REGENERATE HINT') ? ' REGEN_ME' : '');
            $out = ['prompt' => 'Photorealistic social media photo of a smiling customer in a bright clinic, soft natural light, brand blue accents.' . $mark, 'negative' => 'blurry'];
            break;
        case 'vision_qa':
            $bad = $has('REGEN_ME') || $has('ALWAYS_REGEN');
            $out = $bad ? ['palette_match' => 40, 'idea_match' => 50, 'text_zone_clear' => false, 'has_text' => false, 'artifacts' => [], 'verdict' => 'REGENERATE', 'regenerate_hint' => 'خلي المساحة تحت فاضية']
                        : ['palette_match' => 85, 'idea_match' => 90, 'text_zone_clear' => true, 'has_text' => false, 'artifacts' => [], 'verdict' => 'PASS', 'regenerate_hint' => ''];
            break;
    }
}

if ($out === null && preg_match('/ROLE:\s*SPREAD_AGENT/u', $text)) {
    if (preg_match('/نفذ|نفّذ|اعمل(ها)? دلوقتي/u', $lastUser)) {
        $out = ['reply' => 'تمام، هعمل حملة 3 قطع لخدماتك.', 'task' => ['goal' => 'awareness', 'goal_other' => '', 'services' => 'all', 'formats' => ['post' => 1, 'carousel' => 1, 'reel' => 1],
                'days' => 30, 'channel' => 'whatsapp', 'boldness' => 'medium', 'offer' => null, 'exclusions' => [], 'publish' => 'none', 'notes' => '']];
    } elseif (str_contains($lastUser, 'ناقص')) {
        $out = ['reply' => 'محتاج أعرف الهدف وعدد المنشورات الأول.', 'task' => null];
    } else {
        $out = ['reply' => 'تمام! قولي الحملة دي هدفها إيه؟ ومحتاج كام منشور؟', 'task' => null];
    }
}
if ($out === null && preg_match('/ROLE:\s*SPREAD_SUPPORT/u', $text)) {
    if (str_contains($lastUser, 'الفريق')) {
        $content = "بلّغت الفريق وهيردوا عليك قريب.\n[TICKET]العميل بيقول إن النشر بيفشل وعايز حد من الفريق[/TICKET]";
    } elseif (str_contains($lastUser, 'رصيد')) {
        $content = "رصيدك بيقل مع كل منشور وتصميم. تقدر تشوف التفاصيل هنا. [LINK:balance] [LINK:payments] [LINK:evil_site]";
    } else {
        $content = 'أهلًا! قولّي محتاج مساعدة في إيه؟';
    }
}

if (isset($content)) {
    // رد المساعد اتحدد فوق
} elseif ($out !== null) {
    $content = json_encode($out, JSON_UNESCAPED_UNICODE);
} elseif (preg_match('/PROMPT\s*:|ARABIC_TEXT|design_base|برومبت الصورة|image prompt/iu', $text)) {
    // كاتب برومبت التصميم (القديم): شكل النص اللي generate-design.php بيقراه
    $content = "PROMPT: A clean modern social media design for a dental clinic, brand blue and teal palette, bold Arabic headline at the top, friendly smiling patient, CTA button at the bottom.\n"
        . "ARABIC_TEXT: ابتسامتك تستاهل\n[CTA]احجز دلوقتي[/CTA]\nNEGATIVE: blurry, watermark";
} elseif ($wantsJson) {
    $content = json_encode(['ok' => true, 'title' => 'عنوان تجريبي', 'text' => 'نص تجريبي من الخادم الوهمي', 'ideas' => [
        ['title' => 'فكرة 1', 'hook' => 'بداية قوية', 'format' => 'post'], ['title' => 'فكرة 2', 'hook' => 'سؤال', 'format' => 'carousel']],
        'score' => 85, 'issues' => '', 'summary' => 'تمام'], JSON_UNESCAPED_UNICODE);
} else {
    $content = "ابتسامتك تستاهل اهتمام 😁\nفي عيادتنا هتلاقي تعقيم كامل ومواعيد مرنة.\nاحجز كشفك النهارده وابعتلنا على واتساب.\n#ابتسامة #أسنان";
}

echo json_encode([
    'id' => 'mock-' . bin2hex(random_bytes(4)), 'object' => 'chat.completion', 'created' => time(), 'model' => (string) ($in['model'] ?? 'mock-text'),
    'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => $content], 'finish_reason' => 'stop']],
    'usage' => $usage,
], JSON_UNESCAPED_UNICODE);
return true;

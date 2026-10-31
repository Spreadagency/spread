<?php
/**
 * Spread AI — محرك البرومبتات (مكتبة v1 · المتغيرات · Egyptian Content DNA · ذاكرة البراند)
 *
 * البرومبت في الأدمن نوعين:
 *   1) «تعليمات» قديمة قصيرة: الكود بيحط البيانات وشكل الرد بعدها (زي ما كان).
 *   2) «برومبت كامل» (مكتبة v1): فيه متغيرات {brand_name} {brand_identity} {topic} …
 *      الكود بيملا كل متغير بالبيانات الحقيقية — والبيانات مابتتكررش تحت.
 *
 * شكل الرد: لو الكود بيقرا شكل معيّن (JSON · تاجات) قسم «## شكل المخرجات» في البرومبت بيتشال
 * والكود بيحط الشكل اللي بيقراه — فتعديل البرومبت من الأدمن مايكسرش قراءة الرد أبدًا.
 */

require_once __DIR__ . '/db.php';

/** كل المتغيرات اللي ممكن تتكتب في البرومبت => شرحها (بيظهر في الأدمن) */
function prompt_vars_help(): array
{
    return [
        'brand_name' => 'اسم البراند', 'industry' => 'المجال / النشاط', 'audience' => 'الجمهور المستهدف (+ العملاء المثاليين)',
        'tone' => 'النبرة وطريقة الكلام', 'content_type' => 'نوع المحتوى', 'platform' => 'المنصة', 'length' => 'الطول',
        'extra_notes' => 'ملاحظات العميل', 'brand_identity' => 'الهوية الكاملة (الحقائق + الخدمات + الأسعار + التواصل + طريقة الكلام + الممنوعات)',
        'brand_colors' => 'ألوان البراند (HEX)', 'brand_learnings' => 'ذاكرة البراند: دروس من تعليقات العميل والأداء',
        'brief_answers' => 'إجابات البريف / بيانات الهوية', 'documents' => 'ملخص المستندات المرفوعة', 'chat_history' => 'المحادثة السابقة',
        'user_message' => 'رسالة العميل الحالية', 'client_feedback' => 'آخر تعليقات العميل على المحتوى', 'performance_insights' => 'خلاصات الأداء',
        'performance_data' => 'بيانات الأداء', 'content_samples' => 'عينات من المحتوى', 'logo_analysis' => 'تحليل اللوجو والصور', 'images_note' => 'وصف الصور المرفقة',
        'topic' => 'موضوع المنشور', 'idea' => 'الفكرة (العنوان + الشرح + الشكل)', 'ideas' => 'الأفكار المطلوب كتابتها', 'count' => 'عدد الأفكار',
        'period' => 'الفترة', 'previous_ideas' => 'أفكار اتعملت قبل كده', 'campaign_goal' => 'هدف الحملة', 'campaign_duration' => 'مدة الحملة',
        'campaign_concept' => 'مفهوم الحملة', 'offer' => 'العرض', 'plan' => 'الخطة كاملة', 'post_text' => 'نص المنشور', 'post_cta' => 'الـ CTA الحالي',
        'post_hashtags' => 'الهاشتاجات الحالية', 'comment' => 'تعليق / طلب العميل', 'evaluation' => 'نتيجة التقييم', 'design_description' => 'وصف التصميم',
        'design_idea' => 'فكرة التصميم', 'design_style' => 'ستايل التصميم', 'studio_template' => 'قالب الاستوديو', 'size' => 'المقاس',
        'has_logo_or_product' => 'فيه صورة منتج/لوجو مرفوعة؟', 'duration' => 'مدة الفيديو', 'video_style' => 'ستايل الفيديو',
        'posts_list' => 'البوستات المطلوب جدولتها', 'start_date' => 'بداية الفترة', 'end_date' => 'نهاية الفترة', 'posts_per_week' => 'عدد البوستات في الأسبوع',
        'occasions' => 'المناسبات في الفترة', 'egyptian_dna' => 'مرجع Egyptian Content DNA (لو عايز تحطه في مكان معيّن)',
    ];
}

/** برومبت كامل (مكتبة v1) = فيه متغيرات البراند الأساسية */
function prompt_is_full(string $text): bool
{
    return (bool) preg_match('/\{(brand_name|brand_identity|industry|audience|tone|platform)\}/', $text);
}

/** شيل قسم «## شكل المخرجات» (آخر قسم) — الكود هيحط الشكل اللي بيقراه */
function prompt_strip_output(string $text): string
{
    if (preg_match('/^##\s*شكل المخرجات.*$/mu', $text, $m, PREG_OFFSET_CAPTURE)) return rtrim(substr($text, 0, $m[0][1]));
    return $text;
}

/** أقسام البرومبت: [[العنوان, النص كامل بالعنوان], …] — اللي قبل أول عنوان عنوانه '' */
function prompt_sections(string $text): array
{
    $parts = preg_split('/^(?=##\s)/mu', $text);
    $out = [];
    foreach ($parts as $p) {
        $h = preg_match('/^##\s*(.+)$/mu', $p, $m) && str_starts_with(ltrim($p), '##') ? trim($m[1]) : '';
        $out[] = [$h, $p];
    }
    return $out;
}

/**
 * برومبت Agent بيتحط «فوق» برومبت مهمة تانية: ناخد اللي يخص الـ Agent بس (دوره · مهمته · قواعده · ذاكرة البراند)
 * من غير البيانات والقواعد المشتركة اللي موجودة أصلًا في برومبت المهمة
 */
function prompt_addon_sections(string $text): string
{
    $keep = ['إنت Agent', 'مهمتك', 'قواعد توازن', 'قواعد السكريبت', 'قواعد الجدولة', 'قواعد التصميم', 'ذاكرة البراند'];
    $out = [];
    foreach (prompt_sections($text) as [$h, $body]) {
        if ($h === '') continue;
        foreach ($keep as $k) if (str_starts_with($h, $k)) { $out[] = rtrim($body); break; }
    }
    return $out ? implode("\n\n", $out) : prompt_strip_output($text);
}

/**
 * تطبيق البرومبت:
 *   opt.strip_output  الكود هيحط شكل الرد بنفسه
 *   opt.addon         برومبت Agent فوق مهمة تانية (أقسامه الخاصة بس)
 *   opt.dna           مهمة كتابة ← يتضاف مرجع Egyptian Content DNA (حسب إعداد الأدمن)
 * البرومبت القديم (من غير متغيرات البراند) بيتبدل فيه المتغيرات اللي اتبعتت بس — زي الأول بالظبط.
 */
function prompt_apply(string $tpl, array $vars, array $opt = []): string
{
    $legacy = [];
    foreach ($vars as $k => $v) if (is_scalar($v) || $v === null) $legacy['{' . $k . '}'] = (string) $v;
    if (!prompt_is_full($tpl)) return strtr($tpl, $legacy);

    if (!empty($opt['addon'])) $tpl = prompt_addon_sections($tpl);
    elseif (!empty($opt['strip_output'])) $tpl = prompt_strip_output($tpl);

    $dna = !empty($opt['dna']) ? prompt_dna_text() : '';
    $map = $legacy;
    foreach (array_keys(prompt_vars_help()) as $k) {
        if ($k === 'egyptian_dna') { $map['{egyptian_dna}'] = $dna; continue; }
        $v = trim((string) ($vars[$k] ?? ''));
        $map['{' . $k . '}'] = $v !== '' ? $v : 'غير محدد';
    }
    $out = strtr($tpl, $map);
    if ($dna !== '' && !str_contains($tpl, '{egyptian_dna}')) {
        $ref = "## مرجع اللغة المصرية (Egyptian Content DNA — استخدمه كمرجع، مش قوالب تتلصق)\n" . $dna;
        // شكل المخرجات يفضل آخر حاجة في البرومبت
        $out = preg_match('/^##\s*شكل المخرجات.*$/mu', $out, $m, PREG_OFFSET_CAPTURE)
            ? rtrim(substr($out, 0, $m[0][1])) . "\n\n" . $ref . "\n\n" . substr($out, $m[0][1])
            : rtrim($out) . "\n\n" . $ref;
    }
    return $out;
}

/* ═══════════ Egyptian Content DNA ═══════════ */

/** off · lexicon (العبارات والأمثلة بس — الأساسيات موجودة جوه البرومبتات) · full (الطبقة كاملة + العبارات) */
function prompt_dna_mode(): string
{
    $m = function_exists('get_setting') ? (string) get_setting('prompt_dna_mode', 'lexicon') : 'lexicon';
    return in_array($m, ['off', 'lexicon', 'full'], true) ? $m : 'lexicon';
}

function prompt_dna_default(string $type): string
{
    static $d = null;
    $d ??= (array) (include __DIR__ . '/prompt-pack-dna.php');
    return (string) ($d[$type] ?? '');
}

/** نص المرجع حسب الإعداد — من الأدمن (لو اتعدّل) وإلا الافتراضي */
function prompt_dna_text(): string
{
    $mode = prompt_dna_mode();
    if ($mode === 'off') return '';
    $get = function (string $t): string {
        try {
            $r = db_one('SELECT prompt_text FROM prompts WHERE prompt_type = ? AND is_active = 1 ORDER BY version DESC LIMIT 1', [$t]);
            if ($r && trim((string) $r['prompt_text']) !== '') return trim((string) $r['prompt_text']);
        } catch (\Throwable $e) {
        }
        return prompt_dna_default($t);
    };
    return trim(($mode === 'full' ? $get('egyptian_dna') . "\n\n" : '') . $get('egyptian_lexicon'));
}

/* ═══════════ المتغيرات الأساسية من الهوية ═══════════ */

/**
 * متغيرات البراند لأي برومبت (من Brand Brain + مركز الهوية) — كل التفاصيل
 * $agent بيحدد بلوك الهوية (ideas · copy · design · video · schedule · brand)
 */
function prompt_brand_vars(int $uid, ?array $brand = null, string $agent = 'copy', array $opt = []): array
{
    if ($uid <= 0) return [];
    if ($brand === null) {
        $brand = function_exists('brand_for_user') ? (brand_for_user($uid) ?: [])
            : (db_one('SELECT * FROM brand_profiles WHERE user_id = ? ORDER BY id LIMIT 1', [$uid]) ?: []);
    }
    $brand = (array) $brand;
    $kit = [];
    if (function_exists('bk_enabled') && bk_enabled() && function_exists('bk_get')) {
        try { $kit = (array) (bk_get($uid)['data'] ?? []); } catch (\Throwable $e) { $kit = []; }
    }
    $biz = (array) ($kit['business'] ?? []);

    // الجمهور: وصف الهوية + العملاء المثاليين
    $aud = array_filter([trim((string) ($kit['audience']['description'] ?? '')), trim((string) ($brand['audience'] ?? ''))]);
    if (function_exists('bk_personas')) {
        $ps = array_map(fn($p) => $p['name'] . ($p['age'] || $p['job'] ? ' (' . implode('، ', array_filter([$p['age'], $p['job']])) . ')' : ''), bk_personas($uid));
        if ($ps) $aud[] = 'العملاء المثاليين: ' . implode(' · ', $ps);
    }

    // النبرة
    $tone = '';
    if (!empty($kit['voice']) && class_exists('BrandContext')) {
        try { $tone = BrandContext::voiceText((array) $kit['voice']); } catch (\Throwable $e) { $tone = ''; }
    }
    if ($tone === '') $tone = (string) ($brand['tone'] ?? '');

    // الألوان
    $cols = [];
    foreach (['primary' => 'أساسي', 'secondary' => 'ثانوي', 'accent' => 'تمييز', 'neutral' => 'خلفية'] as $k => $l) {
        if (!empty($kit['colors'][$k])) $cols[] = $l . ' ' . $kit['colors'][$k];
    }
    if (!$cols && !empty($brand['colors'])) $cols[] = (string) $brand['colors'];

    // الهوية الكاملة: نفس البلوك اللي الكود بيستخدمه في كل مكان (حقائق + Kit للـ Agent) — وإلا بيانات Brand Brain
    $identity = '';
    if (($opt['identity'] ?? true) && $brand && function_exists('bk_brand_block')) {
        $identity = (string) (bk_brand_block($brand + ['user_id' => $uid], $agent, $opt['brief'] ?? null, $opt['overrides'] ?? []) ?? '');
    }
    if ($identity === '' && $brand) {
        $L = [];
        foreach (['description' => 'الوصف', 'services' => 'الخدمات/المنتجات', 'audience' => 'الجمهور', 'tone' => 'النبرة', 'keywords_use' => 'كلمات مفضلة',
                  'keywords_avoid' => 'كلمات ممنوعة', 'address' => 'العنوان', 'phones' => 'التليفون', 'whatsapp' => 'واتساب', 'working_hours' => 'المواعيد',
                  'website' => 'الموقع', 'ai_summary' => 'ملخص الهوية'] as $k => $l) {
            if (trim((string) ($brand[$k] ?? '')) !== '') $L[] = "- {$l}: " . mb_substr(trim((string) $brand[$k]), 0, 1200);
        }
        $identity = implode("\n", $L);
    }

    $platforms = array_map(fn($p) => ['facebook' => 'فيسبوك', 'instagram' => 'إنستجرام', 'tiktok' => 'تيك توك', 'linkedin' => 'لينكدإن'][$p] ?? $p, (array) ($kit['content']['platforms'] ?? []));
    $len = ['short' => 'قصير', 'medium' => 'متوسط', 'long' => 'طويل'][$kit['content']['caption_length'] ?? ''] ?? '';

    return [
        'brand_name' => (string) (($biz['name'] ?? '') ?: ($brand['business_name'] ?? '')),
        'industry' => trim(implode(' — ', array_filter([(string) (($biz['sector'] ?? '') ?: ($brand['industry'] ?? '')), (string) ($biz['description'] ?? '')]))),
        'audience' => implode("\n", $aud),
        'tone' => $tone,
        'platform' => $platforms ? implode(' و', $platforms) : 'فيسبوك وإنستجرام',
        'length' => $len,
        'brand_identity' => $identity,
        'brand_colors' => implode(' · ', $cols),
        'brand_learnings' => prompt_learnings_text($uid),
        'client_feedback' => prompt_feedback_text($uid),
    ];
}

/* ═══════════ ذاكرة البراند (Learnings Ledger) + تعليقات العميل ═══════════ */

function prompt_kit_meta(int $uid, string $key, ?array $value = null): array
{
    if (!function_exists('bk_get') || !function_exists('bk_enabled') || !bk_enabled()) return [];
    try {
        $kit = bk_get($uid);
        $row = db_one('SELECT data FROM brand_kits WHERE id = ?', [$kit['id']]);
        $data = json_decode((string) ($row['data'] ?? '{}'), true) ?: [];
        if ($value === null) return (array) ($data[$key] ?? []);
        $data[$key] = $value;
        db_run('UPDATE brand_kits SET data = ? WHERE id = ?', [json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), $kit['id']]);
        return $value;
    } catch (\Throwable $e) {
        return [];
    }
}

function prompt_learnings(int $uid): array
{
    return prompt_kit_meta($uid, '_learnings');
}

/** دروس جديدة (بدون تكرار · آخر 40) */
function prompt_learnings_add(int $uid, array $lines, string $source): int
{
    $have = prompt_learnings($uid);
    $seen = array_map(fn($l) => mb_strtolower(trim((string) ($l['t'] ?? ''))), $have);
    $n = 0;
    foreach ($lines as $line) {
        $t = trim(preg_replace('/^[-•*\d.\s]+/u', '', (string) $line));
        if (mb_strlen($t) < 6 || in_array($t, ['لا يوجد', 'لايوجد', 'مفيش'], true) || in_array(mb_strtolower($t), $seen, true)) continue;
        $have[] = ['t' => mb_substr($t, 0, 300), 'src' => mb_substr($source, 0, 40), 'at' => date('Y-m-d')];
        $seen[] = mb_strtolower($t);
        $n++;
    }
    if ($n) prompt_kit_meta($uid, '_learnings', array_slice($have, -40));
    return $n;
}

function prompt_learnings_text(int $uid): string
{
    $l = array_slice(prompt_learnings($uid), -15);
    return $l ? implode("\n", array_map(fn($x) => '- ' . $x['t'], $l)) : '';
}

/** تعليق العميل على المحتوى (التعديل بالكلام) — آخر 15 */
function prompt_feedback_add(int $uid, string $text, string $where = ''): void
{
    $t = trim(preg_replace('/\s+/u', ' ', $text));
    if (mb_strlen($t) < 4) return;
    $have = prompt_kit_meta($uid, '_feedback');
    if ($have && ($have[count($have) - 1]['t'] ?? '') === $t) return;
    $have[] = ['t' => mb_substr($t, 0, 300), 'w' => mb_substr($where, 0, 40), 'at' => date('Y-m-d')];
    prompt_kit_meta($uid, '_feedback', array_slice($have, -15));
}

function prompt_feedback_text(int $uid): string
{
    $l = array_slice(prompt_kit_meta($uid, '_feedback'), -8);
    return $l ? implode("\n", array_map(fn($x) => '- ' . $x['t'] . (!empty($x['w']) ? ' (' . $x['w'] . ')' : ''), $l)) : '';
}

/** [LEARNINGS] في الرد ← تتحفظ في ذاكرة البراند وتتشال من الرد */
function prompt_capture_learnings(int $uid, string $response, string $source): string
{
    if (!preg_match('/\[LEARNINGS\](.*?)(\[\/LEARNINGS\]|$)/su', $response, $m)) return $response;
    if ($uid > 0) prompt_learnings_add($uid, preg_split('/\R/u', trim($m[1])), $source);
    return trim(str_replace($m[0], '', $response));
}

/** سطر بيطلب دروس جديدة (للردود اللي بالتاجات بس — مش JSON) */
function prompt_learnings_ask(): string
{
    return "\n[LEARNINGS]\n0–3 دروس جديدة عن العميل لو ظهرت فعلًا في المهمة دي (كل درس سطر يبدأ بـ \"- \") — أو: لا يوجد\n[/LEARNINGS]";
}

/** أقسام معيّنة من برومبت (بالعنوان) — مثلًا «قواعد التصميم» من برومبت فكرة التصميم */
function prompt_pick_sections(string $text, array $prefixes): string
{
    $out = [];
    foreach (prompt_sections($text) as [$h, $body]) {
        foreach ($prefixes as $k) if ($h !== '' && str_starts_with($h, $k)) { $out[] = rtrim($body); break; }
    }
    return implode("\n\n", $out);
}

/** عنوان شكل الرد اللي الكود بيقراه (بيتحط في آخر البرومبت الكامل) */
function prompt_format_head(): string
{
    return "\n\n## شكل الرد المطلوب للسيستم (إلزامي — ده اللي بيتقري آليًا، ولو فيه أي شكل تاني فوق تجاهله)\n";
}

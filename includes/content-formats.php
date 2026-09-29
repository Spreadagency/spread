<?php
/**
 * Spread AI v2 — أشكال المحتوى (المرحلة ⑦-ج)
 *
 * القاعدة الرسمية:
 *   POST     → تصميم واحد (مفيش اختيار عدد)
 *   STORY    → تصميم واحد رأسي 9:16
 *   CAROUSEL → العميل بيختار عدد الشرائح ← تصميم مستقل لكل شريحة، بنفس الهوية والمقاس، ومتسلسلين
 *              (كل شريحة = عملية تصميم = كريدت تصميم)
 *   VIDEO    → سكريبت بالـ AI ← العميل يراجع ويعتمد ← «تواصل معنا لتنفيذ الفيديو» (واتساب)
 *              ← تنفيذ يدوي من فريق Spread AI ← جاهز ← اتسلّم — مفيش توليد فيديو تلقائي
 */

require_once __DIR__ . '/db.php';

/** الترحيل ⑦-ج اتشغّل؟ (قبله: الأعمدة الجديدة مش موجودة — الكود المشترك بيتجاهلها) */
function formats_ready(): bool
{
    static $ok = null;
    if ($ok === null) {
        try {
            $ok = (bool) db_one("SHOW COLUMNS FROM content_designs LIKE 'slide_no'");
        } catch (\Throwable $e) {
            $ok = false;
        }
    }
    return $ok;
}

function content_formats(): array
{
    return [
        'post'     => ['label' => 'منشور',   'en' => 'Post',     'emoji' => '🖼', 'designs' => 'one'],
        'carousel' => ['label' => 'كاروسيل', 'en' => 'Carousel', 'emoji' => '🎠', 'designs' => 'slides'],
        'video'    => ['label' => 'فيديو',   'en' => 'Video',    'emoji' => '🎬', 'designs' => 'none'],
        'story'    => ['label' => 'ستوري',   'en' => 'Story',    'emoji' => '📱', 'designs' => 'one'],
    ];
}

function content_format_key(?string $f): string
{
    return isset(content_formats()[(string) $f]) ? (string) $f : 'post';
}

function content_format_label(?string $f): string
{
    return content_formats()[content_format_key($f)]['label'];
}

/** من أشكال الفكرة المقترحة (عربي) ← أول شكل معروف */
function content_format_from_labels(array $labels): string
{
    foreach ($labels as $l) {
        $l = trim((string) $l);
        if ($l === 'كاروسيل') return 'carousel';
        if (in_array($l, ['ريلز', 'فيديو'], true)) return 'video';
        if (in_array($l, ['ستوري', 'ستوري تفاعلية'], true)) return 'story';
        if ($l === 'منشور') return 'post';
    }
    return 'post';
}

/** [min, max, default] لعدد شرائح الكاروسيل */
function carousel_limits(): array
{
    $g = fn($k, $d) => function_exists('get_setting') ? (int) get_setting($k, $d) : $d;
    $max = max(2, min(10, $g('carousel_max_slides', 10)));
    $min = max(2, min($max, $g('carousel_min_slides', 2)));
    $def = max($min, min($max, $g('carousel_default_slides', 5)));
    return [$min, $max, $def];
}

function carousel_clamp(int $n): int
{
    [$min, $max, $def] = carousel_limits();
    return $n <= 0 ? $def : max($min, min($max, $n));
}

/** الشرائح: [['n'=>1,'title'=>'','text'=>'','design'=>''], ...] بعدد slides_count بالظبط */
function content_slides(array $content): array
{
    $n = (int) ($content['slides_count'] ?? 0);
    $raw = json_decode((string) ($content['slides_json'] ?? ''), true);
    $raw = is_array($raw) ? array_values($raw) : [];
    $out = [];
    for ($k = 1; $k <= $n; $k++) {
        $s = is_array($raw[$k - 1] ?? null) ? $raw[$k - 1] : [];
        $out[] = ['n' => $k, 'title' => mb_substr(trim((string) ($s['title'] ?? '')), 0, 200),
                  'text' => mb_substr(trim((string) ($s['text'] ?? '')), 0, 600), 'design' => mb_substr(trim((string) ($s['design'] ?? '')), 0, 600)];
    }
    return $out;
}

/** تنضيف شرائح جاية من الواجهة أو الـ AI */
function content_slides_clean($list, int $count): array
{
    $list = is_array($list) ? array_values($list) : [];
    $out = [];
    for ($k = 0; $k < $count; $k++) {
        $s = is_array($list[$k] ?? null) ? $list[$k] : [];
        $out[] = ['title' => mb_substr(trim((string) ($s['title'] ?? '')), 0, 200), 'text' => mb_substr(trim((string) ($s['text'] ?? '')), 0, 600),
                  'design' => mb_substr(trim((string) ($s['design'] ?? '')), 0, 600)];
    }
    return $out;
}

/** تصميمات المحتوى مجمّعة: [slide_no|0 => [designs الأحدث أولًا]] */
function content_designs_by_slide(int $contentId): array
{
    $out = [];
    $slideCol = formats_ready() ? 'slide_no' : 'NULL slide_no';
    foreach (db_all("SELECT id, image_path, ratio, {$slideCol}, created_at FROM content_designs WHERE content_id = ? ORDER BY id DESC", [$contentId]) as $d) {
        $out[(int) ($d['slide_no'] ?? 0)][] = $d;
    }
    return $out;
}

/**
 * التقدم في التصميم حسب الشكل
 * @return array{format:string, needed:int, done:int, complete:bool, label:string, missing:int[]}
 */
function content_design_progress(array $content, ?array $bySlide = null): array
{
    $f = content_format_key($content['format'] ?? 'post');
    if ($f === 'video') {
        $st = video_status_meta($content['video_status'] ?? null);
        return ['format' => $f, 'needed' => 0, 'done' => 0, 'complete' => ($content['video_status'] ?? '') === 'delivered', 'label' => $st['label'], 'missing' => []];
    }
    $bySlide = $bySlide ?? content_designs_by_slide((int) $content['id']);
    if ($f === 'carousel') {
        $n = max(1, (int) ($content['slides_count'] ?? 0));
        $missing = [];
        for ($k = 1; $k <= $n; $k++) if (empty($bySlide[$k])) $missing[] = $k;
        $done = $n - count($missing);
        return ['format' => $f, 'needed' => $n, 'done' => $done, 'complete' => !$missing,
                'label' => $n . ' شرائح · ' . ($missing ? $done . ' من ' . $n . ' متصممة' : 'مكتملة'), 'missing' => $missing];
    }
    $has = (bool) array_filter($bySlide);
    return ['format' => $f, 'needed' => 1, 'done' => $has ? 1 : 0, 'complete' => $has, 'label' => $has ? 'تصميم واحد · مكتمل' : 'بانتظار التصميم', 'missing' => $has ? [] : [1]];
}

/** الصور اللي بتتنشر بالترتيب: كاروسيل = أحدث تصميم لكل شريحة · غيره = المختار أو الأحدث */
function content_publish_images(array $content): array
{
    require_once __DIR__ . '/uploader.php';
    $f = content_format_key($content['format'] ?? 'post');
    $abs = fn($p) => url('storage/' . $p);
    if ($f === 'carousel') {
        $by = content_designs_by_slide((int) $content['id']);
        $out = [];
        for ($k = 1; $k <= (int) ($content['slides_count'] ?? 0); $k++) {
            if (!empty($by[$k])) $out[] = $abs($by[$k][0]['image_path']);
        }
        return $out;
    }
    if ($f === 'video') return [];
    if (!empty($content['selected_image_id'])) {
        $img = db_one('SELECT image_path FROM content_designs WHERE id = ? AND content_id = ?', [$content['selected_image_id'], $content['id']]);
        if ($img) return [$abs($img['image_path'])];
    }
    $img = db_one('SELECT image_path FROM content_designs WHERE content_id = ? ORDER BY id DESC LIMIT 1', [$content['id']]);
    return $img ? [$abs($img['image_path'])] : [];
}

/* ═══════════ الفيديو ═══════════ */

/** الحالات بالترتيب: key => [label, لون الشيب] */
function video_statuses(): array
{
    return [
        'script'        => ['السكريبت جاهز', 'grey'],
        'approved'      => ['العميل اعتمد السكريبت', 'blue'],
        'sent'          => ['اتبعت للتنفيذ', 'blue'],
        'in_production' => ['قيد التنفيذ', 'warn'],
        'ready'         => ['الفيديو جاهز', 'ok'],
        'delivered'     => ['اتسلّم', 'ok'],
    ];
}

function video_status_meta(?string $s): array
{
    $all = video_statuses();
    $s = isset($all[(string) $s]) ? (string) $s : 'script';
    $keys = array_keys($all);
    return ['key' => $s, 'label' => $all[$s][0], 'cls' => $all[$s][1], 'step' => array_search($s, $keys, true) + 1, 'total' => count($keys)];
}

/** الحالات اللي الأدمن بيحركها (بعد ما الطلب يوصله) */
function video_admin_statuses(): array
{
    return ['sent', 'in_production', 'ready', 'delivered'];
}

function video_brief(array $content): array
{
    $b = json_decode((string) ($content['video_brief_json'] ?? ''), true);
    $b = is_array($b) ? $b : [];
    $s = is_array($b['script'] ?? null) ? $b['script'] : [];
    return [
        'type' => (string) ($b['type'] ?? 'ريلز'), 'duration' => (string) ($b['duration'] ?? '30 ثانية'),
        'platform' => (string) ($b['platform'] ?? 'إنستجرام + فيسبوك'), 'ratio' => (string) ($b['ratio'] ?? '9:16'),
        'notes' => (string) ($b['notes'] ?? ''),
        'script' => ['hook' => (string) ($s['hook'] ?? ''), 'body' => (string) ($s['body'] ?? ''), 'cta' => (string) ($s['cta'] ?? ''),
                     'shoot' => (string) ($s['shoot'] ?? '')],
    ];
}

/** خيارات البريف (قوايم ثابتة) */
function video_brief_options(): array
{
    return [
        'type' => ['ريلز', 'فيديو إعلاني', 'فيديو تعليمي', 'آراء عملاء', 'موشن جرافيك', 'تصوير منتج'],
        'duration' => ['15 ثانية', '30 ثانية', '45 ثانية', '60 ثانية', '90 ثانية'],
        'platform' => ['إنستجرام + فيسبوك', 'إنستجرام', 'فيسبوك', 'تيك توك', 'يوتيوب شورتس'],
        'ratio' => ['9:16', '1:1', '4:5', '16:9'],
    ];
}

/** تنضيف البريف الجاي من الواجهة (القيم برّه القوايم بترجع للحالية) */
function video_brief_clean(array $in, array $current): array
{
    $o = video_brief_options();
    $b = $current;
    foreach (['type', 'duration', 'platform', 'ratio'] as $k) {
        if (isset($in[$k]) && in_array((string) $in[$k], $o[$k], true)) $b[$k] = (string) $in[$k];
    }
    if (array_key_exists('notes', $in)) $b['notes'] = mb_substr(trim((string) $in['notes']), 0, 1000);
    if (isset($in['script']) && is_array($in['script'])) {
        foreach (['hook' => 500, 'body' => 5000, 'cta' => 300, 'shoot' => 1000] as $k => $max) {
            if (array_key_exists($k, $in['script'])) $b['script'][$k] = mb_substr(trim((string) $in['script'][$k]), 0, $max);
        }
    }
    return $b;
}

function video_script_text(array $brief): string
{
    $s = $brief['script'];
    return trim(($s['hook'] !== '' ? 'Hook: ' . $s['hook'] . "\n\n" : '') . $s['body'] . ($s['cta'] !== '' ? "\n\nCTA: " . $s['cta'] : '')
        . ($s['shoot'] !== '' ? "\n\nفكرة التصوير: " . $s['shoot'] : ''));
}

/** رقم واتساب طلبات الفيديو (أرقام بس) — فاضي = مش مضبوط */
function video_whatsapp_number(): string
{
    foreach (['video_whatsapp', 'whatsapp_float', 'pay_whatsapp'] as $k) {
        $n = preg_replace('/\D+/', '', (string) (function_exists('get_setting') ? get_setting($k, '') : ''));
        if (strlen($n) >= 8) return $n;
    }
    return '';
}

/** رسالة واتساب الجاهزة (زي المطلوب بالظبط) */
function video_whatsapp_message(array $user, ?array $brand, array $content): string
{
    $b = video_brief($content);
    return "طلب تنفيذ فيديو 🎬\n"
        . 'اسم العميل: ' . trim((string) ($user['name'] ?? '')) . "\n"
        . 'اسم البراند: ' . trim((string) ($brand['business_name'] ?? '')) . "\n"
        . 'نوع الفيديو: ' . $b['type'] . "\n"
        . 'مدة الفيديو: ' . $b['duration'] . "\n"
        . 'المنصة: ' . $b['platform'] . "\n"
        . 'المقاس: ' . $b['ratio'] . "\n"
        . 'رقم الطلب: #' . (int) $content['id'] . "\n"
        . "السكريبت:\n----------------\n" . mb_substr(video_script_text($b), 0, 3000) . "\n----------------\n"
        . "ملاحظات:\n" . ($b['notes'] !== '' ? $b['notes'] : '—');
}

function video_whatsapp_link(array $user, ?array $brand, array $content): string
{
    $n = video_whatsapp_number();
    return $n === '' ? '' : 'https://wa.me/' . $n . '?text=' . rawurlencode(video_whatsapp_message($user, $brand, $content));
}

function video_set_status(int $contentId, string $status): void
{
    db_run('UPDATE contents SET video_status = ?, video_status_at = NOW() WHERE id = ?', [$status, $contentId]);
}

/** شكل المحتوى للواجهة (المكتبة · الحملة · الأدمن) */
function content_format_api(array $content, ?array $bySlide = null): array
{
    require_once __DIR__ . '/uploader.php';
    $f = content_format_key($content['format'] ?? 'post');
    $out = ['format' => $f, 'format_label' => content_format_label($f), 'format_emoji' => content_formats()[$f]['emoji']];
    if ($f === 'carousel') {
        $bySlide = $bySlide ?? content_designs_by_slide((int) $content['id']);
        $out['slides'] = array_map(fn($s) => $s + ['designs' => array_map(fn($d) => ['id' => (int) $d['id'], 'url' => upload_url($d['image_path']), 'ratio' => (string) $d['ratio']],
            $bySlide[$s['n']] ?? [])], content_slides($content));
    }
    if ($f === 'video') {
        $out['video'] = ['status' => video_status_meta($content['video_status'] ?? null), 'brief' => video_brief($content),
                         'delivery_url' => (string) ($content['video_delivery_url'] ?? ''), 'note' => (string) ($content['video_admin_note'] ?? ''),
                         'at' => $content['video_status_at'] ?? null, 'wa' => video_whatsapp_number() !== ''];
    }
    $out['progress'] = content_design_progress($content, $bySlide);
    return $out;
}

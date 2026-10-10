<?php
/**
 * Spread AI v2 — إعدادات Design Studio من الأدمن (المرحلة ⑤-ب)
 *
 *  • المقاسات: قايمة يتحكم فيها الأدمن (design_ratios() في helpers بتقرا من هنا)
 *  • المقاس المكتوب هو الأساسي: لو العميل كتب «1080×1350» أو «مقاس 4:5» أو «ستوري»
 *    ده اللي بيتنفّذ — حتى لو اختار مقاس تاني، وحتى لو مش موجود في القايمة
 *  • الطرق: الأساسية (المحرك ثابت) + طرق جديدة من الأدمن بتفاصيلها
 */

/* ═══════════ المقاسات ═══════════ */

function studio_ratio_defaults(): array
{
    return [
        ['w' => 1024, 'h' => 1024, 'label' => 'مربع 1:1',      'hint' => 'بوست فيسبوك/انستجرام', 'keywords' => 'مربع, square',                     'active' => 1],
        ['w' => 1024, 'h' => 1280, 'label' => 'عمودي 4:5',     'hint' => 'انستجرام عمودي',       'keywords' => 'عمودي, portrait',                  'active' => 1],
        ['w' => 1024, 'h' => 1820, 'label' => 'ستوري 9:16',    'hint' => 'ستوري وريلز',          'keywords' => 'ستوري, ريلز, story, reels, تيك توك', 'active' => 1],
        ['w' => 1280, 'h' => 1024, 'label' => 'أفقي خفيف 5:4', 'hint' => 'بوست عريض',            'keywords' => '',                                 'active' => 1],
        ['w' => 1536, 'h' => 1024, 'label' => 'أفقي 3:2',      'hint' => 'غلاف/بانر',            'keywords' => 'غلاف, بانر, cover, banner',         'active' => 1],
    ];
}

/** مفتاح المقاس من الأبعاد: 1080×1350 ← 4:5 · 1200×628 ← 1.91:1 */
function studio_ratio_key(int $w, int $h): string
{
    // النسب المعروفة بفرق أقل من 1% بتاخد اسمها — 1024×1820 = 9:16 (مش 1:1.78)
    // مهم: التصميمات القديمة متحفظة بالمفاتيح دي، ولازم تفضل تتعرف
    $r = $w / max(1, $h);
    foreach (['1:1' => 1, '4:5' => 0.8, '5:4' => 1.25, '9:16' => 9 / 16, '16:9' => 16 / 9, '2:3' => 2 / 3, '3:2' => 1.5,
              '3:4' => 0.75, '4:3' => 4 / 3, '1.91:1' => 1.91, '21:9' => 21 / 9] as $k => $v) {
        if (abs($r - $v) / $v < 0.01) return $k;
    }
    $a = $w; $b = $h;
    while ($b) { [$a, $b] = [$b, $a % $b]; }
    $x = intdiv($w, max(1, $a)); $y = intdiv($h, max(1, $a));
    if ($x <= 40 && $y <= 40) return "{$x}:{$y}";
    return $w >= $h ? (rtrim(rtrim(number_format($w / $h, 2, '.', ''), '0'), '.') . ':1')
                    : ('1:' . rtrim(rtrim(number_format($h / $w, 2, '.', ''), '0'), '.'));
}

/** مقاس OpenAI المسموح (3 بس) — بيتحسب لوحده من الاتجاه، الأدمن مابيكتبوش */
function studio_ratio_api_size(int $w, int $h): string
{
    if (abs($w - $h) <= max($w, $h) * 0.04) return '1024x1024';
    return $h > $w ? '1024x1536' : '1536x1024';
}

/** كل المقاسات (بما فيها المقفولة) — للأدمن */
function studio_ratios_all(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;
    $rows = null;
    // لو get_setting لسه مااتحمّلتش، بنرجّع الافتراضي من غير ما نحفظه في الكاش —
    // وإلا المقاسات بتاعة الأدمن كانت هتتجاهل لحد آخر الطلب
    $canRead = function_exists('get_setting');
    if ($canRead) {
        try {
            $json = (string) get_setting('design_ratios_json', '');
            $rows = $json !== '' ? json_decode($json, true) : null;
        } catch (\Throwable $e) { $rows = null; }
    }
    if (!is_array($rows) || !$rows) $rows = studio_ratio_defaults();
    $out = [];
    foreach ($rows as $r) {
        $w = max(256, min(4096, (int) ($r['w'] ?? 0)));
        $h = max(256, min(4096, (int) ($r['h'] ?? 0)));
        $key = studio_ratio_key($w, $h);
        $out[$key] = [
            'label'    => trim((string) ($r['label'] ?? $key)) ?: $key,
            'w' => $w, 'h' => $h,
            'size'     => studio_ratio_api_size($w, $h),
            'hint'     => trim((string) ($r['hint'] ?? '')),
            'keywords' => trim((string) ($r['keywords'] ?? '')),
            'active'   => !empty($r['active']) ? 1 : 0,
        ];
    }
    if (!$canRead) return $out;
    return $cache = $out;
}

function studio_ratios_save(array $rows): void
{
    set_setting('design_ratios_json', json_encode(array_values($rows), JSON_UNESCAPED_UNICODE));
}

/** تحويل الأرقام العربية/الفارسية لإنجليزي */
function studio_digits(string $t): string
{
    return strtr($t, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
                      '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
                      '×' => 'x', '✕' => 'x', '＊' => 'x', '：' => ':']);
}

/**
 * المقاس اللي العميل كتبه في النص (لو موجود) — بالترتيب: بكسلات ← نسبة ← كلمة
 * @return array|null ['key','label','w','h','size','hint','typed'=>true,'match']
 */
function studio_ratio_from_text(string $text): ?array
{
    $t = mb_strtolower(studio_digits($text));
    if (trim($t) === '') return null;
    $all = studio_ratios_all();
    $build = function (int $w, int $h, string $match) use ($all): array {
        $key = studio_ratio_key($w, $h);
        $base = $all[$key] ?? null;
        return [
            'key' => $key, 'label' => $base['label'] ?? ('مقاس مكتوب ' . $key),
            'w' => $w, 'h' => $h, 'size' => studio_ratio_api_size($w, $h),
            'hint' => $base['hint'] ?? '', 'typed' => true, 'match' => $match, 'in_list' => $base !== null,
        ];
    };

    // ① بكسلات: 1080x1350 · 1080 × 1920 · 1080 في 1350
    if (preg_match('/(?<!\d)(\d{3,4})\s*(?:x|\*|بـ|ب|في)\s*(\d{3,4})(?!\d)/u', $t, $m)) {
        $w = (int) $m[1]; $h = (int) $m[2];
        if ($w >= 200 && $h >= 200 && $w <= 6000 && $h <= 6000 && max($w, $h) / min($w, $h) <= 4) {
            // بنحفظ النسبة بالظبط، وبنخلّي الضلع الطويل ≤ 2048
            $s = min(1, 2048 / max($w, $h));
            return $build((int) round($w * $s), (int) round($h * $s), $m[0]);
        }
    }

    // ② نسبة: 4:5 · 9/16 · 1.91:1 — قريبة من «مقاس/نسبة» أو من النسب المعروفة (عشان «الساعة 9:30» ماتتفهمش مقاس)
    $common = ['1:1', '4:5', '5:4', '9:16', '16:9', '2:3', '3:2', '3:4', '4:3', '1.91:1', '21:9'];
    if (preg_match_all('/(?<![\d.])(\d{1,2}(?:\.\d{1,2})?)\s*[:\/]\s*(\d{1,2}(?:\.\d{1,2})?)(?![\d.:])/u', $t, $mm, PREG_OFFSET_CAPTURE)) {
        foreach ($mm[0] as $i => [$whole, $off]) {
            $a = (float) $mm[1][$i][0]; $b = (float) $mm[2][$i][0];
            if ($a <= 0 || $b <= 0 || max($a, $b) / min($a, $b) > 4) continue;
            $before = substr($t, max(0, $off - 40), min(40, $off));
            if (preg_match('/(الساعة|ساعه|الساعه|at\s*$|pm|am)\s*$/u', $before)) continue;
            $ctx = (bool) preg_match('/(مقاس|نسبة|نسبه|ابعاد|أبعاد|ratio|size|aspect|فورمات|format)/u', $before);
            $norm = rtrim(rtrim((string) $a, '0'), '.') . ':' . rtrim(rtrim((string) $b, '0'), '.');
            // «/» بتتكتب في التواريخ (10/10) — ماتتقبلش غير جنب كلمة مقاس/نسبة
            if (str_contains($whole, '/') && !$ctx) continue;
            if (!$ctx && !in_array($norm, $common, true)) continue;
            if ($a >= $b) { $h = 1024; $w = (int) round(1024 * $a / $b); } else { $w = 1024; $h = (int) round(1024 * $b / $a); }
            $s = min(1, 2048 / max($w, $h));
            return $build((int) round($w * $s), (int) round($h * $s), trim($whole));
        }
    }

    // ③ كلمات الأدمن: «ستوري» · «غلاف» … (كلمة كاملة)
    foreach ($all as $key => $r) {
        if (empty($r['active']) || $r['keywords'] === '') continue;
        foreach (array_filter(array_map('trim', explode(',', mb_strtolower($r['keywords'])))) as $kw) {
            if (preg_match('/(?<![\p{L}\p{N}])' . preg_quote($kw, '/') . '(?![\p{L}\p{N}])/u', $t)) {
                return ['key' => $key, 'label' => $r['label'], 'w' => $r['w'], 'h' => $r['h'], 'size' => $r['size'],
                        'hint' => $r['hint'], 'typed' => true, 'match' => $kw, 'in_list' => true];
            }
        }
    }
    return null;
}

/**
 * المقاس النهائي: المكتوب في النص (لو فيه) ← وإلا الاختيار ← وإلا أول مقاس
 * @return array ['key','meta','typed','match']
 */
function studio_ratio_resolve(string $chosen, string ...$texts): array
{
    foreach ($texts as $tx) {
        if ($tx === '') continue;
        if ($typed = studio_ratio_from_text($tx)) {
            return ['key' => $typed['key'], 'meta' => $typed, 'typed' => true, 'match' => $typed['match']];
        }
    }
    $all = design_ratios();
    $key = array_key_exists($chosen, $all) ? $chosen : (string) array_key_first($all);
    return ['key' => $key, 'meta' => $all[$key], 'typed' => false, 'match' => null];
}

/** وصف المقاس للبرومبت (بيقبل مقاس مكتوب مش موجود في القايمة) */
function studio_ratio_hint(array $r): string
{
    return "Aspect ratio: {$r['key']} ({$r['meta']['w']}x{$r['meta']['h']} pixels) — compose the layout to fill this exact frame naturally.";
}

/* ═══════════ الطرق ═══════════ */

function studio_method_presets(): array
{
    return [
        'icons'  => ['split' => 'قبل/بعد', 'image' => 'صورة', 'bulb' => 'فكرة', 'code' => 'كود', 'trend' => 'ترند', 'star' => 'نجمة',
                     'sparkles' => 'لمعة', 'megaphone' => 'إعلان', 'gift' => 'هدية', 'user' => 'شخص', 'doc' => 'مستند', 'calendar' => 'تقويم'],
        'colors' => ['sunset' => 'برتقالي وردي', 'blue' => 'أزرق', 'teal' => 'تركواز', 'dark' => 'داكن', 'violet' => 'بنفسجي', 'gold' => 'دهبي'],
    ];
}

function studio_method_decode(array $row): array
{
    $cfg = json_decode((string) ($row['config'] ?? ''), true) ?: [];
    $uploads = [];
    foreach (array_slice((array) ($cfg['uploads'] ?? []), 0, 3) as $u) {
        if (!is_array($u) || trim((string) ($u['label'] ?? '')) === '') continue;
        $uploads[] = ['label' => mb_substr(trim($u['label']), 0, 60), 'role' => mb_substr(trim((string) ($u['role'] ?? '')), 0, 400),
                      'required' => !empty($u['required'])];
    }
    return [
        'id'          => (int) $row['id'],
        'key'         => $row['mkey'],
        'kind'        => $row['kind'],
        'title'       => $row['title'],
        'description' => (string) ($row['description'] ?? ''),
        'badge'       => (string) ($row['badge'] ?? ''),
        'icon'        => $row['icon'],
        'color'       => $row['color'],
        'sort'        => (int) $row['sort_order'],
        'active'      => (int) $row['is_active'],
        'ratio'       => (string) ($cfg['ratio'] ?? '1:1'),
        'brief'       => array_key_exists('brief', $cfg) ? (bool) $cfg['brief'] : true,
        'purposes'    => array_values(array_filter(array_map(fn($p) => mb_substr(trim((string) $p), 0, 40), (array) ($cfg['purposes'] ?? [])))),
        'text'        => [
            'label'       => (string) ($cfg['text']['label'] ?? ''),
            'placeholder' => (string) ($cfg['text']['placeholder'] ?? ''),
            'required'    => !empty($cfg['text']['required']),
        ],
        'uploads'     => $uploads,
        'prompt'      => (string) ($cfg['prompt'] ?? ''),
    ];
}

/** @param bool $activeOnly */
function studio_methods(bool $activeOnly = true): array
{
    try {
        $rows = db_all('SELECT * FROM studio_methods' . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY sort_order, id');
    } catch (\Throwable $e) {
        return [];   // قبل ترحيل ⑤-ب
    }
    return array_map('studio_method_decode', $rows);
}

function studio_method(string $key): ?array
{
    try {
        $r = db_one('SELECT * FROM studio_methods WHERE mkey = ? AND is_active = 1', [$key]);
    } catch (\Throwable $e) {
        return null;
    }
    return $r ? studio_method_decode($r) : null;
}

/** قالب البرومبت للطرق الجديدة: {text} · {purpose} · {brand} */
function studio_method_prompt(array $m, string $text, string $purpose, string $brandName): string
{
    $tpl = $m['prompt'] !== '' ? $m['prompt'] : 'Create a professional social media design. {text}';
    return trim(strtr($tpl, ['{text}' => $text, '{purpose}' => $purpose, '{brand}' => $brandName]));
}

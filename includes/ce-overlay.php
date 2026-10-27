<?php
/**
 * Spread AI — محرك المحتوى v3 · النص العربي فوق الصورة (المرحلة G)
 *
 * الموديل مابيكتبش أي نص جوه الصورة. النص بيتحط بعد التوليد بـ GD:
 *   1) تشكيل الحروف العربية (أول/وسط/آخر/لوحده + لام ألف) — GD مابيعملش ده لوحده
 *   2) ترتيب العرض من اليمين للشمال (والأرقام والإنجليزي بيفضلوا بترتيبهم)
 *   3) لفّ السطور على عرض المنطقة الفاضية وتصغير الخط لحد ما يدخل
 *   4) القالب: خلفية للنص (تدرّج · شريط · كارت · كامل) + لون النص حسب التباين + خط اللون + الشعار
 * الخطوط: assets/fonts (Cairo · Tajawal — رخصة OFL) + اللي الأدمن بيرفعه في storage/uploads/ce-fonts.
 */

/* ═══════════════════ تشكيل العربي ═══════════════════ */

/** [لوحده، آخر، أول، وسط] — null = الحرف مابيتوصلش باللي بعده */
const CE_AR_FORMS = [
    0x0621 => [0xFE80, null, null, null], 0x0622 => [0xFE81, 0xFE82, null, null], 0x0623 => [0xFE83, 0xFE84, null, null],
    0x0624 => [0xFE85, 0xFE86, null, null], 0x0625 => [0xFE87, 0xFE88, null, null], 0x0626 => [0xFE89, 0xFE8A, 0xFE8B, 0xFE8C],
    0x0627 => [0xFE8D, 0xFE8E, null, null], 0x0628 => [0xFE8F, 0xFE90, 0xFE91, 0xFE92], 0x0629 => [0xFE93, 0xFE94, null, null],
    0x062A => [0xFE95, 0xFE96, 0xFE97, 0xFE98], 0x062B => [0xFE99, 0xFE9A, 0xFE9B, 0xFE9C], 0x062C => [0xFE9D, 0xFE9E, 0xFE9F, 0xFEA0],
    0x062D => [0xFEA1, 0xFEA2, 0xFEA3, 0xFEA4], 0x062E => [0xFEA5, 0xFEA6, 0xFEA7, 0xFEA8], 0x062F => [0xFEA9, 0xFEAA, null, null],
    0x0630 => [0xFEAB, 0xFEAC, null, null], 0x0631 => [0xFEAD, 0xFEAE, null, null], 0x0632 => [0xFEAF, 0xFEB0, null, null],
    0x0633 => [0xFEB1, 0xFEB2, 0xFEB3, 0xFEB4], 0x0634 => [0xFEB5, 0xFEB6, 0xFEB7, 0xFEB8], 0x0635 => [0xFEB9, 0xFEBA, 0xFEBB, 0xFEBC],
    0x0636 => [0xFEBD, 0xFEBE, 0xFEBF, 0xFEC0], 0x0637 => [0xFEC1, 0xFEC2, 0xFEC3, 0xFEC4], 0x0638 => [0xFEC5, 0xFEC6, 0xFEC7, 0xFEC8],
    0x0639 => [0xFEC9, 0xFECA, 0xFECB, 0xFECC], 0x063A => [0xFECD, 0xFECE, 0xFECF, 0xFED0], 0x0641 => [0xFED1, 0xFED2, 0xFED3, 0xFED4],
    0x0642 => [0xFED5, 0xFED6, 0xFED7, 0xFED8], 0x0643 => [0xFED9, 0xFEDA, 0xFEDB, 0xFEDC], 0x0644 => [0xFEDD, 0xFEDE, 0xFEDF, 0xFEE0],
    0x0645 => [0xFEE1, 0xFEE2, 0xFEE3, 0xFEE4], 0x0646 => [0xFEE5, 0xFEE6, 0xFEE7, 0xFEE8], 0x0647 => [0xFEE9, 0xFEEA, 0xFEEB, 0xFEEC],
    0x0648 => [0xFEED, 0xFEEE, null, null], 0x0649 => [0xFEEF, 0xFEF0, null, null], 0x064A => [0xFEF1, 0xFEF2, 0xFEF3, 0xFEF4],
    0x067E => [0xFB56, 0xFB57, 0xFB58, 0xFB59], 0x0686 => [0xFB7A, 0xFB7B, 0xFB7C, 0xFB7D], 0x06A4 => [0xFB6A, 0xFB6B, 0xFB6C, 0xFB6D],
    0x06AF => [0xFB92, 0xFB93, 0xFB94, 0xFB95], 0x06A9 => [0xFB8E, 0xFB8F, 0xFB90, 0xFB91], 0x06CC => [0xFBFC, 0xFBFD, 0xFBFE, 0xFBFF],
];
/** لام + ألف ← [لوحدها، آخر] */
const CE_AR_LAMALEF = [0x0622 => [0xFEF5, 0xFEF6], 0x0623 => [0xFEF7, 0xFEF8], 0x0625 => [0xFEF9, 0xFEFA], 0x0627 => [0xFEFB, 0xFEFC]];
const CE_AR_MIRROR = ['(' => ')', ')' => '(', '[' => ']', ']' => '[', '{' => '}', '}' => '{', '«' => '»', '»' => '«', '<' => '>', '>' => '<'];

/** تشكيل سطر منطقي (بالترتيب اللي اتكتب بيه) لأشكال العرض — من غير ما نعكس الاتجاه */
function ce_ar_shape(string $s): string
{
    // التشكيل (الحركات) بيتشال: GD مابيعرفش يحطه في مكانه فوق الحرف
    $s = preg_replace('/[\x{064B}-\x{065F}\x{0670}]/u', '', $s);
    $cp = array_map(fn($c) => mb_ord($c), mb_str_split($s));
    $n = count($cp);
    $joins = fn(?int $c) => $c !== null && ((isset(CE_AR_FORMS[$c]) && $c !== 0x0621) || $c === 0x0640);
    $dual = fn(?int $c) => $c !== null && ((isset(CE_AR_FORMS[$c]) && CE_AR_FORMS[$c][2] !== null) || $c === 0x0640);
    $out = [];
    for ($i = 0; $i < $n; $i++) {
        $c = $cp[$i];
        $prev = $i > 0 ? $cp[$i - 1] : null;
        $next = $i < $n - 1 ? $cp[$i + 1] : null;
        if ($c === 0x0644 && $next !== null && isset(CE_AR_LAMALEF[$next])) {
            $out[] = CE_AR_LAMALEF[$next][$dual($prev) ? 1 : 0];
            $i++;
            continue;
        }
        if (!isset(CE_AR_FORMS[$c])) { $out[] = $c; continue; }
        $f = CE_AR_FORMS[$c];
        $toPrev = $c !== 0x0621 && $dual($prev);
        $toNext = $f[2] !== null && $joins($next);
        // الشكل «لوحده» = الحرف الأصلي: خطوط كتير (Cairo · Tajawal) مافيهاش نموذج العرض المنفصل وبترسم الأصلي بنفس الشكل
        $out[] = $toPrev && $toNext ? $f[3] : ($toPrev ? $f[1] : ($toNext ? $f[2] : $c));
    }
    return implode('', array_map('mb_chr', $out));
}

/** سطر جاهز للرسم: تشكيل + ترتيب من اليمين للشمال (الأرقام والإنجليزي بيفضلوا زي ما هم) */
function ce_ar_visual(string $s): string
{
    $s = ce_ar_shape($s);
    if (!preg_match('/[\x{0600}-\x{06FF}\x{FB50}-\x{FEFF}]/u', $s)) return $s;       // سطر إنجليزي/أرقام بس
    $ltr = '[A-Za-z0-9\x{0660}-\x{0669}\x{06F0}-\x{06F9}]';
    preg_match_all('/(' . $ltr . '+(?:[ .,:%\/\-+]+' . $ltr . '+)*%?)|([^A-Za-z0-9\x{0660}-\x{0669}\x{06F0}-\x{06F9}]+)/u', $s, $m, PREG_SET_ORDER);
    $segs = [];
    foreach ($m as $g) {
        if (($g[1] ?? '') !== '') { $segs[] = $g[1]; continue; }
        $chars = array_reverse(mb_str_split($g[2]));
        $segs[] = implode('', array_map(fn($c) => CE_AR_MIRROR[$c] ?? $c, $chars));
    }
    return implode('', array_reverse($segs));
}

/* ═══════════════════ الخطوط ═══════════════════ */

/** كل الخطوط المتاحة: [المفتاح => ['path', 'label', 'family', 'weight', 'builtin']] */
function ce_fonts(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;
    $out = [];
    $dirs = [[dirname(__DIR__) . '/assets/fonts', true], [(defined('STORAGE_PATH') ? STORAGE_PATH : dirname(__DIR__) . '/storage') . '/uploads/ce-fonts', false]];
    foreach ($dirs as [$dir, $builtin]) {
        foreach (glob($dir . '/*.{ttf,otf,TTF,OTF}', GLOB_BRACE) ?: [] as $f) {
            $key = preg_replace('/[^A-Za-z0-9_\-]/', '', pathinfo($f, PATHINFO_FILENAME));
            if ($key === '' || isset($out[$key])) continue;
            $fam = preg_replace('/[-_ ]?(\d{3}|Regular|Bold|ExtraBold|Black|Light|Medium|SemiBold)$/i', '', $key);
            preg_match('/(\d{3})$/', $key, $wm);
            $out[$key] = ['path' => $f, 'label' => $fam . (isset($wm[1]) ? ' ' . $wm[1] : ''), 'family' => strtolower($fam),
                          'weight' => (int) ($wm[1] ?? (preg_match('/bold|black/i', $key) ? 700 : 400)), 'builtin' => $builtin];
        }
    }
    ksort($out);
    return $cache = $out;
}

/** مسار الخط: لو خط البراند (من الهوية) متاح بنفس التقل بيكسب — غير كده خط القالب — غير كده Cairo */
function ce_font_path(string $key, array $brandFonts = []): string
{
    $fonts = ce_fonts();
    $want = $fonts[$key] ?? null;
    foreach ($brandFonts as $bf) {
        $fam = strtolower(preg_replace('/[^A-Za-z]/', '', (string) $bf));
        if ($fam === '') continue;
        $cands = array_filter($fonts, fn($f) => preg_replace('/[^a-z]/', '', $f['family']) === $fam);
        if (!$cands) continue;
        $w = $want['weight'] ?? 700;
        usort($cands, fn($a, $b) => abs($a['weight'] - $w) <=> abs($b['weight'] - $w));
        return $cands[0]['path'];
    }
    if ($want) return $want['path'];
    return $fonts['Cairo-700']['path'] ?? (reset($fonts)['path'] ?? '');
}

/** الخط فيه حروف العرض العربي؟ (من غيرها النص بيطلع مربعات) */
function ce_font_has_arabic(string $path): bool
{
    if (!is_file($path) || !function_exists('imagettfbbox')) return false;
    $probe = function (string $ch) use ($path): string {
        $im = imagecreatetruecolor(50, 50);
        imagettftext($im, 28, 0, 6, 40, imagecolorallocate($im, 255, 255, 255), $path, $ch);
        ob_start(); imagepng($im); $h = md5((string) ob_get_clean());
        imagedestroy($im);
        return $h;
    };
    $empty = $probe("\u{E000}");
    // حروف الوصل (أول · وسط · آخر) لازم تكون موجودة — الشكل المنفصل بنستخدم فيه الحرف الأصلي
    foreach (["\u{FE91}", "\u{FE92}", "\u{FE90}", "\u{FEFB}", "\u{0628}"] as $ch) if ($probe($ch) === $empty) return false;
    return true;
}

/* ═══════════════════ القوالب ═══════════════════ */

const CE_TPL_DEFAULT = ['layout' => 'auto', 'scrim' => 'fade', 'scrim_color' => 'dark', 'scrim_opacity' => 78, 'text_color' => 'auto', 'align' => 'center',
    'h_size' => 8, 's_size' => 4.2, 'font_h' => 'Cairo-800', 'font_s' => 'Cairo-400', 'accent' => 1, 'logo' => 'corner', 'band' => 36];
const CE_TPL_LAYOUTS = ['auto' => 'حسب المساحة الفاضية في الصورة', 'bottom' => 'تحت', 'top' => 'فوق', 'center' => 'في النص', 'side' => 'جنب'];
const CE_TPL_SCRIMS = ['fade' => 'تدرّج', 'solid' => 'شريط', 'card' => 'كارت', 'full' => 'طبقة على الصورة كلها', 'none' => 'من غير خلفية'];
const CE_TPL_COLORS = ['dark' => 'غامق', 'light' => 'فاتح', 'primary' => 'لون البراند الأساسي', 'secondary' => 'لون البراند التاني'];
/** المقاسات النهائية بالبكسل */
const CE_CANVAS = ['1:1' => [1080, 1080], '4:5' => [1080, 1350], '9:16' => [1080, 1920]];
const CE_FORMAT_ASPECT = ['post' => '1:1', 'carousel' => '4:5', 'reel' => '9:16'];

function ce_tpl_clean(array $in): array
{
    $t = CE_TPL_DEFAULT;
    $fonts = ce_fonts();
    if (isset(CE_TPL_LAYOUTS[$in['layout'] ?? ''])) $t['layout'] = $in['layout'];
    if (isset(CE_TPL_SCRIMS[$in['scrim'] ?? ''])) $t['scrim'] = $in['scrim'];
    $sc = (string) ($in['scrim_color'] ?? '');
    if (isset(CE_TPL_COLORS[$sc]) || preg_match('/^#[0-9a-f]{6}$/i', $sc)) $t['scrim_color'] = $sc;
    $tc = (string) ($in['text_color'] ?? '');
    if ($tc === 'auto' || preg_match('/^#[0-9a-f]{6}$/i', $tc)) $t['text_color'] = $tc;
    if (in_array($in['align'] ?? '', ['center', 'right'], true)) $t['align'] = $in['align'];
    foreach (['scrim_opacity' => [0, 100], 'h_size' => [3, 16], 's_size' => [2, 9], 'band' => [18, 70]] as $k => [$a, $b]) {
        if (isset($in[$k]) && is_numeric($in[$k])) $t[$k] = max($a, min($b, round((float) $in[$k], 1)));
    }
    foreach (['font_h', 'font_s'] as $k) if (isset($fonts[$in[$k] ?? ''])) $t[$k] = $in[$k];
    $t['accent'] = !empty($in['accent']) ? 1 : 0;
    $t['logo'] = ($in['logo'] ?? '') === 'none' ? 'none' : 'corner';
    return $t;
}

function ce_templates(bool $activeOnly = true, ?string $format = null): array
{
    try {
        $rows = db_all('SELECT * FROM ce_text_templates' . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY sort_order, id');
    } catch (\Throwable $e) {
        return [];
    }
    $out = [];
    foreach ($rows as $r) {
        if ($format && !in_array($format, explode(',', (string) $r['formats']), true)) continue;
        $out[] = ['id' => (int) $r['id'], 'key' => $r['tkey'], 'name' => $r['name'], 'style' => ce_tpl_clean(json_decode((string) $r['style_json'], true) ?: []),
                  'formats' => explode(',', (string) $r['formats']), 'is_active' => (int) $r['is_active'], 'sort_order' => (int) $r['sort_order']];
    }
    return $out;
}

function ce_template_get(?int $id, string $format = 'post'): array
{
    $all = ce_templates(true, $format) ?: ce_templates(false);
    foreach ($all as $t) if ($id && $t['id'] === $id) return $t;
    return $all[0] ?? ['id' => 0, 'key' => 'default', 'name' => 'افتراضي', 'style' => CE_TPL_DEFAULT, 'formats' => ['post', 'carousel', 'reel'], 'is_active' => 1, 'sort_order' => 0];
}

/* ═══════════════════ الرسم ═══════════════════ */

/** المساحة الفاضية من وصف الـ Design Brief (بالعربي أو الإنجليزي) */
function ce_zone_from_text(string $z): string
{
    $z = mb_strtolower($z);
    if (preg_match('/فوق|اعلى|أعلى|top|upper/u', $z)) return 'top';
    if (preg_match('/شمال|يسار|left/u', $z)) return 'left';
    if (preg_match('/يمين|right/u', $z)) return 'right';
    if (preg_match('/نص|منتصف|وسط|center|middle/u', $z)) return 'center';
    return 'bottom';
}

function ce_hex_rgb(string $hex): array
{
    $h = ltrim($hex, '#');
    if (!preg_match('/^[0-9a-f]{6}$/i', $h)) return [12, 135, 239];
    return [hexdec(substr($h, 0, 2)), hexdec(substr($h, 2, 2)), hexdec(substr($h, 4, 2))];
}

function ce_luma(array $rgb): float
{
    return 0.2126 * $rgb[0] + 0.7152 * $rgb[1] + 0.0722 * $rgb[2];
}

/** صورة → GD (png · jpg · webp) */
function ce_gd_load(string $abs)
{
    $info = @getimagesize($abs);
    if (!$info) return null;
    return match ($info[2]) {
        IMAGETYPE_PNG => @imagecreatefrompng($abs),
        IMAGETYPE_JPEG => @imagecreatefromjpeg($abs),
        IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($abs) : null,
        default => null,
    } ?: null;
}

/** قصّ «cover»: الصورة بتملا المقاس كله من غير تمطيط */
function ce_gd_cover($src, int $w, int $h)
{
    $sw = imagesx($src);
    $sh = imagesy($src);
    $scale = max($w / $sw, $h / $sh);
    $cw = (int) round($w / $scale);
    $ch = (int) round($h / $scale);
    $dst = imagecreatetruecolor($w, $h);
    imagecopyresampled($dst, $src, 0, 0, (int) (($sw - $cw) / 2), (int) (($sh - $ch) / 2), $w, $h, $cw, $ch);
    return $dst;
}

function ce_gd_rounded($im, int $x1, int $y1, int $x2, int $y2, int $r, int $col): void
{
    $r = max(0, min($r, (int) (($x2 - $x1) / 2), (int) (($y2 - $y1) / 2)));
    imagefilledrectangle($im, $x1 + $r, $y1, $x2 - $r, $y2, $col);
    imagefilledrectangle($im, $x1, $y1 + $r, $x1 + $r - 1, $y2 - $r, $col);
    imagefilledrectangle($im, $x2 - $r + 1, $y1 + $r, $x2, $y2 - $r, $col);
    if ($r > 0) {
        foreach ([[$x1 + $r, $y1 + $r, 180, 270], [$x2 - $r, $y1 + $r, 270, 360], [$x1 + $r, $y2 - $r, 90, 180], [$x2 - $r, $y2 - $r, 0, 90]] as [$cx, $cy, $a, $b]) {
            imagefilledarc($im, $cx, $cy, $r * 2, $r * 2, $a, $b, $col, IMG_ARC_PIE);
        }
    }
}

/** عرض نص مرسوم (بعد التشكيل) */
function ce_text_width(string $visual, string $font, float $size): int
{
    $b = imagettfbbox($size, 0, $font, $visual);
    return (int) abs($b[2] - $b[0]);
}

/** لف الكلام على سطور بعرض أقصى — بالترتيب المنطقي، وكل سطر بيتشكّل بعدين */
function ce_wrap(string $text, string $font, float $size, int $maxW, int $maxLines): array
{
    $words = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY);
    $lines = [];
    $cur = '';
    foreach ($words as $w) {
        $try = $cur === '' ? $w : $cur . ' ' . $w;
        if ($cur !== '' && ce_text_width(ce_ar_visual($try), $font, $size) > $maxW) {
            $lines[] = $cur;
            $cur = $w;
        } else {
            $cur = $try;
        }
    }
    if ($cur !== '') $lines[] = $cur;
    $fits = count($lines) <= $maxLines;
    foreach ($lines as $l) if (ce_text_width(ce_ar_visual($l), $font, $size) > $maxW) $fits = false;
    return [$lines, $fits];
}

/** أكبر مقاس خط يخلّي الكلام يدخل في العرض وعدد السطور */
function ce_fit(string $text, string $font, float $size, int $maxW, int $maxLines, float $min): array
{
    for ($s = $size; $s >= $min; $s *= 0.92) {
        [$lines, $ok] = ce_wrap($text, $font, $s, $maxW, $maxLines);
        if ($ok) return [$lines, $s];
    }
    [$lines] = ce_wrap($text, $font, $min, $maxW, 99);
    return [array_slice($lines, 0, $maxLines), $min];
}

/**
 * رسم التصميم النهائي.
 * $o: base (مسار الصورة النضيفة أو null = خلفية البراند) · aspect · headline · subline · zone · template (style)
 *     colors (hex[]) · fonts (خطوط البراند) · logo (مسار مطلق) · out (مسار مطلق للحفظ)
 * @return array{ok:bool, error?:string, size?:array}
 */
function ce_overlay_render(array $o): array
{
    if (!function_exists('imagettftext')) return ['ok' => false, 'error' => 'GD/FreeType مش متاح على السيرفر'];
    [$W, $H] = CE_CANVAS[$o['aspect'] ?? '1:1'] ?? CE_CANVAS['1:1'];
    $st = ce_tpl_clean($o['template'] ?? []);
    $colors = array_values(array_filter((array) ($o['colors'] ?? []), fn($c) => preg_match('/^#[0-9a-f]{6}$/i', (string) $c)));
    $primary = ce_hex_rgb($colors[0] ?? '#0C87EF');
    $secondary = ce_hex_rgb($colors[1] ?? ($colors[0] ?? '#2EE3CC'));
    $accentRgb = ce_hex_rgb($colors[2] ?? ($colors[1] ?? '#FFB300'));

    // الخلفية: الصورة (قصّ cover) أو تدرّج بلون البراند
    $src = !empty($o['base']) && is_file($o['base']) ? ce_gd_load($o['base']) : null;
    if ($src) {
        $im = ce_gd_cover($src, $W, $H);
        imagedestroy($src);
    } else {
        $im = imagecreatetruecolor($W, $H);
        for ($y = 0; $y < $H; $y++) {
            $t = $y / $H;
            $c = imagecolorallocate($im, (int) ($primary[0] + ($secondary[0] - $primary[0]) * $t), (int) ($primary[1] + ($secondary[1] - $primary[1]) * $t), (int) ($primary[2] + ($secondary[2] - $primary[2]) * $t));
            imageline($im, 0, $y, $W, $y, $c);
        }
    }
    imagealphablending($im, true);

    // المنطقة
    $zone = $st['layout'] === 'auto' ? ($o['zone'] ?? 'bottom') : ($st['layout'] === 'side' ? (($o['zone'] ?? '') === 'left' ? 'left' : 'right') : $st['layout']);
    if (!$src && $zone !== 'center') $zone = 'center';   // شريحة نص على خلفية البراند: النص في النص
    $band = $st['band'] / 100;
    $pad = (int) round($W * 0.07);
    [$x1, $y1, $x2, $y2] = match ($zone) {
        'top' => [0, 0, $W, (int) ($H * $band)],
        'left' => [0, 0, (int) ($W * max(0.46, $band)), $H],
        'right' => [(int) ($W * (1 - max(0.46, $band))), 0, $W, $H],
        'center' => [0, (int) ($H * (0.5 - $band / 2)), $W, (int) ($H * (0.5 + $band / 2))],
        default => [0, (int) ($H * (1 - $band)), $W, $H],
    };

    // لون الخلفية اللي تحت النص
    $sc = $st['scrim_color'];
    $scRgb = match ($sc) { 'dark' => [11, 21, 38], 'light' => [255, 255, 255], 'primary' => $primary, 'secondary' => $secondary, default => ce_hex_rgb($sc) };
    $alpha = fn(float $op) => (int) round(127 - 127 * max(0, min(1, $op)));
    $op = $st['scrim_opacity'] / 100;
    if (!$src) $st['scrim'] = 'none';
    $under = $scRgb;
    switch ($st['scrim']) {
        case 'solid':
            imagefilledrectangle($im, $x1, $y1, $x2, $y2, imagecolorallocatealpha($im, $scRgb[0], $scRgb[1], $scRgb[2], $alpha($op)));
            break;
        case 'full':
            imagefilledrectangle($im, 0, 0, $W, $H, imagecolorallocatealpha($im, $scRgb[0], $scRgb[1], $scRgb[2], $alpha($op)));
            break;
        case 'card':
            $m = (int) round($W * 0.05);
            [$x1, $y1, $x2, $y2] = [$x1 + $m, $y1 + $m, $x2 - $m, $y2 - $m];
            ce_gd_rounded($im, $x1, $y1, $x2, $y2, (int) round($W * 0.035), imagecolorallocatealpha($im, $scRgb[0], $scRgb[1], $scRgb[2], $alpha($op)));
            $pad = (int) round($W * 0.05);
            break;
        case 'fade':
            // تدرّج من شفاف لغامق ناحية الحافة (أو الناحيتين في النص)
            $len = in_array($zone, ['left', 'right'], true) ? $x2 - $x1 : $y2 - $y1;
            for ($k = 0; $k < $len; $k++) {
                $t = $k / max(1, $len);
                $f = $zone === 'center' ? 1 - abs(0.5 - $t) * 2 : (in_array($zone, ['top', 'left'], true) ? 1 - $t : $t);
                $col = imagecolorallocatealpha($im, $scRgb[0], $scRgb[1], $scRgb[2], $alpha($op * min(1, $f * 1.35)));
                if (in_array($zone, ['left', 'right'], true)) imageline($im, $x1 + $k, 0, $x1 + $k, $H, $col);
                else imageline($im, 0, $y1 + $k, $W, $y1 + $k, $col);
            }
            break;
        case 'none':
            // مفيش خلفية: لون النص حسب متوسط إضاءة المنطقة
            $sum = [0, 0, 0]; $n = 0;
            for ($yy = $y1; $yy < $y2; $yy += 12) for ($xx = $x1; $xx < $x2; $xx += 12) {
                $c = imagecolorat($im, $xx, $yy); $sum[0] += ($c >> 16) & 255; $sum[1] += ($c >> 8) & 255; $sum[2] += $c & 255; $n++;
            }
            $under = $n ? [$sum[0] / $n, $sum[1] / $n, $sum[2] / $n] : [0, 0, 0];
            break;
    }
    if ($st['scrim'] !== 'none' && $op < 0.5 && $src) $under = [($scRgb[0] + 128) / 2, ($scRgb[1] + 128) / 2, ($scRgb[2] + 128) / 2];
    $txtRgb = $st['text_color'] !== 'auto' ? ce_hex_rgb($st['text_color']) : (ce_luma($under) > 150 ? [11, 21, 38] : [255, 255, 255]);
    $txt = imagecolorallocate($im, $txtRgb[0], $txtRgb[1], $txtRgb[2]);
    $shadow = imagecolorallocatealpha($im, 0, 0, 0, 95);

    // النص: العنوان (≤ 3 سطور) + السطر التاني (≤ 2)
    $fh = ce_font_path($st['font_h'], (array) ($o['fonts'] ?? []));
    $fs = ce_font_path($st['font_s'], []);
    if ($fh === '' || !is_file($fh)) return ['ok' => false, 'error' => 'مفيش خط عربي متاح'];
    if ($fs === '' || !is_file($fs)) $fs = $fh;
    $boxW = ($x2 - $x1) - 2 * $pad;
    $boxH = ($y2 - $y1) - 2 * $pad;
    $head = trim((string) ($o['headline'] ?? ''));
    $sub = trim((string) ($o['subline'] ?? ''));
    $hSize = $W * $st['h_size'] / 100 * 0.75;          // pt تقريبًا
    $sSize = $W * $st['s_size'] / 100 * 0.75;
    $blocks = [];
    for ($round = 0; $round < 8; $round++) {
        [$hl, $hs] = $head !== '' ? ce_fit($head, $fh, $hSize, $boxW, in_array($zone, ['left', 'right'], true) ? 4 : 3, $hSize * 0.45) : [[], $hSize];
        [$sl, $ss] = $sub !== '' ? ce_fit($sub, $fs, min($sSize, $hs * 0.62), $boxW, 2, $sSize * 0.5) : [[], $sSize];
        $hLH = $hs * 1.62;
        $sLH = $ss * 1.7;
        $accentH = $st['accent'] && $head !== '' ? (int) round($W * 0.012) + (int) round($hs * 0.6) : 0;
        $total = count($hl) * $hLH + ($sl ? $ss * 0.5 + count($sl) * $sLH : 0) + $accentH;
        if ($total <= $boxH || $round === 7) break;
        $hSize *= 0.9;
        $sSize *= 0.9;
    }
    $y = $y1 + $pad + max(0, ($boxH - $total) / 2);
    $right = $x2 - $pad;
    $cx = ($x1 + $x2) / 2;
    $place = function (string $vis, string $font, float $size) use ($st, $right, $cx) {
        $w = ce_text_width($vis, $font, $size);
        return $st['align'] === 'right' ? $right - $w : (int) round($cx - $w / 2);
    };
    if ($accentH) {
        $aw = (int) round($W * 0.09);
        $ah = (int) round($W * 0.012);
        $ax = $st['align'] === 'right' ? $right - $aw : (int) round($cx - $aw / 2);
        ce_gd_rounded($im, $ax, (int) $y, $ax + $aw, (int) $y + $ah, (int) ($ah / 2), imagecolorallocate($im, $accentRgb[0], $accentRgb[1], $accentRgb[2]));
        $y += $accentH;
    }
    foreach ($hl as $line) {
        $vis = ce_ar_visual($line);
        $x = $place($vis, $fh, $hs);
        $base = (int) round($y + $hs * 1.18);
        if ($st['scrim'] === 'none' || $st['scrim'] === 'full') imagettftext($im, $hs, 0, $x + 2, $base + 3, $shadow, $fh, $vis);
        imagettftext($im, $hs, 0, $x, $base, $txt, $fh, $vis);
        $y += $hLH;
    }
    if ($sl) {
        $y += $ss * 0.5;
        $subCol = imagecolorallocatealpha($im, $txtRgb[0], $txtRgb[1], $txtRgb[2], 18);
        foreach ($sl as $line) {
            $vis = ce_ar_visual($line);
            imagettftext($im, $ss, 0, $place($vis, $fs, $ss), (int) round($y + $ss * 1.2), $subCol, $fs, $vis);
            $y += $sLH;
        }
    }

    // شعار البراند في الركن اللي بعيد عن النص
    if ($st['logo'] === 'corner' && !empty($o['logo']) && is_file($o['logo']) && ($lg = ce_gd_load($o['logo']))) {
        imagealphablending($lg, true);
        $lw = (int) round($W * 0.13);
        $lh = (int) round(imagesy($lg) * $lw / max(1, imagesx($lg)));
        $m = (int) round($W * 0.045);
        $ly = $zone === 'top' ? $H - $lh - $m : $m;
        $lx = $zone === 'right' ? $m : $W - $lw - $m;
        imagecopyresampled($im, $lg, $lx, $ly, 0, 0, $lw, $lh, imagesx($lg), imagesy($lg));
        imagedestroy($lg);
    }

    $dir = dirname((string) $o['out']);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $ok = imagepng($im, (string) $o['out'], 6);
    imagedestroy($im);
    return $ok ? ['ok' => true, 'size' => [$W, $H], 'zone' => $zone, 'lines' => count($hl)] : ['ok' => false, 'error' => 'مقدرناش نحفظ الصورة'];
}

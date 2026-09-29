<?php
/**
 * Spread AI v2 — Watermark Engine (Phase 5)
 *
 * وضع لوجو المنصة كعلامة مائية صغيرة شفافة على الصور المولّدة.
 * GD فقط (متاح على أي استضافة) — يحافظ على شفافية اللوجو PNG.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/credits.php'; // get_setting

/**
 * هل العلامة المائية مفعّلة وجاهزة؟
 */
function watermark_ready(): bool
{
    if (!function_exists('imagecreatetruecolor')) {
        return false; // GD غير متاح
    }
    if (get_setting('watermark_enabled', '0') !== '1') {
        return false;
    }
    $logo = (string) get_setting('watermark_logo', '');
    return $logo !== '' && is_file(STORAGE_PATH . '/' . $logo);
}

/**
 * تطبيق العلامة المائية على صورة (in-place) مع الاحتفاظ بنسخة أصلية.
 *
 * @param string $relativePath  المسار نسبةً لـ storage/ (مثال: uploads/designs/abc.png)
 * @param array  $override      إعدادات بديلة للاختبار (size/opacity/position/padding/keep_original)
 * @return array {ok, error}
 */
function watermark_apply(string $relativePath, array $override = []): array
{
    $logoRel = (string) get_setting('watermark_logo', '');
    if ($logoRel === '') {
        return ['ok' => false, 'error' => 'ارفع لوجو العلامة المائية أولًا'];
    }
    return wm_overlay($relativePath, $logoRel, [
        'size'          => (int) ($override['size']     ?? get_setting('watermark_size', 12)),
        'opacity'       => (int) ($override['opacity']  ?? get_setting('watermark_opacity', 40)),
        'position'      => (string) ($override['position'] ?? get_setting('watermark_position', 'bottom-right')),
        'padding'       => (int) ($override['padding']  ?? get_setting('watermark_padding', 3)),
        'keep_original' => (string) ($override['keep_original'] ?? get_setting('watermark_keep_original', '1')),
    ]);
}

/**
 * وضع لوجو براند العميل على تصميم (يُستخدم عند توليد التصميمات)
 */
function brand_logo_apply(string $relativePath, string $logoRel, array $override = []): array
{
    return wm_overlay($relativePath, $logoRel, [
        'size'          => (int) ($override['size'] ?? 16),
        'opacity'       => (int) ($override['opacity'] ?? 100),
        'position'      => (string) ($override['position'] ?? 'top-right'),
        'padding'       => (int) ($override['padding'] ?? 3),
        'keep_original' => '0', // مفيش داعي لنسخة أصلية هنا — العلامة المائية هي اللي بتحتفظ بالأصل
    ]);
}

/**
 * المحرك العام: دمج أي لوجو على أي صورة مع الحفاظ على الشفافية
 */
function wm_overlay(string $relativePath, string $logoRel, array $opts): array
{
    if (!function_exists('imagecreatetruecolor')) {
        return ['ok' => false, 'error' => 'مكتبة GD غير متاحة على السيرفر'];
    }

    $absPath = STORAGE_PATH . '/' . ltrim($relativePath, '/');
    if (!is_file($absPath)) {
        return ['ok' => false, 'error' => 'الصورة غير موجودة'];
    }

    $logoAbs = STORAGE_PATH . '/' . ltrim($logoRel, '/');
    if ($logoRel === '' || !is_file($logoAbs)) {
        return ['ok' => false, 'error' => 'اللوجو غير موجود'];
    }

    $sizePct    = max(3, min(40, (int) ($opts['size'] ?? 12)));
    $opacityPct = max(5, min(100, (int) ($opts['opacity'] ?? 100)));
    $position   = (string) ($opts['position'] ?? 'bottom-right');
    $padPct     = max(0, min(15, (int) ($opts['padding'] ?? 3)));
    $keepOrig   = (string) ($opts['keep_original'] ?? '0') === '1';

    // ── تحميل الصورة الهدف ──
    [$img, $type] = wm_load_image($absPath);
    if (!$img) {
        return ['ok' => false, 'error' => 'صيغة الصورة غير مدعومة'];
    }

    // ── تحميل اللوجو ──
    [$logo] = wm_load_image($logoAbs);
    if (!$logo) {
        imagedestroy($img);
        return ['ok' => false, 'error' => 'تعذر قراءة اللوجو — الأفضل PNG شفاف'];
    }

    $imgW = imagesx($img);
    $imgH = imagesy($img);

    // ── تصغير اللوجو لنسبة من عرض الصورة ──
    $targetW = max(24, (int) round($imgW * $sizePct / 100));
    $ratio = imagesy($logo) / max(1, imagesx($logo));
    $targetH = max(12, (int) round($targetW * $ratio));
    $scaled = imagescale($logo, $targetW, $targetH, IMG_BICUBIC);
    imagedestroy($logo);
    if (!$scaled) {
        imagedestroy($img);
        return ['ok' => false, 'error' => 'فشل تصغير اللوجو'];
    }
    imagealphablending($scaled, false);
    imagesavealpha($scaled, true);

    // ── تخفيف الشفافية مع الحفاظ على قناة alpha الأصلية ──
    // GD alpha: 0 = معتم ... 127 = شفاف تمامًا
    if ($opacityPct < 100) {
        for ($x = 0; $x < $targetW; $x++) {
            for ($y = 0; $y < $targetH; $y++) {
                $rgba = imagecolorat($scaled, $x, $y);
                $a = ($rgba >> 24) & 0x7F;
                $newA = 127 - (int) round((127 - $a) * $opacityPct / 100);
                if ($newA !== $a) {
                    $col = imagecolorallocatealpha($scaled, ($rgba >> 16) & 0xFF, ($rgba >> 8) & 0xFF, $rgba & 0xFF, $newA);
                    imagesetpixel($scaled, $x, $y, $col);
                }
            }
        }
    }

    // ── حساب المكان ──
    $pad = (int) round($imgW * $padPct / 100);
    switch ($position) {
        case 'bottom-left':  $dx = $pad;                        $dy = $imgH - $targetH - $pad; break;
        case 'top-right':    $dx = $imgW - $targetW - $pad;     $dy = $pad;                    break;
        case 'top-left':     $dx = $pad;                        $dy = $pad;                    break;
        case 'bottom-right':
        default:             $dx = $imgW - $targetW - $pad;     $dy = $imgH - $targetH - $pad; break;
    }

    // ── نسخة أصلية قبل التعديل ──
    if ($keepOrig) {
        $origDir = dirname($absPath) . '/originals';
        if (!is_dir($origDir)) {
            @mkdir($origDir, 0755, true);
        }
        $origPath = $origDir . '/' . basename($absPath);
        if (!is_file($origPath)) {
            @copy($absPath, $origPath);
        }
    }

    // ── الدمج ──
    imagealphablending($img, true);
    imagecopy($img, $scaled, $dx, $dy, 0, 0, $targetW, $targetH);
    imagedestroy($scaled);

    // ── الحفظ بنفس الصيغة ──
    $ok = wm_save_image($img, $absPath, $type);
    imagedestroy($img);

    return $ok ? ['ok' => true, 'error' => null] : ['ok' => false, 'error' => 'فشل حفظ الصورة'];
}

/**
 * تحميل صورة حسب نوعها الفعلي → [GdImage|null, type]
 */
function wm_load_image(string $path): array
{
    $info = @getimagesize($path);
    if (!$info) {
        return [null, null];
    }
    $img = match ($info[2]) {
        IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
        IMAGETYPE_PNG  => @imagecreatefrompng($path),
        IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : null,
        IMAGETYPE_GIF  => @imagecreatefromgif($path),
        default        => null,
    };
    if (!$img) {
        return [null, null];
    }
    // truecolor + alpha جاهزة للدمج
    if (!imageistruecolor($img)) {
        imagepalettetotruecolor($img);
    }
    imagealphablending($img, true);
    imagesavealpha($img, true);
    return [$img, $info[2]];
}

/**
 * حفظ بنفس الصيغة
 */
function wm_save_image($img, string $path, int $type): bool
{
    return match ($type) {
        IMAGETYPE_JPEG => imagejpeg($img, $path, 90),
        IMAGETYPE_PNG  => imagepng($img, $path, 6),
        IMAGETYPE_WEBP => function_exists('imagewebp') ? imagewebp($img, $path, 90) : imagepng($img, preg_replace('/\.webp$/i', '.png', $path), 6),
        IMAGETYPE_GIF  => imagegif($img, $path),
        default        => imagepng($img, $path, 6),
    };
}

<?php
declare(strict_types=1);

final class ImageException extends RuntimeException
{
}

/**
 * Upload validation and re-encoding (GD, or Imagick when available).
 * Every image is decoded and written again, which drops EXIF/GPS and any
 * payload hidden in the original file.
 */
final class ImageService
{
    public const MAX_BYTES = 10 * 1024 * 1024;
    public const MAX_SIDE = 1536;
    private const ALLOWED = ['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif'];

    /** Detect the real MIME type from the bytes, never from the file name. */
    public static function mime(string $path): string
    {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        return (string) $finfo->file($path);
    }

    /**
     * Validate an uploaded file and store it as a clean JPEG.
     * @return string file name inside ORIGINALS_PATH
     */
    public static function storeOriginal(array $file): string
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new ImageException(match ($file['error'] ?? 0) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'حجم الصورة أكبر من المسموح',
                UPLOAD_ERR_NO_FILE => 'مفيش صورة اترفعت',
                default => 'الرفع ما كملش، جرّب تاني',
            });
        }
        if (!is_uploaded_file($file['tmp_name']) && PHP_SAPI !== 'cli') {
            throw new ImageException('ملف غير صالح');
        }
        if ($file['size'] > self::MAX_BYTES) {
            throw new ImageException('حجم الصورة أكبر من 10 ميجا');
        }
        $mime = self::mime($file['tmp_name']);
        if (!in_array($mime, self::ALLOWED, true)) {
            throw new ImageException('الملف لازم يكون صورة (JPG, PNG, WEBP, HEIC)');
        }

        $name = bin2hex(random_bytes(16)) . '.jpg';
        $dest = ORIGINALS_PATH . '/' . self::shard($name);
        self::ensureDir(dirname($dest));

        if (class_exists(Imagick::class)) {
            self::reencodeImagick($file['tmp_name'], $dest);
        } else {
            if (in_array($mime, ['image/heic', 'image/heif'], true)) {
                throw new ImageException('صيغة HEIC مش مدعومة على السيرفر — صوّر الصورة تاني أو ابعتها JPG');
            }
            self::reencodeGd($file['tmp_name'], $mime, $dest);
        }
        return self::shard($name);
    }

    /** Store a generated image (raw bytes from Gemini). Returns the relative path. */
    public static function storeResult(string $bytes): string
    {
        $img = @imagecreatefromstring($bytes);
        if (!$img) {
            throw new ImageException('Result image could not be decoded');
        }
        $webp = function_exists('imagewebp');
        $name = bin2hex(random_bytes(16)) . ($webp ? '.webp' : '.jpg');
        $rel = self::shard($name);
        $dest = RESULTS_PATH . '/' . $rel;
        self::ensureDir(dirname($dest));
        $img = self::fitWithin($img, 2048);
        $ok = $webp ? imagewebp($img, $dest, 86) : imagejpeg($img, $dest, 88);
        imagedestroy($img);
        if (!$ok) {
            throw new ImageException('Could not write result image');
        }
        @chmod($dest, 0640);
        return $rel;
    }

    /**
     * 1200×630 share card: result photo on the left, logo + title on the right.
     * Arabic text is a pre-rendered PNG (GD cannot shape Arabic).
     */
    public static function shareCard(string $resultAbs, string $destAbs): bool
    {
        $src = @imagecreatefromstring((string) @file_get_contents($resultAbs));
        if (!$src) {
            return false;
        }
        $W = 1200;
        $H = 630;
        $card = imagecreatetruecolor($W, $H);
        // soft sky gradient
        for ($y = 0; $y < $H; $y++) {
            $t = $y / $H;
            $c = imagecolorallocate($card, (int) (245 - 13 * $t), (int) (250 - 7 * $t), (int) (254 - 3 * $t));
            imageline($card, 0, $y, $W, $y, $c);
        }
        // decorative circle
        imagefilledellipse($card, 1080, 560, 420, 420, imagecolorallocate($card, 232, 243, 251));

        // photo (cover-crop) with white frame
        $pw = 470;
        $ph = 570;
        $px = 40;
        $py = 30;
        imagefilledrectangle($card, $px - 8, $py - 8, $px + $pw + 7, $py + $ph + 7, imagecolorallocate($card, 255, 255, 255));
        [$sw, $sh] = [imagesx($src), imagesy($src)];
        $scale = max($pw / $sw, $ph / $sh);
        $cw = (int) round($pw / $scale);
        $ch = (int) round($ph / $scale);
        imagecopyresampled($card, $src, $px, $py, (int) (($sw - $cw) / 2), (int) max(0, ($sh - $ch) / 4), $pw, $ph, $cw, $ch);
        imagedestroy($src);

        $logo = self::loadPng(PUBLIC_PATH . '/' . ltrim((string) Settings::get('logo', 'assets/img/logo.png'), '/'));
        if ($logo) {
            self::placeFit($card, $logo, 600, 70, 540, 170, 'right');
        }
        $title = self::loadPng(PUBLIC_PATH . '/assets/img/share-title.png');
        if ($title) {
            self::placeFit($card, $title, 580, 300, 560, 260, 'right');
        }

        self::ensureDir(dirname($destAbs));
        $ok = imagejpeg($card, $destAbs, 86);
        imagedestroy($card);
        return $ok;
    }

    /* ---------------- internals ---------------- */

    private static function reencodeGd(string $path, string $mime, string $dest): void
    {
        $img = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png' => @imagecreatefrompng($path),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
            default => false,
        };
        if (!$img) {
            throw new ImageException('مقدرناش نقرا الصورة دي — جرّب صورة تانية');
        }
        if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
            $exif = @exif_read_data($path);
            $img = self::orient($img, (int) ($exif['Orientation'] ?? 1));
        }
        $img = self::fitWithin($img, self::MAX_SIDE);
        // flatten transparency on white
        $w = imagesx($img);
        $h = imagesy($img);
        $flat = imagecreatetruecolor($w, $h);
        imagefill($flat, 0, 0, imagecolorallocate($flat, 255, 255, 255));
        imagecopy($flat, $img, 0, 0, 0, 0, $w, $h);
        imagedestroy($img);
        if (!imagejpeg($flat, $dest, 88)) {
            throw new ImageException('Could not save the image');
        }
        imagedestroy($flat);
        @chmod($dest, 0640);
    }

    private static function reencodeImagick(string $path, string $dest): void
    {
        try {
            $im = new Imagick($path);
            $im->setIteratorIndex(0);
            if (method_exists($im, 'autoOrient')) {
                $im->autoOrient();
            }
            $im->setImageBackgroundColor('white');
            $im = $im->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
            $w = $im->getImageWidth();
            $h = $im->getImageHeight();
            if (max($w, $h) > self::MAX_SIDE) {
                $im->resizeImage($w >= $h ? self::MAX_SIDE : 0, $h > $w ? self::MAX_SIDE : 0, Imagick::FILTER_LANCZOS, 1);
            }
            $im->stripImage();
            $im->setImageFormat('jpeg');
            $im->setImageCompressionQuality(88);
            $im->writeImage($dest);
            $im->clear();
            @chmod($dest, 0640);
        } catch (Throwable $e) {
            throw new ImageException('مقدرناش نقرا الصورة دي — جرّب صورة تانية');
        }
    }

    private static function orient(GdImage $img, int $o): GdImage
    {
        $r = match ($o) {
            3 => imagerotate($img, 180, 0),
            6 => imagerotate($img, -90, 0),
            8 => imagerotate($img, 90, 0),
            default => $img,
        };
        return $r ?: $img;
    }

    private static function fitWithin(GdImage $img, int $max): GdImage
    {
        $w = imagesx($img);
        $h = imagesy($img);
        if (max($w, $h) <= $max) {
            return $img;
        }
        $s = $max / max($w, $h);
        $nw = (int) round($w * $s);
        $nh = (int) round($h * $s);
        $out = imagecreatetruecolor($nw, $nh);
        imagealphablending($out, false);
        imagesavealpha($out, true);
        imagecopyresampled($out, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($img);
        return $out;
    }

    private static function loadPng(string $path): ?GdImage
    {
        if (!is_file($path)) {
            return null;
        }
        $img = @imagecreatefromstring((string) file_get_contents($path));
        if (!$img) {
            return null;
        }
        imagealphablending($img, true);
        return $img;
    }

    /** Scale $img into the box and copy it with alpha, aligned right. */
    private static function placeFit(GdImage $dst, GdImage $img, int $x, int $y, int $bw, int $bh, string $align): void
    {
        $w = imagesx($img);
        $h = imagesy($img);
        $s = min($bw / $w, $bh / $h, 1);
        $nw = (int) round($w * $s);
        $nh = (int) round($h * $s);
        $dx = $align === 'right' ? $x + $bw - $nw : $x;
        imagealphablending($dst, true);
        imagecopyresampled($dst, $img, $dx, $y, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($img);
    }

    /** Spread files into 2-char subfolders so no folder grows too big. */
    private static function shard(string $name): string
    {
        return substr($name, 0, 2) . '/' . $name;
    }

    private static function ensureDir(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new ImageException('Storage folder is not writable');
        }
    }

    public static function deleteFile(string $base, ?string $rel): void
    {
        if (!$rel || str_contains($rel, '..')) {
            return;
        }
        $path = $base . '/' . $rel;
        if (is_file($path)) {
            @unlink($path);
        }
    }

    public static function ogPath(string $resultRel): string
    {
        return RESULTS_PATH . '/' . preg_replace('/\.(webp|jpg)$/', '_og.jpg', $resultRel);
    }
}

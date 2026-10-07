<?php
/**
 * Spread AI — File Uploader
 */

require_once __DIR__ . '/config.php';

/**
 * Upload an image file
 * Returns ['ok' => bool, 'path' => string|null, 'error' => string|null]
 * The returned path is relative to storage/ (e.g. uploads/brand-images/abc.png)
 */
function upload_image(array $file, string $subfolder = 'brand-images'): array
{
    if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'فشل رفع الملف', 'path' => null];
    }
    if ($file['size'] > MAX_UPLOAD_SIZE) {
        return ['ok' => false, 'error' => 'حجم الملف كبير (الحد الأقصى 5 ميجا)', 'path' => null];
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);

    if (!in_array($mime, ALLOWED_IMAGE_TYPES, true)) {
        return ['ok' => false, 'error' => 'نوع الملف غير مسموح', 'path' => null];
    }

    $ext = match ($mime) {
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/svg+xml' => 'svg',
        default => 'bin'
    };

    $folder = UPLOADS_PATH . '/' . trim($subfolder, '/');
    if (!is_dir($folder)) {
        mkdir($folder, 0755, true);
    }

    $filename = bin2hex(random_bytes(12)) . '.' . $ext;
    $dest = $folder . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        return ['ok' => false, 'error' => 'تعذر حفظ الملف', 'path' => null];
    }

    return [
        'ok' => true,
        'path' => 'uploads/' . trim($subfolder, '/') . '/' . $filename,
        'error' => null
    ];
}

/**
 * Delete uploaded file
 */
function delete_upload(string $relativePath): bool
{
    if (empty($relativePath)) return false;
    $abs = STORAGE_PATH . '/' . ltrim($relativePath, '/');
    if (file_exists($abs) && is_file($abs)) {
        return @unlink($abs);
    }
    return false;
}

/**
 * Get full URL for an uploaded file
 */
function upload_url(?string $relativePath): ?string
{
    if (empty($relativePath)) return null;
    return APP_URL . '/storage/' . ltrim($relativePath, '/');
}

/**
 * تحويل صورة محلية لـ data URI — لإرسالها للموديل مباشرة
 */
function image_data_uri(string $relativePath): ?string
{
    $abs = STORAGE_PATH . '/' . ltrim($relativePath, '/');
    if (!is_file($abs) || filesize($abs) > 8 * 1024 * 1024) {
        return null;
    }
    $info = @getimagesize($abs);
    if (!$info || strpos((string) $info['mime'], 'image/') !== 0) {
        return null;
    }
    return 'data:' . $info['mime'] . ';base64,' . base64_encode((string) file_get_contents($abs));
}


/**
 * تحويل مرجع ستايل (مسار محلي أو "url:https://...") لـ data URI جاهز للموديل
 */
function style_ref_data_uri(?string $ref): ?string
{
    if (!$ref) {
        return null;
    }
    if (strpos($ref, 'url:') === 0) {
        return remote_image_data_uri(substr($ref, 4));
    }
    return image_data_uri($ref);
}

/**
 * تحميل صورة من لينك خارجي وتحويلها data URI (بحد أقصى 8 ميجا)
 */
function remote_image_data_uri(string $url): ?string
{
    if (!preg_match('#^https?://#i', $url) || !filter_var($url, FILTER_VALIDATE_URL)) {
        return null;
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_USERAGENT => 'SpreadAI/2.0',
        CURLOPT_MAXFILESIZE => 8 * 1024 * 1024,
    ]);
    $body = curl_exec($ch);
    $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false || $code < 200 || $code >= 300 || strlen($body) > 8 * 1024 * 1024) {
        return null;
    }
    $type = trim(explode(';', $type)[0]);
    if (strpos($type, 'image/') !== 0) {
        $info = @getimagesizefromstring($body);
        if (!$info || strpos((string) $info['mime'], 'image/') !== 0) {
            return null;
        }
        $type = $info['mime'];
    }
    return 'data:' . $type . ';base64,' . base64_encode($body);
}

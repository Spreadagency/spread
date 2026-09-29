<?php
/** دوال الموقع المشتركة */
require_once __DIR__ . '/db.php';

// محمية ضد التعارض مع دالة المنصة لو الملفين اتحمّلوا مع بعض
if (!function_exists('e')) {
    function e($v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
}

function s_url(string $path = ''): string
{
    return SITE_BASE . '/' . ltrim($path, '/');
}

/* ─── الإعدادات ─── */
function s_setting(string $key, string $default = ''): string
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (s_all('SELECT setting_key, setting_value FROM site_settings') as $r) {
            $cache[$r['setting_key']] = (string) $r['setting_value'];
        }
    }
    $v = $cache[$key] ?? '';
    return $v !== '' ? $v : $default;
}

function s_set(string $key, string $value): void
{
    s_run('INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?)
           ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)', [$key, $value]);
}

/* ─── الأقسام ─── */
function s_section(string $key): array
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (s_all('SELECT * FROM site_sections') as $r) {
            $cache[$r['section_key']] = $r;
        }
    }
    return $cache[$key] ?? ['is_visible' => 1, 'title' => '', 'subtitle' => '', 'label_ar' => $key];
}

function s_visible(string $key): bool
{
    return (int) (s_section($key)['is_visible'] ?? 1) === 1;
}

/* ─── الصور: رفع أو لينك ─── */
function s_img(array $row, string $pathCol = 'image_path', string $urlCol = 'image_url'): string
{
    if (!empty($row[$urlCol])) {
        return (string) $row[$urlCol];
    }
    if (!empty($row[$pathCol])) {
        $p = (string) $row[$pathCol];
        // مسار من تخزين المنصة (التصميمات المستوردة)
        if (str_starts_with($p, 'uploads/') || str_starts_with($p, 'storage/')) {
            return PLATFORM_STORAGE_URL . '/' . ltrim(str_replace('storage/', '', $p), '/');
        }
        return SITE_UPLOAD_URL . '/' . ltrim($p, '/');
    }
    return '';
}

/* ─── رفع صورة ─── */
function s_upload(array $file, string $prefix = 'img'): array
{
    if (empty($file['name']) || ($file['error'] ?? 1) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'مفيش ملف'];
    }
    if ($file['size'] > 6 * 1024 * 1024) {
        return ['ok' => false, 'error' => 'الحجم أكبر من 6 ميجا'];
    }
    $info = @getimagesize($file['tmp_name']);
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif', 'image/svg+xml' => 'svg'];
    $mime = $info['mime'] ?? mime_content_type($file['tmp_name']);
    if (!isset($allowed[$mime])) {
        return ['ok' => false, 'error' => 'نوع الملف غير مدعوم'];
    }
    if (!is_dir(SITE_UPLOAD_DIR)) {
        @mkdir(SITE_UPLOAD_DIR, 0755, true);
    }
    $name = $prefix . '-' . bin2hex(random_bytes(8)) . '.' . $allowed[$mime];
    if (!move_uploaded_file($file['tmp_name'], SITE_UPLOAD_DIR . '/' . $name)) {
        return ['ok' => false, 'error' => 'فشل الحفظ — تأكد إن مجلد site-assets/uploads قابل للكتابة'];
    }
    return ['ok' => true, 'path' => $name];
}

function s_delete_upload(?string $path): void
{
    if (!$path) return;
    $f = SITE_UPLOAD_DIR . '/' . basename($path);
    if (is_file($f)) @unlink($f);
}

/* ─── CSRF ─── */
function s_csrf(): string
{
    if (empty($_SESSION['site_csrf'])) {
        $_SESSION['site_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['site_csrf'];
}

function s_csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(s_csrf()) . '">';
}

function s_check_csrf(): void
{
    if (!hash_equals($_SESSION['site_csrf'] ?? '', (string) ($_POST['csrf'] ?? ''))) {
        http_response_code(403);
        exit('انتهت الجلسة — حدّث الصفحة وحاول تاني.');
    }
}

/* ─── فلاش ─── */
function s_flash(string $type, string $msg): void { $_SESSION['site_flash'] = ['t' => $type, 'm' => $msg]; }

function s_flash_render(): string
{
    if (empty($_SESSION['site_flash'])) return '';
    $f = $_SESSION['site_flash'];
    unset($_SESSION['site_flash']);
    $colors = ['success' => '#0f7a5f', 'danger' => '#c0392b', 'warning' => '#a06c1e'];
    $bg = ['success' => '#e8f7f1', 'danger' => '#fdecea', 'warning' => '#fdf6e3'];
    return '<div style="background:' . ($bg[$f['t']] ?? '#eee') . ';color:' . ($colors[$f['t']] ?? '#333')
        . ';padding:12px 16px;border-radius:12px;margin-bottom:18px;font-weight:600">' . e($f['m']) . '</div>';
}

function s_redirect(string $path): void { header('Location: ' . s_url($path)); exit; }

/* ─── صفحات القائمة ─── */
function s_menu_pages(): array
{
    return s_all('SELECT slug, title FROM site_pages WHERE is_active = 1 AND show_in_menu = 1 ORDER BY sort_order, id');
}

function s_page_url(string $slug): string
{
    return s_url('site/page.php?p=' . urlencode($slug));
}

/** تحويل نص متعدد الأسطر لمصفوفة */
function s_lines(?string $text): array
{
    if (!$text) return [];
    return array_values(array_filter(array_map('trim', preg_split('/[\r\n]+/', $text))));
}

/** رابط فيديو → embed */
function s_video_embed(string $url): string
{
    if (preg_match('~youtu\.be/([\w-]+)~', $url, $m) || preg_match('~youtube\.com/watch\?v=([\w-]+)~', $url, $m)) {
        return 'https://www.youtube.com/embed/' . $m[1];
    }
    if (preg_match('~youtube\.com/embed/([\w-]+)~', $url)) return $url;
    if (preg_match('~vimeo\.com/(\d+)~', $url, $m)) return 'https://player.vimeo.com/video/' . $m[1];
    return $url;
}


/**
 * فك تشفير الحقول اللي اتبعتت base64 لتفادي حجب mod_security (403)
 * لازم تتنادى في أول أي معالجة POST
 */
function s_decode_b64(): void
{
    if (empty($_POST['_b64'])) {
        return;
    }
    $names = is_array($_POST['_b64']) ? $_POST['_b64'] : explode(',', (string) $_POST['_b64']);

    foreach ($names as $name) {
        $name = trim((string) $name);
        if ($name === '') {
            continue;
        }

        // اسم الحقل ممكن يكون متداخل: sec[5][title]
        if (preg_match_all('/\[([^\]]*)\]/', $name, $m)) {
            $root = substr($name, 0, strpos($name, '['));
            $path = array_merge([$root], $m[1]);
        } else {
            $path = [$name];
        }

        // امشِ على المسار بالمرجع
        $ref = &$_POST;
        $ok = true;
        foreach ($path as $key) {
            if (!is_array($ref) || !array_key_exists($key, $ref)) {
                $ok = false;
                break;
            }
            $ref = &$ref[$key];
        }
        if ($ok && is_string($ref)) {
            $decoded = base64_decode(strtr($ref, '-_', '+/'), true);
            if ($decoded !== false && mb_check_encoding($decoded, 'UTF-8')) {
                $ref = $decoded;
            }
        }
        unset($ref);
    }
}

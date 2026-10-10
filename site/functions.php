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
    // SVG ممكن يشيل سكريبت — بنرفض أي SVG فيه كود أو أحداث أو روابط javascript
    if ($mime === 'image/svg+xml') {
        $svg = (string) @file_get_contents($file['tmp_name']);
        if (preg_match('~<\s*(script|foreignObject|iframe|embed|object|handler|use[^>]+href\s*=\s*["\']?\s*(?!#))|\bon[a-z]+\s*=|javascript\s*:|data\s*:\s*text/html|<!ENTITY~i', $svg)) {
            return ['ok' => false, 'error' => 'ملف SVG فيه كود — ارفع نسخة PNG/WebP أو SVG من غير سكريبتات'];
        }
    }
    if (!is_dir(SITE_UPLOAD_DIR)) {
        @mkdir(SITE_UPLOAD_DIR, 0755, true);
    }
    $name = $prefix . '-' . bin2hex(random_bytes(8)) . '.' . $allowed[$mime];
    if (!move_uploaded_file($file['tmp_name'], SITE_UPLOAD_DIR . '/' . $name)) {
        return ['ok' => false, 'error' => 'فشل الحفظ — تأكد إن مجلد site-assets/uploads قابل للكتابة'];
    }
    // مكتبة الوسائط: كل ملف بيترفع من أي مكان في اللوحة بيتسجّل فيها
    s_run('INSERT IGNORE INTO site_media (path, original_name, mime, size, width, height, admin_id) VALUES (?,?,?,?,?,?,?)', [
        $name, mb_substr((string) $file['name'], 0, 255), $mime, (int) $file['size'],
        isset($info[0]) ? (int) $info[0] : null, isset($info[1]) ? (int) $info[1] : null,
        isset($_SESSION['site_admin_id']) ? (int) $_SESSION['site_admin_id'] : null,
    ]);
    return ['ok' => true, 'path' => $name];
}

function s_delete_upload(?string $path): void
{
    if (!$path) return;
    // الملف ممكن يكون مستخدم في مكان تاني من مكتبة الوسائط — نسيبه في المكتبة ونمسح بس لو مش متسجّل فيها
    if (s_one('SELECT id FROM site_media WHERE path = ?', [basename($path)])) return;
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
    if (!function_exists('s_col_exists') || s_col_exists('site_pages', 'status')) {
        return s_all("SELECT slug, title FROM site_pages WHERE is_active = 1 AND show_in_menu = 1 AND status = 'published' ORDER BY sort_order, id");
    }
    // قبل الترقية (عمود status لسه مش موجود)
    return s_all('SELECT slug, title FROM site_pages WHERE is_active = 1 AND show_in_menu = 1 ORDER BY sort_order, id');
}

/**
 * رابط الصفحة: /slug (روابط نظيفة — قاعدة .htaccess) أو site/page.php?p=slug
 * الرابط القديم بيفضل شغال دايمًا.
 */
function s_page_url(string $slug, bool $absolute = false): string
{
    $path = s_setting('pretty_urls', '1') === '1' && !s_slug_conflicts($slug) ? '/' . rawurlencode($slug) : '/site/page.php?p=' . urlencode($slug);
    $u = SITE_BASE . $path;
    return $absolute ? rtrim(SITE_URL, '/') . $path : $u;
}

/** رابط آمن للعرض (http/https/مسار/# · وإلا #) */
function s_link(string $u): string
{
    $u = trim($u);
    if ($u === '') return '';
    if (preg_match('~^(https?://|/|#|mailto:|tel:)~i', $u)) return $u;
    if (preg_match('~^[a-z][a-z0-9+.\-]*:~i', $u)) return '#';
    return s_url($u);
}

/**
 * الـ slug ده بيتعارض مع فولدر/ملف حقيقي في الجذر أو صفحة في المنصة؟
 * (الرابط النظيف /slug هيروح للفولدر أو المنصة بدل الصفحة — زي فولدر services/)
 */
function s_slug_conflicts(string $slug): bool
{
    $root = dirname(__DIR__);
    return in_array($slug, s_reserved_slugs(), true) || file_exists($root . '/' . $slug) || file_exists($root . '/public/' . $slug)
        || is_file($root . '/public/' . $slug . '.php');
}

/** أسماء محجوزة مينفعش تبقى slug (مجلدات النظام وصفحات المنصة) */
function s_reserved_slugs(): array
{
    return ['admin', 'site-admin', 'assets', 'storage', 'site', 'site-assets', 'includes', 'sql', 'cron', 'public', 'services',
            'templates', 'ajax', 'api', 'auth', 'social', 'webhooks', 'index', 'login', 'register', 'logout', 'dashboard', 'checkout', 'packages'];
}

/** الأدمن الحالي للوحة الموقع (للأزرار اللي بتظهر للأدمن بس في الموقع) — null للزوار */
function s_current_admin(): ?array
{
    static $a = false;
    if ($a !== false) return $a;
    $a = null;
    if (!empty($_SESSION['site_admin_id'])) {
        $a = s_one('SELECT * FROM site_admins WHERE id = ? AND status = "active"', [(int) $_SESSION['site_admin_id']]);
    }
    return $a;
}

/** الأدمن الحالي (لو فيه) عنده صلاحية القسم ده؟ — نفس منطق site-admin/auth.php (sa_can) */
function s_admin_can(string $perm): bool
{
    $a = s_current_admin();
    if (!$a) return false;
    if (!function_exists('sa_can')) require_once dirname(__DIR__) . '/site-admin/auth.php';
    return sa_can($perm, $a);
}

/** عدّاد زيارات خفيف (يوم × صفحة) — من غير كوكيز ولا بيانات شخصية، والبوتات مابتتعدّش */
function s_track_view(string $path): void
{
    $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    if ($ua === '' || preg_match('~bot|crawl|spider|slurp|preview|lighthouse|headless|curl|wget|python|monitor~i', $ua)) return;
    if (s_setting('track_views', '1') !== '1') return;
    if (s_current_admin()) return; // زيارات الأدمن مابتتحسبش
    s_run('INSERT INTO site_pageviews (day, path, views) VALUES (CURDATE(), ?, 1) ON DUPLICATE KEY UPDATE views = views + 1', [mb_substr($path, 0, 190)]);
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

/* ─── قاعدة بيانات المنصة (قراءة بس) ───
 * الموقع ليه قاعدة منفصلة، بس بعض الأقسام بتقرا من المنصة مباشرة:
 * الباقات (نظام الدفع الجديد) · استيراد التصميمات في لوحة الموقع.
 * بنقرا بيانات الاتصال من includes/config.php من غير ما نحمّل كود المنصة (عشان مايحصلش تعارض دوال).
 */
function s_platform_pdo(): ?PDO
{
    static $p = null;
    static $tried = false;
    if ($tried) return $p;
    $tried = true;
    $cfg = dirname(__DIR__) . '/includes/config.php';
    if (!is_file($cfg)) return null;
    $src = (string) file_get_contents($cfg);
    $get = function (string $c) use ($src) {
        return preg_match("/define\(\s*'" . $c . "'\s*,\s*'([^']*)'/", $src, $m) ? $m[1] : null;
    };
    $h = $get('DB_HOST'); $n = $get('DB_NAME'); $u = $get('DB_USER'); $w = $get('DB_PASS');
    if (!$n || !$u) return null;
    try {
        $p = new PDO('mysql:host=' . ($h ?: 'localhost') . ";dbname={$n};charset=utf8mb4", $u, (string) $w, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } catch (\Throwable $e) {
        $p = null;
    }
    return $p;
}

/** استعلام قراءة من قاعدة المنصة — بيرجع [] لو مش متاحة */
function s_platform_all(string $sql, array $params = []): array
{
    $pdo = s_platform_pdo();
    if (!$pdo) return [];
    try { $st = $pdo->prepare($sql); $st->execute($params); return $st->fetchAll(); }
    catch (\Throwable $e) { return []; }
}

/** إعداد من جدول إعدادات المنصة (آخر قيمة) */
function s_platform_setting(string $key, string $default = ''): string
{
    $r = s_platform_all('SELECT setting_value FROM settings WHERE setting_key = ? ORDER BY id DESC LIMIT 1', [$key]);
    $v = $r ? (string) $r[0]['setting_value'] : '';
    return $v !== '' ? $v : $default;
}

/* ─── الهوية البصرية (Design System من لوحة الموقع) ─── */
function s_design_defaults(): array
{
    return ['ds_primary' => '#0A6FD8', 'ds_secondary' => '#2EE3CC', 'ds_accent' => '#9C8CFF', 'ds_background' => '#F4F7FC', 'ds_ink' => '#0B1526',
            'ds_font' => 'Readex Pro', 'ds_buttons' => 'gradient', 'ds_shadow' => 'soft', 'ds_radius' => '18'];
}

/** القيم الفعلية (المحفوظة أو الافتراضية) */
function s_design(): array
{
    $out = [];
    foreach (s_design_defaults() as $k => $d) $out[$k] = s_setting($k, $d);
    return $out;
}

/** CSS بيغيّر شكل الموقع حسب الهوية — فاضي لو كله على الافتراضي (الشكل الأصلي زي ما هو) */
function s_design_css(): string
{
    $D = s_design_defaults();
    $c = s_design();
    if ($c == $D) return '';
    $hex = fn($v, $d) => preg_match('/^#[0-9a-fA-F]{6}$/', (string) $v) ? $v : $d;
    $pri = $hex($c['ds_primary'], $D['ds_primary']); $sec = $hex($c['ds_secondary'], $D['ds_secondary']); $acc = $hex($c['ds_accent'], $D['ds_accent']);
    $bg = $hex($c['ds_background'], $D['ds_background']); $ink = $hex($c['ds_ink'], $D['ds_ink']);
    $r = max(4, min(36, (int) $c['ds_radius']));
    $grad = $c['ds_buttons'] === 'solid' ? $pri : "linear-gradient(100deg,{$sec} -40%,{$pri} 70%)";
    $font = in_array($c['ds_font'], ['Readex Pro', 'IBM Plex Sans Arabic'], true) ? $c['ds_font'] : 'Readex Pro';
    $css = ":root{--blue:{$pri};--blue-d:{$pri};--teal:{$sec};--violet:{$acc};--bg:{$bg};--ink:{$ink};--grad-ai:linear-gradient(90deg,{$sec},{$pri});--ds-r:{$r}px}"
        . "body.hv2{background:{$bg};color:{$ink}}"
        . ".ws-pri{background:{$grad}!important}.ws-btn{border-radius:{$r}px}"
        . ".ws-card,.pr-card,.s-card,.o-card,.pg-card,.t-card,.faq-i,.cta-box{border-radius:" . ($r + 10) . "px}"
        . ".hv2 h1,.hv2 h2,.hv2 h3,.ws-h2{font-family:\"{$font}\",sans-serif}";
    if ($c['ds_shadow'] === 'none') $css .= '.ws-card,.pr-card,.s-card,.o-card,.pg-card,.t-card,.ws-pri{box-shadow:none!important}';
    if ($c['ds_shadow'] === 'strong') $css .= '.ws-card,.pr-card,.s-card,.pg-card,.t-card{box-shadow:0 24px 54px rgba(12,40,90,.18)!important}';
    return '<style id="ds-css">' . $css . '</style>';
}

/* ─── شاشة الاشتراك عند مرحلة التصميم (Create Post) ─── */
function s_paywall_defaults(): array
{
    return [
        'paywall_title'    => 'اشترك لصناعة التصميم والنشر',
        'paywall_body'     => 'منشورك جاهز! تحويله لتصميم ونشره تلقائيًا متاحين ضمن الاشتراك — اختار الباقة اللي تناسبك وكمّل من نفس المكان.',
        'paywall_benefits' => "تصميمات جاهزة للنشر\nمقاسات مناسبة لكل منصة\nتطبيق هوية البراند\nجدولة المحتوى\nالنشر من مكان واحد",
        'paywall_cta'      => 'شوف الباقات',
        'paywall_cta_url'  => '',
    ];
}

function s_paywall(): array
{
    $out = [];
    foreach (s_paywall_defaults() as $k => $d) $out[$k] = s_setting($k, $d);
    $out['benefits'] = s_lines($out['paywall_benefits']);
    return $out;
}

/* ─── ترقية Website OS (مرة واحدة · آمنة) ─── */
require_once __DIR__ . '/upgrade.php';
s_site_os_upgrade();

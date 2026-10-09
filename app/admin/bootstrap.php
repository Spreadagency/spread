<?php
declare(strict_types=1);

/** Loaded by every page in public/admin. */
require dirname(__DIR__) . '/bootstrap.php';
require APP_PATH . '/services/AdminAuth.php';
require __DIR__ . '/ui.php';

/*
 * WAF-safe posts: admin.js sends every text field packed in one base64url field "__p",
 * because hosting firewalls (ModSecurity / Imunify360) answer 403 to POSTs that contain
 * links, code snippets or {placeholders}. Unpack it before anything reads $_POST.
 */
if (isset($_POST['__p']) && is_string($_POST['__p'])) {
    $packed = base64_decode(strtr($_POST['__p'], '-_', '+/'), true);
    unset($_POST['__p']);
    if ($packed !== false) {
        parse_str($packed, $fields);
        $_POST = array_replace($_POST, $fields);
    }
    unset($packed, $fields);
}

header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com data:; img-src 'self' data: blob:; connect-src 'self'; frame-src 'self'; frame-ancestors 'self'; base-uri 'self'; form-action 'self'");
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');

if (!AdminAuth::ipAllowed()) {
    http_response_code(403);
    exit('Forbidden');
}

/* ---------------- request guards ---------------- */

/** Page guard. Returns the logged-in admin. */
function admin_require(string $perm = 'view'): array
{
    $user = AdminAuth::user();
    if (!$user) {
        if (is_ajax()) {
            json_response(['error' => 'انتهت الجلسة، سجّل دخول تاني.'], 401);
        }
        redirect('login.php?next=' . rawurlencode($_SERVER['REQUEST_URI'] ?? ''));
    }
    if ($user['must_change_password'] && basename($_SERVER['SCRIPT_NAME']) !== 'account.php') {
        flash('info', 'لازم تغيّر الباسورد الأول قبل ما تكمل.');
        redirect('account.php');
    }
    if (!AdminAuth::can($perm)) {
        if (is_ajax()) {
            json_response(['error' => 'مش مسموحلك تعمل ده.'], 403);
        }
        http_response_code(403);
        admin_page_start('مش مسموح', '');
        echo '<div class="card">' . empty_block('shield', 'الصفحة دي مش متاحة لصلاحيتك', 'كلّم صاحب الحساب (Owner) لو محتاج تدخلها.') . '</div>';
        admin_page_end();
        exit;
    }
    return $user;
}

/** POST guard: method, CSRF and permission. */
function admin_post(string $perm = 'edit'): array
{
    $user = admin_require('view');
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        redirect(back_url());
    }
    $token = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!csrf_valid($token)) {
        if (is_ajax()) {
            json_response(['error' => 'انتهت صلاحية الصفحة، اعمل Refresh.'], 419);
        }
        flash('err', 'انتهت صلاحية الصفحة، جرّب تاني.');
        redirect(back_url());
    }
    if (!AdminAuth::can($perm)) {
        if (is_ajax()) {
            json_response(['error' => 'صلاحيتك مش بتسمح بالتعديل.'], 403);
        }
        flash('err', 'صلاحيتك مش بتسمح بالتعديل.');
        redirect(back_url());
    }
    return $user;
}

function is_ajax(): bool
{
    return ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch' || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
}

function redirect(string $to): never
{
    header('Location: ' . $to, true, 303);
    exit;
}

/** Same-site referer path, or the given fallback. */
function back_url(string $fallback = 'index.php'): string
{
    $ref = $_SERVER['HTTP_REFERER'] ?? '';
    $base = url('admin/');
    return str_starts_with($ref, $base) ? $ref : $fallback;
}

function flash(string $type, string $msg): void
{
    $_SESSION['flash'][] = [$type, $msg];
}

function take_flashes(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

/* ---------------- settings forms ---------------- */

/**
 * Save posted settings.
 * @param array $text   keys saved as trimmed text
 * @param array $bools  checkbox keys saved as 1/0
 * @param array $secret keys left untouched when the field is empty (masked)
 */
function save_settings_from_post(array $text, array $bools = [], array $secret = [], array $ints = []): array
{
    $changed = [];
    foreach ($text as $key) {
        if (!array_key_exists($key, $_POST)) {
            continue;
        }
        $v = trim((string) $_POST[$key]);
        if ((string) (Settings::all()[$key] ?? '') !== $v) {
            Settings::set($key, $v);
            $changed[] = $key;
        }
    }
    foreach ($ints as $key => [$min, $max]) {
        if (!isset($_POST[$key])) {
            continue;
        }
        $v = (string) max($min, min($max, (int) $_POST[$key]));
        if ((string) Settings::get($key) !== $v) {
            Settings::set($key, $v);
            $changed[] = $key;
        }
    }
    foreach ($bools as $key) {
        $v = !empty($_POST[$key]) ? '1' : '0';
        if ((string) (Settings::all()[$key] ?? '') !== $v) {
            Settings::set($key, $v);
            $changed[] = $key;
        }
    }
    foreach ($secret as $key) {
        $v = trim((string) ($_POST[$key] ?? ''));
        if (!empty($_POST[$key . '__clear'])) {
            Settings::set($key, '');
            $changed[] = $key;
        } elseif ($v !== '') {
            Settings::set($key, $v);
            $changed[] = $key;
        }
    }
    Settings::flush();
    return $changed;
}

/** Store an uploaded brand image (logo, photo, OG, favicon) under public/uploads/site. Returns the public path. */
function store_site_image(string $field, int $maxSide = 2400): ?string
{
    $f = $_FILES[$field] ?? null;
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($f['error'] !== UPLOAD_ERR_OK || $f['size'] > 5 * 1024 * 1024) {
        throw new RuntimeException('الصورة لازم تكون أقل من 5 ميجا.');
    }
    $mime = ImageService::mime($f['tmp_name']);
    if (!in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true)) {
        throw new RuntimeException('الصورة لازم تكون PNG أو JPG أو WEBP.');
    }
    $img = @imagecreatefromstring((string) file_get_contents($f['tmp_name']));
    if (!$img) {
        throw new RuntimeException('مقدرناش نقرا الصورة.');
    }
    $w = imagesx($img);
    $h = imagesy($img);
    if (max($w, $h) > $maxSide) {
        $s = $maxSide / max($w, $h);
        $out = imagecreatetruecolor((int) ($w * $s), (int) ($h * $s));
        imagealphablending($out, false);
        imagesavealpha($out, true);
        imagecopyresampled($out, $img, 0, 0, 0, 0, (int) ($w * $s), (int) ($h * $s), $w, $h);
        $img = $out;
    }
    imagesavealpha($img, true);
    $dir = PUBLIC_PATH . '/uploads/site';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $keepAlpha = $mime === 'image/png';
    $name = $field . '-' . bin2hex(random_bytes(6)) . ($keepAlpha ? '.png' : '.jpg');
    $ok = $keepAlpha ? imagepng($img, $dir . '/' . $name, 7) : imagejpeg($img, $dir . '/' . $name, 88);
    if (!$ok) {
        throw new RuntimeException('مقدرناش نحفظ الصورة (صلاحيات الفولدر).');
    }
    return 'uploads/site/' . $name;
}

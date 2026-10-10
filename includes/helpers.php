<?php
/**
 * Spread AI — Helpers & Utility Functions
 */

require_once __DIR__ . '/config.php';

/**
 * Escape HTML output
 */
function e(?string $str): string
{
    return htmlspecialchars($str ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/**
 * Build URL relative to APP_URL
 */
function url(string $path = ''): string
{
    return APP_URL . '/' . ltrim($path, '/');
}

/**
 * Redirect to URL
 */
function redirect(string $path): void
{
    $isAbsolute = (strpos($path, 'http://') === 0 || strpos($path, 'https://') === 0);
    header('Location: ' . ($isAbsolute ? $path : url($path)));
    exit;
}

/**
 * Flash messages
 */
function flash_set(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function flash_get(): array
{
    $flash = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $flash;
}

function render_flash(): string
{
    $out = '';
    foreach (flash_get() as $f) {
        $out .= '<div class="alert ' . e($f['type']) . '">' . e($f['message']) . '</div>';
    }
    return $out;
}

/**
 * CSRF
 */
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

/**
 * فك قفل الجلسة قبل العمليات الطويلة (توليد AI · تصميم · بحث)
 * PHP بيقفل ملف الجلسة طول الطلب — فطلب تصميم بياخد دقيقة كان بيوقّف أي صفحة تانية
 * لنفس العميل لحد ما يخلص («السيستم كله بيقف» على Apache). القراءة من $_SESSION بتفضل شغالة بعدها.
 */
function session_release(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        csrf_token();   // نتأكد إن التوكن متسجّل قبل القفل
        session_write_close();
    }
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(): bool
{
    // POST only: tokens in URLs leak via referrer/logs
    $token = $_POST['csrf'] ?? '';
    return !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $token);
}

function require_csrf(): void
{
    if (!csrf_check()) {
        http_response_code(403);
        die('CSRF verification failed.');
    }
}

/**
 * Generate random token
 */
function random_token(int $length = 64): string
{
    return bin2hex(random_bytes($length / 2));
}

/**
 * Format date in Arabic
 */
function fmt_date(string $datetime, bool $time = false): string
{
    $months = [
        '01' => 'يناير', '02' => 'فبراير', '03' => 'مارس', '04' => 'أبريل',
        '05' => 'مايو', '06' => 'يونيو', '07' => 'يوليو', '08' => 'أغسطس',
        '09' => 'سبتمبر', '10' => 'أكتوبر', '11' => 'نوفمبر', '12' => 'ديسمبر'
    ];
    $ts = strtotime($datetime);
    if (!$ts) return $datetime;
    $day = date('d', $ts);
    $month = $months[date('m', $ts)] ?? date('M', $ts);
    $year = date('Y', $ts);
    $base = "$day $month $year";
    if ($time) {
        $base .= ' · ' . date('H:i', $ts);
    }
    return $base;
}

/**
 * Time ago in Arabic
 */
function time_ago(string $datetime): string
{
    $diff = time() - strtotime($datetime);
    if ($diff < 60) return 'الآن';
    if ($diff < 3600) return 'منذ ' . floor($diff / 60) . ' دقيقة';
    if ($diff < 86400) return 'منذ ' . floor($diff / 3600) . ' ساعة';
    if ($diff < 2592000) return 'منذ ' . floor($diff / 86400) . ' يوم';
    return fmt_date($datetime);
}

/**
 * User initials for avatar
 */
function initials(string $name): string
{
    $parts = preg_split('/\s+/u', trim($name));
    if (count($parts) >= 2) {
        return mb_substr($parts[0], 0, 1) . mb_substr(end($parts), 0, 1);
    }
    return mb_substr($name, 0, 2);
}

/**
 * Color from string (deterministic avatar color)
 */
function color_from_string(string $str): string
{
    $colors = ['#7c6df2', '#f28b8b', '#7ccfb3', '#f0b967', '#8ec2f0', '#e89ac8', '#b79af0'];
    $idx = abs(crc32($str)) % count($colors);
    return $colors[$idx];
}

/**
 * Truncate text
 */
function str_limit(string $str, int $limit = 100, string $suffix = '...'): string
{
    if (mb_strlen($str) <= $limit) return $str;
    return rtrim(mb_substr($str, 0, $limit)) . $suffix;
}

/**
 * JSON response helper (for AJAX endpoints)
 */
function json_response(array $data, int $status = 200): void
{
    // تطبيق الموبايل: حفظ الرد لمفتاح العملية (منع الخصم المكرر) — مابيتعرّفش غير في /api/v1
    if (function_exists('mobile_on_response')) {
        mobile_on_response($data, $status);
    }
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Validate email
 */
function valid_email(string $email): bool
{
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Get content type label in Arabic
 */
function content_type_label(string $type): string
{
    $map = [
        'introductory' => 'تعريفي',
        'marketing' => 'تسويقي',
        'educational' => 'تعليمي',
        'engaging' => 'تفاعلي',
        'offer' => 'عرض خاص',
        'trend' => 'ترند',
        // أنواع «خطة المحتوى» (نظام تاني — المنشورات اللي طالعة من الخطط والقمع التجريبي)
        'promotional' => 'إعلاني',
        'storytelling' => 'قصصي',
        'awareness' => 'توعوي',
    ];
    // نوع مش معروف: كلمة عربي بدل ما الكود الإنجليزي يظهر للعميل
    return $map[$type] ?? 'منشور';
}

/**
 * Get platform label
 */
function platform_label(string $platform): string
{
    $map = [
        'facebook' => 'فيسبوك',
        'instagram' => 'إنستجرام',
        'both' => 'فيسبوك وإنستجرام'
    ];
    return $map[$platform] ?? $platform;
}

/**
 * Get tone label
 */
function tone_label(string $tone): string
{
    $map = [
        'formal' => 'رسمي',
        'simple' => 'بسيط',
        'fun' => 'مرح',
        'professional' => 'احترافي',
    ];
    return $map[$tone] ?? $tone;
}

/**
 * Status chip class
 */
function status_chip(string $status): string
{
    $map = [
        'generated' => 'chip-sky',
        'edited' => 'chip-amber',
        'saved' => 'chip-mint',
        'active' => 'chip-mint',
        'inactive' => 'chip-line',
        'blocked' => 'chip-coral',
        'success' => 'chip-mint',
        'failed' => 'chip-coral',
        'pending' => 'chip-amber',
    ];
    return $map[$status] ?? 'chip-line';
}

function status_label(string $status): string
{
    $map = [
        'generated' => 'تم التوليد',
        'edited' => 'تم التعديل',
        'saved' => 'محفوظ',
        'active' => 'نشط',
        'inactive' => 'غير نشط',
        'blocked' => 'محظور',
        'success' => 'نجح',
        'failed' => 'فشل',
        'pending' => 'قيد الانتظار',
    ];
    return $map[$status] ?? $status;
}

/* ─── مظهر الموقع (يتحكم فيه الأدمن) ─────────────────────── */

function site_setting(string $key, string $default = ''): string
{
    if (!function_exists('get_setting')) {
        return $default;
    }
    try {
        $v = (string) get_setting($key, $default);
        return $v !== '' ? $v : $default;
    } catch (\Throwable $e) {
        return $default;
    }
}

function site_name(): string
{
    return site_setting('site_name', APP_NAME);
}

function site_tagline(): string
{
    return site_setting('site_tagline', defined('APP_TAGLINE') ? APP_TAGLINE : '');
}

/** لوجو الموقع: صورة لو مرفوعة، وإلا الحرف الافتراضي */
function site_logo_html(): string
{
    $logo = site_setting('site_logo', '');
    if ($logo !== '') {
        return '<div class="brand-mark" style="background:transparent;padding:0;overflow:hidden"><img src="' . e(url('storage/' . $logo)) . '" style="width:100%;height:100%;object-fit:contain" alt="logo"></div>';
    }
    return '<div class="brand-mark">' . e(mb_substr(site_name(), 0, 1)) . '</div>';
}

/** ألوان الثيم المخصصة → CSS variables */
function site_theme_css(): string
{
    $p  = site_setting('theme_primary', '');
    $p2 = site_setting('theme_primary_2', '');
    $pi = site_setting('theme_primary_ink', '');
    if ($p === '' && $p2 === '' && $pi === '') {
        return '';
    }
    $vars = [];
    if ($p !== '') {
        $vars[] = '--primary:' . e($p);
        $vars[] = '--primary-soft:color-mix(in srgb, ' . e($p) . ' 14%, white)';
    }
    if ($p2 !== '') $vars[] = '--primary-2:' . e($p2);
    if ($pi !== '') $vars[] = '--primary-ink:' . e($pi);
    return '<style>:root{' . implode(';', $vars) . '}</style>';
}

/** شريط رسالة أعلى الموقع (من الأدمن) */
function site_header_message_html(): string
{
    $msg = site_setting('header_message', '');
    if ($msg === '') {
        return '';
    }
    return '<div style="background:var(--primary,#7c6df2);color:#fff;text-align:center;padding:8px 14px;font-size:13px;font-weight:600">' . e($msg) . '</div>';
}

/** زرار واتساب عائم + سطر الفوتر (من الأدمن) */
function site_footer_extras_html(): string
{
    $out = '';
    $ft = site_setting('footer_text', '');
    if ($ft !== '') {
        $out .= '<div style="text-align:center;padding:14px;font-size:12px;color:#888">' . e($ft) . '</div>';
    }
    $wa = preg_replace('/[^0-9]/', '', site_setting('whatsapp_float', ''));
    if ($wa !== '') {
        $msg = rawurlencode(site_setting('whatsapp_float_msg', ''));
        $out .= '<a href="https://wa.me/' . e($wa) . '?text=' . $msg . '" target="_blank" rel="noopener" aria-label="WhatsApp"'
            . ' style="position:fixed;bottom:22px;left:22px;z-index:999;width:54px;height:54px;border-radius:50%;background:#25d366;display:flex;align-items:center;justify-content:center;box-shadow:0 4px 14px rgba(0,0,0,.25);text-decoration:none">'
            . '<svg viewBox="0 0 32 32" width="30" height="30" fill="#fff"><path d="M16 3C9.4 3 4 8.4 4 15c0 2.1.6 4.2 1.6 6L4 29l8.2-1.5c1.2.5 2.5.7 3.8.7 6.6 0 12-5.4 12-12S22.6 3 16 3zm0 22c-1.2 0-2.4-.2-3.5-.7l-.5-.2-4.9.9.9-4.7-.3-.5C6.6 18.2 6 16.6 6 15 6 9.5 10.5 5 16 5s10 4.5 10 10-4.5 10-10 10zm5.3-7.4c-.3-.2-1.7-.9-2-1s-.5-.2-.7.2-.8 1-.9 1.1-.3.2-.6.1-1.2-.5-2.3-1.4c-.9-.8-1.4-1.7-1.6-2s0-.5.1-.6l.4-.5c.1-.2.2-.3.3-.5s0-.4 0-.5-.7-1.6-.9-2.2c-.2-.6-.5-.5-.7-.5h-.6c-.2 0-.5.1-.8.4s-1 1-1 2.5 1.1 2.9 1.2 3.1c.1.2 2.1 3.2 5.1 4.5.7.3 1.3.5 1.7.6.7.2 1.4.2 1.9.1.6-.1 1.7-.7 1.9-1.4.2-.7.2-1.2.2-1.4s-.3-.2-.6-.4z"/></svg></a>';
    }
    return $out;
}


/**
 * فك تشفير حقول الفورم اللي اتبعتت base64 (تفادي حجب mod_security للنصوص العربية الطويلة)
 * الفورم بيبعت الحقل باسمه + حقل مخفي `_b64[]` بأسماء الحقول المشفّرة
 */
function decode_b64_fields(): void
{
    if (empty($_POST['_b64'])) {
        return;
    }
    $names = is_array($_POST['_b64']) ? $_POST['_b64'] : explode(',', (string) $_POST['_b64']);
    foreach ($names as $n) {
        $n = trim((string) $n);
        if ($n === '' || !isset($_POST[$n]) || !is_string($_POST[$n])) {
            continue;
        }
        $decoded = base64_decode(strtr($_POST[$n], '-_', '+/'), true);
        if ($decoded !== false && $decoded !== '' && mb_check_encoding($decoded, 'UTF-8')) {
            $_POST[$n] = $decoded;
        }
    }
}

/* ─── مقاسات التصميمات ───────────────────────────────────── */

/**
 * المقاسات المتاحة: المفتاح => [العرض, الارتفاع, الاسم, حجم الـ API]
 */
function design_ratios(): array
{
    // المقاسات من الأدمن (⑤-ب) — المقفولة مابتظهرش · لو مفيش إعداد: الافتراضي تحت
    if (!function_exists('studio_ratios_all') && is_file(__DIR__ . '/studio-config.php')) {
        require_once __DIR__ . '/studio-config.php';
    }
    if (function_exists('studio_ratios_all')) {
        $active = array_filter(studio_ratios_all(), fn($r) => !empty($r['active']));
        if ($active) {
            return array_map(fn($r) => ['label' => $r['label'], 'w' => $r['w'], 'h' => $r['h'], 'size' => $r['size'], 'hint' => $r['hint']], $active);
        }
    }
    return [
        '1:1'  => ['label' => 'مربع 1:1',        'w' => 1024, 'h' => 1024, 'size' => '1024x1024', 'hint' => 'بوست فيسبوك/انستجرام'],
        '4:5'  => ['label' => 'عمودي 4:5',       'w' => 1024, 'h' => 1280, 'size' => '1024x1536', 'hint' => 'انستجرام عمودي'],
        '9:16' => ['label' => 'ستوري 9:16',      'w' => 1024, 'h' => 1820, 'size' => '1024x1536', 'hint' => 'ستوري وريلز'],
        '5:4'  => ['label' => 'أفقي خفيف 5:4',   'w' => 1280, 'h' => 1024, 'size' => '1536x1024', 'hint' => 'بوست عريض'],
        '3:2'  => ['label' => 'أفقي 3:2',        'w' => 1536, 'h' => 1024, 'size' => '1536x1024', 'hint' => 'غلاف/بانر'],
    ];
}

function design_ratio(string $key): array
{
    $all = design_ratios();
    return $all[$key] ?? $all['1:1'];
}

/** وصف المقاس للبرومبت */
function ratio_prompt_hint(string $key): string
{
    $r = design_ratio($key);
    return "Aspect ratio: {$key} ({$r['w']}x{$r['h']} pixels) — compose the layout to fill this exact frame naturally.";
}

/**
 * رابط عرض عنصر معرض الإلهام — ملف مرفوع أو لينك خارجي
 */
function media_display_url(array $m): string
{
    if (!empty($m['image_url'])) {
        return $m['image_url'];
    }
    if (!empty($m['image_path'])) {
        return url('storage/' . $m['image_path']);
    }
    return '';
}

/**
 * هل عنصر القائمة ده ظاهر للعميل؟ (الأدمن بيتحكم من صفحة التكاليف والقائمة)
 */
function menu_visible(string $key): bool
{
    static $hidden = null;
    if ($hidden === null) {
        $hidden = array_filter(array_map('trim', explode(',', (string) (function_exists('get_setting') ? get_setting('menu_hidden', '') : ''))));
    }
    return !in_array($key, $hidden, true);
}

/* ═══════════ تسليم بيانات التجربة المجانية للحساب الجديد ═══════════ */

/** يمسك توكن التجربة من الرابط ويحطه في الجلسة */
function trial_capture_token(): void
{
    $t = preg_replace('/[^a-f0-9]/', '', (string) ($_GET['trial'] ?? ''));
    if ($t !== '') {
        $_SESSION['trial_token'] = $t;
    }
}

/**
 * ينقل بيانات التجربة لحساب المستخدم: يملّي الهوية ويحفظ المنشور
 * بيرجع id المنشور اللي اتعمل (أو null)
 */
function trial_claim(int $userId): ?int
{
    $token = preg_replace('/[^a-f0-9]/', '', (string) ($_SESSION['trial_token'] ?? ''));
    if ($token === '') {
        return null;
    }
    unset($_SESSION['trial_token']);

    try {
        $t = db_one('SELECT * FROM trial_sessions WHERE token = ? AND claimed_user_id IS NULL', [$token]);
        if (!$t) {
            return null;
        }

        // 1) الهوية
        $brand = db_one('SELECT * FROM brand_profiles WHERE user_id = ?', [$userId]);
        $fields = [
            'business_name' => $t['business_name'],
            'industry'      => $t['industry'],
            'audience'      => $t['audience'],
            'services'      => $t['services'],
            'tone'          => $t['tone'],
            'dialect'       => $t['dialect'] ?: 'egyptian',
        ];
        if ($brand) {
            $set = [];
            $vals = [];
            foreach ($fields as $k => $v) {
                if ($v === null || $v === '') {
                    continue;
                }
                $set[] = "`{$k}` = ?";
                $vals[] = $v;
            }
            if ($set) {
                $vals[] = $brand['id'];
                db_run('UPDATE brand_profiles SET ' . implode(', ', $set) . ' WHERE id = ?', $vals);
            }
            $brandId = (int) $brand['id'];
        } else {
            $brandId = db_insert(
                'INSERT INTO brand_profiles (user_id, business_name, industry, audience, services, tone, dialect)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$userId, $t['business_name'] ?: 'براندي', $t['industry'], $t['audience'], $t['services'], $t['tone'], $t['dialect'] ?: 'egyptian']
            );
        }

        // 2) المنشور اللي اتولد في التجربة
        $contentId = null;
        if (trim((string) $t['generated_text']) !== '') {
            $idea = json_decode((string) $t['chosen_idea'], true) ?: [];
            $contentId = db_insert_row('contents', [
                'user_id'          => $userId,
                'brand_profile_id' => $brandId,
                'content_type'     => 'promotional',
                'platform'         => 'facebook',
                'length'           => 'medium',
                'tone'             => $t['tone'] ?: null,
                'dialect'          => $t['dialect'] ?: 'egyptian',
                'generated_text'   => $t['generated_text'],
                'hashtags'         => $t['hashtags'],
                'cta'              => $t['cta'],
                'design_direction' => $idea['title'] ?? null,
                'status'           => 'generated',
                'credits_used'     => 0,
                'model'            => 'trial',
            ]);
        }

        db_run('UPDATE trial_sessions SET claimed_user_id = ?, claimed_at = NOW() WHERE id = ?', [$userId, $t['id']]);
        return $contentId ? (int) $contentId : null;
    } catch (\Throwable $e) {
        return null;
    }
}

/* ═══════════ التقاط كود الإحالة (لازم في helpers عشان يشتغل في فلو التسجيل) ═══════════ */

/** بيمسك ?ref=CODE من الرابط ويحفظه في الجلسة والكوكي */
function referral_capture(): void
{
    $code = strtoupper(trim((string) ($_GET['ref'] ?? $_GET['offer'] ?? '')));
    $code = preg_replace('/[^A-Z0-9_-]/', '', $code);
    if ($code === '' || mb_strlen($code) > 40) {
        return;
    }
    $_SESSION['sp_ref'] = $code;
    $days = (int) (function_exists('get_setting') ? get_setting('referral_cookie_days', 30) : 30);
    @setcookie('sp_ref', $code, [
        'expires'  => time() + max(1, $days) * 86400,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/** الكود الفعّال: المكتوب يدويًا يغلب الجلسة، والجلسة تغلب الكوكي */
function referral_active_code(?string $typed = null): string
{
    $typed = strtoupper(trim((string) $typed));
    if ($typed !== '') return preg_replace('/[^A-Z0-9_-]/', '', $typed);
    if (!empty($_SESSION['sp_ref'])) return (string) $_SESSION['sp_ref'];
    if (!empty($_COOKIE['sp_ref'])) return preg_replace('/[^A-Z0-9_-]/', '', strtoupper((string) $_COOKIE['sp_ref']));
    return '';
}

function referral_clear(): void
{
    unset($_SESSION['sp_ref']);
    @setcookie('sp_ref', '', ['expires' => time() - 3600, 'path' => '/']);
}

/**
 * بتتنادى بعد إنشاء المستخدم مباشرة.
 * بتربط الإحالة وتصرف هدية الترحيب — وبتفشل بصمت عشان متمنعش التسجيل أبدًا.
 */
function referral_attach_on_register(int $userId, ?string $typedCode = null): void
{
    try {
        if (function_exists('get_setting') && get_setting('referral_enabled', '1') !== '1') {
            return;
        }
        require_once __DIR__ . '/credits.php';
        require_once __DIR__ . '/offers.php';

        $u = db_one('SELECT * FROM users WHERE id = ?', [$userId]);
        if (!$u) return;

        // بصمة الهوية و IP التسجيل — بتتسجل دايمًا حتى لو مفيش كود
        $idHash = identity_hash_for($u['phone'] ?? null, $u['email'] ?? null);
        db_run('UPDATE users SET identity_hash = ?, signup_ip = ? WHERE id = ?',
            [$idHash, offer_client_ip(), $userId]);

        // كود الأفلييت الخاص بيه
        ensure_user_referral_offer($userId);

        $code = referral_active_code($typedCode);
        if ($code === '') return;

        $u['identity_hash'] = $idHash;
        $el = offer_eligibility($code, $u, ['role' => 'referee', 'identity_hash' => $idHash]);
        if (!$el['ok']) {
            error_log('[referral] رفض ' . $code . ' للمستخدم ' . $userId . ': ' . $el['reason']);
            referral_clear();
            return;
        }

        $offer = $el['offer'];

        db_run('UPDATE users SET referred_by = ?, referral_offer_id = ? WHERE id = ?',
            [$offer['owner_user_id'] ?: null, $offer['id'], $userId]);

        if (!empty($offer['owner_user_id'])) {
            try {
                db_insert('INSERT INTO referrals (offer_id, referrer_user_id, referee_user_id, signup_ip) VALUES (?,?,?,?)',
                    [$offer['id'], $offer['owner_user_id'], $userId, offer_client_ip()]);
            } catch (\Throwable $e) {
                // uq_referee: المستخدم اتحال قبل كده
            }
        }

        // هدية الترحيب فورًا
        if ((int) $offer['referee_credits'] > 0) {
            redeem_offer((int) $offer['id'], $userId, 'referee', ['identity_hash' => $idHash]);
        }

        // لو الاستحقاق عند التسجيل، نصرف للمُحيل على طول
        if ($offer['trigger_event'] === 'on_register') {
            referral_qualify($userId, 'on_register');
        }

        referral_clear();
    } catch (\Throwable $e) {
        error_log('[referral] استثناء: ' . $e->getMessage());
    }
}

/** بتتنادى عند تفعيل/اعتماد الحساب */
function referral_on_activation(int $userId): void
{
    try {
        require_once __DIR__ . '/credits.php';
        require_once __DIR__ . '/offers.php';
        referral_qualify($userId, 'on_activation');
    } catch (\Throwable $e) {
        error_log('[referral] activation: ' . $e->getMessage());
    }
}

/** بتتنادى عند أول عملية شراء */
function referral_on_first_payment(int $userId): void
{
    try {
        require_once __DIR__ . '/credits.php';
        require_once __DIR__ . '/offers.php';
        db_run('UPDATE users SET has_purchased = 1 WHERE id = ?', [$userId]);
        referral_qualify($userId, 'on_first_payment');
    } catch (\Throwable $e) {
        error_log('[referral] payment: ' . $e->getMessage());
    }
}

/* ═══════════ الواجهة الجديدة (v2) — مفتاح التفعيل ═══════════ */

/**
 * هل المستخدم الحالي شايف الواجهة الجديدة؟
 *   off   → القديمة للكل
 *   optin → الجديدة لمن فعّلها بس
 *   all   → الجديدة للكل ما عدا اللي اختار القديمة
 * ومعاينة مؤقتة: ?ui=v2 أو ?ui=v1 (بتتحفظ في الجلسة)
 */
function ui_v2_enabled(): bool
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    // معاينة من الرابط
    if (isset($_GET['ui']) && in_array($_GET['ui'], ['v1', 'v2'], true) && session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['ui_preview'] = $_GET['ui'];
    }
    if (!empty($_SESSION['ui_preview'])) {
        return $cached = ($_SESSION['ui_preview'] === 'v2');
    }

    try {
        $mode = function_exists('get_setting')
            ? (string) get_setting('ui_v2_mode', 'optin')
            : (string) (db_one('SELECT setting_value FROM settings WHERE setting_key = "ui_v2_mode"')['setting_value'] ?? 'optin');
    } catch (\Throwable $e) {
        return $cached = false;
    }
    if ($mode === 'off') {
        return $cached = false;
    }

    // زائر مش مسجّل (صفحة الدخول/التسجيل): بنعتمد على اختيار آخر حساب دخل من الجهاز ده.
    // الكوكي بيأثر على الشكل بس — مش على أي صلاحية أو بيانات.
    if (empty($_SESSION['user_id'])) {
        if ($mode === 'all') return $cached = (($_COOKIE['spread_ui'] ?? '') !== 'v1');
        return $cached = (($_COOKIE['spread_ui'] ?? '') === 'v2');
    }

    $pref = 'auto';
    if (!empty($_SESSION['user_id'])) {
        try {
            $row = db_one('SELECT ui_pref FROM users WHERE id = ?', [(int) $_SESSION['user_id']]);
            $pref = $row['ui_pref'] ?? 'auto';
        } catch (\Throwable $e) {
            $pref = 'auto';   // العمود لسه ما اتضافش — نفضل على الافتراضي
        }
    }

    $on = $pref === 'v2' ? true : ($pref === 'v1' ? false : ($mode === 'all'));
    ui_remember_choice($on);
    return $cached = $on;
}

/** نفتكر اختيار الشكل على الجهاز ده (عشان صفحة الدخول تظهر بنفس الشكل) */
function ui_remember_choice(bool $v2): void
{
    $want = $v2 ? 'v2' : 'v1';
    if (($_COOKIE['spread_ui'] ?? '') === $want || headers_sent()) {
        return;
    }
    setcookie('spread_ui', $want, [
        'expires'  => time() + 60 * 60 * 24 * 180,
        'path'     => '/',
        'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    $_COOKIE['spread_ui'] = $want;
}

/** نص الـ class اللي بيتحط على body */
function ui_body_class(string $extra = ''): string
{
    return trim($extra . (ui_v2_enabled() ? ' ui-v2' : ''));
}

<?php
/**
 * Spread AI v2 — تسجيل الدخول بجوجل (OAuth 2.0 + OpenID Connect)
 *
 * الأمان: state ضد CSRF · nonce ضد إعادة التشغيل ·
 * التحقق من الـ id_token عبر خادم جوجل قبل الوثوق بأي بيانات.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/crypto.php';
require_once __DIR__ . '/credits.php';   // get_setting
require_once __DIR__ . '/helpers.php';   // e() و url()

const GOOGLE_AUTH_URL  = 'https://accounts.google.com/o/oauth2/v2/auth';
const GOOGLE_TOKEN_URL = 'https://oauth2.googleapis.com/token';
const GOOGLE_TOKENINFO = 'https://oauth2.googleapis.com/tokeninfo';

/* ═══════════ الإعدادات ═══════════ */

function google_enabled(): bool
{
    return get_setting('google_login_enabled', '0') === '1'
        && google_client_id() !== ''
        && google_client_secret() !== '';
}

function google_client_id(): string
{
    return trim((string) get_setting('google_client_id', ''));
}

function google_client_secret(): string
{
    $enc = (string) get_setting('google_client_secret_enc', '');
    if ($enc === '') return '';
    try {
        return (string) Crypto::decrypt($enc);
    } catch (\Throwable $e) {
        return '';
    }
}

function google_redirect_uri(): string
{
    return rtrim(APP_URL, '/') . '/auth/google-callback.php';
}

/* ═══════════ بدء التدفق ═══════════ */

/**
 * بيبني رابط جوجل ويحفظ state و nonce في الجلسة
 * @param string $intent 'login' أو 'register'
 * @param string $ref كود الإحالة لو موجود (بيتحفظ عشان ميضيعش)
 */
function google_auth_url(string $intent = 'login', string $ref = ''): string
{
    $state = bin2hex(random_bytes(24));
    $nonce = bin2hex(random_bytes(16));

    $_SESSION['google_state']  = $state;
    $_SESSION['google_nonce']  = $nonce;
    $_SESSION['google_intent'] = $intent === 'register' ? 'register' : 'login';
    $_SESSION['google_time']   = time();
    if ($ref !== '') {
        $_SESSION['sp_ref'] = $ref;
    }

    return GOOGLE_AUTH_URL . '?' . http_build_query([
        'client_id'     => google_client_id(),
        'redirect_uri'  => google_redirect_uri(),
        'response_type' => 'code',
        'scope'         => 'openid email profile',
        'state'         => $state,
        'nonce'         => $nonce,
        'prompt'        => 'select_account',
        'access_type'   => 'online',
    ]);
}

/* ═══════════ تبادل الكود بالتوكن ═══════════ */

function google_exchange_code(string $code): array
{
    $ch = curl_init(GOOGLE_TOKEN_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_POSTFIELDS     => http_build_query([
            'code'          => $code,
            'client_id'     => google_client_id(),
            'client_secret' => google_client_secret(),
            'redirect_uri'  => google_redirect_uri(),
            'grant_type'    => 'authorization_code',
        ]),
    ]);
    $res  = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($res === false) {
        return ['ok' => false, 'error' => 'تعذّر الاتصال بجوجل: ' . $err];
    }
    $data = json_decode((string) $res, true);
    if ($http !== 200 || empty($data['id_token'])) {
        google_log('token exchange failed: ' . mb_substr((string) $res, 0, 300));
        return ['ok' => false, 'error' => 'جوجل رفض الطلب — راجع Client ID/Secret ورابط الإرجاع'];
    }
    return ['ok' => true, 'id_token' => $data['id_token'], 'access_token' => $data['access_token'] ?? ''];
}

/**
 * التحقق من الـ id_token عند جوجل نفسها.
 * مش بنفك التوكن محليًا — بنسأل جوجل عشان التوقيع يتأكد.
 */
function google_verify_id_token(string $idToken): array
{
    $ch = curl_init(GOOGLE_TOKENINFO . '?id_token=' . urlencode($idToken));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]);
    $res  = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($res === false || $http !== 200) {
        return ['ok' => false, 'error' => 'تعذّر التحقق من هوية جوجل'];
    }
    $p = json_decode((string) $res, true);
    if (!is_array($p)) {
        return ['ok' => false, 'error' => 'رد غير مفهوم من جوجل'];
    }

    // التوكن لازم يكون لتطبيقنا إحنا
    if (($p['aud'] ?? '') !== google_client_id()) {
        google_log('aud mismatch');
        return ['ok' => false, 'error' => 'التوكن مش صادر لتطبيقنا'];
    }
    if (!in_array($p['iss'] ?? '', ['accounts.google.com', 'https://accounts.google.com'], true)) {
        return ['ok' => false, 'error' => 'مُصدِر التوكن غير موثوق'];
    }
    if ((int) ($p['exp'] ?? 0) < time()) {
        return ['ok' => false, 'error' => 'انتهت صلاحية التوكن — حاول تاني'];
    }
    // الـ nonce لازم يطابق اللي بعتناه
    if (!empty($_SESSION['google_nonce']) && ($p['nonce'] ?? '') !== $_SESSION['google_nonce']) {
        google_log('nonce mismatch');
        return ['ok' => false, 'error' => 'فشل التحقق الأمني — ابدأ من جديد'];
    }
    if (empty($p['email'])) {
        return ['ok' => false, 'error' => 'حساب جوجل من غير إيميل'];
    }
    // جوجل بترجّع email_verified كنص "true" أحيانًا
    $verified = $p['email_verified'] ?? 'false';
    if ($verified !== true && $verified !== 'true') {
        return ['ok' => false, 'error' => 'إيميل جوجل ده مش مفعّل'];
    }

    return [
        'ok'      => true,
        'sub'     => (string) $p['sub'],
        'email'   => mb_strtolower(trim((string) $p['email'])),
        'name'    => trim((string) ($p['name'] ?? '')) ?: (explode('@', (string) $p['email'])[0]),
        'picture' => (string) ($p['picture'] ?? ''),
    ];
}

/* ═══════════ إنشاء أو ربط الحساب ═══════════ */

/**
 * بيرجّع ['ok'=>bool, 'user_id'=>int, 'is_new'=>bool, 'error'=>?string]
 */
function google_find_or_create_user(array $g): array
{
    // 1) حساب مربوط بجوجل بالفعل
    $user = db_one('SELECT * FROM users WHERE google_id = ?', [$g['sub']]);

    // 2) نفس الإيميل بحساب كلمة مرور → نربطهم
    if (!$user) {
        $byEmail = db_one('SELECT * FROM users WHERE email = ?', [$g['email']]);
        if ($byEmail) {
            db_run(
                'UPDATE users SET google_id = ?, avatar_url = ?, auth_provider = ?,
                        email_verified_at = COALESCE(email_verified_at, NOW()) WHERE id = ?',
                [$g['sub'], mb_substr($g['picture'], 0, 500) ?: null,
                 $byEmail['password'] ? 'both' : 'google', $byEmail['id']]
            );
            return ['ok' => true, 'user_id' => (int) $byEmail['id'], 'is_new' => false, 'linked' => true];
        }
    }

    // 3) حساب موجود ومربوط → تحديث الصورة فقط
    if ($user) {
        if ($g['picture'] !== '' && $g['picture'] !== ($user['avatar_url'] ?? '')) {
            db_run('UPDATE users SET avatar_url = ? WHERE id = ?', [mb_substr($g['picture'], 0, 500), $user['id']]);
        }
        return ['ok' => true, 'user_id' => (int) $user['id'], 'is_new' => false, 'linked' => false];
    }

    // 4) حساب جديد
    if (get_setting('registration_open', '1') !== '1') {
        return ['ok' => false, 'error' => 'التسجيل مقفول حاليًا'];
    }

    $autoApprove = get_setting('google_auto_approve', '1') === '1'
        && get_setting('manual_approval', '0') !== '1';

    try {
        $userId = db_insert(
            'INSERT INTO users (name, email, password, google_id, avatar_url, auth_provider,
                                role, status, email_verified_at, approval_status)
             VALUES (?, ?, NULL, ?, ?, "google", "user", "active", NOW(), ?)',
            [
                mb_substr($g['name'], 0, 150),
                $g['email'],
                $g['sub'],
                mb_substr($g['picture'], 0, 500) ?: null,
                $autoApprove ? 'approved' : 'pending',
            ]
        );
    } catch (\Throwable $e) {
        google_log('insert failed: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'تعذّر إنشاء الحساب'];
    }

    if ($autoApprove) {
        db_run('UPDATE users SET approved_at = NOW() WHERE id = ?', [$userId]);
    }

    // نفس ما بيحصل في التسجيل العادي: محفظة + كريدت ترحيبي + ملف براند
    db_run('INSERT INTO credit_wallets (user_id, balance) VALUES (?, ?)', [$userId, STARTER_CREDITS]);
    db_run(
        'INSERT INTO credit_transactions (user_id, action_type, amount, reference_type, notes) VALUES (?, "add", ?, "signup", ?)',
        [$userId, STARTER_CREDITS, 'كريدت ترحيبي عند التسجيل بجوجل']
    );
    db_run('INSERT INTO brand_profiles (user_id, business_name) VALUES (?, ?)', [$userId, mb_substr($g['name'], 0, 150)]);

    return ['ok' => true, 'user_id' => $userId, 'is_new' => true, 'auto_approved' => $autoApprove];
}

/* ═══════════ سجل الأخطاء ═══════════ */

function google_log(string $msg): void
{
    $dir = dirname(__DIR__) . '/storage/logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    @file_put_contents($dir . '/google-auth.log',
        date('Y-m-d H:i:s') . ' | ' . $msg . PHP_EOL, FILE_APPEND);
}

/** تنظيف بيانات الجلسة المؤقتة */
function google_clear_session(): void
{
    unset($_SESSION['google_state'], $_SESSION['google_nonce'],
          $_SESSION['google_intent'], $_SESSION['google_time']);
}

/**
 * آخر خطوة في دخول جوجل (بعد التأكد إن الحساب فيه رقم موبايل):
 * التحقق بخطوتين ← الدخول («افتكرني» حسب افتراضي النظام) ← مكافأة الإحالة ← بيانات التجربة ← الرئيسية
 * @param array $res نتيجة google_find_or_create_user (is_new · linked)
 */
function google_finish_login(array $user, array $res): void
{
    $userId = (int) $user['id'];
    // التحقق بخطوتين (حساب قديم مفعّله) ← كود على الإيميل قبل الدخول
    if (empty($res['is_new']) && function_exists('account_2fa_on') && account_2fa_on($user)) {
        account_login_or_challenge($user);
    }
    login_user($userId);
    $_SESSION['user_name'] = $user['name'];
    db_run('UPDATE users SET updated_at = NOW() WHERE id = ?', [$userId]);

    // استحقاق مكافأة المُحيل (الإيميل مفعّل من جوجل أصلًا)
    if (!empty($res['is_new']) && function_exists('referral_on_activation')) {
        referral_on_activation($userId);
    }
    // نقل بيانات التجربة المجانية لو جاي منها
    $trialContent = function_exists('trial_claim') ? trial_claim($userId) : null;

    if (!empty($res['is_new'])) {
        flash_set('success', 'أهلًا بيك في Spread AI 🎉 حسابك جاهز — والكريدت الترحيبي في محفظتك.');
    } elseif (!empty($res['linked'])) {
        flash_set('success', 'ربطنا حساب جوجل بحسابك — تقدر تدخل بالطريقتين من دلوقتي.');
    }
    redirect($trialContent ? 'content-view.php?id=' . $trialContent : 'dashboard.php');
}

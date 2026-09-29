<?php
/**
 * Spread AI — Auth System (Client / User Auth)
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/account.php';   // الجلسات النشطة · التحقق بخطوتين (⑥-أ)

/**
 * Currently logged in user
 */
function current_user(): ?array
{
    if (empty($_SESSION['user_id'])) return null;
    static $user = null;
    if ($user === null) {
        $user = db_one('SELECT * FROM users WHERE id = ? LIMIT 1', [$_SESSION['user_id']]);
    }
    return $user ?: null;
}

function is_logged_in(): bool
{
    return current_user() !== null;
}

/**
 * Require login on a page (redirect if not logged in)
 */
function require_login(): void
{
    if (!is_logged_in()) {
        flash_set('warning', 'لازم تسجل دخول الأول');
        redirect('login.php');
    }
    // Block inactive / unverified users
    $u = current_user();
    if (!$u['email_verified_at']) {
        flash_set('warning', 'الحساب لسه مش مفعل. راجع بريدك أو أعد إرسال التفعيل.');
        redirect('login.php');
    }
    if (($u['approval_status'] ?? 'approved') !== 'approved') {
        session_destroy();
        flash_set('warning', 'حسابك تحت المراجعة أو تم إيقافه. تواصل مع الإدارة.');
        redirect('login.php');
    }
    if ($u['status'] !== 'active') {
        session_destroy();
        flash_set('danger', 'الحساب موقوف. تواصل مع الإدارة.');
        redirect('login.php');
    }
    // الجلسة اتنهت من جهاز تاني («خروج من الأجهزة الأخرى» في الإعدادات)
    if (!account_session_check((int) $u['id'])) {
        unset($_SESSION['user_id']);
        session_regenerate_id(true);
        flash_set('warning', 'الجلسة دي اتنهت من جهاز تاني — سجّل دخول تاني');
        redirect('login.php');
    }
}

/**
 * Login user (set session)
 */
function login_user(int $userId): void
{
    $_SESSION['user_id'] = $userId;
    session_regenerate_id(true);
    unset($_SESSION['2fa_pending'], $_SESSION['2fa']);
    account_session_register($userId);
}

/**
 * Logout
 */
function logout_user(): void
{
    account_session_end_current();
    unset($_SESSION['user_id'], $_SESSION['us_chk']);
    session_regenerate_id(true);
}

/**
 * Register new user
 * Returns ['ok' => bool, 'error' => string|null, 'user_id' => int|null]
 */
function register_user(string $name, string $email, string $password): array
{
    if (mb_strlen($name) < 2) return ['ok' => false, 'error' => 'الاسم قصير جدًا'];
    if (!valid_email($email)) return ['ok' => false, 'error' => 'البريد الإلكتروني غير صحيح'];
    if (strlen($password) < 8) return ['ok' => false, 'error' => 'كلمة المرور لازم تكون 8 حروف على الأقل'];

    // Check duplicate
    $exists = db_one('SELECT id FROM users WHERE email = ?', [$email]);
    if ($exists) return ['ok' => false, 'error' => 'الإيميل ده مسجل قبل كده'];

    // Insert user
    $userId = db_insert(
        'INSERT INTO users (name, email, password, role, status) VALUES (?, ?, ?, ?, ?)',
        [$name, $email, password_hash($password, PASSWORD_BCRYPT), 'user', 'active']
    );

    // Create credit wallet with starter credits
    db_run('INSERT INTO credit_wallets (user_id, balance) VALUES (?, ?)', [$userId, STARTER_CREDITS]);
    db_run(
        'INSERT INTO credit_transactions (user_id, action_type, amount, reference_type, notes) VALUES (?, ?, ?, ?, ?)',
        [$userId, 'add', STARTER_CREDITS, 'signup', 'كريدت ترحيبي عند التسجيل']
    );

    // Create empty brand profile
    db_run(
        'INSERT INTO brand_profiles (user_id, business_name) VALUES (?, ?)',
        [$userId, $name]
    );

    return ['ok' => true, 'error' => null, 'user_id' => $userId];
}

/**
 * Authenticate user (check email + password)
 */
function authenticate_user(string $email, string $password): array
{
    $user = db_one('SELECT * FROM users WHERE email = ? LIMIT 1', [$email]);
    if (!$user) return ['ok' => false, 'error' => 'البيانات غير صحيحة'];
    // حساب جوجل خالص — مفيش كلمة مرور يتقارن بيها
    if (empty($user['password'])) {
        return ['ok' => false,
                'error' => 'الحساب ده بيدخل بجوجل — اضغط «الدخول بحساب جوجل» فوق',
                'use_google' => true];
    }
    if (!password_verify($password, (string) $user['password'])) {
        return ['ok' => false, 'error' => 'البيانات غير صحيحة'];
    }
    if ($user['status'] !== 'active') {
        return ['ok' => false, 'error' => 'الحساب موقوف. تواصل مع الإدارة.'];
    }
    return ['ok' => true, 'user' => $user];
}

/**
 * Create email verification token
 */
function create_verification_token(int $userId): string
{
    $token = random_token(64);
    db_run(
        'INSERT INTO email_verifications (user_id, token, expires_at) VALUES (?, ?, ?)',
        [$userId, $token, date('Y-m-d H:i:s', time() + VERIFY_TOKEN_TTL)]
    );
    return $token;
}

/**
 * Verify email token
 */
function verify_email_token(string $token): bool
{
    $row = db_one(
        'SELECT v.*, u.id as uid FROM email_verifications v
         JOIN users u ON u.id = v.user_id
         WHERE v.token = ? AND v.verified_at IS NULL AND v.expires_at > NOW() LIMIT 1',
        [$token]
    );
    if (!$row) return false;

    db_run('UPDATE email_verifications SET verified_at = NOW() WHERE id = ?', [$row['id']]);
    db_run('UPDATE users SET email_verified_at = NOW() WHERE id = ?', [$row['user_id']]);

    // لحظة استحقاق مكافأة المُحيل (لو مفيش موافقة يدوية)
    if (function_exists('referral_on_activation')
        && (!function_exists('get_setting') || get_setting('manual_approval', '0') !== '1')) {
        referral_on_activation((int) $row['user_id']);
    }
    return true;
}

/**
 * Brand profile of current user
 */
function user_brand(?int $userId = null): ?array
{
    $userId = $userId ?? ($_SESSION['user_id'] ?? null);
    if (!$userId) return null;
    return db_one('SELECT * FROM brand_profiles WHERE user_id = ? LIMIT 1', [$userId]);
}

/**
 * Credit balance of current user
 */
function user_credits(?int $userId = null): int
{
    $userId = $userId ?? ($_SESSION['user_id'] ?? null);
    if (!$userId) return 0;
    $row = db_one('SELECT balance FROM credit_wallets WHERE user_id = ?', [$userId]);
    return $row ? (int) $row['balance'] : 0;
}

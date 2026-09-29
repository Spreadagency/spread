<?php
/**
 * Spread AI v2 — حساب العميل (المرحلة ⑥-أ · مركز الإعدادات)
 *
 * ① الجلسات النشطة: كل دخول بيتسجّل (الجهاز · الـ IP · آخر نشاط) — والعميل يقدر ينهي أي جلسة تانية
 * ② التحقق بخطوتين: كود 6 أرقام على الإيميل عند كل دخول (بيتفعّل بعد ما يستلم كود فعلًا — علشان مايتقفلش برّه)
 * ③ حذف الحساب: طلب بمهلة 14 يوم يقدر يلغيه — بعدها الكرون بيمسح البيانات نهائيًا
 * ④ الاستهلاك بالتفصيل من آخر شحن
 *
 * كل حاجة هنا محمية لو ترحيل المرحلة لسه ماتشغّلش (مابتكسرش الدخول).
 */

if (!function_exists('db')) {
    require_once __DIR__ . '/db.php';
}

const ACCOUNT_DELETE_GRACE_DAYS = 14;
const ACCOUNT_SESSION_CHECK_SEC = 60;     // فحص «الجلسة اتنهت من جهاز تاني؟» كل دقيقة بالكتير
const ACCOUNT_2FA_TTL = 600;              // صلاحية الكود 10 دقايق
const ACCOUNT_2FA_TRIES = 5;

/* ═══════════════ ① الجلسات ═══════════════ */

function account_sid_hash(): string
{
    return hash('sha256', (string) session_id());
}

/** جلسة دخول جديدة (بعد session_regenerate_id) */
function account_session_register(int $userId): void
{
    if (!empty($_SESSION['impersonator_admin_id']) || session_id() === '') return;
    try {
        db_run('INSERT INTO user_sessions (user_id, sid_hash, user_agent, ip) VALUES (?,?,?,?)
                ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), revoked_at = NULL, last_seen_at = NOW()',
            [$userId, account_sid_hash(), mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255), account_ip()]);
        $_SESSION['us_chk'] = time();
    } catch (\Throwable $e) { /* قبل الترحيل */ }
}

/**
 * بيتنادى من require_login: لو الجلسة دي اتنهت من جهاز تاني ← خروج.
 * @return bool false = الجلسة ملغية
 */
function account_session_check(int $userId): bool
{
    if (!empty($_SESSION['impersonator_admin_id'])) return true;
    if (time() - (int) ($_SESSION['us_chk'] ?? 0) < ACCOUNT_SESSION_CHECK_SEC) return true;
    try {
        $row = db_one('SELECT id, user_id, revoked_at FROM user_sessions WHERE sid_hash = ?', [account_sid_hash()]);
        if ($row && ((int) $row['user_id'] !== $userId || $row['revoked_at'] !== null)) {
            return false;
        }
        if ($row) {
            db_run('UPDATE user_sessions SET last_seen_at = NOW(), ip = ? WHERE id = ?', [account_ip(), $row['id']]);
        } else {
            // جلسة قديمة من قبل الميزة (أو الـ session id اتغيّر) — نسجّلها علشان تبان في القايمة
            account_session_register($userId);
        }
        $_SESSION['us_chk'] = time();
    } catch (\Throwable $e) { /* قبل الترحيل */ }
    return true;
}

/** تسجيل خروج — الجلسة ماتفضلش ظاهرة كنشطة */
function account_session_end_current(): void
{
    try {
        db_run('UPDATE user_sessions SET revoked_at = NOW() WHERE sid_hash = ? AND revoked_at IS NULL', [account_sid_hash()]);
    } catch (\Throwable $e) {}
}

/** الجلسات النشطة (آخر 30 يوم) — الحالية الأول */
function account_sessions(int $userId): array
{
    try {
        $rows = db_all('SELECT id, sid_hash, user_agent, ip, created_at, last_seen_at FROM user_sessions
                        WHERE user_id = ? AND revoked_at IS NULL AND last_seen_at >= NOW() - INTERVAL 30 DAY
                        ORDER BY last_seen_at DESC LIMIT 20', [$userId]);
    } catch (\Throwable $e) {
        return [];
    }
    $cur = account_sid_hash();
    $out = [];
    foreach ($rows as $r) {
        $isCur = hash_equals($r['sid_hash'], $cur);
        $out[] = [
            'id'      => (int) $r['id'],
            'device'  => account_ua_label((string) $r['user_agent']),
            'where'   => account_ip_mask((string) $r['ip']) . ' · ' . ($isCur ? 'الجلسة الحالية' : account_ago($r['last_seen_at'])),
            'current' => $isCur,
        ];
    }
    usort($out, fn($a, $b) => $b['current'] <=> $a['current']);
    return $out;
}

function account_session_revoke(int $userId, int $sessionId): bool
{
    try {
        $st = db()->prepare('UPDATE user_sessions SET revoked_at = NOW() WHERE id = ? AND user_id = ? AND sid_hash <> ? AND revoked_at IS NULL');
        $st->execute([$sessionId, $userId, account_sid_hash()]);
        return $st->rowCount() > 0;
    } catch (\Throwable $e) {
        return false;
    }
}

/** «خروج من الأجهزة الأخرى» — بيرجّع عدد الجلسات اللي اتنهت */
function account_session_revoke_others(int $userId): int
{
    try {
        $st = db()->prepare('UPDATE user_sessions SET revoked_at = NOW() WHERE user_id = ? AND sid_hash <> ? AND revoked_at IS NULL');
        $st->execute([$userId, account_sid_hash()]);
        return $st->rowCount();
    } catch (\Throwable $e) {
        return 0;
    }
}

function account_ip(): string
{
    if (function_exists('client_ip')) return mb_substr(client_ip(), 0, 45);
    return mb_substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

/** 41.35.x.x — مانعرضش الـ IP كامل */
function account_ip_mask(string $ip): string
{
    if ($ip === '') return 'مكان غير معروف';
    if (str_contains($ip, ':')) {
        $p = explode(':', $ip);
        return 'IP ' . ($p[0] ?? '') . ':' . ($p[1] ?? '') . ':…';
    }
    $p = explode('.', $ip);
    return count($p) === 4 ? 'IP ' . $p[0] . '.' . $p[1] . '.x.x' : 'IP';
}

/** «Chrome · Windows» من الـ User-Agent */
function account_ua_label(string $ua): string
{
    if ($ua === '') return 'جهاز غير معروف';
    $b = 'متصفح';
    foreach ([['Edg/', 'Edge'], ['OPR/', 'Opera'], ['SamsungBrowser', 'Samsung Internet'], ['Firefox/', 'Firefox'],
              ['CriOS', 'Chrome'], ['Chrome/', 'Chrome'], ['FxiOS', 'Firefox'], ['Safari/', 'Safari']] as [$k, $v]) {
        if (stripos($ua, $k) !== false) { $b = $v; break; }
    }
    $o = 'جهاز';
    foreach ([['iPhone', 'iPhone'], ['iPad', 'iPad'], ['Android', 'Android'], ['Windows', 'Windows'],
              ['Mac OS X', 'Mac'], ['Macintosh', 'Mac'], ['CrOS', 'ChromeOS'], ['Linux', 'Linux']] as [$k, $v]) {
        if (stripos($ua, $k) !== false) { $o = $v; break; }
    }
    return $b . ' · ' . $o;
}

function account_ago(?string $dt): string
{
    if (!$dt) return '';
    $s = max(0, time() - strtotime($dt));
    if ($s < 120) return 'نشط دلوقتي';
    if ($s < 3600) return 'منذ ' . floor($s / 60) . ' دقيقة';
    if ($s < 86400) { $h = (int) floor($s / 3600); return 'منذ ' . ($h === 1 ? 'ساعة' : ($h === 2 ? 'ساعتين' : $h . ' ساعات')); }
    $d = (int) floor($s / 86400);
    return 'منذ ' . ($d === 1 ? 'يوم' : ($d === 2 ? 'يومين' : $d . ' أيام'));
}

/* ═══════════════ ② التحقق بخطوتين (كود على الإيميل) ═══════════════ */

function account_2fa_on(array $user): bool
{
    return !empty($user['two_fa_enabled']);
}

/**
 * يبعت كود 6 أرقام. $purpose: login | enable | disable
 * @return array{ok:bool, error?:string}
 */
function account_2fa_send(array $user, string $purpose): array
{
    require_once __DIR__ . '/mailer.php';
    require_once __DIR__ . '/rate-limit.php';
    $uid = (int) $user['id'];
    if (!rate_limit_ok('2fa_send', 'u' . $uid, 5, 900)) {
        return ['ok' => false, 'error' => 'طلبت أكواد كتير — استنى ربع ساعة وجرّب تاني'];
    }
    rate_limit_hit('2fa_send', 'u' . $uid);
    $code = (string) random_int(100000, 999999);
    $_SESSION['2fa'] = [
        'uid' => $uid, 'purpose' => $purpose, 'tries' => 0, 'exp' => time() + ACCOUNT_2FA_TTL,
        'hash' => password_hash($code, PASSWORD_DEFAULT),
    ];
    $what = ['login' => 'تسجيل الدخول', 'enable' => 'تفعيل التحقق بخطوتين', 'disable' => 'إيقاف التحقق بخطوتين'][$purpose] ?? 'التحقق';
    $name = htmlspecialchars((string) ($user['name'] ?? ''), ENT_QUOTES, 'UTF-8');
    $html = '<div dir="rtl" style="font-family:Tahoma,Arial,sans-serif;font-size:15px;line-height:1.9;color:#0B1526">'
        . "أهلًا {$name}،<br>كود {$what} في Spread AI:"
        . '<div style="font-size:30px;font-weight:700;letter-spacing:8px;margin:14px 0;color:#0A5FCF" dir="ltr">' . $code . '</div>'
        . 'الكود صالح 10 دقايق. لو مش انت اللي طلبته، غيّر كلمة المرور فورًا.</div>';
    if (!send_mail((string) $user['email'], "كود {$what} — Spread AI", $html)) {
        unset($_SESSION['2fa']);
        return ['ok' => false, 'error' => 'تعذّر إرسال الكود على الإيميل — جرّب تاني بعد شوية'];
    }
    return ['ok' => true];
}

/** @return array{ok:bool, error?:string} */
function account_2fa_verify(int $userId, string $purpose, string $code): array
{
    $t = $_SESSION['2fa'] ?? null;
    if (!$t || (int) $t['uid'] !== $userId || $t['purpose'] !== $purpose) {
        return ['ok' => false, 'error' => 'اطلب كود جديد'];
    }
    if (time() > (int) $t['exp']) {
        unset($_SESSION['2fa']);
        return ['ok' => false, 'error' => 'الكود انتهى — اطلب كود جديد'];
    }
    if ((int) $t['tries'] >= ACCOUNT_2FA_TRIES) {
        unset($_SESSION['2fa']);
        return ['ok' => false, 'error' => 'محاولات كتير غلط — اطلب كود جديد'];
    }
    $code = preg_replace('/\D/', '', $code);
    if (strlen($code) !== 6 || !password_verify($code, (string) $t['hash'])) {
        $_SESSION['2fa']['tries'] = (int) $t['tries'] + 1;
        $left = ACCOUNT_2FA_TRIES - (int) $_SESSION['2fa']['tries'];
        return ['ok' => false, 'error' => 'الكود غلط' . ($left > 0 ? " — باقي {$left} محاولات" : '')];
    }
    unset($_SESSION['2fa']);
    return ['ok' => true];
}

/**
 * نقطة الدخول الموحّدة بعد ما الباسورد/جوجل يتأكدوا:
 * لو التحقق بخطوتين شغّال ← كود على الإيميل وصفحة الكود — وإلا دخول عادي
 */
function account_login_or_challenge(array $user, string $next = 'dashboard.php'): void
{
    if (account_2fa_on($user)) {
        $_SESSION['2fa_pending'] = ['uid' => (int) $user['id'], 'next' => $next, 'at' => time()];
        $r = account_2fa_send($user, 'login');
        if (!$r['ok']) flash_set('danger', $r['error']);
        redirect('login-verify.php');
    }
    login_user((int) $user['id']);
}

/* ═══════════════ ③ حذف الحساب ═══════════════ */

function account_deletion_date(array $user): ?string
{
    if (empty($user['deletion_requested_at'])) return null;
    return date('Y-m-d H:i:s', strtotime($user['deletion_requested_at']) + ACCOUNT_DELETE_GRACE_DAYS * 86400);
}

/** مسح نهائي لكل بيانات العميل (نفس اللي الأدمن بيعمله) */
function account_purge(int $uid): void
{
    foreach ([
        'DELETE FROM content_designs WHERE user_id = ?',
        'DELETE FROM studio_designs WHERE user_id = ?',
        'DELETE FROM social_connections WHERE user_id = ?',
        'DELETE FROM user_media_selections WHERE user_id = ?',
        'DELETE FROM user_feature_access WHERE user_id = ?',
        'DELETE FROM agent_sessions WHERE user_id = ?',
        'DELETE FROM plan_ideas WHERE user_id = ?',
        'DELETE FROM content_plans WHERE user_id = ?',
        'DELETE FROM brand_sources WHERE user_id = ?',
        'DELETE FROM contents WHERE user_id = ?',
        'DELETE FROM credit_transactions WHERE user_id = ?',
        'DELETE FROM credit_wallets WHERE user_id = ?',
        'DELETE FROM brand_profiles WHERE user_id = ?',
        'DELETE FROM user_sessions WHERE user_id = ?',
    ] as $sql) {
        try {
            db_run($sql, [$uid]);
        } catch (\Throwable $e) {
            // جدول مش موجود أو مربوط CASCADE — نكمل
        }
    }
    db_run('DELETE FROM users WHERE id = ?', [$uid]);
}

/** الكرون: الطلبات اللي عدّت مهلتها — بيرجّع عدد الحسابات اللي اتمسحت */
function account_purge_due(int $limit = 5): int
{
    try {
        $due = db_all('SELECT id, email FROM users WHERE deletion_requested_at IS NOT NULL
                       AND deletion_requested_at <= NOW() - INTERVAL ' . ACCOUNT_DELETE_GRACE_DAYS . ' DAY LIMIT ' . (int) $limit);
    } catch (\Throwable $e) {
        return 0;
    }
    $n = 0;
    foreach ($due as $u) {
        try {
            account_purge((int) $u['id']);
            $n++;
        } catch (\Throwable $e) {
            error_log('account_purge ' . $u['id'] . ': ' . $e->getMessage());
        }
    }
    return $n;
}

/* ═══════════════ ④ الاستهلاك ═══════════════ */

/**
 * الكريدت المستهلك من آخر شحن مقسوم على أنواع الشغل
 * @return array<int, array{t:string, credits:int, ops:int}>
 */
function account_usage_breakdown(int $userId): array
{
    $groups = [
        'المحتوى'           => ['content', 'regenerate', 'plan_idea', 'ai_edit'],
        'التصميمات'         => ['design', 'studio', 'logo'],
        'الأفكار والخطط'    => ['plan', 'trend_ideas', 'studio_brief'],
        'Brand Brain'       => ['brand'],
    ];
    $last = db_one('SELECT MAX(created_at) t FROM credit_transactions WHERE user_id = ? AND action_type = "add"', [$userId]);
    $rows = db_all('SELECT reference_type rt, notes, ABS(amount) a FROM credit_transactions
                    WHERE user_id = ? AND action_type IN ("consume","deduct") AND created_at >= ?',
        [$userId, $last['t'] ?? '1970-01-01']);
    $out = [];
    foreach (array_keys($groups) as $g) $out[$g] = ['t' => $g, 'credits' => 0, 'ops' => 0];
    $out['أخرى'] = ['t' => 'أخرى', 'credits' => 0, 'ops' => 0];
    foreach ($rows as $r) {
        $g = 'أخرى';
        foreach ($groups as $name => $types) {
            if (in_array((string) $r['rt'], $types, true)) { $g = $name; break; }
        }
        // عمليات قديمة من غير نوع
        if ($g === 'أخرى' && $r['rt'] === null) {
            $n = (string) $r['notes'];
            if (str_contains($n, 'فكرة') || str_contains($n, 'أفكار')) $g = 'الأفكار والخطط';
            elseif (str_contains($n, 'الهوية')) $g = 'Brand Brain';
        }
        $out[$g]['credits'] += (int) $r['a'];
        $out[$g]['ops']++;
    }
    return array_values(array_filter($out, fn($x) => $x['ops'] > 0 || $x['t'] !== 'أخرى'));
}

/** اسم آخر باقة اتدفعت (للشارة) — وإلا «رصيد مجاني» */
function account_plan_label(int $userId): string
{
    try {
        $r = db_one('SELECT p.name FROM payment_orders o JOIN credit_packages p ON p.id = o.package_id
                     WHERE o.user_id = ? AND o.status = "paid" ORDER BY o.paid_at DESC, o.id DESC LIMIT 1', [$userId]);
        if ($r && trim((string) $r['name']) !== '') return (string) $r['name'];
    } catch (\Throwable $e) {}
    return 'رصيد مجاني';
}

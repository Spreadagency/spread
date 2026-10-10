<?php
/**
 * Spread AI v2 — استقبال رد ميتا (OAuth callback) — نسخة محصّنة
 * أي عطل هنا بيظهر كصفحة مفهومة + يتسجل في storage/logs/social-errors.log
 * بدل صفحة 500 فاضية.
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/credits.php';
require_once __DIR__ . '/../../includes/social.php';

social_guard('callback');   // يمسك الأخطاء القاتلة

require_login();
$user = current_user();
feature_require((int) $user['id']);

/* ═══════════════ مرحلة 2: العميل اختار صفحة ═══════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pick_page'])) {
    try {
        require_csrf();

        $pages = $_SESSION['fb_pages_pending'] ?? [];
        if (!$pages) {
            social_log_error('pick_page: الجلسة فقدت قائمة الصفحات (fb_pages_pending فاضية)');
            social_render_error(
                'جلسة الربط انتهت',
                'قائمة الصفحات مش موجودة في الجلسة — غالبًا الجلسة انتهت أو الكوكيز مش بتتحفظ. ابدأ الربط من الأول.'
            );
            exit;
        }

        $pageId = (string) ($_POST['pick_page'] ?? '');
        $chosen = null;
        foreach ($pages as $p) {
            if ((string) ($p['id'] ?? '') === $pageId) {
                $chosen = $p;
                break;
            }
        }
        if (!$chosen) {
            social_render_error('الصفحة المختارة مش موجودة', 'page_id=' . htmlspecialchars($pageId));
            exit;
        }

        // تأكد إن الجداول موجودة (بيصلّح نفسه لو الترقية ما اتنفذتش)
        $fixed = social_ensure_schema();
        if ($fixed) {
            social_log_error('ensure_schema نفّذ: ' . implode(' | ', $fixed));
        }

        // التشفير — لو المفتاح ناقص بنوضح السبب بدل ما نقع
        try {
            $enc = social_token_encrypt((string) ($chosen['access_token'] ?? ''));
        } catch (\Throwable $e) {
            social_log_error('encrypt: ' . $e->getMessage());
            social_render_error(
                'مشكلة في تشفير التوكن',
                $e->getMessage() . ' — تأكد إن ENCRYPTION_KEY (64 حرف hex) موجود في includes/config.php'
            );
            exit;
        }

        // القص حسب الطول الفعلي للعمود في قاعدة البيانات (روابط صور فيسبوك بتوصل 600+ حرف)
        $avatarRaw = (string) ($chosen['picture'] ?? '');
        $avatar = $avatarRaw !== '' ? social_fit($avatarRaw, 'social_connections', 'page_avatar_url', 500) : null;
        $pageName = social_fit((string) ($chosen['name'] ?? 'صفحة'), 'social_connections', 'page_name', 255);
        $igUser = $chosen['ig_user_id'] ?? null;
        $igName = !empty($chosen['ig_username'])
            ? social_fit((string) $chosen['ig_username'], 'social_connections', 'ig_username', 128)
            : null;

        $existing = db_one(
            'SELECT id FROM social_connections WHERE user_id = ? AND platform = "facebook" AND provider_page_id = ?',
            [$user['id'], $pageId]
        );

        if (!$existing && count(user_connections((int) $user['id'], null)) >= feature_max_pages((int) $user['id'])) {
            flash_set('warning', 'وصلت للحد الأقصى من الصفحات المسموح بيها');
            redirect('social-accounts.php');
        }

        // الحفظ — مع محاولة ثانية بدون صورة الصفحة لو حصل خطأ طول (الصورة تحسينية مش أساسية)
        $saveConnection = static function (?string $avatarValue) use ($existing, $enc, $pageName, $igUser, $igName, $user, $pageId) {
            if ($existing) {
                db_run(
                    'UPDATE social_connections SET access_token_enc = ?, page_name = ?, page_avatar_url = ?, ig_user_id = ?, ig_username = ?, status = "active", last_error = NULL, last_verified_at = NOW() WHERE id = ?',
                    [$enc, $pageName, $avatarValue, $igUser, $igName, $existing['id']]
                );
            } else {
                db_insert(
                    'INSERT INTO social_connections (user_id, platform, provider_page_id, page_name, page_avatar_url, ig_user_id, ig_username, access_token_enc, token_type, scopes, status, last_verified_at)
                     VALUES (?, "facebook", ?, ?, ?, ?, ?, ?, "page", ?, "active", NOW())',
                    [$user['id'], $pageId, $pageName, $avatarValue, $igUser, $igName, $enc, (string) ($_SESSION['fb_oauth_scopes'] ?? '')]
                );
            }
        };

        try {
            $saveConnection($avatar);
        } catch (\Throwable $e) {
            // 1406 = Data too long — نعيد المحاولة من غير صورة بدل ما نفشّل الربط كله
            social_log_error('save retry without avatar: ' . $e->getMessage());
            $saveConnection(null);
        }

        flash_set('success', ($existing ? 'تم تجديد ربط «' : 'تم ربط صفحة «') . $pageName . '» بنجاح 🎉'
            . ($igUser ? ' — ومعاها انستجرام @' . ($igName ?? '') : ''));

        unset($_SESSION['fb_pages_pending'], $_SESSION['fb_oauth_scopes']);
        redirect('social-accounts.php');
    } catch (\Throwable $e) {
        social_log_error('pick_page EXCEPTION: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
        social_render_error(
            'تعذّر حفظ الصفحة',
            get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine()
        );
        exit;
    }
}

/* ═══════════════ مرحلة 1: راجعين من ميتا بالكود ═══════════════ */
$error = null;
$errorTech = '';
$pagesToPick = [];

try {
    if (isset($_GET['error'])) {
        $error = 'ميتا رفضت الربط: ' . (string) ($_GET['error_description'] ?? $_GET['error']);
        $errorTech = 'error=' . (string) ($_GET['error'] ?? '') . ' reason=' . (string) ($_GET['error_reason'] ?? '');
    } elseif (isset($_GET['code'])) {
        $state = (string) ($_GET['state'] ?? '');
        $saved = (string) ($_SESSION['fb_oauth_state'] ?? '');

        if ($saved === '' || !hash_equals($saved, $state) || time() - (int) ($_SESSION['fb_oauth_time'] ?? 0) > 900) {
            $error = 'جلسة الربط انتهت أو غير صالحة — ابدأ من جديد';
            $errorTech = $saved === ''
                ? 'الجلسة مفيهاش state — غالبًا الكوكيز مش بتتحفظ أو الجلسة اتمسحت بين الخطوتين'
                : 'state مش مطابق أو عدّى 15 دقيقة';
            social_log_error('state mismatch: saved=' . ($saved === '' ? 'EMPTY' : 'set') . ' got=' . ($state === '' ? 'EMPTY' : 'set'));
        } else {
            unset($_SESSION['fb_oauth_state'], $_SESSION['fb_oauth_time']);

            if (!meta_configured()) {
                $error = 'بيانات تطبيق ميتا ناقصة في الإعدادات';
                $errorTech = 'App ID أو App Secret فاضي/مش بيتفك تشفيره — راجع الأدمن ← التكاملات';
            } else {
                // 1) code → short-lived
                $r1 = graph_request('GET', 'oauth/access_token', [
                    'client_id' => meta_app_id(),
                    'client_secret' => meta_app_secret(),
                    'redirect_uri' => social_callback_url(),
                    'code' => (string) $_GET['code'],
                ], ['action' => 'oauth_code_exchange']);

                if (!$r1['ok']) {
                    $error = 'فشل تبديل الكود مع فيسبوك';
                    $errorTech = 'code#' . ($r1['code'] ?? 0) . ': ' . ($r1['error'] ?? '')
                        . ' | redirect_uri المستخدم: ' . social_callback_url();
                    social_log_error('code_exchange: ' . $errorTech);
                } else {
                    $shortToken = (string) ($r1['data']['access_token'] ?? '');

                    // 2) long-lived
                    $r2 = graph_request('GET', 'oauth/access_token', [
                        'grant_type' => 'fb_exchange_token',
                        'client_id' => meta_app_id(),
                        'client_secret' => meta_app_secret(),
                        'fb_exchange_token' => $shortToken,
                    ], ['action' => 'oauth_long_lived']);
                    $userToken = $r2['ok'] ? (string) ($r2['data']['access_token'] ?? $shortToken) : $shortToken;

                    // 3) الصفحات
                    $r3 = graph_request('GET', 'me/accounts', [
                        'fields' => 'id,name,access_token,picture{url},instagram_business_account{id,username}',
                        'limit' => 50,
                        'access_token' => $userToken,
                    ], ['action' => 'me_accounts']);

                    if (!$r3['ok']) {
                        $error = 'تعذّر جلب صفحاتك من فيسبوك';
                        $errorTech = 'code#' . ($r3['code'] ?? 0) . ': ' . ($r3['error'] ?? '');
                        social_log_error('me_accounts: ' . $errorTech);
                    } else {
                        foreach (($r3['data']['data'] ?? []) as $p) {
                            $pagesToPick[] = [
                                'id' => (string) ($p['id'] ?? ''),
                                'name' => (string) ($p['name'] ?? 'صفحة'),
                                'access_token' => (string) ($p['access_token'] ?? ''),
                                'picture' => $p['picture']['data']['url'] ?? null,
                                'ig_user_id' => $p['instagram_business_account']['id'] ?? null,
                                'ig_username' => $p['instagram_business_account']['username'] ?? null,
                            ];
                        }
                        if (!$pagesToPick) {
                            $error = 'مفيش صفحات فيسبوك متاحة على حسابك';
                            $errorTech = 'لازم تكون أدمن على صفحة واحدة على الأقل. ولو التطبيق لسه في Development Mode، لازم حسابك يكون مضاف كـ Tester من App Roles.';
                        } else {
                            $_SESSION['fb_pages_pending'] = $pagesToPick;
                            $_SESSION['fb_oauth_scopes'] = 'pages_manage_posts,pages_read_engagement,instagram_content_publish';
                            // تأكد إن الجداول جاهزة قبل خطوة الحفظ
                            social_ensure_schema();
                        }
                    }
                }
            }
        }
    } else {
        redirect('social-accounts.php');
    }
} catch (\Throwable $e) {
    social_log_error('callback GET EXCEPTION: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
    social_render_error(
        'خطأ أثناء استقبال رد فيسبوك',
        get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine()
    );
    exit;
}

// لو فيه خطأ: صفحة مستقلة (متعتمدش على القوالب)
if ($error) {
    social_render_error($error, $errorTech);
    exit;
}

/* ═══════════════ شاشة اختيار الصفحة ═══════════════ */
$active = 'social-accounts';
$page_title = 'اختيار الصفحة';
include __DIR__ . '/../../templates/header.php';
?>

<div class="app">
    <?php include __DIR__ . '/../../templates/sidebar.php'; ?>
    <main class="main">
        <?php include __DIR__ . '/../../templates/topbar.php'; ?>

        <div class="page-head">
            <h1>اختر الصفحة 🔗</h1>
            <div class="sub">دي الصفحات اللي انت أدمن عليها — اختار اللي عايز المنصة تنشر عليها</div>
        </div>

        <div class="card" style="max-width:560px">
            <form method="POST" action="<?= e(url('social/callback.php')) ?>">
                <?= csrf_field() ?>
                <div style="display:grid;gap:10px">
                    <?php foreach ($pagesToPick as $p): ?>
                        <label style="display:flex;align-items:center;gap:12px;border:1.5px solid var(--line);border-radius:14px;padding:12px;cursor:pointer">
                            <input type="radio" name="pick_page" value="<?= e($p['id']) ?>" required style="accent-color:var(--primary)">
                            <?php if ($p['picture']): ?>
                                <img src="<?= e($p['picture']) ?>" style="width:44px;height:44px;border-radius:12px;object-fit:cover" alt="">
                            <?php else: ?>
                                <div style="width:44px;height:44px;border-radius:12px;background:var(--primary-soft);display:grid;place-items:center">📘</div>
                            <?php endif; ?>
                            <div style="flex:1;min-width:0">
                                <b><?= e($p['name']) ?></b>
                                <div class="sub" style="font-size:12px" dir="ltr">
                                    <?= e($p['id']) ?>
                                    <?php if ($p['ig_user_id']): ?> · 📸 @<?= e($p['ig_username'] ?? 'instagram') ?><?php endif; ?>
                                </div>
                            </div>
                        </label>
                    <?php endforeach; ?>
                </div>
                <button type="submit" class="btn full" style="margin-top:14px">✓ اربط الصفحة دي</button>
            </form>
        </div>

    </main>
</div>

<?php include __DIR__ . '/../../templates/footer.php'; ?>

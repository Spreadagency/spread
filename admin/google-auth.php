<?php
/**
 * Spread AI v2 — الأدمن: إعدادات الدخول بجوجل
 */
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/credits.php';
require_once __DIR__ . '/../includes/google-auth.php';

require_admin();
require_admin_can('site_settings');

$testResult = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    decode_b64_fields();
    $action = $_POST['action'] ?? 'save';

    if ($action === 'save') {
        $cid = trim((string) ($_POST['client_id'] ?? ''));
        set_setting('google_client_id', mb_substr($cid, 0, 255));

        $sec = trim((string) ($_POST['client_secret'] ?? ''));
        if ($sec !== '' && $sec !== '••••••••') {
            set_setting('google_client_secret_enc', Crypto::encrypt($sec));
        }
        set_setting('google_login_enabled', !empty($_POST['enabled']) ? '1' : '0');
        set_setting('google_auto_approve', !empty($_POST['auto_approve']) ? '1' : '0');
        admin_log('save_google_auth', 'settings');
        flash_set('success', 'تم حفظ الإعدادات ✓');
        redirect('admin/google-auth.php');
    }

    if ($action === 'clear') {
        set_setting('google_client_secret_enc', '');
        set_setting('google_login_enabled', '0');
        flash_set('success', 'تم مسح البيانات وإيقاف الخدمة');
        redirect('admin/google-auth.php');
    }

    if ($action === 'test') {
        $checks = [];
        $cid = google_client_id();
        $sec = google_client_secret();

        $checks[] = ['Client ID مضبوط', $cid !== '', $cid !== '' ? mb_substr($cid, 0, 22) . '…' : 'فاضي'];
        $checks[] = ['صيغة Client ID صحيحة', str_ends_with($cid, '.apps.googleusercontent.com'),
                     str_ends_with($cid, '.apps.googleusercontent.com') ? 'تمام' : 'لازم ينتهي بـ .apps.googleusercontent.com'];
        $checks[] = ['Client Secret محفوظ', $sec !== '', $sec !== '' ? 'موجود ومشفّر' : 'فاضي'];
        $checks[] = ['امتداد cURL', function_exists('curl_init'), function_exists('curl_init') ? 'موجود' : 'ناقص — كلّم الاستضافة'];
        $checks[] = ['الموقع يعمل بـ HTTPS', str_starts_with(APP_URL, 'https://'),
                     str_starts_with(APP_URL, 'https://') ? 'تمام' : '⚠️ جوجل بيرفض http إلا localhost'];

        // اتصال فعلي بجوجل
        $reach = false;
        $detail = '';
        if (function_exists('curl_init')) {
            $ch = curl_init(GOOGLE_TOKENINFO . '?id_token=invalid');
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 12]);
            $r = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);
            $reach = $r !== false && $code > 0;
            $detail = $reach ? 'السيرفر بيوصل لجوجل (HTTP ' . $code . ')' : 'فشل: ' . $err;
        }
        $checks[] = ['الاتصال بخوادم جوجل', $reach, $detail];

        $testResult = $checks;
    }
}

$redirectUri = google_redirect_uri();
$origin = rtrim(APP_URL, '/');

$stats = db_one('SELECT
    SUM(auth_provider = "google") gonly,
    SUM(auth_provider = "both") both_,
    SUM(google_id IS NOT NULL) linked,
    COUNT(*) total FROM users') ?: [];

$recent = db_all('SELECT id, name, email, avatar_url, auth_provider, created_at
                  FROM users WHERE google_id IS NOT NULL ORDER BY id DESC LIMIT 10');

$active = 'google-auth';
$page_title = 'الدخول بجوجل';
include __DIR__ . '/../templates/admin-header.php';
?>

<div class="app">
    <?php include __DIR__ . '/../templates/admin-sidebar.php'; ?>
    <main class="main">
        <div class="page-head">
            <h1>الدخول بجوجل 🔐</h1>
            <div class="sub">خلّي العملاء يسجّلوا بضغطة واحدة — بدون كلمة مرور</div>
        </div>

        <?= render_flash() ?>

        <div class="card" style="border:2px solid <?= google_enabled() ? '#2a7d5f' : '#c0392b' ?>;
             background:<?= google_enabled() ? '#eefaf4' : '#fdecea' ?>">
            <b style="font-size:15.5px">
                <?= google_enabled() ? '✅ الدخول بجوجل شغّال' : '🔴 مش مفعّل — اتبع الخطوات تحت' ?>
            </b>
            <div class="sub" style="margin-top:6px;font-size:13px">
                <?= (int) ($stats['linked'] ?? 0) ?> حساب مربوط بجوجل ·
                <?= (int) ($stats['gonly'] ?? 0) ?> بجوجل بس ·
                <?= (int) ($stats['both_'] ?? 0) ?> بالطريقتين
            </div>
            <form method="POST" style="margin-top:12px">
                <?= csrf_field() ?><input type="hidden" name="action" value="test">
                <button class="btn">🧪 افحص الإعدادات</button>
            </form>
        </div>

        <?php if ($testResult): ?>
            <div class="card">
                <div class="card-head"><h3>نتيجة الفحص</h3></div>
                <div class="table-wrap">
                <table class="table"><tbody>
                    <?php foreach ($testResult as [$lbl, $ok, $detail]): ?>
                        <tr>
                            <td style="width:34px;font-size:16px"><?= $ok ? '✅' : '❌' ?></td>
                            <td><b><?= e($lbl) ?></b></td>
                            <td class="sub" style="font-size:12.5px" dir="auto"><?= e($detail) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody></table>
                </div>
            </div>
        <?php endif; ?>

        <div class="split split-2" style="align-items:flex-start">
            <div class="card">
                <div class="card-head"><h3>🔑 بيانات التطبيق</h3></div>
                <form method="POST" data-safe-post>
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save">

                    <div class="field">
                        <label>Client ID</label>
                        <input type="text" name="client_id" class="input" dir="ltr" data-no-encode="1"
                               value="<?= e(google_client_id()) ?>"
                               placeholder="123456-abc.apps.googleusercontent.com">
                    </div>

                    <div class="field">
                        <label>Client Secret <span class="sub" style="font-size:11px">(بيتخزن مشفّر)</span></label>
                        <input type="password" name="client_secret" class="input" dir="ltr"
                               autocomplete="new-password" data-no-encode="1"
                               placeholder="<?= google_client_secret() !== '' ? '•••••••• (محفوظ)' : 'GOCSPX-...' ?>">
                    </div>

                    <label style="display:flex;gap:9px;align-items:center;cursor:pointer;margin-bottom:11px">
                        <input type="checkbox" name="enabled" value="1" <?= get_setting('google_login_enabled', '0') === '1' ? 'checked' : '' ?> style="width:auto">
                        <span><b>تفعيل الدخول بجوجل</b><br><span class="sub" style="font-size:11.5px">الزرار هيظهر في صفحتي الدخول والتسجيل</span></span>
                    </label>

                    <label style="display:flex;gap:9px;align-items:center;cursor:pointer;margin-bottom:16px">
                        <input type="checkbox" name="auto_approve" value="1" <?= get_setting('google_auto_approve', '1') === '1' ? 'checked' : '' ?> style="width:auto">
                        <span><b>اعتماد حسابات جوجل تلقائيًا</b><br><span class="sub" style="font-size:11.5px">جوجل متحقق من الإيميل أصلًا — لو قفلته هيستنى موافقتك</span></span>
                    </label>

                    <button class="btn full">💾 حفظ</button>
                </form>

                <?php if (google_client_secret() !== ''): ?>
                    <form method="POST" style="margin-top:8px">
                        <?= csrf_field() ?><input type="hidden" name="action" value="clear">
                        <button class="btn ghost sm" style="color:#c0392b">🗑 مسح البيانات وإيقاف الخدمة</button>
                    </form>
                <?php endif; ?>
            </div>

            <div class="card">
                <div class="card-head"><h3>📋 خطوات الإعداد في جوجل</h3></div>
                <ol style="margin:0 20px;font-size:13.5px;line-height:2.1">
                    <li>افتح <a href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener" style="color:var(--primary)">Google Cloud Console</a></li>
                    <li>اعمل مشروع جديد (أو اختار واحد موجود)</li>
                    <li>من <b>OAuth consent screen</b>: اختار <b>External</b> واملأ اسم التطبيق وإيميل الدعم</li>
                    <li>في <b>Scopes</b> اختار: <code>email</code> · <code>profile</code> · <code>openid</code></li>
                    <li>من <b>Credentials</b> ← <b>Create Credentials</b> ← <b>OAuth client ID</b> ← <b>Web application</b></li>
                    <li>حط القيمتين دول بالظبط 👇</li>
                    <li>انسخ الـ Client ID والـ Secret وحطهم هنا</li>
                </ol>

                <div class="field" style="margin-top:14px">
                    <label>Authorized JavaScript origins</label>
                    <div style="display:flex;gap:7px">
                        <input type="text" class="input" id="g-origin" dir="ltr" readonly value="<?= e($origin) ?>">
                        <button type="button" class="btn ghost sm" onclick="cpy('g-origin', this)">📋</button>
                    </div>
                </div>

                <div class="field">
                    <label>Authorized redirect URIs</label>
                    <div style="display:flex;gap:7px">
                        <input type="text" class="input" id="g-redir" dir="ltr" readonly value="<?= e($redirectUri) ?>">
                        <button type="button" class="btn ghost sm" onclick="cpy('g-redir', this)">📋</button>
                    </div>
                    <div class="field-help">⚠️ لازم يتطابق حرف بحرف — أي اختلاف وجوجل هيرفض بخطأ <code>redirect_uri_mismatch</code></div>
                </div>

                <div class="card" style="background:#fdf6e3;border-color:#f0c36d;margin-top:14px">
                    <b>⚠️ بيجيلك Error 403 بعد ما جوجل يرجّعك؟</b>
                    <p style="margin:7px 0 0;font-size:13.5px;line-height:1.95">
                        ده mod_security — مش مشكلة في الإعداد. جوجل بيرجّع في الرابط
                        <code>scope=...https://www.googleapis.com/...</code> وده بيستفز
                        قاعدة الحماية فبترفض الطلب <b>قبل ما PHP يشتغل</b>.
                    </p>
                    <a href="<?= url('auth/ms-test.php') ?>" target="_blank" rel="noopener" class="btn sm" style="margin-top:10px">
                        🔍 افحص المسار
                    </a>
                    <div class="field-help" style="margin-top:8px">
                        الحل: ملف <code>public/auth/.htaccess</code> (مرفق) · أو اقفل ModSecurity
                        للدومين من cPanel · أو كلّم الاستضافة يستثنوا القاعدة 931130.
                    </div>
                </div>

                <?php if (!str_starts_with(APP_URL, 'https://')): ?>
                    <div class="card" style="background:#fdf6e3;border-color:#f0c36d;margin-top:12px">
                        ⚠️ موقعك شغّال على <code>http</code> — جوجل بيرفض غير <code>https</code> (إلا localhost للتجربة).
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($recent): ?>
        <div class="card">
            <div class="card-head"><h3>آخر الحسابات المربوطة</h3></div>
            <div class="table-wrap">
            <table class="table">
                <thead><tr><th></th><th>الاسم</th><th>الإيميل</th><th>طريقة الدخول</th><th>التاريخ</th></tr></thead>
                <tbody>
                <?php foreach ($recent as $u): ?>
                    <tr>
                        <td><?php if ($u['avatar_url']): ?>
                            <img src="<?= e($u['avatar_url']) ?>" alt="" referrerpolicy="no-referrer"
                                 style="width:32px;height:32px;border-radius:50%;object-fit:cover">
                        <?php endif; ?></td>
                        <td><b><?= e($u['name']) ?></b></td>
                        <td dir="ltr" style="text-align:start;font-size:12.5px"><?= e($u['email']) ?></td>
                        <td><span class="chip"><?= ['google' => 'جوجل بس', 'both' => 'جوجل + كلمة مرور', 'password' => 'كلمة مرور'][$u['auth_provider']] ?? '' ?></span></td>
                        <td class="sub" style="font-size:12px"><?= e(fmt_date($u['created_at'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
        <?php endif; ?>

        <script>
        function cpy(id, btn) {
            const el = document.getElementById(id);
            navigator.clipboard.writeText(el.value).then(() => {
                btn.textContent = '✓'; setTimeout(() => btn.textContent = '📋', 1500);
            }).catch(() => { el.select(); document.execCommand('copy'); btn.textContent = '✓'; });
        }
        </script>
    </main>
</div>

<?php include __DIR__ . '/../templates/admin-footer.php'; ?>

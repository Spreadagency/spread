<?php
/**
 * Spread AI v2 — الأدمن: ربط الذكاء الاصطناعي (الطريقة المباشرة) + اختبار حقيقي
 */
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/credits.php';
require_once __DIR__ . '/../includes/crypto.php';
require_once __DIR__ . '/../includes/ai.php';

require_admin();
require_admin_can('ai_settings');

$testResult = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    decode_b64_fields();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_key') {
        $key = trim($_POST['api_key'] ?? '');
        $provider = in_array($_POST['ai_provider'] ?? '', ['openrouter', 'openai'], true) ? $_POST['ai_provider'] : 'openrouter';
        set_setting('ai_provider', $provider);
        if ($key !== '' && $key !== '••••••••') {
            set_setting('ai_api_key_enc', Crypto::encrypt($key));
        }
        $model = trim($_POST['ai_model'] ?? '');
        if ($model !== '') {
            set_setting('ai_model', $model);
        }
        $imgModel = trim($_POST['ai_image_model'] ?? '');
        if ($imgModel !== '') {
            set_setting('ai_image_model', $imgModel);
        }
        set_setting('allow_mock', !empty($_POST['allow_mock']) ? '1' : '0');
        admin_log('save_ai_key', 'settings', null, $provider);
        flash_set('success', 'تم حفظ بيانات الاتصال ✓ — اضغط «اختبار الاتصال» للتأكد');
        redirect('admin/ai-connection.php');
    }

    if ($action === 'clear_key') {
        set_setting('ai_api_key_enc', '');
        flash_set('success', 'تم مسح المفتاح');
        redirect('admin/ai-connection.php');
    }

    if ($action === 'test') {
        $t0 = microtime(true);
        $r = ai_generate('اكتب كلمة واحدة فقط: تم');
        $ms = (int) round((microtime(true) - $t0) * 1000);
        $testResult = [
            'ok' => $r['ok'] && ($r['model'] ?? '') !== 'mock',
            'model' => $r['model'] ?? '—',
            'ms' => $ms,
            'response' => mb_substr((string) ($r['response'] ?? ''), 0, 200),
            'error' => $r['error'] ?? null,
        ];
    }
}

$cred = ai_effective_credentials();
$smartOn = function_exists('smart_ai_enabled') && smart_ai_enabled();
$providers = [];
try {
    $providers = db_all('SELECT provider_name, status, api_key_encrypted IS NOT NULL AND api_key_encrypted != "" AS has_key FROM ai_providers');
} catch (\Throwable $e) {
}

$sourceLabels = [
    'config.php' => 'ملف config.php',
    'admin_settings' => 'إعدادات الأدمن (الصفحة دي)',
    'ai_providers' => 'موفر من «إدارة الـ AI»',
    'none' => '❌ مفيش مفتاح',
];

$active = 'ai-connection';
$page_title = 'ربط الذكاء الاصطناعي';
include __DIR__ . '/../templates/admin-header.php';
?>

<div class="app">
    <?php include __DIR__ . '/../templates/admin-sidebar.php'; ?>
    <main class="main">
        <div class="page-head">
            <h1>ربط الذكاء الاصطناعي 🔌</h1>
            <div class="sub">أسرع طريقة تشغّل المنصة بمحتوى حقيقي</div>
        </div>

        <?= render_flash() ?>

        <!-- الحالة الحالية -->
        <div class="card" style="margin-bottom:20px;border:2px solid <?= $cred['key'] !== '' ? '#2a7d5f' : '#c0392b' ?>;
             background:<?= $cred['key'] !== '' ? '#eefaf4' : '#fdecea' ?>">
            <b style="font-size:15px">
                <?= $cred['key'] !== '' ? '✅ المنصة مربوطة بالذكاء الاصطناعي' : '🔴 مفيش مفتاح — المحتوى مش هيتولد' ?>
            </b>
            <div class="table-wrap" style="margin-top:10px">
                <table class="table">
                    <tbody>
                        <tr><td class="sub">مصدر المفتاح</td><td><b><?= e($sourceLabels[$cred['source']] ?? $cred['source']) ?></b></td></tr>
                        <tr><td class="sub">الموفر</td><td><b><?= e($cred['provider'] ?: '—') ?></b></td></tr>
                        <tr><td class="sub">رابط الـ API</td><td dir="ltr" style="font-size:12px"><?= e($cred['url'] ?: '—') ?></td></tr>
                        <tr><td class="sub">Smart Router</td><td><?= $smartOn ? '✅ مفعّل' : '⚪ مقفول (المسار المباشر شغال)' ?></td></tr>
                        <tr><td class="sub">الموديل النصي</td><td dir="ltr"><b><?= e((string) get_setting('ai_model', defined('AI_MODEL') ? AI_MODEL : '—')) ?></b></td></tr>
                        <tr><td class="sub">موديل الصور</td><td dir="ltr"><b><?= e((string) get_setting('ai_image_model', 'google/gemini-2.5-flash-image')) ?></b></td></tr>
                    </tbody>
                </table>
            </div>

            <form method="POST" style="margin-top:12px">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="test">
                <button class="btn">🧪 اختبار الاتصال الحقيقي</button>
            </form>
        </div>

        <?php if ($testResult): ?>
            <div class="card" style="margin-bottom:20px;border:2px solid <?= $testResult['ok'] ? '#2a7d5f' : '#c0392b' ?>">
                <b><?= $testResult['ok'] ? '✅ الاتصال شغال!' : '❌ الاتصال فشل' ?></b>
                <div class="table-wrap" style="margin-top:8px">
                    <table class="table"><tbody>
                        <tr><td class="sub">الموديل</td><td dir="ltr"><b><?= e($testResult['model']) ?></b></td></tr>
                        <tr><td class="sub">زمن الرد</td><td><?= (int) $testResult['ms'] ?> ملّي ثانية</td></tr>
                        <?php if ($testResult['response']): ?>
                            <tr><td class="sub">رد الموديل</td><td><?= e($testResult['response']) ?></td></tr>
                        <?php endif; ?>
                        <?php if ($testResult['error']): ?>
                            <tr><td class="sub">الخطأ</td><td style="color:#c0392b"><?= e($testResult['error']) ?></td></tr>
                        <?php endif; ?>
                    </tbody></table>
                </div>
                <?php if (($testResult['model'] ?? '') === 'mock'): ?>
                    <p style="color:#c0392b;margin-top:8px">⚠️ الرد جه من الـ mock مش من الـ AI — المفتاح لسه مش شغال.</p>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="split split-2">
            <div class="card">
                <div class="card-head"><h3>🔑 المفتاح والموديلات</h3></div>
                <form method="POST" data-safe-post>
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save_key">

                    <div class="field">
                        <label>الموفر</label>
                        <select name="ai_provider" class="input">
                            <option value="openrouter" <?= get_setting('ai_provider', 'openrouter') === 'openrouter' ? 'selected' : '' ?>>OpenRouter (موصى به — كل الموديلات بمفتاح واحد)</option>
                            <option value="openai" <?= get_setting('ai_provider', '') === 'openai' ? 'selected' : '' ?>>OpenAI مباشرة</option>
                        </select>
                    </div>

                    <div class="field">
                        <label>مفتاح الـ API <span class="sub" style="font-size:11px">(بيتخزن مشفّر AES-256)</span></label>
                        <input type="password" name="api_key" class="input" dir="ltr" autocomplete="new-password"
                               placeholder="<?= get_setting('ai_api_key_enc', '') ? '•••••••• (محفوظ)' : 'sk-or-v1-...' ?>" data-no-encode="1">
                        <div class="field-help">من <a href="https://openrouter.ai/keys" target="_blank" rel="noopener">openrouter.ai/keys</a> أو <a href="https://platform.openai.com/api-keys" target="_blank" rel="noopener">platform.openai.com</a></div>
                    </div>

                    <div class="field">
                        <label>موديل الكتابة</label>
                        <input type="text" name="ai_model" class="input" dir="ltr" data-no-encode="1"
                               value="<?= e((string) get_setting('ai_model', defined('AI_MODEL') ? AI_MODEL : 'openai/gpt-4o-mini')) ?>">
                        <div class="field-help">مقترح: <code>openai/gpt-4o-mini</code> · <code>google/gemini-2.0-flash-001</code></div>
                    </div>

                    <div class="field">
                        <label>موديل الصور</label>
                        <input type="text" name="ai_image_model" class="input" dir="ltr" data-no-encode="1"
                               value="<?= e((string) get_setting('ai_image_model', 'google/gemini-2.5-flash-image')) ?>">
                        <div class="field-help">مقترح: <code>google/gemini-2.5-flash-image</code> · <code>openai/gpt-image-1</code></div>
                    </div>

                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;margin-bottom:14px">
                        <input type="checkbox" name="allow_mock" <?= get_setting('allow_mock', '0') === '1' ? 'checked' : '' ?>>
                        <span>السماح بالمحتوى الوهمي لو المفتاح ناقص <span class="sub" style="font-size:11px">(للتجربة فقط — الأفضل يفضل مقفول)</span></span>
                    </label>

                    <button class="btn full">💾 حفظ</button>
                </form>

                <?php if (get_setting('ai_api_key_enc', '')): ?>
                    <form method="POST" style="margin-top:8px">
                        <?= csrf_field() ?><input type="hidden" name="action" value="clear_key">
                        <button class="btn ghost sm" style="color:#c0392b">🗑 مسح المفتاح</button>
                    </form>
                <?php endif; ?>
            </div>

            <div class="card">
                <div class="card-head"><h3>ℹ️ إزاي بيشتغل</h3></div>
                <p style="font-size:13.5px;line-height:2">
                    النظام بيدوّر على المفتاح بالترتيب ده:
                </p>
                <ol style="margin:8px 20px;font-size:13px;line-height:2.1">
                    <li><b>config.php</b> — لو <code>AI_API_KEY</code> متحطوط فيه</li>
                    <li><b>الصفحة دي</b> — أسهل وأسرع طريقة ✅</li>
                    <li><b>إدارة الـ AI</b> — أول موفر نشط له مفتاح</li>
                </ol>
                <p style="font-size:13.5px;line-height:2">
                    يعني <b>مش لازم تفعّل الـ Smart Router</b> عشان المنصة تشتغل — لكن لو فعّلته هتقدر تحدد موديل مختلف لكل مهمة وسلسلة بدائل.
                </p>
                <?php if ($providers): ?>
                    <div style="border-top:1px dashed var(--line);margin-top:12px;padding-top:12px">
                        <b style="font-size:13px">الموفرين في «إدارة الـ AI»:</b>
                        <ul style="margin:6px 20px;font-size:12.5px;line-height:1.9">
                            <?php foreach ($providers as $pr): ?>
                                <li dir="ltr" style="text-align:start">
                                    <?= e($pr['provider_name']) ?> —
                                    <?= $pr['status'] === 'active' ? 'نشط' : 'موقوف' ?> ·
                                    <?= $pr['has_key'] ? 'له مفتاح ✓' : 'مفيش مفتاح ✕' ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
            </div>
        </div>

    </main>
</div>

<?php include __DIR__ . '/../templates/admin-footer.php'; ?>

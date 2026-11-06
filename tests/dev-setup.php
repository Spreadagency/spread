<?php
/**
 * Spread AI — تجهيز البيئة المحلية للاختبارات (مرة واحدة بعد ما القاعدة تتعمل) — للتطوير المحلي بس
 *
 *   php tests/dev-setup.php
 *
 * بيعمل (ومفيش ضرر لو اتشغّل تاني):
 *   - الترحيلات كلها (نفس «فحص التحديث» في الأدمن)
 *   - أدمن owner@app.test · عميل sub@test.local (باقة + كريدت + براند + المحرك مفتوح) · عميل free@test.local (من غير باقة)
 *     الباسورد للكل: Test12345!
 *   - مزوّد AI واحد شغال = الخادم الوهمي tests/mock-ai.php على http://127.0.0.1:8090/v1 (والباقيين مقفولين) + تشغيل بوابة الـ AI
 * مابيشتغلش غير لو APP_DEBUG = true (يعني مستحيل يشتغل على الموقع الحقيقي).
 */
if (PHP_SAPI !== 'cli') exit("CLI only\n");
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require_once __DIR__ . '/../includes/config.php';
if (!defined('APP_DEBUG') || !APP_DEBUG) exit("APP_DEBUG مقفول — التجهيز ده للتطوير المحلي بس\n");
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/credits.php';
require_once __DIR__ . '/../includes/plans.php';
require_once __DIR__ . '/../includes/migrations.php';
require_once __DIR__ . '/../includes/crypto.php';

$m = migrations_run_pending('dev-setup');
echo 'الترحيلات: ' . (empty($m['ran']) ? 'كلها متشغّلة' : implode(', ', $m['ran'])) . "\n";

$pw = password_hash('Test12345!', PASSWORD_DEFAULT);
if (!db_one('SELECT id FROM admin_users WHERE email = ?', ['owner@app.test'])) {
    db_insert('INSERT INTO admin_users (name, email, password, role, status) VALUES (?,?,?,?,?)', ['Owner', 'owner@app.test', $pw, 'super', 'active']);
}
$mk = function (string $email, string $name) use ($pw): int {
    $u = db_one('SELECT id FROM users WHERE email = ?', [$email]);
    if ($u) return (int) $u['id'];
    return (int) db_insert('INSERT INTO users (name, email, password, status, email_verified_at, approval_status, approved_at, created_at)
                            VALUES (?,?,?, "active", NOW(), "approved", NOW(), NOW())', [$name, $email, $pw]);
};
$sub = $mk('sub@test.local', 'عميل تجريبي');
$free = $mk('free@test.local', 'عميل مجاني');
if (!db_one('SELECT id FROM brand_profiles WHERE user_id = ?', [$sub])) {
    db_insert('INSERT INTO brand_profiles (user_id, business_name, industry, description, audience, tone, colors, dialect, services, created_at)
               VALUES (?,?,?,?,?,?,?,?,?, NOW())',
        [$sub, 'عيادة سمايل', 'عيادة أسنان', 'عيادة أسنان في مدينة نصر: تقويم وتبييض وزراعة', 'شباب وعائلات 25-45', 'ودود ومطمّن', '#0C87EF, #2EE3CC', 'egyptian', 'تقويم · تبييض · زراعة · حشو']);
}
if (!db_one('SELECT id FROM user_plans WHERE user_id = ? AND status = "active" AND ends_at > NOW()', [$sub])) {
    $pkg = db_one('SELECT * FROM credit_packages WHERE is_active = 1 ORDER BY credits DESC LIMIT 1');
    if ($pkg) plan_start($sub, (int) $pkg['id'], (int) $pkg['credits'], 30, 'admin', null, 'dev-setup');
}
if (credits_balance($sub) < 300) credits_add($sub, 400, 'dev-setup', null, 'admin_add', null);
db_run('INSERT IGNORE INTO user_feature_access (user_id, feature_key, is_enabled) VALUES (?, "content_engine", 1), (?, "social_publishing", 1)', [$sub, $sub]);
// ملف البراند المختصر (DNA) من الهوية مباشرة — من غير AI (بعض الاختبارات محتاجاه جاهز)
require_once __DIR__ . '/../includes/ce-dna.php';
if (function_exists('ce_dna_ready') ? ce_dna_ready() : true) {
    if (!ce_dna_get($sub)) ce_dna_save($sub, ce_dna_trim(ce_dna_clean($sub, ce_dna_from_kit($sub))), 'rules');
}

// ── مزوّد الـ AI الوهمي ──
$mockBase = 'http://127.0.0.1:8090/v1';
$enc = Crypto::encrypt('mock-key-local-only');
$caps = ['supports_text' => 1, 'supports_image' => 1, 'supports_json' => 1, 'supports_vision' => 1, 'supports_web' => 1, 'supports_image_edit' => 1];
$models = json_encode(['text' => ['mock-text'], 'image' => ['mock-image'], 'web' => ['mock-text']]);
$row = db_one('SELECT id FROM ai_providers WHERE provider_name = "openai"');
if ($row) {
    db_run('UPDATE ai_providers SET api_base_url = ?, api_key_encrypted = ?, status = "active", priority = 1, kind = "openai", label = "Mock (local)", models_json = ?,
            supports_text = 1, supports_image = 1, supports_json = 1, supports_vision = 1, supports_web = 1, supports_image_edit = 1 WHERE id = ?',
        [$mockBase, $enc, $models, (int) $row['id']]);
    $pid = (int) $row['id'];
} else {
    $pid = (int) db_insert('INSERT INTO ai_providers (provider_name, api_base_url, api_key_encrypted, status, is_default, priority, kind, label, models_json, '
        . implode(', ', array_keys($caps)) . ', created_at) VALUES ("openai", ?, ?, "active", 1, 1, "openai", "Mock (local)", ?, 1,1,1,1,1,1, NOW())', [$mockBase, $enc, $models]);
}
db_run('UPDATE ai_providers SET status = "disabled" WHERE id <> ?', [$pid]);
set_setting('ai_gateway_enabled', '1');
// سجل الموديلات: موديلين نص (علشان اختبارات الموديلات والمستويات تلاقي بديل) + صورة + رؤية — بأسعار وهمية
foreach ([['mock-text', 'text', 0, 0.15, 0.60, null], ['mock-text-pro', 'text', 1, 2.50, 10.00, null],
          ['mock-vision', 'vision', 1, 0.15, 0.60, null], ['mock-image', 'image', 0, null, null, 0.04]] as [$mdl, $type, $vis, $pin, $pout, $pimg]) {
    if (!db_one('SELECT id FROM ai_model_registry WHERE provider_name = "openai" AND model = ?', [$mdl])) {
        db_insert('INSERT INTO ai_model_registry (provider_name, model, label, type, supports_vision, supports_json, price_in_1m, price_out_1m, price_image, enabled, source, created_at)
                   VALUES ("openai", ?, ?, ?, ?, 1, ?, ?, ?, 1, "manual", NOW())', [$mdl, $mdl, $type, $vis, $pin, $pout, $pimg]);
    }
}
echo "أدمن owner@app.test · عميل #$sub (رصيد " . credits_balance($sub) . ") · عميل مجاني #$free · مزوّد AI وهمي #$pid ← $mockBase\n";

<?php
/**
 * Spread AI — اختبارات إصلاحات Content Engine (المرحلة B)
 * للتطوير المحلي بس: بيعمل بيانات تجريبية ويمسحها في الآخر — ماتشغّلوش على السيرفر الحقيقي.
 *
 *   php tests/ce-b-fixes.php http://localhost:8080
 *
 * محتاج: حساب عميل sub@test.local وأدمن owner@app.test (الباسورد Test12345!) وصلاحية TRIGGER على القاعدة.
 */
if (PHP_SAPI !== 'cli') exit("CLI only\n");
$base = rtrim($argv[1] ?? 'http://localhost:8080', '/');
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require_once __DIR__ . '/../includes/config.php';
if (!APP_DEBUG) exit("APP_DEBUG مقفول — الاختبارات دي للتطوير المحلي بس\n");
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/credits.php';
require_once __DIR__ . '/../includes/offers.php';
require_once __DIR__ . '/../includes/account.php';
require_once __DIR__ . '/../includes/trial-brand.php';
require_once __DIR__ . '/../includes/crypto.php';

$pass = 0; $fail = 0;
function t(string $name, bool $ok, string $why = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? '  ✓ ' : '  ✗ ') . $name . ($ok || $why === '' ? '' : " — $why") . "\n";
}
function http(string $method, string $url, array|string $post = [], ?string $jar = null, array $headers = []): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => false, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 30, CURLOPT_HTTPHEADER => $headers]);
    if ($jar) { curl_setopt($ch, CURLOPT_COOKIEJAR, $jar); curl_setopt($ch, CURLOPT_COOKIEFILE, $jar); }
    if ($method === 'POST') { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($post) ? http_build_query($post) : $post); }
    $body = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $body];
}
function login(string $base, string $path, string $email, string $pw): string
{
    $jar = tempnam(sys_get_temp_dir(), 'ceb');
    [, $html] = http('GET', "$base/$path", [], $jar);
    preg_match('/name="csrf" value="([^"]+)"/', $html, $m);
    http('POST', "$base/$path", ['csrf' => $m[1] ?? '', 'email' => $email, 'password' => $pw], $jar);
    return $jar;
}
function tmp_user(string $tag): int
{
    return (int) db_insert('INSERT INTO users (name, email, password, status, created_at) VALUES (?,?,?,"active",NOW())',
        ['CE-B test ' . $tag, 'ceb-' . $tag . '-' . bin2hex(random_bytes(3)) . '@test.local', password_hash('x', PASSWORD_DEFAULT)]);
}
db_run('DELETE FROM rate_limits');

/* ─── 1) js_str: نص في onclick مايقدرش يكسر الـ JS ولا الـ HTML ─── */
echo "js_str (XSS في confirm/onclick)\n";
$evil = "x'); alert(1); ('</script><b \"q\">&";
$out = js_str($evil);
t('مفيش علامة \' أو " أو < خام', !preg_match('/[\'"<>]/', $out), $out);
t('بيرجع نفس النص بعد فك HTML و JSON', json_decode(html_entity_decode($out, ENT_QUOTES)) === $evil);

/* ─── 2) Crypto: المفتاح القديم بيفك والجديد بيشفّر ─── */
echo "تغيير مفتاح التشفير\n";
$old = bin2hex(random_bytes(32)); $new = bin2hex(random_bytes(32));
$probe = <<<'PHP'
[$old, $new, $mode] = array_slice($argv, 1);
if ($mode === 'enc') { define('ENCRYPTION_KEY', $old); require 'includes/crypto.php'; echo Crypto::encrypt('sk-secret-123'); exit; }
define('ENCRYPTION_KEY', $new); define('ENCRYPTION_KEY_PREV', $old); require 'includes/crypto.php';
$p = stream_get_contents(STDIN);
echo json_encode(['which' => Crypto::which($p), 'dec' => Crypto::decrypt($p), 're' => Crypto::which(Crypto::encrypt(Crypto::decrypt($p))), 'pub' => Crypto::usingPublicKey()]);
PHP;
$root = dirname(__DIR__);
$cipher = shell_exec('cd ' . escapeshellarg($root) . ' && php -r ' . escapeshellarg($probe) . " -- $old $new enc");
$r = json_decode((string) shell_exec('cd ' . escapeshellarg($root) . ' && printf %s ' . escapeshellarg((string) $cipher) . ' | php -r ' . escapeshellarg($probe) . " -- $old $new dec"), true);
t('القيمة القديمة متعرّفة إنها بالمفتاح القديم', ($r['which'] ?? '') === 'prev', json_encode($r));
t('بتتفك بالمفتاح القديم تلقائي', ($r['dec'] ?? '') === 'sk-secret-123');
t('إعادة التشفير بتخليها بالمفتاح الحالي', ($r['re'] ?? '') === 'current');
t('مفتاح عشوائي مش متعرّف إنه المنشور', ($r['pub'] ?? true) === false);
t('config.sample.php مافيهوش مفتاح حقيقي', !preg_match("/define\('ENCRYPTION_KEY',\s*'[0-9a-f]{64}'/i", (string) file_get_contents($root . '/includes/config.sample.php')));

/* ─── 3) IP: هيدر CF-Connecting-IP مايتصدّقش ─── */
echo "IP الزائر\n";
$_SERVER['HTTP_CF_CONNECTING_IP'] = '6.6.6.6';
t('offer_client_ip بياخد REMOTE_ADDR', offer_client_ip() === '127.0.0.1');
t('trial_client_ip بياخد REMOTE_ADDR', trial_client_ip() === '127.0.0.1');
unset($_SERVER['HTTP_CF_CONNECTING_IP']);

/* ─── 4) استرجاع مكافأة عرض: خصم مرة واحدة حتى لو اتطلب مرتين في نفس اللحظة ─── */
echo "استرجاع مكافأة العرض\n";
$uid = tmp_user('offer');
$offerId = (int) db_one('SELECT id FROM offers ORDER BY id LIMIT 1')['id'];
credits_add($uid, 10, 'CE-B test bonus');
$before = credits_balance($uid);
$redId = (int) db_insert('INSERT INTO offer_redemptions (offer_id, user_id, role, credits_given, status) VALUES (?,?,"referee",5,"granted")', [$offerId, $uid]);
$cmd = 'cd ' . escapeshellarg($root) . ' && php -r ' . escapeshellarg("\$_SERVER['REMOTE_ADDR']='127.0.0.1'; require_once 'includes/config.php'; require_once 'includes/functions.php'; require_once 'includes/credits.php'; require_once 'includes/offers.php'; echo json_encode(reverse_redemption((int) \$argv[1], 'CE-B race'));") . " -- $redId";
$procs = [];
for ($i = 0; $i < 2; $i++) $procs[] = popen($cmd, 'r');
$res = [];
foreach ($procs as $p) { $res[] = json_decode((string) stream_get_contents($p), true) ?: []; pclose($p); }
$oks = count(array_filter($res, fn($x) => !empty($x['ok'])));
t('طلبين في نفس اللحظة → واحد بس نجح', $oks === 1, json_encode($res, JSON_UNESCAPED_UNICODE));
t('الرصيد اتخصم 5 بس', credits_balance($uid) === $before - 5, 'قبل ' . $before . ' بعد ' . credits_balance($uid));
t('الطلب التالت بيرفض', empty(reverse_redemption($redId, 'again')['ok']));

/* ─── 5) مسح الحساب: كل البيانات تتمسح والسجل المالي يفضل ─── */
echo "مسح الحساب نهائي\n";
$uid2 = tmp_user('purge');
credits_add($uid2, 7, 'CE-B purge ledger');
$bpid = (int) db_insert('INSERT INTO brand_profiles (user_id) VALUES (?)', [$uid2]);
$cid = (int) db_insert('INSERT INTO contents (user_id, brand_profile_id, generated_text, created_at) VALUES (?, ?, "CE-B", NOW())', [$uid2, $bpid]);
try { db_insert('INSERT INTO content_versions (content_id, version_number, content_text) VALUES (?,1,"x")', [$cid]); } catch (\Throwable $e) {}
$runId = (int) db_insert('INSERT INTO ai_runs (run_key, task, user_id, status) VALUES (?, "ce_test", ?, "ok")', [bin2hex(random_bytes(8)), $uid2]);
$err = '';
try { account_purge($uid2); } catch (\Throwable $e) { $err = $e->getMessage(); }
t('account_purge خلص من غير خطأ', $err === '', $err);
t('المستخدم اتمسح', !db_one('SELECT id FROM users WHERE id = ?', [$uid2]));
t('البراند اتمسح', !db_one('SELECT id FROM brand_profiles WHERE id = ?', [$bpid]));
t('المحتوى اتمسح', !db_one('SELECT id FROM contents WHERE id = ?', [$cid]));
t('نسخ المحتوى اتمسحت', !db_one('SELECT id FROM content_versions WHERE content_id = ?', [$cid]));
$led = db_one('SELECT user_id FROM credit_transactions WHERE notes = "CE-B purge ledger" ORDER BY id DESC LIMIT 1');
t('قيد الكريدت لسه موجود من غير صاحب', $led && $led['user_id'] === null, json_encode($led));
$run = db_one('SELECT user_id FROM ai_runs WHERE id = ?', [$runId]);
t('سجل تكلفة الـ AI لسه موجود من غير صاحب', $run && $run['user_id'] === null);

/* ─── 6) Paymob: الدفع والرصيد في transaction واحدة ─── */
echo "Paymob callback\n";
$hmacOld = get_setting('paymob_hmac', '');
set_setting('paymob_hmac', 'ceb-hmac-secret');
$uid3 = tmp_user('pay');
$pkg = db_one('SELECT id FROM credit_packages ORDER BY id LIMIT 1');
$mkOrder = function (string $pm) use ($uid3, $pkg) {
    return (int) db_insert('INSERT INTO payment_orders (user_id, package_id, credits, amount_egp, paymob_order_id, status) VALUES (?,?,25,100,?,"pending")', [$uid3, (int) ($pkg['id'] ?? 0), $pm]);
};
$cb = function (string $pm, string $txn) use ($base) {
    $q = ['amount_cents' => '10000', 'created_at' => '2026-10-22', 'currency' => 'EGP', 'error_occured' => 'false', 'has_parent_transaction' => 'false',
        'id' => $txn, 'integration_id' => '1', 'is_3d_secure' => 'true', 'is_auth' => 'false', 'is_capture' => 'false', 'is_refunded' => 'false',
        'is_standalone_payment' => 'true', 'is_voided' => 'false', 'order' => $pm, 'owner' => '1', 'pending' => 'false',
        'source_data_pan' => '2346', 'source_data_sub_type' => 'MasterCard', 'source_data_type' => 'card', 'success' => 'true'];
    $q['hmac'] = hash_hmac('sha512', implode('', $q), 'ceb-hmac-secret');
    return http('GET', "$base/public/paymob-callback.php?" . http_build_query($q));
};
$pm1 = 'ceb-' . bin2hex(random_bytes(4));
$o1 = $mkOrder($pm1);
$b0 = credits_balance($uid3);
$cb($pm1, 'T1'); $cb($pm1, 'T1');
t('الطلب بقى paid', db_one('SELECT status FROM payment_orders WHERE id = ?', [$o1])['status'] === 'paid');
t('الرصيد زاد 25 مرة واحدة بس (رغم الكول باك مرتين)', credits_balance($uid3) === $b0 + 25, 'بقى ' . credits_balance($uid3));
// فشل الشحن: trigger مؤقت بيرفض قيد الطلب ده
$pm2 = 'ceb-' . bin2hex(random_bytes(4));
$o2 = $mkOrder($pm2);
db()->exec("CREATE TRIGGER ceb_fail BEFORE INSERT ON credit_transactions FOR EACH ROW BEGIN IF NEW.user_id = $uid3 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ceb forced'; END IF; END");
$b1 = credits_balance($uid3);
try { $cb($pm2, 'T2'); } finally { db()->exec('DROP TRIGGER IF EXISTS ceb_fail'); }
t('لو الشحن فشل الطلب يفضل pending (يتعاد بأمان)', db_one('SELECT status FROM payment_orders WHERE id = ?', [$o2])['status'] === 'pending');
t('ومفيش رصيد اتضاف نصّه', credits_balance($uid3) === $b1);
t('والأدمن اتبلّغ بتنبيه critical', (bool) db_one('SELECT id FROM admin_alerts WHERE dedupe_key = ?', ['paymob_fail:' . $o2]));
$cb($pm2, 'T2');
t('إعادة فتح لينك الرجوع بعد الإصلاح بتشحن عادي', db_one('SELECT status FROM payment_orders WHERE id = ?', [$o2])['status'] === 'paid' && credits_balance($uid3) === $b1 + 25);
set_setting('paymob_hmac', $hmacOld);

/* ─── 7) الـ API: GET للقراءة بس ─── */
echo "API: العمليات اللي بتغيّر لازم POST\n";
$jar = login($base, 'public/login.php', 'sub@test.local', 'Test12345!');
[$c1] = http('GET', "$base/public/api/campaigns.php?action=list", [], $jar);
[$c2, $b2] = http('GET', "$base/public/api/campaigns.php?action=delete&id=1", [], $jar);
[$c3] = http('GET', "$base/public/api/contents.php?action=delete&id=1", [], $jar);
t('GET قراءة (campaigns list) = 200', $c1 === 200, (string) $c1);
t('GET عملية مسح (campaigns delete) = 405', $c2 === 405, "$c2 $b2");
t('GET عملية مسح (contents delete) = 405', $c3 === 405, (string) $c3);

/* ─── 8) صفحات التشخيص مقفولة ─── */
echo "صفحات التشخيص\n";
foreach (['check.php', 'check-db.php', 'public/auth/ms-test.php'] as $p) {
    [$c] = http('GET', "$base/$p");
    t("$p من غير دخول = 404", $c === 404, (string) $c);
}
$ajar = login($base, 'admin/login.php', 'owner@app.test', 'Test12345!');
[$c] = http('GET', "$base/check.php", [], $ajar);
t('check.php للأدمن = 200', $c === 200, (string) $c);

/* ─── 9) Meta webhook من غير App Secret بيترفض ─── */
echo "Meta webhook\n";
if (get_setting('meta_app_secret_enc', '') === '') {
    [$c] = http('POST', "$base/public/webhooks/meta.php", '{"entry":[{"id":"123"}]}', null, ['Content-Type: application/json']);
    t('POST من غير سيكرت = 503 ومحصلش تعديل', $c === 503, (string) $c);
} else {
    echo "  - اتخطّى: فيه App Secret متسجّل\n";
}

/* ─── 10) الفيديو المقفول بيترفض قبل الخصم ─── */
echo "حملة: الفيديو المقفول\n";
$src = (string) file_get_contents($root . '/public/api/campaign-flow.php');
t('شرط cf_video_locked قبل خصم كتابة الفكرة', strpos($src, 'cf_video_locked($old)) api_fail') < strpos($src, 'cf_charge($uid, $cost, ($old ? \'إعادة كتابة\''));
t('الحملة بتسيب الجلسة غير في توليد الأفكار', str_contains($src, "api_boot(true, \$action === 'ideas_generate')"));

/* ─── 11) صورة الستايل من لينك: مايوصلش للسيرفر نفسه أو شبكته (SSRF) ─── */
echo "صورة الستايل من لينك\n";
require_once __DIR__ . '/../includes/uploader.php';
t('لينك localhost مرفوض', remote_image_data_uri($base . '/assets/img/logo.png') === null);
t('لينك metadata السيرفر مرفوض', remote_image_data_uri('http://169.254.169.254/latest/meta-data/') === null);
t('لينك IP داخلي مرفوض', remote_image_data_uri('http://10.0.0.1/a.png') === null);

/* ─── تنظيف ─── */
foreach ([$uid, $uid3] as $u) { try { account_purge($u); } catch (\Throwable $e) { echo '  (تنظيف: ' . $e->getMessage() . ")\n"; } }
db_run('DELETE FROM credit_transactions WHERE user_id IS NULL AND (notes LIKE "CE-B%" OR notes LIKE "%CE-B race%")');
db_run('DELETE FROM ai_runs WHERE task = "ce_test"');
db_run('DELETE FROM payment_orders WHERE paymob_order_id LIKE "ceb-%"');
db_run('DELETE FROM admin_alerts WHERE dedupe_key LIKE "paymob_fail:%" AND title LIKE "%Paymob%" AND created_at > NOW() - INTERVAL 1 HOUR');
db_run('DELETE FROM rate_limits');

echo "\n$pass ✓ · $fail ✗\n";
exit($fail ? 1 : 0);

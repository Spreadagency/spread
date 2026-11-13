<?php
/**
 * Spread AI — خريطة التطوير · المرحلة 0: إصلاحات المراجعة
 * للتطوير المحلي بس — بيشغّل Graph وهمي على 127.0.0.1:8093 وبيمسح كل اللي بيعمله. مفيش نشر حقيقي.
 *
 *   php tests/p0-fixes.php http://localhost:8080
 */
if (PHP_SAPI !== 'cli') exit("CLI only\n");
$base = rtrim($argv[1] ?? 'http://localhost:8080', '/');
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require_once __DIR__ . '/../includes/config.php';
if (!APP_DEBUG) exit("APP_DEBUG مقفول — الاختبارات دي للتطوير المحلي بس\n");
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/social.php';

$pass = 0; $fail = 0;
function t(string $name, bool $ok, string $why = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? '  ✓ ' : '  ✗ ') . $name . ($ok || $why === '' ? '' : " — $why") . "\n";
}
function http(string $method, string $url, $post = [], ?string $jar = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 120, CURLOPT_FOLLOWLOCATION => false, CURLOPT_HEADER => true]);
    if ($jar) curl_setopt_array($ch, [CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar]);
    if ($method === 'POST') curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($post)]);
    $raw = (string) curl_exec($ch);
    $c = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hs = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return [$c, substr($raw, $hs), substr($raw, 0, $hs)];
}
function csrf_of(string $html): string { return preg_match('/name="(?:csrf|_csrf|csrf_token)" value="([^"]+)"/', $html, $m) ? $m[1] : ''; }
function swap(string $k, ?string $v): void { db_run('DELETE FROM settings WHERE setting_key = ?', [$k]); if ($v !== null) set_setting($k, $v); }

$root = dirname(__DIR__);
$tag = bin2hex(random_bytes(3));

/* ─── 1) مستندات العملاء مش بتتفتح بلينك ─── */
echo "مستندات العملاء\n";
$dir = $root . '/storage/uploads/sources';
if (!is_dir($dir)) @mkdir($dir, 0755, true);
$probe = "src_p0_$tag.txt";
file_put_contents("$dir/$probe", 'سر العميل');
[$c, $b] = http('GET', "$base/storage/uploads/sources/$probe");
t('لينك مباشر لمستند عميل ← 403', $c === 403 && !str_contains($b, 'سر العميل'), (string) $c);
@unlink("$dir/$probe");
t('القاعدة موجودة في .htaccess قبل تحويل storage', (function () use ($root) {
    $h = (string) file_get_contents($root . '/.htaccess');
    $a = strpos($h, 'RewriteRule ^storage/uploads/sources/ - [F,L]'); $bb = strpos($h, '^/(admin|assets|storage|');
    return $a !== false && $bb !== false && $a < $bb;
})());

/* ─── 2) النشر مابيتكررش ─── */
echo "النشر على منصتين\n";
$stateFile = sys_get_temp_dir() . '/spread-mock-graph.json';
$logFile = $stateFile . '.log';
$log = fn() => array_values(array_filter(array_map(fn($l) => json_decode($l, true), explode("\n", (string) @file_get_contents($logFile)))));
$fbPosts = fn() => count(array_filter($log(), fn($l) => str_starts_with((string) ($l['path'] ?? ''), '7711/')));
file_put_contents($stateFile, json_encode(['pages' => ['7711' => []], 'ig' => [], 'metrics' => []]));
@unlink($logFile);
$proc = proc_open([PHP_BINARY, '-S', '127.0.0.1:8093', __DIR__ . '/mock-graph.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
usleep(500000);
$saved = [];
foreach (['graph_base_url', 'cron_secret'] as $k) $saved[$k] = db_one('SELECT setting_value v FROM settings WHERE setting_key = ?', [$k])['v'] ?? null;
swap('graph_base_url', 'http://127.0.0.1:8093/v21.0');
$secret = 'p0-' . bin2hex(random_bytes(8));
swap('cron_secret', $secret);
$cron = function () use ($root, $secret): string {
    return (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/cron/publish-scheduled.php') . ' ' . escapeshellarg('cron_secret=' . $secret) . ' 2>&1');
};
$uid = (int) db_insert('INSERT INTO users (name, email, password, status, email_verified_at, approval_status, approved_at) VALUES (?,?,?, "active", NOW(), "approved", NOW())', ["P0 $tag", "p0-$tag@test.local", password_hash('Test12345!', PASSWORD_DEFAULT)]);
db_insert('INSERT INTO user_feature_access (user_id, feature_key, is_enabled) VALUES (?, "social_publishing", 1)', [$uid]);
$bid = (int) db_insert('INSERT INTO brand_profiles (user_id, business_name) VALUES (?,?)', [$uid, 'براند P0']);
db_run('INSERT INTO credit_wallets (user_id, balance) VALUES (?, 40)', [$uid]);
// صفحة فيسبوك من غير حساب إنستجرام ← فيسبوك ينجح وإنستجرام يفشل
$conn = (int) db_insert('INSERT INTO social_connections (user_id, platform, provider_page_id, page_name, access_token_enc, status) VALUES (?, "facebook", "7711", "صفحة P0", ?, "active")', [$uid, social_token_encrypt('tok_ok')]);
$mk = fn(string $pf, string $st = 'pending', ?string $pid = null) => (int) db_insert('INSERT INTO contents (user_id, brand_profile_id, content_type, platform, generated_text, status, publish_status, publish_platform, connection_id, scheduled_at, publish_post_id)
    VALUES (?,?,?,?,?,?,?,?,?, NOW() - INTERVAL 1 MINUTE, ?)', [$uid, $bid, 'introductory', 'both', "منشور P0 $tag", 'generated', $st, $pf, $conn, $pid]);
$row = fn(int $id) => db_one('SELECT * FROM contents WHERE id = ?', [$id]);

$c1 = $mk('both');
$out = $cron();
$r = $row($c1);
t('فيسبوك نجح وإنستجرام فشل ← نجاح فيسبوك اتسجّل فورًا', str_contains((string) $r['publish_post_id'], 'facebook:') && $r['publish_status'] !== 'published' && $fbPosts() === 1, trim($out) . ' | ' . json_encode([$r['publish_status'], $r['publish_post_id'], $fbPosts()]));
db_run('UPDATE contents SET publish_status = "pending", next_attempt_at = NULL, attempts = 0 WHERE id = ?', [$c1]);
$cron();
db_run('UPDATE contents SET publish_status = "pending", next_attempt_at = NULL, attempts = 0 WHERE id = ?', [$c1]);
$cron();
t('إعادة المحاولة مرتين مانشرتش على فيسبوك تاني (مفيش بوست مكرر)', $fbPosts() === 1, 'fb posts=' . $fbPosts());
t('ورقم بوست فيسبوك متسجل مرة واحدة', substr_count((string) $row($c1)['publish_post_id'], 'facebook:') === 1, (string) $row($c1)['publish_post_id']);

// السكربت وقع بعد نجاح فيسبوك وقبل ما يقفل الصف ← علق في processing
$c2 = $mk('facebook', 'processing', 'facebook:7711_999');
db_run('UPDATE contents SET lock_token = "dead", updated_at = NOW() - INTERVAL 20 MINUTE WHERE id = ?', [$c2]);
$before = $fbPosts();
$cron();
$r2 = $row($c2);
t('منشور علق بعد ما اتنشر ← بيتقفل «اتنشر» من غير نشر تاني', $r2['publish_status'] === 'published' && $fbPosts() === $before && $r2['publish_post_id'] === 'facebook:7711_999', json_encode([$r2['publish_status'], $fbPosts() - $before]));
$c3 = $mk('facebook');
$cron();
t('والنشر العادي لسه شغال', $row($c3)['publish_status'] === 'published' && str_starts_with((string) $row($c3)['publish_post_id'], 'facebook:') && $fbPosts() === $before + 1, json_encode([$row($c3)['publish_status'], $row($c3)['publish_post_id']]));

/* ─── 3) الكريدت مايروحش على الفاضي ─── */
echo "الكريدت\n";
$src = (string) file_get_contents($root . '/public/ajax/generate-stream.php');
t('التوليد بالـ stream بيكمّل لو العميل قفل الصفحة (ignore_user_abort قبل الخصم)', ($a = strpos($src, 'ignore_user_abort(true)')) !== false && $a < strpos($src, 'credits_consume('));
foreach (['generate-content.php', 'generate-stream.php'] as $f) {
    $s = (string) file_get_contents($root . '/public/ajax/' . $f);
    t("$f: استرداد لو الرد فاضي أو الحفظ فشل", str_contains($s, "'استرداد: الرد جه فاضي'") && str_contains($s, "'استرداد: المنشور ماتحفظش'") && strpos($s, "'استرداد: الرد جه فاضي'") < strpos($s, "db_insert_row('contents'"));
}
$jar = tempnam(sys_get_temp_dir(), 'p0');
[, $h] = http('GET', "$base/public/login.php", [], $jar);
db_run('DELETE FROM rate_limits WHERE action IN ("login", "generate")');
http('POST', "$base/public/login.php", ['csrf' => csrf_of($h), 'email' => "p0-$tag@test.local", 'password' => 'Test12345!'], $jar);
[, $h] = http('GET', "$base/public/create-content.php", [], $jar);
$bal = credits_balance($uid);
// الـ AI الوهمي بيرجّع رد فاضي لما الملاحظة فيها العلامة دي
[$c, $b] = http('POST', "$base/public/ajax/generate-content.php", ['csrf' => csrf_of($h), 'content_type' => 'introductory', 'platform' => 'both', 'length' => 'medium', 'dialect' => 'egyptian', 'extra_notes' => 'MOCK_EMPTY_REPLY'], $jar);
$j = json_decode($b, true);
t('رد فاضي ← مفيش منشور والكريدت رجع', empty($j['ok']) && credits_balance($uid) === $bal && !db_one('SELECT id FROM contents WHERE user_id = ? AND extra_notes = "MOCK_EMPTY_REPLY"', [$uid]), substr($b, 0, 160) . ' bal ' . credits_balance($uid) . "/$bal");
[$c, $b] = http('POST', "$base/public/ajax/generate-content.php", ['csrf' => csrf_of($h), 'content_type' => 'introductory', 'platform' => 'both', 'length' => 'medium', 'dialect' => 'egyptian'], $jar);
t('والتوليد العادي لسه بيخصم ويحفظ', !empty(json_decode($b, true)['content_id']) && credits_balance($uid) < $bal, substr($b, 0, 120));

/* ─── 4) صلاحيات صفحات الأدمن ─── */
echo "صفحات الأدمن\n";
foreach (['user-brand.php' => 'view_users', 'integrations.php' => 'site_settings', 'social-connections.php' => 'view_users', 'designs-gallery.php' => 'view_content', 'content-history.php' => 'view_content'] as $f => $perm) {
    t("admin/$f عليها require_admin_can('$perm')", str_contains((string) file_get_contents("$root/admin/$f"), "require_admin_can('$perm')"));
}
$asst = (int) db_insert('INSERT INTO admin_users (name, email, password, role, status) VALUES (?,?,?, "assistant", "active")', ["P0 مساعد $tag", "p0-asst-$tag@test.local", password_hash('Test12345!', PASSWORD_DEFAULT)]);
$jarA = tempnam(sys_get_temp_dir(), 'p0');
db_run('DELETE FROM rate_limits WHERE action = "admin_login"');
[, $h] = http('GET', "$base/admin/login.php", [], $jarA);
http('POST', "$base/admin/login.php", ['csrf' => csrf_of($h), 'email' => "p0-asst-$tag@test.local", 'password' => 'Test12345!'], $jarA);
[$c] = http('GET', "$base/admin/integrations.php", [], $jarA);
t('المساعد مايفتحش صفحة الربط والمفاتيح (403)', $c === 403, (string) $c);
[$c] = http('GET', "$base/admin/content-history.php", [], $jarA);
t('وصفحات المحتوى لسه مفتوحاله', $c === 200, (string) $c);

/* ─── 5) دخول أدمن الموقع التعريفي ─── */
echo "دخول site-admin\n";
require_once $root . '/site-admin/auth.php';
$savedThrottle = s_setting('login_throttle', '');
s_set('login_throttle', '{}');
$jarS = tempnam(sys_get_temp_dir(), 'p0');
$codes = [];
for ($i = 0; $i < 6; $i++) {
    [, $h] = http('GET', "$base/site-admin/login.php", [], $jarS);
    [$c, $b] = http('POST', "$base/site-admin/login.php", ['csrf' => csrf_of($h), '_csrf' => csrf_of($h), 'csrf_token' => csrf_of($h), 'email' => "nobody-$tag@test.local", 'password' => 'wrong' . $i], $jarS);
    $codes[] = str_contains($b, 'محاولات كتير') ? 'blocked' : (str_contains($b, 'غير صحيحة') ? 'wrong' : "?$c");
}
t('5 محاولات غلط وبعدها قفل 15 دقيقة', $codes === ['wrong', 'wrong', 'wrong', 'wrong', 'wrong', 'blocked'], implode(',', $codes));
t('والقفل بالإيميل كمان (من IP تاني)', !sa_login_allowed('10.9.8.7', "nobody-$tag@test.local") && sa_login_allowed('10.9.8.7', "other-$tag@test.local"));
s_set('login_throttle', json_encode(['i' . substr(hash('sha256', '10.1.1.1'), 0, 16) => array_fill(0, 5, time() - 1000)]));
t('المحاولات القديمة بتسقط بعد 15 دقيقة', sa_login_allowed('10.1.1.1', 'x@test.local'));
s_set('login_throttle', $savedThrottle !== '' ? $savedThrottle : '{}');

/* ─── تنضيف ─── */
if (is_resource($proc)) { proc_terminate($proc); proc_close($proc); }
foreach ($saved as $k => $v) swap($k, $v);
foreach (['content_versions' => 'content_id IN (SELECT id FROM contents WHERE user_id = ?)', 'contents' => 'user_id = ?', 'social_connections' => 'user_id = ?', 'user_feature_access' => 'user_id = ?', 'usage_events' => 'user_id = ?',
          'user_notifications' => 'user_id = ?', 'messages' => 'user_id = ?', 'brand_kits' => 'user_id = ?', 'brand_profiles' => 'user_id = ?', 'credit_transactions' => 'user_id = ?', 'credit_wallets' => 'user_id = ?'] as $tb => $w) {
    try { db_run("DELETE FROM $tb WHERE $w", [$uid]); } catch (\Throwable $e) {}
}
try { db_run('DELETE FROM users WHERE id = ?', [$uid]); } catch (\Throwable $e) { echo '  (تنضيف: ' . $e->getMessage() . ")\n"; }
try { db_run('DELETE FROM admin_logs WHERE admin_id = ?', [$asst]); } catch (\Throwable $e) {}
db_run('DELETE FROM admin_users WHERE id = ?', [$asst]);
db_run('DELETE FROM rate_limits WHERE action IN ("login", "generate", "admin_login")');
@unlink($jar); @unlink($jarA); @unlink($jarS); @unlink($stateFile); @unlink($logFile);

echo "\n" . ($fail ? "✗ $fail فشل · " : '✓ ') . "$pass نجح\n";
exit($fail ? 1 : 0);

<?php
/**
 * Spread AI — خريطة التطوير · المرحلة 6: باقي ملاحظات المراجعة
 * للتطوير المحلي بس — بيعمل حسابات مؤقتة ويمسحها. (إيقاف الواتساب التسويقي متغطي في tests/ms1-messaging.php)
 *
 *   php tests/p6-fixes.php http://localhost:8080
 */
if (PHP_SAPI !== 'cli') exit("CLI only\n");
$base = rtrim($argv[1] ?? 'http://localhost:8080', '/');
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require_once __DIR__ . '/../includes/config.php';
if (!APP_DEBUG) exit("APP_DEBUG مقفول — الاختبارات دي للتطوير المحلي بس\n");
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/rate-limit.php';
require_once __DIR__ . '/../includes/uploader.php';

$pass = 0; $fail = 0;
function t(string $name, bool $ok, string $why = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? '  ✓ ' : '  ✗ ') . $name . ($ok || $why === '' ? '' : " — $why") . "\n";
}
function http(string $method, string $url, $post = [], ?string $jar = null, array $headers = []): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 120, CURLOPT_FOLLOWLOCATION => false, CURLOPT_HEADER => true, CURLOPT_HTTPHEADER => $headers]);
    if ($jar) curl_setopt_array($ch, [CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar]);
    if ($method === 'POST') curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => is_array($post) && !array_filter($post, fn($v) => $v instanceof CURLFile) ? http_build_query($post) : $post]);
    $raw = (string) curl_exec($ch);
    $c = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hs = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return [$c, substr($raw, $hs), substr($raw, 0, $hs)];
}
function loc(string $h): string { return preg_match('/^Location:\s*(\S+)/mi', $h, $m) ? $m[1] : ''; }
function csrf_of(string $html): string { return preg_match('/name="csrf" value="([^"]+)"/', $html, $m) ? $m[1] : ''; }
function swap(string $k, ?string $v): void { db_run('DELETE FROM settings WHERE setting_key = ?', [$k]); if ($v !== null) set_setting($k, $v); }
$root = dirname(__DIR__);
$tag = bin2hex(random_bytes(3));
$clean = fn() => db_run('DELETE FROM rate_limits WHERE action IN ("login", "login_acct", "admin_login", "admin_login_acct")');
$clean();

$uid = (int) db_insert('INSERT INTO users (name, email, password, status, email_verified_at, approval_status, approved_at) VALUES (?,?,?, "active", NOW(), "approved", NOW())',
    ["P6 $tag", "p6-$tag@test.local", password_hash('Test12345!', PASSWORD_DEFAULT)]);
$bid = (int) db_insert('INSERT INTO brand_profiles (user_id, business_name) VALUES (?,?)', [$uid, 'براند P6']);
$adm = (int) db_insert('INSERT INTO admin_users (name, email, password, role, status) VALUES (?,?,?, "assistant", "active")', ["P6 أدمن $tag", "p6-adm-$tag@test.local", password_hash('Test12345!', PASSWORD_DEFAULT)]);
$login = function (string $path, string $email, string $pw) use ($base): array {
    $jar = tempnam(sys_get_temp_dir(), 'p6');
    [, $h] = http('GET', "$base/$path", [], $jar);
    $r = http('POST', "$base/$path", ['csrf' => csrf_of($h), 'email' => $email, 'password' => $pw], $jar);
    @unlink($jar);
    return $r;
};

/* ─── 1) حد المحاولات لكل حساب ─── */
echo "الدخول\n";
// 10 محاولات غلط على الحساب ده من IPs مختلفة (متسجلة في العدّاد كأنها اتعملت)
for ($i = 0; $i < LOGIN_ACCT_MAX; $i++) rate_limit_hit('login_acct', login_acct_key("P6-$tag@TEST.local "));
[$c, $b, $hd] = $login('public/login.php', "p6-$tag@test.local", 'Test12345!');
t('بعد 10 محاولات غلط على الحساب ← حتى الباسورد الصح بيستنى (الإيميل بأي حروف)', $c === 200 && str_contains($b, 'محاولات كتير'), $c . ' ' . loc($hd));
[$c, , $hd] = $login('public/login.php', 'sub@test.local', 'Test12345!');
t('وحساب تاني من نفس الجهاز بيدخل عادي', $c === 302 && !str_contains(loc($hd), 'login.php'), $c . ' ' . loc($hd));
$clean();
[$c, , $hd] = $login('public/login.php', "p6-$tag@test.local", 'wrong');
t('محاولة غلط بتتسجل على الحساب', (int) db_one('SELECT COUNT(*) n FROM rate_limits WHERE action = "login_acct" AND rate_key = ?', [login_acct_key("p6-$tag@test.local")])['n'] === 1);
$clean();
for ($i = 0; $i < LOGIN_ACCT_MAX; $i++) rate_limit_hit('admin_login_acct', login_acct_key("p6-adm-$tag@test.local"));
[$c, $b] = $login('admin/login.php', "p6-adm-$tag@test.local", 'Test12345!');
t('ونفس الحد على دخول الأدمن', $c === 200 && str_contains($b, 'محاولات كتير'), (string) $c);
$clean();
[$c, , $hd] = $login('admin/login.php', "p6-adm-$tag@test.local", 'Test12345!');
t('ولما المحاولات تتمسح بيدخل', $c === 302, (string) $c);
$clean();
[$c, $b] = http('POST', "$base/public/api/v1/auth.php?action=login", json_encode(['email' => "p6-$tag@test.local", 'password' => 'x']), null, ['Content-Type: application/json']);
t('ودخول التطبيق (API) بيسجّل على الحساب كمان', (int) db_one('SELECT COUNT(*) n FROM rate_limits WHERE action = "login_acct" AND rate_key = ?', [login_acct_key("p6-$tag@test.local")])['n'] === 1, $c . ' ' . substr($b, 0, 100));
$clean();

/* ─── 2) العدّاد بيقفل الدخول لو وقع ─── */
echo "العدّاد\n";
db()->exec('RENAME TABLE rate_limits TO rate_limits_p6');
try {
    $loginOk = rate_limit_ok('login', '1.2.3.4', 6, 600);
    $genOk = rate_limit_ok('generate', 'u1', 10, 600);
} finally {
    db()->exec('RENAME TABLE rate_limits_p6 TO rate_limits');
}
t('جدول العدّاد وقع ← الدخول بيترفض', $loginOk === false);
t('والتوليد بيكمّل زي الأول', $genOk === true);

/* ─── 3) الحد الأسبوعي جوه القفل ─── */
echo "الكريدت\n";
$src = (string) file_get_contents("$root/includes/credits.php");
$fn = substr($src, strpos($src, 'function credits_consume('), 2500);
t('الحد الأسبوعي بيتحسب بعد قفل المحفظة (FOR UPDATE)', strpos($fn, 'FOR UPDATE') !== false && strpos($fn, 'pkg_weekly_block') > strpos($fn, 'FOR UPDATE'));
db_run('INSERT INTO credit_wallets (user_id, balance) VALUES (?, 5)', [$uid]);
t('والخصم العادي شغال ومفيش transaction فاضل مفتوح', credits_consume($uid, 2, 'p6') && credits_balance($uid) === 3 && !db()->inTransaction() && !credits_consume($uid, 9, 'p6') && !db()->inTransaction());

/* ─── 4) توكن استرجاع الباسورد ─── */
echo "استرجاع الباسورد\n";
$raw = random_token(64);
db_run('INSERT INTO password_resets (user_id, token, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR))', [$uid, reset_token_hash($raw)]);
$old = random_token(64);
// توكن نص من قبل التحويل (created_at قبل reset_hash_since)
db_run('INSERT INTO password_resets (user_id, token, expires_at, created_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR), ?)', [$uid, $old, date('Y-m-d H:i:s', strtotime(reset_hash_since()) - 60)]);
t('التوكن متخزن كبصمة مش نص', !db_one('SELECT id FROM password_resets WHERE token = ?', [$raw]));
[$c, $h] = http('GET', "$base/public/reset-password.php?token=$raw");
t('الرابط بيفتح بالتوكن الأصلي', $c === 200 && str_contains($h, 'name="token"'), (string) $c);
[$c, $h2] = http('GET', "$base/public/reset-password.php?token=$old");
t('والتوكنز القديمة (نص) لسه شغالة لحد ما تنتهي', $c === 200 && str_contains($h2, 'name="token"'));
[$c, $h3] = http('GET', "$base/public/reset-password.php?token=" . reset_token_hash($raw));
t('البصمة نفسها مابتفتحش (تسريب القاعدة مايكفيش)', !str_contains($h3, 'name="token"'));
$jar = tempnam(sys_get_temp_dir(), 'p6');
[, $h] = http('GET', "$base/public/reset-password.php?token=$raw", [], $jar);
http('POST', "$base/public/reset-password.php", ['csrf' => csrf_of($h), 'token' => $raw, 'password' => 'NewPass123!', 'password_confirm' => 'NewPass123!'], $jar);
t('بعد التغيير ← كل روابط الحساب اتقفلت (مش اللي اتستخدم بس)', (int) db_one('SELECT COUNT(*) n FROM password_resets WHERE user_id = ? AND used_at IS NULL', [$uid])['n'] === 0
    && password_verify('NewPass123!', (string) db_one('SELECT password FROM users WHERE id = ?', [$uid])['password']));
$f = (string) file_get_contents("$root/public/forgot-password.php") . file_get_contents("$root/public/api/v1/auth.php");
t('صفحة «نسيت الباسورد» والتطبيق بيخزنوا البصمة', substr_count($f, 'reset_token_hash($token)') === 2);

/* ─── 5) نوع المستند ─── */
echo "المستندات\n";
$tmp = sys_get_temp_dir() . "/p6-$tag";
@mkdir($tmp);
file_put_contents("$tmp/fake.pdf", "<html><script>alert(1)</script></html>");
file_put_contents("$tmp/real.pdf", "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n");
file_put_contents("$tmp/notes.txt", "ملاحظات عادية عن النشاط");
file_put_contents("$tmp/evil.txt", "<script>alert(1)</script>");
t('PDF مزيّف (HTML) ← مرفوض', !upload_doc_type_ok("$tmp/fake.pdf", 'pdf'));
t('PDF حقيقي ← مقبول', upload_doc_type_ok("$tmp/real.pdf", 'pdf'));
t('TXT عادي مقبول · وفيه سكربت مرفوض', upload_doc_type_ok("$tmp/notes.txt", 'txt') && !upload_doc_type_ok("$tmp/evil.txt", 'txt'));
t('DOCX مش ZIP ← مرفوض', !upload_doc_type_ok("$tmp/notes.txt", 'docx'));
db_run('DELETE FROM rate_limits WHERE action IN ("login", "login_acct")');
$jarC = tempnam(sys_get_temp_dir(), 'p6');
[, $h] = http('GET', "$base/public/login.php", [], $jarC);
http('POST', "$base/public/login.php", ['csrf' => csrf_of($h), 'email' => "p6-$tag@test.local", 'password' => 'NewPass123!'], $jarC);
[, $h] = http('GET', "$base/public/sources.php", [], $jarC);
$before = (int) db_one('SELECT COUNT(*) n FROM brand_sources WHERE user_id = ?', [$uid])['n'];
[$c, , $hd] = http('POST', "$base/public/sources.php", ['csrf' => csrf_of($h), 'action' => 'upload_file', 'title' => 'p6', 'file' => new CURLFile("$tmp/fake.pdf", 'application/pdf', 'brochure.pdf')], $jarC);
[, $h2] = http('GET', "$base/public/sources.php", [], $jarC);
t('رفع PDF مزيّف من صفحة المصادر ← مرفوض برسالة', (int) db_one('SELECT COUNT(*) n FROM brand_sources WHERE user_id = ?', [$uid])['n'] === $before && str_contains($h2, 'مش PDF فعلًا'), $c . ' ' . loc($hd));
[$c] = http('POST', "$base/public/sources.php", ['csrf' => csrf_of($h2), 'action' => 'upload_file', 'title' => 'p6', 'file' => new CURLFile("$tmp/real.pdf", 'application/pdf', 'real.pdf')], $jarC);
$srcRow = db_one('SELECT * FROM brand_sources WHERE user_id = ? ORDER BY id DESC LIMIT 1', [$uid]);
t('وPDF حقيقي بيترفع', (int) db_one('SELECT COUNT(*) n FROM brand_sources WHERE user_id = ?', [$uid])['n'] === $before + 1);
if ($srcRow) {
    [$c] = http('GET', "$base/storage/" . $srcRow['file_path']);
    t('والملف المرفوع مابيتفتحش بلينك مباشر (المرحلة 0)', $c === 403, (string) $c);
}

/* ─── 6) حقن في الواجهة ─── */
echo "الواجهة\n";
$co = (string) file_get_contents("$root/public/checkout.php");
t('الكوبون بيتعرض كنص (من غير innerHTML)', !str_contains($co, "msg.innerHTML = '✓ تم تطبيق الكود") && str_contains($co, 'cb.textContent'));
$nx = (string) file_get_contents("$root/public/notifications.php");
t('لينكات الإشعارات http/https بس', str_contains($nx, 'function safeUrl') && !str_contains($nx, "href=\"' + A.esc(m.link)"));
$sr = (string) file_get_contents("$root/templates/style-ref-picker.php");
t('صورة اللينك في اختيار الستايل بالـ DOM', !str_contains($sr, "d.innerHTML = '<img src=\"' + url") && str_contains($sr, "createElement('img')"));
[$c] = http('GET', "$base/public/notifications.php", [], $jarC);
t('صفحة الإشعارات بتفتح', $c === 200, (string) $c);

/* ─── 7) سر الكرون ─── */
echo "الكرون\n";
$saved = db_one('SELECT setting_value v FROM settings WHERE setting_key = "cron_secret"')['v'] ?? null;
$sec = 'p6-' . bin2hex(random_bytes(6));
swap('cron_secret', $sec);
[$c, $b] = http('GET', "$base/cron/publish-scheduled.php", [], null, ['X-Cron-Secret: ' . $sec]);
t('الكرون بيقبل السر في الـ header', $c === 200 && get_setting('cron_publish_via_query', '') === '0', $c . ' ' . substr($b, 0, 80));
[$c] = http('GET', "$base/cron/publish-scheduled.php", [], null, ['X-Cron-Secret: غلط']);
t('سر غلط ← 403', $c === 403, (string) $c);
[$c] = http('GET', "$base/cron/publish-scheduled.php?cron_secret=" . urlencode($sec));
t('اللينك القديم (السر في الرابط) لسه شغال ومتعلّم', $c === 200 && get_setting('cron_publish_via_query', '') === '1', (string) $c);
require_once "$root/includes/admin-today.php";
t('و«صحة النظام» بتنبّه عليه', (bool) array_filter(admin_system_health()['warnings'], fn($w) => str_contains($w, 'cron_secret')));
swap('cron_secret', $saved);
swap('cron_publish_via_query', null);

/* ─── تنضيف ─── */
if ($srcRow) @unlink(STORAGE_PATH . '/' . $srcRow['file_path']);
foreach (['brand_sources', 'password_resets', 'brand_kits', 'brand_profiles', 'credit_transactions', 'credit_wallets', 'user_sessions'] as $tb) { try { db_run("DELETE FROM $tb WHERE user_id = ?", [$uid]); } catch (\Throwable $e) {} }
try { db_run('DELETE FROM credit_lots WHERE user_id = ?', [$uid]); } catch (\Throwable $e) {}
try { db_run('DELETE FROM users WHERE id = ?', [$uid]); } catch (\Throwable $e) { echo '  (تنضيف: ' . $e->getMessage() . ")\n"; }
try { db_run('DELETE FROM admin_logs WHERE admin_id = ?', [$adm]); } catch (\Throwable $e) {}
db_run('DELETE FROM admin_users WHERE id = ?', [$adm]);
$clean();
array_map('unlink', glob("$tmp/*") ?: []); @rmdir($tmp); @unlink($jar); @unlink($jarC);

echo "\n" . ($fail ? "✗ $fail فشل · " : '✓ ') . "$pass نجح\n";
exit($fail ? 1 : 0);

<?php
/**
 * Spread AI — تبسيط لوحة الإدارة (خريطة التطوير · المرحلة 5)
 * للتطوير المحلي بس — بيعمل عميل وأدمن مؤقتين ويمسحهم، وبيرجّع الإعدادات زي ما كانت.
 *
 *   php tests/ad5-admin-simple.php http://localhost:8080
 */
if (PHP_SAPI !== 'cli') exit("CLI only\n");
$base = rtrim($argv[1] ?? 'http://localhost:8080', '/');
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require_once __DIR__ . '/../includes/config.php';
if (!APP_DEBUG) exit("APP_DEBUG مقفول — الاختبارات دي للتطوير المحلي بس\n");
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/admin-ui.php';
require_once __DIR__ . '/../includes/managed-accounts.php';
require_once __DIR__ . '/../includes/messaging.php';

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
function loc(string $h): string { return preg_match('/^Location:\s*(\S+)/mi', $h, $m) ? $m[1] : ''; }
function csrf_of(string $html): string { return preg_match('/name="csrf" value="([^"]+)"/', $html, $m) ? $m[1] : ''; }
function admin_login(string $base, string $email, string $jar): array
{
    [, $h] = http('GET', "$base/admin/login.php", [], $jar);
    return http('POST', "$base/admin/login.php", ['csrf' => csrf_of($h), 'email' => $email, 'password' => 'Test12345!'], $jar);
}
function swap(string $k, ?string $v): void { db_run('DELETE FROM settings WHERE setting_key = ?', [$k]); if ($v !== null) set_setting($k, $v); }
function nav_groups(string $html): array { preg_match_all('/class="nav-section nav-gbtn"[^>]*>\s*<span>([^<]+)<\/span>/u', $html, $m); return $m[1]; }

$keys = ['limit_generate_per_10min', 'wa_inbound_enabled'];
$saved = [];
foreach ($keys as $k) $saved[$k] = db_one('SELECT setting_value v FROM settings WHERE setting_key = ?', [$k])['v'] ?? null;
db_run('DELETE FROM rate_limits WHERE action IN ("admin_login", "login", "generate", "admin_search")');
$tag = bin2hex(random_bytes(3));
$superId = (int) db_one('SELECT id FROM admin_users WHERE email = "owner@app.test"')['id'];
$asst = (int) db_insert('INSERT INTO admin_users (name, email, password, role, status) VALUES (?,?,?, "assistant", "active")', ["AD5 مساعد $tag", "ad5-asst-$tag@test.local", password_hash('Test12345!', PASSWORD_DEFAULT)]);
$uid = (int) db_insert('INSERT INTO users (name, email, password, status, email_verified_at, approval_status, approved_at, phone) VALUES (?,?,?, "active", NOW(), "approved", NOW(), ?)',
    ["AD5 عميلة $tag", "ad5-$tag@test.local", password_hash('Test12345!', PASSWORD_DEFAULT), '0109' . random_int(1000000, 9999999)]);
$bid = (int) db_insert('INSERT INTO brand_profiles (user_id, business_name, industry) VALUES (?,?,?)', [$uid, 'براند AD5', 'عيادة']);
db_run('INSERT INTO credit_wallets (user_id, balance) VALUES (?, 100)', [$uid]);
ma_add($uid, $superId);
$failed = (int) db_insert('INSERT INTO contents (user_id, brand_profile_id, content_type, platform, generated_text, status, publish_status, publish_error) VALUES (?,?,?,?,?,?, "failed", ?)', [$uid, $bid, 'introductory', 'both', 'منشور AD5', 'generated', "خطأ AD5 $tag"]);
$chg = (int) db_insert('INSERT INTO contents (user_id, brand_profile_id, content_type, platform, generated_text, status, approval_status, approval_note, approval_at) VALUES (?,?,?,?,?,?, "changes", ?, NOW())', [$uid, $bid, 'introductory', 'both', 'منشور AD5 تاني', 'generated', "غيّروا الصورة $tag"]);
$inId = msg_ready() ? (int) db_insert('INSERT INTO message_inbound (user_id, wa_phone, body) VALUES (?,?,?)', [$uid, '2010' . random_int(10000000, 99999999), "<b>سؤال AD5 $tag</b>"]) : 0;
$jar = tempnam(sys_get_temp_dir(), 'ad5'); $jarA = tempnam(sys_get_temp_dir(), 'ad5');

/* ─── 1) «اليوم» ─── */
echo "«اليوم»\n";
[$c, , $hd] = admin_login($base, 'owner@app.test', $jar);
t('بعد الدخول ← «اليوم»', $c === 302 && str_contains(loc($hd), 'admin/today.php'), loc($hd));
[$c, $h] = http('GET', "$base/admin/today.php", [], $jar);
t('الصفحة بتفتح وفيها فشل النشر وتعديل العميل', $c === 200 && str_contains($h, 'id="q-publish"') && str_contains($h, "خطأ AD5 $tag") && str_contains($h, 'id="q-changes"') && str_contains($h, "غيّروا الصورة $tag"), (string) $c);
if ($inId) {
    t('ورسالة العميل escaped', str_contains($h, 'id="q-inbox"') && str_contains($h, "&lt;b&gt;سؤال AD5 $tag") && !str_contains($h, "<b>سؤال AD5"));
    http('POST', "$base/admin/today.php", ['csrf' => csrf_of($h), 'action' => 'inbound_done', 'inbound_id' => $inId], $jar);
    t('«✓» بيقفل الرسالة من «اليوم»', !empty(db_one('SELECT handled_at FROM message_inbound WHERE id = ?', [$inId])['handled_at']));
}
t('الأحمر قبل الأصفر', (function () use ($h) { $p = strpos($h, 'id="q-publish"'); $w = strpos($h, 'id="q-changes"'); return $p !== false && $w !== false && $p < $w; })());
t('وكارت «حساباتي» فيه العميل', str_contains($h, 'admin/workspace.php?id=' . $uid));
[$c] = http('GET', "$base/admin/dashboard.php", [], $jar);
t('لوحة الأرقام القديمة لسه شغالة', $c === 200, (string) $c);

/* ─── 2) القايمة ─── */
echo "القايمة\n";
$groups = nav_groups($h);
t('القايمة 5 أقسام + «اليوم» واللوحة فوق (بدل 10)', $groups === ['العملاء', 'المحتوى', 'الرسائل', 'الفلوس', 'الإعدادات'], json_encode($groups, JSON_UNESCAPED_UNICODE));
$all = [];
foreach (admin_nav() as $items) foreach ($items as $it) $all[$it[4]] = 1;
t('كل الصفحات القديمة لسه في القايمة (' . count($all) . ' صفحة)', count($all) === 54);
$bad = [];
foreach (array_keys($all) as $u) { [$c] = http('GET', "$base/$u", [], $jar); if ($c !== 200) $bad[] = "$u=$c"; }
t('وكل لينك فيها بيفتح للأدمن الكامل', !$bad, implode(' ', $bad));
admin_login($base, "ad5-asst-$tag@test.local", $jarA);
[$c, $h] = http('GET', "$base/admin/today.php", [], $jarA);
$ga = nav_groups($h);
t('المساعد بيشوف الأقسام اللي صلاحياته بتسمح بيها بس', $c === 200 && !str_contains($h, 'admin/system-health.php') && !str_contains($h, 'admin/settings.php"') && !str_contains($h, 'admin/packages.php'), json_encode($ga, JSON_UNESCAPED_UNICODE));
t('والمساعد مايشوفش تعديل عميل مش في حساباته', !str_contains($h, "غيّروا الصورة $tag"));
[$c] = http('GET', "$base/admin/system-health.php", [], $jarA);
t('«صحة النظام» للأدمن الكامل بس', $c === 403, (string) $c);
[$c, $h] = http('GET', "$base/admin/system-health.php", [], $jar);
t('«صحة النظام» بتعرض الكرون والطوابير والترحيلات', $c === 200 && str_contains($h, 'cron/publish-scheduled.php') && str_contains($h, 'الطوابير') && str_contains($h, 'كل الترحيلات متشغّلة'), (string) $c);

/* ─── 3) البحث العام ─── */
echo "البحث العام\n";
[$c, $b] = http('GET', "$base/admin/search-clients.php?q=" . urlencode("AD5 عميلة $tag"), [], $jar);
$j = json_decode($b, true);
t('بالاسم ← العميلة المُدارة ← «مساحة العميل»', $c === 200 && count($j['clients'] ?? []) === 1 && str_contains($j['clients'][0]['url'], 'admin/workspace.php?id=' . $uid), $b);
$phone = (string) db_one('SELECT phone FROM users WHERE id = ?', [$uid])['phone'];
[, $b] = http('GET', "$base/admin/search-clients.php?q=" . urlencode('+2' . $phone), [], $jar);
t('بالموبايل (بأي صيغة)', str_contains($b, 'workspace.php?id=' . $uid), $b);
[, $b] = http('GET', "$base/admin/search-clients.php?q=" . urlencode("ad5-$tag@"), [], $jarA);
t('المساعد (مش مسؤولها) ← ملف العميل مش المساحة', str_contains($b, 'user-view.php?id=' . $uid) && !str_contains($b, 'workspace.php'), $b);
[$c] = http('GET', "$base/admin/search-clients.php?q=ad5");
t('من غير دخول ← 403', $c === 403, (string) $c);
[, $b] = http('GET', "$base/admin/search-clients.php?q=" . urlencode('%'), [], $jar);
t('حرف % مايرجّعش كل العملاء', count(json_decode($b, true)['clients'] ?? [1]) === 0, $b);
[, $h] = http('GET', "$base/admin/today.php", [], $jar);
t('الشريط العلوي بيعرف endpoint البحث', str_contains($h, 'search-clients.php'));

/* ─── 4) الحدود من الإعدادات ─── */
echo "الحدود\n";
[$c, $h] = http('GET', "$base/admin/settings.php?tab=general", [], $jar);
t('الإعدادات ← عام فيها الحدود الخمسة', $c === 200 && substr_count($h, 'id="f-limit_') === 5, (string) substr_count($h, 'id="f-limit_'));
t('القيم الافتراضية زي اللي كانت في الكود', setting_int('limit_sources_max', 15) === 15 && setting_int('limit_generate_per_10min', 10) === 10 && setting_int('limit_design_per_10min', 6) === 6);
swap('limit_generate_per_10min', '1');
$jarC = tempnam(sys_get_temp_dir(), 'ad5');
[, $h] = http('GET', "$base/public/login.php", [], $jarC);
http('POST', "$base/public/login.php", ['csrf' => csrf_of($h), 'email' => "ad5-$tag@test.local", 'password' => 'Test12345!'], $jarC);
[, $h] = http('GET', "$base/public/create-content.php", [], $jarC);
$gen = fn() => json_decode(http('POST', "$base/public/ajax/generate-content.php", ['csrf' => csrf_of($h), 'content_type' => 'introductory', 'platform' => 'both', 'length' => 'medium', 'dialect' => 'egyptian'], $jarC)[1], true);
$g1 = $gen(); $g2 = $gen();
t('حد التوليد بيتقرا من الإعدادات (1 ← التاني اترفض)', !empty($g1['ok']) && empty($g2['ok']) && str_contains((string) ($g2['error'] ?? ''), 'الأقصى'), json_encode([$g1['ok'] ?? null, $g2['error'] ?? null], JSON_UNESCAPED_UNICODE));
swap('limit_generate_per_10min', 'كلام');
t('قيمة غلط ← الافتراضي', setting_int('limit_generate_per_10min', 10, 1, 200) === 10);

/* ─── تنضيف ─── */
foreach (['message_inbound', 'content_versions' => null, 'contents', 'managed_notes', 'managed_accounts', 'brand_kits', 'brand_profiles', 'credit_transactions', 'credit_wallets', 'user_notifications'] as $k => $tb) {
    if ($tb === null) { try { db_run('DELETE FROM content_versions WHERE content_id IN (SELECT id FROM contents WHERE user_id = ?)', [$uid]); } catch (\Throwable $e) {} continue; }
    try { db_run("DELETE FROM $tb WHERE user_id = ?", [$uid]); } catch (\Throwable $e) {}
}
try { db_run('DELETE FROM users WHERE id = ?', [$uid]); } catch (\Throwable $e) { echo '  (تنضيف: ' . $e->getMessage() . ")\n"; }
try { db_run('DELETE FROM admin_logs WHERE admin_id = ?', [$asst]); } catch (\Throwable $e) {}
db_run('DELETE FROM admin_users WHERE id = ?', [$asst]);
foreach ($saved as $k => $v) swap($k, $v);
db_run('DELETE FROM rate_limits WHERE action IN ("admin_login", "login", "generate", "admin_search")');
foreach ([$jar, $jarA, $jarC] as $f) @unlink($f);

echo "\n" . ($fail ? "✗ $fail فشل · " : '✓ ') . "$pass نجح\n";
exit($fail ? 1 : 0);

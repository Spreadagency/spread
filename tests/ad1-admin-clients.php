<?php
/**
 * Spread AI — الأدمن: «عملاء بنديرهم» (⭐ الأول + فلتر) · استخراج محتوى العميل Excel · تنزيل تصميماته ZIP (الكل أو المختار)
 * للتطوير المحلي بس — بيعمل عميل مؤقت بمحتوى وتصميمات ويمسحه.
 *
 *   php tests/ad1-admin-clients.php http://localhost:8080
 */
if (PHP_SAPI !== 'cli') exit("CLI only\n");
$base = rtrim($argv[1] ?? 'http://localhost:8080', '/');
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require_once __DIR__ . '/../includes/config.php';
if (!APP_DEBUG) exit("APP_DEBUG مقفول — الاختبارات دي للتطوير المحلي بس\n");
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/managed-clients.php';

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
$savedMc = get_setting('managed_client_ids', null);

$tag = bin2hex(random_bytes(3));
$uid = (int) db_insert('INSERT INTO users (name, email, password, status, email_verified_at, approval_status, approved_at, created_at) VALUES (?,?,?, "active", NOW(), "approved", NOW(), DATE_SUB(NOW(), INTERVAL 400 DAY))',
    ["AD1 عميل $tag", "ad1-$tag@test.local", password_hash('x', PASSWORD_DEFAULT)]);
$bid = (int) db_insert('INSERT INTO brand_profiles (user_id, business_name, created_at) VALUES (?,?, NOW())', [$uid, 'براند AD1']);
$cid = (int) db_insert('INSERT INTO campaigns (user_id, title, basis, topic, status, created_at) VALUES (?,?,?,?, "active", NOW())', [$uid, 'حملة AD1', 'awareness', 'x']);
$dir = STORAGE_PATH . '/designs/ad1-' . $tag;
@mkdir($dir, 0775, true);
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
$contents = [];
foreach ([['post', null], ['carousel', [['n' => 1, 'title' => 'غلاف', 'text' => 'نص الشريحة الأولى'], ['n' => 2, 'title' => 'تاني', 'text' => 'نص الشريحة التانية']]]] as $k => [$fmt, $sl]) {
    $co = (int) db_insert('INSERT INTO contents (user_id, brand_profile_id, content_type, platform, generated_text, cta, hashtags, design_direction, status, campaign_id, format, slides_count, slides_json, created_at)
                           VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())', [$uid, $bid, 'introductory', 'both', "هوك $k\n\nنص منشور AD1 رقم $k", 'احجز', '#اختبار', "فكرة تصميم AD1 $k", 'generated', $cid, $fmt,
                           $sl ? count($sl) : null, $sl ? json_encode($sl, JSON_UNESCAPED_UNICODE) : null]);
    $contents[] = $co;
    foreach ($sl ? [1, 2] : [null] as $s) {
        $rel = 'designs/ad1-' . $tag . '/d' . $co . '-' . (int) $s . '.png';
        file_put_contents(STORAGE_PATH . '/' . $rel, $png);
        db_insert('INSERT INTO content_designs (content_id, user_id, image_path, model, credits_used, ratio, slide_no) VALUES (?,?,?,?,0,"1:1",?)', [$co, $uid, $rel, 'mock-image', $s]);
    }
}
// مسار ضار: لازم يتجاهل (مايخرجش برّه storage)
db_insert('INSERT INTO content_designs (content_id, user_id, image_path, model, credits_used, ratio) VALUES (?,?,?,?,0,"1:1")', [$contents[0], $uid, '../includes/config.php', 'x']);

// دخول الأدمن
$jar = tempnam(sys_get_temp_dir(), 'ad1');
[, $h] = http('GET', "$base/admin/login.php", [], $jar);
preg_match('/name="csrf" value="([^"]+)"/', $h, $m);
http('POST', "$base/admin/login.php", ['csrf' => $m[1] ?? '', 'email' => 'owner@app.test', 'password' => 'Test12345!'], $jar);
$csrfOf = function (string $html): string { return preg_match('/name="csrf" value="([^"]+)"/', $html, $mm) ? $mm[1] : ''; };

/* ─── 1) «عملاء بنديرهم» ─── */
echo "عملاء بنديرهم\n";
[$c, $h] = http('GET', "$base/admin/users.php", [], $jar);
t('صفحة العملاء فيها فلتر «عملاء بنديرهم»', $c === 200 && str_contains($h, 'عملاء بنديرهم'), (string) $c);
http('POST', "$base/admin/users.php", ['csrf' => $csrfOf($h), 'action' => 'toggle_managed', 'uid' => $uid], $jar);
t('⭐ اتضاف', mc_is($uid) || in_array($uid, array_map('intval', json_decode((string) get_setting('managed_client_ids', '[]'), true) ?: []), true));
[$c, $h] = http('GET', "$base/admin/users.php", [], $jar);
preg_match_all('/admin\/user-view\.php\?id=(\d+)"/', $h, $mm);
$first = array_values(array_unique(array_map('intval', $mm[1])));
t('العميل اللي بنديره بيظهر في أول القايمة', ($first[0] ?? 0) === $uid || (count(mc_ids(true)) > 1 && in_array($uid, array_slice($first, 0, count(mc_ids(true))), true)), json_encode(array_slice($first, 0, 3)));
[$c, $h] = http('GET', "$base/admin/users.php?managed=1", [], $jar);
preg_match_all('/admin\/user-view\.php\?id=(\d+)"/', $h, $mm);
$ids = array_values(array_unique(array_map('intval', $mm[1])));
t('فلتر «بنديرهم» بيعرضهم بس', $c === 200 && in_array($uid, $ids, true) && !array_diff($ids, mc_ids(true)), json_encode($ids));
[$c, $h] = http('GET', "$base/admin/user-view.php?id=$uid", [], $jar);
t('ملف العميل فيه ⭐ وأزرار Excel والتصميمات', $c === 200 && str_contains($h, '⭐ ملف العميل') && str_contains($h, 'user-content-export.php?id=' . $uid) && str_contains($h, 'user-designs.php?id=' . $uid));

/* ─── 2) Excel ─── */
echo "استخراج المحتوى Excel\n";
[$c, $b, $hd] = http('GET', "$base/admin/user-content-export.php?id=$uid", [], $jar);
$xml = '';
if (str_starts_with($b, 'PK')) {
    $tf = tempnam(sys_get_temp_dir(), 'x'); file_put_contents($tf, $b);
    $z = new ZipArchive(); $z->open($tf);
    for ($i = 0; $i < $z->numFiles; $i++) $xml .= (string) $z->getFromIndex($i);
    $z->close(); @unlink($tf);
}
t('ملف xlsx حقيقي', $c === 200 && str_contains($hd, 'spreadsheetml') && str_starts_with($b, 'PK'), $c . ' ' . substr($hd, 0, 120));
t('فيه النص والـ CTA وفكرة التصميم والشرائح ولينكات التصميمات', str_contains($xml, 'نص منشور AD1 رقم 0') && str_contains($xml, 'فكرة تصميم AD1 1') && str_contains($xml, 'نص الشريحة التانية') && str_contains($xml, 'حملة AD1') && str_contains($xml, 'storage/designs/ad1-' . $tag));

/* ─── 3) التصميمات ─── */
echo "تنزيل التصميمات\n";
[$c, $h] = http('GET', "$base/admin/user-designs.php?id=$uid", [], $jar);
preg_match_all('/name="keys\[\]" value="([ps]\d+)"/', $h, $km);
t('صفحة التصميمات بتعرض الـ 3 تصميمات السليمة بس (المسار الضار مش ظاهر)', $c === 200 && count($km[1]) === 3, count($km[1]) . '');
$zipOf = function (string $b): array {
    if (!str_starts_with($b, 'PK')) return [];
    $tf = tempnam(sys_get_temp_dir(), 'z'); file_put_contents($tf, $b);
    $z = new ZipArchive(); $z->open($tf); $names = [];
    for ($i = 0; $i < $z->numFiles; $i++) $names[] = $z->getNameIndex($i);
    $z->close(); @unlink($tf);
    return $names;
};
[$c, $b, $hd] = http('POST', "$base/admin/user-designs.php", ['csrf' => $csrfOf($h), 'id' => $uid, 'mode' => 'all'], $jar);
$names = $zipOf($b);
t('«نزّل الكل» ← ZIP فيه الـ 3 ملفات السليمة جوه فولدر الحملة', $c === 200 && count($names) === 3 && count(array_filter($names, fn($n) => str_starts_with($n, 'حملة AD1/'))) === 3, json_encode($names, JSON_UNESCAPED_UNICODE));
t('المسار الضار (برّه storage) اتجاهل', !array_filter($names, fn($n) => str_contains($n, 'config')));
[$c, $b] = http('POST', "$base/admin/user-designs.php", ['csrf' => $csrfOf($h), 'id' => $uid, 'mode' => 'pick', 'keys' => [$km[1][0]]], $jar);
t('«نزّل المختار» ← تصميم واحد', count($zipOf($b)) === 1);
[$c, , $hd] = http('POST', "$base/admin/user-designs.php", ['csrf' => $csrfOf($h), 'id' => $uid, 'mode' => 'pick'], $jar);
t('من غير اختيار ← رجوع برسالة', $c === 302);

/* ─── تنضيف ─── */
db_run('DELETE FROM content_designs WHERE user_id = ?', [$uid]);
db_run('DELETE FROM contents WHERE user_id = ?', [$uid]);
db_run('DELETE FROM campaigns WHERE user_id = ?', [$uid]);
db_run('DELETE FROM brand_profiles WHERE user_id = ?', [$uid]);
db_run('DELETE FROM users WHERE id = ?', [$uid]);
array_map('unlink', glob($dir . '/*') ?: []);
@rmdir($dir);
db_run('DELETE FROM settings WHERE setting_key = "managed_client_ids"');
if ($savedMc !== null) set_setting('managed_client_ids', $savedMc);
@unlink($jar);

echo "\n" . ($fail ? "✗ $fail فشل · " : '✓ ') . "$pass نجح\n";
exit($fail ? 1 : 0);

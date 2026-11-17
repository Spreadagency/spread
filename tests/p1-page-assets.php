<?php
/**
 * Spread AI — المرحلة 1 من تقرير الاختبار: اللوجو والتصميمات من صفحة العميل في رحلة «ابدأ»
 *   + لوجو Spread بدل حرف S (الموقع · المنصة · الأدمن).
 * للتطوير المحلي بس: بيشغّل Graph API وهمي (tests/mock-graph.php) على 127.0.0.1:8093 ويوجّه الموقع ليه مؤقتًا،
 * وبيمسح كل حاجة في الآخر.
 *
 *   php tests/p1-page-assets.php http://localhost:8080
 */
if (PHP_SAPI !== 'cli') exit("CLI only\n");
$base = rtrim($argv[1] ?? 'http://localhost:8080', '/');
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require_once __DIR__ . '/../includes/config.php';
if (!APP_DEBUG) exit("APP_DEBUG مقفول — الاختبارات دي للتطوير المحلي بس\n");
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/social.php';
require_once __DIR__ . '/../includes/page-assets.php';

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
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 90, CURLOPT_HEADER => true, CURLOPT_HTTPHEADER => $headers]);
    if ($jar) curl_setopt_array($ch, [CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar]);
    if ($method === 'POST') curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => is_string($post) ? $post : http_build_query($post)]);
    $raw = (string) curl_exec($ch);
    $c = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hs = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return [$c, substr($raw, $hs), substr($raw, 0, $hs)];
}
function csrf_of(string $html): string { return preg_match('/name="csrf" value="([^"]+)"/', $html, $m) ? $m[1] : (preg_match('/name="csrf-token" content="([^"]+)"/', $html, $m) ? $m[1] : ''); }

$tag = bin2hex(random_bytes(3));
$stateFile = sys_get_temp_dir() . '/spread-mock-graph.json';
$proc = proc_open([PHP_BINARY, '-S', '127.0.0.1:8093', __DIR__ . '/mock-graph.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
usleep(400000);
$savedGraph = get_setting('graph_base_url', null);
set_setting('graph_base_url', 'http://127.0.0.1:8093/v21.0');
$uid = 0; $files = [];

try {
    echo "1) لوجو Spread بدل حرف S\n";
    foreach (['public/login.php', 'public/register.php', 'public/forgot-password.php', 'public/verify-email.php', 'admin/login.php'] as $p) {
        [$c, $h] = http('GET', "$base/$p");
        t("$p: لوجو Spread ومفيش «S»", $c === 200 && str_contains($h, 'spread-mark') && !str_contains($h, 'brand-mark">S<') && !str_contains($h, '>S</text>'), (string) $c);
    }
    [$c] = http('GET', "$base/site-assets/img/spread-mark.png");
    t('لوجو الموقع التعريفي (site-assets) موجود', $c === 200, (string) $c);
    t('site_logo_html من غير لوجو مرفوع ← صورة Spread', str_contains(site_logo_html(), 'spread-mark-128.png') || site_setting('site_logo', '') !== '');

    echo "2) قراءة الموقع (HTML)\n";
    $html = '<html><head><link rel="apple-touch-icon" sizes="180x180" href="/apple-icon.png"><link rel="icon" href="/favicon.ico">'
        . '<link rel="icon" sizes="16x16" href="/tiny.png"><meta property="og:image" content="https://cdn.example.com/cover.jpg"></head><body>'
        . '<img src="img/site-logo.png" alt="Clinic"><img class="brand-logo" src="//cdn.example.com/l2.webp">'
        . '<img src="/photos/team.jpg" width="800" height="600"><img src="/icons/arrow.svg"><img src="/thumb.jpg" width="40" height="40">'
        . '<img data-src="/lazy/work-1.jpg" alt="شغلنا"><img srcset="/r/work-2-400.jpg 400w, /r/work-2-800.jpg 800w">'
        . '<img src="https://track.example.com/pixel.gif"></body></html>';
    $r = pa_from_html($html, 'https://clinic.example.com/ar/home.html');
    t('أيقونة الموقع الكبيرة لوجو (والصغيرة و.ico لأ)', in_array('https://clinic.example.com/apple-icon.png', $r['logos'], true) && !preg_grep('/favicon\.ico|tiny\.png/', $r['logos']), json_encode($r['logos']));
    t('صورة فيها «logo» (لينك نسبي ← مطلق)', in_array('https://clinic.example.com/ar/img/site-logo.png', $r['logos'], true) && in_array('https://cdn.example.com/l2.webp', $r['logos'], true));
    t('الصورة الرئيسية (og:image) أول صورة', ($r['images'][0] ?? '') === 'https://cdn.example.com/cover.jpg', json_encode($r['images']));
    t('صور الصفحة الكبيرة + lazy + srcset', in_array('https://clinic.example.com/photos/team.jpg', $r['images'], true) && in_array('https://clinic.example.com/lazy/work-1.jpg', $r['images'], true) && in_array('https://clinic.example.com/r/work-2-400.jpg', $r['images'], true));
    t('من غير svg ولا صور صغيرة ولا pixel', !preg_grep('/arrow\.svg|thumb\.jpg|pixel\.gif/', $r['images']));

    echo "3) لينك فيسبوك\n";
    foreach (['https://www.facebook.com/SmileClinicEG' => 'SmileClinicEG', 'facebook.com/SmileClinicEG/photos' => 'SmileClinicEG', 'https://m.facebook.com/profile.php?id=1000123' => '1000123',
              'https://www.facebook.com/pages/Smile/123456789' => '123456789', 'https://www.facebook.com/groups/abc' => '', 'https://example.com/x' => ''] as $u => $want) {
        t("«{$u}» ← «{$want}»", pa_fb_page_ref($u) === $want, pa_fb_page_ref($u));
    }
    file_put_contents($stateFile, json_encode(['pictures' => ['SmileClinicEG' => 'https://cdn.example.com/fb-pic.jpg']]));
    $r = pa_from_url('https://www.facebook.com/SmileClinicEG');
    t('صفحة فيسبوك من غير ربط ← صورة البروفايل العامة لوجو', $r['social'] && $r['logos'] === ['https://cdn.example.com/fb-pic.jpg'], json_encode($r));

    echo "4) حفظ الصورة (لازم تكون صورة فعلًا)\n";
    $im = imagecreatetruecolor(120, 120);
    imagefill($im, 0, 0, imagecolorallocate($im, 20, 120, 200));
    ob_start(); imagepng($im); $png = ob_get_clean();
    imagedestroy($im);
    $p = pa_store_bytes($png, 'logos');
    if ($p) $files[] = $p;
    t('PNG حقيقية بتتحفظ', $p !== null && is_file(STORAGE_PATH . '/' . $p), (string) $p);
    t('ملف مش صورة ← مرفوض', pa_store_bytes('<?php echo 1; ?>', 'logos') === null);
    $im = imagecreatetruecolor(20, 20); ob_start(); imagepng($im); $tiny = ob_get_clean(); imagedestroy($im);
    t('صورة صغيرة أوي ← مرفوضة', pa_store_bytes($tiny, 'logos') === null);
    t('اسم فولدر غريب ← مرفوض', pa_store_bytes($png, '../x') === null);
    t('عنوان داخلي (127.0.0.1) مايتحمّلش', pa_store('http://127.0.0.1:8093/x.png', 'logos') === null);

    echo "5) الصفحة المربوطة بميتا\n";
    $email = "pa-$tag@test.local";
    $uid = (int) db_insert('INSERT INTO users (name, email, phone, password, status, email_verified_at, approval_status, approved_at) VALUES (?,?,?,?, "active", NOW(), "approved", NOW())',
        ['PA Test', $email, '010' . random_int(10000000, 99999999), password_hash('Test12345!', PASSWORD_DEFAULT)]);
    db_insert('INSERT INTO brand_profiles (user_id, business_name) VALUES (?,?)', [$uid, 'عيادة الصفحة']);
    db_insert('INSERT INTO social_connections (user_id, platform, provider_page_id, page_name, ig_user_id, ig_username, access_token_enc, token_type, scopes, status, last_verified_at)
               VALUES (?, "facebook", "5551", "صفحة العيادة", "5552", "clinic_ig", ?, "page", "pages_read_engagement", "active", NOW())', [$uid, social_token_encrypt('tok_ok')]);
    file_put_contents($stateFile, json_encode([
        'pictures' => ['5551' => 'https://cdn.example.com/page-pic.jpg'],
        'ig_pictures' => ['5552' => 'https://cdn.example.com/ig-pic.jpg'],
        'pages' => ['5551' => [['id' => '5551_1', 'message' => 'a', 'created_time' => '2026-11-01T10:00:00+0000', 'full_picture' => 'https://cdn.example.com/post1.jpg'],
                               ['id' => '5551_2', 'message' => 'b', 'created_time' => '2026-11-02T10:00:00+0000'],
                               ['id' => '5551_3', 'message' => 'c', 'created_time' => '2026-11-03T10:00:00+0000', 'full_picture' => 'https://cdn.example.com/post3.jpg']]],
        'ig' => ['5552' => [['id' => 'ig1', 'caption' => 'x', 'timestamp' => '2026-11-01T10:00:00+0000', 'media_type' => 'IMAGE', 'media_url' => 'https://cdn.example.com/ig1.jpg'],
                            ['id' => 'ig2', 'caption' => 'y', 'timestamp' => '2026-11-02T10:00:00+0000', 'media_type' => 'VIDEO', 'media_url' => 'https://cdn.example.com/v.mp4', 'thumbnail_url' => 'https://cdn.example.com/ig2-thumb.jpg']]],
    ]));
    $m = pa_from_meta($uid);
    t('صورة الصفحة وصورة إنستجرام ← اقتراحات لوجو', $m['connected'] && $m['logos'] === ['https://cdn.example.com/page-pic.jpg', 'https://cdn.example.com/ig-pic.jpg'], json_encode($m['logos']));
    t('صور البوستات (فيسبوك + إنستجرام · الفيديو بصورته المصغّرة)', $m['images'] === ['https://cdn.example.com/post1.jpg', 'https://cdn.example.com/post3.jpg', 'https://cdn.example.com/ig1.jpg', 'https://cdn.example.com/ig2-thumb.jpg'], json_encode($m['images']));

    echo "6) الـ API في رحلة «ابدأ»\n";
    db_run('DELETE FROM rate_limits WHERE action IN ("login", "login_acct", "bk_page_assets")');
    $j = tempnam(sys_get_temp_dir(), 'pa');
    [, $lp] = http('GET', "$base/public/login.php", [], $j);
    http('POST', "$base/public/login.php", ['csrf' => csrf_of($lp), 'email' => $email, 'password' => 'Test12345!'], $j);
    [$c, $sp] = http('GET', "$base/public/start.php", [], $j);
    $cs = csrf_of($sp);
    t('«ابدأ» فيها «اربط صفحتك» وأماكن اقتراحات اللوجو والتصميمات', $c === 200 && str_contains($sp, 'id="meta-early"') && str_contains($sp, 'id="pa-logos"') && str_contains($sp, 'id="pa-imgs"'), (string) $c);
    $H = ['X-CSRF-Token: ' . $cs, 'Content-Type: application/json'];
    [$c, $b] = http('POST', "$base/public/api/brand-kit.php?action=page_assets", json_encode(['url' => '']), $j, $H);
    $r = json_decode($b, true) ?: [];
    t('page_assets بيرجع اللوجو والتصميمات من الصفحة المربوطة', $c === 200 && !empty($r['connected']) && count($r['logos'] ?? []) === 2 && count($r['images'] ?? []) === 4, "$c " . substr($b, 0, 200));
    [$c, $b] = http('POST', "$base/public/api/brand-kit.php?action=page_logo_use", json_encode(['url' => 'https://evil.example.com/x.png']), $j, $H);
    t('لوجو من لينك مش من الاقتراحات ← مرفوض', $c === 422 && str_contains($b, 'مش من الاقتراحات'), "$c " . substr($b, 0, 120));
    [$c, $b] = http('POST', "$base/public/api/brand-kit.php?action=page_logo_use", json_encode(['url' => 'https://cdn.example.com/page-pic.jpg']), $j, $H);
    t('اقتراح ماتحمّلش (الصورة مش متاحة) ← رسالة واضحة «ارفعه من جهازك»', $c === 422 && str_contains($b, 'ارفع اللوجو من جهازك'), "$c " . substr($b, 0, 120));
    [$c, $b] = http('POST', "$base/public/api/brand-kit.php?action=page_insp_add", json_encode(['urls' => ['https://evil.example.com/a.jpg']]), $j, $H);
    t('تصميمات من لينكات مش من الاقتراحات ← مفيش حاجة اتحفظت', $c === 422 && !db_one('SELECT id FROM brand_inspirations WHERE user_id = ?', [$uid]), "$c " . substr($b, 0, 120));
    @unlink($j);
} finally {
    if ($uid) {
        try { db_run('DELETE FROM brand_inspirations WHERE user_id = ?', [$uid]); } catch (\Throwable $e) {}
        foreach (['social_connections', 'brand_kits', 'brand_profiles', 'credit_transactions', 'credit_wallets', 'user_sessions', 'remember_tokens'] as $tb) {
            try { db_run("DELETE FROM $tb WHERE user_id = ?", [$uid]); } catch (\Throwable $e) {}
        }
        db_run('DELETE FROM users WHERE id = ?', [$uid]);
    }
    foreach ($files as $f) @unlink(STORAGE_PATH . '/' . $f);
    if ($savedGraph === null) db_run('DELETE FROM settings WHERE setting_key = "graph_base_url"'); else set_setting('graph_base_url', (string) $savedGraph);
    @unlink($stateFile);
    proc_terminate($proc);
}
echo "\n" . ($fail ? "✗ $fail فشل · " : '✓ ') . "$pass نجح\n";
exit($fail ? 1 : 0);

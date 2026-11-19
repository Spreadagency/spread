<?php
/**
 * Spread AI — «Trend Studio»: أفكار الترند بتوصل للـ AI بالهوية كاملة (التفاصيل · الألوان · اللوجو) وصورة الترند،
 * والرد مابيضيعش لو اتقطع أو جه بشكل مختلف («الـ AI رجّع رد مش مفهوم»).
 * للتطوير المحلي بس — بيعمل حساب مؤقت ويمسحه، وبيرجّع صورة الترند زي ما كانت.
 *
 *   php tests/p3-trend.php http://localhost:8080
 */
if (PHP_SAPI !== 'cli') exit("CLI only\n");
$base = rtrim($argv[1] ?? 'http://localhost:8080', '/');
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require_once __DIR__ . '/../includes/config.php';
if (!APP_DEBUG) exit("APP_DEBUG مقفول — الاختبارات دي للتطوير المحلي بس\n");
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/brand-kit.php';
require_once __DIR__ . '/../includes/trends.php';

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
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 120, CURLOPT_HEADER => true, CURLOPT_HTTPHEADER => $headers]);
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
$uid = 0; $files = []; $trendId = 0; $oldRef = null; $newTrend = false;
try {
    echo "1) قراءة الرد\n";
    $idea = fn($n) => ['title' => "فكرة $n", 'desc' => 'شرح', 'headline' => 'عنوان', 'cta' => 'احجز', 'caption' => 'كابشن طويل', 'hashtags' => '#x'];
    $cases = [
        'object' => json_encode(['ideas' => [$idea(1), $idea(2), $idea(3)]], JSON_UNESCAPED_UNICODE),
        'array' => json_encode([$idea(1), $idea(2)], JSON_UNESCAPED_UNICODE),
        'arabic key' => json_encode(['الأفكار' => [$idea(1)]], JSON_UNESCAPED_UNICODE),
        'fenced' => "```json\n" . json_encode(['ideas' => [$idea(1)]], JSON_UNESCAPED_UNICODE) . "\n```",
    ];
    foreach ($cases as $k => $raw) t("الرد ($k) بيتقرا", count(ai_json_list($raw, ['ideas', 'الأفكار', 'items', 'data'], 'title')) >= 1);
    $cut = substr(json_encode(['ideas' => [$idea(1), $idea(2), $idea(3)]], JSON_UNESCAPED_UNICODE), 0, -40);
    t('رد مقطوع (الحد اتعدّى) ← الأفكار الكاملة بتفضل (2 من 3) بدل «رد مش مفهوم»', count(ai_json_list($cut, ['ideas'], 'title')) === 2);

    echo "2) الطلب بيوصل للـ AI بالهوية والصور\n";
    // ترند مؤقت شغال (بيتمسح في الآخر)
    $trendId = (int) db_insert('INSERT INTO trends (code, name, type, platforms, industries, description, structure, allowed, locked, status, start_date, end_date) VALUES (?,?,?,?,?,?,?,?,?, "active", CURDATE() - INTERVAL 1 DAY, CURDATE() + INTERVAL 7 DAY)',
        ["T-$tag", "ترند الاختبار $tag", 'Visual', 'Facebook,Instagram', 'مطاعم', 'صورة قبل وبعد', "قبل\nبعد\nالنتيجة", 'الألوان', 'التقسيم', ]);
    $newTrend = true;
    if (!trend_get_live($trendId)) throw new \RuntimeException('الترند المؤقت مش شغال');
    @mkdir(STORAGE_PATH . '/uploads/designs', 0755, true);
    $mkPng = function (string $rel, array $rgb) use (&$files) {
        $im = imagecreatetruecolor(200, 200);
        imagefill($im, 0, 0, imagecolorallocate($im, ...$rgb));
        imagepng($im, STORAGE_PATH . '/' . $rel);
        imagedestroy($im);
        $files[] = $rel;
    };
    $refRel = "uploads/designs/trend-ref-$tag.png";
    $logoRel = "uploads/designs/logo-$tag.png";
    $mkPng($refRel, [200, 40, 40]);
    $mkPng($logoRel, [20, 120, 220]);
    db_run('UPDATE trends SET ref_image_path = ? WHERE id = ?', [$refRel, $trendId]);

    $email = "tr3-$tag@test.local";
    $uid = (int) db_insert('INSERT INTO users (name, email, phone, password, status, email_verified_at, approval_status, approved_at) VALUES (?,?,?,?, "active", NOW(), "approved", NOW())',
        ['Trend Test', $email, '010' . random_int(10000000, 99999999), password_hash('Test12345!', PASSWORD_DEFAULT)]);
    db_insert('INSERT INTO brand_profiles (user_id, business_name, industry, logo_path, design_rules, brand_approved_at) VALUES (?,?,?,?,?, NOW())',
        [$uid, "كافيه الترند $tag", 'كافيه', $logoRel, 'خط عريض وخلفيات فاتحة']);
    credits_add($uid, 50, 'اختبار الترند', null, 'test', null, 30);
    bk_get($uid);
    bk_save_section($uid, 'colors', array_merge(bk_get($uid)['data']['colors'], ['primary' => '#1D4E89', 'secondary' => '#F49D37', 'accent' => '#2EE3CC']), 'user', 'اختبار');

    db_run('DELETE FROM rate_limits WHERE action IN ("login", "login_acct", "studio_idea")');
    $j = tempnam(sys_get_temp_dir(), 'tr3');
    [, $lp] = http('GET', "$base/public/login.php?ui=v2", [], $j);
    http('POST', "$base/public/login.php", ['csrf' => csrf_of($lp), 'email' => $email, 'password' => 'Test12345!'], $j);
    [, $d] = http('GET', "$base/public/dashboard.php?onb=skip", [], $j);
    $cs = csrf_of($d);
    @unlink(STORAGE_PATH . '/logs/mock-ai-last.json');
    [$c, $b] = http('POST', "$base/public/api/studio.php?action=trend_ideas", json_encode(['trend_id' => $trendId]), $j, ['X-CSRF-Token: ' . $cs, 'Content-Type: application/json']);
    $r = json_decode($b, true) ?: [];
    t('الأفكار رجعت (مش «رد مش مفهوم»)', $c === 200 && !empty($r['ok']) && count($r['ideas'] ?? []) >= 1, "$c " . substr($b, 0, 160));
    $req = (string) @file_get_contents(STORAGE_PATH . '/logs/mock-ai-last.json');
    $rj = json_decode($req, true) ?: [];
    $text = ''; $images = 0;
    foreach ((array) ($rj['messages'] ?? []) as $m) {
        if (is_string($m['content'] ?? null)) { $text .= $m['content']; continue; }
        foreach ((array) ($m['content'] ?? []) as $part) {
            if (($part['type'] ?? '') === 'text') $text .= (string) $part['text'];
            if (in_array($part['type'] ?? '', ['image_url', 'image', 'input_image'], true)) $images++;
        }
    }
    if ($text === '') $text = $req;
    if (!$images) $images = substr_count($req, 'data:image/');
    t('اسم البراند وتفاصيله وصلوا', str_contains($text, "كافيه الترند $tag"), mb_substr($text, 0, 120));
    t('ألوان البراند وصلت صريحة', str_contains($text, '#1D4E89') && str_contains($text, '#F49D37'));
    t('قواعد التصميم وصلت', str_contains($text, 'خط عريض وخلفيات فاتحة'));
    t('صورة الترند واللوجو اتبعتوا كصور (2)', $images === 2, (string) $images);
    t('والبرومبت بيشرح كل صورة (ترند · لوجو)', str_contains($text, 'صورة الترند الأصلية') && str_contains($text, 'لوجو البراند'));
    t('حد الرد كفاية (مش 1000)', (int) ($rj['max_tokens'] ?? $rj['max_completion_tokens'] ?? 0) >= 3500, (string) ($rj['max_tokens'] ?? $rj['max_completion_tokens'] ?? 'none'));
    @unlink($j);
} catch (\Throwable $e) {
    t('الاختبار كمل', false, $e->getMessage());
} finally {
    if ($trendId && $newTrend) db_run('DELETE FROM trends WHERE id = ? AND code = ?', [$trendId, "T-$tag"]);
    if ($uid) {
        foreach (['brand_kit_versions' => 'DELETE v FROM brand_kit_versions v JOIN brand_kits k ON k.id = v.brand_kit_id WHERE k.user_id = ?'] as $q) { try { db_run($q, [$uid]); } catch (\Throwable $e) {} }
        foreach (['brand_kits', 'brand_profiles', 'credit_lots', 'credit_transactions', 'credit_wallets', 'user_sessions', 'remember_tokens'] as $tb) {
            try { db_run("DELETE FROM $tb WHERE user_id = ?", [$uid]); } catch (\Throwable $e) {}
        }
        db_run('DELETE FROM users WHERE id = ?', [$uid]);
    }
    foreach ($files as $f) @unlink(STORAGE_PATH . '/' . $f);
}
echo "\n" . ($fail ? "✗ $fail فشل · " : '✓ ') . "$pass نجح\n";
exit($fail ? 1 : 0);

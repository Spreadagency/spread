<?php
/**
 * Spread AI — المرحلة 2 من تقرير الاختبار:
 *   «يحتاج إجراء» نفس الرقم في الرئيسية والجرس وصفحة المحتوى ولينكات بالفلتر · التحليلات بتعرف إن الصفحة مربوطة
 *   · مؤشر تحميل بدل الصفحة البيضا · سبب زرار توليد الأفكار ومؤشره · الأفكار ماتكررش آخر المنشورات + الشهر والموسم
 *   ومن غير وعود مش موجودة · من غير كلام طبي لكل البراندات · الجرس طلب واحد في نفس الوقت
 *   · التنقّل بين مراحل الحملة (المحتوى ← التصميم في وضع الطلب الواحد) وتقييم الخطة في «المحتوى».
 * للتطوير المحلي بس — بيعمل حسابات مؤقتة ويمسحها.
 *
 *   php tests/p2-report-fixes.php http://localhost:8080
 */
if (PHP_SAPI !== 'cli') exit("CLI only\n");
$base = rtrim($argv[1] ?? 'http://localhost:8080', '/');
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require_once __DIR__ . '/../includes/config.php';
if (!APP_DEBUG) exit("APP_DEBUG مقفول — الاختبارات دي للتطوير المحلي بس\n");
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/lifecycle.php';
require_once __DIR__ . '/../includes/campaign-flow.php';
require_once __DIR__ . '/../includes/campaign-quality.php';
require_once __DIR__ . '/../includes/ui-v2.php';
require_once __DIR__ . '/../includes/dashboard-v2.php';

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
$uid = 0;
try {
    $email = "p2-$tag@test.local";
    $uid = (int) db_insert('INSERT INTO users (name, email, phone, password, status, email_verified_at, approval_status, approved_at) VALUES (?,?,?,?, "active", NOW(), "approved", NOW())',
        ['P2 Test', $email, '010' . random_int(10000000, 99999999), password_hash('Test12345!', PASSWORD_DEFAULT)]);
    $bid = (int) db_insert('INSERT INTO brand_profiles (user_id, business_name, industry, brand_approved_at) VALUES (?,?,?, NOW())', [$uid, 'محل P2', 'ملابس']);
    $mk = function (array $f) use ($uid, $bid) {
        return (int) db_insert('INSERT INTO contents (user_id, brand_profile_id, content_type, platform, generated_text, status, publish_status, workflow_flag) VALUES (?,?,?,?,?,?,?,?)',
            [$uid, $bid, 'introductory', 'both', $f['text'] ?? 'نص', 'generated', $f['ps'] ?? 'draft', $f['flag'] ?? 'none']);
    };
    // 2 فشل نشر · 1 مراجعة · 2 تصميم (1 needs_design + 1 content_ready) · 1 تصميم جاهز
    $mk(['ps' => 'failed']); $mk(['ps' => 'failed']); $mk(['flag' => 'needs_review']); $mk(['flag' => 'needs_design']);
    $mk(['text' => "لبس الشتا وصل والأسعار حلوة جدًا في محلنا {$tag}\nتعالى شوف"]);
    $dr = $mk([]); db_insert('INSERT INTO content_designs (content_id, user_id, image_path, prompt, model, credits_used) VALUES (?,?,?,?,?,1)', [$dr, $uid, 'uploads/designs/x.png', 'x', 'mock']);

    echo "1) «يحتاج إجراء» رقم واحد\n";
    $ac = content_action_counts($uid);
    t('العدّ: 2 فشل · 1 مراجعة · 2 تصميم · المجموع 5 (والجاهز للجدولة برا المجموع)', $ac['failed'] === 2 && $ac['review'] === 1 && $ac['design'] === 2 && $ac['total'] === 5 && $ac['ready'] === 1, json_encode($ac));
    $user = db_one('SELECT * FROM users WHERE id = ?', [$uid]);
    $d = dashboard_v2_data($user);
    t('الرئيسية: نفس المجموع (5)', (int) $d['actionsTotal'] === 5, (string) $d['actionsTotal']);
    $urls = array_column($d['actions'], 'url');
    t('كل شريحة بتفتح المحتوى بالفلتر بتاعها', in_array('content-history.php?status=publish_failed', $urls, true) && in_array('content-history.php?status=needs_design,content_ready', $urls, true)
        && in_array('content-history.php?status=needs_review', $urls, true) && $d['actionsAll'] === 'content-history.php?needs=1', json_encode($urls));
    $nsum = 0;
    foreach (ui_notifications($uid)['items'] as $it) if (preg_match('/^(\d+) منشور/u', (string) $it['title'], $m)) $nsum += (int) $m[1];
    t('الجرس: نفس الأرقام (المجموع 5)', $nsum === 5, (string) $nsum);
    db_run('DELETE FROM rate_limits WHERE action IN ("login", "login_acct")');
    $j = tempnam(sys_get_temp_dir(), 'p2');
    [, $lp] = http('GET', "$base/public/login.php?ui=v2", [], $j);   // الشكل الجديد (الحملات والتحليلات)
    http('POST', "$base/public/login.php", ['csrf' => csrf_of($lp), 'email' => $email, 'password' => 'Test12345!'], $j);
    [, $r] = http('GET', "$base/public/api/contents.php?action=library", [], $j);
    $lib = json_decode($r, true) ?: [];
    t('صفحة المحتوى: «⚡ يحتاج إجراء» = 5', (int) ($lib['actionCount'] ?? -1) === 5, (string) ($lib['actionCount'] ?? substr($r, 0, 120)));
    [, $r] = http('GET', "$base/public/api/contents.php?action=library&status=needs_design,content_ready", [], $j);
    t('فلتر «يحتاج تصميم» (حالتين) بيرجع الاتنين', (int) (json_decode($r, true)['total'] ?? -1) === 2, substr($r, 0, 120));

    echo "2) التحليلات\n";
    [, $an] = http('GET', "$base/public/analytics.php", [], $j);
    t('من غير ربط ← «اربط صفحتك» بلينك', str_contains($an, 'اربط صفحتك على فيسبوك وإنستجرام') && str_contains($an, 'social-accounts.php'));
    require_once __DIR__ . '/../includes/social.php';
    db_insert('INSERT INTO social_connections (user_id, platform, provider_page_id, page_name, access_token_enc, token_type, status) VALUES (?, "facebook", "9991", ?, ?, "page", "active")',
        [$uid, "صفحة P2 $tag", social_token_encrypt('tok_ok')]);
    [, $an] = http('GET', "$base/public/analytics.php", [], $j);
    t('مربوطة ← مابتطلبش الربط وبتقول اسم الصفحة', !str_contains($an, 'اربط صفحتك على فيسبوك وإنستجرام') && str_contains($an, "صفحتك مربوطة (صفحة P2 $tag)"));

    echo "3) التحميل والتوليد\n";
    [, $page] = http('GET', "$base/public/content-history.php", [], $j);
    t('قبل ما الواجهة تجهز: مؤشر تحميل بدل الصفحة البيضا', str_contains($page, '[v-cloak]::before') && !str_contains($page, '[v-cloak]{display:none!important}'));
    $cid = (int) db_insert('INSERT INTO campaigns (user_id, title, ideas_count, period_month) VALUES (?,?,?,?)', [$uid, "حملة P2 $tag", 3, date('Y-m')]);
    [, $cp] = http('GET', "$base/public/campaign.php?id=$cid", [], $j);
    t('زرار توليد الأفكار بيقول ناقصه إيه + مؤشر جنبه وهو شغال', str_contains($cp, 'اختار «الأساس اللي تحب نبني عليه الحملة» فوق الأول') && str_contains($cp, 'cf-gen-live'));
    t('من غير كلام طبي ثابت (🩺 / «صورة الدكتور»)', !str_contains($cp, '🩺') && !str_contains($cp, 'صورة الدكتور'));
    [, , $h] = http('GET', "$base/public/campaign.php?id=99999999", [], $j);
    [, $cl] = http('GET', "$base/public/campaigns.php", [], $j);
    t('حملة مش موجودة ← «حملاتي» برسالة', str_contains($h, 'campaigns.php') && str_contains($cl, 'الحملة دي مش موجودة'));

    echo "4) الأفكار\n";
    $brand = brand_for_user($uid);
    $rules = campaign_ideas_rules($brand);
    $months = ['', 'يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو', 'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر'];
    t('البرومبت فيه الشهر الحالي وممنوع المواسم اللي مش وقتها', str_contains($rules, 'شهر ' . $months[(int) date('n')]) && str_contains($rules, 'ماتقترحش موسم'));
    t('وممنوع وعود مش موجودة (خصم/تجربة مجانية/من غير تسجيل)', str_contains($rules, 'من غير تسجيل') && str_contains($rules, 'ماتوعدش'));
    t('وفيه أول جملة من آخر المنشورات علشان مايتكررش', str_contains($rules, "لبس الشتا وصل والأسعار حلوة جدًا في محلنا {$tag}"));
    $hooks = campaign_recent_hooks($uid);
    t('فكرة نفس منشور قديم (تقريبًا) ← بتتشال', campaign_idea_repeats(['hook' => "لبس الشتا وصل! والأسعار حلوه جدا في محلنا {$tag}", 'title' => 'x'], $hooks));
    t('فكرة جديدة ← بتفضل', !campaign_idea_repeats(['hook' => 'إزاي تختار الجاكيت المناسب لجسمك؟', 'title' => 'دليل اختيار الجاكيت'], $hooks));
    t('اسم «العيادة قفلت» مايتشالش علشان كلمة واحدة شبه', !campaign_idea_repeats(['hook' => 'محل', 'title' => 'لبس'], $hooks));

    echo "5) التنقّل بين المراحل\n";
    db_run('UPDATE campaigns SET basis = "services", stage = 2, max_stage = 2 WHERE id = ?', [$cid]);
    $c = db_one('SELECT * FROM campaigns WHERE id = ?', [$cid]);
    $single = campaign_write_mode() === 'single';
    t('من «المحتوى» ← التصميم مسموح (لما «التقييم» مش ضمن الخطوات)', $single ? campaign_stage_reachable($c, 4) && !campaign_stage_reachable($c, 3) : campaign_stage_reachable($c, 3));
    t('ومرحلتين لقدام ممنوع', !campaign_stage_reachable($c, 5));
    t('الرجوع لأي مرحلة اتعملت مسموح', campaign_stage_reachable($c, 1) && campaign_stage_reachable($c, 2));
    [, $d2] = http('GET', "$base/public/dashboard.php?onb=skip", [], $j);
    $cs = csrf_of($d2);
    [$code, $r] = http('POST', "$base/public/api/campaigns.php?action=stage", json_encode(['id' => $cid, 'stage' => $single ? 4 : 3]), $j, ['X-CSRF-Token: ' . $cs, 'Content-Type: application/json']);
    t('السيرفر: «التالي» من المحتوى بيتقبل (كان 422)', $code === 200, "$code " . substr($r, 0, 100));
    [$code] = http('POST', "$base/public/api/campaigns.php?action=stage", json_encode(['id' => $cid, 'stage' => 2]), $j, ['X-CSRF-Token: ' . $cs, 'Content-Type: application/json']);
    t('السيرفر: «السابق» للمحتوى', $code === 200, (string) $code);
    t('«تقييم الخطة كاملة» موجود في «المحتوى» لوضع الطلب الواحد', str_contains($cp, "c.stage === 2 && health.unlocked && writeMode === 'single'"));

    echo "6) الجرس\n";
    t('طلب واحد في نفس الوقت وبينهم 5 ثواني (ومرة واحدة في الصفحة)', str_contains($d2, '__spInboxOn') && str_contains($d2, 'Date.now() - last < 5000'));
    @unlink($j);
} finally {
    if ($uid) {
        foreach (['campaign_ideas', 'campaigns', 'content_designs', 'contents', 'social_connections', 'brand_kits', 'brand_profiles', 'credit_transactions', 'credit_wallets', 'user_sessions', 'remember_tokens'] as $tb) {
            try { db_run("DELETE FROM $tb WHERE user_id = ?", [$uid]); } catch (\Throwable $e) {}
        }
        db_run('DELETE FROM users WHERE id = ?', [$uid]);
    }
}
echo "\n" . ($fail ? "✗ $fail فشل · " : '✓ ') . "$pass نجح\n";
exit($fail ? 1 : 0);

<?php
/**
 * Spread AI — خطة الحملات · «المرحلة 6»: ترتيب البرومبت للكاش (§7)
 *   الجزء الثابت (ملف البراند الموحّد + الـ Skill كاملة) في أول كل خطوات الحملة وبنفس الحروف ← وبعده الخطوة
 *   ملف البراند مابيتكررش جوه الخطوة · قياس tokens_cached في سجل الطلبات وتقرير التكلفة
 * للتطوير المحلي بس — بيعمل Skill وعميل مؤقتين ويمسحهم.
 *
 *   php tests/cc6-cache.php http://localhost:8080
 */
if (PHP_SAPI !== 'cli') exit("CLI only\n");
$base = rtrim($argv[1] ?? 'http://localhost:8080', '/');
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require_once __DIR__ . '/../includes/config.php';
if (!APP_DEBUG) exit("APP_DEBUG مقفول — الاختبارات دي للتطوير المحلي بس\n");
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/credits.php';
require_once __DIR__ . '/../includes/plans.php';
require_once __DIR__ . '/../includes/campaign-write.php';
require_once __DIR__ . '/../includes/campaign-cost.php';
require_once __DIR__ . '/../includes/ce-skills.php';

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
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 120, CURLOPT_FOLLOWLOCATION => false, CURLOPT_HTTPHEADER => $headers]);
    if ($jar) curl_setopt_array($ch, [CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar]);
    if ($method === 'POST') curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => is_array($post) ? http_build_query($post) : $post]);
    $b = (string) curl_exec($ch);
    $c = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$c, $b];
}
$split = fn(string $p) => ($k = mb_strpos($p, CAMP_CACHE_SPLIT)) === false ? null : mb_substr($p, 0, $k);

db_run('DELETE FROM rate_limits');
$keys = ['campaign_prompt_cache', 'campaign_skills_enabled', 'brand_gate_pct', 'campaign_write_mode'];
$saved = [];
foreach ($keys as $k) $saved[$k] = get_setting($k, null);
db_run('DELETE FROM settings WHERE setting_key IN ("campaign_prompt_cache", "campaign_skills_enabled")');
set_setting('brand_gate_pct', '0');
set_setting('campaign_write_mode', 'single');
@unlink(STORAGE_PATH . '/logs/mock-ai-cache.json');

$tag = bin2hex(random_bytes(3));
$word = 'كاشتخصص' . $tag;
$r = ce_skill_save_md("---\nkey: cc6_$tag\nname: تخصص cc6\nmatch: $word\n---\n## المعرفة\n- معرفة-cc6-$tag\n## القواعد\n- قاعدة-cc6-$tag\n## الممنوعات\n- ممنوع-cc6-$tag\n## الناقد\n- ناقد-cc6-$tag\n## التصميم\n- تصميم-cc6-$tag\n", null, 'cc6', 'upload', null);
$sid = (int) $r['skill_id'];
db_run('UPDATE ce_skill_versions SET is_active = (id = ?) WHERE skill_id = ?', [(int) $r['id'], $sid]);
ce_skill_cache_bust();
$uid = (int) db_insert('INSERT INTO users (name, email, password, status, email_verified_at, approval_status, approved_at, created_at) VALUES (?,?,?, "active", NOW(), "approved", NOW(), NOW())',
    ['CC6', "cc6-$tag@test.local", password_hash('Test12345!', PASSWORD_DEFAULT)]);
db_insert('INSERT INTO brand_profiles (user_id, business_name, industry, description, audience, tone, dialect, created_at) VALUES (?,?,?,?,?,?,?, NOW())',
    [$uid, 'عيادة cc6', 'مركز ' . $word, 'وصف-فريد-cc6-' . $tag, 'عائلات', 'ودود', 'egyptian']);
$pkg = db_one('SELECT * FROM credit_packages WHERE is_active = 1 AND campaigns_month = 0 ORDER BY credits DESC LIMIT 1');
if ($pkg) plan_start($uid, (int) $pkg['id'], (int) $pkg['credits'], 30, 'admin', null, 'cc6');
credits_add($uid, 200, 'cc6', null, 'admin_add', null);
$brand = db_one('SELECT * FROM brand_profiles WHERE user_id = ?', [$uid]);
$cid = (int) db_insert('INSERT INTO campaigns (user_id, title, basis, topic, status, created_at) VALUES (?,?,?,?, "active", NOW())', [$uid, 'حملة cc6', 'awareness', 'كشف']);
$c = db_one('SELECT * FROM campaigns WHERE id = ?', [$cid]);
$idea = ['angle' => 'توعية', 'title' => 'فكرة cc6', 'description' => 'تفاصيل', 'audience' => 'الكل', 'hook' => 'هوك'];

/* ─── 1) الترتيب ─── */
echo "الجزء الثابت في الأول\n";
t('ترتيب الكاش شغال افتراضيًا', campaign_prompt_cache_on());
$pI = campaign_ideas_prompt($brand, $c, 3, []);
$pC = campaign_content_prompt($brand, $c, $idea, '', 'post');
$pE = campaign_eval_prompt($brand, $c, $idea, ['generated_text' => "h\n\nb", 'format' => 'post']);
$pM = campaign_content_prompt($brand, $c, $idea, 'تحسين: قوّي الـ Hook', 'post', 0, '30 ثانية', null, ['type' => 'campaign_improve', 'vars' => ['issues' => 'x', 'evaluation' => 'y', 'post_text' => 'z', 'post_cta' => '', 'post_hashtags' => '']]);
$fx = array_map($split, [$pI, $pC, $pE, $pM]);
t('كل الخطوات بتبدأ بنفس الجزء الثابت بالحرف (الأفكار · الكتابة · التقييم · التحسين)', $fx[0] !== null && count(array_unique($fx)) === 1, json_encode(array_map(fn($x) => $x === null ? null : md5($x), $fx)));
t('الجزء الثابت فيه ملف البراند والـ Skill كاملة (المعرفة + الممنوعات + الناقد)', str_contains((string) $fx[0], 'وصف-فريد-cc6-' . $tag) && str_contains((string) $fx[0], "معرفة-cc6-$tag")
    && str_contains((string) $fx[0], "ممنوع-cc6-$tag") && str_contains((string) $fx[0], "ناقد-cc6-$tag") && !str_contains((string) $fx[0], "تصميم-cc6-$tag"));
// ملف البراند الكامل مابيتكررش جوه الخطوة (بقى إشارة) — سطور الملخص اللي في قوالب الأدمن v1 نفسها ({brand_name} …) بتفضل زي ما هي
set_setting('campaign_prompt_cache', '0');
$classicBody = campaign_content_prompt_base($brand, $c, $idea, '', 'post');
db_run('DELETE FROM settings WHERE setting_key = "campaign_prompt_cache"');
$cacheBody = mb_substr($pC, mb_strpos($pC, CAMP_CACHE_SPLIT));
$full = trim(campaign_brand_block($brand, 'copy'));
t('ملف البراند الكامل مابيتكررش جوه الخطوة (بقى إشارة)', str_contains($cacheBody, 'مكتوب كامل في أول الرسالة') && !str_contains($cacheBody, $full)
    && mb_strlen($cacheBody) < mb_strlen($classicBody), mb_strlen($cacheBody) . ' vs ' . mb_strlen($classicBody));
t('الجزء الثابت بنفس الحروف لو اتبنى تاني', campaign_cache_prefix($brand, $c) === campaign_cache_prefix($brand, $c));
$tok = function_exists('ce_tokens_est') ? ce_tokens_est((string) $fx[0]) : (int) (mb_strlen((string) $fx[0]) / 3);
echo "    (حجم الجزء الثابت هنا ~$tok توكن — المزوّد بيكاش من 1024 توكن، وملف براند حقيقي + Skill بيعدّوه عادة)\n";
set_setting('campaign_prompt_cache', '0');
t('campaign_prompt_cache = 0 ← الترتيب القديم بالحرف', campaign_ideas_prompt($brand, $c, 3, []) === campaign_skill_prefix('campaign_ideas', $brand, $c) . campaign_ideas_prompt_base($brand, $c, 3, [])
    && $split(campaign_content_prompt($brand, $c, $idea, '', 'post')) === null);
db_run('DELETE FROM settings WHERE setting_key = "campaign_prompt_cache"');

/* ─── 2) لحد المزوّد والقياس ─── */
echo "القياس (tokens_cached)\n";
$jar = tempnam(sys_get_temp_dir(), 'cc6');
[, $html] = http('GET', "$base/public/login.php", [], $jar);
preg_match('/name="csrf" value="([^"]+)"/', $html, $m);
http('POST', "$base/public/login.php", ['csrf' => $m[1] ?? '', 'email' => "cc6-$tag@test.local", 'password' => 'Test12345!'], $jar);
[, $dash] = http('GET', "$base/public/dashboard.php", [], $jar);
preg_match('/<meta name="csrf-token" content="([^"]+)"/', $dash, $cm);
$api = fn(array $d) => http('POST', "$base/public/api/campaign-flow.php", json_encode($d, JSON_UNESCAPED_UNICODE), $jar, ['Content-Type: application/json', 'X-CSRF-Token: ' . ($cm[1] ?? '')]);
$ids = [];
foreach (['أ', 'ب'] as $k => $n) {
    $ids[] = (int) db_insert('INSERT INTO campaign_ideas (campaign_id, user_id, sort_order, angle, title, description, audience, hook, formats, selected, format) VALUES (?,?,?,?,?,?,?,?,?,1,"post")',
        [$cid, $uid, $k + 1, 'توعية', "فكرة $n", 'x', 'x', 'x', 'منشور']);
}
$t0 = date('Y-m-d H:i:s', time() - 2);
foreach ($ids as $i) $api(['action' => 'content_generate', 'id' => $cid, 'idea_id' => $i]);
$runs = db_all('SELECT tokens_in, tokens_cached FROM ai_runs WHERE user_id = ? AND created_at >= ? AND ref_type = "campaign_idea" ORDER BY id', [$uid, $t0]);
t('أول طلب من غير كاش وتاني طلب في نفس الحملة جزء منه من الكاش', count($runs) === 2 && (int) $runs[0]['tokens_cached'] === 0 && (int) $runs[1]['tokens_cached'] > 0, json_encode($runs));
$cs = cc_cache_stats(1);
t('تقرير التكلفة بيقيس نسبة الكاش', $cs['runs'] >= 2 && $cs['tokens_cached'] > 0 && $cs['pct'] > 0, json_encode($cs));
$ajar = tempnam(sys_get_temp_dir(), 'cc6a');
[, $ah] = http('GET', "$base/admin/login.php", [], $ajar);
preg_match('/name="csrf" value="([^"]+)"/', $ah, $am);
http('POST', "$base/admin/login.php", ['csrf' => $am[1] ?? '', 'email' => 'owner@app.test', 'password' => 'Test12345!'], $ajar);
[$c1, $b1] = http('GET', "$base/admin/campaign-cost.php", [], $ajar);
t('صفحة «تكلفة الحملة» فيها كارت الكاش', $c1 === 200 && str_contains($b1, 'الكاش (آخر 30 يوم)') && str_contains($b1, 'ترتيب الكاش شغال'), (string) $c1);

/* ─── تنضيف ─── */
$cids = array_column(db_all('SELECT id FROM contents WHERE user_id = ?', [$uid]), 'id');
if ($cids) { try { db_run('DELETE FROM content_versions WHERE content_id IN (' . implode(',', array_map('intval', $cids)) . ')'); } catch (\Throwable $e) {} }
foreach (['campaign_ideas', 'campaigns', 'contents', 'brand_profiles', 'credit_transactions', 'credit_lots', 'credit_wallets', 'ai_usage_logs', 'usage_events', 'user_plans', 'notifications', 'ai_runs'] as $tb) {
    try { db_run("DELETE FROM $tb WHERE user_id = ?", [$uid]); } catch (\Throwable $e) {}
}
db_run('DELETE FROM users WHERE id = ?', [$uid]);
db_run('DELETE FROM ce_skill_versions WHERE skill_id = ?', [$sid]);
db_run('DELETE FROM ce_skills WHERE id = ?', [$sid]);
ce_skill_cache_bust();
foreach ($keys as $k) {
    db_run('DELETE FROM settings WHERE setting_key = ?', [$k]);
    if ($saved[$k] !== null) set_setting($k, $saved[$k]);
}
db_run('DELETE FROM rate_limits');
@unlink($jar); @unlink($ajar);

echo "\n" . ($fail ? "✗ $fail فشل · " : '✓ ') . "$pass نجح\n";
exit($fail ? 1 : 0);

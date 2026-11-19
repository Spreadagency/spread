<?php
/**
 * Spread AI — MCP المرحلة 6: المسودات والموافقات
 * Claude (عن طريق الـ Internal API) بيكتب مسودة ← بيجرّبها sandbox ← الأدمن يوافق (تجربة بنسبة) ← A/B ← ترقية ← رجوع
 *
 *   php tests/mcp6-drafts.php http://localhost:8080
 */
if (PHP_SAPI !== 'cli') exit("CLI only\n");
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require_once __DIR__ . '/../includes/config.php';
if (!APP_DEBUG) exit("APP_DEBUG مقفول — الاختبارات دي للتطوير المحلي بس\n");
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/credits.php';
require_once __DIR__ . '/../includes/prompt-review.php';
require_once __DIR__ . '/../includes/prompt-library.php';
require_once __DIR__ . '/../includes/prompt-builder.php';
if (!pr_ready()) exit("جداول قاعدة الـ AI أو أعمدة prompts ناقصة\n");
$site = rtrim($argv[1] ?? 'http://localhost:8080', '/');
$api = $site . '/internal-api/v1/index.php';

$pass = 0; $fail = 0;
function t(string $name, bool $ok, string $why = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? '  ✓ ' : '  ✗ ') . $name . ($ok || $why === '' ? '' : " — $why") . "\n";
}
function call(string $route, string $scope, ?array $body = null): array
{
    global $api;
    $ch = curl_init($api . '?r=' . $route);
    $h = ['Authorization: Bearer ' . INTERNAL_API_TOKEN, 'X-Scope: ' . $scope, 'X-Client: mcp6-test'];
    if ($body !== null) { $h[] = 'Content-Type: application/json'; curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE)); }
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $h, CURLOPT_TIMEOUT => 90]);
    $raw = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, json_decode($raw, true) ?: []];
}
function http(string $method, string $url, array $post = [], ?string $jar = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 90]);
    if ($jar) { curl_setopt($ch, CURLOPT_COOKIEJAR, $jar); curl_setopt($ch, CURLOPT_COOKIEFILE, $jar); }
    if ($method === 'POST') { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
    $body = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $body];
}
function csrf(string $html): string { preg_match('/name="csrf" value="([^"]+)"/', $html, $m); return $m[1] ?? ''; }

$type = 'content';
$bak = db_all('SELECT id, is_active, status, traffic_percent FROM prompts WHERE prompt_type = ?', [$type]);
$maxId = (int) (db_one('SELECT MAX(id) m FROM prompts')['m'] ?? 0);
$saved = ['sandbox_daily_cap' => get_setting('sandbox_daily_cap', null)];
set_setting('sandbox_daily_cap', '20');
$drafts = []; $crs = []; $styleDrafts = []; $presets = [];
// منشور للتجربة
$u = (int) (db_one('SELECT id FROM users ORDER BY id LIMIT 1')['id'] ?? 1);
$bp = (int) (db_one('SELECT id FROM brand_profiles WHERE user_id = ? LIMIT 1', [$u])['id'] ?? 0);
$post = (int) db_insert('INSERT INTO contents (user_id, brand_profile_id, content_type, platform, length, tone, generated_text, hashtags, cta, status) VALUES (?,?,"marketing","facebook","medium","friendly","نص المنشور الأصلي MCP6","#قديم","كلمنا","generated")', [$u, $bp]);
$creditsBefore = credits_balance($u);
$liveText = pr_live_text($type);

try {
echo "المسودة والتجربة (sandbox)\n";
[$c, $r] = call('draft_prompt', 'draft', ['template_key' => $type, 'body' => $liveText . "\n\nMCP6: خلي أول سطر سؤال للعميل.", 'reason' => 'منشورات كتير الـ hook بتاعها ضعيف — تقييم الخطّاف 5.1 من 10']);
$did = (int) ($r['id'] ?? 0);
$drafts[] = $did;
t('Claude كتب مسودة برومبت', $c === 200 && $did > 0 && ($r['status'] ?? '') === 'draft', json_encode([$c, $r], JSON_UNESCAPED_UNICODE));
[$c, $r] = call('test_regenerate', 'read', ['prompt_draft_id' => $did, 'post_id' => $post]);
t('التوليد التجريبي محتاج صلاحية المسودات (read مرفوض)', $c === 403, (string) $c);
[$c, $r] = call('test_regenerate', 'draft', ['post_id' => $post]);
t('من غير مسودة أو إصدار ← مرفوض', $c === 422);
[$c, $r] = call('test_regenerate', 'draft', ['prompt_draft_id' => $did, 'post_id' => $post]);
$last = json_decode((string) @file_get_contents(STORAGE_PATH . '/logs/mock-ai-last.json'), true);
t('قبل/بعد على منشور حقيقي', $c === 200 && str_contains((string) ($r['before'] ?? ''), 'نص المنشور الأصلي MCP6') && ($r['after'] ?? '') !== '' && (int) ($r['sandbox_run_id'] ?? 0) > 0, json_encode([$c, $r], JSON_UNESCAPED_UNICODE));
t('الموديل اتبعتله المسودة نفسها (مش المفعّل)', str_contains(json_encode($last, JSON_UNESCAPED_UNICODE), 'MCP6: خلي أول سطر سؤال للعميل'));
$pc = db_one('SELECT generated_text FROM contents WHERE id = ?', [$post]);
$run = db_one('SELECT sandbox, credits_charged FROM ai_runs ORDER BY id DESC LIMIT 1');
t('المنشور ماتغيّرش · كريدت العميل زي ما هو · العملية sandbox', $pc['generated_text'] === 'نص المنشور الأصلي MCP6' && credits_balance($u) === $creditsBefore && (int) ($run['sandbox'] ?? 0) === 1, json_encode($run));
set_setting('sandbox_daily_cap', (string) ((int) db_one('SELECT COUNT(*) n FROM ' . mcp_t('sandbox_runs') . ' WHERE created_at >= CURDATE()')['n']));
[$c, $r] = call('test_regenerate', 'draft', ['prompt_draft_id' => $did, 'post_id' => $post]);
t('الحد اليومي ← 429', $c === 429 && ($r['code'] ?? '') === 'rate_limit', json_encode([$c, $r['code'] ?? null]));
set_setting('sandbox_daily_cap', '20');

echo "الأدمن: طلبات التعديل\n";
$jar = tempnam(sys_get_temp_dir(), 'mcp6');
[, $lp] = http('GET', "$site/admin/login.php", [], $jar);
http('POST', "$site/admin/login.php", ['csrf' => csrf($lp), 'email' => 'owner@app.test', 'password' => 'Test12345!'], $jar);
[$c, $h] = http('GET', "$site/admin/ai-changes.php?tab=prompts", [], $jar);
t('صفحة «طلبات التعديل»: المسودة + الفرق + نتيجة التجربة', $c === 200 && str_contains($h, '#' . $did) && str_contains($h, 'pr-add') && str_contains($h, 'MCP6: خلي أول سطر') && str_contains($h, 'تجربة على منشور #' . $post) && !str_contains($h, 'Warning:'), (string) $c);
[, $h] = http('POST', "$site/admin/ai-changes.php", ['csrf' => csrf($h), 'action' => 'sandbox', 'id' => $did, 'post_id' => $post], $jar);
t('«🧪 جرّب على منشور» من الأدمن', (int) db_one('SELECT COUNT(*) n FROM ' . mcp_t('sandbox_runs') . ' WHERE prompt_draft_id = ?', [$did])['n'] >= 2);
[, $h] = http('GET', "$site/admin/ai-changes.php?tab=prompts", [], $jar);
http('POST', "$site/admin/ai-changes.php", ['csrf' => csrf($h), 'action' => 'prompt_approve', 'id' => $did, 'mode' => 'testing', 'pct' => 30, 'note' => 'نجرّب'], $jar);
$test = pr_testing($type);
$d = pr_prompt_draft($did);
t('موافقة كتجربة 30% ← إصدار «testing» من Claude والمفعّل زي ما هو', $test && (int) $test['traffic_percent'] === 30 && $test['created_by_kind'] === 'ai' && $d['status'] === 'approved' && (int) $d['applied_prompt_id'] === (int) $test['id']
    && pr_live_text($type) === $liveText, json_encode([$test['id'] ?? null, $test['traffic_percent'] ?? null, $d['status']]));
// توزيع العملاء: نفس العميل بيفضل في نفس المجموعة
$_SESSION['user_id'] = 999001;
$GLOBALS['__prompt_used'] = [];
$a1 = prompt_pick($type)['id'] ?? null;
t('التجربة شغالة في الاختيار (العميل بيتحدد بالـ hash)', in_array((int) $a1, [(int) $test['id'], (int) (pr_live($type)['id'] ?? 0)], true));
unset($_SESSION['user_id']);

[$c, $r] = call('draft_prompt', 'draft', ['template_key' => $type, 'body' => $liveText . "\n\nMCP6-2: نسخة تانية مرفوضة", 'reason' => 'تجربة رفض المسودة في الاختبار']);
$d2 = (int) ($r['id'] ?? 0);
$drafts[] = $d2;
[, $h] = http('GET', "$site/admin/ai-changes.php?tab=prompts", [], $jar);
http('POST', "$site/admin/ai-changes.php", ['csrf' => csrf($h), 'action' => 'prompt_reject', 'id' => $d2, 'note' => ''], $jar);
t('الرفض من غير سبب ← مرفوض', pr_prompt_draft($d2)['status'] === 'draft');
http('POST', "$site/admin/ai-changes.php", ['csrf' => csrf($h), 'action' => 'prompt_reject', 'id' => $d2, 'note' => 'طويلة زيادة'], $jar);
t('الرفض بسبب', pr_prompt_draft($d2)['status'] === 'rejected' && pr_prompt_draft($d2)['review_note'] === 'طويلة زيادة');

echo "الاقتراحات\n";
[$c, $r] = call('propose_change', 'draft', ['type' => 'content_rule', 'target' => 'MCP6 قاعدة الإيموجي', 'diff' => "- 5 إيموجي\n+ 2 إيموجي بالكتير", 'reason' => 'العملاء بيعدّلوا الإيموجي كتير (37 تعديل يدوي)', 'evidence' => ['edits' => 37]]);
$cr = (int) ($r['id'] ?? 0);
$crs[] = $cr;
[, $h] = http('GET', "$site/admin/ai-changes.php?tab=changes", [], $jar);
t('الاقتراح ظاهر بالفرق والأدلة', str_contains($h, 'MCP6 قاعدة الإيموجي') && str_contains($h, '+ 2 إيموجي بالكتير'));
http('POST', "$site/admin/ai-changes.php", ['csrf' => csrf($h), 'action' => 'cr_review', 'id' => $cr, 'to' => 'rejected', 'note' => 'الإيموجي جزء من هوية العملاء'], $jar);
[$c, $r] = call('propose_change', 'draft', ['type' => 'content_rule', 'target' => 'MCP6 قاعدة الإيموجي', 'diff' => "- 5 إيموجي\n+ 2 إيموجي بالكتير", 'reason' => 'العملاء بيعدّلوا الإيموجي كتير (37 تعديل يدوي)', 'evidence' => ['edits' => 37]]);
t('اترفض بملاحظة ← Claude مايقدرش يعيده بنفس الدليل', $c === 409 && ($r['code'] ?? '') === 'already_rejected' && str_contains((string) ($r['error'] ?? ''), 'الإيموجي جزء من هوية العملاء'), json_encode([$c, $r], JSON_UNESCAPED_UNICODE));
[$c, $r] = call('change_requests', 'read', ['status' => 'rejected']);
t('Claude بيشوف ملاحظة الرفض', str_contains(json_encode($r, JSON_UNESCAPED_UNICODE), 'الإيموجي جزء من هوية العملاء'));
[$c, $r] = call('propose_change', 'draft', ['type' => 'ux', 'target' => 'MCP6 زرار النشر', 'reason' => 'العملاء بيدوروا على زرار النشر كتير', 'evidence' => ['clicks' => 12]]);
$cr2 = (int) ($r['id'] ?? 0); $crs[] = $cr2;
[, $h] = http('GET', "$site/admin/ai-changes.php?tab=changes", [], $jar);
http('POST', "$site/admin/ai-changes.php", ['csrf' => csrf($h), 'action' => 'cr_review', 'id' => $cr2, 'to' => 'approved', 'note' => ''], $jar);
http('POST', "$site/admin/ai-changes.php", ['csrf' => csrf($h), 'action' => 'cr_review', 'id' => $cr2, 'to' => 'applied', 'note' => 'اتعمل في الإصدار الجاي'], $jar);
t('موافقة ← اتنفّذ', db_one('SELECT status FROM ' . mcp_t('change_requests') . ' WHERE id = ?', [$cr2])['status'] === 'applied');

echo "مسودات الاستايل\n";
$pid = (int) db_insert('INSERT INTO style_presets (kind, name, data, is_active, sort_order) VALUES ("design_style", "MCP6 استايل", ?, 1, 0)', [json_encode(['prompt' => 'قديم', 'mood' => 'هادي'], JSON_UNESCAPED_UNICODE)]);
$presets[] = $pid;
[$c, $r] = call('draft_style', 'draft', ['preset_id' => $pid, 'payload' => ['prompt' => 'جديد أوضح'], 'reason' => 'الصور طالعة باهتة مع الاستايل ده']);
$sd = (int) ($r['id'] ?? 0); $styleDrafts[] = $sd;
[, $h] = http('GET', "$site/admin/ai-changes.php?tab=styles", [], $jar);
http('POST', "$site/admin/ai-changes.php", ['csrf' => csrf($h), 'action' => 'style_approve', 'id' => $sd], $jar);
$np = json_decode((string) db_one('SELECT data FROM style_presets WHERE id = ?', [$pid])['data'], true);
$backup = db_one('SELECT id, data, is_active FROM style_presets WHERE name LIKE ?', ['MCP6 استايل — نسخة قبل اقتراح #' . $sd]);
if ($backup) $presets[] = (int) $backup['id'];
t('اتطبّق (دمج) والنسخة القديمة اتحفظت مقفولة', ($np['prompt'] ?? '') === 'جديد أوضح' && ($np['mood'] ?? '') === 'هادي' && $backup && (int) $backup['is_active'] === 0 && str_contains((string) $backup['data'], 'قديم'));

echo "تجارب A/B\n";
[$c, $h] = http('GET', "$site/admin/ai-ab.php", [], $jar);
t('صفحة A/B: المفعّل قدام التجربة + النسبة', $c === 200 && str_contains($h, '30%') && str_contains($h, 'رقّي التجربة') && !str_contains($h, 'Warning:'), (string) $c);
http('POST', "$site/admin/ai-ab.php", ['csrf' => csrf($h), 'action' => 'traffic', 'id' => $test['id'], 'pct' => 50], $jar);
t('تغيير النسبة', (int) db_one('SELECT traffic_percent FROM prompts WHERE id = ?', [$test['id']])['traffic_percent'] === 50);
$oldLive = pr_live($type);
[, $h] = http('GET', "$site/admin/ai-ab.php", [], $jar);
http('POST', "$site/admin/ai-ab.php", ['csrf' => csrf($h), 'action' => 'promote', 'id' => $test['id']], $jar);
$nl = pr_live($type);
t('ترقية ← التجربة بقت المفعّل لكل العملاء', $nl && (int) $nl['id'] === (int) $test['id'] && $nl['status'] === 'live' && !pr_testing($type));
$_SESSION['user_id'] = 999002; $GLOBALS['__prompt_used'] = [];
t('المفعّل دلوقتي = الجديد (اللي هيتستخدم في الطلبات الجاية)', str_contains(pr_live_text($type), 'MCP6: خلي أول سطر'));
unset($_SESSION['user_id']);
if ($oldLive) {
    [, $h] = http('GET', "$site/admin/ai-ab.php", [], $jar);
    t('زرار الرجوع ظاهر', str_contains($h, 'رجوع لـ v' . $oldLive['version']));
    http('POST', "$site/admin/ai-ab.php", ['csrf' => csrf($h), 'action' => 'rollback', 'type' => $type], $jar);
    $rb = pr_live($type);
    t('رجوع بضغطة ← المفعّل القديم رجع', $rb && (int) $rb['id'] === (int) $oldLive['id'] && db_one('SELECT status FROM prompts WHERE id = ?', [$test['id']])['status'] === 'archived');
} else {
    t('زرار الرجوع (مفيش مفعّل قديم — اتخطى)', true);
    t('رجوع بضغطة (اتخطى)', true);
}
[$c, $h] = http('GET', "$site/admin/ai-report.php", [], $jar);
t('تبويب «طلبات التعديل» بعداده في مركز الجودة', str_contains($h, 'ai-changes.php') && str_contains($h, 'ai-ab.php'));

} finally {
db_run('DELETE FROM prompts WHERE id > ? AND prompt_type = ?', [$maxId, $type]);
foreach ($bak as $b) db_run('UPDATE prompts SET is_active = ?, status = ?, traffic_percent = ? WHERE id = ?', [$b['is_active'], $b['status'], $b['traffic_percent'], $b['id']]);
foreach ($drafts as $d) { db_run('DELETE FROM ' . mcp_t('sandbox_runs') . ' WHERE prompt_draft_id = ?', [$d]); db_run('DELETE FROM ' . mcp_t('prompt_drafts') . ' WHERE id = ?', [$d]); }
db_run('DELETE FROM ' . mcp_t('sandbox_runs') . ' WHERE content_id = ?', [$post]);
foreach ($crs as $x) db_run('DELETE FROM ' . mcp_t('change_requests') . ' WHERE id = ?', [$x]);
foreach ($styleDrafts as $x) db_run('DELETE FROM ' . mcp_t('style_preset_drafts') . ' WHERE id = ?', [$x]);
foreach ($presets as $x) db_run('DELETE FROM style_presets WHERE id = ?', [$x]);
db_run('DELETE FROM contents WHERE id = ?', [$post]);
foreach ($saved as $k => $v) { if ($v === null) db_run('DELETE FROM settings WHERE setting_key = ?', [$k]); else set_setting($k, (string) $v); }
}
t('رجّعنا البرومبتات والبيانات زي ما كانت', (int) (db_one('SELECT MAX(id) m FROM prompts')['m'] ?? 0) === $maxId && pr_live_text($type) === $liveText);
echo "\n$pass ✓ · $fail ✗\n";
exit($fail ? 1 : 0);

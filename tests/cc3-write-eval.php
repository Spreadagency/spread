<?php
/**
 * Spread AI — خطة الحملات · المرحلة 3: الكتابة والتقييم في طلب واحد + فحص بالكود + تحسين تلقائي مرة
 * للتطوير المحلي بس — بيعمل عميل مؤقت وحملات تجريبية ويمسحهم، وبيرجّع الإعدادات زي ما كانت.
 *
 *   php tests/cc3-write-eval.php http://localhost:8080
 *
 * محتاج: tests/dev-setup.php اتشغّل · الموقع شغال · الـ AI الوهمي شغال (storage/logs/mock-ai.jsonl).
 */
if (PHP_SAPI !== 'cli') exit("CLI only\n");
$base = rtrim($argv[1] ?? 'http://localhost:8080', '/');
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require_once __DIR__ . '/../includes/config.php';
if (!APP_DEBUG) exit("APP_DEBUG مقفول — الاختبارات دي للتطوير المحلي بس\n");
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/credits.php';
require_once __DIR__ . '/../includes/plans.php';
require_once __DIR__ . '/../includes/campaign-flow.php';
require_once __DIR__ . '/../includes/campaign-quality.php';
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
/** نصوص طلبات الكتابة اللي وصلت للـ AI الوهمي من لحظة معيّنة (للحملة دي بس) */
function mock_texts(int $offset, string $mark): array
{
    $f = STORAGE_PATH . '/logs/mock-ai.jsonl';
    clearstatcache();
    if (!is_file($f)) return [];
    $h = fopen($f, 'r');
    fseek($h, $offset);
    $out = [];
    while (($l = fgets($h)) !== false) {
        $x = json_decode($l, true);
        $b = json_decode((string) ($x['body'] ?? ''), true);
        $txt = '';
        foreach ((array) ($b['messages'] ?? []) as $m) $txt .= is_string($m['content'] ?? null) ? $m['content'] . "\n" : json_encode($m['content'] ?? '', JSON_UNESCAPED_UNICODE);
        if ($txt === '') $txt = (string) ($x['body'] ?? '');
        if (str_contains($txt, $mark)) $out[] = $txt;
    }
    fclose($h);
    return $out;
}
$logSize = function (): int { clearstatcache(); $f = STORAGE_PATH . '/logs/mock-ai.jsonl'; return is_file($f) ? filesize($f) : 0; };

db_run('DELETE FROM rate_limits');
$keys = ['campaign_write_mode', 'campaign_auto_improve_rounds', 'ce_qa_pass_total', 'campaign_skills_enabled', 'brand_gate_pct'];
$saved = [];
foreach ($keys as $k) { $saved[$k] = get_setting($k, null); db_run('DELETE FROM settings WHERE setting_key = ?', [$k]); }
set_setting('campaign_skills_enabled', '0');   // الـ Skills ليها اختبار لوحدها (cc2)
set_setting('brand_gate_pct', '0');            // العميل المؤقت هويته ناقصة — بوابة الهوية مش موضوع الاختبار ده

/* ─── 1) الدوال (من غير AI) ─── */
echo "الدوال\n";
t('الطريقة الافتراضية: طلب واحد (single)', campaign_write_mode() === 'single');
t('الأوزان الخمسة مجموعها 100 وبنفس أسماء مقياس المحرك',
    array_sum(CAMP_SELF_WEIGHTS) === 100 && CAMP_SELF_WEIGHTS === ['hook' => 30, 'audience_fit' => 20, 'value' => 20, 'trust' => 15, 'cta_business' => 15]
    && (!defined('CE_QA_WEIGHTS') || !array_diff_key(CAMP_SELF_WEIGHTS, CE_QA_WEIGHTS)));
$okResp = "[HOOK]h[/HOOK]\n[SELF_EVAL]{\"criteria\":{\"hook\":90,\"audience_fit\":80,\"value\":70,\"trust\":60,\"cta_business\":50},\"issues\":[\"x\"],\"fix\":\"y\"}[/SELF_EVAL]";
$se = campaign_parse_self_eval($okResp);
t('قراءة التقييم الذاتي', $se && $se['criteria']['hook'] === 90 && $se['issues'] === ['x'] && $se['fix'] === 'y');
t('تقييم ناقص معيار أو JSON مكسور ← null', campaign_parse_self_eval('[SELF_EVAL]{"criteria":{"hook":90}}[/SELF_EVAL]') === null
    && campaign_parse_self_eval('[SELF_EVAL]{broken[/SELF_EVAL]') === null && campaign_parse_self_eval('مفيش') === null);
t('بلوك التقييم بيتشال من النص', !str_contains(campaign_strip_self_eval($okResp), 'SELF_EVAL') && str_contains(campaign_strip_self_eval($okResp), '[HOOK]h'));
$sc = campaign_self_score($se, ['flags' => [], 'notes' => []], 0);
t('الدرجة بيحسبها الكود بالأوزان (90·30+80·20+70·20+60·15+50·15)/100 = 74', $sc['score'] === 74 && $sc['pass'] === false);
$hi = campaign_self_score(['criteria' => array_fill_keys(array_keys(CAMP_SELF_WEIGHTS), 95), 'issues' => [], 'fix' => ''], ['flags' => [], 'notes' => []], 0);
$hiF = campaign_self_score(['criteria' => array_fill_keys(array_keys(CAMP_SELF_WEIGHTS), 95), 'issues' => [], 'fix' => ''], ['flags' => ['مفيش CTA'], 'notes' => []], 0);
t('95 من غير مخالفات = يعدّي · نفس الدرجة + مخالفة = مايعدّيش', $hi['pass'] && !$hiF['pass'] && $hiF['score'] === 95);
t('حد النجاح الافتراضي 85 (أو مستوى الباقة)', abs(campaign_pass_total(0) - (qt_engine(0)['qa_pass'] ?? 85)) < 0.01);
set_setting('ce_qa_pass_total', '96');
t('حد النجاح من إعداد المحرك لو المستوى مالوش', qt_engine(0)['qa_pass'] !== null || !campaign_self_score(['criteria' => array_fill_keys(array_keys(CAMP_SELF_WEIGHTS), 95), 'issues' => [], 'fix' => ''], ['flags' => [], 'notes' => []], 0)['pass']);
db_run('DELETE FROM settings WHERE setting_key = "ce_qa_pass_total"');

$p = ['hook' => 'سؤال مهم', 'body' => str_repeat('كلام مفيد عن الخدمة ', 10) . 'نتيجة مضمونة', 'cta' => 'احجز', 'script' => '', 'slides_raw' => ''];
$sk = ['sections' => ['forbidden' => ['items' => ['كلمة سكيل ممنوعة']]]];
$ck = campaign_code_check(array_merge($p, ['body' => $p['body'] . ' كلمة سكيل ممنوعة']), ['fmt' => 'post', 'brand' => ['keywords_avoid' => 'رخيص، تخفيضات'], 'skill' => $sk]);
t('الفحص: وعد ممنوع + ممنوعات الـ Skill', count(array_filter($ck['flags'], fn($f) => str_contains($f, 'نتيجة مضمونة') || str_contains($f, 'كلمة سكيل ممنوعة'))) === 2, json_encode($ck, JSON_UNESCAPED_UNICODE));
$ck2 = campaign_code_check(['hook' => 'x', 'body' => 'العرض ده رخيص جدًا ' . str_repeat('كلام ', 30), 'cta' => ''], ['fmt' => 'post', 'brand' => ['keywords_avoid' => 'رخيص، تخفيضات']]);
t('الفحص: «كلمات ممنوعة» في الهوية + مفيش CTA', in_array('مفيش CTA', $ck2['flags'], true) && (bool) array_filter($ck2['flags'], fn($f) => str_contains($f, 'رخيص')));
$ck3 = campaign_code_check(['hook' => 'h', 'body' => str_repeat('كلام ', 30), 'cta' => 'c', 'slides_raw' => "شريحة 1 | a\nشريحة 2 | b"], ['fmt' => 'carousel', 'slides' => 4]);
t('الفحص: عدد الشرايح غير المطلوب = ملاحظة (مش مخالفة)', !$ck3['flags'] && (bool) array_filter($ck3['notes'], fn($n) => str_contains($n, 'الشرايح')), json_encode($ck3, JSON_UNESCAPED_UNICODE));
t('الفحص: فيديو من غير سكريبت = مخالفة', in_array('مفيش سكريبت', campaign_code_check(['hook' => 'h', 'body' => str_repeat('كلام ', 20), 'cta' => 'c', 'script' => ''], ['fmt' => 'video'])['flags'], true));

/* ─── عميل مؤقت + حملة ─── */
$tag = bin2hex(random_bytes(3));
$email = "cc3-$tag@test.local";
$uid = (int) db_insert('INSERT INTO users (name, email, password, status, email_verified_at, approval_status, approved_at, created_at) VALUES (?,?,?, "active", NOW(), "approved", NOW(), NOW())',
    ['CC3 test', $email, password_hash('Test12345!', PASSWORD_DEFAULT)]);
db_insert('INSERT INTO brand_profiles (user_id, business_name, industry, description, audience, tone, dialect, created_at) VALUES (?,?,?,?,?,?,?, NOW())',
    [$uid, 'عيادة cc3', 'عيادة أسنان', 'عيادة أسنان تجريبية', 'عائلات', 'ودود', 'egyptian']);
$pkg = db_one('SELECT * FROM credit_packages WHERE is_active = 1 ORDER BY credits DESC LIMIT 1');
if ($pkg) plan_start($uid, (int) $pkg['id'], (int) $pkg['credits'], 30, 'admin', null, 'cc3 test');
credits_add($uid, 500, 'cc3 test', null, 'admin_add', null);
// الكود القديم: ق ← ض (من الدوال اللي فوق) — مالوش دعوة بالعميل
$code = 'C3' . strtoupper($tag);
$camp = function (string $marker) use ($uid, $code): array {
    $cid = (int) db_insert('INSERT INTO campaigns (user_id, title, basis, topic, status, created_at) VALUES (?,?,?,?, "active", NOW())', [$uid, "حملة $code $marker", 'awareness', 'كشف الأسنان']);
    $iid = (int) db_insert('INSERT INTO campaign_ideas (campaign_id, user_id, angle, title, description, audience, hook, formats, selected) VALUES (?,?,?,?,?,?,?,?,1)',
        [$cid, $uid, 'توعية', "فكرة $code $marker", 'تفاصيل الفكرة', 'عائلات', 'بتأجل الكشف؟', 'منشور']);
    return [$cid, $iid];
};

// تسجيل الدخول
$jar = tempnam(sys_get_temp_dir(), 'cc3');
[, $html] = http('GET', "$base/public/login.php", [], $jar);
preg_match('/name="csrf" value="([^"]+)"/', $html, $m);
http('POST', "$base/public/login.php", ['csrf' => $m[1] ?? '', 'email' => $email, 'password' => 'Test12345!'], $jar);
[, $dash] = http('GET', "$base/public/dashboard.php", [], $jar);
preg_match('/<meta name="csrf-token" content="([^"]+)"/', $dash, $cm);
$csrf = $cm[1] ?? '';
t('دخول العميل المؤقت', $csrf !== '', 'مفيش csrf');
$api = function (array $d) use ($base, $jar, $csrf): array {
    [$c, $b] = http('POST', "$base/public/api/campaign-flow.php", json_encode($d, JSON_UNESCAPED_UNICODE), $jar, ['Content-Type: application/json', 'X-CSRF-Token: ' . $csrf]);
    return [$c, json_decode($b, true) ?: ['raw' => mb_substr($b, 0, 300)]];
};
$idea = fn(int $iid) => db_one('SELECT * FROM campaign_ideas WHERE id = ?', [$iid]);
$evj = fn(array $row) => json_decode((string) ($row['eval_json'] ?? ''), true) ?: [];
$gen = function (string $marker, array $extra = []) use ($camp, $api, $logSize, $code): array {
    [$cid, $iid] = $camp($marker);
    $off = $logSize();
    $bal = credits_balance((int) db_one('SELECT user_id FROM campaigns WHERE id = ?', [$cid])['user_id']);
    [$c, $j] = $api(['action' => 'content_generate', 'id' => $cid, 'idea_id' => $iid] + $extra);
    return [$cid, $iid, $c, $j, mock_texts($off, "فكرة $code $marker"), $bal];
};

/* ─── 2) المنشور العادي: طلب واحد ─── */
echo "طلب واحد\n";
[$cid, $iid, $c, $j, $reqs, $bal0] = $gen('NORMAL');
$row = $idea($iid);
$e = $evj($row);
t('المنشور اتكتب (200)', $c === 200 && !empty($j['ok']), json_encode($j, JSON_UNESCAPED_UNICODE));
t('طلب AI واحد بس للكتابة + التقييم', count($reqs) === 1, (string) count($reqs));
t('الطلب فيه تعليمات [SELF_EVAL] بالمعايير الخمسة', isset($reqs[0]) && str_contains($reqs[0], '[SELF_EVAL]{') && str_contains($reqs[0], 'cta_business'));
t('التقييم اتحفظ مع الكتابة (mode=self · 92 · يعدّي · approved)', ($e['mode'] ?? '') === 'self' && (int) $row['eval_score'] === 92 && !empty($e['pass']) && (int) $row['approved'] === 1, json_encode($e, JSON_UNESCAPED_UNICODE));
$cont = db_one('SELECT * FROM contents WHERE id = ?', [(int) $row['content_id']]);
t('بلوك التقييم مادخلش في نص المنشور', $cont && !str_contains((string) $cont['generated_text'], 'SELF_EVAL') && !str_contains((string) $cont['generated_text'], 'criteria'));
t('المنشور جاهز للتصميم (workflow_flag)', ($cont['workflow_flag'] ?? '') === 'needs_design');
t('الكريدت اتخصم مرة واحدة بس (سعر المنشور)', $bal0 - credits_balance($uid) === cost_for_format('post'), ($bal0 - credits_balance($uid)) . ' ≠ ' . cost_for_format('post'));
t('اللوحة بتقول «جاهز» من حكم الكود', (bool) ($j['idea']['eval']['ready'] ?? false));

/* ─── 3) تحت الحد ← تحسين تلقائي مرة ─── */
echo "التحسين التلقائي\n";
[$cid, $iid, $c, $j, $reqs, $bal0] = $gen('SE_LOW');
$row = $idea($iid);
$e = $evj($row);
t('تحت الحد ← طلبين (كتابة + تحسين تلقائي)', $c === 200 && count($reqs) === 2, (string) count($reqs));
t('طلب التحسين فيه الدرجة والملاحظات نفسها', isset($reqs[1]) && str_contains($reqs[1], CAMP_IMPROVE_MARK) && str_contains($reqs[1], 'الـ Hook ضعيف') && str_contains($reqs[1], '55/100'));
t('النسخة المحسّنة هي اللي اتحفظت (92 · يعدّي)', (int) $row['eval_score'] === 92 && !empty($e['pass']) && (int) ($e['auto_improved'] ?? 0) === 1
    && str_contains((string) db_one('SELECT generated_text FROM contents WHERE id = ?', [(int) $row['content_id']])['generated_text'], 'نسخة محسّنة'));
t('التحسين التلقائي مش بيتخصم من العميل', $bal0 - credits_balance($uid) === cost_for_format('post'));

[$cid, $iid, $c, $j, $reqs] = $gen('SE_ALWAYS_LOW');
$row = $idea($iid);
$e = $evj($row);
t('دايمًا ضعيف ← تحسين مرة واحدة بس (طلبين) والمنشور «محتاج مراجعة»', count($reqs) === 2 && empty($e['pass']) && (int) $row['approved'] === 0
    && (db_one('SELECT workflow_flag FROM contents WHERE id = ?', [(int) $row['content_id']])['workflow_flag'] ?? '') === 'needs_review', json_encode($e, JSON_UNESCAPED_UNICODE));
t('الملاحظة بتظهر للعميل', str_contains((string) ($j['idea']['eval']['issues'] ?? ''), 'الـ Hook ضعيف'));

set_setting('campaign_auto_improve_rounds', '0');
[, $iid, , , $reqs] = $gen('SE_LOW');
t('campaign_auto_improve_rounds = 0 ← من غير تحسين تلقائي', count($reqs) === 1 && (int) $idea($iid)['approved'] === 0);
db_run('DELETE FROM settings WHERE setting_key = "campaign_auto_improve_rounds"');

/* ─── 4) المخالفة بتوقف مهما كانت الدرجة ─── */
echo "المخالفات\n";
[, $iid, $c, $j, $reqs] = $gen('SE_NOCTA');
$row = $idea($iid);
$e = $evj($row);
t('درجة 92 بس مفيش CTA ← تحسين تلقائي', count($reqs) === 2 && isset($reqs[1]) && str_contains($reqs[1], 'مفيش CTA'));
t('بعد التحسين الـ CTA رجع والمنشور عدّى', !empty($e['pass']) && !($e['flags'] ?? []) && (string) db_one('SELECT cta FROM contents WHERE id = ?', [(int) $row['content_id']])['cta'] !== '');

/* ─── 5) التقييم مارجعش ─── */
echo "الرد المكسور\n";
[, $iid, $c, , $reqs] = $gen('SE_NOEVAL_ONCE');
$row = $idea($iid);
t('التقييم مارجعش ← إعادة مرة والخطأ في البرومبت ← تمام', $c === 200 && count($reqs) === 2 && str_contains($reqs[1] ?? '', 'كان ناقص [SELF_EVAL]') && (int) $row['eval_score'] === 92,
    $c . ' · ' . count($reqs) . ' · ' . json_encode($row['eval_score']) . ' · ' . (int) str_contains($reqs[1] ?? '', 'كان ناقص'));
[$cid, $iid, $c, , $reqs] = $gen('SE_MISSING');
$row = $idea($iid);
$e = $evj($row);
t('مارجعش تاني ← المنشور اتحفظ و«محتاج مراجعة» من غير درجة', $c === 200 && count($reqs) === 2 && $row['content_id'] && $row['eval_score'] === null && !empty($e['missing']) && (int) $row['approved'] === 0);

/* ─── 6) زرار «حسّن» من العميل ─── */
echo "زرار «حسّن»\n";
$off = $logSize();
[$c, $j] = $api(['action' => 'improve', 'id' => $cid, 'idea_id' => $iid]);
$reqs = mock_texts($off, '[SELF_EVAL]{');
$row = $idea($iid);
t('«حسّن» = نفس الطلب الواحد (كتابة + تقييم)', $c === 200 && count($reqs) >= 1 && str_contains($reqs[0], '[SELF_EVAL]{'), $c . ' · ' . count($reqs));
// SE_MISSING دايمًا من غير تقييم ← إعادة مرة ← محتاج مراجعة (مفيش تحسين تلقائي فوق زرار العميل)
t('«حسّن» مافيهوش تحسين تلقائي فوقه', count($reqs) <= 2 && !str_contains(implode('', $reqs), CAMP_IMPROVE_MARK . ' —'));
[$cid2, $iid2] = $camp('NORMAL2');
$api(['action' => 'content_generate', 'id' => $cid2, 'idea_id' => $iid2]);
$off = $logSize();
[$c, $j] = $api(['action' => 'improve', 'id' => $cid2, 'idea_id' => $iid2]);
$row = $idea($iid2);
$e = $evj($row);
t('«حسّن» بيحفظ التقييم الجديد والنسخة اللي قبلها', $c === 200 && count(mock_texts($off, '[SELF_EVAL]{')) === 1 && (int) $row['eval_score'] === 92 && isset($e['prev_text']), json_encode($j, JSON_UNESCAPED_UNICODE));

/* ─── 7) «اعتمد الجاهز» ─── */
echo "اعتماد الجاهز\n";
[$cid3, $iid3] = $camp('APPROVE');
$api(['action' => 'content_generate', 'id' => $cid3, 'idea_id' => $iid3]);
db_run('UPDATE campaign_ideas SET approved = 0, eval_score = 90, eval_json = ? WHERE id = ?', [json_encode(['mode' => 'self', 'score' => 90, 'pass' => false, 'flags' => ['مفيش CTA']], JSON_UNESCAPED_UNICODE), $iid3]);
$api(['action' => 'approve', 'id' => $cid3]);
t('«اعتمد الجاهز»: درجة 90 فيها مخالفة مابتتعتمدش', (int) $idea($iid3)['approved'] === 0);
db_run('UPDATE campaign_ideas SET eval_json = NULL WHERE id = ?', [$iid3]);
$api(['action' => 'approve', 'id' => $cid3]);
t('التقييم القديم (من غير mode) زي ما كان: 90 ≥ الحد ← بيتعتمد', (int) $idea($iid3)['approved'] === 1);

/* ─── 8) الطريقة القديمة ─── */
echo "الطريقة القديمة (classic)\n";
set_setting('campaign_write_mode', 'classic');
[, $iid, $c, , $reqs] = $gen('CLASSIC');
$row = $idea($iid);
t('classic: طلب واحد من غير [SELF_EVAL] والتقييم بالزرار زي الأول', $c === 200 && count($reqs) === 1 && !str_contains($reqs[0] ?? '', '[SELF_EVAL]{') && $row['eval_score'] === null && $row['eval_json'] === null,
    $c . ' · ' . count($reqs) . ' · ' . json_encode([$row['eval_score'], $row['eval_json']]));
db_run('DELETE FROM settings WHERE setting_key = "campaign_write_mode"');

/* ─── تنضيف ─── */
$cids = array_column(db_all('SELECT id FROM contents WHERE user_id = ?', [$uid]), 'id');
foreach (['content_versions', 'content_designs'] as $tb) { if ($cids) { try { db_run("DELETE FROM $tb WHERE content_id IN (" . implode(',', array_map('intval', $cids)) . ')'); } catch (\Throwable $e) {} } }
db_run('DELETE FROM campaign_ideas WHERE user_id = ?', [$uid]);
db_run('DELETE FROM campaigns WHERE user_id = ?', [$uid]);
db_run('DELETE FROM contents WHERE user_id = ?', [$uid]);
db_run('DELETE FROM brand_profiles WHERE user_id = ?', [$uid]);
foreach (['credit_transactions', 'ai_usage_logs', 'usage_events', 'user_plans', 'notifications', 'user_sessions', 'ai_runs'] as $tb) { try { db_run("DELETE FROM $tb WHERE user_id = ?", [$uid]); } catch (\Throwable $e) {} }
try { db_run('DELETE FROM users WHERE id = ?', [$uid]); } catch (\Throwable $e) { echo "  (المستخدم المؤقت #$uid ماتمسحش: " . $e->getMessage() . ")\n"; }
foreach ($keys as $k) {
    db_run('DELETE FROM settings WHERE setting_key = ?', [$k]);
    if ($saved[$k] !== null) set_setting($k, $saved[$k]);
}
db_run('DELETE FROM rate_limits');
@unlink($jar);

echo "\n" . ($fail ? "✗ $fail فشل · " : '✓ ') . "$pass نجح\n";
exit($fail ? 1 : 0);

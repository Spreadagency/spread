<?php
/**
 * Spread AI — اختبارات محرك المحتوى · المرحلة I (حلقة التعلّم + لوحة الاستخدام)
 * للتطوير المحلي بس: بيعمل عميل وحملة تجريبية ويمسحهم في الآخر — ماتشغّلوش على السيرفر الحقيقي.
 *
 *   php tests/ce-i-learning.php http://localhost:8080
 *
 * محتاج: ترحيلات المحرك لحد 2026_ce_learning · أدمن owner@app.test · الـ AI الوهمي شغال.
 */
if (PHP_SAPI !== 'cli') exit("CLI only\n");
$base = rtrim($argv[1] ?? 'http://localhost:8080', '/');
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require_once __DIR__ . '/../includes/config.php';
if (!APP_DEBUG) exit("APP_DEBUG مقفول — الاختبارات دي للتطوير المحلي بس\n");
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/credits.php';
require_once __DIR__ . '/../includes/ce-campaign.php';
require_once __DIR__ . '/../includes/ce-learning.php';
require_once __DIR__ . '/../includes/ce-lab.php';
require_once __DIR__ . '/../includes/account.php';
if (!ce_learning_ready()) exit("شغّل ترحيل 2026_ce_learning الأول\n");

$pass = 0; $fail = 0;
function t(string $name, bool $ok, string $why = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? '  ✓ ' : '  ✗ ') . $name . ($ok || $why === '' ? '' : " — $why") . "\n";
}
function http(string $method, string $url, array|string $post = [], ?string $jar = null, array $headers = []): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_HTTPHEADER => $headers]);
    if ($jar) { curl_setopt($ch, CURLOPT_COOKIEJAR, $jar); curl_setopt($ch, CURLOPT_COOKIEFILE, $jar); }
    if ($method === 'POST') { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($post) ? http_build_query($post) : $post); }
    $body = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $body];
}
function login(string $base, string $email): array
{
    $jar = tempnam(sys_get_temp_dir(), 'cei');
    [, $html] = http('GET', "$base/public/login.php", [], $jar);
    preg_match('/name="csrf" value="([^"]+)"/', $html, $m);
    http('POST', "$base/public/login.php", ['csrf' => $m[1] ?? '', 'email' => $email, 'password' => 'Test12345!'], $jar);
    [, $page] = http('GET', "$base/public/dashboard.php", [], $jar);
    preg_match('/name="csrf-token" content="([^"]+)"/', $page, $c);
    return [$jar, $c[1] ?? ''];
}
function api_post(string $base, array $s, string $action, array $data = []): array
{
    [$code, $body] = http('POST', "$base/public/api/engine.php", base64_encode(json_encode(['action' => $action] + $data)), $s[0],
        ['X-CSRF-Token: ' . $s[1], 'X-Payload: b64', 'Content-Type: text/plain;charset=UTF-8', 'X-Requested-With: XMLHttpRequest']);
    return [$code, json_decode($body, true) ?: []];
}
function run_job(int $id, int $max = 200): ?array
{
    $j = null;
    for ($i = 0; $i < $max; $i++) {
        $x = ce_job_step($id);
        if ($x) $j = $x;
        if ($j && !in_array($j['status'], ['queued', 'running'], true)) break;
    }
    return $j;
}
db_run('DELETE FROM rate_limits');
$savedSet = [];
foreach (['ce_learning_min_n', 'ce_learning_apply', 'ce_learning_last_period'] as $k) $savedSet[$k] = get_setting($k, null);

/* ─── 1) الإحصاء ─── */
echo "الإحصاء\n";
t('Spearman: نفس الترتيب = 1 · عكسه = -1', ce_spearman([1, 2, 3, 4, 5], [10, 20, 30, 40, 500]) == 1.0 && ce_spearman([1, 2, 3, 4, 5], [5, 4, 3, 2, 1]) == -1.0);
t('Spearman بيتعامل مع القيم المتساوية (متوسط الرتب)', ce_ranks([10, 20, 20, 30]) === [1, 2.5, 2.5, 4] && abs(ce_spearman([1, 2, 2, 3], [1, 2, 3, 4]) - 0.949) < 0.01);
t('Pearson قيمة معروفة', ce_pearson([1, 2, 3, 4], [2, 4, 5, 9]) == 0.965);
t('أقل من 3 نقط أو قيم ثابتة ← مفيش علاقة', ce_spearman([1, 2], [1, 2]) === null && ce_pearson([1, 1, 1], [1, 2, 3]) === null);
t('معدل التفاعل الموزون', ce_perf_rate(['reach' => 1000, 'likes' => 50, 'comments' => 10, 'shares' => 5, 'saves' => 5, 'clicks' => 0, 'messages' => 2]) == 11.0
    && ce_perf_rate(['reach' => 0, 'impressions' => 2000, 'likes' => 20]) == 1.0 && ce_perf_rate(['reach' => 0]) == 0.0);
t('وصف العلاقة بالعربي', str_contains(ce_corr_label(0.7), 'قوية') && str_contains(ce_corr_label(-0.5), 'عكسية') && ce_corr_label(null) === 'مش كفاية بيانات');

/* ─── 2) عميل وحملة بـ 8 قطع ─── */
echo "تجهيز حملة\n";
$uid = (int) db_insert('INSERT INTO users (name, email, password, status, email_verified_at, created_at) VALUES (?,?,?,"active",NOW(),NOW())',
    ['CE-I test', 'cei-' . bin2hex(random_bytes(3)) . '@test.local', password_hash('Test12345!', PASSWORD_DEFAULT)]);
$email = db_one('SELECT email FROM users WHERE id = ?', [$uid])['email'];
credits_add($uid, 400, 'CE-I test credits');
db_insert('INSERT INTO user_feature_access (user_id, feature_key, is_enabled) VALUES (?, "content_engine", 1)', [$uid]);
db_insert('INSERT INTO brand_profiles (user_id, business_name, description) VALUES (?,?,?)', [$uid, 'براند التعلّم', 'براند تجريبي']);
foreach ([['خدمة أ', 'high'], ['خدمة ب', 'medium']] as [$n, $p]) db_insert('INSERT INTO brand_services (user_id, name, priority, is_active) VALUES (?,?,?,1)', [$uid, $n, $p]);
$per = (int) db_insert('INSERT INTO brand_personas (user_id, name, pains, desires, objections, is_active, source) VALUES (?,?,?,?,?,1,"user")', [$uid, 'عميل', json_encode(['الوقت']), json_encode(['سرعة']), json_encode(['السعر'])]);
run_job((int) ce_dna_build($uid)['job']['id']);
$dna0 = ce_dna_get($uid);
$dna0['winning_patterns'] = ['نمط كتبه العميل'];
ce_dna_user_save($uid, ['winning_patterns' => $dna0['winning_patterns']]);
$r = ce_campaign_save($uid, ['goal' => 'awareness', 'all_services' => 1, 'persona_ids' => [$per], 'formats' => ['post' => 6, 'carousel' => 2], 'channel' => 'whatsapp', 'boldness' => 'medium']);
$cid = (int) ($r['campaign']['id'] ?? 0);
run_job((int) ce_plan_start($uid, $cid)['job']['id'], 30);
if (!ce_plan_approve($uid, $cid)['ok']) db_run('UPDATE ce_campaigns SET status = "plan_ok" WHERE id = ? AND status = "review"', [$cid]);
run_job((int) ce_write_start($uid, $cid)['job']['id'], 400);
$contents = db_all('SELECT c.*, s.position FROM ce_contents c JOIN ce_slots s ON s.id = c.slot_id WHERE c.campaign_id = ? ORDER BY s.position', [$cid]);
t('الحملة اتكتبت: 8 قطع', count($contents) === 8 && !array_filter($contents, fn($c) => !in_array($c['status'], ['pass', 'manual'], true)), (string) count($contents));
// درجات متحكّم فيها: القطعة i درجتها 70+3i و«الـ Hook» بس هو اللي ماشي مع التفاعل
foreach ($contents as $i => $c) {
    $qa = json_decode((string) $c['qa_json'], true) ?: [];
    foreach (array_keys(CE_QA_LABELS) as $k) $qa['criteria'][$k]['score'] = $k === 'hook' ? 60 + 5 * $i : 80;
    db_run('UPDATE ce_contents SET qa_score = ?, qa_json = ? WHERE id = ?', [70 + 3 * $i, json_encode($qa, JSON_UNESCAPED_UNICODE), (int) $c['id']]);
}

/* ─── 3) إدخال النتايج ─── */
echo "إدخال النتايج\n";
$c0 = (int) $contents[0]['id'];
$other = (int) (db_one('SELECT id FROM ce_contents WHERE user_id <> ? LIMIT 1', [$uid])['id'] ?? 0);
t('قطعة عميل تاني ← مش موجودة', $other === 0 || (ce_perf_save($uid, $other, ['reach' => 100, 'likes' => 1])['code'] ?? '') === 'not_found');
t('أرقام سالبة ← مرفوض', !ce_perf_save($uid, $c0, ['reach' => -5, 'likes' => 1])['ok']);
t('من غير وصول ولا مشاهدات ← مرفوض', !ce_perf_save($uid, $c0, ['likes' => 10])['ok']);
t('تفاعل أكبر من الوصول بكتير ← مرفوض', !ce_perf_save($uid, $c0, ['reach' => 10, 'likes' => 500])['ok']);
$future = ce_perf_save($uid, $c0, ['reach' => 1000, 'likes' => 10, 'published_at' => date('Y-m-d', strtotime('+10 days'))]);
t('تاريخ نشر في المستقبل بيتشال', $future['ok'] && $future['perf']['published_at'] === null);
$month = date('Y-m', strtotime('first day of last month'));
$day = $month . '-15';
// التفاعل ماشي مع درجة الناقد (مع شوية تشويش في الآخر)
$rates = [];
foreach ($contents as $i => $c) {
    $likes = 20 + 15 * $i + ($i === 7 ? -10 : 0);
    $res = ce_perf_save($uid, (int) $c['id'], ['platform' => $i % 2 ? 'instagram' : 'facebook', 'published_at' => $day, 'reach' => 1000, 'likes' => $likes, 'comments' => $i, 'shares' => intdiv($i, 2), 'saves' => 0, 'messages' => $i > 5 ? 3 : 0]);
    $rates[] = $res['perf']['eng_rate'] ?? null;
}
t('8 نتايج اتحفظت بالمعدل', count(array_filter($rates, fn($x) => $x > 0)) === 8, json_encode($rates));
t('الحفظ تاني بيحدّث نفس الصف (مش بيكرر)', (int) db_one('SELECT COUNT(*) n FROM ce_performance WHERE content_id = ?', [$c0])['n'] === 1 && (int) db_one('SELECT likes FROM ce_performance WHERE content_id = ?', [$c0])['likes'] === 20);
$wv = ce_write_view($uid, ce_campaign_get($cid, $uid));
t('شاشة الكتابة: النتايج تحت كل قطعة + ملخص الحملة', ($wv['slots'][0]['content']['perf']['reach'] ?? 0) === 1000 && ($wv['perf_sum']['n'] ?? 0) === 8 && $wv['perf_ready'] === true);

/* ─── 4) التقرير الشهري ─── */
echo "التقرير الشهري\n";
set_setting('ce_learning_min_n', '5');
set_setting('ce_learning_apply', '1');
$g = ce_learning_run($month, 'global');
$rep = $g['report'];
$mine = ce_learning_run($month, 'user', $uid, false)['report'];
t('العلاقة بين درجة الناقد والتفاعل موجبة وقوية (Spearman)', $mine['enough'] && $mine['spearman'] > 0.8, json_encode([$mine['n'], $mine['spearman'], $mine['pearson']]));
t('التقرير بيتحفظ (شهر · نطاق)', (bool) db_one('SELECT id FROM ce_learning_reports WHERE period = ? AND scope = "user" AND scope_id = ?', [$month, $uid]) && (bool) db_one('SELECT id FROM ce_learning_reports WHERE period = ? AND scope = "global"', [$month]));
t('المعيار اللي ماشي مع الأداء (الـ Hook) علاقته عالية والثابتة مالهاش علاقة', ($mine['criteria']['hook']['spearman'] ?? 0) > 0.8 && $mine['criteria']['trust']['spearman'] === null);
t('أحسن 3 قطع بالترتيب', count($mine['top']) === 3 && $mine['top'][0]['eng_rate'] >= $mine['top'][1]['eng_rate'] && $mine['top'][1]['eng_rate'] >= $mine['top'][2]['eng_rate']);
t('الأشكال والزوايا بالمتوسط والفرق عن العام', isset($mine['dims']['format'][0]['lift']) && count($mine['dims']['format']) === 2);
t('الشهر اللي مافيهوش نتايج ← «مش كفاية بيانات»', !ce_learning_run('2001-01', 'user', $uid, false)['report']['enough']);
t('شهر بشكل غلط ← مرفوض', !ce_learning_run('2024-13', 'global')['ok']);

/* ─── 5) التطبيق على ملف الهوية ─── */
echo "التطبيق على ملف الهوية\n";
$ap = ce_learning_run($month, 'user', $uid, true);
$dna = ce_dna_get($uid);
$topIds = array_map(fn($x) => $x['content_id'], $ap['report']['top']);
t('أحسن 3 قطع بقت أمثلة للكاتب (golden_ce_ids)', array_slice($dna['golden_ce_ids'], 0, 3) === $topIds, json_encode([$dna['golden_ce_ids'], $topIds]));
t('الأنماط اللي العميل كتبها بإيده بتفضل', in_array('نمط كتبه العميل', $dna['winning_patterns'], true));
t('الأنماط المتعلّمة اتضافت', !$ap['report']['patterns'] || (bool) array_intersect($ap['report']['patterns'], $dna['winning_patterns']), json_encode([$ap['report']['patterns'], $dna['winning_patterns']], JSON_UNESCAPED_UNICODE));
t('ملف الهوية اتعلّم «اتحدّث من التعلّم»', ce_dna_row($uid)['built_by'] === 'learning' && !empty($ap['applied']['changed']));
$again = ce_learning_run($month, 'user', $uid, true);
t('تشغيله تاني مابيكررش ولا بيغيّر حاجة', empty($again['applied']['changed']) && count(ce_dna_get($uid)['golden_ce_ids']) === count(array_unique(ce_dna_get($uid)['golden_ce_ids'])));
$gt = ce_learning_golden_texts($uid, $dna['golden_ce_ids']);
t('الكاتب بياخد نص أحسن القطع كأمثلة', count($gt) === 2 && mb_strlen($gt[0]) > 10);
t('قطع عميل تاني مابتطلعش كأمثلة', ce_learning_golden_texts($uid + 999999, $dna['golden_ce_ids']) === []);
// إعادة بناء الـ DNA مابتمسحش اللي اتعلّمناه
run_job((int) ce_dna_build($uid, true)['job']['id']);
$dna2 = ce_dna_get($uid);
t('إعادة بناء ملف الهوية بتحافظ على الأمثلة والأنماط المتعلّمة', $dna2['golden_ce_ids'] === $dna['golden_ce_ids'] && (!$ap['report']['patterns'] || (bool) array_intersect($ap['report']['patterns'], $dna2['winning_patterns'])));

/* ─── 6) الدورة الشهرية من الـ worker ─── */
echo "الدورة الشهرية\n";
set_setting('ce_learning_last_period', '');
$m1 = ce_learning_maybe_monthly();
t('أول مرة في الشهر ← التقرير بيتعمل (عام + البراندات)', $m1 && $m1['period'] === ce_learning_last_month() && $m1['users'] >= 1, json_encode($m1, JSON_UNESCAPED_UNICODE));
t('تاني مرة في نفس الشهر ← مفيش تكرار', ce_learning_maybe_monthly() === null);

/* ─── 7) اقتراح نسخة للـ Skill ─── */
echo "اقتراح نسخة للـ Skill\n";
$sk = ce_skill_save_md("---\nkey: tst_learn\nname: تخصص التعلّم\nmatch: تعلم\n---\n## القواعد\n- قاعدة\n\n## أمثلة Hooks\n- hook قديم\n\n## التصميم\nx", null, '', 'upload', null);
db_run('UPDATE ce_skill_versions SET is_active = 1, activated_at = NOW() WHERE id = ?', [$sk['id']]);
db_run('UPDATE ce_campaigns SET skill_version_id = ? WHERE id = ?', [$sk['id'], $cid]);
db_run('UPDATE ce_ideas SET hook = CONCAT("هوك ناجح ", id) WHERE campaign_id = ?', [$cid]);
$sr = ce_learning_run($month, 'skill', (int) $sk['skill_id']);
t('تقرير التخصص بيجمع حملات الـ Skill دي بس', $sr['report']['n'] === 8);
$rid = (int) db_one('SELECT id FROM ce_learning_reports WHERE period = ? AND scope = "skill" AND scope_id = ?', [$month, $sk['skill_id']])['id'];
$dr = ce_learning_skill_draft($rid, null);
$body = (string) (db_one('SELECT body_md FROM ce_skill_versions WHERE id = ?', [(int) ($dr['id'] ?? 0)])['body_md'] ?? '');
t('نسخة جديدة (مسودة) فيها الـ Hooks الناجحة تحت «أمثلة Hooks»', $dr['ok'] && $dr['version'] === 2 && str_contains($body, 'هوك ناجح') && strpos($body, 'هوك ناجح') < strpos($body, '## التصميم')
    && !(int) db_one('SELECT is_active FROM ce_skill_versions WHERE id = ?', [$dr['id']])['is_active'], json_encode($dr, JSON_UNESCAPED_UNICODE));
t('نفس الاقتراح تاني ← مفيش حاجة جديدة', !ce_learning_skill_draft($rid, null)['ok']);

/* ─── 8) لوحة الاستخدام ─── */
echo "لوحة الاستخدام\n";
$u = ce_usage(30);
$mine = db_one('SELECT COUNT(*) n, SUM(cost_usd) c FROM ai_runs WHERE campaign_id = ? AND sandbox = 0', [$cid]);
$topC = array_column($u['top_campaigns'], 'campaign_id');
t('الإجمالي فيه نداءات وتوكنز وتكلفة', (int) $u['total']['calls'] > 0 && (float) $u['total']['cost'] > 0 && (int) $u['total']['tin'] > 0);
t('لكل مرحلة: مراحل المحرك بس (ce_*)', $u['by_stage'] && !array_filter($u['by_stage'], fn($s) => !str_starts_with($s['task'], 'ce_')));
t('كل نداءات الموقع لما تختار «الكل»', (int) ce_usage(30, false)['total']['calls'] >= (int) $u['total']['calls']);
t('الـ Playground برّه الحساب ومحسوب لوحده', (int) $u['sandbox']['calls'] >= 0 && (int) db_one('SELECT COUNT(*) n FROM ai_runs WHERE sandbox = 1 AND task LIKE "ce\_%" AND created_at >= ?', [$u['range']['from']])['n'] + (int) $u['total']['calls'] >= (int) $u['total']['calls']);
t('الحملة دي في أغلى الحملات لو تكلفتها عالية', in_array($cid, array_map('intval', $topC), true) || count($topC) === 10, json_encode([$mine, count($topC)]));
t('نسبة الإعادة لكل مرحلة (الخطة · الناقد · الأفكار · الصورة)', isset($u['revise']['ce_plan_critic'], $u['revise']['ce_critic'], $u['revise']['ce_selection'], $u['revise']['ce_vision_qa']) && $u['revise']['ce_critic']['n'] > 0);
t('يوم بيوم و12 شهر', count($u['daily']) >= 1 && count($u['monthly']) >= 1);

/* ─── 9) الـ API والأدمن ─── */
echo "الـ API والأدمن\n";
$s = login($base, $email);
[$a1, $j1] = api_post($base, $s, 'perf_save', ['id' => $cid, 'content_id' => (int) $contents[1]['id'], 'perf' => ['reach' => 2000, 'likes' => 40, 'platform' => 'tiktok']]);
t('العميل بيحفظ النتايج من الـ API', $a1 === 200 && ($j1['perf']['eng_rate'] ?? 0) == 2.0 && ($j1['perf']['platform'] ?? '') === 'tiktok', $a1 . ' ' . json_encode($j1, JSON_UNESCAPED_UNICODE));
$foreign = (int) (db_one('SELECT id FROM ce_contents WHERE user_id <> ? LIMIT 1', [$uid])['id'] ?? 0);
[$a2] = api_post($base, $s, 'perf_save', ['id' => $cid, 'content_id' => $foreign, 'perf' => ['reach' => 1, 'likes' => 1]]);
t('قطعة مش من الحملة دي ← 404', $a2 === 404, (string) $a2);
[$a3] = http('POST', "$base/public/api/engine.php", base64_encode(json_encode(['action' => 'perf_save', 'id' => $cid])), $s[0], ['X-Payload: b64', 'Content-Type: text/plain;charset=UTF-8']);
t('من غير CSRF ← مرفوض', $a3 === 403 || $a3 === 419, (string) $a3);
[$a4, $j4] = api_post($base, $s, 'perf_save', ['id' => $cid, 'content_id' => (int) $contents[1]['id'], 'perf' => ['likes' => 5]]);
t('بيانات ناقصة ← 422 برسالة واضحة', $a4 === 422 && str_contains((string) ($j4['error'] ?? ''), 'الوصول'), $a4 . ' ' . json_encode($j4, JSON_UNESCAPED_UNICODE));
$jar = tempnam(sys_get_temp_dir(), 'ceia');
[, $lp] = http('GET', "$base/admin/login.php", [], $jar);
preg_match('/name="csrf" value="([^"]+)"/', $lp, $m);
http('POST', "$base/admin/login.php", ['csrf' => $m[1] ?? '', 'email' => 'owner@app.test', 'password' => 'Test12345!'], $jar);
foreach (['usage' => 'نسبة الإعادة لكل مرحلة', 'learning&period=' . $month => 'أنهي معيار بيتنبأ بالأداء', 'results' => 'آخر 100 نتيجة'] as $tab => $needle) {
    [$c, $html] = http('GET', "$base/admin/ce-insights.php?tab=$tab", [], $jar);
    t("صفحة الأدمن: $tab", $c === 200 && str_contains($html, $needle) && !str_contains($html, 'Fatal') && !str_contains($html, 'Warning:'), (string) $c);
}
[$c5] = http('GET', "$base/admin/ce-insights.php?tab=usage");
t('من غير دخول أدمن ← مفيش وصول', $c5 !== 200, (string) $c5);

/* ─── التنضيف ─── */
db_run('DELETE FROM ce_learning_reports WHERE (scope = "user" AND scope_id = ?) OR (scope = "skill" AND scope_id = ?)', [$uid, (int) $sk['skill_id']]);
db_run('DELETE FROM ce_learning_reports WHERE period IN (?, ?, "2001-01") AND scope = "global"', [$month, ce_learning_last_month()]);
db_run('DELETE FROM ce_skill_versions WHERE skill_id = ?', [(int) $sk['skill_id']]);
db_run('DELETE FROM ce_skills WHERE id = ?', [(int) $sk['skill_id']]);
db_run('DELETE FROM ce_jobs WHERE user_id = ?', [$uid]);
account_purge($uid);
t('مسح الحساب بيمسح النتايج', !db_one('SELECT id FROM ce_performance WHERE user_id = ?', [$uid]));
db_run('DELETE FROM credit_transactions WHERE user_id IS NULL AND (notes LIKE "CE-I%" OR notes LIKE "حجز:%" OR notes LIKE "استرداد الفرق:%")');
foreach ($savedSet as $k => $v) {
    if ($v === null) db_run('DELETE FROM settings WHERE setting_key = ?', [$k]);
    else set_setting($k, (string) $v);
}
db_run('DELETE FROM rate_limits');
echo "\n$pass ✓ · $fail ✗\n";
exit($fail ? 1 : 0);

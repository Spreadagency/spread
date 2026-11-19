<?php
/**
 * Spread AI — اختبارات محرك المحتوى · المرحلة H (مدير البرومبتات + الـ Playground + اختبار الرجوع)
 * للتطوير المحلي بس: بيعمل بيانات تجريبية ويمسحها في الآخر — ماتشغّلوش على السيرفر الحقيقي.
 *
 *   php tests/ce-h-lab.php http://localhost:8080
 *
 * محتاج: ترحيلات المحرك كلها لحد 2026_ce_lab · أدمن owner@app.test · الـ AI الوهمي شغال.
 * علامات الـ AI الوهمي: LAB_SLIGHT في برومبت الناقد ← درجاته تنزل شوية (88) · LAB_WORSE ← تنزل كتير (60) والحكم REVISE
 */
if (PHP_SAPI !== 'cli') exit("CLI only\n");
$base = rtrim($argv[1] ?? 'http://localhost:8080', '/');
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require_once __DIR__ . '/../includes/config.php';
if (!APP_DEBUG) exit("APP_DEBUG مقفول — الاختبارات دي للتطوير المحلي بس\n");
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/credits.php';
require_once __DIR__ . '/../includes/ce-lab.php';
if (!ce_prompts_ready()) exit("شغّل ترحيل 2026_ce_lab الأول\n");

$pass = 0; $fail = 0;
function t(string $name, bool $ok, string $why = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? '  ✓ ' : '  ✗ ') . $name . ($ok || $why === '' ? '' : " — $why") . "\n";
}
function http(string $method, string $url, array|string $post = [], ?string $jar = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_FOLLOWLOCATION => false]);
    if ($jar) { curl_setopt($ch, CURLOPT_COOKIEJAR, $jar); curl_setopt($ch, CURLOPT_COOKIEFILE, $jar); }
    if ($method === 'POST') { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, $post); }
    $body = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $body];
}
/** نسخة جديدة + اختبار رجوع لحد ما يخلص */
function regress(string $stage, ?int $vid, ?string $model = null): array
{
    $r = ce_lab_regression_start($stage, $vid, $model, null);
    if (!$r['ok']) return ['status' => 'start_failed', 'error' => $r['error']];
    for ($i = 0; $i < 20; $i++) {
        $s = ce_lab_regression_step($r['id']);
        if ($s['run']['status'] !== 'running') return $s['run'];
    }
    return ['status' => 'timeout'];
}
// نبدأ من صفر للمراحل اللي بنختبرها (وبنرجّعها في الآخر)
$stages = ['ce_critic', 'ce_ideation', 'ce_writer'];
$in = implode(',', array_map(fn($s) => db()->quote($s), $stages));
$savedVers = db_all("SELECT * FROM ce_prompt_versions WHERE stage IN ($in)");
$savedCases = db_all("SELECT * FROM ce_golden_cases WHERE stage IN ($in)");
$savedRoute = db_one('SELECT chain_json FROM ai_task_routes WHERE task = "ce_writer"');
db_run("DELETE FROM ce_prompt_versions WHERE stage IN ($in)");
db_run("DELETE FROM ce_golden_cases WHERE stage IN ($in)");
db_run('DELETE FROM rate_limits');

/* ─── 1) v0 = البرومبت اللي في الكود ─── */
echo "البرومبتات في الكود (v0)\n";
t('10 مراحل في المدير', count(CE_PROMPT_STAGES) === 10);
t('v0 للناقد = البرومبت اللي المحرك بيستخدمه', ce_critic_system() === ce_prompt_render(ce_prompt_default('ce_critic'), ce_prompt_vars('ce_critic')));
t('المتغيرات بتتملا (الأوزان والحدود)', str_contains(ce_critic_system(), 'hook (الـ Hook) = 15') && str_contains(ce_critic_system(), '≥ 85') && !str_contains(ce_critic_system(), '{{'));
$wr = ce_writer_system(['dna' => ce_demo_dna()]);
t('الكاتب: مجال البراند وصوته والممنوعات من ملف الهوية', str_contains($wr, 'عيادة أسنان') && str_contains($wr, 'رخيص') && str_contains($wr, 'من غير إيموجي'));
t('متغير مش معروف بيفضل ظاهر في المعاينة', ce_prompt_render('x {{nope}} {{pass_total}}', ['pass_total' => 85]) === 'x {{nope}} 85');

/* ─── 2) حفظ النسخ ─── */
echo "حفظ النسخ\n";
$def = ce_prompt_default('ce_critic');
t('متغير مش متاح للمرحلة ← مرفوض', !ce_prompt_save('ce_critic', $def . "\n{{sector}}", '', null)['ok']);
t('من غير تغيير ← مرفوض', !ce_prompt_save('ce_critic', $def, '', null)['ok']);
t('قصير جدًا ← مرفوض', !ce_prompt_save('ce_critic', 'قصير', '', null)['ok']);
$v1 = ce_prompt_save('ce_critic', $def . "\nركّز على الـ Hook.", 'تشديد', null);
$v2 = ce_prompt_save('ce_critic', $def . "\nLAB_WORSE", 'نسخة أسوأ', null);
$v3 = ce_prompt_save('ce_critic', $def . "\nLAB_SLIGHT", 'نسخة أسوأ شوية', null);
t('النسخ بتترقّم v1 · v2 · v3 ومش شغالة', $v1['version'] === 1 && $v2['version'] === 2 && $v3['version'] === 3 && !ce_prompt_active('ce_critic'));
$d = ce_prompt_diff($def, $def . "\nركّز على الـ Hook.");
t('الفرق: سطر واحد اتضاف والباقي زي ما هو', count(array_filter($d, fn($x) => $x['op'] === '+')) === 1 && !array_filter($d, fn($x) => $x['op'] === '-'));

/* ─── 3) التفعيل محتاج اختبار رجوع ─── */
echo "اختبار الرجوع والتفعيل\n";
$a = ce_prompt_activate('ce_critic', $v1['id'], null);
t('تفعيل من غير اختبار ← ممنوع', !$a['ok'] && ($a['code'] ?? '') === 'regression');
$r0 = ce_lab_regression_start('ce_critic', $v1['id'], null, null);
t('اختبار من غير حالات ← بيطلب حالة', !$r0['ok'] && ($r0['code'] ?? '') === 'no_cases');
$seed = ce_lab_case_seed('ce_critic', null);
$case = db_one('SELECT * FROM ce_golden_cases WHERE id = ?', [(int) ($seed['id'] ?? 0)]);
t('حالة من البراند التجريبي: الرسالة + المتغيرات + رد النسخة الحالية كمتوقع', $seed['ok'] && $case && str_contains($case['user_text'], 'CE:critic') && $case['expected_json'] && json_decode($case['expected_json'], true)['verdict'] === 'PASS');
$run1 = regress('ce_critic', $v1['id']);
t('نسخة كويسة ← «عدّى» (المتوسط ماتغيرش)', $run1['status'] === 'passed' && $run1['delta'] == 0, json_encode([$run1['status'], $run1['delta'] ?? null, $run1['error'] ?? null], JSON_UNESCAPED_UNICODE));
$a1 = ce_prompt_activate('ce_critic', $v1['id'], null);
t('بعد ما عدّت ← اتفعّلت والمحرك بيستخدمها', $a1['ok'] && str_contains(ce_critic_system_fresh(), 'ركّز على الـ Hook.'));
$run2 = regress('ce_critic', $v2['id']);
t('نسخة بتبوّظ الحكم ← «فشل» (تحت أقل درجة)', $run2['status'] === 'failed', json_encode([$run2['status'], $run2['avg_new'] ?? null, $run2['note'] ?? null], JSON_UNESCAPED_UNICODE));
t('النسخة الفاشلة مابتتفعّلش', !ce_prompt_activate('ce_critic', $v2['id'], null)['ok']);
$run3 = regress('ce_critic', $v3['id']);
t('نسخة الدرجة فيها نزلت أكتر من 3 ← «تحذير»', $run3['status'] === 'warn' && $run3['delta'] < -3, json_encode([$run3['status'], $run3['delta'] ?? null], JSON_UNESCAPED_UNICODE));
$a3 = ce_prompt_activate('ce_critic', $v3['id'], null);
t('التحذير محتاج تأكيد', !$a3['ok'] && ($a3['code'] ?? '') === 'warn');
t('بالتأكيد ← اتفعّلت', ce_prompt_activate('ce_critic', $v3['id'], null, true)['ok'] && (int) ce_prompt_active_fresh('ce_critic')['version'] === 3);
t('الرجوع لنسخة اتفعّلت قبل كده (v1) من غير اختبار', ce_prompt_activate('ce_critic', $v1['id'], null)['ok'] && (int) ce_prompt_active_fresh('ce_critic')['version'] === 1);
t('الرجوع للي في الكود (v0) بضغطة', ce_prompt_activate('ce_critic', 0, null)['ok'] && !ce_prompt_active_fresh('ce_critic'));

/* ─── 4) الـ Playground (sandbox) ─── */
echo "الـ Playground\n";
$before = ['credits' => (int) db_one('SELECT COUNT(*) n FROM credit_transactions')['n'], 'camps' => (int) db_one('SELECT COUNT(*) n FROM ce_campaigns')['n'],
           'contents' => (int) db_one('SELECT COUNT(*) n FROM ce_contents')['n'], 'jobs' => (int) db_one('SELECT COUNT(*) n FROM ce_jobs')['n']];
$ok = true;
foreach (array_keys(CE_PROMPT_STAGES) as $st) { $i = ce_lab_input($st, 0); if (mb_strlen($i['user']) < 50) $ok = false; }
t('مدخلات لكل المراحل من البراند التجريبي (بنفس دوال المحرك)', $ok);
$iw = ce_lab_input('ce_writer', 0, 'carousel');
t('مدخلات الكاتب فيها ملف الهوية والخانة بالشكل المطلوب', str_contains($iw['user'], 'BRAND_PROFILE') && str_contains($iw['user'], 'FORMAT=carousel') && ($iw['meta']['format'] ?? '') === 'carousel');
$t0 = date('Y-m-d H:i:s', time() - 1);
$models = array_slice(array_column(ce_lab_models('ce_writer'), 'key'), 0, 3);
$res = [];
foreach ($models as $m) $res[] = ce_lab_call('ce_writer', ce_lab_system('ce_writer', null, $iw['vars']), $iw['user'], [], $m);
t('3 موديلات جنب بعض: كل واحد بالتوكنز والتكلفة والوقت', count($res) === 3 && !array_filter($res, fn($r) => !$r['ok'] || $r['tokens_in'] <= 0 || $r['ms'] <= 0), json_encode(array_map(fn($r) => [$r['ok'], $r['model'], $r['error']], $res)));
t('كل موديل اتنادى هو اللي اخترناه', array_map(fn($r) => $r['provider'] . '|' . $r['model'], $res) === $models, json_encode(array_map(fn($r) => $r['provider'] . '|' . $r['model'], $res)));
$sc = ce_lab_score('ce_writer', $res[0]['data'], $iw['meta']);
t('«قيّم بالناقد»: الكتابة بتاخد درجة ناقد الجودة', $sc['valid'] && $sc['critic']['total'] == 92 && $sc['score'] == 92, json_encode($sc, JSON_UNESCAPED_UNICODE));
t('رد مش JSON ← درجة صفر', ce_lab_score('ce_writer', null, $iw['meta'])['score'] == 0);
$after = ['credits' => (int) db_one('SELECT COUNT(*) n FROM credit_transactions')['n'], 'camps' => (int) db_one('SELECT COUNT(*) n FROM ce_campaigns')['n'],
          'contents' => (int) db_one('SELECT COUNT(*) n FROM ce_contents')['n'], 'jobs' => (int) db_one('SELECT COUNT(*) n FROM ce_jobs')['n']];
t('الـ Playground مالمسش بيانات العملاء ولا الكريدت', $before === $after, json_encode([$before, $after]));
$sb = db_one('SELECT COUNT(*) n, SUM(sandbox = 1) s, SUM(user_id IS NULL) u FROM ai_runs WHERE created_at >= ? AND feature = "playground"', [$t0]);
t('كل النداءات اتسجلت sandbox=1 ومن غير رقم عميل', (int) $sb['n'] >= 4 && (int) $sb['s'] === (int) $sb['n'] && (int) $sb['u'] === (int) $sb['n'], json_encode($sb));
// الـ Pipeline: الأفكار بتمشي للاختيار والاختيار للكتابة
$prev = [];
foreach (['ce_ideation', 'ce_selection', 'ce_writer'] as $st) {
    $i = ce_lab_input($st, 0, 'post', $prev);
    $r = ce_lab_call($st, ce_lab_system($st, null, $i['vars']), $i['user'], $i['images']);
    $prev[$st] = $r['data'];
}
$hook = (string) ($prev['ce_ideation']['ideas'][1]['hook'] ?? '');
t('الـ Pipeline: ناتج كل مرحلة بيدخل اللي بعدها (الكاتب أخد الفكرة الأعلى درجة)', $hook !== '' && str_contains(ce_lab_input('ce_writer', 0, 'post', $prev)['user'], $hook), $hook);
$prev['ce_ideation']['ideas'][1]['hook'] = 'Hook معدّل من الأدمن';
t('تعديل الأدمن على ناتج مرحلة بيوصل للمرحلة الجاية', str_contains(ce_lab_input('ce_writer', 0, 'post', $prev)['user'], 'Hook معدّل من الأدمن'));

/* ─── 5) موديل جديد للمرحلة ─── */
echo "تطبيق موديل على مرحلة\n";
ce_lab_case_save('ce_writer', ['name' => 'كتابة بوست', 'user' => $iw['user'], 'vars' => $iw['vars'], 'meta' => $iw['meta'], 'min_score' => 70], null);
$cur = ce_lab_current_model('ce_writer');
$cand = array_values(array_filter(array_column(ce_lab_models('ce_writer'), 'key'), fn($k) => $k !== $cur))[0] ?? null;
t('تطبيق موديل من غير اختبار ← ممنوع', $cand && !ce_lab_model_apply('ce_writer', $cand, null)['ok']);
$rm = regress('ce_writer', null, $cand);
t('اختبار الموديل الجديد على حالات الكتابة ← عدّى', $rm['status'] === 'passed' && $rm['avg_new'] == 92, json_encode([$rm['status'], $rm['avg_new'] ?? null]));
t('موديل مش في السجل ← مرفوض', !ce_lab_regression_start('ce_writer', null, 'evil|model', null)['ok']);
$ap = ce_lab_model_apply('ce_writer', $cand, null);
$chain = json_decode((string) db_one('SELECT chain_json FROM ai_task_routes WHERE task = "ce_writer"')['chain_json'], true);
t('اتطبّق: الجديد أساسي والقديم بقى بديل', $ap['ok'] && ($chain[0]['p'] . '|' . $chain[0]['m']) === $cand && in_array($cur, array_map(fn($c) => $c['p'] . '|' . $c['m'], $chain), true), json_encode($chain));

/* ─── 6) الـ API ─── */
echo "الـ API\n";
[$c1, $b1] = http('GET', "$base/admin/ce-lab-api.php?a=state");
t('من غير دخول أدمن ← مفيش وصول', $c1 !== 200 || !str_contains($b1, '"stages"'), (string) $c1);
$jar = tempnam(sys_get_temp_dir(), 'ceh');
[, $lp] = http('GET', "$base/admin/login.php", [], $jar);
preg_match('/name="csrf" value="([^"]+)"/', $lp, $m);
http('POST', "$base/admin/login.php", ['csrf' => $m[1] ?? '', 'email' => 'owner@app.test', 'password' => 'Test12345!'], $jar);
[$c2, $b2] = http('GET', "$base/admin/ce-lab-api.php?a=state", [], $jar);
$j2 = json_decode($b2, true);
t('الأدمن: الحالة بالمراحل العشرة والبراند التجريبي', $c2 === 200 && count($j2['stages'] ?? []) === 10 && ($j2['brands'][0]['uid'] ?? -1) === 0, (string) $c2);
[$c3] = http('POST', "$base/admin/ce-lab-api.php", ['a' => 'prompt_save', 'p' => base64_encode(json_encode(['stage' => 'ce_critic', 'body' => $def . "\nx"]))], $jar);
t('POST من غير CSRF ← 403', $c3 === 403, (string) $c3);
[, $page] = http('GET', "$base/admin/ce-lab.php", [], $jar);
preg_match('/data-csrf="([^"]+)"/', $page, $cm);
[$c4, $b4] = http('POST', "$base/admin/ce-lab-api.php", ['csrf' => $cm[1] ?? '', 'a' => 'input', 'p' => base64_encode(json_encode(['stage' => 'ce_ideation', 'uid' => 0]))], $jar);
t('POST بالـ CSRF ← مدخلات الأفكار', $c4 === 200 && str_contains((string) (json_decode($b4, true)['user'] ?? ''), 'CE:ideation'), (string) $c4);

/* ─── التنضيف ─── */
db_run("DELETE FROM ce_prompt_versions WHERE stage IN ($in)");
db_run("DELETE FROM ce_golden_cases WHERE stage IN ($in)");
db_run("DELETE FROM ce_regression_runs WHERE stage IN ($in)");
foreach ($savedVers as $r) db_insert('INSERT INTO ce_prompt_versions (' . implode(',', array_keys($r)) . ') VALUES (' . implode(',', array_fill(0, count($r), '?')) . ')', array_values($r));
foreach ($savedCases as $r) db_insert('INSERT INTO ce_golden_cases (' . implode(',', array_keys($r)) . ') VALUES (' . implode(',', array_fill(0, count($r), '?')) . ')', array_values($r));
db_run('UPDATE ai_task_routes SET chain_json = ? WHERE task = "ce_writer"', [$savedRoute['chain_json'] ?? null]);
db_run('DELETE FROM rate_limits');
echo "\n$pass ✓ · $fail ✗\n";
exit($fail ? 1 : 0);

/** النسخة الشغالة من غير كاش الطلب (للاختبار بعد التفعيل) */
function ce_prompt_active_fresh(string $stage): ?array
{
    return db_one('SELECT * FROM ce_prompt_versions WHERE stage = ? AND is_active = 1 ORDER BY id DESC LIMIT 1', [$stage]) ?: null;
}
function ce_critic_system_fresh(): string
{
    $a = ce_prompt_active_fresh('ce_critic');
    return ce_prompt_render($a ? (string) $a['body'] : ce_prompt_default('ce_critic'), ce_prompt_vars('ce_critic'));
}

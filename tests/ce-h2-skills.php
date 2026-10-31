<?php
/**
 * Spread AI — اختبارات محرك المحتوى · المرحلة H2 (Skills التخصصات — ملفات MD)
 * للتطوير المحلي بس: بيعمل بيانات تجريبية ويمسحها في الآخر — ماتشغّلوش على السيرفر الحقيقي.
 *
 *   php tests/ce-h2-skills.php http://localhost:8080
 *
 * محتاج: ترحيلات المحرك لحد 2026_ce_skills · أدمن owner@app.test · العميل sub@test.local (عنده Brand DNA) · الـ AI الوهمي شغال.
 * علامات الـ AI الوهمي: LAB_WORSE في برومبت الناقد ← درجاته 60 والحكم REVISE
 */
if (PHP_SAPI !== 'cli') exit("CLI only\n");
$base = rtrim($argv[1] ?? 'http://localhost:8080', '/');
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require_once __DIR__ . '/../includes/config.php';
if (!APP_DEBUG) exit("APP_DEBUG مقفول — الاختبارات دي للتطوير المحلي بس\n");
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/credits.php';
require_once __DIR__ . '/../includes/ce-lab.php';
require_once __DIR__ . '/../includes/ce-campaign.php';
require_once __DIR__ . '/../includes/ce-write.php';
require_once __DIR__ . '/../includes/ce-design.php';
require_once __DIR__ . '/../includes/account.php';
if (!ce_skills_ready() || !ce_prompts_ready()) exit("شغّل ترحيلات 2026_ce_lab و 2026_ce_skills الأول\n");

$pass = 0; $fail = 0;
function t(string $name, bool $ok, string $why = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? '  ✓ ' : '  ✗ ') . $name . ($ok || $why === '' ? '' : " — $why") . "\n";
}
function http(string $method, string $url, array|string $post = [], ?string $jar = null, bool $headers = false): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 90, CURLOPT_FOLLOWLOCATION => false, CURLOPT_HEADER => $headers]);
    if ($jar) { curl_setopt($ch, CURLOPT_COOKIEJAR, $jar); curl_setopt($ch, CURLOPT_COOKIEFILE, $jar); }
    if ($method === 'POST') { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, $post); }
    $body = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $body];
}
function md(string $key, string $name, string $rules, string $extra = ''): string
{
    return "---\nkey: $key\nname: $name\nmatch: تستكوين, testkoin\n---\n# $name\n\n## المعرفة\nمعرفة المجال التجريبي: الخدمات والمخاوف.\n\n## القواعد\n$rules\n\n"
        . "## الممنوعات\n- كلمة_ممنوعة_تجريبية\n- وعد سحري\n\n## أمثلة Hooks\n- Hook تجريبي قوي\n\n## الناقد\n- دقق على الثقة.\n\n"
        . "## التصميم\nBright test studio, natural light.\n\n## ممنوع في الصورة\n- melted props\n- neon signs\n\n## أسئلة البريف\n- عندك فرع تاني؟\n- إيه أكتر خدمة مطلوبة؟\n$extra";
}
function regress_skill(int $sid, int $vid): array
{
    $r = ce_lab_skill_regression_start($sid, $vid, null);
    if (!$r['ok']) return ['status' => 'start_failed', 'error' => $r['error'], 'code' => $r['code'] ?? null];
    for ($i = 0; $i < 20; $i++) {
        $s = ce_lab_regression_step($r['id']);
        if ($s['run']['status'] !== 'running') return $s['run'];
    }
    return ['status' => 'timeout'];
}
$cleanup = function () {
    $ids = array_column(db_all('SELECT id FROM ce_skills WHERE skey LIKE "tst\_%"'), 'id');
    foreach ($ids as $id) {
        db_run('DELETE FROM ce_golden_cases WHERE skill_id = ?', [$id]);
        db_run('DELETE FROM ce_regression_runs WHERE skill_id = ?', [$id]);
        db_run('DELETE FROM ce_skill_versions WHERE skill_id = ?', [$id]);
        db_run('DELETE FROM ce_skills WHERE id = ?', [$id]);
    }
};
$cleanup();
db_run('DELETE FROM rate_limits');
$uid = (int) (db_one('SELECT id FROM users WHERE email = "sub@test.local"')['id'] ?? 0);
$dnaRow = db_one('SELECT * FROM ce_brand_dna WHERE user_id = ?', [$uid]);
if (!$dnaRow) exit("العميل sub@test.local مالوش Brand DNA — شغّل tests/ce-d-engine.php الأول\n");
$preSamples = array_column(db_all('SELECT skey FROM ce_skills'), 'skey');

/* ─── 1) قراية ملف الـ MD ─── */
echo "قراية ملف الـ MD\n";
$p = ce_skill_parse("\xEF\xBB\xBF" . md('tst_a', 'تخصص تجريبي', "- قاعدة 1\n- قاعدة 2", "\n## حاجة مش معروفة\nنص زيادة\n<!-- تعليق للشرح -->\n"));
t('بيانات الملف: المفتاح والاسم وكلمات التعرّف', ($p['meta']['key'] ?? '') === 'tst_a' && ($p['meta']['name'] ?? '') === 'تخصص تجريبي' && str_contains($p['meta']['match'] ?? '', 'تستكوين'));
t('العناوين بتتقري صح («ممنوع في الصورة» مش «الممنوعات»)', isset($p['sections']['visual_avoid'], $p['sections']['forbidden']) && $p['sections']['visual_avoid']['items'] === ['melted props', 'neon signs'], json_encode(array_keys($p['sections'])));
t('البنود (- …) بتتقري كقايمة', $p['sections']['forbidden']['items'] === ['كلمة_ممنوعة_تجريبية', 'وعد سحري'] && count($p['sections']['brief_questions']['items']) === 2);
t('قسم مش معروف ← «أقسام تانية» + ملاحظة', isset($p['sections']['extra']) && str_contains($p['sections']['extra']['text'], 'نص زيادة') && (bool) array_filter($p['warnings'], fn($w) => str_contains($w, 'مش من الأقسام')));
t('تعليقات HTML بتتشال', !str_contains(json_encode($p['sections'], JSON_UNESCAPED_UNICODE), 'تعليق للشرح'));
t('عناوين بالإنجليزي مقبولة', isset(ce_skill_parse("## Rules\n- x\n## Visual Avoid\n- y")['sections']['visual_avoid']));
t('ملف مفيهوش ولا قسم معروف ← مرفوض', (bool) ce_skill_parse("# بس\nكلام")['errors']);
t('ملف أكبر من الحد ← مرفوض', (bool) ce_skill_parse(str_repeat('ا', CE_SKILL_MAX_BYTES))['errors']);
t('الأمثلة اللي مع المنصة: 5 تخصصات كلها بتتقري من غير أخطاء', count(ce_skill_samples()) === 5 && !array_filter(ce_skill_samples(), fn($s) => $s['key'] === ''));
$tplP = ce_skill_parse(ce_skill_template());
t('القالب فيه كل الأقسام ومن غير أخطاء', !$tplP['errors'] && count(array_diff(array_keys(CE_SKILL_SECTIONS), array_keys($tplP['sections']), ['extra'])) === 0, json_encode(array_keys($tplP['sections'])));

/* ─── 2) الحفظ والنسخ ─── */
echo "الحفظ والنسخ\n";
t('Skill جديدة من غير key ← مرفوض', !ce_skill_save_md("---\nname: x\n---\n## القواعد\n- y", null, '', 'upload', null)['ok']);
$a1 = ce_skill_save_md(md('tst_a', 'تخصص تجريبي', "- قاعدة 1"), null, 'أول نسخة', 'upload', null);
$sid = (int) ($a1['skill_id'] ?? 0);
t('أول ملف ← Skill جديدة + v1 (مسودة)', $a1['ok'] && $a1['version'] === 1 && !ce_skill_active_list(true), json_encode($a1, JSON_UNESCAPED_UNICODE));
t('نفس الملف تاني ← مرفوض (مفيش تغيير)', !ce_skill_save_md(md('tst_a', 'تخصص تجريبي', "- قاعدة 1"), null, '', 'upload', null)['ok']);
$a2 = ce_skill_save_md(md('tst_a', 'تخصص تجريبي', "- قاعدة 1\n- LAB_WORSE"), null, 'نسخة أسوأ', 'upload', null);
t('ملف بنفس المفتاح ← v2 لنفس الـ Skill', $a2['ok'] && $a2['version'] === 2 && $a2['skill_id'] === $sid);
t('مفتاح مختلف وأنا بعدّل Skill معيّنة ← مرفوض', !ce_skill_save_md(md('tst_b', 'تاني', "- ق"), $sid, '', 'edit', null)['ok']);

/* ─── 3) التفعيل محتاج اختبار رجوع ─── */
echo "اختبار الرجوع والتفعيل\n";
$x = ce_skill_activate($sid, $a1['id'], null);
t('تفعيل من غير اختبار ← ممنوع', !$x['ok'] && ($x['code'] ?? '') === 'regression');
$r0 = ce_lab_skill_regression_start($sid, $a1['id'], null);
t('اختبار من غير حالات ← بيطلب حالات', !$r0['ok'] && ($r0['code'] ?? '') === 'no_cases');
$seed = ce_lab_skill_seed($sid, null);
$cases = ce_lab_skill_cases($sid);
t('«حالات للـ Skill»: أفكار + كتابة + ناقد (والناقد معاه رد متوقع)', $seed['ok'] && array_column($cases, 'stage') === ['ce_ideation', 'ce_writer', 'ce_critic'] && !empty($cases[2]['expected_json']), json_encode($seed, JSON_UNESCAPED_UNICODE));
t('حالات الـ Skill مش بتدخل اختبارات البرومبت العادية', !array_filter(ce_lab_cases('ce_critic'), fn($c) => (int) ($c['skill_id'] ?? 0) === $sid));
$run1 = regress_skill($sid, $a1['id']);
t('نسخة كويسة ← «عدّى»', $run1['status'] === 'passed', json_encode([$run1['status'], $run1['avg_base'] ?? null, $run1['avg_new'] ?? null, $run1['note'] ?? null], JSON_UNESCAPED_UNICODE));
t('الاختبار شغّل حالات المراحل التلاتة', array_column($run1['items'] ?? [], 'stage') === ['ce_ideation', 'ce_writer', 'ce_critic']);
t('بعد ما عدّت ← اتفعّلت', ce_skill_activate($sid, $a1['id'], null)['ok'] && isset(ce_skill_active_list(true)['tst_a']) && ce_skill_active_list()['tst_a']['version'] === 1);
$run2 = regress_skill($sid, $a2['id']);
t('نسخة بتبوّظ حكم الناقد ← «فشل»', $run2['status'] === 'failed', json_encode([$run2['status'], $run2['note'] ?? null], JSON_UNESCAPED_UNICODE));
t('النسخة الفاشلة مابتتفعّلش', !ce_skill_activate($sid, $a2['id'], null)['ok']);
$a3 = ce_skill_save_md(md('tst_a', 'تخصص تجريبي', "- قاعدة 1\n- قاعدة 3"), $sid, 'تعديل', 'edit', null);
regress_skill($sid, $a3['id']);
t('نسخة تالتة عدّت ← اتفعّلت', ce_skill_activate($sid, $a3['id'], null)['ok'] && ce_skill_active_list(true)['tst_a']['version'] === 3);
t('الرجوع لـ v1 (اتفعّلت قبل كده) من غير اختبار', ($rb = ce_skill_activate($sid, $a1['id'], null))['ok'] && !empty($rb['rollback']) && ce_skill_active_list(true)['tst_a']['version'] === 1);

/* ─── 4) اختيار الـ Skill للبراند ─── */
echo "اختيار الـ Skill للبراند\n";
t('تلقائي من مجال البراند (كلمات التعرّف)', (ce_skill_for_dna(['brand' => ['sector' => 'مركز تستكوين للتجارب', 'skill' => '']])['skey'] ?? '') === 'tst_a');
t('مجال مالوش Skill ← البرومبت العام', ce_skill_for_dna(['brand' => ['sector' => 'ورشة نجارة', 'skill' => '']]) === null);
t('العميل اختار «عام» ← من غير Skill حتى لو المجال مطابق', ce_skill_for_dna(['brand' => ['sector' => 'تستكوين', 'skill' => 'none']]) === null);
t('العميل اختار Skill بالاسم ← بتتاخد حتى لو المجال مختلف', (ce_skill_for_dna(['brand' => ['sector' => 'ورشة نجارة', 'skill' => 'tst_a']])['skey'] ?? '') === 'tst_a');
$clean = ce_dna_clean($uid, ['brand' => ['name' => 'x', 'sector' => 'y', 'skill' => 'مش_موجود']] + ce_dna_get($uid));
t('اختيار مش موجود في الـ DNA ← بيرجع «تلقائي»', $clean['brand']['skill'] === '');
db_run('UPDATE ce_skills SET is_enabled = 0 WHERE id = ?', [$sid]);
t('Skill موقوفة ← مابتتاخدش', ce_skill_for_dna(['brand' => ['sector' => 'تستكوين', 'skill' => 'tst_a']] + []) === null || !isset(ce_skill_active_list(true)['tst_a']));
db_run('UPDATE ce_skills SET is_enabled = 1 WHERE id = ?', [$sid]);
ce_skill_cache_bust();
$sk = ce_skill_active_list()['tst_a'];
t('الحملة بتفضل على النسخة اللي بدأت بيها', (ce_skill_for_campaign(['skill_version_id' => $a3['id']], [])['version'] ?? 0) === 3 && ce_skill_for_campaign(['skill_version_id' => 0], ['brand' => ['sector' => 'تستكوين']]) === null);

/* ─── 5) الـ Skill جوه المراحل ─── */
echo "الـ Skill جوه المراحل\n";
t('الكاتب: المعرفة + القواعد + الممنوعات + الـ Hooks', ($b = ce_skill_block('ce_writer', $sk)) !== '' && str_contains($b, '[المعرفة]') && str_contains($b, '[الممنوعات]') && str_contains($b, 'Hook تجريبي') && !str_contains($b, 'Bright test studio'));
t('برومبت التصميم: التصميم + ممنوع في الصورة بس', ($b = ce_skill_block('ce_design_prompt', $sk)) !== '' && str_contains($b, 'Bright test studio') && !str_contains($b, '[القواعد]'));
t('مراجعة الصورة: ممنوع في الصورة بس', str_contains(ce_skill_block('ce_vision_qa', $sk), 'melted props') && !str_contains(ce_skill_block('ce_vision_qa', $sk), 'Bright'));
t('من غير Skill ← ولا حرف زيادة (البرومبت زي ما هو)', ce_skill_block('ce_writer', null) === '');
$big = $sk;
$big['sections']['knowledge']['text'] = str_repeat('معلومة طويلة ', 600);
t('القسم الطويل بيتقص جوه البرومبت', mb_strlen(ce_skill_block('ce_writer', $big)) <= CE_SKILL_BLOCK_CHARS + 300);
$ctx = ['dna' => ce_demo_dna(), 'skill' => $sk];
$flags = ce_content_flags(['hooks' => ['بوست فيه كلمة_ممنوعة_تجريبية']], $ctx, []);
t('ممنوعات الـ Skill بيتشيّك عليها في الكود', (bool) array_filter($flags, fn($f) => str_contains($f, 'كلمة_ممنوعة_تجريبية')), json_encode($flags, JSON_UNESCAPED_UNICODE));
// المحرك فعلًا بيبعت الجزء ده للموديل: الناقد الوهمي بيدّي 60 لما يلاقي LAB_WORSE في البرومبت
$worse = ce_skill_version_load($a2['id']);
$in = ce_lab_input('ce_critic', 0, 'post');
$sys = ce_critic_system();
$plain = ce_ai_json('ce_critic', $sys, $in['user'], ['sandbox' => true, 'feature' => 'playground']);
$with = ce_ai_json('ce_critic', $sys, $in['user'], ['sandbox' => true, 'feature' => 'playground', 'skill' => $worse]);
t('ce_ai_json بيضيف جزء الـ Skill لبرومبت النظام (الناقد اتأثر)', ($plain['data']['criteria']['hook']['score'] ?? 0) == 92 && ($with['data']['criteria']['hook']['score'] ?? 0) == 60,
  json_encode([$plain['data']['criteria']['hook']['score'] ?? null, $with['data']['criteria']['hook']['score'] ?? null]));

/* ─── 6) الحملة: أسئلة المجال ─── */
echo "أسئلة المجال في الحملة\n";
$dnaNow = ce_dna_get($uid);
$dnaNow['brand']['skill'] = 'tst_a';
ce_dna_user_save($uid, ['brand' => $dnaNow['brand']]);
t('اختيار التخصص بيتحفظ في الـ DNA', (ce_dna_get($uid)['brand']['skill'] ?? '') === 'tst_a');
t('اختيار التخصص لوحده مايقفلش بيانات البراند عن التحديث', !in_array('brand', ce_dna_edited($uid), true) || in_array('brand', json_decode((string) $dnaRow['edited_json'], true) ?: [], true));
$opt = ce_wizard_options($uid);
t('المعالج: أسئلة الـ Skill ظاهرة', ($opt['skill']['name'] ?? '') === 'تخصص تجريبي' && $opt['skill']['questions'] === ['عندك فرع تاني؟', 'إيه أكتر خدمة مطلوبة؟']);
[$brief] = ce_brief_clean($uid, ['goal' => 'awareness', 'all_services' => 1, 'formats' => ['post' => 2], 'channel' => 'whatsapp', 'boldness' => 'medium',
    'skill_answers' => [['q' => 'عندك فرع تاني؟', 'a' => 'أيوه في المعادي'], ['q' => 'سؤال مزوّر', 'a' => 'x'], ['q' => 'إيه أكتر خدمة مطلوبة؟', 'a' => str_repeat('ط', 400)]]]);
t('الإجابات: الأسئلة المزوّرة بتتشال والطويلة بتتقص', count($brief['skill_answers']) === 2 && $brief['skill_answers'][0]['a'] === 'أيوه في المعادي' && mb_strlen($brief['skill_answers'][1]['a']) === 200);
$pctx = ce_plan_ctx($uid, ['id' => 0, 'brief_json' => json_encode($brief, JSON_UNESCAPED_UNICODE), 'start_date' => date('Y-m-d')]);
t('الإجابات بتدخل رسالة الخطة والأفكار والكتابة', str_contains(ce_plan_brief_text($pctx), 'أيوه في المعادي'));
t('سياق الحملة فيه الـ Skill', ($pctx['skill']['skey'] ?? '') === 'tst_a');
$opts = ce_dna_options($uid);
t('صفحة الـ DNA: قايمة التخصصات الشغالة', in_array('tst_a', array_column($opts['skills'], 'key'), true));

/* ─── 7) صفحة الأدمن والـ API ─── */
echo "صفحة الأدمن والـ API\n";
[$c0] = http('GET', "$base/admin/ce-skills.php");
t('من غير دخول أدمن ← مفيش وصول', $c0 !== 200, (string) $c0);
$jar = tempnam(sys_get_temp_dir(), 'ceh2');
[, $lp] = http('GET', "$base/admin/login.php", [], $jar);
preg_match('/name="csrf" value="([^"]+)"/', $lp, $m);
http('POST', "$base/admin/login.php", ['csrf' => $m[1] ?? '', 'email' => 'owner@app.test', 'password' => 'Test12345!'], $jar);
[$c1, $page] = http('GET', "$base/admin/ce-skills.php?id=$sid", [], $jar);
t('صفحة الـ Skill: النسخ والحالات والأقسام', $c1 === 200 && str_contains($page, 'تخصص تجريبي') && str_contains($page, 'v3') && str_contains($page, 'اللي بيتضاف لكل مرحلة'), (string) $c1);
preg_match('/name="csrf" value="([^"]+)"/', $page, $cm);
$csrf = $cm[1] ?? '';
$b64 = fn(string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
[$c2] = http('POST', "$base/admin/ce-skills.php", ['csrf' => $csrf, 'act' => 'upload', 'skill_id' => $sid, 'md' => $b64(md('tst_a', 'تخصص تجريبي', "- قاعدة من الأدمن")), 'notes' => $b64('من الصفحة'), 'from_editor' => 1, '_b64' => 'md,notes'], $jar);
t('رفع نسخة من الصفحة (النص مشفّر base64) ← v4', $c2 === 302 && (int) db_one('SELECT MAX(version) n FROM ce_skill_versions WHERE skill_id = ?', [$sid])['n'] === 4, (string) $c2);
$v4 = db_one('SELECT * FROM ce_skill_versions WHERE skill_id = ? AND version = 4', [$sid]);
t('الملاحظات والمصدر اتحفظوا', ($v4['notes'] ?? '') === 'من الصفحة' && $v4['source'] === 'edit');
[$c3] = http('POST', "$base/admin/ce-skills.php", ['act' => 'activate', 'skill_id' => $sid, 'version_id' => 0], $jar);
t('POST من غير CSRF ← مرفوض', $c3 === 403 || $c3 === 419 || $c3 === 400, (string) $c3);
[$c4, $dl] = http('GET', "$base/admin/ce-skills.php?download=" . (int) $v4['id'], [], $jar, true);
t('تحميل النسخة كملف MD', $c4 === 200 && stripos($dl, 'attachment; filename="tst_a-v4.md"') !== false && str_contains($dl, 'قاعدة من الأدمن'));
[$c5, $tpl] = http('GET', "$base/admin/ce-skills.php?download=template", [], $jar);
t('تحميل القالب', $c5 === 200 && str_contains($tpl, '## ممنوع في الصورة'));
[, $lab] = http('GET', "$base/admin/ce-lab.php", [], $jar);
preg_match('/data-csrf="([^"]+)"/', $lab, $lm);
$api = fn(string $a, array $d) => http('POST', "$base/admin/ce-lab-api.php", ['csrf' => $lm[1] ?? '', 'a' => $a, 'p' => base64_encode(json_encode($d, JSON_UNESCAPED_UNICODE))], $jar);
[$c6, $b6] = $api('run', ['stage' => 'ce_writer', 'user' => ce_lab_input('ce_writer', 0, 'post')['user'], 'models' => [], 'skill_version_id' => (int) $a1['id']]);
$j6 = json_decode($b6, true);
t('الـ Playground بنسخة Skill: البرومبت فيه جزء الـ Skill', $c6 === 200 && str_contains((string) ($j6['system'] ?? ''), 'SECTOR SKILL') && str_contains((string) $j6['system'], 'قاعدة 1'), (string) $c6);
[, $b7] = http('GET', "$base/admin/ce-lab-api.php?a=state", [], $jar);
t('حالة الـ Playground فيها الـ Skills بنسخها', in_array('tst_a', array_column(json_decode($b7, true)['skills'] ?? [], 'key'), true));
[$c8, $b8] = $api('skill_regression_start', ['skill_id' => $sid, 'version_id' => (int) $v4['id']]);
t('بدء اختبار رجوع للـ Skill من الـ API', $c8 === 200 && (json_decode($b8, true)['total'] ?? 0) === 3, (string) $c8);

/* ─── 8) الأمثلة ─── */
echo "الأمثلة اللي مع المنصة\n";
$added = ce_skill_import_samples(null);
t('استيراد الأمثلة كمسودات (مش شغالة)', count($added) + count(array_intersect($preSamples, ['dental', 'restaurant', 'real_estate', 'gym', 'beauty'])) === 5
    && !array_filter(ce_skill_active_list(true), fn($s) => in_array($s['skey'], ['dental', 'restaurant', 'real_estate', 'gym', 'beauty'], true) && !in_array($s['skey'], $preSamples, true)), json_encode($added, JSON_UNESCAPED_UNICODE));
t('استيراد تاني مايكرّرش', ce_skill_import_samples(null) === []);

/* ─── 9) حملة كاملة بـ Skill (الخطة ← الكتابة ← التصميم) ─── */
echo "حملة كاملة بـ Skill\n";
function run_job(int $id, int $max = 150): ?array
{
    $j = null;
    for ($i = 0; $i < $max; $i++) {
        $x = ce_job_step($id);
        if ($x) $j = $x;
        if ($j && !in_array($j['status'], ['queued', 'running'], true)) break;
    }
    return $j;
}
// نسخة فيها LAB_WORSE في القواعد (بتوصل للناقد) — بنفعّلها مباشرة في القاعدة هنا عشان نشوف أثرها في المحرك
db_run('UPDATE ce_skill_versions SET is_active = (id = ?) WHERE skill_id = ?', [$a2['id'], $sid]);
ce_skill_cache_bust();
$eu = (int) db_insert('INSERT INTO users (name, email, password, status, created_at) VALUES (?,?,?,"active",NOW())', ['CE-H2 test', 'ceh2-' . bin2hex(random_bytes(3)) . '@test.local', password_hash('x', PASSWORD_DEFAULT)]);
credits_add($eu, 300, 'CE-H2 test credits');
db_insert('INSERT INTO brand_profiles (user_id, business_name, description, industry) VALUES (?,?,?,?)', [$eu, 'مركز التجارب', 'مركز تجريبي', 'مركز تستكوين']);
db_insert('INSERT INTO brand_services (user_id, name, priority, is_active) VALUES (?,?,?,1)', [$eu, 'خدمة تجريبية', 'high']);
$ep = (int) db_insert('INSERT INTO brand_personas (user_id, name, pains, desires, objections, is_active, source) VALUES (?,?,?,?,?,1,"user")', [$eu, 'عميل تجريبي', json_encode(['الوقت']), json_encode(['سرعة']), json_encode(['السعر'])]);
run_job((int) ce_dna_build($eu)['job']['id']);
t('الـ DNA اتبنى والتخصص اتعرف من المجال', (ce_skill_for_dna(ce_dna_get($eu) ?? [])['skey'] ?? '') === 'tst_a');
$cr = ce_campaign_save($eu, ['goal' => 'awareness', 'all_services' => 1, 'persona_ids' => [$ep], 'formats' => ['post' => 1], 'channel' => 'whatsapp', 'boldness' => 'medium',
                              'skill_answers' => [['q' => 'عندك فرع تاني؟', 'a' => 'فرع في طنطا']]]);
$ecid = (int) ($cr['campaign']['id'] ?? 0);
run_job((int) ce_plan_start($eu, $ecid)['job']['id'], 20);
$ec = db_one('SELECT * FROM ce_campaigns WHERE id = ?', [$ecid]);
t('الحملة اتربطت بنسخة الـ Skill اللي بدأت بيها', (int) $ec['skill_version_id'] === (int) $a2['id'], (string) $ec['skill_version_id']);
t('إجابات أسئلة المجال اتحفظت في البريف', str_contains((string) $ec['brief_json'], 'فرع في طنطا'));
if (!ce_plan_approve($eu, $ecid)['ok']) db_run('UPDATE ce_campaigns SET status = "plan_ok" WHERE id = ? AND status = "review"', [$ecid]);
// نسخة تانية بقت شغالة في النص — الحملة لازم تفضل على اللي بدأت بيها
db_run('UPDATE ce_skill_versions SET is_active = (id = ?) WHERE skill_id = ?', [$a1['id'], $sid]);
ce_skill_cache_bust();
run_job((int) ce_write_start($eu, $ecid)['job']['id']);
$cnt = db_one('SELECT * FROM ce_contents WHERE campaign_id = ? LIMIT 1', [$ecid]);
t('الناقد في المحرك اشتغل بالـ Skill بتاعة الحملة (LAB_WORSE ← محتاجة مراجعة)', $cnt && $cnt['status'] === 'manual', json_encode([$cnt['status'] ?? null, $cnt['qa_score'] ?? null]));
db_run('UPDATE ce_contents SET status = "pass" WHERE campaign_id = ?', [$ecid]);
db_run('UPDATE ce_campaigns SET status = "done" WHERE id = ?', [$ecid]);
$ds = ce_design_start($eu, $ecid, false);
if (!empty($ds['job'])) run_job((int) $ds['job']['id']);
$dz = db_one('SELECT * FROM ce_designs WHERE campaign_id = ? LIMIT 1', [$ecid]);
t('التصميم: «ممنوع في الصورة» من الـ Skill في الـ negative', $dz && str_contains((string) $dz['negative'], 'melted props'), json_encode([$ds['ok'] ?? null, $ds['error'] ?? null, mb_substr((string) ($dz['negative'] ?? ''), 0, 120)]));
db_run('DELETE FROM ce_jobs WHERE user_id = ?', [$eu]);
account_purge($eu);
db_run('DELETE FROM credit_transactions WHERE user_id IS NULL AND (notes LIKE "CE-H2%" OR notes LIKE "حجز:%" OR notes LIKE "استرداد الفرق:%")');

/* ─── التنضيف ─── */
db_run('UPDATE ce_brand_dna SET dna_json = ?, prev_json = ?, edited_json = ?, tokens_est = ?, version = ? WHERE user_id = ?',
    [$dnaRow['dna_json'], $dnaRow['prev_json'], $dnaRow['edited_json'], $dnaRow['tokens_est'], $dnaRow['version'], $uid]);
$cleanup();
foreach (['dental', 'restaurant', 'real_estate', 'gym', 'beauty'] as $k) {
    if (in_array($k, $preSamples, true)) continue;
    $s = db_one('SELECT id FROM ce_skills WHERE skey = ?', [$k]);
    if ($s) { db_run('DELETE FROM ce_skill_versions WHERE skill_id = ?', [$s['id']]); db_run('DELETE FROM ce_skills WHERE id = ?', [$s['id']]); }
}
db_run('DELETE FROM rate_limits');
echo "\n$pass ✓ · $fail ✗\n";
exit($fail ? 1 : 0);

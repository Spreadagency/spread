<?php
/**
 * Spread AI — خطة الحملات · المرحلة 1: التصميم بالكتابة (زي القديم) في المحرك + الدالة المشتركة
 * للتطوير المحلي بس — بيعمل عميل وحملات تجريبية ويمسحهم، وبيرجّع الإعدادات زي ما كانت.
 *
 *   php tests/cc1-design.php http://localhost:8080
 *
 * محتاج: tests/dev-setup.php اتشغّل · الـ AI الوهمي شغال (آخر طلب في storage/logs/mock-ai-last.json).
 */
if (PHP_SAPI !== 'cli') exit("CLI only\n");
$base = rtrim($argv[1] ?? 'http://localhost:8080', '/');
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require_once __DIR__ . '/../includes/config.php';
if (!APP_DEBUG) exit("APP_DEBUG مقفول — الاختبارات دي للتطوير المحلي بس\n");
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/credits.php';
require_once __DIR__ . '/../includes/account.php';
require_once __DIR__ . '/../includes/ce-design.php';
require_once __DIR__ . '/../includes/ce-skills.php';
require_once __DIR__ . '/../includes/design-builder.php';
if (!ce_ready() || !ce_write_ready() || !ce_design_ready()) exit("شغّل ترحيلات المحرك الأول\n");

$pass = 0; $fail = 0;
function t(string $name, bool $ok, string $why = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? '  ✓ ' : '  ✗ ') . $name . ($ok || $why === '' ? '' : " — $why") . "\n";
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
/** كل طلبات الـ AI الوهمي من لحظة معيّنة */
function mock_since(int $offset): array
{
    $f = STORAGE_PATH . '/logs/mock-ai.jsonl';
    clearstatcache();
    if (!is_file($f)) return [];
    $h = fopen($f, 'r');
    fseek($h, $offset);
    $out = [];
    while (($l = fgets($h)) !== false) if ($x = json_decode($l, true)) $out[] = $x;
    fclose($h);
    return $out;
}
$logSize = function (): int { clearstatcache(); $f = STORAGE_PATH . '/logs/mock-ai.jsonl'; return is_file($f) ? filesize($f) : 0; };

db_run('DELETE FROM rate_limits');
$saved = [];
foreach (['ce_design_mode', 'ce_vision_enabled', 'ce_billing_mode', 'ce_carousel_ai_images', 'ce_reel_cover'] as $k) $saved[$k] = get_setting($k, null);
foreach (['ce_design_mode', 'ce_vision_enabled', 'ce_carousel_ai_images'] as $k) db_run('DELETE FROM settings WHERE setting_key = ?', [$k]);   // الافتراضي
set_setting('ce_billing_mode', 'actual');
set_setting('ce_reel_cover', '0');
$uid = 0;
$skillIds = [];
try {

echo "الافتراضي\n";
$r = ce_design_rules();
t('الوضع الافتراضي = تصميم كامل بالكتابة · مراجعة الصورة مقفولة', $r['mode'] === 'full' && $r['vision'] === false, json_encode($r));
set_setting('ce_carousel_ai_images', '1');
t('في الوضع الكامل كل شرايح الكاروسيل تصميم (حتى لو الأدمن محدد أول شريحة بس)', array_column(ce_design_frames('carousel', ['slides' => [['text' => 'أ'], ['text' => 'ب'], ['text' => 'ج']], 'design_brief' => []]), 'kind') === ['ai', 'ai', 'ai']);
db_run('DELETE FROM settings WHERE setting_key = "ce_carousel_ai_images"');

echo "الدالة المشتركة\n";
$txt = design_brief_text(['focal_subject' => 'مريض مبتسم', 'layout' => 'centered', 'mood' => 'warm', 'headline' => 'ابتسامتك تستاهل', 'subline' => 'احجز النهارده', 'colors_hex' => ['#0C87EF', 'javascript:x']]);
t('البريف ← سطور للموديل: الموضوع والتكوين والمود + العنوان بالعربي «اكتبه بالظبط» + الألوان السليمة بس',
    str_contains($txt, 'Main subject: مريض مبتسم') && str_contains($txt, 'render exactly') && str_contains($txt, 'ابتسامتك تستاهل') && str_contains($txt, '#0C87EF') && !str_contains($txt, 'javascript'), $txt);
t('بريف فاضي ← مفيش سطور', design_brief_text([]) === '');
$sk = ['name' => 'أسنان', 'sections' => ['visual' => ['text' => 'ألوان هادية ونضافة'], 'visual_avoid' => ['items' => ['دم', 'أدوات حادة']]]];
$sv = design_skill_text($sk);
t('قسم التصميم + «ممنوع في الصورة» من الـ Skill', str_contains($sv, 'SECTOR DESIGN RULES (أسنان)') && str_contains($sv, 'ألوان هادية') && str_contains($sv, 'Never show in the image: دم, أدوات حادة'), $sv);
t('من غير Skill ← فاضي', design_skill_text(null) === '');

/* ─── عميل + حملة اتكتبت ─── */
$uid = (int) db_insert('INSERT INTO users (name, email, password, status, created_at) VALUES (?,?,?,"active",NOW())',
    ['CC1 test', 'cc1-' . bin2hex(random_bytes(3)) . '@test.local', password_hash('x', PASSWORD_DEFAULT)]);
credits_add($uid, 300, 'CC1 test credits');
@mkdir(STORAGE_PATH . '/uploads/devtest', 0755, true);
$logo = 'uploads/devtest/cc1-logo-' . bin2hex(random_bytes(3)) . '.png';
$im = imagecreatetruecolor(64, 64); imagefill($im, 0, 0, imagecolorallocate($im, 220, 40, 40)); imagepng($im, STORAGE_PATH . '/' . $logo); imagedestroy($im);
db_insert('INSERT INTO brand_profiles (user_id, business_name, industry, description, colors, logo_path) VALUES (?,?,?,?,?,?)', [$uid, 'عيادة CC1', 'عيادة أسنان', 'عيادة أسنان', '#0C87EF, #2EE3CC', $logo]);
foreach ([['تبييض', 'high'], ['تقويم', 'medium']] as [$n, $p]) db_insert('INSERT INTO brand_services (user_id, name, priority, is_active) VALUES (?,?,?,1)', [$uid, $n, $p]);
$per = [];
foreach (['أم مشغولة', 'شاب'] as $n) $per[] = (int) db_insert('INSERT INTO brand_personas (user_id, name, pains, desires, objections, is_active, source) VALUES (?,?,?,?,?,1,"user")',
    [$uid, $n, json_encode(['الوقت']), json_encode(['سرعة']), json_encode(['السعر'])]);
run_job((int) ce_dna_build($uid)['job']['id']);
$written = function (array $formats) use ($uid, $per): int {
    $r = ce_campaign_save($uid, ['goal' => 'awareness', 'all_services' => 1, 'persona_ids' => $per, 'formats' => $formats, 'channel' => 'whatsapp', 'boldness' => 'medium', 'exclusions' => []]);
    $cid = (int) $r['campaign']['id'];
    run_job((int) ce_plan_start($uid, $cid)['job']['id'], 20);
    if (!ce_plan_approve($uid, $cid)['ok']) db_run('UPDATE ce_campaigns SET status = "plan_ok" WHERE id = ? AND status = "review"', [$cid]);
    run_job((int) ce_write_start($uid, $cid)['job']['id']);
    return $cid;
};

echo "تصميم حملة (الوضع الكامل)\n";
$cid = $written(['post' => 1, 'carousel' => 1]);
$c = ce_campaign_get($cid, $uid);
$v = ce_design_view($uid, $c);
$bal = credits_balance($uid);
$off = $logSize();
$ds = ce_design_start($uid, $cid);
$hold = $bal - credits_balance($uid);
$j = run_job((int) $ds['job']['id']);
$reqs = mock_since($off);
$rows = db_all('SELECT d.*, s.format FROM ce_designs d JOIN ce_slots s ON s.id = d.slot_id WHERE d.campaign_id = ? ORDER BY d.slot_id, d.frame', [$cid]);
$byF = [];
foreach ($rows as $d) $byF[$d['format']][] = $d;
t('الشغلانة خلصت وكل التصميمات جاهزة', $j && $j['status'] === 'done' && $rows && !array_filter($rows, fn($d) => $d['status'] !== 'pass'), json_encode(['st' => $j['status'] ?? null, 'err' => $j['error'] ?? null, 'rows' => array_column($rows, 'status')], JSON_UNESCAPED_UNICODE));
$p0 = $byF['post'][0] ?? [];
t('البرومبت من التجهيز القديم: «تصميم سوشيال» + العنوان العربي جوه التصميم (مش «مساحة فاضية ومن غير نص»)',
    $p0 && !str_contains((string) $p0['prompt'], 'clean, calm and empty') && !str_contains((string) $p0['prompt'], 'Absolutely no text') && str_contains((string) $p0['prompt'], 'Arabic'), mb_substr((string) ($p0['prompt'] ?? ''), 0, 300));
$imgReqs = array_values(array_filter($reqs, fn($x) => str_contains((string) $x['path'], '/images/')));
$visionReqs = array_values(array_filter($reqs, fn($x) => str_contains((string) $x['body'], 'CE:vision_qa')));
$promptReqs = array_values(array_filter($reqs, fn($x) => str_contains((string) $x['body'], 'CE:design_prompt')));
$frames = count($rows);
t('صورة واحدة لكل تصميم · من غير مراجعة صورة ولا برومبت المحرك القديم', count($imgReqs) === $frames && !$visionReqs && !$promptReqs, json_encode([count($imgReqs), $frames, count($visionReqs), count($promptReqs)]));
t('اللوجو اتبعت للموديل كمرجع (images/edits)', $imgReqs && str_ends_with((string) $imgReqs[0]['path'], '/images/edits'), (string) ($imgReqs[0]['path'] ?? ''));
$car = $byF['carousel'] ?? [];
// كاتب برومبت التصميم (design_base) بياخد الفكرة + البريف + قسم الـ Skill ويكتب البرومبت النهائي — زي القديم بالظبط
$writerReqs = array_values(array_filter($reqs, fn($x) => str_contains((string) $x['path'], '/chat/completions') && str_contains((string) $x['body'], 'Art Director')));
$wAll = implode("\n", array_map(fn($x) => json_decode((string) $x['body'], true)['messages'][1]['content'] ?? '', $writerReqs));
t('كاتب برومبت التصميم القديم اتنده لكل تصميم وبياخد بريف المحرك (الموضوع · الإضاءة · العنوان)', count($writerReqs) === $frames && str_contains($wAll, 'Main subject:') && str_contains($wAll, 'Lighting:') && str_contains($wAll, 'render exactly'), (string) count($writerReqs));
t('الكاروسيل: كل الشرايح تصميم كامل · الشريحة 2+ بتاخد الأولى مرجع («slide 2 of» للكاتب + الشريحة الأولى في الصور المرفقة)', count($car) >= 5 && !array_filter($car, fn($d) => $d['kind'] !== 'ai')
    && str_contains($wAll, 'slide 2 of') && str_contains((string) $car[1]['prompt'], 'OF THIS SAME CAROUSEL'), (string) ($car[1]['prompt'] ?? ''));
$sz = fn($d) => @getimagesize(STORAGE_PATH . '/' . $d['final_path']);
t('النهائية بالمقاس الصح (1080×1080 · 1080×1350)', ($sz($p0)[1] ?? 0) === 1080 && ($sz($car[0] ?? [])[1] ?? 0) === 1350);
t('النهائية = نفس الصورة (من غير نص بالكود فوقها)', $p0 && md5_file(STORAGE_PATH . '/' . $p0['final_path']) === md5_file(STORAGE_PATH . '/' . $p0['image_path']));
$qa = json_decode((string) ($p0['qa_json'] ?? ''), true);
t('مراجعة الصورة متعلّمة «مقفولة»', !empty($qa['skipped']));
$h = db_one('SELECT * FROM ce_credit_holds WHERE id = ?', [(int) ($j['hold_id'] ?? 0)]);
t('الحجز اتسوّى والباقي رجع', $h && $h['status'] === 'settled' && credits_balance($uid) === $bal - (int) $h['used'], json_encode([$h['status'] ?? null, $h['used'] ?? null, $hold]));

echo "تعديل النص (الكتابة جوه التصميم)\n";
$bal = credits_balance($uid);
$tx = ce_design_text($uid, $cid, (int) $p0['slot_id'], 1, ['headline' => 'عنوان جديد للتصميم']);
t('عنوان جديد ← تصميم جديد بالنص الجديد (شغلانة)', $tx['ok'] && !empty($tx['regen']) && !empty($tx['job']['id']), json_encode($tx, JSON_UNESCAPED_UNICODE));
$off = $logSize();
run_job((int) $tx['job']['id'], 30);
$p1 = ce_design_row((int) $p0['slot_id'], 1);
$last = mock_since($off);
t('التصميم الجديد اتعمل بالعنوان الجديد والصورة اتغيرت', $p1['status'] === 'pass' && $p1['headline'] === 'عنوان جديد للتصميم' && $p1['image_path'] !== $p0['image_path']
    && str_contains(json_encode($last, JSON_UNESCAPED_UNICODE), 'عنوان جديد للتصميم'));
t('نفس النص ← مرفوض', !ce_design_text($uid, $cid, (int) $p0['slot_id'], 1, ['headline' => 'عنوان جديد للتصميم'])['ok']);

echo "الـ Skill في التصميم\n";
$skKey = 'cc1_' . bin2hex(random_bytes(2));
$md = "---\nkey: $skKey\nname: تخصص CC1\nmatch: عيادة CC1\n---\n## التصميم\n- CC1_VISUAL_RULE ألوان هادية\n## ممنوع في الصورة\n- CC1_NEVER_SHOW\n## القواعد\n- قاعدة\n";
$sv = ce_skill_save_md($md, null, 'cc1', 'upload', null);
if (!empty($sv['skill_id'])) $skillIds[] = (int) $sv['skill_id'];
$vid = (int) ($sv['id'] ?? 0);
if ($vid) { db_run('UPDATE ce_skill_versions SET is_active = (id = ?) WHERE skill_id = ?', [$vid, (int) $sv['skill_id']]); db_run('UPDATE ce_skills SET is_enabled = 1 WHERE id = ?', [(int) $sv['skill_id']]); ce_skill_active_list(true); }
$cid2 = $written(['post' => 1]);
db_run('UPDATE ce_campaigns SET skill_version_id = ? WHERE id = ?', [$vid, $cid2]);
$off = $logSize();
$j2 = run_job((int) ce_design_start($uid, $cid2)['job']['id']);
$m2 = mock_since($off);
$w2 = implode("\n", array_map(fn($x) => (string) $x['body'], array_filter($m2, fn($x) => str_contains((string) $x['body'], 'Art Director'))));
$i2 = implode("\n", array_map(fn($x) => (string) $x['body'], array_filter($m2, fn($x) => str_contains((string) $x['path'], '/images/'))));
t('«ممنوع في الصورة» في البرومبت النهائي للصورة نفسه (حتى لو الكاتب نساه) · ومن غير منع الكتابة', str_contains($i2, 'Avoid:') && str_contains($i2, 'CC1_NEVER_SHOW') && !str_contains($i2, 'any text, letters'), mb_substr($i2, 0, 200));
t('قسم «التصميم» و«ممنوع في الصورة» من Skill الحملة دخلوا كاتب برومبت التصميم', $vid && $j2 && $j2['status'] === 'done' && str_contains($w2, 'CC1_VISUAL_RULE') && str_contains($w2, 'CC1_NEVER_SHOW'),
    json_encode([$vid, $j2['status'] ?? null, $sv['error'] ?? null], JSON_UNESCAPED_UNICODE));

echo "من غير كاتب البرومبت (الأدمن قفله)\n";
$savedW = get_setting('design_prompt_writer', null);
set_setting('design_prompt_writer', '0');
$cid5 = $written(['post' => 1]);
db_run('UPDATE ce_campaigns SET skill_version_id = ? WHERE id = ?', [$vid, $cid5]);
run_job((int) ce_design_start($uid, $cid5)['job']['id']);
$pw = (string) (db_one('SELECT prompt FROM ce_designs WHERE campaign_id = ?', [$cid5])['prompt'] ?? '');
t('البريف وقسم الـ Skill في البرومبت النهائي نفسه (تصميم سوشيال بالهوية)', str_contains($pw, 'Design a professional social media post image') && str_contains($pw, 'Main subject:')
    && str_contains($pw, 'render exactly') && str_contains($pw, 'CC1_VISUAL_RULE') && str_contains($pw, 'BRAND KIT'), mb_substr($pw, 0, 400));
$savedW === null ? db_run('DELETE FROM settings WHERE setting_key = "design_prompt_writer"') : set_setting('design_prompt_writer', (string) $savedW);

echo "مراجعة الصورة لو الأدمن فتحها\n";
set_setting('ce_vision_enabled', '1');
$cid3 = $written(['post' => 1]);
$off = $logSize();
run_job((int) ce_design_start($uid, $cid3)['job']['id']);
t('اتفتحت ← طلب مراجعة لكل صورة', count(array_filter(mock_since($off), fn($x) => str_contains((string) $x['body'], 'CE:vision_qa'))) === 1);
db_run('DELETE FROM settings WHERE setting_key = "ce_vision_enabled"');

echo "وضع «صورة + نص بالكود» لسه اختيار\n";
set_setting('ce_design_mode', 'overlay');
$cid4 = $written(['post' => 1]);
run_job((int) ce_design_start($uid, $cid4)['job']['id']);
$po = db_one('SELECT * FROM ce_designs WHERE campaign_id = ?', [$cid4]);
t('overlay ← البرومبت القديم (مساحة فاضية ومن غير نص) والنص بيترسم بالكود', $po && str_contains((string) $po['prompt'], 'clean, calm and empty') && $po['final_path'] && md5_file(STORAGE_PATH . '/' . $po['final_path']) !== md5_file(STORAGE_PATH . '/' . $po['image_path']));
db_run('DELETE FROM settings WHERE setting_key = "ce_design_mode"');

} finally {
    if ($uid) {
        account_purge($uid);
        db_run('DELETE FROM ce_jobs WHERE user_id = ?', [$uid]);
    }
    foreach ($skillIds as $sid) { db_run('DELETE FROM ce_skill_versions WHERE skill_id = ?', [$sid]); db_run('DELETE FROM ce_skills WHERE id = ?', [$sid]); }
    if (!empty($logo)) @unlink(STORAGE_PATH . '/' . $logo);
    foreach ($saved as $k => $v) { if ($v === null) db_run('DELETE FROM settings WHERE setting_key = ?', [$k]); else set_setting($k, (string) $v); }
    db_run('DELETE FROM credit_transactions WHERE user_id IS NULL AND (notes LIKE "CC1%" OR notes LIKE "حجز:%" OR notes LIKE "استرداد الفرق:%")');
    db_run('DELETE FROM rate_limits');
}
t('رجّعنا البيانات', !$uid || !db_one('SELECT id FROM users WHERE id = ?', [$uid]));
echo "\n$pass ✓ · $fail ✗\n";
exit($fail ? 1 : 0);

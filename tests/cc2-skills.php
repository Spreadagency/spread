<?php
/**
 * Spread AI — خطة الحملات · المرحلة 2: الـ Skills في الحملات القديمة (الأفكار · الكتابة · التقييم · التصميم) + «تسويق عام»
 * للتطوير المحلي بس — بيعمل Skills وعميل وحملة تجريبية ويمسحهم، وبيرجّع الإعدادات زي ما كانت.
 *
 *   php tests/cc2-skills.php http://localhost:8080
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
require_once __DIR__ . '/../includes/campaign-flow.php';
require_once __DIR__ . '/../includes/ce-skills.php';
require_once __DIR__ . '/../includes/ce-dna.php';
require_once __DIR__ . '/../includes/design-builder.php';
if (!ce_skills_ready()) exit("شغّل ترحيل 2026_ce_skills الأول\n");

$pass = 0; $fail = 0;
function t(string $name, bool $ok, string $why = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? '  ✓ ' : '  ✗ ') . $name . ($ok || $why === '' ? '' : " — $why") . "\n";
}
function http(string $method, string $url, array $post = [], ?string $jar = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 90, CURLOPT_FOLLOWLOCATION => false]);
    if ($jar) curl_setopt_array($ch, [CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar]);
    if ($method === 'POST') curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($post)]);
    $b = (string) curl_exec($ch);
    $c = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$c, $b];
}
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
/** Skill من نص + نسخة شغالة مباشرة (الاختبار مش بيعدّي على اختبار الرجوع) */
function mk_skill(string $md): array
{
    $r = ce_skill_save_md($md, null, 'cc2 test', 'upload', null);
    if (empty($r['ok'])) { echo "  ✗ حفظ Skill: " . ($r['error'] ?? '') . "\n"; exit(1); }
    $sid = (int) $r['skill_id'];
    db_run('UPDATE ce_skill_versions SET is_active = (id = ?) WHERE skill_id = ?', [(int) $r['id'], $sid]);
    db_run('UPDATE ce_skills SET is_enabled = 1 WHERE id = ?', [$sid]);
    ce_skill_cache_bust();
    return ['id' => $sid, 'version_id' => (int) $r['id']];
}

db_run('DELETE FROM rate_limits');
$savedSetting = get_setting('campaign_skills_enabled', null);
db_run('DELETE FROM settings WHERE setting_key = "campaign_skills_enabled"');
// لو «general» موجودة من قبل (أدمن استوردها): بنقفلها وقت الاختبار ونرجّعها
$preGeneral = db_one('SELECT id, is_enabled FROM ce_skills WHERE skey = "general"');
if ($preGeneral) { db_run('UPDATE ce_skills SET is_enabled = 0 WHERE id = ?', [(int) $preGeneral['id']]); ce_skill_cache_bust(); }

$tag = bin2hex(random_bytes(3));
$created = ['skills' => [], 'users' => []];

/* ─── 1) عينة «تسويق عام» ─── */
echo "عينة «تسويق عام»\n";
$gmd = (string) file_get_contents(__DIR__ . '/../includes/ce-skills/general.md');
$gp = ce_skill_parse($gmd);
t('general.md بيتقري من غير أخطاء ولا تحذيرات', !$gp['errors'] && !$gp['warnings'], json_encode($gp['errors'] + $gp['warnings'], JSON_UNESCAPED_UNICODE));
t('مفتاحها general ومن غير كلمات تعرّف', ($gp['meta']['key'] ?? '') === 'general' && trim((string) ($gp['meta']['match'] ?? '')) === '');
t('فيها القواعد الأساسية (بداية قوية · CTA · العامية · ممنوع أرقام مخترعة)',
    str_contains($gp['sections']['rules']['text'] ?? '', 'بداية قوية') && str_contains($gp['sections']['rules']['text'] ?? '', 'CTA')
    && str_contains($gp['sections']['rules']['text'] ?? '', 'العامية المصرية') && str_contains($gp['sections']['rules']['text'] ?? '', 'تخترع أرقام'));
t('الأمثلة اللي مع المنصة بقت 6 (ومنها general)', count(ce_skill_samples()) === 6 && in_array('general', array_column(ce_skill_samples(), 'key'), true));

/* ─── 2) اختيار الـ Skill: التخصص ← العامة ← none ─── */
echo "اختيار الـ Skill\n";
$word = 'تخصصكاتو' . $tag;
// لو general مش موجودة بنعملها باسم الاختبار، ولو موجودة بنستخدم نسخة جديدة منها
if ($preGeneral) {
    $r = ce_skill_save_md(str_replace('# تسويق عام', '# تسويق عام cc2-' . $tag, $gmd), (int) $preGeneral['id'], 'cc2 test', 'upload', null);
    $genVer = (int) ($r['id'] ?? 0);
    $preActive = db_one('SELECT id FROM ce_skill_versions WHERE skill_id = ? AND is_active = 1', [(int) $preGeneral['id']]);
    db_run('UPDATE ce_skill_versions SET is_active = (id = ?) WHERE skill_id = ?', [$genVer, (int) $preGeneral['id']]);
    db_run('UPDATE ce_skills SET is_enabled = 1 WHERE id = ?', [(int) $preGeneral['id']]);
    ce_skill_cache_bust();
    $gen = ['id' => (int) $preGeneral['id'], 'version_id' => $genVer];
} else {
    $gen = mk_skill($gmd);
    $created['skills'][] = $gen['id'];
}
$spec = mk_skill("---\nkey: cc2_$tag\nname: تخصص cc2\nmatch: $word\n---\n## المعرفة\n- معرفة-cc2-$tag\n## القواعد\n- قاعدة-cc2-$tag\n## الممنوعات\n- كلمة-ممنوعة-cc2\n## أمثلة Hooks\n- هوك-cc2-$tag\n## الناقد\n- ناقد-cc2-$tag\n## التصميم\n- تصميم-cc2-$tag\n## ممنوع في الصورة\n- no-cc2-$tag\n");
$created['skills'][] = $spec['id'];

t('ce_skill_detect مابيرجّعش general أبدًا (حتى لو المجال فيه «تسويق عام»)', ce_skill_detect('شركة تسويق عام') === null || (ce_skill_detect('شركة تسويق عام')['skey'] ?? '') !== 'general');
t('المجال المعروف ← الـ Skill بتاعته', (ce_skill_for_dna(['brand' => ['sector' => 'مركز ' . $word]])['skey'] ?? '') === "cc2_$tag");
t('مجال مالوش Skill ← «تسويق عام»', (ce_skill_for_dna(['brand' => ['sector' => 'محل حاجة غريبة ' . $tag]])['skey'] ?? '') === 'general');
t('اختيار «none» ← من غير Skill', ce_skill_for_dna(['brand' => ['skill' => 'none', 'sector' => 'محل حاجة غريبة']]) === null);
t('اختيار Skill بالاسم بيكسب على المجال', (ce_skill_for_dna(['brand' => ['skill' => 'general', 'sector' => $word]])['skey'] ?? '') === 'general');

/* ─── 3) عميل وحملة قديمة ─── */
$uid = (int) db_insert('INSERT INTO users (name, email, password, status, created_at) VALUES (?,?,?,"active",NOW())', ['CC2 test', "cc2-$tag@test.local", password_hash('x', PASSWORD_DEFAULT)]);
$created['users'][] = $uid;
db_insert('INSERT INTO brand_profiles (user_id, business_name, description, industry, dialect) VALUES (?,?,?,?,?)', [$uid, 'براند cc2', 'وصف تجريبي', 'مركز ' . $word, 'egyptian']);
$brand = db_one('SELECT * FROM brand_profiles WHERE user_id = ?', [$uid]);
$cid = (int) db_insert('INSERT INTO campaigns (user_id, title, basis, topic, status, created_at) VALUES (?,?,?,?, "active", NOW())', [$uid, 'حملة cc2', 'awareness', 'موضوع']);
$c = db_one('SELECT * FROM campaigns WHERE id = ?', [$cid]);
$idea = ['angle' => 'توعية', 'title' => 'فكرة cc2', 'description' => 'تفاصيل', 'audience' => 'الكل', 'hook' => 'هوك'];

echo "الحملات القديمة: الحقن\n";
t('campaign_skill: من مجال البراند (من غير DNA)', (campaign_skill($brand, $c)['skey'] ?? '') === "cc2_$tag");
db_run('UPDATE brand_profiles SET industry = ? WHERE user_id = ?', ['محل حاجة غريبة ' . $tag, $uid]);
$brand2 = db_one('SELECT * FROM brand_profiles WHERE user_id = ?', [$uid]);
t('campaign_skill: مجال مالوش Skill ← «تسويق عام»', (campaign_skill($brand2, $c)['skey'] ?? '') === 'general');

$ip = campaign_ideas_prompt($brand, $c, 3, []);
$ipBase = campaign_ideas_prompt_base($brand, $c, 3, []);
t('برومبت الأفكار: الـ Skill في الأول وبعدها البرومبت القديم بالحرف', str_starts_with($ip, 'SECTOR SKILL') && str_ends_with($ip, $ipBase) && $ip !== $ipBase);
t('الأفكار: المعرفة + الـ Hooks + القواعد (ومن غير الممنوعات/الناقد/التصميم)',
    str_contains($ip, "معرفة-cc2-$tag") && str_contains($ip, "هوك-cc2-$tag") && str_contains($ip, "قاعدة-cc2-$tag")
    && !str_contains($ip, "ناقد-cc2-$tag") && !str_contains($ip, "تصميم-cc2-$tag"));
$cp = campaign_content_prompt($brand, $c, $idea, '', 'post');
t('برومبت الكتابة: المعرفة + القواعد + الممنوعات + الـ Hooks', str_starts_with($cp, 'SECTOR SKILL') && str_contains($cp, 'كلمة-ممنوعة-cc2') && str_contains($cp, "معرفة-cc2-$tag")
    && str_ends_with($cp, campaign_content_prompt_base($brand, $c, $idea, '', 'post')));
$ep = campaign_eval_prompt($brand, $c, $idea, ['generated_text' => "h\n\nb", 'format' => 'post']);
t('برومبت التقييم: القواعد + الممنوعات + تعليمات الناقد', str_contains($ep, "ناقد-cc2-$tag") && str_contains($ep, 'كلمة-ممنوعة-cc2') && !str_contains($ep, "معرفة-cc2-$tag"));
t('الـ Skill ثابتة بالحرف بين الخطوات لنفس البراند (أول البرومبت)', substr($ip, 0, 12) === substr($cp, 0, 12));

// none · مفتاح الطوارئ · الحملة المربوطة بنسخة
db_run('INSERT INTO ce_brand_dna (user_id, dna_json, built_by, updated_at) VALUES (?, ?, "rules", NOW()) ON DUPLICATE KEY UPDATE dna_json = VALUES(dna_json)',
    [$uid, json_encode(['brand' => ['skill' => 'none', 'sector' => 'مركز ' . $word]], JSON_UNESCAPED_UNICODE)]);
t('العميل اختار «none» ← البرومبتات زي القديم بالحرف', campaign_skill($brand, $c) === null
    && campaign_ideas_prompt($brand, $c, 3, []) === campaign_ideas_prompt_base($brand, $c, 3, []));
db_run('DELETE FROM ce_brand_dna WHERE user_id = ?', [$uid]);
set_setting('campaign_skills_enabled', '0');
t('مفتاح الطوارئ campaign_skills_enabled = 0 ← من غير Skill', campaign_skill($brand, $c) === null && campaign_content_prompt($brand, $c, $idea, '', 'post') === campaign_content_prompt_base($brand, $c, $idea, '', 'post'));
db_run('DELETE FROM settings WHERE setting_key = "campaign_skills_enabled"');
t('حملة مربوطة بنسخة (skill_version_id) بتفضل عليها', (campaign_skill($brand2, array_merge($c, ['skill_version_id' => $spec['version_id']]))['skey'] ?? '') === "cc2_$tag"
    && campaign_skill($brand, array_merge($c, ['skill_version_id' => 0])) === null);

// لما مفيش Skills خالص: زي القديم بالحرف
db_run('UPDATE ce_skills SET is_enabled = 0 WHERE id IN (' . implode(',', [$gen['id'], $spec['id']]) . ')');
ce_skill_cache_bust();
$others = ce_skill_active_list(true);
if (!$others) {
    t('مفيش Skills شغالة ← البرومبتات القديمة زي ما هي بالحرف', campaign_ideas_prompt($brand2, $c, 3, []) === campaign_ideas_prompt_base($brand2, $c, 3, []));
}
db_run('UPDATE ce_skills SET is_enabled = 1 WHERE id IN (' . implode(',', [$gen['id'], $spec['id']]) . ')');
ce_skill_cache_bust();

/* ─── 4) لحد المزوّد (الـ AI الوهمي) ─── */
echo "لحد المزوّد\n";
$off = $logSize();
$ai = campaign_ai(campaign_ideas_prompt($brand, $c, 2, []), 1800, 'campaign_ideas', ['user_id' => $uid, 'reference_id' => $cid, 'json_mode' => true]);
$reqs = mock_since($off);
$sent = json_encode($reqs, JSON_UNESCAPED_UNICODE);
t('طلب الأفكار وصل للمزوّد وفيه الـ Skill', !empty($ai['ok']) && str_contains($sent, "هوك-cc2-$tag"), (string) ($ai['error'] ?? ''));
t('والأفكار اتقرت عادي', count(campaign_parse_ideas((string) ($ai['response'] ?? ''))) >= 1);

/* ─── 5) التصميم ─── */
echo "التصميم (المسار القديم)\n";
$cnt = (int) db_insert('INSERT INTO contents (user_id, brand_profile_id, content_type, platform, generated_text, status, campaign_id, format, created_at) VALUES (?,?,?,?,?,?,?,?,NOW())',
    [$uid, (int) $brand['id'], 'post', 'facebook', "هوك\n\nنص المنشور", 'generated', $cid, 'post']);
$content = db_one('SELECT * FROM contents WHERE id = ?', [$cnt]);
$opt = ['uid' => $uid, 'brand' => $brand, 'content' => $content, 'format' => 'post', 'include_logo' => false];
$b0 = design_build($opt);
$off = $logSize();
$b1 = design_build($opt + ['skill' => campaign_skill($brand, $c)]);
// كاتب برومبت التصميم (design_base) بيستلم قواعد الـ Skill — والبرومبت النهائي هو رده
$inputs = json_encode([$b1['inputs'], mock_since($off)], JSON_UNESCAPED_UNICODE);
t('design_build بالـ Skill: قسم التصميم و«ممنوع في الصورة» دخلوا', str_contains($b1['prompt'] . $inputs, "تصميم-cc2-$tag") && str_contains($b1['prompt'] . $inputs, "no-cc2-$tag"),
    mb_substr($b1['prompt'], 0, 200));
t('من غير Skill: نفس التصميم القديم', !str_contains($b0['prompt'], "cc2-$tag"));
$src = (string) file_get_contents(__DIR__ . '/../includes/design-generate.php');   // المنطق اتنقل من public/ajax/generate-design.php
t('design-generate.php بيبعت الـ Skill لتصميمات الحملات بس', str_contains($src, "if (!empty(\$content['campaign_id']))") && str_contains($src, "'skill' => \$__skill"));

/* ─── 6) خريطة المراحل ─── */
echo "خريطة المراحل\n";
t('مراحل القديم في CE_SKILL_STAGE_MAP بنفس أقسام مراحل المحرك المقابلة (اختبار الرجوع بيغطيها)',
    CE_SKILL_STAGE_MAP['campaign_ideas'] === CE_SKILL_STAGE_MAP['ce_ideation'] && CE_SKILL_STAGE_MAP['campaign_content'] === CE_SKILL_STAGE_MAP['ce_writer']
    && CE_SKILL_STAGE_MAP['campaign_eval'] === CE_SKILL_STAGE_MAP['ce_critic'] && CE_SKILL_STAGE_MAP['design_base'] === ['visual', 'visual_avoid']);
t('ce_skill_bind_campaign بيكتب في campaigns لو العمود موجود (ومابيكسرش لو لأ)', (function () use ($cid, $spec) {
    ce_skill_bind_campaign($cid, ['version_id' => $spec['version_id']], 'campaigns');
    ce_skill_bind_campaign($cid, null, 'users');   // جدول مش مسموح ← ولا حاجة
    return true;
})());

/* ─── تنضيف ─── */
db_run('DELETE FROM contents WHERE user_id = ?', [$uid]);
db_run('DELETE FROM campaigns WHERE user_id = ?', [$uid]);
db_run('DELETE FROM brand_profiles WHERE user_id = ?', [$uid]);
db_run('DELETE FROM ce_brand_dna WHERE user_id = ?', [$uid]);
foreach (['credit_transactions', 'ai_usage_logs', 'usage_events'] as $tb) { try { db_run("DELETE FROM $tb WHERE user_id = ?", [$uid]); } catch (\Throwable $e) {} }
db_run('DELETE FROM users WHERE id = ?', [$uid]);
foreach ($created['skills'] as $sid) {
    db_run('DELETE FROM ce_skill_versions WHERE skill_id = ?', [$sid]);
    db_run('DELETE FROM ce_skills WHERE id = ?', [$sid]);
}
if ($preGeneral) {
    db_run('UPDATE ce_skill_versions SET is_active = (id = ?) WHERE skill_id = ?', [(int) ($preActive['id'] ?? 0), (int) $preGeneral['id']]);
    db_run('DELETE FROM ce_skill_versions WHERE id = ?', [$gen['version_id']]);
    db_run('UPDATE ce_skills SET is_enabled = ? WHERE id = ?', [(int) $preGeneral['is_enabled'], (int) $preGeneral['id']]);
}
if ($savedSetting !== null) set_setting('campaign_skills_enabled', $savedSetting);
ce_skill_cache_bust();

echo "\n" . ($fail ? "✗ $fail فشل · " : '✓ ') . "$pass نجح\n";
exit($fail ? 1 : 0);

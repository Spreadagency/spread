<?php
/**
 * Spread AI — الـ Skills في المنشور السريع والاستوديو (خطة الحملات §7 — بعد الإصدار الأول)
 *   إنشاء منشور (build_final_prompt) · تصميم المنشور · فكرة التصميم وتصميم الاستوديو · بريف الاستوديو
 * للتطوير المحلي بس — بيعمل Skill وعميل مؤقتين ويمسحهم.
 *
 *   php tests/cc7-post-skills.php http://localhost:8080
 */
if (PHP_SAPI !== 'cli') exit("CLI only\n");
$base = rtrim($argv[1] ?? 'http://localhost:8080', '/');
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require_once __DIR__ . '/../includes/config.php';
if (!APP_DEBUG) exit("APP_DEBUG مقفول — الاختبارات دي للتطوير المحلي بس\n");
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/credits.php';
require_once __DIR__ . '/../includes/plans.php';
require_once __DIR__ . '/../includes/prompt-builder.php';
require_once __DIR__ . '/../includes/post-skills.php';
if (!ce_skills_ready()) exit("شغّل ترحيل 2026_ce_skills الأول\n");

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
$logSize = function (): int { clearstatcache(); $f = STORAGE_PATH . '/logs/mock-ai.jsonl'; return is_file($f) ? filesize($f) : 0; };
/** نص كل الطلبات اللي وصلت للـ AI الوهمي من لحظة معيّنة (نص + برومبت الصورة) */
function mock_text(int $offset): string
{
    $f = STORAGE_PATH . '/logs/mock-ai.jsonl';
    clearstatcache();
    $h = fopen($f, 'r'); fseek($h, $offset);
    $out = '';
    while (($l = fgets($h)) !== false) {
        $x = json_decode($l, true);
        $b = json_decode((string) ($x['body'] ?? ''), true);
        if (is_array($b)) {
            foreach ((array) ($b['messages'] ?? []) as $m) $out .= (is_string($m['content'] ?? null) ? $m['content'] : json_encode($m['content'] ?? '', JSON_UNESCAPED_UNICODE)) . "\n";
            if (isset($b['prompt'])) $out .= (string) $b['prompt'] . "\n";
        } else {
            $out .= (string) ($x['body'] ?? '') . "\n";
        }
    }
    fclose($h);
    return $out;
}

db_run('DELETE FROM rate_limits');
$keys = ['post_skills_enabled', 'brand_gate_pct'];
$saved = [];
foreach ($keys as $k) $saved[$k] = get_setting($k, null);
db_run('DELETE FROM settings WHERE setting_key = "post_skills_enabled"');
set_setting('brand_gate_pct', '0');

$tag = bin2hex(random_bytes(3));
$word = 'منشورتخصص' . $tag;
$r = ce_skill_save_md("---\nkey: cc7_$tag\nname: تخصص cc7\nmatch: $word\n---\n## المعرفة\n- معرفة-cc7-$tag\n## القواعد\n- قاعدة-cc7-$tag\n## الممنوعات\n- ممنوع-cc7-$tag\n"
    . "## أمثلة Hooks\n- هوك-cc7-$tag\n## أمثلة بوستات\nمثال-cc7-$tag\n## الناقد\n- ناقد-cc7-$tag\n## التصميم\n- design-rule-cc7-$tag\n## ممنوع في الصورة\n- no-cc7-$tag\n", null, 'cc7', 'upload', null);
$sid = (int) $r['skill_id'];
db_run('UPDATE ce_skill_versions SET is_active = (id = ?) WHERE skill_id = ?', [(int) $r['id'], $sid]);
ce_skill_cache_bust();
$uid = (int) db_insert('INSERT INTO users (name, email, password, status, email_verified_at, approval_status, approved_at, created_at) VALUES (?,?,?, "active", NOW(), "approved", NOW(), NOW())',
    ['CC7', "cc7-$tag@test.local", password_hash('Test12345!', PASSWORD_DEFAULT)]);
db_insert('INSERT INTO brand_profiles (user_id, business_name, industry, description, audience, tone, dialect, created_at) VALUES (?,?,?,?,?,?,?, NOW())',
    [$uid, 'عيادة cc7', 'مركز ' . $word, 'وصف', 'عائلات', 'ودود', 'egyptian']);
$pkg = db_one('SELECT * FROM credit_packages WHERE is_active = 1 AND campaigns_month = 0 ORDER BY credits DESC LIMIT 1');
if ($pkg) plan_start($uid, (int) $pkg['id'], (int) $pkg['credits'], 30, 'admin', null, 'cc7');
credits_add($uid, 200, 'cc7', null, 'admin_add', null);
$brand = db_one('SELECT * FROM brand_profiles WHERE user_id = ?', [$uid]);
$opts = ['content_type' => 'introductory', 'platform' => 'both', 'length' => 'medium', 'tone' => 'simple', 'dialect' => 'egyptian', 'template_id' => 0, 'extra_notes' => ''];

/* ─── 1) إنشاء منشور ─── */
echo "إنشاء منشور (build_final_prompt)\n";
$p = build_final_prompt($brand, $opts, '');
set_setting('post_skills_enabled', '0');
$p0 = build_final_prompt($brand, $opts, '');
db_run('DELETE FROM settings WHERE setting_key = "post_skills_enabled"');
t('الـ Skill في أول البرومبت وبعدها البرومبت القديم بالحرف', str_starts_with($p, 'SECTOR SKILL') && str_ends_with($p, $p0) && $p !== $p0);
t('أقسام الكتابة (المعرفة · القواعد · الممنوعات · الـ Hooks · الأمثلة) من غير الناقد والتصميم',
    str_contains($p, "معرفة-cc7-$tag") && str_contains($p, "ممنوع-cc7-$tag") && str_contains($p, "هوك-cc7-$tag") && str_contains($p, "مثال-cc7-$tag")
    && !str_contains($p, "ناقد-cc7-$tag") && !str_contains($p, "design-rule-cc7-$tag"));
t('post_skills_enabled = 0 ← من غير Skill (زي القديم)', !str_contains($p0, 'SECTOR SKILL'));
db_run('INSERT INTO ce_brand_dna (user_id, dna_json, built_by, updated_at) VALUES (?, ?, "rules", NOW()) ON DUPLICATE KEY UPDATE dna_json = VALUES(dna_json)',
    [$uid, json_encode(['brand' => ['skill' => 'none', 'sector' => $word]], JSON_UNESCAPED_UNICODE)]);
$pn = (function () use ($brand, $opts) { return build_final_prompt(array_merge($brand, ['industry' => 'مركز ' . $GLOBALS['word'] . ' ' . mt_rand()]), $opts, ''); })();
t('العميل اختار «none» ← من غير Skill', !str_contains($pn, 'SECTOR SKILL'));
db_run('DELETE FROM ce_brand_dna WHERE user_id = ?', [$uid]);

/* ─── 2) من الواجهة ─── */
$jar = tempnam(sys_get_temp_dir(), 'cc7');
[, $html] = http('GET', "$base/public/login.php", [], $jar);
preg_match('/name="csrf" value="([^"]+)"/', $html, $m);
http('POST', "$base/public/login.php", ['csrf' => $m[1] ?? '', 'email' => "cc7-$tag@test.local", 'password' => 'Test12345!'], $jar);
[, $dash] = http('GET', "$base/public/dashboard.php", [], $jar);
preg_match('/<meta name="csrf-token" content="([^"]+)"/', $dash, $cm);
$csrf = $cm[1] ?? '';
echo "من الواجهة لحد المزوّد\n";
$off = $logSize();
[$c, $b] = http('POST', "$base/public/ajax/generate-content.php", ['csrf' => $csrf] + $opts, $jar);
$j = json_decode($b, true) ?: [];
t('«إنشاء منشور» ← الطلب وصل بالـ Skill', !empty($j['ok']) && str_contains(mock_text($off), "هوك-cc7-$tag"), mb_substr($b, 0, 200));
$cid = (int) ($j['content_id'] ?? (db_one('SELECT id FROM contents WHERE user_id = ? ORDER BY id DESC LIMIT 1', [$uid])['id'] ?? 0));
$off = $logSize();
[$c, $b] = http('POST', "$base/public/ajax/generate-design.php", ['csrf' => $csrf, 'content_id' => $cid, 'include_logo' => '0'], $jar);
$j = json_decode($b, true) ?: [];
t('تصميم المنشور السريع ← «التصميم» و«ممنوع في الصورة» وصلوا', !empty($j['ok']) && str_contains(mock_text($off), "design-rule-cc7-$tag") && str_contains(mock_text($off), "no-cc7-$tag"), mb_substr($b, 0, 200));
$off = $logSize();
[$c, $b] = http('POST', "$base/public/ajax/studio-design.php", ['csrf' => $csrf, 'action' => 'idea', 'content_text' => 'منشور عن الكشف الدوري'], $jar);
$j = json_decode($b, true) ?: [];
t('الاستوديو: فكرة التصميم بأقسام الأفكار', !empty($j['ok']) && str_contains(mock_text($off), "هوك-cc7-$tag") && !str_contains(mock_text($off), "ممنوع-cc7-$tag"), mb_substr($b, 0, 200));
$off = $logSize();
[$c, $b] = http('POST', "$base/public/ajax/studio-design.php", ['csrf' => $csrf, 'mode' => 'free', 'free_prompt' => 'تصميم عن الكشف', 'ratio' => '1:1', 'include_logo' => '0'], $jar);
$j = json_decode($b, true) ?: [];
t('الاستوديو: برومبت الصورة فيه قواعد التصميم والممنوع', !empty($j['ok']) && str_contains(mock_text($off), "design-rule-cc7-$tag") && str_contains(mock_text($off), "no-cc7-$tag"), mb_substr($b, 0, 200));
$off = $logSize();
[$c, $b] = http('POST', "$base/public/api/studio.php", json_encode(['action' => 'brief', 'method' => 'free', 'text' => 'عرض الكشف الدوري للعائلات'], JSON_UNESCAPED_UNICODE), $jar,
    ['Content-Type: application/json', 'X-CSRF-Token: ' . $csrf]);
// (الـ AI الوهمي مابيرجّعش JSON البريف — المهم هنا إن الطلب نفسه وصل بالـ Skill)
t('الاستوديو: البريف بالـ Skill', in_array($c, [200, 502], true) && str_contains(mock_text($off), "معرفة-cc7-$tag"), $c . ' ' . mb_substr($b, 0, 200));
set_setting('post_skills_enabled', '0');
$off = $logSize();
http('POST', "$base/public/ajax/studio-design.php", ['csrf' => $csrf, 'action' => 'idea', 'content_text' => 'منشور تاني'], $jar);
t('مفتاح الطوارئ بيقفلها في الاستوديو كمان', !str_contains(mock_text($off), "cc7-$tag"));
db_run('DELETE FROM settings WHERE setting_key = "post_skills_enabled"');
t('خريطة المراحل: نفس أقسام الكتابة والأفكار في المحرك (اختبار الرجوع بيغطيها)',
    CE_SKILL_STAGE_MAP['quick_post'] === CE_SKILL_STAGE_MAP['ce_writer'] && CE_SKILL_STAGE_MAP['studio_ideas'] === CE_SKILL_STAGE_MAP['ce_ideation']);

/* ─── تنضيف ─── */
$cids = array_column(db_all('SELECT id FROM contents WHERE user_id = ?', [$uid]), 'id');
if ($cids) foreach (['content_versions', 'content_designs'] as $tb) { try { db_run("DELETE FROM $tb WHERE content_id IN (" . implode(',', array_map('intval', $cids)) . ')'); } catch (\Throwable $e) {} }
foreach (['contents', 'studio_designs', 'brand_profiles', 'credit_transactions', 'credit_lots', 'credit_wallets', 'ai_usage_logs', 'usage_events', 'user_plans', 'notifications', 'ai_runs', 'ce_brand_dna'] as $tb) {
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
@unlink($jar);

echo "\n" . ($fail ? "✗ $fail فشل · " : '✓ ') . "$pass نجح\n";
exit($fail ? 1 : 0);

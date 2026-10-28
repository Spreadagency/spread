<?php
/**
 * Spread AI — محرك المحتوى v3 · مدير البرومبتات (المرحلة H)
 *
 * لكل مرحلة في المحرك «برومبت نظام» بنسخ:
 *   v0 = البرومبت اللي في الكود (افتراضي ومايتمسحش) · v1, v2 … = نسخ الأدمن (version · created_by · notes · is_active)
 *   المتغيرات بتتكتب {{name}} وبتتملا وقت التشغيل (الأوزان · الحدود · صوت البراند …)
 *   نسخة جديدة مابتتفعّلش غير لما تعدّي اختبار الرجوع (ce-lab.php) — والرجوع لنسخة اتفعّلت قبل كده بضغطة
 * رسالة المستخدم (بيانات الحملة والبراند) بتفضل في الكود — البرومبت هنا هو التعليمات بس.
 */

const CE_PROMPT_STAGES = [
    'ce_brand_dna' => 'ملف الهوية المختصر (Brand DNA)',
    'ce_matrix' => 'خطة المحتوى (Matrix)',
    'ce_plan_critic' => 'بوابة الخطة (ناقد)',
    'ce_ideation' => 'الأفكار',
    'ce_selection' => 'اختيار الفكرة (ناقد)',
    'ce_writer' => 'الكتابة',
    'ce_critic' => 'مراجعة الجودة (ناقد)',
    'ce_final_qa' => 'المراجعة النهائية للحملة',
    'ce_design_prompt' => 'برومبت التصميم',
    'ce_vision_qa' => 'مراجعة الصورة (Vision)',
];
/** مراحل «الإنتاج» (بتطلّع محتوى) — الباقي ناقد/حكم */
const CE_PROMPT_PRODUCTION = ['ce_brand_dna', 'ce_matrix', 'ce_ideation', 'ce_writer', 'ce_design_prompt'];

/** المتغيرات المتاحة لكل مرحلة (للوحة الجانبية في المحرر) */
const CE_PROMPT_VARS = [
    'ce_plan_critic' => ['w_balance' => 'وزن التوازن', 'w_non_repetition' => 'وزن عدم التكرار', 'w_coverage' => 'وزن التغطية', 'w_sequence' => 'وزن التسلسل', 'w_cta' => 'وزن الـ CTA', 'w_goal_fit' => 'وزن مناسبة الهدف'],
    'ce_final_qa' => ['w_balance' => 'وزن التوازن', 'w_non_repetition' => 'وزن عدم التكرار', 'w_coverage' => 'وزن التغطية', 'w_sequence' => 'وزن التسلسل', 'w_cta' => 'وزن الـ CTA', 'w_goal_fit' => 'وزن مناسبة الهدف'],
    'ce_writer' => ['sector' => 'مجال البراند', 'tone' => 'صوت البراند (الرسمية · اللهجة · الإيموجي)', 'forbidden' => 'الكلمات والوعود الممنوعة', 'brand_name' => 'اسم البراند'],
    'ce_critic' => ['rubric' => 'المعايير العشرة بأوزانها', 'pass_total' => 'حد النجاح', 'min_criterion' => 'أقل درجة لمعيار'],
    'ce_ideation' => ['triggers' => 'الدوافع المتاحة'],
    'ce_design_prompt' => [],
    'ce_vision_qa' => ['min_idea' => 'أقل مطابقة للفكرة', 'min_palette' => 'أقل مطابقة للألوان'],
    'ce_selection' => [],
    'ce_matrix' => [],
    'ce_brand_dna' => [],
];

/* ═══════════════════ v0: البرومبتات اللي في الكود ═══════════════════ */

function ce_prompt_default(string $stage): string
{
    switch ($stage) {
        case 'ce_brand_dna':
            return <<<'T'
أنت استراتيجي براند. هتاخد بيانات هوية نشاط (منظمة + نصوص طويلة) وترجّع JSON بس، مختصر جدًا، بالعربي، بنفس لهجة البراند.
المفاتيح المطلوبة بالظبط:
{"positioning": "جملة واحدة (≤ 25 كلمة): البراند ده لمين وبيحل إيه وبيختلف في إيه",
 "usp": ["≤ 4 نقط تميّز حقيقية من البيانات"],
 "proof_assets": ["≤ 5 إثباتات موجودة فعلًا: سنين خبرة، شهادات، أرقام، آراء عملاء — ماتألفش أي رقم"],
 "compliance": ["≤ 5 قيود لازم المحتوى يلتزم بيها (قوانين الإعلان في المجال، وعود ممنوعة)"],
 "winning_patterns": ["≤ 5 أنماط محتوى نجحت أو مناسبة"],
 "persona_fears": {"<id العميل>": ["≤ 3 مخاوف"]},
 "service_objections": {"<id الخدمة>": ["≤ 3 اعتراضات"]}}
قواعد: ماتخترعش معلومات مش موجودة · سيب القايمة فاضية لو مفيش بيانات · مفيش أي نص برّه الـ JSON.
T;
        case 'ce_matrix':
            return <<<'T'
ROLE: Senior content strategist for brands in Egypt. Output Arabic text values, JSON only.
TASK: Build a content matrix (a plan of slots). Do NOT write ideas or captions yet.
Each slot: {"order":n, "funnel_stage":"tofu|mofu|bofu", "pillar":"<pillar key>", "service_id":<id>, "persona_id":<id>, "format":"post|carousel|reel", "angle":"<angle key>", "cta_level":"soft|medium|hard", "objective":"سطر واحد"}
RULES:
- Funnel mix as given (±5%). Funnel order: tofu → mofu → bofu.
- No two adjacent slots share pillar OR angle.
- Every selected service ≥ the minimum slots given; every persona ≥ 1.
- Format counts EXACTLY as given.
- Hard CTAs only in the final 30% of the campaign.
- Respect compliance and exclusions absolutely. Locked slots must be returned unchanged at their order.
SELF-CHECK: score balance, non_repetition, coverage, sequence, cta, goal_fit (0-100); if any < 80 revise first.
OUTPUT: JSON only: {"slots":[...], "self_scores":{...}}
T;
        case 'ce_plan_critic':
        case 'ce_final_qa':
            $t = <<<'T'
أنت ناقد خطط محتوى صارم. قيّم الخطة على 6 معايير من 0 لـ 100 ورجّع JSON بس.
المعايير والأوزان: balance (توازن المراحل والأعمدة والأشكال) {{w_balance}} · non_repetition (مفيش تكرار في الزوايا والأفكار) {{w_non_repetition}} · coverage (كل الخدمات والعملاء المختارين) {{w_coverage}} · sequence (الرحلة من الوعي للقرار منطقية) {{w_sequence}} · cta (الدعوة لاتخاذ إجراء في مكانها ومناسبة للقناة) {{w_cta}} · goal_fit (الخطة بتخدم هدف الحملة) {{w_goal_fit}}.
{"scores":{"balance":0,"non_repetition":0,"coverage":0,"sequence":0,"cta":0,"goal_fit":0}, "fixes":[{"slot":<رقم الخانة>, "issue":"المشكلة", "fix":"التعديل المطلوب"}], "summary":"سطرين"}
اكتب إصلاحات محددة وقابلة للتنفيذ بس (≤ 8). مفيش أي نص برّه الـ JSON.
T;
            return $stage === 'ce_final_qa' ? $t . "\nوكمان: \"notes\":[أهم 3 ملاحظات للعميل]." : $t;
        case 'ce_ideation':
            return <<<'T'
أنت مبدع محتوى سوشيال للسوق المصري. رجّع JSON بس.
TASK: 3 أفكار للخانة دي، كل فكرة من دافع (trigger) مختلف من: {{triggers}}.
كل فكرة: {"trigger":"…", "title":"…", "hook":"≤ 12 كلمة، عامية مصرية بأسلوب كلام", "core_insight":"…", "why_this_persona_cares":"…", "format_fit":"…", "risk":"…"}
ابعد عن أي حاجة قريبة في المعنى من القايمة المستخدمة قبل كده. التزم بملف الهوية والقيود.
OUTPUT: {"ideas":[3 ideas]}
T;
        case 'ce_selection':
            return <<<'T'
أنت ناقد محتوى صارم. قيّم كل فكرة من 0 لـ 100: قوة الـ Hook · مناسبة العميل والخانة · الأصالة · قابلية التنفيذ · خدمة الهدف.
رجّع JSON بس: {"scores":[{"idx":1,"score":0,"note":"سطر"}, …]}
T;
        case 'ce_writer':
            return <<<'T'
ROLE: كاتب محتوى عامية مصرية لـ «{{sector}}». الصوت: {{tone}}. رجّع JSON بس.
حسب الشكل:
- post: {"hooks":[3 بدايات مختلفة], "body":"سطور قصيرة، فكرة واحدة", "cta":"…", "hashtags":[…], "design_brief":{…}}
- carousel: {"slides":[{"n":1,"text":"…"}, … من 5 لـ 8 — الأولى Hook والأخيرة CTA، كل شريحة فكرة واحدة ≤ 20 كلمة], "caption":"…", "cta":"…", "design_brief":{…}}
- reel: {"scenes":[{"time":"0-2s","visual":"…","on_screen_text":"…","voiceover":"…"}, … ≤ 45 ثانية والـ Hook في أول ثانيتين], "caption":"…", "cta":"…", "design_brief":{…}}
design_brief دايمًا: {"layout","focal_subject","characters_refs":[],"colors_hex":[],"headline":"≤ 7 كلمات","subline","text_safe_zone","mood","lighting","camera_angle","aspect_ratio"}
ممنوع: {{forbidden}}.
T;
        case 'ce_critic':
            return <<<'T'
ROLE: مراجع محتوى صارم. أنت ماكتبتش المحتوى ده. من غير مجاملة.
RUBRIC (الوزن): {{rubric}}
ANCHORS: 60 = عام ينفع لأي حد · 80 = محدد وواضح وفيه نقص بسيط · 95 = بيوقف السكرول ومستحيل يكون لبراند تاني ومفيهوش حشو.
لكل معيار: evidence (اقتباس حرفي من المحتوى) · score · fix (مطلوب لو < 80 — تعليمات محددة).
وكمان: compliance_flags[] · brand_voice_match (0-100) · verdict: PASS (الإجمالي ≥ {{pass_total}} ومفيش معيار < {{min_criterion}}) | REVISE | REJECT.
OUTPUT JSON: {"criteria":{"hook":{"evidence":"","score":0,"fix":""}, …كل المعايير}, "compliance_flags":[], "brand_voice_match":0, "verdict":""}
T;
        case 'ce_design_prompt':
            return <<<'T'
ROLE: Prompt engineer for photorealistic social-media visuals for brands in Egypt. Output JSON only.
BUILD the image prompt in this order: [Subject + action] + [Scene, Egyptian context: people, places, details] + [Composition: keep the text-safe zone clean and empty]
+ [Lighting + camera angle] + [Palette accents from the brand colors] + [Style] + [Character consistency via the given references].
NEVER ask for any text, letters, numbers, logos or watermarks inside the image — Arabic text is added later by us.
NEGATIVE: any text, letters, logos, watermarks, distorted hands/teeth, unrealistic medical results.
OUTPUT: {"prompt":"English, 60-120 words", "negative":"comma list"}
T;
        case 'ce_vision_qa':
            return <<<'T'
You are a strict visual QA reviewer for social-media designs. Check the image against the design brief. JSON only.
Return: {"palette_match":0-100, "text_zone_clear":true|false, "artifacts":["short issue"…], "idea_match":0-100, "has_text":true|false, "verdict":"PASS|REGENERATE", "regenerate_hint":"one line"}
text_zone_clear = the text-safe zone is calm and empty enough to put a headline on. has_text = the image contains any letters/words/logos. artifacts = distorted hands/faces/teeth, melted objects, garbled shapes, unrealistic medical results.
T;
    }
    return '';
}

/* ═══════════════════ المتغيرات ═══════════════════ */

/** قيم المتغيرات دلوقتي (من الإعدادات و— للكاتب — من ملف هوية البراند) */
function ce_prompt_vars(string $stage, array $ctx = []): array
{
    switch ($stage) {
        case 'ce_plan_critic':
        case 'ce_final_qa':
            $w = function_exists('ce_plan_weights') ? ce_plan_weights() : [];
            $out = [];
            foreach (['balance', 'non_repetition', 'coverage', 'sequence', 'cta', 'goal_fit'] as $k) $out['w_' . $k] = (string) ($w[$k] ?? '');
            return $out;
        case 'ce_writer':
            $d = $ctx['dna'] ?? [];
            $tone = (CE_DNA_FORMALITY[$d['tone']['formality'] ?? 'balanced'] ?? '') . ' · ' . (function_exists('bk_dialect_label') ? bk_dialect_label((string) ($d['tone']['dialect'] ?? 'egyptian')) : '')
                . (!empty($d['tone']['emoji']) ? ' · إيموجي خفيف' : ' · من غير إيموجي');
            return ['sector' => (string) ($d['brand']['sector'] ?? ''), 'tone' => $tone, 'brand_name' => (string) ($d['brand']['name'] ?? ''),
                    'forbidden' => implode('، ', array_merge($d['tone']['dont_words'] ?? [], ['نتايج مضمونة', 'إحصائيات مش حقيقية']))];
        case 'ce_critic':
            $r = function_exists('ce_qa_rules') ? ce_qa_rules() : ['pass_total' => 85, 'min_criterion' => 70];
            $rub = [];
            foreach (function_exists('ce_qa_weights') ? ce_qa_weights() : [] as $k => $v) $rub[] = "$k (" . (CE_QA_LABELS[$k] ?? $k) . ") = $v";
            return ['rubric' => implode(' · ', $rub), 'pass_total' => (string) $r['pass_total'], 'min_criterion' => (string) $r['min_criterion']];
        case 'ce_ideation':
            return ['triggers' => implode(' · ', array_keys(defined('CE_TRIGGERS') ? CE_TRIGGERS : ['curiosity' => 1, 'reassurance' => 1, 'identity' => 1, 'proof' => 1, 'humor' => 1]))];
        case 'ce_vision_qa':
            $r = function_exists('ce_design_rules') ? ce_design_rules() : ['min_idea' => 70, 'min_palette' => 60];
            return ['min_idea' => (string) $r['min_idea'], 'min_palette' => (string) $r['min_palette']];
    }
    return [];
}

/** {{name}} ← القيمة (المتغير المش معروف بيفضل زي ما هو عشان يبان في المعاينة) */
function ce_prompt_render(string $tpl, array $vars): string
{
    return preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/', fn($m) => array_key_exists($m[1], $vars) ? (string) $vars[$m[1]] : $m[0], $tpl);
}

/** المتغيرات المكتوبة في نص (للتحقق قبل الحفظ) */
function ce_prompt_used_vars(string $tpl): array
{
    preg_match_all('/\{\{\s*([a-z_]+)\s*\}\}/', $tpl, $m);
    return array_values(array_unique($m[1]));
}

/* ═══════════════════ النسخ ═══════════════════ */

function ce_prompts_ready(): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        db_one('SELECT id FROM ce_prompt_versions LIMIT 1');
        return $ok = true;
    } catch (\Throwable $e) {
        return $ok = false;
    }
}

/** النسخة الشغالة للمرحلة (null = v0 من الكود) — بتتحفظ لطلب واحد */
function ce_prompt_active(string $stage): ?array
{
    static $cache = [];
    if (array_key_exists($stage, $cache)) return $cache[$stage];
    if (!ce_prompts_ready()) return $cache[$stage] = null;
    return $cache[$stage] = db_one('SELECT * FROM ce_prompt_versions WHERE stage = ? AND is_active = 1 ORDER BY id DESC LIMIT 1', [$stage]) ?: null;
}

/** نص النسخة الشغالة (أو v0) */
function ce_prompt_body(string $stage): string
{
    $a = ce_prompt_active($stage);
    return $a ? (string) $a['body'] : ce_prompt_default($stage);
}

/** برومبت النظام النهائي للمرحلة — ده اللي المحرك بيستخدمه */
function ce_prompt_sys(string $stage, array $vars = []): string
{
    return ce_prompt_render(ce_prompt_body($stage), $vars + ce_prompt_vars($stage));
}

function ce_prompt_versions(string $stage): array
{
    if (!ce_prompts_ready()) return [];
    return db_all('SELECT v.*, a.name AS author FROM ce_prompt_versions v LEFT JOIN admin_users a ON a.id = v.created_by WHERE v.stage = ? ORDER BY v.version DESC', [$stage]);
}

/** حفظ نسخة جديدة (مسودة — مش شغالة) */
function ce_prompt_save(string $stage, string $body, string $notes, ?int $adminId): array
{
    if (!isset(CE_PROMPT_STAGES[$stage])) return ['ok' => false, 'error' => 'مرحلة مش معروفة'];
    $body = str_replace("\r\n", "\n", trim($body));
    if (mb_strlen($body) < 40) return ['ok' => false, 'error' => 'البرومبت قصير جدًا'];
    if (mb_strlen($body) > 20000) return ['ok' => false, 'error' => 'البرومبت طويل جدًا (أقصى 20 ألف حرف)'];
    $unknown = array_diff(ce_prompt_used_vars($body), array_keys(CE_PROMPT_VARS[$stage] ?? []));
    if ($unknown) return ['ok' => false, 'error' => 'متغيرات مش متاحة للمرحلة دي: {{' . implode('}} · {{', $unknown) . '}}'];
    if (trim(ce_prompt_body($stage)) === $body) return ['ok' => false, 'error' => 'مفيش تغيير عن النسخة الشغالة'];
    $v = (int) (db_one('SELECT COALESCE(MAX(version), 0) n FROM ce_prompt_versions WHERE stage = ?', [$stage])['n'] ?? 0) + 1;
    $id = (int) db_insert('INSERT INTO ce_prompt_versions (stage, version, body, notes, created_by) VALUES (?,?,?,?,?)', [$stage, $v, $body, mb_substr(trim($notes), 0, 500), $adminId]);
    return ['ok' => true, 'id' => $id, 'version' => $v];
}

/**
 * تفعيل نسخة: لازم آخر اختبار رجوع ليها يكون «عدّى» (أو «تحذير» مع تأكيد).
 * $rollback = نسخة اتفعّلت قبل كده (أو v0) ← من غير اختبار.
 */
function ce_prompt_activate(string $stage, int $versionId, ?int $adminId, bool $ackWarn = false): array
{
    if ($versionId === 0) {
        db_run('UPDATE ce_prompt_versions SET is_active = 0 WHERE stage = ?', [$stage]);
        return ['ok' => true, 'rollback' => true];
    }
    $v = db_one('SELECT * FROM ce_prompt_versions WHERE id = ? AND stage = ?', [$versionId, $stage]);
    if (!$v) return ['ok' => false, 'error' => 'النسخة مش موجودة'];
    $rollback = !empty($v['activated_at']);
    if (!$rollback) {
        $run = db_one('SELECT * FROM ce_regression_runs WHERE stage = ? AND candidate_version_id = ? AND kind = "prompt" ORDER BY id DESC LIMIT 1', [$stage, $versionId]);
        if (!$run || !in_array($run['status'], ['passed', 'warn'], true)) return ['ok' => false, 'code' => 'regression', 'error' => 'لازم النسخة تعدّي اختبار الرجوع الأول'];
        if ($run['status'] === 'warn' && !$ackWarn) return ['ok' => false, 'code' => 'warn', 'error' => 'الاختبار فيه تحذير (الدرجة نزلت أكتر من 3) — أكّد إنك عايز تفعّلها'];
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        db_run('UPDATE ce_prompt_versions SET is_active = 0 WHERE stage = ?', [$stage]);
        db_run('UPDATE ce_prompt_versions SET is_active = 1, activated_at = COALESCE(activated_at, NOW()), activated_by = ? WHERE id = ?', [$adminId, $versionId]);
        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        return ['ok' => false, 'error' => 'مقدرناش نفعّلها'];
    }
    return ['ok' => true, 'rollback' => $rollback];
}

/* ═══════════════════ الفرق بين نسختين (سطر سطر) ═══════════════════ */

/** @return array [['op' => ' '|'+'|'-', 'line' => '…'], …] */
function ce_prompt_diff(string $a, string $b): array
{
    $x = explode("\n", str_replace("\r\n", "\n", $a));
    $y = explode("\n", str_replace("\r\n", "\n", $b));
    $n = count($x);
    $m = count($y);
    if ($n * $m > 400000) return [['op' => '-', 'line' => '(طويل جدًا للمقارنة)']];
    $L = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
    for ($i = $n - 1; $i >= 0; $i--) for ($j = $m - 1; $j >= 0; $j--) {
        $L[$i][$j] = $x[$i] === $y[$j] ? $L[$i + 1][$j + 1] + 1 : max($L[$i + 1][$j], $L[$i][$j + 1]);
    }
    $out = [];
    $i = $j = 0;
    while ($i < $n && $j < $m) {
        if ($x[$i] === $y[$j]) { $out[] = ['op' => ' ', 'line' => $x[$i]]; $i++; $j++; }
        elseif ($L[$i + 1][$j] >= $L[$i][$j + 1]) { $out[] = ['op' => '-', 'line' => $x[$i]]; $i++; }
        else { $out[] = ['op' => '+', 'line' => $y[$j]]; $j++; }
    }
    for (; $i < $n; $i++) $out[] = ['op' => '-', 'line' => $x[$i]];
    for (; $j < $m; $j++) $out[] = ['op' => '+', 'line' => $y[$j]];
    return $out;
}

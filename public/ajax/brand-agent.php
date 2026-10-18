<?php
/**
 * Spread AI v2 — مساعد الهوية الذكي (AJAX)
 * محادثة: الايجنت يسأل سؤال سؤال → يجمع التفاصيل → يحفظ الهوية كاملة
 * actions: load | send | restart
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/credits.php';
require_once __DIR__ . '/../../includes/ai.php';
require_once __DIR__ . '/../../includes/rate-limit.php';
require_once __DIR__ . '/../../includes/brand-brain.php';

require_login();
require_csrf();
session_release();   // العملية طويلة — مانقفلش باقي صفحات العميل لحد ما تخلص
decode_b64_fields();

$user = current_user();
$action = $_POST['action'] ?? 'load';

const AGENT_GREETING = "أهلًا بيك! 👋 أنا مساعد الهوية — هسألك شوية أسئلة بسيطة وأظبطلك هوية براندك كاملة على المنصة.\n\nخلينا نبدأ: **إيه اسم البيزنس بتاعك، وشغال في أنهي مجال؟** (مثلًا: عيادة أسنان، مطعم، براند ملابس...)";

const AGENT_OFF_TOPIC = "أنا مساعد مخصص لهوية البراند بس 🙂 ومش بجاوب على أي حاجة برّه الهوية.\n\nأقدر أساعدك في: بيانات نشاطك، خدماتك، جمهورك، طريقة الكلام، الألوان واللوجو والتصميم، الشخصيات والمواضيع والعروض.\nولكتابة بوستات أو تصميمات استخدم «اعمل محتوى». نكمّل هويتك؟";

/**
 * أول رسالة: ملخص اللي اتعمل في الهوية + يبدأ من أول معلومة ناقصة (بالترتيب الأساسي)
 * — مش بيسأل من الأول عن حاجات العميل كاتبها قبل كده
 */
function agent_greeting(int $userId): string
{
    $brand = brand_for_user($userId);
    if (!$brand || !brand_filled($brand['business_name'] ?? '')) {
        return AGENT_GREETING;
    }
    $ov = brand_overview($brand, $userId);
    $known = [];
    foreach (brand_fields() as $k => [$label, $w]) {
        if (!brand_filled($brand[$k] ?? '') || $k === 'logo_path') continue;
        $v = trim(preg_replace('/\s+/u', ' ', (string) $brand[$k]));
        $v = brand_field_options()[$k][$v] ?? $v;
        $known[] = '• ' . $label . ': ' . (mb_strlen($v) > 70 ? mb_substr($v, 0, 70) . '…' : $v);
        if (count($known) >= 8) break;
    }
    $d = $ov['done'];
    $msg = "أهلًا بيك تاني! 👋 ده ملخص اللي اتعمل في هوية **" . $brand['business_name'] . "** لحد دلوقتي (" . $d['pct'] . "%):\n"
        . implode("\n", $known)
        . ($d['researches'] ? "\n• أبحاث عميقة: " . $d['researches'] : '')
        . ($d['inspirations'] ? "\n• تصميمات بتعجبك: " . $d['inspirations'] : '')
        . ($d['sources'] ? "\n• ملفات ومصادر: " . $d['sources'] : '');
    if (!$ov['missing']) {
        return $msg . "\n\n✅ كل المعلومات الأساسية موجودة. تحب نعدّل حاجة أو نضيف تفاصيل زيادة (قواعد التصميم · كلمات ممنوعة)؟";
    }
    $labels = array_map(fn($m) => $m['label'], array_slice($ov['missing'], 0, 4));
    return $msg . "\n\nفاضل: " . implode('، ', $labels) . (count($ov['missing']) > 4 ? '…' : '')
        . "\n\nخلينا نكمّل من الناقص: **" . $ov['missing'][0]['question'] . "**";
}

function agent_session(int $userId): array
{
    $s = db_one('SELECT * FROM agent_sessions WHERE user_id = ? AND status = "active" ORDER BY id DESC LIMIT 1', [$userId]);
    if (!$s) {
        $messages = [['role' => 'assistant', 'content' => agent_greeting($userId)]];
        $id = db_insert(
            'INSERT INTO agent_sessions (user_id, messages) VALUES (?, ?)',
            [$userId, json_encode($messages, JSON_UNESCAPED_UNICODE)]
        );
        return ['id' => $id, 'messages' => $messages, 'status' => 'active'];
    }
    return ['id' => (int) $s['id'], 'messages' => json_decode($s['messages'] ?: '[]', true) ?: [], 'status' => $s['status']];
}

function agent_save(int $sessionId, array $messages, string $status = 'active'): void
{
    db_run(
        'UPDATE agent_sessions SET messages = ?, status = ? WHERE id = ?',
        [json_encode($messages, JSON_UNESCAPED_UNICODE), $status, $sessionId]
    );
}

// ═══ load: رجّع المحادثة الحالية ═══
if ($action === 'load') {
    $s = agent_session((int) $user['id']);
    // محادثة لسه مابدأتش (التحية بس): نحدّث التحية باللي اتعمل في الهوية دلوقتي
    if (count($s['messages']) === 1 && ($s['messages'][0]['role'] ?? '') === 'assistant') {
        $s['messages'] = [['role' => 'assistant', 'content' => agent_greeting((int) $user['id'])]];
        agent_save($s['id'], $s['messages']);
    }
    json_response(['ok' => true, 'messages' => $s['messages'], 'status' => $s['status']]);
}

// ═══ restart: جلسة جديدة ═══
if ($action === 'restart') {
    db_run('UPDATE agent_sessions SET status = "done" WHERE user_id = ? AND status = "active"', [$user['id']]);
    $s = agent_session((int) $user['id']);
    json_response(['ok' => true, 'messages' => $s['messages'], 'status' => 'active']);
}

// ═══ send: رسالة من العميل ═══
$text = mb_substr(trim($_POST['message'] ?? ''), 0, 1500);
if ($text === '') {
    json_response(['ok' => false, 'error' => 'اكتب رسالتك الأول']);
}
if (!rate_limit('brand_agent', 'u' . $user['id'], 30, 600)) {
    json_response(['ok' => false, 'error' => 'وصلت للحد الأقصى — استنى شوية وكمّل']);
}

$cost = (int) get_setting('brand_agent_cost', 0);
if ($cost > 0 && !credits_consume((int) $user['id'], $cost, 'مساعد الهوية')) {
    json_response(['ok' => false, 'error' => credits_short_msg($cost)]);
}

$s = agent_session((int) $user['id']);
$messages = $s['messages'];
$messages[] = ['role' => 'user', 'content' => $text];

// ── بناء البرومبت: نظام + المحادثة كاملة ──
$transcript = '';
foreach ($messages as $m) {
    $transcript .= ($m['role'] === 'user' ? "العميل: " : "المساعد: ") . $m['content'] . "\n\n";
}

// اللي معروف والناقص (بالترتيب الأساسي) — المساعد مايسألش عن حاجة موجودة
$__brand = brand_for_user((int) $user['id']);
$__known = [];
$__missing = [];
if ($__brand) {
    foreach (brand_fields() as $k => [$label]) {
        if ($k === 'logo_path') continue;
        if (brand_filled($__brand[$k] ?? '')) $__known[] = '- ' . $label . ': ' . mb_substr(trim((string) $__brand[$k]), 0, 200);
    }
    foreach (brand_overview($__brand, (int) $user['id'])['missing'] as $m) $__missing[] = $m['label'];
}

// مركز الهوية: الخدمات · المواضيع · طريقة الكلام · الاتجاهات · العروض — المساعد يقدر يعدّلهم كمان
$__kitOn = brand_kit_on();
$__kitKnown = '';
if ($__kitOn) {
    BrandContext::flush((int) $user['id']);
    $__k = bk_get((int) $user['id']);
    $__svc = array_column(bk_services((int) $user['id'], true), 'name');
    $__tp = array_column(array_slice(bk_topics((int) $user['id']), 0, 15), 'title');
    $__kitKnown = "هوية المحتوى الحالية (مركز الهوية):\n"
        . '- الخدمات: ' . ($__svc ? implode('، ', $__svc) : 'لسه مفيش') . "\n"
        . '- مواضيع: ' . ($__tp ? implode('، ', $__tp) : 'لسه مفيش') . "\n"
        . '- طريقة الكلام: ' . BrandContext::voiceText($__k['data']['voice']) . "\n"
        . '- اتجاهات المحتوى: ' . ($__k['data']['pillars'] ? implode('، ', array_map(fn($p) => $p['name'] . ' ' . $p['percent'] . '%', $__k['data']['pillars'])) : 'لسه مفيش') . "\n"
        . '- عروض شغالة: ' . ($__k['data']['offers'] ? implode('، ', array_column($__k['data']['offers'], 'title')) : 'مفيش') . "\n\n";
}
$__tones = $__kitOn ? implode('، ', array_column(db_all('SELECT name FROM style_presets WHERE kind = "tone" AND is_active = 1'), 'name')) : '';

$system = "أنت «مساعد الهوية» — خبير استراتيجية براندات مصري ودود على منصة محتوى بالذكاء الاصطناعي. مهمتك تبني هوية العميل بالكامل عبر المحادثة.\n\n"
    . ($__known ? "المعلومات الموجودة فعلًا في هوية العميل (ماتسألش عنها تاني إلا لو طلب يعدّلها):\n" . implode("\n", $__known) . "\n\n" : '')
    . ($__missing ? "المعلومات الناقصة — ابدأ منها بالترتيب ده: " . implode('، ', $__missing) . "\n\n" : '')
    . $__kitKnown
    . ($__kitOn ? "تقدر كمان تعدّل هوية المحتوى فورًا لما العميل يطلب (يضيف خدمة أو موضوع أو عرض، يغيّر الاتجاهات أو النبرة، كلمات أو مواضيع ممنوعة، يركّز على خدمة الشهر ده):\n"
        . "اكتب رد قصير يأكد اللي هتعمله + [KIT] ثم JSON فيه المفاتيح اللي محتاجها بس: "
        . '{"add_services":[{"name":"","description":"","priority":"high|medium|low"}],"add_topics":[""],"add_offers":[{"title":"","until":"YYYY-MM-DD"}],'
        . '"pillars":[{"name":"","percent":30}],"focus_service":"","voice_preset":"","forbidden_words":[""],"forbidden_topics":[""]}'
        . " ثم [/KIT]. voice_preset واحدة من: {$__tones}. ماتحطش [KIT] غير لما العميل يطلب تغيير صريح.\n\n" : '')
    . "⛔ إنت مخصص لهوية البراند بس. لو آخر رسالة من العميل مش عن هوية البراند (نشاطه، خدماته، جمهوره، طريقة الكلام، الألوان، اللوجو، التصميم، الشخصيات، المواضيع، العروض، بيانات التواصل) — "
    . "زي أسئلة عامة أو معلومات أو أخبار أو برمجة أو ترجمة أو واجبات أو وصفات أو نصايح شخصية أو كتابة بوست/مقال/إعلان كامل أو أي حاجة تانية — ماتجاوبش خالص، واكتب بالظبط: [OFF_TOPIC] ومن غير أي كلام تاني.\n"
    . "التحيات والشكر والرد على أسئلتك عادي ومش خارج الموضوع.\n\n"
    . "القواعد:\n"
    . "1. اسأل سؤال واحد بس في كل رد — قصير وواضح بالعامية المصرية، وممكن تدي مثال يساعده.\n"
    . "2. لازم تجمع بالترتيب: اسم البيزنس والمجال، وصف النشاط وأهم الخدمات/المنتجات، الجمهور المستهدف، نبرة الكلام (رسمي/ودود/شبابي...)، الألوان المفضلة، اللهجة (مصري/فصحى/خليجي)، كلمات أو عبارات يحب تظهر، كلمات ممنوعة، شروط التصميم (مكان اللوجو، ألوان ممنوعة، الستايل).\n"
    . "3. لو العميل جاوب على أكتر من حاجة في رد واحد، متسألش عنها تاني.\n"
    . "4. لما تكون جمعت كل التفاصيل المهمة (على الأقل: الاسم، المجال، الوصف، الجمهور، النبرة، الألوان)، اختم بالظبط بالصيغة دي:\n"
    . "رسالة قصيرة تلخص اللي فهمته + السطر [PROFILE] ثم JSON بالمفاتيح: business_name, industry, description, audience, tone, colors, dialect, keywords_use, keywords_avoid, design_rules, summary (ملخص شامل 100-150 كلمة للهوية) ثم [/PROFILE]\n"
    . "5. متكتبش [PROFILE] غير لما تكون فعلًا خلصت كل الأسئلة الأساسية.\n\n"
    . "المحادثة حتى الآن:\n" . $transcript
    . "المساعد:";

$ai = ai_generate($system);
if (!$ai['ok']) {
    if ($cost > 0) {
        credits_add((int) $user['id'], $cost, 'استرداد: مساعد الهوية', null, 'refund');
    }
    json_response(['ok' => false, 'error' => $ai['error']]);
}

$reply = trim($ai['response']);
$profileSaved = false;

// ── برّه الهوية: رد ثابت ومن غير أي إجابة (والكريدت بيرجع) ──
if (str_contains($reply, '[OFF_TOPIC]')) {
    if ($cost > 0) credits_add((int) $user['id'], $cost, 'استرداد: مساعد الهوية (سؤال برّه الهوية)', null, 'refund');
    $reply = AGENT_OFF_TOPIC;
    $messages[] = ['role' => 'assistant', 'content' => $reply];
    agent_save($s['id'], $messages);
    json_response(['ok' => true, 'reply' => $reply, 'profile_saved' => false, 'off_topic' => true]);
}

// ── هل الايجنت خلّص وطلع البروفايل؟ ──
if (preg_match('/\[PROFILE\](.*?)\[\/PROFILE\]/s', $reply, $m)) {
    $jsonRaw = trim($m[1]);
    $jsonRaw = preg_replace('/^```(json)?|```$/m', '', $jsonRaw);
    $profile = json_decode(trim($jsonRaw), true);

    if (is_array($profile)) {
        $brand = user_brand();
        $fields = ['business_name', 'industry', 'description', 'audience', 'tone', 'colors', 'dialect', 'keywords_use', 'keywords_avoid', 'design_rules'];
        $sets = [];
        $vals = [];
        foreach ($fields as $f) {
            if (!empty($profile[$f]) && is_string($profile[$f])) {
                $sets[] = "`{$f}` = ?";
                $vals[] = mb_substr($profile[$f], 0, 2000);
            }
        }
        if (!empty($profile['summary']) && is_string($profile['summary'])) {
            $sets[] = '`ai_summary` = ?';
            $vals[] = mb_substr($profile['summary'], 0, 4000);
        }
        if ($sets) {
            if ($brand) {
                $vals[] = $brand['id'];
                db_run('UPDATE brand_profiles SET ' . implode(', ', $sets) . ' WHERE id = ?', $vals);
            } else {
                db_run('INSERT INTO brand_profiles (user_id, business_name) VALUES (?, ?)', [$user['id'], mb_substr($profile['business_name'] ?? 'براندي', 0, 180)]);
                $brand = user_brand();
                if ($brand) {
                    $vals[] = $brand['id'];
                    db_run('UPDATE brand_profiles SET ' . implode(', ', $sets) . ' WHERE id = ?', $vals);
                }
            }
            $profileSaved = true;
            $__bkF = [];
            foreach ($sets as $__s) if (preg_match('/`(\w+)`/', $__s, $__m)) $__bkF[] = $__m[1];
            brand_kit_notify((int) $user['id'], $__bkF, 'ai', 'من المساعد الذكي');
        }
    }

    // شيل البلوك من الرد الظاهر للعميل
    $reply = trim(preg_replace('/\[PROFILE\].*?\[\/PROFILE\]/s', '', $reply));
    if ($reply === '') {
        $reply = 'تمام كده! 🎉';
    }
    if ($profileSaved) {
        $reply .= "\n\n✅ **حفظتلك الهوية كاملة!** راجعها وعدّل أي حاجة من صفحة «هوية البراند» — ودلوقتي أي محتوى أو تصميم هيتبني عليها. يلا نبدأ أول خطة؟ 🗓";
    }
}

// ── تعديلات مركز الهوية من المحادثة ──
$kitSaved = [];
if ($__kitOn && preg_match('/\[KIT\](.*?)\[\/KIT\]/su', $reply, $km)) {
    $kj = bk_json_from_ai($km[1]);
    if ($kj) $kitSaved = bk_agent_apply((int) $user['id'], $kj);
    $reply = trim(preg_replace('/\[KIT\].*?\[\/KIT\]/su', '', $reply)) ?: 'تمام ✓';
    if ($kitSaved) $reply .= "\n\n✅ اتحدّث في هوية البراند: " . implode('، ', $kitSaved);
}

$messages[] = ['role' => 'assistant', 'content' => $reply];
agent_save($s['id'], $messages, $profileSaved ? 'done' : 'active');
ai_log_usage((int) $user['id'], 'brand_agent', $ai['model'] ?? 'router', $ai['usage'] ?? []);

json_response([
    'ok' => true,
    'reply' => $reply,
    'profile_saved' => $profileSaved,
]);

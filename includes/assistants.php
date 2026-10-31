<?php
/**
 * Spread AI — المساعدين (المرحلة 7)
 *
 * «🛟 الدعم»: بيعرف حساب العميل (الباقة · الرصيد · الحصص · طلبات الدفع · النشر اللي فشل · الفيديو · الشغل اللي وقف)
 *   ودليل المنصة (من الأدمن) ← يشرح ويحل ويوديه للصفحة الصح بزراير، ولو مقدرش أو العميل طلب حد من الفريق ← تذكرة
 *   في «البلاغات» (user_issues) والأدمن بيتنبّه. مابيلمسش الدفع ولا الحساب ولا المسح.
 *
 * «🤖 الوكيل»: بيدردش مع العميل لحد ما يفهم المطلوب ← بريف حملة للمحرك + تقدير التكلفة ← العميل يوافق
 *   ← بينفّذ الحملة كاملة لوحده بنفس خطوات المحرك: ملف الهوية (لو مش موجود) ← الخطة ← اعتمادها ← الكتابة
 *   ← التصميم ← (اختياري) الجدولة على مواعيد الخطة. كل مرحلة بتحجز وتخصم كريدتها العادي من المحرك نفسه،
 *   والوكيل بياخد «رسوم تنفيذ» ثابتة فوقها. لو وقف (رصيد · حصة · الخطة محتاجة مراجعة) بيقف ويقول للعميل يعمل إيه،
 *   ولما العميل يحل المشكلة بيكمّل لوحده. الـ cron (ce-worker) بيحرّكه كل دقيقة، والشات المفتوح كمان.
 */

const AS_KINDS = ['support', 'agent'];
const AS_HISTORY = 12;          // آخر كام رسالة بتروح للموديل
const AS_MAX_MESSAGES = 80;     // المحادثة الشغالة بتتقص بعد كده
const AS_STAGES = ['dna' => 'ملف الهوية', 'plan' => 'الخطة', 'write' => 'الكتابة', 'design' => 'التصميم', 'publish' => 'الجدولة', 'done' => 'خلصت'];

function as_ready(): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        db_one('SELECT id FROM assistant_threads LIMIT 1');
        db_one('SELECT id FROM agent_runs LIMIT 1');
        return $ok = true;
    } catch (\Throwable $e) {
        return $ok = false;
    }
}

/**
 * المساعد متاح للعميل؟ ← null = أيوه · أو سبب (للواجهة)
 * الدعم: لكل العملاء إلا لو باقته مقفول فيها · الوكيل: الباقات اللي فيها الوكيل بس + المحرك متفعّل للعميل
 */
function as_blocked(int $uid, string $kind): ?string
{
    if (!as_ready()) return 'المساعد لسه مش متركّب';
    if (get_setting('assistant_' . $kind . '_enabled', '1') !== '1') return $kind === 'agent' ? 'الوكيل مقفول دلوقتي' : 'الدعم مقفول دلوقتي';
    $p = function_exists('plan_active') ? plan_active($uid) : null;
    $paid = $p && empty($p['synthetic']);
    if ($kind === 'support') return $paid && isset($p['assistant_support']) && !(int) $p['assistant_support'] ? 'الدعم بالمساعد مش في باقتك' : null;
    if (!$paid || !(int) ($p['assistant_agent'] ?? 0)) return 'الوكيل مش في باقتك — رقّي باقتك علشان يشتغل مكانك';
    if (!function_exists('ce_enabled_for')) require_once __DIR__ . '/ce-jobs.php';
    if (!ce_enabled_for($uid)) return 'الوكيل بيشتغل بمحرك المحتوى — والمحرك لسه مش متاح لحسابك';
    return null;
}

/* ═══════════════════ المحادثات ═══════════════════ */

function as_thread(int $uid, string $kind): array
{
    $t = db_one('SELECT * FROM assistant_threads WHERE user_id = ? AND kind = ? AND status = "active" ORDER BY id DESC LIMIT 1', [$uid, $kind]);
    if ($t) return ['id' => (int) $t['id'], 'messages' => json_decode((string) $t['messages_json'], true) ?: []];
    $msgs = [['role' => 'assistant', 'content' => as_greeting($uid, $kind), 'at' => date('Y-m-d H:i')]];
    $id = (int) db_insert('INSERT INTO assistant_threads (user_id, kind, messages_json) VALUES (?,?,?)', [$uid, $kind, json_encode($msgs, JSON_UNESCAPED_UNICODE)]);
    return ['id' => $id, 'messages' => $msgs];
}

function as_thread_save(int $threadId, array $messages): void
{
    if (count($messages) > AS_MAX_MESSAGES) $messages = array_slice($messages, -AS_MAX_MESSAGES);
    db_run('UPDATE assistant_threads SET messages_json = ? WHERE id = ?', [json_encode($messages, JSON_UNESCAPED_UNICODE), $threadId]);
}

function as_thread_push(int $uid, string $kind, array $msg): void
{
    $t = as_thread($uid, $kind);
    $t['messages'][] = $msg + ['at' => date('Y-m-d H:i')];
    as_thread_save($t['id'], $t['messages']);
}

function as_reset(int $uid, string $kind): array
{
    db_run('UPDATE assistant_threads SET status = "closed" WHERE user_id = ? AND kind = ? AND status = "active"', [$uid, $kind]);
    return as_thread($uid, $kind);
}

function as_greeting(int $uid, string $kind): string
{
    $u = db_one('SELECT name FROM users WHERE id = ?', [$uid]);
    $name = trim(explode(' ', trim((string) ($u['name'] ?? '')))[0] ?? '');
    $hi = 'أهلًا' . ($name !== '' ? ' يا ' . $name : '') . ' 👋 ';
    return $kind === 'agent'
        ? $hi . "أنا **الوكيل** — قولّي محتاج إيه وأنا أعمله مكانك من الأول للآخر: الخطة والكتابة والتصميم وكمان الجدولة.\nمثلًا: «اعمل حملة لعرض العيد 8 بوستات» أو «محتوى توعوي للشهر الجاي عن خدمة التبييض».\nهقولّك التكلفة قبل ما أبدأ، ومش هعمل حاجة غير لما توافق."
        : $hi . "أنا **مساعد الدعم** — اسألني عن أي حاجة في المنصة أو قولّي المشكلة اللي قابلتك (الرصيد · الدفع · النشر · التصميم · الفيديو …) وأنا أشوف حسابك وأحلّها معاك.";
}

/** الصفحات اللي المساعد يقدر يوديك ليها (زراير) — مفيش لينكات من برّه القايمة دي */
function as_links(): array
{
    return [
        'plan' => ['my-plan.php', '💎 باقتي'], 'plans' => ['my-plan.php?tab=plans', '⬆️ الباقات والترقية'], 'balance' => ['my-plan.php?tab=balance', '◔ الرصيد والاستهلاك'],
        'refer' => ['my-plan.php?tab=refer', '🎁 ادع واكسب'], 'payments' => ['payments.php', '💳 طلبات الدفع'],
        'library' => ['content-history.php', '🗂 المحتوى'], 'engine' => ['engine.php', '✨ محرك المحتوى'], 'create' => ['create-content.php', '✎ إنشاء منشور'],
        'calendar' => ['content-plan.php', '🗓 خطة المحتوى'], 'studio' => ['design-studio.php', '🎨 استوديو التصميم'],
        'brand' => ['brand-brain.php', '🧬 هوية البراند'], 'research' => ['research.php', '🔬 البحث'], 'social' => ['social-accounts.php', '🔗 ربط الصفحات'],
        'profile' => ['profile.php', '👤 الملف الشخصي'], 'help' => ['help.php', '❓ دليل المنصة'], 'notifications' => ['notifications.php', '🔔 الإشعارات'],
    ];
}

/** دليل المنصة (الأدمن يقدر يكتب غيره في «المساعدين») */
function as_kb(): string
{
    $kb = trim((string) get_setting('assistant_support_kb', ''));
    if ($kb !== '') return $kb;
    return "- الكريدت: بيتصرف على الكتابة (الأفكار · المنشور · الكاروسيل · الستوري · سكريبت الفيديو · التقييم · التعديل) والنشر والبحث. سعر كل خدمة في «باقتي ← الرصيد». الكريدت اللي ماتصرفش بيترحّل لو جدّدت في خلال 3 أيام من نهاية الباقة.\n"
        . "- التصميمات: عدد في الباقة (مش كريدت). لو خلصت: «باقتي ← زوّد باقتك» تشتري تصميمات زيادة. الباقات السنوية و3 شهور ليها حد أسبوعي تراكمي.\n"
        . "- الاشتراك والدفع: «باقتي» ← اختار الباقة والمدة ← حوّل وارفع صورة الإيصال ← الفريق بيراجع ويفعّل (إشعار بيوصلك). طلبك متابَع من «طلبات الدفع». لو اترفض هتلاقي السبب وتقدر تبعت طلب جديد.\n"
        . "- محرك المحتوى: هوية البراند (Brand DNA) ← بريف الحملة ← الخطة (بتتقيّم ولازم تعدي الحد) ← الكتابة ← التصميم ← النشر أو الجدولة على فيسبوك وإنستجرام. قطع المحرك بتظهر في «المحتوى» والتقويم.\n"
        . "- النشر: لازم تربط صفحتك من «ربط الصفحات». مينفعش تنشر بوست من غير صورة/تصميم. لو الصفحة «منتهية» اربطها تاني. النشر بيتخصم كريدت.\n"
        . "- الفيديو: الـ AI بيكتب السكريبت ← تعتمده ← تختار النوع والمدة وتدفع (بالفلوس مش بالكريدت، وفيه خصم حسب باقتك) ← فريقنا بينفّذ ← بيوصلك في المكتبة.\n"
        . "- الجودة: كل باقة ليها مستوى (عادي · احترافي · بريميوم) بيحدد الموديلات والسرعة.\n"
        . "- البحث العميق: عدد حسب الباقة، ولو مش في باقتك مش هيظهر.\n"
        . "- ادع واكسب: لينكك في «باقتي ← ادع واكسب»، وتختار مكافأتك (كريدت · تصميمات · خصم).\n"
        . "- مشكلة في تصميم أو محتوى معيّن: زرار «بلّغ عن مشكلة» في الصفحة نفسها، أو قولّي وأنا أبلّغ الفريق.";
}

/* ═══════════════════ سياق العميل (للدعم) ═══════════════════ */

/** ملخص حساب العميل — سطور قصيرة للموديل (من غير أي بيانات حساسة) */
function as_context(int $uid): string
{
    $L = [];
    $try = function (callable $f) { try { $f(); } catch (\Throwable $e) {} };
    $try(function () use ($uid, &$L) {
        $pu = function_exists('plan_usage') ? plan_usage($uid, true) : null;
        if (!$pu) return;
        $p = $pu['plan'];
        $L[] = empty($p['synthetic']) ? 'الباقة: ' . $p['name'] . ' — شغالة لحد ' . $pu['ends_label'] . ' (باقي ' . (int) $pu['days_left'] . ' يوم)' : 'مفيش باقة مدفوعة شغالة';
        $L[] = 'الكريدت: ' . (int) $pu['credits']['balance'] . ' متاح (اتصرف ' . (int) $pu['credits']['used'] . ' من ' . (int) $pu['credits']['total'] . ')';
        foreach ($pu['units'] as $u) $L[] = $u['label'] . ': ' . ($u['open'] ? 'مفتوح' : (int) $u['left'] . ' باقي من ' . (int) $u['limit']);
        if (function_exists('pkg_weekly_credits') && ($w = pkg_weekly_credits($uid))) $L[] = 'الحد الأسبوعي للكريدت: باقي ' . (int) $w['left'] . ' الأسبوع ده';
        if (function_exists('pkg_weekly_designs') && ($w = pkg_weekly_designs($uid))) $L[] = 'الحد الأسبوعي للتصميمات: باقي ' . (int) $w['left'] . ' الأسبوع ده';
        if (function_exists('pkg_research_allowed') && !pkg_research_allowed($uid)) $L[] = 'البحث العميق: مش في الباقة';
        if (function_exists('pkg_quality_tier') && function_exists('pkg_tiers')) $L[] = 'مستوى الجودة: ' . (pkg_tiers()[pkg_quality_tier($uid)] ?? 'عادي');
    });
    $try(function () use ($uid, &$L) {
        foreach (db_all('SELECT id, plan_name, status, final_amount, reject_reason, created_at FROM payment_requests WHERE user_id = ? ORDER BY id DESC LIMIT 3', [$uid]) as $r) {
            $L[] = 'طلب دفع #' . $r['id'] . ' «' . $r['plan_name'] . '» ' . number_format((float) $r['final_amount'], 0) . ' جنيه — ' . (function_exists('billing_status_label') ? billing_status_label($r['status']) : $r['status'])
                . ($r['status'] === 'rejected' && $r['reject_reason'] ? ' (السبب: ' . mb_substr((string) $r['reject_reason'], 0, 120) . ')' : '') . ' — ' . substr((string) $r['created_at'], 0, 10);
        }
    });
    $try(function () use ($uid, &$L) {
        foreach (db_all('SELECT page_name, platform, status, last_error FROM social_connections WHERE user_id = ? LIMIT 4', [$uid]) as $c) {
            $L[] = 'صفحة مربوطة: ' . $c['page_name'] . ' (' . $c['platform'] . ') — ' . ($c['status'] === 'active' ? 'شغالة' : 'منتهية/فيها مشكلة' . ($c['last_error'] ? ': ' . mb_substr((string) $c['last_error'], 0, 100) : ''));
        }
    });
    $try(function () use ($uid, &$L) {
        foreach (db_all('SELECT q.platform, q.error, q.updated_at FROM ce_publish_queue q WHERE q.user_id = ? AND q.status = "failed" AND q.updated_at > DATE_SUB(NOW(), INTERVAL 7 DAY) ORDER BY q.id DESC LIMIT 3', [$uid]) as $q) {
            $L[] = 'نشر فشل (' . $q['platform'] . '): ' . mb_substr((string) $q['error'], 0, 160);
        }
        foreach (db_all('SELECT id, publish_error FROM contents WHERE user_id = ? AND publish_error IS NOT NULL AND publish_error <> "" ORDER BY id DESC LIMIT 2', [$uid]) as $c) {
            $L[] = 'منشور #' . $c['id'] . ' فشل نشره: ' . mb_substr((string) $c['publish_error'], 0, 160);
        }
    });
    $try(function () use ($uid, &$L) {
        foreach (db_all('SELECT kind, error, finished_at FROM ce_jobs WHERE user_id = ? AND status = "failed" AND finished_at > DATE_SUB(NOW(), INTERVAL 3 DAY) ORDER BY id DESC LIMIT 2', [$uid]) as $j) {
            $L[] = 'شغلانة في المحرك فشلت (' . (ce_job_handlers()[$j['kind']]['label'] ?? $j['kind']) . '): ' . mb_substr((string) $j['error'], 0, 160) . ' — الكريدت المحجوز رجع';
        }
        foreach (db_all('SELECT kind, stage_label, created_at FROM ce_jobs WHERE user_id = ? AND status IN ("queued","running") ORDER BY id DESC LIMIT 2', [$uid]) as $j) {
            $L[] = 'شغال دلوقتي في المحرك: ' . (ce_job_handlers()[$j['kind']]['label'] ?? $j['kind']) . ($j['stage_label'] ? ' — ' . $j['stage_label'] : '');
        }
    });
    $try(function () use ($uid, &$L) {
        foreach (db_all('SELECT id, video_status FROM contents WHERE user_id = ? AND format = "video" AND video_status IN ("approved","sent","in_production","ready") ORDER BY id DESC LIMIT 3', [$uid]) as $v) {
            $L[] = 'فيديو #' . $v['id'] . ': ' . (function_exists('video_status_meta') ? video_status_meta($v['video_status'])['label'] : $v['video_status']);
        }
    });
    $try(function () use ($uid, &$L) {
        if (!function_exists('brand_for_user')) require_once __DIR__ . '/brand-brain.php';
        $b = brand_for_user($uid);
        $L[] = $b ? 'هوية البراند: ' . $b['business_name'] . ' — مكتملة ' . (int) (brand_overview($b, $uid)['done']['pct'] ?? 0) . '%' : 'هوية البراند: لسه ماتعملتش';
    });
    $try(function () use ($uid, &$L) {
        $n = (int) (db_one('SELECT COUNT(*) n FROM user_issues WHERE user_id = ? AND status IN ("new","analyzing","analyzed","in_progress")', [$uid])['n'] ?? 0);
        if ($n) $L[] = 'بلاغات مفتوحة عند الفريق: ' . $n;
    });
    return implode("\n", array_map(fn($l) => '- ' . $l, $L));
}

/* ═══════════════════ الدعم ═══════════════════ */

function as_support_system(int $uid): string
{
    $links = implode(' · ', array_map(fn($k, $v) => $k . ' = ' . $v[1], array_keys(as_links()), as_links()));
    return "ROLE: SPREAD_SUPPORT\n"
        . "إنت «مساعد الدعم» في منصة Spread AI (منصة محتوى سوشيال ميديا بالذكاء الاصطناعي لأصحاب البيزنس في مصر). بتتكلم بالمصري، ردود قصيرة وواضحة وودودة.\n"
        . "مهمتك: تفهم مشكلة العميل أو سؤاله، تبص على بيانات حسابه تحت، وتشرح السبب والحل خطوة بخطوة.\n\n"
        . "دليل المنصة:\n" . as_kb() . "\n\n"
        . "حساب العميل دلوقتي:\n" . (as_context($uid) ?: '- مفيش بيانات') . "\n\n"
        . "القواعد:\n"
        . "1. اعتمد على بيانات الحساب اللي فوق ودليل المنصة بس — ماتخترعش أرقام أو مزايا أو أسعار مش مكتوبة.\n"
        . "2. إنت مش بتعمل أي حاجة في الحساب: مابتضيفش كريدت، مابتعتمدش دفع، مابتغيّرش باقة أو باسورد، ومابتمسحش حاجة. لو العميل طلب كده وضّح إن الفريق هو اللي بيعملها وافتح تذكرة.\n"
        . "3. عشان توديه لصفحة اكتب في آخر الرد [LINK:key] (لحد 3) من دول بس: {$links}\n"
        . "4. لو مقدرتش تحل المشكلة، أو فيها خطأ تقني محتاج الفريق، أو العميل طلب يكلم حد: اكتب في الآخر [TICKET]ملخص المشكلة في سطرين للفريق[/TICKET] وقول للعميل إنك بلّغت الفريق وهيردوا عليه.\n"
        . "5. لو طلب يعمل شغل (حملة · بوستات · تصميمات) قوله إن «🤖 الوكيل» بيعمل ده مكانه (لو في باقته) أو يبدأ من «محرك المحتوى».\n"
        . "6. لو السؤال برّه المنصة خالص (أخبار · برمجة · واجبات …) اعتذر بلطف وقوله إنك مخصص لدعم المنصة.";
}

/**
 * رسالة للدعم ← الرد + زراير الصفحات + تذكرة (لو اتفتحت)
 * @return array{ok:bool, reply?:string, links?:array, ticket?:int, error?:string, code?:string}
 */
function as_support_send(int $uid, string $text, string $pageUrl = ''): array
{
    if ($why = as_blocked($uid, 'support')) return ['ok' => false, 'error' => $why, 'code' => 'blocked'];
    $text = mb_substr(trim($text), 0, 1500);
    if ($text === '') return ['ok' => false, 'error' => 'اكتب رسالتك الأول', 'code' => 'input'];
    if (function_exists('rate_limit') && !rate_limit('assistant_support', 'u' . $uid, 30, 600)) return ['ok' => false, 'error' => 'رسايل كتير — استنى شوية', 'code' => 'rate'];
    $cost = max(0, (int) get_setting('assistant_support_cost', '0'));
    if ($cost > 0 && !credits_consume($uid, $cost, 'مساعد الدعم')) return ['ok' => false, 'error' => credits_short_msg($cost, 'رسالة الدعم'), 'code' => 'credits'];

    $t = as_thread($uid, 'support');
    $t['messages'][] = ['role' => 'user', 'content' => $text, 'at' => date('Y-m-d H:i')];
    $r = as_ai($uid, 'assistant_support', as_support_system($uid), $t['messages'], false, 700);
    if (!$r['ok']) {
        if ($cost > 0) credits_add($uid, $cost, 'استرداد: مساعد الدعم', null, 'refund');
        return ['ok' => false, 'error' => 'المساعد مش متاح دلوقتي — جرّب كمان شوية', 'code' => 'ai'];
    }
    [$reply, $links] = as_parse_links((string) $r['text']);
    $ticket = null;
    if (preg_match('/\[TICKET\](.*?)(\[\/TICKET\]|$)/su', $reply, $m)) {
        $ticket = as_ticket($uid, trim($m[1]) ?: $text, $t['messages'], $pageUrl);
        $reply = trim(preg_replace('/\[TICKET\].*?(\[\/TICKET\]|$)/su', '', $reply));
        $reply = ($reply !== '' ? $reply . "\n\n" : '') . ($ticket ? '📨 بلّغت فريق Spread AI (بلاغ #' . $ticket . ') — هيردوا عليك في الإشعارات.' : '');
    }
    $reply = $reply !== '' ? $reply : 'تمام ✓';
    $t['messages'][] = ['role' => 'assistant', 'content' => $reply, 'links' => $links, 'ticket' => $ticket, 'at' => date('Y-m-d H:i')];
    as_thread_save($t['id'], $t['messages']);
    return ['ok' => true, 'reply' => $reply, 'links' => $links, 'ticket' => $ticket];
}

/** [LINK:key] ← زراير من القايمة المسموحة بس */
function as_parse_links(string $text): array
{
    $links = [];
    $all = as_links();
    if (preg_match_all('/\[LINK:([a-z_]+)\]/i', $text, $mm)) {
        foreach ($mm[1] as $k) {
            $k = strtolower($k);
            if (isset($all[$k]) && !isset($links[$k]) && count($links) < 3) $links[$k] = ['url' => url($all[$k][0]), 'label' => $all[$k][1]];
        }
    }
    return [trim(preg_replace('/\[LINK:[^\]]*\]/i', '', $text)), array_values($links)];
}

/** تذكرة للفريق في «البلاغات» + تنبيه الأدمن (واحدة كل 10 دقايق للعميل) */
function as_ticket(int $uid, string $summary, array $messages, string $pageUrl = ''): ?int
{
    $recent = db_one('SELECT id FROM user_issues WHERE user_id = ? AND category_user = "support_chat" AND created_at > DATE_SUB(NOW(), INTERVAL 10 MINUTE) ORDER BY id DESC LIMIT 1', [$uid]);
    if ($recent) return (int) $recent['id'];
    $chat = '';
    foreach (array_slice($messages, -8) as $m) $chat .= ($m['role'] === 'user' ? 'العميل: ' : 'المساعد: ') . mb_substr((string) $m['content'], 0, 400) . "\n";
    try {
        $id = (int) db_insert('INSERT INTO user_issues (user_id, page_url, category_user, description, context) VALUES (?,?,?,?,?)', [
            $uid, mb_substr($pageUrl, 0, 255) ?: null, 'support_chat', mb_substr('🛟 من مساعد الدعم: ' . $summary, 0, 2000),
            json_encode(['source' => 'assistant_support', 'chat' => $chat, 'account' => as_context($uid)], JSON_UNESCAPED_UNICODE),
        ]);
    } catch (\Throwable $e) {
        error_log('[assistant] ticket ' . $e->getMessage());
        return null;
    }
    if (function_exists('admin_alert')) {
        try { admin_alert('warn', 'support_ticket', 'تذكرة دعم #' . $id, mb_substr($summary, 0, 300), 'admin/ai-issues.php', 'st:' . $id); } catch (\Throwable $e) {}
    }
    return $id;
}

/* ═══════════════════ الموديل ═══════════════════ */

/** نداء الموديل بالمحادثة (آخر AS_HISTORY رسالة) */
function as_ai(int $uid, string $task, string $system, array $messages, bool $json, int $maxTokens): array
{
    require_once __DIR__ . '/ai-gateway.php';
    $msgs = [['role' => 'system', 'content' => $system]];
    foreach (array_slice($messages, -AS_HISTORY) as $m) {
        $c = (string) ($m['content'] ?? '');
        if ($c === '' || !in_array($m['role'] ?? '', ['user', 'assistant'], true)) continue;
        $msgs[] = ['role' => $m['role'], 'content' => mb_substr($c, 0, 2500)];
    }
    if (!function_exists('ai_gw_enabled') || !ai_gw_enabled()) return ['ok' => false, 'text' => '', 'error' => 'gateway_off'];
    $r = ai_run($task, ['messages' => $msgs, 'max_tokens' => $maxTokens, 'temperature' => 0.3, 'json' => $json],
        ['user_id' => $uid, 'feature' => 'assistant']);
    return ['ok' => !empty($r['ok']), 'text' => (string) ($r['text'] ?? ''), 'error' => $r['error'] ?? null];
}

/* ═══════════════════ الوكيل: المحادثة ← البريف ═══════════════════ */

function as_agent_system(int $uid): string
{
    require_once __DIR__ . '/ce-campaign.php';
    $opt = ce_wizard_options($uid);
    $svc = $opt['services'] ? implode('، ', array_map(fn($s) => $s['name'], $opt['services'])) : 'لسه مفيش خدمات متسجلة';
    $per = $opt['personas'] ? implode('، ', array_map(fn($p) => $p['name'], $opt['personas'])) : 'مفيش';
    $goals = implode(' · ', array_map(fn($g) => $g['key'] . '=' . $g['label'], $opt['goals']));
    $ch = implode(' · ', array_map(fn($c) => $c['key'] . '=' . $c['label'], $opt['channels']));
    $connected = db_one('SELECT id FROM social_connections WHERE user_id = ? AND status = "active" LIMIT 1', [$uid]) ? 'أيوه' : 'لأ';
    return "ROLE: SPREAD_AGENT\n"
        . "إنت «الوكيل» في منصة Spread AI: بتنفّذ حملات سوشيال ميديا كاملة مكان العميل (الخطة · الكتابة · التصميم · الجدولة) بمحرك المحتوى. بتتكلم بالمصري، قصير وعملي.\n"
        . "مهمتك في المحادثة: تفهم المطلوب بأقل أسئلة ممكنة (سؤال واحد في المرة، وبحد أقصى 3 أسئلة)، وبعدين تطلع بريف الحملة. لو العميل قال كفاية أو «نفّذ» استخدم الافتراضيات.\n\n"
        . "اللي تعرفه عن العميل:\n- خدماته: {$svc}\n- عملاؤه: {$per}\n- الرصيد: " . credits_balance($uid) . " كريدت\n- صفحة فيسبوك/إنستجرام مربوطة: {$connected}\n\n"
        . "الاختيارات:\n- goal: {$goals}\n- formats: post (بوست) · carousel (كاروسيل) · reel (ريلز/فيديو قصير) — العدد لكل نوع (الإجمالي من 1 لـ " . (int) $opt['max_pieces'] . ")\n"
        . "- days: مدة الحملة واحدة من " . implode('، ', CE_DAYS) . "\n- channel: {$ch}\n- boldness: safe · medium · bold\n"
        . "- offer (لو goal=offer بس): {type: percent|amount|gift|bundle, value, what}\n- publish: schedule (يجدول على صفحته في مواعيد الخطة — لو الصفحة مربوطة) أو none\n\n"
        . "ردك لازم يكون JSON بس بالشكل ده:\n"
        . '{"reply":"كلامك للعميل","task":null}' . "\n"
        . "ولما تكون فاهم كفاية (أو العميل طلب التنفيذ):\n"
        . '{"reply":"ملخص قصير للي هتعمله","task":{"goal":"","goal_other":"","services":["أسماء من خدماته"] أو "all","formats":{"post":0,"carousel":0,"reel":0},"days":30,"channel":"whatsapp","boldness":"medium","offer":null,"exclusions":[],"publish":"none","notes":""}}' . "\n\n"
        . "القواعد: ماتطلعش task غير لما تعرف الهدف وعدد القطع على الأقل. ماتوعدش بنتايج أو أرقام. إنت مابتلمسش الدفع ولا الحساب. "
        . "لو الطلب مش حملة محتوى (سؤال دعم · مشكلة في الحساب) قوله يستخدم «🛟 الدعم».";
}

/**
 * البريف من رد الموديل ← بريف المحرك (أسماء الخدمات ← أرقامها · افتراضيات معقولة) + أخطاء التحقق
 * @return array{0: array, 1: array, 2: array} [بريف المحرك, الأخطاء, خيارات الوكيل (النشر + الملاحظة)]
 */
function as_agent_brief(int $uid, array $task): array
{
    require_once __DIR__ . '/ce-campaign.php';
    $opt = ce_wizard_options($uid);
    $in = ['goal' => (string) ($task['goal'] ?? ''), 'goal_other' => (string) ($task['goal_other'] ?? '')];
    if (!isset(CE_GOALS[$in['goal']])) { $in['goal_other'] = $in['goal_other'] ?: (string) ($task['goal'] ?? ''); $in['goal'] = 'other'; }
    if ($in['goal'] === 'other' && trim($in['goal_other']) === '') $in['goal_other'] = mb_substr((string) ($task['notes'] ?? 'حملة من الوكيل'), 0, 120);
    $want = $task['services'] ?? 'all';
    $ids = [];
    if (is_array($want)) {
        foreach ($want as $name) {
            $name = mb_strtolower(trim((string) $name));
            if ($name === '') continue;
            foreach ($opt['services'] as $s) {
                $sn = mb_strtolower((string) $s['name']);
                if ($sn === $name || str_contains($sn, $name) || str_contains($name, $sn)) { $ids[] = (int) $s['id']; break; }
            }
        }
    }
    $ids = array_values(array_unique($ids));
    if (!$ids || count($ids) > 2) $in['all_services'] = 1; else $in['service_ids'] = $ids;
    $in['persona_ids'] = array_slice(array_map(fn($p) => (int) $p['id'], $opt['personas']), 0, 2);
    $f = (array) ($task['formats'] ?? []);
    $in['formats'] = ['post' => (int) ($f['post'] ?? 0), 'carousel' => (int) ($f['carousel'] ?? 0), 'reel' => (int) ($f['reel'] ?? 0)];
    $in['days'] = (int) ($task['days'] ?? 30);
    $in['channel'] = isset(CE_CHANNELS[$task['channel'] ?? '']) ? $task['channel'] : $opt['default_channel'];
    $in['boldness'] = isset(CE_BOLDNESS[$task['boldness'] ?? '']) ? $task['boldness'] : 'medium';
    if ($in['goal'] === 'offer') $in['offer'] = (array) ($task['offer'] ?? []) + ['condition' => ''];
    $in['exclusions'] = array_slice((array) ($task['exclusions'] ?? []), 0, 12);
    [$brief, $err] = ce_brief_clean($uid, $in);
    $o = ['publish' => ($task['publish'] ?? '') === 'schedule' ? 'schedule' : 'none', 'notes' => mb_substr(trim((string) ($task['notes'] ?? '')), 0, 300)];
    return [$brief, $err, $o];
}

/**
 * رسالة للوكيل ← رد · ولو البريف جاهز: مهمة «مستنية موافقة» بالتقدير
 * @return array{ok:bool, reply?:string, run?:array, error?:string, code?:string}
 */
function as_agent_send(int $uid, string $text): array
{
    if ($why = as_blocked($uid, 'agent')) return ['ok' => false, 'error' => $why, 'code' => 'blocked'];
    $text = mb_substr(trim($text), 0, 1500);
    if ($text === '') return ['ok' => false, 'error' => 'اكتب رسالتك الأول', 'code' => 'input'];
    if (function_exists('rate_limit') && !rate_limit('assistant_agent', 'u' . $uid, 30, 600)) return ['ok' => false, 'error' => 'رسايل كتير — استنى شوية', 'code' => 'rate'];
    $cost = max(0, (int) get_setting('assistant_agent_msg_cost', '0'));
    if ($cost > 0 && !credits_consume($uid, $cost, 'الوكيل')) return ['ok' => false, 'error' => credits_short_msg($cost, 'رسالة الوكيل'), 'code' => 'credits'];

    require_once __DIR__ . '/ce-design.php';
    $t = as_thread($uid, 'agent');
    $t['messages'][] = ['role' => 'user', 'content' => $text, 'at' => date('Y-m-d H:i')];
    $r = as_ai($uid, 'assistant_agent', as_agent_system($uid), $t['messages'], true, 900);
    $data = $r['ok'] ? ce_json_decode($r['text']) : null;
    if (!$r['ok'] || !is_array($data)) {
        if ($cost > 0) credits_add($uid, $cost, 'استرداد: الوكيل', null, 'refund');
        return ['ok' => false, 'error' => 'الوكيل مش متاح دلوقتي — جرّب كمان شوية', 'code' => 'ai'];
    }
    $reply = trim((string) ($data['reply'] ?? '')) ?: 'تمام ✓';
    $run = null;
    if (is_array($data['task'] ?? null)) {
        [$brief, $err, $o] = as_agent_brief($uid, $data['task']);
        if ($err) {
            $reply .= "\n\nمحتاج أعرف كمان: " . implode(' · ', array_values($err));
        } else {
            // مهمة جديدة مستنية الموافقة (والقديمة اللي لسه ماتوافقش عليها بتتشال)
            db_run('UPDATE agent_runs SET status = "cancelled", note = "اتبدّلت بمهمة أحدث" WHERE user_id = ? AND status = "pending"', [$uid]);
            $est = ce_estimate($brief);
            $id = (int) db_insert('INSERT INTO agent_runs (user_id, thread_id, title, stage, status, brief_json, opts_json, estimate_json, fee_credits) VALUES (?,?,?,"confirm","pending",?,?,?,?)', [
                $uid, $t['id'], mb_substr(ce_brief_title($uid, $brief), 0, 190), json_encode($brief, JSON_UNESCAPED_UNICODE),
                json_encode($o, JSON_UNESCAPED_UNICODE), json_encode(ce_estimate_public($est), JSON_UNESCAPED_UNICODE), max(0, (int) get_setting('assistant_agent_fee', '2')),
            ]);
            $run = as_run_public(as_run($id, $uid));
        }
    }
    $t['messages'][] = ['role' => 'assistant', 'content' => $reply, 'run_id' => $run['id'] ?? null, 'at' => date('Y-m-d H:i')];
    as_thread_save($t['id'], $t['messages']);
    return ['ok' => true, 'reply' => $reply, 'run' => $run];
}

/* ═══════════════════ الوكيل: المهام ═══════════════════ */

function as_run(int $id, ?int $uid = null): ?array
{
    $r = $uid === null ? db_one('SELECT * FROM agent_runs WHERE id = ?', [$id]) : db_one('SELECT * FROM agent_runs WHERE id = ? AND user_id = ?', [$id, $uid]);
    return $r ?: null;
}

function as_run_opts(array $run): array
{
    return json_decode((string) ($run['opts_json'] ?? ''), true) ?: [];
}

function as_run_set(int $id, array $set): void
{
    $cols = [];
    $vals = [];
    foreach ($set as $k => $v) { $cols[] = "`{$k}` = ?"; $vals[] = is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : $v; }
    $vals[] = $id;
    db_run('UPDATE agent_runs SET ' . implode(', ', $cols) . ' WHERE id = ?', $vals);
}

function as_run_log(array &$run, string $msg): void
{
    $log = json_decode((string) ($run['log_json'] ?? ''), true) ?: [];
    $log[] = ['at' => date('Y-m-d H:i'), 'stage' => $run['stage'], 'msg' => $msg];
    $run['log_json'] = json_encode(array_slice($log, -40), JSON_UNESCAPED_UNICODE);
    db_run('UPDATE agent_runs SET log_json = ? WHERE id = ?', [$run['log_json'], (int) $run['id']]);
}

/** المهمة للواجهة */
function as_run_public(?array $r): ?array
{
    if (!$r) return null;
    $est = json_decode((string) ($r['estimate_json'] ?? ''), true) ?: [];
    $b = json_decode((string) ($r['brief_json'] ?? ''), true) ?: [];
    $o = as_run_opts($r);
    $stages = [];
    $keys = array_keys(AS_STAGES);
    $cur = array_search($r['stage'], $keys, true);
    foreach (AS_STAGES as $k => $lbl) {
        if ($k === 'publish' && ($o['publish'] ?? 'none') !== 'schedule') continue;
        if ($k === 'dna' && empty($o['needs_dna'])) continue;
        $i = array_search($k, $keys, true);
        $stages[] = ['key' => $k, 'label' => $lbl, 'state' => $r['status'] === 'done' || ($cur !== false && $i < $cur) ? 'done' : ($k === $r['stage'] ? $r['status'] : 'todo')];
    }
    return [
        'id' => (int) $r['id'], 'title' => (string) $r['title'], 'stage' => (string) $r['stage'], 'stage_label' => AS_STAGES[$r['stage']] ?? 'الموافقة',
        'status' => (string) $r['status'], 'note' => (string) ($r['note'] ?? ''), 'stages' => array_values(array_filter($stages, fn($s) => $s['key'] !== 'done')),
        'pieces' => (int) ($est['pieces'] ?? array_sum((array) ($b['formats'] ?? []))), 'formats' => $b['formats'] ?? [], 'days' => (int) ($b['days'] ?? 0),
        'credits' => (int) ($est['credits'] ?? 0), 'designs' => (int) ($est['images'] ?? 0), 'fee' => (int) $r['fee_credits'],
        'publish' => ($o['publish'] ?? 'none'), 'campaign_id' => $r['campaign_id'] ? (int) $r['campaign_id'] : null,
        'campaign_url' => $r['campaign_id'] ? url('engine.php?campaign=' . (int) $r['campaign_id']) : null,
        'log' => array_slice(json_decode((string) ($r['log_json'] ?? ''), true) ?: [], -6),
        'active' => in_array($r['status'], ['running', 'paused'], true),
    ];
}

/** الصفحات المربوطة (لاختيار الجدولة) */
function as_connections(int $uid): array
{
    return array_map(fn($c) => ['id' => (int) $c['id'], 'name' => (string) $c['page_name'], 'ig' => !empty($c['ig_user_id'])],
        db_all('SELECT id, page_name, ig_user_id FROM social_connections WHERE user_id = ? AND status = "active" ORDER BY id', [$uid]));
}

/**
 * العميل وافق ← رسوم التنفيذ ← الحملة في المحرك ← أول خطوة
 * @param array $in {publish: none|schedule, connection_id, platforms[]}
 */
function as_agent_confirm(int $uid, int $runId, array $in = []): array
{
    if ($why = as_blocked($uid, 'agent')) return ['ok' => false, 'error' => $why, 'code' => 'blocked'];
    $run = as_run($runId, $uid);
    if (!$run || $run['status'] !== 'pending') return ['ok' => false, 'error' => 'المهمة دي مش مستنية موافقة', 'code' => 'state'];
    $daily = max(1, (int) get_setting('assistant_agent_daily', '3'));
    $today = (int) (db_one('SELECT COUNT(*) n FROM agent_runs WHERE user_id = ? AND campaign_id IS NOT NULL AND created_at > DATE_SUB(NOW(), INTERVAL 1 DAY)', [$uid])['n'] ?? 0);
    if ($today >= $daily) return ['ok' => false, 'error' => 'وصلت لحد ' . $daily . ' مهام في اليوم للوكيل — كمّل بكرة', 'code' => 'limit'];
    if (db_one('SELECT id FROM agent_runs WHERE user_id = ? AND status IN ("running","paused") LIMIT 1', [$uid])) return ['ok' => false, 'error' => 'الوكيل شغال على مهمة تانية — استنى لما تخلص أو ألغيها', 'code' => 'busy'];

    $o = as_run_opts($run);
    $o['publish'] = ($in['publish'] ?? $o['publish'] ?? 'none') === 'schedule' ? 'schedule' : 'none';
    if ($o['publish'] === 'schedule') {
        $conn = (int) ($in['connection_id'] ?? 0);
        $ok = $conn && db_one('SELECT id FROM social_connections WHERE id = ? AND user_id = ? AND status = "active"', [$conn, $uid]);
        if (!$ok) return ['ok' => false, 'error' => 'اختار الصفحة اللي هنجدول عليها (أو اربط صفحتك الأول)', 'code' => 'input'];
        require_once __DIR__ . '/ce-publish.php';
        if (!ce_publish_ready() || !ce_publish_allowed($uid)) return ['ok' => false, 'error' => 'النشر مش في باقتك — اختار «من غير جدولة»', 'code' => 'feature'];
        $o['connection_id'] = $conn;
        $pl = array_values(array_intersect(['facebook', 'instagram'], (array) ($in['platforms'] ?? ['facebook'])));
        $o['platforms'] = $pl ?: ['facebook'];
    }
    $fee = (int) $run['fee_credits'];
    if ($fee > 0 && !credits_consume($uid, $fee, 'الوكيل: رسوم تنفيذ «' . mb_substr((string) $run['title'], 0, 60) . '»', 'agent_run', $runId)) {
        return ['ok' => false, 'error' => credits_short_msg($fee, 'رسوم الوكيل'), 'code' => 'credits'];
    }
    require_once __DIR__ . '/ce-design.php';
    $brief = json_decode((string) $run['brief_json'], true) ?: [];
    $cs = ce_campaign_save($uid, $brief);
    if (!$cs['ok']) {
        if ($fee > 0) credits_add($uid, $fee, 'استرداد: رسوم الوكيل', null, 'refund', $runId);
        return ['ok' => false, 'error' => 'البريف محتاج تعديل: ' . implode(' · ', $cs['errors']), 'code' => 'brief'];
    }
    $o['needs_dna'] = ce_dna_row($uid) ? 0 : 1;
    $o['fee_paid'] = $fee;
    $st = db()->prepare('UPDATE agent_runs SET status = "running", stage = ?, campaign_id = ?, opts_json = ? WHERE id = ? AND status = "pending"');
    $st->execute([$o['needs_dna'] ? 'dna' : 'plan', (int) $cs['campaign']['id'], json_encode($o, JSON_UNESCAPED_UNICODE), $runId]);
    if ($st->rowCount() !== 1) return ['ok' => false, 'error' => 'المهمة اتغيرت — حدّث', 'code' => 'state'];
    $run = as_run($runId);
    as_run_log($run, 'بدأت ✓ — الحملة اتعملت في المحرك' . ($fee ? ' · رسوم التنفيذ ' . $fee . ' كريدت' : ''));
    as_agent_tick($run);
    return ['ok' => true, 'run' => as_run_public(as_run($runId))];
}

/** إلغاء: الشغلانة الحالية بتتلغي (حجزها بيرجع) · رسوم التنفيذ بترجع لو لسه في الخطة */
function as_agent_cancel(int $uid, int $runId): array
{
    $run = as_run($runId, $uid);
    if (!$run || !in_array($run['status'], ['pending', 'running', 'paused'], true)) return ['ok' => false, 'error' => 'المهمة دي مينفعش تتلغي', 'code' => 'state'];
    $o = as_run_opts($run);
    if (!empty($o['job_id'])) {
        require_once __DIR__ . '/ce-jobs.php';
        ce_job_cancel((int) $o['job_id'], $uid);
    }
    $refund = $run['status'] !== 'pending' && in_array($run['stage'], ['dna', 'plan'], true) ? (int) ($o['fee_paid'] ?? 0) : 0;
    if ($refund > 0) credits_add($uid, $refund, 'استرداد: رسوم الوكيل (اتلغت)', null, 'refund', $runId);
    db_run('UPDATE agent_runs SET status = "cancelled", finished_at = NOW(), note = ? WHERE id = ?', ['اتلغت' . ($refund ? ' — رسوم التنفيذ رجعت' : ''), $runId]);
    return ['ok' => true, 'run' => as_run_public(as_run($runId))];
}

/* ═══════════════════ الوكيل: التنفيذ (خطوة كل مرة) ═══════════════════ */

/** إيقاف مؤقت بسبب واضح للعميل (بيكمّل لوحده لما السبب يتحل) */
function as_run_pause(array &$run, string $why, bool $notify = true): void
{
    if ($run['status'] === 'paused' && $run['note'] === $why) return;
    as_run_set((int) $run['id'], ['status' => 'paused', 'note' => mb_substr($why, 0, 500)]);
    $run['status'] = 'paused';
    $run['note'] = $why;
    as_run_log($run, '⏸ ' . $why);
    if ($notify) as_run_notify($run, 'الوكيل وقف مستنيك ⏸', $why);
}

function as_run_fail(array &$run, string $why): void
{
    as_run_set((int) $run['id'], ['status' => 'failed', 'note' => mb_substr($why, 0, 500), 'finished_at' => date('Y-m-d H:i:s')]);
    $run['status'] = 'failed';
    as_run_log($run, '✕ ' . $why);
    as_run_notify($run, 'الوكيل مقدرش يكمّل «' . mb_substr((string) $run['title'], 0, 60) . '»', $why);
}

function as_run_notify(array $run, string $title, string $body): void
{
    if (!function_exists('notify_user')) require_once __DIR__ . '/billing.php';
    try { notify_user((int) $run['user_id'], 'agent_run', $title, $body, $run['campaign_id'] ? 'engine.php?campaign=' . (int) $run['campaign_id'] : 'engine.php', 'brand', false); } catch (\Throwable $e) {}
    as_thread_push((int) $run['user_id'], 'agent', ['role' => 'assistant', 'content' => '**' . $title . "**\n" . $body, 'run_id' => (int) $run['id']]);
}

/** الانتقال للمرحلة الجاية */
function as_run_next(array &$run, string $stage, string $msg): void
{
    $o = as_run_opts($run);
    unset($o['job_id']);
    $o['tries'] = 0;
    as_run_set((int) $run['id'], ['stage' => $stage, 'status' => 'running', 'note' => null, 'opts_json' => $o]);
    $run['stage'] = $stage;
    $run['status'] = 'running';
    $run['note'] = null;
    $run['opts_json'] = json_encode($o, JSON_UNESCAPED_UNICODE);
    as_run_log($run, $msg);
}

/** شغلانة المرحلة: لو لسه شغالة ← استنى (ولو مفيش cron: خطوة منها) · لو فشلت ← محاولة تانية مرة واحدة */
function as_job_wait(array &$run, string $kind, bool $stepIfNoWorker): string
{
    $active = ce_job_active_for((int) $run['user_id'], $kind, $kind === 'brand_dna' ? null : 'ce_campaign', $kind === 'brand_dna' ? null : (int) $run['campaign_id']);
    if ($active) {
        if ($stepIfNoWorker && !ce_worker_alive()) ce_job_step((int) $active['id']);
        return 'wait';
    }
    $o = as_run_opts($run);
    if (empty($o['job_id'])) return 'none';
    $j = ce_job_get((int) $o['job_id'], (int) $run['user_id']);
    return $j ? (string) $j['status'] : 'none';
}

/** شغلانة اتعملت ← نحفظ رقمها · أو سبب الوقوف */
function as_job_started(array &$run, array $r, string $what): bool
{
    if (!empty($r['ok'])) {
        $o = as_run_opts($run);
        $o['job_id'] = (int) $r['job']['id'];
        as_run_set((int) $run['id'], ['opts_json' => $o, 'status' => 'running', 'note' => null]);
        $run['opts_json'] = json_encode($o, JSON_UNESCAPED_UNICODE);
        $run['status'] = 'running';
        if (empty($r['existing'])) as_run_log($run, '▶ ' . $what);
        return true;
    }
    $code = (string) ($r['code'] ?? '');
    if ($code === 'credits') as_run_pause($run, 'رصيدك مش كفاية ل' . $what . ' — اشحن أو رقّي باقتك من «باقتي» وأنا هكمّل لوحدي. (' . ($r['error'] ?? '') . ')');
    elseif ($code === 'quota') as_run_pause($run, ($r['error'] ?? 'التصميمات خلصت') . ' — زوّد التصميمات من «باقتي» وأنا هكمّل لوحدي.');
    else as_run_pause($run, 'مقدرتش أبدأ ' . $what . ': ' . ($r['error'] ?? 'مشكلة غير معروفة'), false);
    return false;
}

/** فشل الشغلانة: محاولة تانية مرة · بعدها فشل */
function as_job_retry(array &$run, string $what): bool
{
    $o = as_run_opts($run);
    $o['tries'] = (int) ($o['tries'] ?? 0) + 1;
    unset($o['job_id']);
    as_run_set((int) $run['id'], ['opts_json' => $o]);
    $run['opts_json'] = json_encode($o, JSON_UNESCAPED_UNICODE);
    if ($o['tries'] > 1) {
        as_run_fail($run, $what . ' فشلت مرتين — الكريدت المحجوز رجع. جرّب تاني بعد شوية أو افتح الحملة من المحرك.');
        return false;
    }
    as_run_log($run, '↻ ' . $what . ' فشلت — بجرّب تاني');
    return true;
}

/**
 * خطوة واحدة من المهمة (الـ cron كل دقيقة + الشات وهو مفتوح)
 * @return array المهمة بعد الخطوة
 */
function as_agent_tick(array $run, bool $stepIfNoWorker = true): array
{
    if (!in_array($run['status'], ['running', 'paused'], true)) return $run;
    require_once __DIR__ . '/ce-design.php';
    require_once __DIR__ . '/ce-publish.php';
    $uid = (int) $run['user_id'];
    if (function_exists('qt_price_user')) qt_price_user($uid, true);   // سعر ومستوى صاحب المهمة (من غير جلسة)
    $cid = (int) $run['campaign_id'];
    $c = $cid ? ce_campaign_get($cid, $uid) : null;
    if (!$c) { as_run_fail($run, 'الحملة اتمسحت'); return $run; }

    switch ($run['stage']) {
        case 'dna':
            if (ce_dna_row($uid)) { as_run_next($run, 'plan', '✓ ملف الهوية جاهز'); break; }
            $w = as_job_wait($run, 'brand_dna', $stepIfNoWorker);
            if ($w === 'wait') break;
            if ($w === 'failed' || $w === 'cancelled') { if (!as_job_retry($run, 'بناء ملف الهوية')) break; }
            as_job_started($run, ce_dna_build($uid), 'بناء ملف الهوية');
            break;

        case 'plan':
            $c = ce_plan_sync_state($uid, $c);
            if (in_array($c['status'], ['plan_ok', 'writing', 'done'], true)) { as_run_next($run, 'write', '✓ الخطة اتعتمدت'); break; }
            if ($c['status'] === 'planning') { as_job_wait($run, 'campaign_plan', $stepIfNoWorker); break; }
            if ($c['status'] === 'review') {
                $a = ce_plan_approve($uid, $cid);
                if ($a['ok']) { as_run_next($run, 'write', '✓ الخطة اتعتمدت (درجتها عدّت الحد)'); break; }
                as_run_pause($run, 'الخطة محتاجة مراجعة منك: ' . $a['error'] . ' — افتح الحملة، عدّل الخطة واعتمدها، وأنا هكمّل الكتابة والتصميم لوحدي.');
                break;
            }
            // ready: لو فيه شغلانة فشلت قبل كده ← محاولة تانية
            if (in_array(as_job_wait($run, 'campaign_plan', false), ['failed', 'cancelled'], true) && !as_job_retry($run, 'الخطة')) break;
            as_job_started($run, ce_plan_start($uid, $cid), 'الخطة');
            break;

        case 'write':
            if ($c['status'] === 'plan_ok') {
                if (in_array(as_job_wait($run, 'campaign_write', false), ['failed', 'cancelled'], true) && !as_job_retry($run, 'الكتابة')) break;
                as_job_started($run, ce_write_start($uid, $cid), 'الكتابة');
                break;
            }
            if ($c['status'] === 'writing' || ce_job_active_for($uid, 'campaign_write', 'ce_campaign', $cid)) {
                // لو الشغلانة فشلت المحرك بيرجّع الحملة «plan_ok» ← المحاولة التانية في الخطوة الجاية
                as_job_wait($run, 'campaign_write', $stepIfNoWorker);
                break;
            }
            if ($c['status'] === 'done') {
                // القطع «محتاجة مراجعة يدوية» ← الوكيل بيعتمدها (هو اللي شغال مكان العميل)
                $n = 0;
                foreach (db_all('SELECT slot_id FROM ce_contents WHERE campaign_id = ? AND user_id = ? AND status = "manual"', [$cid, $uid]) as $x) {
                    if (ce_content_accept($uid, $cid, (int) $x['slot_id'])) $n++;
                }
                $ok = (int) (db_one('SELECT COUNT(*) n FROM ce_contents WHERE campaign_id = ? AND user_id = ? AND status IN ("pass","manual")', [$cid, $uid])['n'] ?? 0);
                if ($ok === 0) { as_run_fail($run, 'الكتابة خلصت من غير ولا قطعة جاهزة — افتح الحملة وشوف التقرير.'); break; }
                as_run_next($run, 'design', '✓ الكتابة خلصت — ' . $ok . ' قطعة جاهزة' . ($n ? ' (اعتمدت ' . $n . ' كانت محتاجة مراجعة)' : ''));
            }
            break;

        case 'design':
            $w = as_job_wait($run, 'campaign_design', $stepIfNoWorker);
            if ($w === 'wait') break;
            $o = as_run_opts($run);
            if ($w === 'done' || !empty($o['design_done'])) {
                $n = 0;
                foreach (db_all('SELECT slot_id, frame FROM ce_designs WHERE campaign_id = ? AND status = "manual"', [$cid]) as $d) {
                    if (ce_design_accept($uid, $cid, (int) $d['slot_id'], (int) $d['frame'])) $n++;
                }
                $made = (int) (db_one('SELECT COUNT(*) n FROM ce_designs WHERE campaign_id = ? AND status IN ("pass","manual")', [$cid])['n'] ?? 0);
                $pub = ($o['publish'] ?? 'none') === 'schedule';
                as_run_next($run, $pub ? 'publish' : 'done', '✓ التصميم خلص — ' . $made . ' تصميم' . ($n ? ' (اعتمدت ' . $n . ')' : ''));
                if (!$pub) as_run_finish($run);
                break;
            }
            if (in_array($w, ['failed', 'cancelled'], true) && !as_job_retry($run, 'التصميم')) break;
            $r = ce_design_start($uid, $cid, false);
            if (!$r['ok'] && ($r['code'] ?? '') === 'nothing') {
                $o['design_done'] = 1;
                as_run_set((int) $run['id'], ['opts_json' => $o]);
                $run['opts_json'] = json_encode($o, JSON_UNESCAPED_UNICODE);
                break;
            }
            as_job_started($run, $r, 'التصميم');
            break;

        case 'publish':
            $o = as_run_opts($run);
            $ids = array_map('intval', array_column(db_all('SELECT id FROM ce_contents WHERE campaign_id = ? AND user_id = ? AND status IN ("pass","manual")', [$cid, $uid]), 'id'));
            $r = $ids ? ce_publish_queue_add($uid, $cid, $ids, (int) ($o['connection_id'] ?? 0), (array) ($o['platforms'] ?? ['facebook']), 'slot') : ['ok' => false, 'error' => 'مفيش قطع'];
            if (!$r['ok']) {
                as_run_log($run, '⚠ الجدولة: ' . ($r['error'] ?? ''));
                as_run_next($run, 'done', 'خلصت من غير جدولة — تقدر تجدول من الحملة');
            } else {
                as_run_next($run, 'done', '✓ اتجدول ' . (int) $r['queued'] . ' منشور على مواعيد الخطة' . ((int) ($r['skipped'] ?? 0) ? ' (اتخطى ' . (int) $r['skipped'] . ')' : ''));
            }
            as_run_finish($run);
            break;
    }
    return as_run((int) $run['id']) ?? $run;
}

function as_run_finish(array &$run): void
{
    as_run_set((int) $run['id'], ['status' => 'done', 'stage' => 'done', 'finished_at' => date('Y-m-d H:i:s'), 'note' => null]);
    $run['status'] = 'done';
    $pub = (as_run_opts($run)['publish'] ?? 'none') === 'schedule';
    as_run_notify($run, 'الوكيل خلّص «' . mb_substr((string) $run['title'], 0, 60) . '» 🎉',
        'الحملة جاهزة: الخطة والكتابة والتصميم' . ($pub ? ' والجدولة' : '') . ' — افتح الحملة تراجعها، وكل القطع في «المحتوى» والتقويم.');
}

/** الـ cron: كل المهام الشغالة والواقفة (الواقفة بتكمّل لوحدها لو السبب اتحل) */
function as_agent_tick_all(int $limit = 10): int
{
    if (!as_ready()) return 0;
    $n = 0;
    // الواقفة بتتشيّك كل 5 دقايق بس (مش كل دقيقة) — عشان مانعيدش محاولات ملهاش لازمة
    foreach (db_all('SELECT * FROM agent_runs WHERE status = "running" OR (status = "paused" AND updated_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE)) ORDER BY updated_at LIMIT ' . max(1, $limit)) as $r) {
        try {
            $before = $r['stage'] . $r['status'];
            $after = as_agent_tick($r, false);
            if ($after['stage'] . $after['status'] !== $before) $n++;
            else db_run('UPDATE agent_runs SET updated_at = NOW() WHERE id = ?', [(int) $r['id']]);
        } catch (\Throwable $e) {
            error_log('[agent] run ' . $r['id'] . ' ' . $e->getMessage());
        }
    }
    if (function_exists('qt_price_user')) qt_price_user(null, true);
    return $n;
}

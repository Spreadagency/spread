<?php
/**
 * Spread AI — خطة الحملات · المرحلة 4: الحملة وحدة في الباقة (docs/PLAN_CAMPAIGNS_V2.md §2)
 *
 * قاعدة واحدة في مكان واحد — «اسمح · احسب · رجّع» للحملة ومنشوراتها وتصميماتها ورصيد التعديلات.
 * كل المسارات بتناديه: api/campaign-flow.php · ajax/generate-design.php · (بعدين: شغل الخلفية والوكيل).
 *
 *   الباقة فيها campaigns_month > 0 (لقطة user_plans) ← الحملة الجديدة «في الباقة»:
 *     مسودة (pkg_state = draft) ← «اعتمد الخطة» = الخصم (usage_events unit campaigns) ← معتمدة ← خلصت | اتلغت
 *     جوه الحملة: المنشورات والفيديوهات من حد الحملة (من غير كريدت) · تصميم واحد لكل منشور من حصة الحملة
 *     (عدّاد التصميمات العادي بيستثنيه) · الإعادة بطلب العميل من رصيد التعديلات ← ولو خلص بالكريدت
 *   0 = الحملات بالكريدت زي دلوقتي · الحملات اللي قبل الترحيل (campaign_pkg_since) بتكمّل بالكريدت
 *   مفتاح طوارئ: campaign_pkg_enabled = 0
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/plans.php';

const CA_DAY = 86400;
const CA_WINDOW_DAYS = 30;

function ca_ready(): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        db_one('SELECT pkg_state, posts_used, draft_plans FROM campaigns LIMIT 1');
        db_one('SELECT campaigns_month FROM user_plans LIMIT 1');
        db_one('SELECT campaign_alloc FROM content_designs LIMIT 1');
        return $ok = true;
    } catch (\Throwable $e) {
        return $ok = false;
    }
}

function ca_enabled(): bool
{
    return ca_ready() && (string) get_setting('campaign_pkg_enabled', '1') === '1';
}

/** حدود الحملة من الدورة (لقطة الاشتراك) ← null = الحملات بالكريدت */
function ca_limits_from_plan(array $plan): ?array
{
    if (!empty($plan['synthetic']) || empty($plan['id']) || (int) ($plan['campaigns_month'] ?? 0) <= 0) return null;
    $lim = [
        'plan_id' => (int) $plan['id'], 'starts' => (string) $plan['starts'], 'ends' => (string) $plan['ends'],
        'campaigns_month' => (int) $plan['campaigns_month'],
        'posts' => max(0, (int) ($plan['posts_per_campaign'] ?? 0)), 'videos' => max(0, (int) ($plan['videos_per_campaign'] ?? 0)),
        'edits' => max(0, (int) ($plan['free_edits_per_campaign'] ?? 0)), 'slides' => max(0, (int) ($plan['carousel_max_slides'] ?? 0)),
    ];
    return $lim;
}

/** حدود العميل دلوقتي ← null = الحملات بالكريدت */
function ca_user_limits(int $uid): ?array
{
    if (!ca_enabled()) return null;
    return ca_limits_from_plan(plan_active($uid));
}

/** حدود الحملة نفسها (من الدورة اللي اتحسبت منها — حتى لو الباقة اتغيّرت بعدها) */
function ca_campaign_limits(array $c): ?array
{
    if (!ca_ready() || empty($c['pkg_state']) || empty($c['user_plan_id'])) return null;
    $r = db_one('SELECT * FROM user_plans WHERE id = ? AND user_id = ?', [(int) $c['user_plan_id'], (int) $c['user_id']]);
    if (!$r) return null;
    return ca_limits_from_plan(['id' => (int) $r['id'], 'starts' => (string) $r['starts_at'], 'ends' => (string) $r['ends_at'], 'synthetic' => false,
        'campaigns_month' => (int) $r['campaigns_month'], 'posts_per_campaign' => (int) $r['posts_per_campaign'], 'videos_per_campaign' => (int) $r['videos_per_campaign'],
        'free_edits_per_campaign' => (int) $r['free_edits_per_campaign'], 'carousel_max_slides' => (int) $r['carousel_max_slides']]);
}

/** الحملة في الباقة؟ (مفتاح الطوارئ بيرجّع كل حاجة للكريدت) */
function ca_is_package(array $c): bool
{
    return ca_enabled() && !empty($c['pkg_state']) && !empty($c['user_plan_id']);
}

/* ═══════════════════ الحملات في الشهر (تراكمي — كل 30 يوم من بداية الاشتراك) ═══════════════════ */

/** رقم الشهر في الدورة (1 من أول يوم) ومعاد الشهر الجاي — زي pkg_week بس 30 يوم */
function ca_window(string $starts, int $days = CA_WINDOW_DAYS, ?int $now = null): array
{
    $now ??= time();
    $start = strtotime($starts) ?: $now;
    $len = max(1, $days) * CA_DAY;
    $n = (int) floor(max(0, $now - $start) / $len) + 1;
    return [$n, date('Y-m-d', $start + $n * $len)];
}

/** حالة حملات العميل في الدورة ← null = بالكريدت */
function ca_status(int $uid): ?array
{
    $lim = ca_user_limits($uid);
    if (!$lim) return null;
    [$n, $next] = ca_window($lim['starts']);
    $allow = $lim['campaigns_month'] * $n;
    $used = (int) (db_one('SELECT COUNT(*) n FROM usage_events WHERE user_id = ? AND unit = "campaigns" AND created_at >= ?', [$uid, $lim['starts']])['n'] ?? 0);
    $drafts = (int) (db_one('SELECT COUNT(*) n FROM campaigns WHERE user_id = ? AND pkg_state = "draft" AND status = "active"', [$uid])['n'] ?? 0);
    return $lim + ['month' => $n, 'allow' => $allow, 'used' => $used, 'left' => max(0, $allow - $used), 'next' => $next, 'drafts' => $drafts,
                   'max_drafts' => max(1, (int) get_setting('campaign_max_drafts', '2'))];
}

/* ═══════════════════ دخول الحملة الباقة (مسودة) ═══════════════════ */

/**
 * الحملة الجديدة بتدخل الباقة كمسودة أول ما العميل يبدأ يشتغل عليها (أول توليد للخطة)
 * — مش بتتحسب لسه. القديمة (قبل الترحيل) وحملات المحرك (basis = engine) واللي ليها محتوى بتفضل بالكريدت.
 * @return array الحملة بعد التحديث
 */
function ca_adopt(array $c): array
{
    if (!empty($c['pkg_state']) || !ca_enabled()) return $c;
    $uid = (int) $c['user_id'];
    $lim = ca_user_limits($uid);
    if (!$lim) return $c;
    if (($c['basis'] ?? '') === 'engine') return $c;
    $since = (string) get_setting('campaign_pkg_since', '');
    if ($since === '' || strtotime((string) $c['created_at']) < strtotime($since)) return $c;
    if (db_one('SELECT id FROM campaign_ideas WHERE campaign_id = ? AND content_id IS NOT NULL LIMIT 1', [(int) $c['id']])) return $c;
    db_run('UPDATE campaigns SET pkg_state = "draft", user_plan_id = ?, posts_target = IF(posts_target = 0, ?, LEAST(posts_target, ?)),
            videos_target = IF(videos_target = 0, ?, LEAST(videos_target, ?)) WHERE id = ? AND pkg_state IS NULL',
        [$lim['plan_id'], $lim['posts'], $lim['posts'], $lim['videos'], $lim['videos'], (int) $c['id']]);
    return db_one('SELECT * FROM campaigns WHERE id = ?', [(int) $c['id']]) ?: $c;
}

/**
 * توليد الخطة في المسودة: الأول ببلاش + إعادتين ببلاش (campaign_draft_regens) — بعد كده بسعر الأفكار العادي
 * + أقصى حملتين مسودة في نفس الوقت
 * @return array{ok: bool, free: bool, error: ?string}
 */
function ca_draft_plan(array $c): array
{
    if (!ca_is_package($c)) return ['ok' => true, 'free' => false, 'error' => null];
    if ($c['pkg_state'] !== 'draft') {
        return ['ok' => false, 'free' => false, 'error' => $c['pkg_state'] === 'approved' ? 'الخطة اتعتمدت خلاص — عدّل الأفكار واحدة واحدة' : 'الحملة دي اتقفلت'];
    }
    $uid = (int) $c['user_id'];
    $max = max(1, (int) get_setting('campaign_max_drafts', '2'));
    if ((int) $c['draft_plans'] === 0) {
        $others = (int) (db_one('SELECT COUNT(*) n FROM campaigns WHERE user_id = ? AND id <> ? AND pkg_state = "draft" AND status = "active" AND draft_plans > 0',
            [$uid, (int) $c['id']])['n'] ?? 0);
        if ($others >= $max) return ['ok' => false, 'free' => false, 'error' => 'عندك ' . $others . ' حملات مسودة — اعتمد واحدة أو الغيها الأول'];
    }
    $free = (int) $c['draft_plans'] <= max(0, (int) get_setting('campaign_draft_regens', '2'));
    db_run('UPDATE campaigns SET draft_plans = LEAST(draft_plans + 1, 255), draft_regens = LEAST(draft_regens + IF(draft_plans > 1, 1, 0), 255) WHERE id = ?', [(int) $c['id']]);
    return ['ok' => true, 'free' => $free, 'error' => null];
}

/** فكرة بديلة واحدة في المسودة: أول 5 ببلاش (campaign_draft_alts) — بعدها بسعر الأفكار */
function ca_draft_alt(array $c): array
{
    if (!ca_is_package($c) || $c['pkg_state'] !== 'draft') return ['ok' => true, 'free' => false];
    $st = db()->prepare('UPDATE campaigns SET draft_alts = draft_alts + 1 WHERE id = ? AND draft_alts < ?');
    $st->execute([(int) $c['id'], max(0, (int) get_setting('campaign_draft_alts', '5'))]);
    return ['ok' => true, 'free' => $st->rowCount() === 1];
}

/* ═══════════════════ «اعتمد الخطة» = الخصم ═══════════════════ */

/**
 * بقفل على العميل (موافقتين في نفس اللحظة مايعدّوش الاتنين) · plan_event بمفتاح الحملة (مايتعدّش مرتين)
 * @return array{ok: bool, error: ?string, already?: bool, status?: array}
 */
function ca_approve(int $uid, int $cid): array
{
    if (!ca_ready()) return ['ok' => false, 'error' => 'شغّل ترحيل 2026_campaign_package الأول'];
    $lock = 'spread_camp_' . $uid;
    $got = (int) (db_one('SELECT GET_LOCK(?, 20) l', [$lock])['l'] ?? 0);
    if ($got !== 1) return ['ok' => false, 'error' => 'فيه طلب تاني شغال — استنى لحظة وجرّب تاني'];
    try {
        $c = db_one('SELECT * FROM campaigns WHERE id = ? AND user_id = ?', [$cid, $uid]);
        if (!$c) return ['ok' => false, 'error' => 'الحملة مش موجودة'];
        if (!ca_is_package($c)) return ['ok' => false, 'error' => 'الحملة دي بالكريدت — مش محتاجة اعتماد'];
        if ($c['pkg_state'] === 'approved' || $c['pkg_state'] === 'done') return ['ok' => true, 'error' => null, 'already' => true];
        if ($c['pkg_state'] !== 'draft') return ['ok' => false, 'error' => 'الحملة دي اتلغت'];
        if (!db_one('SELECT id FROM campaign_ideas WHERE campaign_id = ? AND selected = 1 AND rejected = 0 LIMIT 1', [$cid])) {
            return ['ok' => false, 'error' => 'اختار فكرة واحدة على الأقل في الخطة'];
        }
        $st = ca_status($uid);
        // الحملة اتعملت من دورة اتقفلت (اشترك من جديد) ← بتتحسب من الدورة الحالية
        if (!$st) return ['ok' => false, 'error' => 'باقتك دلوقتي مافيهاش حملات — جدّد أو رقّي باقتك'];
        if ($st['left'] <= 0) {
            return ['ok' => false, 'code' => 'quota', 'error' => 'خلّصت حملات الفترة دي (' . $st['used'] . ' من ' . $st['allow'] . ') — الحملة الجاية بتنزل يوم '
                . (function_exists('fmt_date') ? fmt_date($st['next']) : $st['next']) . '، أو رقّي باقتك'];
        }
        plan_event($uid, 'campaigns', $cid);
        db_run('UPDATE campaigns SET pkg_state = "approved", counted_at = NOW(), user_plan_id = ? WHERE id = ? AND pkg_state = "draft"', [$st['plan_id'], $cid]);
        if (function_exists('ce_skill_bind_campaign') && function_exists('campaign_skill') && $c['skill_version_id'] === null) {
            $brand = db_one('SELECT * FROM brand_profiles WHERE user_id = ? ORDER BY id LIMIT 1', [$uid]) ?: [];
            ce_skill_bind_campaign($cid, campaign_skill($brand, ['user_id' => $uid]), 'campaigns');
        }
        return ['ok' => true, 'error' => null, 'status' => ca_status($uid)];
    } finally {
        try { db_one('SELECT RELEASE_LOCK(?) l', [$lock]); } catch (\Throwable $e) {}
    }
}

/**
 * إلغاء الحملة: قبل ما أي منشور يتكتب ← الحملة بترجع للعميل · بعد كده مابترجعش (اللي اتكتب بيفضل معاه)
 * @return array{ok: bool, released: bool, error: ?string}
 */
function ca_cancel(int $uid, int $cid): array
{
    $c = db_one('SELECT * FROM campaigns WHERE id = ? AND user_id = ?', [$cid, $uid]);
    if (!$c || !ca_is_package($c)) return ['ok' => false, 'released' => false, 'error' => 'الحملة دي مش في الباقة'];
    if (in_array($c['pkg_state'], ['cancelled', 'done'], true)) return ['ok' => true, 'released' => false, 'error' => null];
    $written = (int) $c['posts_used'] + (int) $c['videos_used'] > 0
        || db_one('SELECT id FROM campaign_ideas WHERE campaign_id = ? AND content_id IS NOT NULL LIMIT 1', [$cid]);
    $released = false;
    if ($c['pkg_state'] === 'approved' && !$written) {
        plan_event_release($uid, 'campaigns', $cid);
        $released = true;
    }
    db_run('UPDATE campaigns SET pkg_state = "cancelled" WHERE id = ?', [$cid]);
    return ['ok' => true, 'released' => $released, 'error' => null];
}

/* ═══════════════════ المنشورات والفيديوهات جوه الحملة ═══════════════════ */

function ca_unit(string $fmt): string
{
    return $fmt === 'video' ? 'camp_video' : 'camp_post';
}

/**
 * قبل كتابة منشور/سكريبت لفكرة في حملة في الباقة
 * @param ?string $oldFmt شكل المحتوى القديم لو موجود (إعادة كتابة)
 * @return array{ok: bool, cost: int, mode: string, error: ?string, code?: string}
 *   mode: new (من حد الحملة) · moved (نقل من منشور لفيديو أو العكس) · edit (من رصيد التعديلات) · credits (رصيد التعديلات خلص)
 */
function ca_write_allow(array $c, int $ideaId, string $fmt, ?string $oldFmt): array
{
    if (!ca_is_package($c)) return ['ok' => true, 'cost' => -1, 'mode' => 'off', 'error' => null];
    if ($c['pkg_state'] === 'draft') return ['ok' => false, 'cost' => 0, 'mode' => 'none', 'code' => 'not_approved', 'error' => 'اعتمد الخطة الأول — الحملة بتتحسب من باقتك لما تعتمدها'];
    if ($c['pkg_state'] !== 'approved') return ['ok' => false, 'cost' => 0, 'mode' => 'none', 'code' => 'closed', 'error' => 'الحملة دي اتقفلت'];
    $lim = ca_campaign_limits($c);
    if (!$lim) return ['ok' => false, 'cost' => 0, 'mode' => 'none', 'code' => 'closed', 'error' => 'الدورة اللي الحملة اتحسبت منها مش موجودة'];
    $uid = (int) $c['user_id'];
    $unit = ca_unit($fmt);
    $isVideo = $fmt === 'video';
    $col = $isVideo ? 'videos_used' : 'posts_used';
    $cap = $isVideo ? min($lim['videos'], (int) $c['videos_target']) : min($lim['posts'], (int) $c['posts_target']);   // العدد اللي العميل اختاره (بيبدأ بحد الباقة)
    $label = $isVideo ? 'سكريبتات الفيديو' : 'المنشورات';

    // إعادة كتابة لنفس النوع ← رصيد التعديلات
    if ($oldFmt !== null && ca_unit($oldFmt) === $unit) {
        $st = db()->prepare('UPDATE campaigns SET edits_used = edits_used + 1 WHERE id = ? AND edits_used < ?');
        $st->execute([(int) $c['id'], $lim['edits']]);
        if ($st->rowCount() === 1) return ['ok' => true, 'cost' => 0, 'mode' => 'edit', 'error' => null];
        return ['ok' => true, 'cost' => cost_for('content_regeneration_cost'), 'mode' => 'credits', 'error' => null];
    }
    // أول مرة للفكرة دي (أو نقل بين منشور وفيديو) ← خانة من حد الحملة (مفتاح لكل فكرة: الضغطة المزدوجة مابتعدّش مرتين)
    $ins = db()->prepare('INSERT IGNORE INTO usage_events (user_id, unit, ref_id) VALUES (?,?,?)');
    $ins->execute([$uid, $unit, $ideaId]);
    if ($ins->rowCount() === 0) return ['ok' => true, 'cost' => 0, 'mode' => 'counted', 'error' => null];   // طلب تاني لنفس الفكرة شغال/اتعدّ
    $st = db()->prepare("UPDATE campaigns SET $col = $col + 1 WHERE id = ? AND $col < ?");
    $st->execute([(int) $c['id'], $cap]);
    if ($st->rowCount() !== 1) {
        db_run('DELETE FROM usage_events WHERE user_id = ? AND unit = ? AND ref_id = ?', [$uid, $unit, $ideaId]);
        return ['ok' => false, 'cost' => 0, 'mode' => 'none', 'code' => 'campaign_limit', 'error' => 'وصلت لحد ' . $label . ' في الحملة دي (' . $cap . ')'];
    }
    // النقل: الخانة القديمة بترجع
    if ($oldFmt !== null) {
        $oldUnit = ca_unit($oldFmt);
        $oldCol = $oldUnit === 'camp_video' ? 'videos_used' : 'posts_used';
        db_run('DELETE FROM usage_events WHERE user_id = ? AND unit = ? AND ref_id = ?', [$uid, $oldUnit, $ideaId]);
        db_run("UPDATE campaigns SET $oldCol = GREATEST($oldCol - 1, 0) WHERE id = ?", [(int) $c['id']]);
        return ['ok' => true, 'cost' => 0, 'mode' => 'moved', 'error' => null, 'from' => $oldFmt];
    }
    return ['ok' => true, 'cost' => 0, 'mode' => 'new', 'error' => null];
}

/** رجوع اللي اتحسب لو الكتابة فشلت */
function ca_write_release(array $c, int $ideaId, string $fmt, array $allow): void
{
    if (!ca_is_package($c) || empty($allow['ok'])) return;
    $uid = (int) $c['user_id'];
    $unit = ca_unit($fmt);
    $col = $unit === 'camp_video' ? 'videos_used' : 'posts_used';
    switch ($allow['mode']) {
        case 'edit':
            db_run('UPDATE campaigns SET edits_used = GREATEST(edits_used - 1, 0) WHERE id = ?', [(int) $c['id']]);
            break;
        case 'new':
        case 'moved':
            db_run('DELETE FROM usage_events WHERE user_id = ? AND unit = ? AND ref_id = ?', [$uid, $unit, $ideaId]);
            db_run("UPDATE campaigns SET $col = GREATEST($col - 1, 0) WHERE id = ?", [(int) $c['id']]);
            if ($allow['mode'] === 'moved' && !empty($allow['from'])) {   // الخانة القديمة ترجع زي ما كانت
                $ou = ca_unit((string) $allow['from']);
                $oc = $ou === 'camp_video' ? 'videos_used' : 'posts_used';
                db_run('INSERT IGNORE INTO usage_events (user_id, unit, ref_id) VALUES (?,?,?)', [$uid, $ou, $ideaId]);
                db_run("UPDATE campaigns SET $oc = $oc + 1 WHERE id = ?", [(int) $c['id']]);
            }
            break;
    }
}

/** «حسّن» من العميل ← رصيد التعديلات ← ولو خلص بالكريدت */
function ca_edit_allow(array $c, int $creditCost): array
{
    if (!ca_is_package($c)) return ['ok' => true, 'cost' => -1, 'mode' => 'off'];
    if ($c['pkg_state'] !== 'approved') return ['ok' => false, 'cost' => 0, 'mode' => 'none', 'error' => 'الحملة دي مش معتمدة'];
    $lim = ca_campaign_limits($c);
    $st = db()->prepare('UPDATE campaigns SET edits_used = edits_used + 1 WHERE id = ? AND edits_used < ?');
    $st->execute([(int) $c['id'], (int) ($lim['edits'] ?? 0)]);
    return $st->rowCount() === 1 ? ['ok' => true, 'cost' => 0, 'mode' => 'edit'] : ['ok' => true, 'cost' => $creditCost, 'mode' => 'credits'];
}

function ca_edit_release(array $c, array $allow): void
{
    if (($allow['mode'] ?? '') === 'edit') db_run('UPDATE campaigns SET edits_used = GREATEST(edits_used - 1, 0) WHERE id = ?', [(int) $c['id']]);
}

/** أقصى شرايح للكاروسيل في الحملة (0 = الحد العام) */
function ca_max_slides(array $c): int
{
    if (!ca_is_package($c)) return 0;
    return (int) (ca_campaign_limits($c)['slides'] ?? 0);
}

/* ═══════════════════ التصميم ═══════════════════ */

/**
 * تصميم لمحتوى من حملة في الباقة
 *   alloc  = أول تصميم للمنشور (الكاروسيل: كل شريحة لسه ماتصممتش لحد carousel_max_slides) ← ببلاش ومن غير حصة التصميمات
 *   edit   = إعادة تصميم ← رصيد التعديلات
 *   credits= رصيد التعديلات خلص ← كريدت بس (content_design_cost) ومن غير حصة التصميمات
 *   off    = مش حملة في الباقة ← المسار العادي زي ما هو
 * @return array{mode: string, cost: int, error: ?string, campaign?: array}
 */
function ca_design_allow(array $content, ?int $slideNo, bool $isEdit): array
{
    if (!ca_ready() || empty($content['campaign_id'])) return ['mode' => 'off', 'cost' => 0, 'error' => null];
    $c = db_one('SELECT * FROM campaigns WHERE id = ? AND user_id = ?', [(int) $content['campaign_id'], (int) $content['user_id']]);
    if (!$c || !ca_is_package($c)) return ['mode' => 'off', 'cost' => 0, 'error' => null];
    if ($c['pkg_state'] !== 'approved') return ['mode' => 'none', 'cost' => 0, 'error' => 'الحملة دي مش معتمدة'];
    $max = ca_max_slides($c);
    $slide = $slideNo ?: 1;
    $designed = (bool) db_one('SELECT id FROM content_designs WHERE content_id = ? AND campaign_alloc = 1' . ($slideNo ? ' AND slide_no = ?' : '') . ' LIMIT 1',
        $slideNo ? [(int) $content['id'], $slideNo] : [(int) $content['id']]);
    if (!$isEdit && !$designed && ($max <= 0 || $slide <= $max)) return ['mode' => 'alloc', 'cost' => 0, 'error' => null, 'campaign' => $c];
    $cost = cost_for('content_design_cost');
    $a = ca_edit_allow($c, $cost);
    return ['mode' => $a['mode'], 'cost' => (int) $a['cost'], 'error' => null, 'campaign' => $c];
}

function ca_design_release(array $allow): void
{
    if (($allow['mode'] ?? '') === 'edit' && !empty($allow['campaign'])) ca_edit_release($allow['campaign'], ['mode' => 'edit']);
}

/* ═══════════════════ العرض ═══════════════════ */

/** حالة الحملة للواجهة ← null = بالكريدت */
function ca_campaign_view(array $c): ?array
{
    if (!ca_is_package($c)) return null;
    $lim = ca_campaign_limits($c);
    if (!$lim) return null;
    $postsCap = min($lim['posts'], (int) $c['posts_target']);
    $videosCap = min($lim['videos'], (int) $c['videos_target']);
    return [
        'state' => (string) $c['pkg_state'], 'counted_at' => $c['counted_at'],
        'posts' => ['used' => (int) $c['posts_used'], 'limit' => $postsCap, 'max' => $lim['posts']],
        'videos' => ['used' => (int) $c['videos_used'], 'limit' => $videosCap, 'max' => $lim['videos']],
        'edits' => ['used' => (int) $c['edits_used'], 'limit' => $lim['edits'], 'left' => max(0, $lim['edits'] - (int) $c['edits_used'])],
        'carousel_max_slides' => $lim['slides'],
        'draft' => ['plans' => (int) $c['draft_plans'], 'free_regens' => max(0, (int) get_setting('campaign_draft_regens', '2')),
                    'alts' => (int) $c['draft_alts'], 'free_alts' => max(0, (int) get_setting('campaign_draft_alts', '5'))],
    ];
}

/** تحديد عدد المنشورات/الفيديوهات (لحد حد الباقة) — بيتزوّد بعدين ببلاش لحد الحد */
function ca_set_targets(array $c, ?int $posts, ?int $videos): array
{
    if (!ca_is_package($c)) return ['ok' => false, 'error' => 'الحملة دي بالكريدت'];
    if (in_array($c['pkg_state'], ['cancelled', 'done'], true)) return ['ok' => false, 'error' => 'الحملة دي اتقفلت'];
    $lim = ca_campaign_limits($c);
    $p = $posts === null ? (int) $c['posts_target'] : max((int) $c['posts_used'], min($lim['posts'], max(0, $posts)));
    $v = $videos === null ? (int) $c['videos_target'] : max((int) $c['videos_used'], min($lim['videos'], max(0, $videos)));
    db_run('UPDATE campaigns SET posts_target = ?, videos_target = ? WHERE id = ?', [$p, $v, (int) $c['id']]);
    return ['ok' => true, 'posts_target' => $p, 'videos_target' => $v];
}

<?php
/**
 * Spread AI — «عرض البداية» (التجربة المجانية للحساب الجديد)
 *
 * بعد ما العميل يعتمد هويته في رحلة «ابدأ» (public/start.php) بياخد مرة واحدة:
 *   trial_contents محتوى (افتراضي 10) + trial_designs تصميم (افتراضي 4) لمدة trial_days يوم (افتراضي 14).
 *   - مرة واحدة لكل حساب ولكل رقم موبايل · مش للي عنده باقة شغالة أو اشترى قبل كده.
 *   - بيتسجّل كدورة في user_plans (source = trial · حصة التصميمات) + كريدت بيخلص مع آخر يوم.
 *   - تصميمات التجربة عليها العلامة المائية (حتى لو العلامة مقفولة للمنصة)، والأصل بيتحفظ
 *     وبيرجع تلقائي لما العميل يشترك في باقة من غير علامة مائية (plan_start ← trial_unwatermark).
 *   - بعد ما التجربة تخلص من غير اشتراك: يدخل ويشوف محتواه عادي، بس مفيش رصيد لإنتاج جديد.
 * الإعدادات من الأدمن: admin/onboarding-questions.php (قسم «عرض البداية»). مفيش جداول جديدة.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/credits.php';

function trial_enabled(): bool
{
    return get_setting('trial_on', '1') === '1';
}

/** إعدادات العرض (بحدود آمنة) */
function trial_cfg(): array
{
    return [
        'days'     => max(1, min(90, (int) get_setting('trial_days', '14'))),
        'contents' => max(0, min(100, (int) get_setting('trial_contents', '10'))),
        'designs'  => max(0, min(50, (int) get_setting('trial_designs', '4'))),
    ];
}

/** كريدت العرض = (محتوى × تكلفة الكتابة) + (تصميم × تكلفة التصميم) من إعدادات التكلفة الحالية */
function trial_credits(?array $cfg = null): int
{
    $cfg = $cfg ?? trial_cfg();
    return $cfg['contents'] * max(0, cost_for('content_generation_cost')) + $cfg['designs'] * max(0, cost_for('content_design_cost'));
}

/** دورة التجربة الشغالة دلوقتي (أو null) */
function trial_active_plan(int $uid): ?array
{
    try {
        return db_one('SELECT * FROM user_plans WHERE user_id = ? AND source = "trial" AND status = "active" AND ends_at > NOW() ORDER BY id DESC LIMIT 1', [$uid]) ?: null;
    } catch (\Throwable $e) {
        return null;
    }
}

function trial_active(int $uid): bool
{
    static $c = [];
    return $c[$uid] ??= (bool) trial_active_plan($uid);
}

/** أخد التجربة قبل كده (الحساب ده أو حساب تاني بنفس الموبايل)؟ */
function trial_taken(int $uid): bool
{
    try {
        if (db_one('SELECT id FROM user_plans WHERE user_id = ? AND source = "trial" LIMIT 1', [$uid])) return true;
        $u = db_one('SELECT phone FROM users WHERE id = ?', [$uid]);
        $vars = function_exists('onb_phone_variants') ? onb_phone_variants((string) ($u['phone'] ?? '')) : [];
        if (!$vars) return false;
        $ph = implode(',', array_fill(0, count($vars), '?'));
        return (bool) db_one("SELECT p.id FROM user_plans p JOIN users u ON u.id = p.user_id
                              WHERE p.source = 'trial' AND u.id <> ? AND u.phone IN ($ph) LIMIT 1", array_merge([$uid], $vars));
    } catch (\Throwable $e) {
        return true;   // لو فيه مشكلة في الجدول مانديش عرض مرتين
    }
}

/** العميل يستاهل العرض؟ جديد = ماخدش تجربة · مفيش باقة شغالة · ماشتراش قبل كده */
function trial_eligible(int $uid): bool
{
    if (!trial_enabled() || !function_exists('plans_ready') || !plans_ready() || trial_taken($uid)) return false;
    try {
        if (db_one('SELECT id FROM user_plans WHERE user_id = ? AND (source = "paid" OR (status = "active" AND ends_at > NOW())) LIMIT 1', [$uid])) return false;
    } catch (\Throwable $e) {
        return false;
    }
    return trial_cfg()['contents'] + trial_cfg()['designs'] > 0;
}

/**
 * تفعيل العرض (مرة واحدة) ← true لو اتفعّل دلوقتي
 */
function trial_grant(int $uid): bool
{
    if (!function_exists('plan_start')) require_once __DIR__ . '/plans.php';
    $lock = 'spread_trial_' . $uid;
    try {
        if ((int) (db_one('SELECT GET_LOCK(?, 5) l', [$lock])['l'] ?? 0) !== 1) return false;
    } catch (\Throwable $e) {
        return false;
    }
    $ok = false;
    try {
        if (trial_eligible($uid)) {
            $cfg = trial_cfg();
            $credits = trial_credits($cfg);
            $pid = plan_start($uid, null, $credits, $cfg['days'], 'trial', null, 'عرض البداية', ['quotas_json' => json_encode(['designs' => $cfg['designs']])]);
            if ($pid) {
                db_run('UPDATE user_plans SET name = ? WHERE id = ?', ['عرض البداية', $pid]);
                if ($credits > 0) credits_add($uid, $credits, 'عرض البداية: ' . $cfg['contents'] . ' محتوى + ' . $cfg['designs'] . ' تصميم', null, 'trial', $pid, $cfg['days'], ['source' => 'free', 'idem_key' => 'trial:' . $uid]);
                $ok = true;
                if (function_exists('plan_active')) plan_active($uid, true);
            }
        }
    } catch (\Throwable $e) {
        error_log('[trial] grant ' . $e->getMessage());
    }
    try { db_one('SELECT RELEASE_LOCK(?) l', [$lock]); } catch (\Throwable $e) {}
    return $ok;
}

/**
 * حالة العرض للعميل (للرئيسية وصفحة «ابدأ»)
 * @return array{state:string, days_left:int, ends:string, contents:array, designs:array}
 *   state: none (ماخدوش) · active · ended (خلص من غير اشتراك) · subscribed (اشترك بعده)
 */
function trial_status(int $uid): array
{
    $cfg = trial_cfg();
    $out = ['state' => 'none', 'days_left' => 0, 'ends' => '', 'contents' => ['used' => 0, 'total' => $cfg['contents']], 'designs' => ['used' => 0, 'total' => $cfg['designs']]];
    try {
        $p = db_one('SELECT * FROM user_plans WHERE user_id = ? AND source = "trial" ORDER BY id DESC LIMIT 1', [$uid]);
    } catch (\Throwable $e) {
        return $out;
    }
    if (!$p) return $out;
    $q = json_decode((string) $p['quotas_json'], true) ?: [];
    $out['designs']['total'] = (int) ($q['designs'] ?? $cfg['designs']);
    $out['ends'] = (string) $p['ends_at'];
    $to = date('Y-m-d H:i:s', max(time() + 60, strtotime((string) $p['ends_at'])));
    try {
        $out['contents']['used'] = (int) (db_one('SELECT COUNT(*) n FROM contents WHERE user_id = ? AND created_at >= ? AND created_at < ?', [$uid, $p['starts_at'], $to])['n'] ?? 0);
    } catch (\Throwable $e) {}
    if (function_exists('plan_used')) $out['designs']['used'] = plan_used($uid, 'designs', (string) $p['starts_at'], $to);
    $active = $p['status'] === 'active' && strtotime((string) $p['ends_at']) > time();
    if ($active) {
        $out['state'] = 'active';
        $out['days_left'] = max(0, (int) ceil((strtotime((string) $p['ends_at']) - time()) / 86400));
    } else {
        $after = null;
        try { $after = db_one('SELECT id FROM user_plans WHERE user_id = ? AND id > ? AND source <> "trial" LIMIT 1', [$uid, $p['id']]); } catch (\Throwable $e) {}
        $out['state'] = $after ? 'subscribed' : 'ended';
    }
    return $out;
}

/** مسارات تصميمات العميل من وقت معيّن (كل أماكن التصميم) */
function trial_design_paths(int $uid, string $since): array
{
    $paths = [];
    foreach (['content_designs', 'ce_designs', 'studio_designs'] as $t) {
        try {
            foreach (db_all("SELECT image_path FROM `$t` WHERE user_id = ? AND created_at >= ? AND image_path IS NOT NULL", [$uid, $since]) as $r) $paths[] = (string) $r['image_path'];
        } catch (\Throwable $e) {}
    }
    return array_values(array_unique(array_filter($paths)));
}

/**
 * شيل العلامة المائية من تصميمات التجربة: الأصل محفوظ في originals/ جنب الصورة ← يرجع مكانها
 * @return int عدد التصميمات اللي رجعت من غير علامة
 */
function trial_unwatermark(int $uid): int
{
    try {
        $p = db_one('SELECT starts_at FROM user_plans WHERE user_id = ? AND source = "trial" ORDER BY id ASC LIMIT 1', [$uid]);
    } catch (\Throwable $e) {
        return 0;
    }
    if (!$p || !defined('STORAGE_PATH')) return 0;
    $n = 0;
    $root = realpath(STORAGE_PATH);
    foreach (trial_design_paths($uid, (string) $p['starts_at']) as $rel) {
        $abs = realpath(STORAGE_PATH . '/' . ltrim($rel, '/'));
        if (!$abs || !$root || strpos($abs, $root) !== 0) continue;
        $orig = dirname($abs) . '/originals/' . basename($abs);
        if (is_file($orig) && @copy($orig, $abs)) {
            @unlink($orig);
            $n++;
        }
    }
    return $n;
}

/** بعد بداية دورة جديدة (من plan_start): اشتراك في باقة من غير علامة ← تصميمات التجربة ترجع نضيفة */
function trial_after_plan_start(int $uid, ?int $packageId, string $source): void
{
    if ($source === 'trial' || !$packageId) return;
    try {
        $pkg = db_one('SELECT watermark FROM credit_packages WHERE id = ?', [$packageId]);
        if ($pkg && array_key_exists('watermark', $pkg) && (int) $pkg['watermark'] === 0) trial_unwatermark($uid);
    } catch (\Throwable $e) {
        error_log('[trial] unwatermark ' . $e->getMessage());
    }
}

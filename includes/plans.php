<?php
/**
 * Spread AI v2 — المرحلة 8-ب: الباقة كحصص شهرية + الاستهلاك بالنسبة %
 *
 *  - الدورة (user_plans): بتبدأ مع شراء/تفعيل باقة وبتخلص مع صلاحية الكريدت
 *  - الحصص: منشورات · تصميمات · نشر · أبحاث · سكريبتات فيديو — بتتعد من المخرجات الناجحة فعلًا
 *    (المحاولة الفاشلة مابتعملش منشور/تصميم فمابتتحسبش على العميل)
 *  - العميل بيشوف نسب % بس (credits_display = percent) — الكريدت وحدة محاسبة داخلية
 *  - الحصة لما تخلص الخدمة بتقف برسالة ترقية (quotas_enforced = 1)
 */

require_once __DIR__ . '/db.php';

function plans_ready(): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        db_one('SELECT id FROM user_plans LIMIT 1');
        return $ok = true;
    } catch (\Throwable $e) {
        return $ok = false;
    }
}

function plans_setting(string $k, $d)
{
    if (!function_exists('get_setting')) return $d;
    try { $v = get_setting($k, null); } catch (\Throwable $e) { $v = null; }
    return ($v === null || $v === '') ? $d : $v;
}

/** وحدات الحصص: key => [emoji, label, en] */
function plan_units(): array
{
    return [
        'posts'     => ['📝', 'منشورات', 'Posts'],
        'designs'   => ['🎨', 'تصميمات', 'Designs'],
        'publishes' => ['📤', 'نشر', 'Publishing'],
        'research'  => ['🔬', 'أبحاث', 'Research'],
        'videos'    => ['🎬', 'سكريبتات فيديو', 'Video scripts'],
    ];
}

/** العميل بيشوف أرقام الكريدت؟ (percent = لأ، نسب بس) */
function credits_show_numbers(): bool
{
    if (!plans_ready()) return true; // قبل ترحيل 8-ب: العرض القديم زي ما هو
    return (string) plans_setting('credits_display', 'percent') === 'visible';
}

/** حصص الباقة من JSON → [unit => limit] (0 = مفتوح) */
function plan_quotas_decode(?string $json): array
{
    $q = json_decode((string) $json, true);
    $out = [];
    foreach (array_keys(plan_units()) as $u) {
        $out[$u] = is_array($q) ? max(0, (int) ($q[$u] ?? 0)) : 0;
    }
    return $out;
}

/**
 * الدورة الحالية للعميل.
 * لو مفيش دورة مسجّلة (عملاء قبل 8-ب / رصيد مجاني): دورة من آخر شحن لحد انتهاء الصلاحية ومن غير حصص.
 */
function plan_active(int $uid): array
{
    static $cache = [];
    if (isset($cache[$uid])) return $cache[$uid];
    if (plans_ready()) {
        try {
            $r = db_one('SELECT * FROM user_plans WHERE user_id = ? AND status = "active" AND ends_at > NOW() ORDER BY id DESC LIMIT 1', [$uid]);
            if ($r) {
                return $cache[$uid] = [
                    'id' => (int) $r['id'], 'name' => (string) $r['name'], 'package_id' => $r['package_id'] ? (int) $r['package_id'] : null,
                    'source' => (string) $r['source'], 'starts' => (string) $r['starts_at'], 'ends' => (string) $r['ends_at'],
                    'allowance' => (int) $r['credits_allowance'], 'quotas' => plan_quotas_decode($r['quotas_json']), 'synthetic' => false,
                ];
            }
        } catch (\Throwable $e) {
        }
    }
    $w = null;
    try { $w = db_one('SELECT balance, expires_at FROM credit_wallets WHERE user_id = ?', [$uid]); } catch (\Throwable $e) {}
    $ends = !empty($w['expires_at']) ? (string) $w['expires_at'] : date('Y-m-d H:i:s', strtotime('+30 days'));
    // الدورة خلصت بس الرصيد لسه صالح (كريدت اتضاف بعدها): امتداد بنفس حصص آخر باقة — الحصص ماتفتحش لوحدها
    if (plans_ready() && (int) ($w['balance'] ?? 0) > 0 && strtotime($ends) > time()) {
        try {
            $lp = db_one('SELECT * FROM user_plans WHERE user_id = ? AND status IN ("active","ended") AND ends_at <= NOW() ORDER BY ends_at DESC LIMIT 1', [$uid]);
            if ($lp && array_filter(plan_quotas_decode($lp['quotas_json']))) {
                return $cache[$uid] = [
                    'id' => null, 'name' => (string) $lp['name'], 'package_id' => $lp['package_id'] ? (int) $lp['package_id'] : null,
                    'source' => 'extension', 'starts' => (string) $lp['ends_at'], 'ends' => $ends,
                    'allowance' => 0, 'quotas' => plan_quotas_decode($lp['quotas_json']), 'synthetic' => true, 'extension' => true,
                ];
            }
        } catch (\Throwable $e) {}
    }
    $last = null;
    try {
        $last = db_one('SELECT MAX(created_at) t FROM credit_transactions WHERE user_id = ? AND action_type = "add" AND COALESCE(reference_type, "") <> "refund"', [$uid]);
    } catch (\Throwable $e) {}
    $starts = !empty($last['t']) ? (string) $last['t'] : date('Y-m-d H:i:s', strtotime($ends . ' -30 days'));
    $name = function_exists('account_plan_label') ? account_plan_label($uid) : 'رصيد مجاني';
    return $cache[$uid] = ['id' => null, 'name' => $name, 'package_id' => null, 'source' => 'legacy', 'starts' => $starts, 'ends' => $ends,
        'allowance' => 0, 'quotas' => plan_quotas_decode(null), 'synthetic' => true];
}

/** المستهلك من وحدة في فترة (من المخرجات الفعلية) */
function plan_used(int $uid, string $unit, string $from, string $to): int
{
    try {
        switch ($unit) {
            case 'posts':
                try {
                    return (int) (db_one('SELECT COUNT(*) n FROM contents WHERE user_id = ? AND created_at >= ? AND created_at < ? AND credits_used > 0 AND COALESCE(format, "post") <> "video"', [$uid, $from, $to])['n'] ?? 0);
                } catch (\Throwable $e) {
                    return (int) (db_one('SELECT COUNT(*) n FROM contents WHERE user_id = ? AND created_at >= ? AND created_at < ? AND credits_used > 0', [$uid, $from, $to])['n'] ?? 0);
                }
            case 'videos':
                try {
                    return (int) (db_one('SELECT COUNT(*) n FROM contents WHERE user_id = ? AND created_at >= ? AND created_at < ? AND credits_used > 0 AND format = "video"', [$uid, $from, $to])['n'] ?? 0);
                } catch (\Throwable $e) {
                    return 0;
                }
            case 'designs':
                $n = (int) (db_one('SELECT COUNT(*) n FROM content_designs WHERE user_id = ? AND created_at >= ? AND created_at < ? AND credits_used > 0', [$uid, $from, $to])['n'] ?? 0);
                try {
                    $n += (int) (db_one('SELECT COUNT(*) n FROM studio_designs WHERE user_id = ? AND created_at >= ? AND created_at < ?', [$uid, $from, $to])['n'] ?? 0);
                } catch (\Throwable $e) {}
                return $n;
            case 'research':
                // كل تشغيل (بحث جديد أو تحديث) بيتخصم عليه — ناقص اللي اترد لأنه فشل (الاسترجاع مربوط بعملية الخصم نفسها)
                return (int) (db_one('SELECT COUNT(*) n FROM credit_transactions c WHERE c.user_id = ? AND c.action_type = "consume" AND c.reference_type = "research"
                                      AND c.created_at >= ? AND c.created_at < ?
                                      AND NOT EXISTS (SELECT 1 FROM credit_transactions r WHERE r.user_id = c.user_id AND r.reference_type = "refund"
                                                      AND r.idem_key LIKE CONCAT("rf:", c.id, ":%"))', [$uid, $from, $to])['n'] ?? 0);
            case 'publishes':
                // النشر اللي اتلغى أو فشل نهائيًا من غير ما يتنشر مابيتحسبش (من أي مسار: إلغاء · سحب صلاحية · فصل صفحة)
                return (int) (db_one('SELECT COUNT(*) n FROM usage_events e LEFT JOIN contents c ON c.id = e.ref_id
                                      WHERE e.user_id = ? AND e.unit = "publishes" AND e.created_at >= ? AND e.created_at < ?
                                        AND (c.id IS NULL OR c.published_at IS NOT NULL OR COALESCE(c.publish_status, "") NOT IN ("failed", "cancelled", "draft"))', [$uid, $from, $to])['n'] ?? 0);
        }
    } catch (\Throwable $e) {
    }
    return 0;
}

/** الحد الفعلي لكل وحدة = حصة الباقة + الزيادات السارية · null = مفتوح (الباقة مالهاش حد للخدمة دي) */
function plan_limits(int $uid, array $plan): array
{
    $lim = [];
    foreach ($plan['quotas'] as $k => $v) $lim[$k] = (int) $v > 0 ? (int) $v : null;
    if (plans_ready()) {
        try {
            foreach (db_all('SELECT unit, SUM(extra) x FROM quota_overrides WHERE user_id = ? AND (expires_at IS NULL OR expires_at > NOW()) GROUP BY unit', [$uid]) as $o) {
                if (array_key_exists($o['unit'], $lim) && $lim[$o['unit']] !== null) $lim[$o['unit']] = max(0, $lim[$o['unit']] + (int) $o['x']);
            }
        } catch (\Throwable $e) {}
    }
    return $lim;
}

/**
 * الاستهلاك الكامل للدورة الحالية
 * @return array plan · credits[used,total,balance,pct] · units[] · days_left · pct (نسبة الباقة الإجمالية)
 */
function plan_usage(int $uid, bool $fresh = false): array
{
    static $cache = [];
    if (!$fresh && isset($cache[$uid])) return $cache[$uid];
    $plan = plan_active($uid);
    $from = $plan['starts'];
    $to = date('Y-m-d H:i:s', max(time() + 60, strtotime($plan['ends'])));
    $balance = function_exists('credits_balance') ? credits_balance($uid) : 0;
    $used = 0;
    try {
        $r = db_one('SELECT COALESCE(SUM(IF(action_type = "consume", amount, 0)),0) c,
                            COALESCE(SUM(IF(action_type = "add" AND reference_type = "refund", amount, 0)),0) r
                     FROM credit_transactions WHERE user_id = ? AND created_at >= ?', [$uid, $from]);
        $used = max(0, (int) ($r['c'] ?? 0) - (int) ($r['r'] ?? 0));
    } catch (\Throwable $e) {}
    $total = $used + $balance;
    $cpct = $total > 0 ? (int) min(100, round($used / $total * 100)) : 0;
    $lim = plan_limits($uid, $plan);
    $units = [];
    foreach (plan_units() as $k => [$emo, $lbl, $en]) {
        $u = plan_used($uid, $k, $from, $to);
        $l = $lim[$k] ?? null;
        $units[] = ['key' => $k, 'emoji' => $emo, 'label' => $lbl, 'en' => $en, 'limit' => (int) $l, 'open' => $l === null, 'used' => $u,
            'pct' => $l === null ? null : ($l > 0 ? (int) min(100, round($u / $l * 100)) : 100), 'left' => $l === null ? null : max(0, $l - $u)];
    }
    $daysLeft = max(0, (int) ceil((strtotime($plan['ends']) - time()) / 86400));
    return $cache[$uid] = [
        'plan' => $plan, 'credits' => ['used' => $used, 'total' => $total, 'balance' => $balance, 'pct' => $cpct],
        'units' => $units, 'days_left' => $daysLeft, 'pct' => $cpct,
        'ends_label' => function_exists('fmt_date') ? fmt_date(substr($plan['ends'], 0, 10)) : substr($plan['ends'], 0, 10),
    ];
}

/**
 * بوابة الحصة قبل الخدمة: null = مسموح · نص = رسالة المنع
 */
function plan_gate(int $uid, string $unit, int $n = 1): ?string
{
    if ((string) plans_setting('quotas_enforced', '1') !== '1' || !plans_ready()) return null;
    $plan = plan_active($uid);
    $lim = plan_limits($uid, $plan);
    $l = $lim[$unit] ?? null;
    if ($l === null) return null; // مفتوح
    $to = date('Y-m-d H:i:s', max(time() + 60, strtotime($plan['ends'])));
    $used = plan_used($uid, $unit, $plan['starts'], $to);
    // قرّب الحد: طلبات متوازية لنفس الخدمة تستنى بعض (القفل بيفضل لحد آخر الطلب)، وبعدين نعدّ تاني
    if ($l - $used <= 10 && $used + $n <= $l) {
        static $held = [];
        $key = 'spread_q_' . $uid . '_' . $unit;
        if (empty($held[$key])) {
            try {
                $got = (int) (db_one('SELECT GET_LOCK(?, 25) l', [$key])['l'] ?? 0);
                if ($got !== 1) return 'فيه طلب تاني شغال على نفس الخدمة — استنى لحظة وجرّب تاني.';
                $held[$key] = true;
                register_shutdown_function(static function () use ($key) { try { db_one('SELECT RELEASE_LOCK(?) l', [$key]); } catch (\Throwable $e) {} });
            } catch (\Throwable $e) {}
            $used = plan_used($uid, $unit, $plan['starts'], $to);
        }
    }
    if ($used + $n <= $l) return null;
    $lbl = plan_units()[$unit][1] ?? $unit;
    if ($used < $l) {
        return 'فاضل ' . ($l - $used) . ' بس من «' . $lbl . '» في باقة الشهر — قلّل العدد أو رقّي باقتك.';
    }
    return 'خلّصت «' . $lbl . '» في باقة الشهر — رقّي باقتك أو كلمنا واتساب، والباقة بتتجدد يوم ' . plan_usage($uid)['ends_label'] . '.';
}

/** رسالة «مش كفاية» حسب طريقة العرض */
function credits_short_msg(int $need = 0, string $what = ''): string
{
    if (credits_show_numbers()) {
        return 'رصيدك مش كفاية' . ($what !== '' ? ' — ' . $what : '') . ($need > 0 ? ' — محتاج ' . $need . ' كريدت' : '');
    }
    return 'باقة الشهر خلصت — رقّي باقتك أو كلمنا واتساب علشان تكمّل.';
}

/** لينك الترقية/التواصل */
function plan_upgrade_link(): string
{
    $num = preg_replace('/\D+/', '', (string) plans_setting('whatsapp_float', plans_setting('pay_whatsapp', '')));
    if ($num !== '') {
        return 'https://wa.me/' . $num . '?text=' . rawurlencode((string) plans_setting('upgrade_whatsapp_msg', 'السلام عليكم، عايز أرقّي باقتي في Spread AI'));
    }
    return function_exists('url') ? url('credits.php') : 'credits.php';
}

/** العنصر ده اتحسب قبل كده؟ (النشر: المنشور الواحد مرة واحدة) */
function plan_counted(int $uid, string $unit, int $refId): bool
{
    if (!plans_ready()) return false;
    try {
        if ($unit === 'publishes') {
            // اتحسب ولسه «ماشي» (مش ملغي/فاشل) — الملغي لما يتعاد نشره بيعدي على الحصة تاني
            return (bool) db_one('SELECT e.id FROM usage_events e LEFT JOIN contents c ON c.id = e.ref_id WHERE e.user_id = ? AND e.unit = ? AND e.ref_id = ?
                                  AND (c.id IS NULL OR c.published_at IS NOT NULL OR COALESCE(c.publish_status, "") NOT IN ("failed", "cancelled", "draft"))', [$uid, $unit, $refId]);
        }
        return (bool) db_one('SELECT id FROM usage_events WHERE user_id = ? AND unit = ? AND ref_id = ?', [$uid, $unit, $refId]);
    } catch (\Throwable $e) {
        return false;
    }
}

/** تسجيل حدث استهلاك (مرة واحدة لكل عنصر) — للنشر */
function plan_event(int $uid, string $unit, int $refId): void
{
    if (!plans_ready()) return;
    try {
        db_run('INSERT INTO usage_events (user_id, unit, ref_id) VALUES (?,?,?) ON DUPLICATE KEY UPDATE created_at = IF(created_at < DATE_SUB(NOW(), INTERVAL 1 DAY), NOW(), created_at)', [$uid, $unit, $refId]);
    } catch (\Throwable $e) {}
}

/** رجوع حدث استهلاك (النشر اتلغى أو فشل نهائيًا قبل ما يتنشر) */
function plan_event_release(int $uid, string $unit, int $refId): void
{
    if (!plans_ready()) return;
    try {
        if ($unit === 'publishes' && db_one('SELECT id FROM contents WHERE id = ? AND published_at IS NOT NULL', [$refId])) return;
        db_run('DELETE FROM usage_events WHERE user_id = ? AND unit = ? AND ref_id = ?', [$uid, $unit, $refId]);
    } catch (\Throwable $e) {}
}

/**
 * بداية دورة جديدة (شراء/تفعيل باقة). الدورة القديمة بتتقفل كـ replaced.
 * @return int|null id الدورة
 */
function plan_start(int $uid, ?int $packageId, int $credits, ?int $days = null, string $source = 'paid', ?int $by = null, ?string $note = null): ?int
{
    if (!plans_ready()) return null;
    try {
        $pkg = $packageId ? db_one('SELECT * FROM credit_packages WHERE id = ?', [$packageId]) : null;
        $days = max(1, (int) ($days ?: ($pkg['validity_days'] ?? plans_setting('default_credit_validity_days', 30))));
        $name = $pkg ? (string) $pkg['name'] : 'باقة مخصصة';
        db_run('UPDATE user_plans SET status = "replaced" WHERE user_id = ? AND status = "active"', [$uid]);
        $id = db_insert(
            'INSERT INTO user_plans (user_id, package_id, name, source, starts_at, ends_at, credits_allowance, quotas_json, note, created_by)
             VALUES (?,?,?,?, NOW(), DATE_ADD(NOW(), INTERVAL ? DAY), ?, ?, ?, ?)',
            [$uid, $packageId, $name, $source, $days, $credits, $pkg['quotas_json'] ?? null, $note ? mb_substr($note, 0, 255) : null, $by]
        );
        return $id ?: null;
    } catch (\Throwable $e) {
        error_log('[plans] start ' . $e->getMessage());
        return null;
    }
}

/**
 * مؤشرات العميل بقواعد معلنة (بتتظبط من «العملاء»)
 * @return array [[key, label, level warn|bad|info, why]]
 */
function customer_signals(int $uid, ?array $usage = null): array
{
    $out = [];
    $usage = $usage ?? plan_usage($uid);
    $inact = max(1, (int) plans_setting('health_inactive_days', 7));
    $high = max(1, (int) plans_setting('health_usage_high', 85));
    $renew = max(1, (int) plans_setting('health_renew_days', 3));
    try {
        $last = db_one('SELECT GREATEST(COALESCE((SELECT MAX(created_at) FROM contents WHERE user_id = ?), "1970-01-01"),
                                        COALESCE((SELECT MAX(created_at) FROM credit_transactions WHERE user_id = ? AND action_type = "consume"), "1970-01-01")) t', [$uid, $uid]);
        $t = strtotime((string) ($last['t'] ?? '1970-01-01'));
        if ($t < time() - $inact * 86400) {
            $out[] = ['inactive', 'مهدد بالإلغاء', 'bad', $t > 86400 ? 'آخر نشاط من ' . (int) floor((time() - $t) / 86400) . ' يوم (القاعدة: ' . $inact . ' أيام)' : 'مفيش أي نشاط لسه'];
        }
    } catch (\Throwable $e) {}
    $maxUnit = 0;
    foreach ($usage['units'] as $u) if ($u['pct'] !== null) $maxUnit = max($maxUnit, $u['pct']);
    $peak = max($usage['pct'], $maxUnit);
    if ($peak >= $high) $out[] = ['near_limit', 'قرّب يخلص باقته', 'warn', 'استهلك ' . $peak . '% (القاعدة: ' . $high . '%) — فرصة ترقية'];
    if (!$usage['plan']['synthetic'] || $usage['credits']['balance'] > 0) {
        if ($usage['days_left'] <= $renew) $out[] = ['renew', 'التجديد قريب', 'info', 'فاضل ' . $usage['days_left'] . ' يوم (القاعدة: ' . $renew . ' أيام)'];
    }
    try {
        $bad = (int) (db_one('SELECT COUNT(*) n FROM social_connections WHERE user_id = ? AND (status IN ("expired","error") OR (expires_at IS NOT NULL AND expires_at < NOW()))', [$uid])['n'] ?? 0);
        if ($bad) $out[] = ['social', 'ربط سوشيال منتهي', 'warn', $bad . ' صفحة محتاجة ربط تاني'];
    } catch (\Throwable $e) {}
    try {
        $f = (int) (db_one('SELECT COUNT(*) n FROM ai_runs WHERE user_id = ? AND status = "failed" AND created_at > DATE_SUB(NOW(), INTERVAL 1 DAY)', [$uid])['n'] ?? 0);
        if ($f >= 3) $out[] = ['ai_fail', 'أعطال AI متكررة', 'warn', $f . ' عملية فشلت في آخر 24 ساعة'];
    } catch (\Throwable $e) {}
    try {
        $j = db_one('SELECT id FROM campaigns WHERE user_id = ? LIMIT 1', [$uid]);
        if (!$j) $out[] = ['no_campaign', 'مابدأش أول حملة', 'info', 'مخلّصش «رحلتك الأولى»'];
    } catch (\Throwable $e) {}
    return $out;
}

<?php
/**
 * Spread AI v2 — المرحلة 8-أ: الاستهلاك والتكلفة الفعلية + تنبيهات الأدمن
 *
 *  - ai_runs      : العملية اللي العميل طلبها (منشور · تصميم · بحث …)
 *  - ai_attempts  : كل محاولة عند مزود — ناجحة أو فاشلة — بتكلفتها وسعر الصرف وقتها
 *  - التكلفة      : provider (المزود بلّغ بيها) · calculated (توكنز × سعر بتاريخ السريان) · estimated (تقدير)
 *  - الإيراد      : من حركات الكريدت (credit_lots) — المدفوع بس ليه إيراد، المجاني إيراده صفر
 *  - كل الأرقام بتتحفظ وقت العملية: تغيير السعر أو سعر الدولار مايغيّرش الحسابات القديمة
 *
 * كل الدوال «أفضل مجهود»: أي خطأ هنا مايوقفش توليد العميل أبدًا.
 */

require_once __DIR__ . '/db.php';

/** الجداول موجودة؟ (قبل تشغيل الترحيل كل حاجة هنا بتتجاهل بهدوء) */
function usage_ready(): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        db_one('SELECT id FROM ai_runs LIMIT 1');
        return $ok = true;
    } catch (\Throwable $e) {
        return $ok = false;
    }
}

function usage_setting(string $key, $default)
{
    if (!function_exists('get_setting')) return $default;
    try { $v = get_setting($key, null); } catch (\Throwable $e) { $v = null; }
    return ($v === null || $v === '') ? $default : $v;
}

/** رقم ثابت لطلب الـ HTTP الحالي — بيربط عمليات الـ AI بحركات الكريدت اللي حصلت في نفس الطلب */
function usage_request_id(): string
{
    static $id = null;
    if ($id === null) {
        $id = bin2hex(random_bytes(8));
    }
    return $id;
}

/** اسم الميزة من الصفحة اللي نادت (generate-design · campaign-flow …) */
function usage_feature(): string
{
    $s = (string) ($_SERVER['SCRIPT_NAME'] ?? ($_SERVER['argv'][0] ?? 'cli'));
    return mb_substr(preg_replace('/\.php$/', '', basename($s)) ?: 'cli', 0, 60);
}

/** نوع السجل القديم (kind) → مهمة البوابة */
function usage_task_for_kind(string $kind): string
{
    $k = strtolower(trim($kind));
    $map = [
        'content' => 'content', 'campaign_content' => 'content', 'custom' => 'content', 'regenerate' => 'content',
        'ideas' => 'ideas', 'campaign_ideas' => 'ideas', 'studio' => 'ideas', 'plan_ideas' => 'ideas',
        'campaign_eval' => 'eval', 'eval' => 'eval',
        'brand' => 'brand', 'visual_identity' => 'brand', 'source_summary' => 'brand', 'brand_agent' => 'brand',
        'research_analyze' => 'research_analyze', 'research_opps' => 'research_analyze',
        'research_search' => 'research_search',
        'design' => 'design', 'logo' => 'design', 'image' => 'design', 'studio_design' => 'design',
    ];
    return $map[$k] ?? 'general';
}

/** قدرة المهمة */
function usage_task_capability(string $task): string
{
    return ['design' => 'image', 'research_search' => 'web'][$task] ?? 'text';
}

/* ═══════════ سعر الدولار ═══════════ */

/** سعر الدولار الساري في لحظة معينة (الافتراضي: دلوقتي) */
function usage_fx_rate(?string $at = null): float
{
    static $cache = [];
    $key = $at ?? 'now';
    if (isset($cache[$key])) return $cache[$key];
    $rate = 0.0;
    try {
        $row = db_one(
            'SELECT rate_egp FROM fx_rates WHERE currency = "USD" AND effective_from <= ? ORDER BY effective_from DESC, id DESC LIMIT 1',
            [$at ?? date('Y-m-d H:i:s')]
        );
        $rate = (float) ($row['rate_egp'] ?? 0);
    } catch (\Throwable $e) {
    }
    if ($rate <= 0) $rate = (float) usage_setting('fx_default_usd_egp', 50);
    return $cache[$key] = $rate;
}

/* ═══════════ الأسعار ═══════════ */

/** أسماء بديلة للموديل: «openai/gpt-4o-mini» ↔ «gpt-4o-mini» · بدون «:free» */
function usage_model_candidates(string $model): array
{
    $m = strtolower(trim($model));
    $out = [$m];
    $base = preg_replace('/:[a-z0-9._-]+$/', '', $m);
    $out[] = $base;
    if (str_contains($base, '/')) $out[] = substr($base, strrpos($base, '/') + 1);
    return array_values(array_unique(array_filter($out)));
}

/** سعر وحدة ساري دلوقتي: ['id','price_usd'] أو null */
function usage_price(string $provider, string $model, string $unit, string $size = ''): ?array
{
    static $cache = [];
    $ck = "$provider|$model|$unit|$size";
    if (array_key_exists($ck, $cache)) return $cache[$ck];
    $cands = usage_model_candidates($model);
    if (!$cands) return $cache[$ck] = null;
    try {
        $in = implode(',', array_fill(0, count($cands), '?'));
        $row = db_one(
            "SELECT id, price_usd FROM ai_prices
             WHERE LOWER(model) IN ($in) AND unit = ? AND effective_from <= NOW()
               AND (provider = ? OR provider = '*') AND (size = ? OR size = '')
             ORDER BY (provider = ?) DESC, (size = ?) DESC, FIELD(LOWER(model), $in) ASC, effective_from DESC, id DESC LIMIT 1",
            array_merge($cands, [$unit, $provider, $size, $provider, $size], $cands)
        );
        return $cache[$ck] = $row ? ['id' => (int) $row['id'], 'price_usd' => (float) $row['price_usd']] : null;
    } catch (\Throwable $e) {
        return $cache[$ck] = null;
    }
}

/**
 * تكلفة محاولة واحدة
 * @param array $a provider, model, capability, tokens_in, tokens_out, images, size, provider_cost (nullable), requests
 * @return array [cost_usd, source, price_ref]
 */
function usage_cost(array $a): array
{
    $provider = (string) ($a['provider'] ?? '');
    $model = (string) ($a['model'] ?? '');
    $tin = (int) ($a['tokens_in'] ?? 0);
    $tout = (int) ($a['tokens_out'] ?? 0);
    $imgs = (int) ($a['images'] ?? 0);
    $reqs = (int) ($a['requests'] ?? 0);

    // 1) المزود بلّغ بالتكلفة الفعلية (OpenRouter)
    if (isset($a['provider_cost']) && $a['provider_cost'] !== null && (float) $a['provider_cost'] > 0) {
        return [round((float) $a['provider_cost'], 6), 'provider', null];
    }
    if ($tin === 0 && $tout === 0 && $imgs === 0 && $reqs === 0) {
        return [0.0, 'none', null];
    }

    // 2) محسوبة من جدول الأسعار
    $cost = 0.0;
    $refs = [];
    $missing = false;
    // الصور: لو ليها سعر بالصورة → ده التسعير (التوكنز جواه) — غير كده بالتوكنز لو ليها سعر
    $pimg = $imgs > 0 ? usage_price($provider, $model, 'image', (string) ($a['size'] ?? '')) : null;
    if ($pimg) {
        $tin = 0;
        $tout = 0;
    }
    if ($tin > 0 || $tout > 0) {
        $pin = usage_price($provider, $model, 'in_1m');
        $pout = usage_price($provider, $model, 'out_1m');
        if ($pin && $pout) {
            $cost += $tin / 1e6 * $pin['price_usd'] + $tout / 1e6 * $pout['price_usd'];
            $refs[] = $pin['id'];
            $refs[] = $pout['id'];
        } else {
            $missing = true;
            $cost += $tin / 1e6 * (float) usage_setting('ai_est_text_in_1m', 0.5)
                   + $tout / 1e6 * (float) usage_setting('ai_est_text_out_1m', 1.5);
        }
    }
    if ($imgs > 0) {
        if ($pimg) {
            $cost += $imgs * $pimg['price_usd'];
            $refs[] = $pimg['id'];
        } else {
            $missing = true;
            $cost += $imgs * (float) usage_setting('ai_est_image', 0.05);
        }
    }
    if ($reqs > 0) {
        $preq = usage_price($provider, $model, 'request');
        if ($preq) {
            $cost += $reqs * $preq['price_usd'];
            $refs[] = $preq['id'];
        }
    }
    return [round($cost, 6), $missing ? 'estimated' : 'calculated', $refs ? implode(',', array_unique($refs)) : null];
}

/* ═══════════ العمليات والمحاولات ═══════════ */

/** بداية عملية — بيرجع id أو null */
function usage_run_start(array $r): ?int
{
    if (!usage_ready()) return null;
    try {
        $key = (string) ($r['run_key'] ?? bin2hex(random_bytes(16)));
        // مزايا كتير مابتبعتش رقم العميل في السياق → من الجلسة (علشان التكلفة والكريدت يتنسبوا له)
        if (empty($r['user_id']) && !empty($_SESSION['user_id']) && ($r['feature'] ?? '') !== 'admin_test') {
            $r['user_id'] = (int) $_SESSION['user_id'];
        }
        $id = db_insert(
            'INSERT INTO ai_runs (run_key, request_id, user_id, task, feature, route, ref_type, ref_id, status, fx_rate, created_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,NOW())',
            [
                $key, usage_request_id(),
                isset($r['user_id']) && $r['user_id'] ? (int) $r['user_id'] : null,
                mb_substr((string) ($r['task'] ?? 'general'), 0, 40),
                mb_substr((string) ($r['feature'] ?? usage_feature()), 0, 60),
                mb_substr((string) ($r['route'] ?? 'gateway'), 0, 12),
                isset($r['ref_type']) ? mb_substr((string) $r['ref_type'], 0, 40) : null,
                isset($r['ref_id']) && $r['ref_id'] !== null ? (int) $r['ref_id'] : null,
                'running',
                usage_fx_rate(),
            ]
        );
        if ($id) {
            $GLOBALS['__usage_runs'][] = (int) $id;
            usage_register_shutdown();
        }
        return $id ? (int) $id : null;
    } catch (\Throwable $e) {
        error_log('[usage] run_start ' . $e->getMessage());
        return null;
    }
}

/**
 * تسجيل محاولة (بيحسب التكلفة بالسعر وسعر الصرف الساريين دلوقتي)
 * @return float التكلفة بالدولار
 */
function usage_attempt(?int $runId, array $a): float
{
    if (!$runId || !usage_ready()) return 0.0;
    try {
        [$cost, $src, $ref] = usage_cost($a);
        // طلب اترفض (4xx: مفتاح/باراميتر/حد) مابيتحاسبش عليه عند المزود — تكلفته صفر
        $hc = (int) ($a['http_code'] ?? 0);
        if (($a['status'] ?? 'ok') !== 'ok' && $hc >= 400 && $hc < 500 && $src !== 'provider') {
            [$cost, $src, $ref] = [0.0, 'none', null];
        }
        $fx = usage_fx_rate();
        db_run(
            'INSERT INTO ai_attempts (run_id, seq, provider, model, capability, status, error_class, http_code, error,
                tokens_in, tokens_out, images_count, image_size, cost_usd, cost_source, price_ref, fx_rate, cost_egp, duration_ms, created_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())',
            [
                $runId, (int) ($a['seq'] ?? 1),
                mb_substr((string) ($a['provider'] ?? ''), 0, 60) ?: null,
                mb_substr((string) ($a['model'] ?? ''), 0, 120) ?: null,
                mb_substr((string) ($a['capability'] ?? 'text'), 0, 10),
                ($a['status'] ?? 'ok') === 'ok' ? 'ok' : 'failed',
                isset($a['error_class']) ? mb_substr((string) $a['error_class'], 0, 20) : null,
                isset($a['http_code']) && $a['http_code'] ? (int) $a['http_code'] : null,
                isset($a['error']) && $a['error'] !== null ? mb_substr((string) $a['error'], 0, 300) : null,
                (int) ($a['tokens_in'] ?? 0), (int) ($a['tokens_out'] ?? 0), (int) ($a['images'] ?? 0),
                isset($a['size']) && $a['size'] ? mb_substr((string) $a['size'], 0, 12) : null,
                $cost, $src, $ref, $fx, round($cost * $fx, 4), (int) ($a['duration_ms'] ?? 0),
            ]
        );
        return $cost;
    } catch (\Throwable $e) {
        error_log('[usage] attempt ' . $e->getMessage());
        return 0.0;
    }
}

/** إقفال العملية: يجمّع المحاولات (التكلفة الكاملة = الناجحة + الفاشلة) */
function usage_run_finish(?int $runId, array $f): void
{
    if (!$runId || !usage_ready()) return;
    try {
        $agg = db_one(
            'SELECT COUNT(*) n, SUM(status = "failed") nf, COALESCE(SUM(cost_usd),0) c, COALESCE(SUM(cost_egp),0) ce,
                    COALESCE(SUM(IF(status = "failed", cost_usd, 0)),0) cf,
                    COUNT(DISTINCT cost_source) ns, MAX(cost_source) src,
                    COUNT(DISTINCT CONCAT(IFNULL(provider,""), "|", IFNULL(model,""))) nt
             FROM ai_attempts WHERE run_id = ?',
            [$runId]
        ) ?: [];
        $okAtt = db_one('SELECT provider, model, tokens_in, tokens_out, images_count FROM ai_attempts WHERE run_id = ? AND status = "ok" ORDER BY id DESC LIMIT 1', [$runId]);
        $last = $okAtt ?: db_one('SELECT provider, model FROM ai_attempts WHERE run_id = ? ORDER BY id DESC LIMIT 1', [$runId]);
        $src = (int) ($agg['ns'] ?? 0) > 1 ? 'mixed' : (string) ($agg['src'] ?? 'none');
        // «none» مع «calculated» = calculated
        if ($src === 'mixed') {
            $srcs = array_column(db_all('SELECT DISTINCT cost_source s FROM ai_attempts WHERE run_id = ? AND cost_source <> "none"', [$runId]), 's');
            $src = count($srcs) === 1 ? $srcs[0] : (count($srcs) === 0 ? 'none' : 'mixed');
        }
        db_run(
            'UPDATE ai_runs SET status = ?, attempts = ?, failovers = ?, provider = ?, model = ?, tokens_in = ?, tokens_out = ?, images_count = ?,
                cost_usd = ?, cost_failed_usd = ?, cost_egp = ?, cost_source = ?, error_class = ?, error = ?, duration_ms = ?, finished_at = NOW()
             WHERE id = ?',
            [
                in_array($f['status'] ?? 'ok', ['ok', 'failed', 'refused'], true) ? $f['status'] : 'failed',
                (int) ($agg['n'] ?? 0),
                max(0, (int) ($agg['nt'] ?? 1) - 1),
                $last['provider'] ?? null, $last['model'] ?? null,
                (int) ($okAtt['tokens_in'] ?? 0), (int) ($okAtt['tokens_out'] ?? 0), (int) ($okAtt['images_count'] ?? 0),
                (float) ($agg['c'] ?? 0), (float) ($agg['cf'] ?? 0), (float) ($agg['ce'] ?? 0), $src,
                isset($f['error_class']) ? mb_substr((string) $f['error_class'], 0, 20) : null,
                isset($f['error']) && $f['error'] !== null ? mb_substr((string) $f['error'], 0, 300) : null,
                (int) ($f['duration_ms'] ?? 0),
                $runId,
            ]
        );
    } catch (\Throwable $e) {
        error_log('[usage] run_finish ' . $e->getMessage());
    }
}

/** تصنيف خطأ المسار القديم (http_429 · curl · empty …) */
function usage_legacy_error_class(?string $err, ?int $http = null): ?string
{
    if ($err === null && !$http) return null;
    $e = strtolower((string) $err);
    if (preg_match('/http_(\d{3})/', $e, $m)) $http = (int) $m[1];
    if ($http) return usage_http_class($http, $e);
    if (str_contains($e, 'curl') || str_contains($e, 'timeout')) return 'network';
    if (str_contains($e, 'empty')) return 'empty';
    return 'server';
}

/** HTTP → نوع الخطأ */
function usage_http_class(int $http, string $body = ''): string
{
    $b = strtolower($body);
    // الرسالة الأول: رفض سياسة (OpenRouter moderation بيرجع 403) · مفتاح غلط (Gemini بيرجع 400)
    if (in_array($http, [400, 403, 422], true)
        && preg_match('/content[_ ]?policy|moderation|flagged|safety system|responsible ai|requires moderation|violat/', $b)) return 'policy';
    if (in_array($http, [400, 401, 403], true)
        && preg_match('/api[_ ]?key (not valid|invalid|is invalid)|invalid[_ ]?api[_ ]?key|incorrect api key|unauthori[sz]ed|no auth credentials|permission[_ ]denied/', $b)) return 'auth';
    if ($http === 429) return 'rate_limit';
    if ($http === 401 || $http === 403) return 'auth';
    if ($http === 402) return 'quota';
    if ($http === 404) return 'not_found';
    if ($http === 408 || $http === 504) return 'timeout';
    if ($http >= 500) return 'server';
    if ($http === 400 || $http === 422) {
        if (preg_match('/content[_ ]?policy|safety|moderation|flagged|responsible ai|blocked/', $b)) return 'policy';
        if (preg_match('/insufficient[_ ]?(quota|credits|funds)|billing|credit balance/', $b)) return 'quota';
        if (preg_match('/unknown parameter|unsupported (parameter|value)|not supported with this model/', $b)) return 'unsupported';
        if (preg_match('/model.*(not found|does not exist|not a valid)|model_not_found|no endpoints|unknown model/', $b)) return 'not_found';
        return 'bad_request';
    }
    return 'server';
}

/**
 * المسار القديم (legacy/smart) → عملية بمحاولة واحدة.
 * بيتنادى من ai_log_request — كده كل استدعاء في المنصة بيتسجل حتى لو البوابة مقفولة.
 */
function usage_record_legacy(array $d): void
{
    if (!usage_ready()) return;
    $kind = (string) ($d['kind'] ?? 'text');
    $task = usage_task_for_kind($kind);
    if (($d['cap'] ?? '') === 'image') $task = 'design';
    elseif (($d['cap'] ?? '') === 'web') $task = 'research_search';
    elseif (($d['cap'] ?? '') === 'text' && usage_task_capability($task) !== 'text') $task = 'general';
    $cap = usage_task_capability($task);
    $ok = ($d['status'] ?? 'ok') !== 'failed';
    $opts = (array) ($d['options'] ?? []);
    $size = (string) ($opts['size'] ?? '');
    $provider = (string) ($d['provider'] ?? '');
    $runId = usage_run_start([
        'task' => $task, 'feature' => $kind !== '' && $kind !== 'text' ? $kind : usage_feature(),
        'route' => (string) ($d['route'] ?? 'legacy'),
        'user_id' => $d['user_id'] ?? null, 'ref_type' => $d['reference_type'] ?? null, 'ref_id' => $d['reference_id'] ?? null,
    ]);
    if (!$runId) return;
    // المسار القديم أحيانًا مابيبعتش كود الـ HTTP — بنطلّعه من نص الخطأ («HTTP 400: …»)
    if (!$ok && empty($d['http_code']) && preg_match('/\bHTTP\s+(\d{3})\b/', (string) ($d['error'] ?? ''), $__hm)) $d['http_code'] = (int) $__hm[1];
    $cls = $ok ? null : usage_legacy_error_class((string) ($d['error'] ?? ''), isset($d['http_code']) ? (int) $d['http_code'] : null);
    usage_attempt($runId, [
        'provider' => $provider, 'model' => (string) ($d['model'] ?? ''), 'capability' => $cap,
        'status' => $ok ? 'ok' : 'failed', 'error_class' => $cls, 'error' => $ok ? null : ($d['error'] ?? null),
        'http_code' => $d['http_code'] ?? null,
        'tokens_in' => (int) ($d['tokens_in'] ?? 0), 'tokens_out' => (int) ($d['tokens_out'] ?? 0),
        'images' => $cap === 'image' && $ok ? 1 : 0, 'size' => $size,
        'requests' => $cap === 'web' ? 1 : 0,
        'provider_cost' => $d['cost_usd'] ?? null,
        'duration_ms' => (int) ($d['duration_ms'] ?? 0),
    ]);
    usage_run_finish($runId, ['status' => $ok ? 'ok' : 'failed', 'error_class' => $cls, 'error' => $ok ? null : ($d['error'] ?? null),
        'duration_ms' => (int) ($d['duration_ms'] ?? 0)]);
}

/* ═══════════ ربط العمليات بالكريدت (نهاية الطلب) ═══════════ */

function usage_register_shutdown(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    register_shutdown_function('usage_link_credits');
}

/** حركة كريدت حصلت في الطلب الحالي (credits.php بتناديها) */
function usage_note_credit_tx(int $txId): void
{
    $GLOBALS['__usage_tx'][] = $txId;
    usage_register_shutdown();
}

/**
 * آخر الطلب: الكريدت اللي اتخصم (ناقص المسترد) + الإيراد → على عمليات نفس الطلب.
 * لو فيه أكتر من عملية: الناجحة بتاخد الكريدت بالتساوي (الفاشلة مابتتحاسبش).
 */
function usage_link_credits(): void
{
    $runs = array_values(array_unique($GLOBALS['__usage_runs'] ?? []));
    $txs = array_values(array_unique($GLOBALS['__usage_tx'] ?? []));
    if (!$runs || !$txs || !usage_ready()) return;
    try {
        $in = implode(',', array_map('intval', $txs));
        $rows = db_all("SELECT user_id, action_type, amount, reference_type, COALESCE(revenue_egp,0) rev FROM credit_transactions WHERE id IN ($in)");
        $byUser = [];
        foreach ($rows as $t) {
            $u = (int) $t['user_id'];
            $byUser[$u] = $byUser[$u] ?? ['credits' => 0, 'rev' => 0.0];
            if ($t['action_type'] === 'consume') {
                $byUser[$u]['credits'] += (int) $t['amount'];
                $byUser[$u]['rev'] += (float) $t['rev'];
            } elseif ($t['action_type'] === 'add' && $t['reference_type'] === 'refund') {
                $byUser[$u]['credits'] -= (int) $t['amount'];
                $byUser[$u]['rev'] += (float) $t['rev']; // سالب
            }
        }
        $rin = implode(',', array_map('intval', $runs));
        foreach ($byUser as $uid => $v) {
            $ur = db_all("SELECT id, status FROM ai_runs WHERE id IN ($rin) AND user_id = ?", [$uid]);
            if (!$ur) continue;
            $okRuns = array_values(array_filter($ur, fn($r) => $r['status'] === 'ok')) ?: $ur;
            $n = count($okRuns);
            $credits = max(0, $v['credits']);
            $rev = max(0.0, $v['rev']);
            foreach ($okRuns as $i => $r) {
                $c = intdiv($credits, $n) + ($i < $credits % $n ? 1 : 0);
                db_run('UPDATE ai_runs SET credits_charged = ?, revenue_egp = ? WHERE id = ?', [$c, round($rev / $n, 4), (int) $r['id']]);
            }
        }
    } catch (\Throwable $e) {
        error_log('[usage] link ' . $e->getMessage());
    }
}

/* ═══════════ تنبيهات الأدمن (جرس + إيميل) ═══════════ */

function admin_alerts_ready(): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    try { db_one('SELECT id FROM admin_alerts LIMIT 1'); return $ok = true; } catch (\Throwable $e) { return $ok = false; }
}

/**
 * تنبيه للأدمن. نفس dedupe_key خلال ساعة = نفس التنبيه (بيزيد العداد ويرجع يظهر كجديد).
 * الإيميل: مرة كل ساعة بحد أقصى لكل تنبيه، ولمستوى ≥ alerts_email_min_level.
 */
function admin_alert(string $level, string $kind, string $title, string $body = '', ?string $link = null, ?string $dedupe = null): void
{
    if (!admin_alerts_ready()) return;
    $level = in_array($level, ['info', 'warn', 'critical'], true) ? $level : 'info';
    $dedupe = $dedupe ?: ($kind . ':' . md5($title));
    try {
        $row = db_one('SELECT id, emailed_at FROM admin_alerts WHERE dedupe_key = ? AND last_at > DATE_SUB(NOW(), INTERVAL 1 HOUR) ORDER BY id DESC LIMIT 1', [$dedupe]);
        if ($row) {
            db_run('UPDATE admin_alerts SET hits = hits + 1, last_at = NOW(), read_at = NULL, body = ?, level = ? WHERE id = ?',
                [mb_substr($body, 0, 1000), $level, (int) $row['id']]);
            $id = (int) $row['id'];
            $emailedAt = $row['emailed_at'];
        } else {
            $id = (int) db_insert('INSERT INTO admin_alerts (level, kind, title, body, link, dedupe_key) VALUES (?,?,?,?,?,?)',
                [$level, mb_substr($kind, 0, 40), mb_substr($title, 0, 200), mb_substr($body, 0, 1000), $link ? mb_substr($link, 0, 255) : null, mb_substr($dedupe, 0, 120)]);
            $emailedAt = null;
        }
        $to = trim((string) usage_setting('alerts_email', ''));
        $min = (string) usage_setting('alerts_email_min_level', 'warn');
        $rank = ['info' => 0, 'warn' => 1, 'critical' => 2];
        if ($id && $to !== '' && filter_var($to, FILTER_VALIDATE_EMAIL) && ($rank[$level] >= ($rank[$min] ?? 1))
            && (!$emailedAt || strtotime($emailedAt) < time() - 3600)) {
            // بنعلّم إنه اتبعت دلوقتي (يمنع التكرار) والإرسال نفسه بعد ما رد العميل يخلص
            db_run('UPDATE admin_alerts SET emailed_at = NOW() WHERE id = ?', [$id]);
            $icon = ['info' => 'ℹ️', 'warn' => '⚠️', 'critical' => '🚨'][$level];
            $url = defined('APP_URL') ? rtrim(APP_URL, '/') . '/' . ltrim($link ?: 'admin/alerts.php', '/') : '';
            $html = '<div dir="rtl" style="font-family:Tahoma,Arial;font-size:14px;line-height:1.8">'
                . '<h3 style="margin:0 0 8px">' . $icon . ' ' . htmlspecialchars($title) . '</h3>'
                . '<p>' . nl2br(htmlspecialchars($body)) . '</p>'
                . ($url ? '<p><a href="' . htmlspecialchars($url) . '">افتح لوحة الأدمن</a></p>' : '') . '</div>';
            $GLOBALS['__alert_mails'][] = [$to, $icon . ' Spread AI — ' . $title, $html];
            static $reg = false;
            if (!$reg) {
                $reg = true;
                register_shutdown_function(function () {
                    if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
                    if (!function_exists('send_mail') && is_file(__DIR__ . '/mailer.php')) require_once __DIR__ . '/mailer.php';
                    foreach ($GLOBALS['__alert_mails'] ?? [] as [$t, $sub, $h]) {
                        if (function_exists('send_mail')) @send_mail($t, $sub, $h);
                    }
                });
            }
        }
    } catch (\Throwable $e) {
        error_log('[alerts] ' . $e->getMessage());
    }
}

/** التنبيهات المفتوحة (للجرس) */
function admin_alerts_open(int $limit = 8): array
{
    if (!admin_alerts_ready()) return [];
    try {
        return db_all('SELECT * FROM admin_alerts WHERE read_at IS NULL ORDER BY FIELD(level, "critical", "warn", "info"), last_at DESC LIMIT ' . (int) $limit);
    } catch (\Throwable $e) {
        return [];
    }
}

function admin_alerts_open_count(): int
{
    if (!admin_alerts_ready()) return 0;
    try {
        return (int) (db_one('SELECT COUNT(*) n FROM admin_alerts WHERE read_at IS NULL')['n'] ?? 0);
    } catch (\Throwable $e) {
        return 0;
    }
}

/* ═══════════ أرقام مساعدة للتقارير ═══════════ */

/** ملخص فترة: العمليات · النجاح · التكلفة · الكريدت · الإيراد */
function usage_summary(string $from, string $to): array
{
    $z = ['runs' => 0, 'ok' => 0, 'failed' => 0, 'refused' => 0, 'failovers' => 0, 'tokens_in' => 0, 'tokens_out' => 0, 'images' => 0,
          'cost_usd' => 0.0, 'cost_egp' => 0.0, 'cost_failed_usd' => 0.0, 'credits' => 0, 'revenue_egp' => 0.0, 'estimated' => 0];
    if (!usage_ready()) return $z;
    try {
        $r = db_one(
            'SELECT COUNT(*) runs, SUM(status="ok") ok, SUM(status="failed") failed, SUM(status="refused") refused, SUM(failovers > 0) failovers,
                    COALESCE(SUM(tokens_in),0) tokens_in, COALESCE(SUM(tokens_out),0) tokens_out, COALESCE(SUM(images_count),0) images,
                    COALESCE(SUM(cost_usd),0) cost_usd, COALESCE(SUM(cost_egp),0) cost_egp, COALESCE(SUM(cost_failed_usd),0) cost_failed_usd,
                    COALESCE(SUM(credits_charged),0) credits, COALESCE(SUM(revenue_egp),0) revenue_egp,
                    SUM(cost_source IN ("estimated","mixed")) estimated
             FROM ai_runs WHERE created_at >= ? AND created_at < ?',
            [$from, $to]
        ) ?: [];
        foreach ($z as $k => $v) $z[$k] = is_float($v) ? (float) ($r[$k] ?? 0) : (int) ($r[$k] ?? 0);
    } catch (\Throwable $e) {
    }
    return $z;
}

/** إيراد الكريدت المستهلك في فترة (من الحركات نفسها — المرجع المحاسبي) */
function usage_credit_revenue(string $from, string $to): array
{
    try {
        $r = db_one(
            'SELECT COALESCE(SUM(IF(action_type = "consume", amount, 0)),0) consumed,
                    COALESCE(SUM(IF(action_type = "add" AND reference_type = "refund", amount, 0)),0) refunded,
                    COALESCE(SUM(revenue_egp),0) revenue
             FROM credit_transactions WHERE created_at >= ? AND created_at < ?',
            [$from, $to]
        ) ?: [];
        return ['consumed' => (int) ($r['consumed'] ?? 0), 'refunded' => (int) ($r['refunded'] ?? 0), 'revenue' => (float) ($r['revenue'] ?? 0)];
    } catch (\Throwable $e) {
        return ['consumed' => 0, 'refunded' => 0, 'revenue' => 0.0];
    }
}

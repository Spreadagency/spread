<?php
/**
 * Spread AI — محرك المحتوى v3 · طابور الشغل + حجز الكريدت (المرحلة D)
 *
 * الشغل الطويل مابيحصلش جوه طلب HTTP:
 *   ce_job_create()  ← بيحجز الكريدت (لو فيه) ويسجّل الشغلانة في نفس الـ transaction
 *   cron/ce-worker.php كل دقيقة ← ce_worker_tick() بيشغّل خطوة خطوة
 *   والواجهة بتسأل عن الحالة (job_status)، ولو الـ cron مش متظبط بتطلب خطوة بنفسها (job_step)
 *
 * كل شغلانة ليها handler: fn(array $job, array &$state): array
 *   ['done' => bool, 'progress' => 0..100, 'stage' => '…', 'label' => '…', 'result' => [...], 'used_credits' => n]
 *   ['fail' => 'رسالة']  ← فشل نهائي (مابيتعادش)
 *   أي Exception ← فشل مؤقت: بيتعاد بعد شوية لحد max_attempts
 * الخطوة الواحدة لازم تبقى قصيرة (نداء AI واحد تقريبًا) — الحالة بتتحفظ بين الخطوات في state_json.
 *
 * الكريدت: بيتحجز قبل التشغيل (ce_hold) وبيتسوّى بعده (ce_settle): الفرق بيرجع للعميل،
 * ومفيش أبدًا خصم أكتر من اللي اتحجز. لو الشغلانة فشلت نهائي ← الحجز كله بيرجع.
 * مفتاح المنع (idem_key) بيضمن إن نفس الطلب مايتحجزش مرتين ولا يتعمل له شغلانتين.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/credits.php';
require_once __DIR__ . '/ce-prompts.php';
require_once __DIR__ . '/ce-skills.php';    // Skills التخصصات (ملفات MD)

/* ═══════════════════ الجاهزية ═══════════════════ */

function ce_ready(): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        db_one('SELECT id FROM ce_jobs LIMIT 1');
        db_one('SELECT id FROM ce_credit_holds LIMIT 1');
        db_one('SELECT id FROM ce_brand_dna LIMIT 1');
        db_one('SELECT id FROM ce_campaigns LIMIT 1');
        return $ok = true;
    } catch (\Throwable $e) {
        return $ok = false;
    }
}

/** المحرك الجديد متاح للعميل ده؟ (الترحيل اتشغّل + الميزة مفتوحة له من «الميزات») */
function ce_enabled_for(int $uid): bool
{
    if (!ce_ready()) return false;
    require_once __DIR__ . '/social.php'; // feature_allows
    return feature_allows($uid, 'content_engine');
}

function ce_setting_int(string $k, int $def): int
{
    return (int) get_setting($k, (string) $def);
}

function ce_setting_float(string $k, float $def): float
{
    $v = str_replace(',', '.', (string) get_setting($k, (string) $def));
    return is_numeric($v) ? (float) $v : $def;
}

/* ═══════════════════ حجز الكريدت ═══════════════════ */

/**
 * حجز كريدت قبل شغلانة. نفس المفتاح مرتين = نفس الحجز (من غير خصم تاني).
 * @return array{ok:bool, hold_id?:int, existing?:bool, error?:string, code?:string}
 */
function ce_hold(int $uid, int $credits, string $purpose, string $idem, ?string $refType = null, ?int $refId = null, string $note = ''): array
{
    $credits = max(0, $credits);
    $idem = mb_substr($idem, 0, 100);
    $pdo = db();
    $began = !$pdo->inTransaction();
    if ($began) $pdo->beginTransaction();
    try {
        $old = db_one('SELECT id FROM ce_credit_holds WHERE idem_key = ? FOR UPDATE', [$idem]);
        if ($old) {
            if ($began) $pdo->commit();
            return ['ok' => true, 'hold_id' => (int) $old['id'], 'existing' => true];
        }
        $hid = (int) db_insert('INSERT INTO ce_credit_holds (user_id, purpose, ref_type, ref_id, reserved, idem_key, note) VALUES (?,?,?,?,?,?,?)',
            [$uid, mb_substr($purpose, 0, 40), $refType, $refId, $credits, $idem, mb_substr($note, 0, 255)]);
        if ($credits > 0 && !credits_consume($uid, $credits, 'حجز: ' . mb_substr($note ?: $purpose, 0, 150), 'ce_hold', $hid)) {
            if ($began) $pdo->rollBack();
            return ['ok' => false, 'code' => 'credits', 'error' => function_exists('credits_short_msg') ? credits_short_msg($credits) : 'رصيدك مش كفاية'];
        }
        if ($began) $pdo->commit();
        return ['ok' => true, 'hold_id' => $hid, 'existing' => false];
    } catch (\Throwable $e) {
        if ($began && $pdo->inTransaction()) $pdo->rollBack();
        if (!$began) throw $e;
        error_log('[ce_hold] ' . $e->getMessage());
        return ['ok' => false, 'code' => 'error', 'error' => 'مقدرناش نحجز الكريدت — جرّب تاني'];
    }
}

/**
 * تسوية الحجز بعد ما الشغلانة خلصت: اللي اتصرف فعلًا بيتخصم، والباقي بيرجع.
 * مابيخصمش أكتر من المحجوز أبدًا · بيتنفّذ مرة واحدة بس (الحالة لازم تكون held).
 */
function ce_settle(int $holdId, int $used, string $status = 'settled'): array
{
    $pdo = db();
    $began = !$pdo->inTransaction();
    if ($began) $pdo->beginTransaction();
    try {
        $h = db_one('SELECT * FROM ce_credit_holds WHERE id = ? FOR UPDATE', [$holdId]);
        if (!$h || $h['status'] !== 'held') {
            if ($began) $pdo->commit();
            return ['ok' => true, 'already' => true];
        }
        $reserved = (int) $h['reserved'];
        $used = max(0, min($used, $reserved));
        $back = $reserved - $used;
        if ($back > 0 && !credits_add((int) $h['user_id'], $back, 'استرداد الفرق: ' . mb_substr((string) ($h['note'] ?: $h['purpose']), 0, 150),
                null, 'refund', $holdId, null, ['idem_key' => 'ceh:' . $holdId])) {
            throw new RuntimeException('credits_add failed');
        }
        db_run('UPDATE ce_credit_holds SET status = ?, used = ?, refunded = ?, settled_at = NOW() WHERE id = ?',
            [$status === 'released' ? 'released' : 'settled', $used, $back, $holdId]);
        if ($began) $pdo->commit();
        return ['ok' => true, 'used' => $used, 'refunded' => $back];
    } catch (\Throwable $e) {
        if ($began && $pdo->inTransaction()) $pdo->rollBack();
        if (!$began) throw $e;
        error_log('[ce_settle] ' . $e->getMessage());
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/** إلغاء الحجز كله (الشغلانة فشلت أو اتلغت) */
function ce_release(int $holdId): array
{
    return ce_settle($holdId, 0, 'released');
}

/* ═══════════════════ الشغلانات ═══════════════════ */

/**
 * تسجيل نوع شغلانة: ce_job_register('brand_dna', 'ce_dna_job', 'بناء الـ Brand DNA')
 * $onCancel (اختياري): دالة بتاخد الحالة وترجّع الكريدت المستهلك لحد الإلغاء (الباقي بيرجع) — من غيرها الحجز كله بيرجع
 */
function ce_job_register(string $kind, string $fn, string $label, ?string $onCancel = null): void
{
    $GLOBALS['__ce_job_handlers'][$kind] = ['fn' => $fn, 'label' => $label, 'on_cancel' => $onCancel];
}

function ce_job_handlers(): array
{
    // كل وحدة بتسجّل الـ handlers بتاعتها لما تتحمّل
    foreach (['ce-dna.php', 'ce-campaign.php', 'ce-plan.php', 'ce-write.php', 'ce-design.php'] as $f) {
        if (is_file(__DIR__ . '/' . $f)) require_once __DIR__ . '/' . $f;
    }
    return $GLOBALS['__ce_job_handlers'] ?? [];
}

/**
 * إنشاء شغلانة (ومعاها حجز الكريدت لو $opt['credits'] > 0) — في transaction واحدة.
 * $opt: ref_type · ref_id · idem_key · credits · note · max_attempts
 * @return array{ok:bool, job?:array, existing?:bool, error?:string, code?:string}
 */
function ce_job_create(int $uid, string $kind, array $state = [], array $opt = []): array
{
    if (!isset(ce_job_handlers()[$kind])) return ['ok' => false, 'code' => 'kind', 'error' => 'نوع شغل مش معروف'];
    $idem = isset($opt['idem_key']) && $opt['idem_key'] !== '' ? mb_substr((string) $opt['idem_key'], 0, 100) : null;
    $credits = max(0, (int) ($opt['credits'] ?? 0));
    $pdo = db();
    $pdo->beginTransaction();
    try {
        if ($idem) {
            $old = db_one('SELECT * FROM ce_jobs WHERE idem_key = ? FOR UPDATE', [$idem]);
            if ($old) {
                $pdo->commit();
                return ['ok' => true, 'job' => $old, 'existing' => true];
            }
        }
        $holdId = null;
        if ($credits > 0) {
            $h = ce_hold($uid, $credits, $kind, 'job:' . ($idem ?: bin2hex(random_bytes(12))),
                $opt['ref_type'] ?? null, isset($opt['ref_id']) ? (int) $opt['ref_id'] : null, (string) ($opt['note'] ?? ''));
            if (!$h['ok']) {
                $pdo->rollBack();
                return $h;
            }
            $holdId = (int) $h['hold_id'];
        }
        $id = (int) db_insert('INSERT INTO ce_jobs (user_id, kind, ref_type, ref_id, status, stage_label, state_json, max_attempts, hold_id, idem_key)
                               VALUES (?,?,?,?,"queued",?,?,?,?,?)', [
            $uid ?: null, $kind, $opt['ref_type'] ?? null, isset($opt['ref_id']) ? (int) $opt['ref_id'] : null,
            'في الطابور…', json_encode($state, JSON_UNESCAPED_UNICODE), max(1, (int) ($opt['max_attempts'] ?? 3)), $holdId, $idem,
        ]);
        $pdo->commit();
        return ['ok' => true, 'job' => db_one('SELECT * FROM ce_jobs WHERE id = ?', [$id]), 'existing' => false];
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[ce_job_create] ' . $e->getMessage());
        return ['ok' => false, 'code' => 'error', 'error' => 'مقدرناش نبدأ الشغل — جرّب تاني'];
    }
}

function ce_job_get(int $id, ?int $uid = null): ?array
{
    $j = $uid === null
        ? db_one('SELECT * FROM ce_jobs WHERE id = ?', [$id])
        : db_one('SELECT * FROM ce_jobs WHERE id = ? AND user_id = ?', [$id, $uid]);
    return $j ?: null;
}

/** شكل الشغلانة للواجهة (من غير الحالة الداخلية) */
function ce_job_public(array $j): array
{
    $label = ce_job_handlers()[$j['kind']]['label'] ?? $j['kind'];
    return [
        'id' => (int) $j['id'], 'kind' => $j['kind'], 'kind_label' => $label, 'status' => $j['status'],
        'stage' => $j['stage'], 'label' => $j['stage_label'], 'progress' => (int) $j['progress'],
        'error' => $j['status'] === 'failed' ? $j['error'] : null,
        'result' => $j['result_json'] ? json_decode((string) $j['result_json'], true) : null,
        'created_at' => $j['created_at'], 'finished_at' => $j['finished_at'],
        'active' => in_array($j['status'], ['queued', 'running'], true),
    ];
}

/** آخر شغلانة شغالة لنفس المرجع (عشان الواجهة ترجع تتابعها بعد الريفرش) */
function ce_job_active_for(int $uid, string $kind, ?string $refType = null, ?int $refId = null): ?array
{
    $sql = 'SELECT * FROM ce_jobs WHERE user_id = ? AND kind = ? AND status IN ("queued","running")';
    $p = [$uid, $kind];
    if ($refType !== null) { $sql .= ' AND ref_type = ? AND ref_id <=> ?'; $p[] = $refType; $p[] = $refId; }
    return db_one($sql . ' ORDER BY id DESC LIMIT 1', $p) ?: null;
}

/**
 * قفل الشغلانة لخطوة واحدة. القفل اللي عدّى عليه 5 دقايق بيتعتبر واقع (العملية وقفت في النص)
 * فبيتاخد تاني ويتحسب محاولة.
 */
function ce_job_claim(int $id): ?string
{
    $tok = bin2hex(random_bytes(16));
    $st = db()->prepare('UPDATE ce_jobs SET attempts = attempts + IF(lock_token IS NULL, 0, 1), status = "running", lock_token = ?, locked_at = NOW()
                         WHERE id = ? AND status IN ("queued","running") AND (run_after IS NULL OR run_after <= NOW())
                           AND (lock_token IS NULL OR locked_at < NOW() - INTERVAL 5 MINUTE)');
    $st->execute([$tok, $id]);
    return $st->rowCount() === 1 ? $tok : null;
}

/** الشغلانة اللي عليها الدور (أقدم واحدة جاهزة ومش مقفولة) */
function ce_job_pick(): ?int
{
    $r = db_one('SELECT id FROM ce_jobs WHERE status IN ("queued","running") AND (run_after IS NULL OR run_after <= NOW())
                 AND (lock_token IS NULL OR locked_at < NOW() - INTERVAL 5 MINUTE) ORDER BY id LIMIT 1');
    return $r ? (int) $r['id'] : null;
}

/**
 * تشغيل خطوة واحدة من شغلانة. بيرجع الشغلانة بعد الخطوة (أو null لو مقدرش يقفلها — حد تاني شغّال عليها).
 */
function ce_job_step(int $id): ?array
{
    $tok = ce_job_claim($id);
    if ($tok === null) return null;
    $job = db_one('SELECT * FROM ce_jobs WHERE id = ?', [$id]);
    $h = ce_job_handlers()[$job['kind']] ?? null;
    $state = json_decode((string) ($job['state_json'] ?? ''), true) ?: [];
    $fin = function (string $status, ?string $error, ?array $result = null, ?int $used = null) use ($job, $tok, &$state) {
        db_run('UPDATE ce_jobs SET status = ?, error = ?, result_json = COALESCE(?, result_json), state_json = ?, progress = IF(? = "done", 100, progress),
                stage_label = ?, lock_token = NULL, locked_at = NULL, finished_at = NOW() WHERE id = ? AND lock_token = ?', [
            $status, $error !== null ? mb_substr($error, 0, 500) : null, $result !== null ? json_encode($result, JSON_UNESCAPED_UNICODE) : null,
            json_encode($state, JSON_UNESCAPED_UNICODE), $status,
            $status === 'done' ? 'خلص ✓' : ($status === 'failed' ? 'وقف' : 'اتلغى'), (int) $job['id'], $tok,
        ]);
        if (!empty($job['hold_id'])) {
            $status === 'done' ? ce_settle((int) $job['hold_id'], $used ?? PHP_INT_MAX) : ce_release((int) $job['hold_id']);
        }
    };
    if (!$h || !function_exists($h['fn'])) {
        $fin('failed', 'نوع الشغل مش متسجّل');
        return db_one('SELECT * FROM ce_jobs WHERE id = ?', [$id]);
    }

    @set_time_limit(180);
    try {
        $r = ($h['fn'])($job, $state);
        if (!empty($r['fail'])) {
            $fin('failed', (string) $r['fail']);
        } elseif (!empty($r['done'])) {
            $fin('done', null, (array) ($r['result'] ?? []), isset($r['used_credits']) ? (int) $r['used_credits'] : null);
            // القطع والتصميمات اللي خلصت ← مكتبة المحتوى والتقويم
            if (in_array($job['kind'], ['campaign_write', 'campaign_design'], true) && $job['ref_type'] === 'ce_campaign') ce_lib_after((int) $job['user_id'], (int) $job['ref_id']);
        } else {
            db_run('UPDATE ce_jobs SET state_json = ?, progress = ?, stage = ?, stage_label = ?, lock_token = NULL, locked_at = NULL
                    WHERE id = ? AND lock_token = ?', [
                json_encode($state, JSON_UNESCAPED_UNICODE), max(0, min(99, (int) ($r['progress'] ?? $job['progress']))),
                isset($r['stage']) ? mb_substr((string) $r['stage'], 0, 40) : $job['stage'],
                isset($r['label']) ? mb_substr((string) $r['label'], 0, 160) : $job['stage_label'], $id, $tok,
            ]);
        }
    } catch (\Throwable $e) {
        error_log('[ce_job ' . $id . ' ' . $job['kind'] . '] ' . $e->getMessage());
        $att = (int) $job['attempts'] + 1;
        if ($att >= (int) $job['max_attempts']) {
            db_run('UPDATE ce_jobs SET attempts = ? WHERE id = ?', [$att, $id]);
            $fin('failed', 'حصلت مشكلة وإحنا بنشتغل — الكريدت المحجوز رجع لرصيدك. جرّب تاني.');
        } else {
            // إعادة بعد 30 ثانية × رقم المحاولة — من غير ما الحالة اللي اتحفظت تضيع
            db_run('UPDATE ce_jobs SET attempts = ?, state_json = ?, run_after = NOW() + INTERVAL ? SECOND, stage_label = ?, lock_token = NULL, locked_at = NULL
                    WHERE id = ? AND lock_token = ?', [$att, json_encode($state, JSON_UNESCAPED_UNICODE), 30 * $att, 'بنحاول تاني…', $id, $tok]);
        }
    }
    return db_one('SELECT * FROM ce_jobs WHERE id = ?', [$id]);
}

/** نسخة المكتبة من الحملة (ce-library.php) — أي خطأ فيها مايوقفش المحرك */
function ce_lib_after(int $uid, int $cid): void
{
    try {
        require_once __DIR__ . '/ce-library.php';
        ce_lib_sync_campaign($uid, $cid);
    } catch (\Throwable $e) {
        error_log('[ce_lib_after] ' . $e->getMessage());
    }
}

/** إلغاء شغلانة من العميل — الحجز بيرجع كله */
function ce_job_cancel(int $id, int $uid): bool
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $j = db_one('SELECT * FROM ce_jobs WHERE id = ? AND user_id = ? FOR UPDATE', [$id, $uid]);
        if (!$j || !in_array($j['status'], ['queued', 'running'], true)) { $pdo->commit(); return false; }
        db_run('UPDATE ce_jobs SET status = "cancelled", stage_label = "اتلغى", lock_token = NULL, finished_at = NOW() WHERE id = ?', [$id]);
        if (!empty($j['hold_id'])) {
            $cb = ce_job_handlers()[$j['kind']]['on_cancel'] ?? null;
            $used = $cb && function_exists($cb) ? max(0, (int) $cb(json_decode((string) $j['state_json'], true) ?: [])) : 0;
            $used > 0 ? ce_settle((int) $j['hold_id'], $used) : ce_release((int) $j['hold_id']);
        }
        $pdo->commit();
        return true;
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[ce_job_cancel] ' . $e->getMessage());
        return false;
    }
}

/** دورة الـ worker: يشغّل خطوات لحد ما الوقت يخلص أو مايبقاش فيه شغل */
function ce_worker_tick(int $seconds = 50): int
{
    $t0 = time();
    $n = 0;
    while (time() - $t0 < $seconds) {
        $id = ce_job_pick();
        if (!$id) break;
        if (ce_job_step($id) !== null) $n++;
        else usleep(200000); // حد تاني خدها في نفس اللحظة
    }
    return $n;
}

/** الـ cron شغال؟ (آخر دورة من أقل من 3 دقايق) — لو لأ، الواجهة بتشغّل الخطوات بنفسها */
function ce_worker_alive(): bool
{
    $t = (string) get_setting('ce_worker_last_run', '');
    return $t !== '' && time() - strtotime($t) < 180;
}

/* ═══════════════════ التوكنز ═══════════════════ */

/** تقدير التوكنز من النص (العربي ≈ 2.8 حرف/توكن · الإنجليزي ≈ 4) — للتقدير قبل التشغيل بس، الحساب الفعلي من usage */
function ce_tokens_est(string $s): int
{
    if ($s === '') return 0;
    $len = mb_strlen($s);
    $ar = preg_match_all('/[\x{0600}-\x{06FF}]/u', $s);
    $ratio = $len > 0 ? $ar / $len : 0;
    return (int) ceil($len / (4 - 1.2 * $ratio));
}

/* ═══════════════════ نداء AI بـ JSON + تحويل التكلفة لكريدت ═══════════════════ */

/**
 * نداء AI لمرحلة من مراحل المحرك (task = ce_*) والرد JSON.
 * الـ system ثابت (أول البرومبت) عشان الـ prompt caching عند المزودين اللي بيدعموه.
 * @return array{ok:bool, data:?array, text:string, error:?string, usage:array, cost_usd:float, run_id:?int, model:?string}
 */
function ce_ai_json(string $task, string $system, string $user, array $ctx = [], int $maxTokens = 1500, float $temperature = 0.3, array $images = []): array
{
    require_once __DIR__ . '/ai-gateway.php';
    $out = ['ok' => false, 'data' => null, 'text' => '', 'error' => null, 'usage' => ['in' => 0, 'out' => 0], 'cost_usd' => 0.0, 'run_id' => null, 'model' => null, 'provider' => null];
    if (!function_exists('ai_gw_enabled') || !ai_gw_enabled()) {
        $out['error'] = 'gateway_off';
        return $out;
    }
    // Skill التخصص (H2): الأقسام اللي تخص المرحلة دي بتتضاف في آخر برومبت النظام
    if (array_key_exists('skill', $ctx)) {
        $system .= ce_skill_block($task, is_array($ctx['skill']) ? $ctx['skill'] : null);
        unset($ctx['skill']);
    }
    $r = ai_run($task, [
        // صور داخلة للموديل (مراجعة الصورة): data URI أو رابط — والـ gateway بيختار مزود بيدعم الرؤية
        'messages' => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $images
            ? array_merge([['type' => 'text', 'text' => $user]], array_map(fn($u) => ['type' => 'image_url', 'image_url' => ['url' => $u]], array_slice($images, 0, 4)))
            : $user]],
        'max_tokens' => $maxTokens, 'temperature' => $temperature, 'json' => true,
    ], $ctx + ['feature' => 'content_engine']);
    $out['usage'] = $r['usage'] ?? $out['usage'];
    $out['cost_usd'] = (float) ($r['cost_usd'] ?? 0);
    $out['run_id'] = $r['run_id'] ?? null;
    $out['model'] = $r['model'] ?? null;
    $out['provider'] = is_array($r['provider'] ?? null) ? ($r['provider']['name'] ?? null) : ($r['provider'] ?? null);
    $out['text'] = (string) ($r['text'] ?? '');
    if (empty($r['ok'])) {
        $out['error'] = (string) ($r['error'] ?? 'ai_failed');
        return $out;
    }
    $data = ce_json_decode($out['text']);
    if ($data === null) {
        $out['error'] = 'bad_json';
        return $out;
    }
    $out['ok'] = true;
    $out['data'] = $data;
    return $out;
}

/** فك JSON من رد الموديل (بيشيل ```json … ``` وأي كلام قبل/بعد القوس) */
function ce_json_decode(string $t): ?array
{
    $t = trim($t);
    $t = preg_replace('/^```(?:json)?\s*|\s*```$/u', '', $t);
    $j = json_decode($t, true);
    if (is_array($j)) return $j;
    $a = strpos($t, '{');
    $b = strrpos($t, '}');
    if ($a !== false && $b !== false && $b > $a) {
        $j = json_decode(substr($t, $a, $b - $a + 1), true);
        if (is_array($j)) return $j;
    }
    return null;
}

/* ═══ الحساب الثابت (الباقات الجديدة) ═══
 * الكتابة بسعر ثابت لكل قطعة من «تسعير الخدمات» (حسب الشكل) بدل تكلفة الـ AI الفعلية — والتكلفة الفعلية بتفضل في ai_runs للأدمن.
 * التصميم من حصة الباقة (مش كريدت). ce_billing_mode = actual ← الطريقة القديمة (التكلفة الفعلية × الهامش).
 */
function ce_billing_fixed(): bool
{
    return (string) get_setting('ce_billing_mode', 'fixed') !== 'actual';
}

/** الخطة: بناء الخطة (ce_plan_cost) · إعادة التقييم بس (campaign_plan_eval_cost) */
function ce_price_plan(bool $gateOnly = false): int
{
    if ($gateOnly) return max(0, (int) get_setting('campaign_plan_eval_cost', 1));
    $v = get_setting('ce_plan_cost', '');
    return max(0, (int) ($v === '' || $v === null ? get_setting('plan_ideas_cost', 2) : $v));
}

/** سعر كتابة خانات (حسب شكل كل خانة) */
function ce_price_slots(array $slots): int
{
    $n = 0;
    foreach ($slots as $s) $n += cost_for_format((string) ($s['format'] ?? 'post'));
    return $n;
}

/**
 * قاعدة الأدمن: الكريدت = التكلفة بالدولار × سعر الدولار × (1 + الهامش%) ÷ قيمة الكريدت بالجنيه
 * (الإعدادات: ce_margin_pct · ce_egp_per_credit · سعر الدولار من fx_rates)
 */
function ce_usd_to_credits(float $usd): int
{
    if ($usd <= 0) return 0;
    require_once __DIR__ . '/usage.php';
    $fx = function_exists('usage_fx_rate') ? usage_fx_rate() : 0.0;
    if ($fx <= 0) $fx = 50.0;
    $margin = max(0.0, ce_setting_float('ce_margin_pct', 100)) / 100;
    $perCredit = max(0.01, ce_setting_float('ce_egp_per_credit', 1.5));
    return (int) ceil($usd * $fx * (1 + $margin) / $perCredit);
}

<?php
/**
 * Spread AI — مستويات الجودة (المرحلة 5)
 *
 * كل باقة على مستوى (عادي · احترافي · بريميوم …) والمستوى بيحدد:
 *   الموديل لكل مهمة (ai_tier_routes) ← بيتقدّم في سلسلة البوابة، والسلسلة الأساسية بتفضل بديل لو فشل
 *   معامل سعر الكتابة (credit_mult) ← أسعار «التكاليف» × المعامل
 *   محرك المحتوى: جولات التحسين وحد الجودة · إعادات الصورة · الأولوية في الطابور
 * المستوى بيتعرف من باقة العميل (لقطة الاشتراك) — «عادي» = زي ما المنصة شغالة دلوقتي بالظبط.
 */

const QT_DEFAULT = 'standard';
/** أسعار الكتابة اللي بتتضرب في معامل المستوى (النشر والبحث والهوية مابيتأثروش) */
const QT_MULT_KEYS = ['content_generation_cost', 'content_regeneration_cost', 'carousel_generation_cost', 'story_generation_cost', 'video_script_cost',
                      'ce_plan_cost', 'campaign_plan_eval_cost', 'ai_edit_cost', 'plan_ideas_cost', 'campaign_eval_cost'];

function qt_ready(): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        db_one('SELECT tkey FROM quality_tiers LIMIT 1');
        db_one('SELECT tier FROM ai_tier_routes LIMIT 1');
        return $ok = true;
    } catch (\Throwable $e) {
        return $ok = false;
    }
}

/** المستويات (key ← الصف) */
function qt_all(bool $activeOnly = true): array
{
    static $cache = null;
    if (!qt_ready()) return [];
    if ($cache === null) {
        $cache = [];
        foreach (db_all('SELECT * FROM quality_tiers ORDER BY sort_order, tkey') as $r) $cache[$r['tkey']] = $r;
    }
    return $activeOnly ? array_filter($cache, fn($r) => (int) $r['is_active'] === 1) : $cache;
}

function qt_get(string $key): ?array
{
    return qt_all(false)[$key] ?? null;
}

/* ═══════════════════ العميل اللي بنحسب له (السعر والمستوى) ═══════════════════ */

/** الـ worker (من غير جلسة) بيحدد صاحب الشغلانة — غير كده العميل اللي داخل */
function qt_price_user(?int $uid = null, bool $set = false): ?int
{
    static $forced = null;
    if ($set) $forced = $uid;
    if ($forced) return $forced;
    return !empty($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
}

/** مستوى العميل (من باقته) — مستوى اتقفل أو اتمسح ← عادي */
function qt_for_user(?int $uid): string
{
    if (!$uid || !qt_ready() || !function_exists('pkg_quality_tier')) return QT_DEFAULT;
    $t = pkg_quality_tier($uid);
    $row = qt_get($t);
    return $row && (int) $row['is_active'] === 1 ? $t : QT_DEFAULT;
}

/** معامل سعر الكتابة للعميل */
function qt_credit_mult(?int $uid = null): float
{
    $uid ??= qt_price_user();
    $row = qt_get(qt_for_user($uid));
    return $row ? max(0.1, (float) $row['credit_mult']) : 1.0;
}

/** سعر خدمة كتابة بعد معامل المستوى (التقريب لفوق) */
function qt_price(string $key, int $base, ?int $uid = null): int
{
    if ($base <= 0 || !in_array($key, QT_MULT_KEYS, true)) return $base;
    $m = qt_credit_mult($uid);
    return $m === 1.0 ? $base : (int) ceil($base * $m - 1e-9);
}

/** إعدادات المحرك حسب المستوى (null = إعداد المحرك العام) */
function qt_engine(?int $uid = null): array
{
    $row = qt_get(qt_for_user($uid ?? qt_price_user()));
    return ['qa_rounds' => isset($row['qa_rounds']) && $row['qa_rounds'] !== null ? (int) $row['qa_rounds'] : null,
            'qa_pass' => isset($row['qa_pass']) && $row['qa_pass'] !== null ? (float) $row['qa_pass'] : null,
            'vision_regen' => isset($row['vision_regen']) && $row['vision_regen'] !== null ? (int) $row['vision_regen'] : null,
            'priority' => (int) ($row['priority'] ?? 0)];
}

/* ═══════════════════ الموديلات ═══════════════════ */

/** موديل المهمة في المستوى ← ['p' => provider_name, 'm' => model, 'temp' => ?float] أو null (زي الأساسي) */
function qt_route(string $tier, string $task): ?array
{
    static $cache = [];
    if ($tier === QT_DEFAULT || !qt_ready()) return null;
    $k = $tier . '|' . $task;
    if (array_key_exists($k, $cache)) return $cache[$k];
    $r = db_one('SELECT r.temperature, m.provider_name, m.model, m.enabled FROM ai_tier_routes r LEFT JOIN ai_model_registry m ON m.id = r.model_id WHERE r.tier = ? AND r.task = ?', [$tier, $task]);
    if (!$r || (empty($r['model']) && $r['temperature'] === null)) return $cache[$k] = null;
    return $cache[$k] = [
        'p' => !empty($r['model']) && (int) $r['enabled'] === 1 ? (string) $r['provider_name'] : null,
        'm' => !empty($r['model']) && (int) $r['enabled'] === 1 ? (string) $r['model'] : null,
        'temp' => $r['temperature'] !== null ? (float) $r['temperature'] : null,
    ];
}

/** حفظ موديل مهمة في مستوى (من الأدمن) */
function qt_route_save(string $tier, string $task, ?int $modelId, ?float $temp): void
{
    if ($modelId === null && $temp === null) {
        db_run('DELETE FROM ai_tier_routes WHERE tier = ? AND task = ?', [$tier, $task]);
        return;
    }
    db_run('INSERT INTO ai_tier_routes (tier, task, model_id, temperature) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE model_id = VALUES(model_id), temperature = VALUES(temperature)',
        [$tier, $task, $modelId, $temp]);
}

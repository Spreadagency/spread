<?php
/**
 * Spread AI — تتبّع الأحداث (أساس تحليلات نظام الجودة / MCP)
 *
 * بيسجّل الأحداث اللي مش متسجلة في مكان تاني بس:
 *   idea_selected · post_scheduled · post_rated · design_rated · design_downloaded · issue_reported
 * الباقي موجود فعلًا ومابيتكررش: توليد الـ AI + الوقت + المزود + الأخطاء ← ai_request_logs ·
 * الكريدت ← credit_transactions · التعديل اليدوي ← content_versions · النشر ← publish_logs.
 *
 * ⚠️ مابيوقّعش رحلة العميل أبدًا: أي خطأ بيتسجّل في error_log ويكمّل.
 */

require_once __DIR__ . '/db.php';

final class EventTracker
{
    /** الأحداث المسموحة — أي حاجة تانية بتتجاهل */
    public const TYPES = ['idea_selected', 'post_scheduled', 'post_rated', 'design_rated', 'design_downloaded', 'issue_reported'];

    public static function track(string $type, ?string $entityType = null, ?int $entityId = null, array $meta = [], ?int $userId = null): void
    {
        try {
            if (!in_array($type, self::TYPES, true)) return;
            $uid = $userId ?? (int) ($_SESSION['user_id'] ?? 0);
            if ($uid <= 0) return;
            $brand = db_one('SELECT id FROM brand_profiles WHERE user_id = ? ORDER BY id LIMIT 1', [$uid]);
            $pv = isset($meta['prompt_version_id']) ? (int) $meta['prompt_version_id'] : null;
            $prov = isset($meta['provider']) ? mb_substr((string) $meta['provider'], 0, 60) : null;
            unset($meta['prompt_version_id'], $meta['provider']);
            // المحتوى: الإصدار والمزود من المنشور نفسه لو مابعتناهمش
            if (($pv === null || $prov === null) && $entityId && in_array($entityType, ['content', 'post'], true)) {
                $c = db_one('SELECT prompt_version_id, provider FROM contents WHERE id = ?', [$entityId]);
                $pv ??= $c && $c['prompt_version_id'] !== null ? (int) $c['prompt_version_id'] : null;
                $prov ??= $c['provider'] ?? null;
            }
            db_insert('INSERT INTO ai_events (user_id, brand_id, event_type, entity_type, entity_id, prompt_version_id, provider, meta) VALUES (?,?,?,?,?,?,?,?)', [
                $uid, $brand ? (int) $brand['id'] : null, $type, $entityType ? mb_substr($entityType, 0, 30) : null, $entityId,
                $pv, $prov, $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) : null,
            ]);
        } catch (\Throwable $e) {
            error_log('[events] ' . $type . ': ' . $e->getMessage());
        }
    }

    /** آخر N حدث للعميل (من ai_events + آخر طلبات الـ AI) — سياق لـ «بلّغ عن مشكلة» */
    public static function recent(int $userId, int $limit = 10): array
    {
        $out = [];
        try {
            foreach (db_all('SELECT event_type t, entity_type et, entity_id eid, created_at at FROM ai_events WHERE user_id = ? ORDER BY id DESC LIMIT ' . (int) $limit, [$userId]) as $r) {
                $out[] = ['type' => $r['t'], 'entity' => $r['et'] ? $r['et'] . '#' . $r['eid'] : null, 'at' => $r['at']];
            }
        } catch (\Throwable $e) {
        }
        try {
            foreach (db_all('SELECT kind, status, provider, model, error, duration_ms, reference_type, reference_id, created_at FROM ai_request_logs WHERE user_id = ? ORDER BY id DESC LIMIT ' . (int) $limit, [$userId]) as $r) {
                $out[] = ['type' => 'ai_' . $r['kind'], 'status' => $r['status'], 'provider' => $r['provider'], 'model' => $r['model'],
                          'error' => $r['error'] ? mb_substr((string) $r['error'], 0, 160) : null, 'ms' => (int) $r['duration_ms'],
                          'entity' => $r['reference_type'] ? $r['reference_type'] . '#' . $r['reference_id'] : null, 'at' => $r['created_at']];
            }
        } catch (\Throwable $e) {
        }
        usort($out, fn($a, $b) => strcmp((string) $b['at'], (string) $a['at']));
        return array_slice($out, 0, $limit);
    }
}

/**
 * إصدار البرومبت والمزود على المنشور / التصميم بعد التوليد (للتقييم والـ A/B)
 * $table: contents | content_designs · $promptType: نوع البرومبت الأساسي للمهمة · $ai: نتيجة الـ AI
 */
function ai_stamp(string $table, int $id, string $promptType, ?array $ai = null): void
{
    if ($id <= 0 || !in_array($table, ['contents', 'content_designs'], true)) return;
    try {
        $pv = function_exists('prompt_used_id') ? prompt_used_id($promptType) : null;
        $prov = $ai['provider'] ?? null;
        if (!$prov && !empty($ai['model'])) $prov = (string) $ai['model'];
        db_run("UPDATE `{$table}` SET prompt_version_id = ?, provider = ? WHERE id = ?", [$pv, $prov ? mb_substr((string) $prov, 0, 60) : null, $id]);
    } catch (\Throwable $e) {
        error_log('[stamp] ' . $table . '#' . $id . ': ' . $e->getMessage());
    }
}

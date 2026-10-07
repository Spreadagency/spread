<?php
namespace Spread;

class SmartRouter
{
    /**
     * يختار الموديل المناسب للمهمة
     *
     * @param string $taskCode content_generation / image_generation / source_summary / plan_ideas ...
     * @return array {model, provider, fallback: [['model','provider'], ...]}
     */
    public static function route(string $taskCode): array
    {
        $rule = db_one(
            'SELECT r.fallback_chain, m.model_name, m.id AS model_id, m.max_tokens, p.provider_name
             FROM smart_routing_rules r
             JOIN ai_models m    ON m.id = r.preferred_model_id AND m.status = "active"
             JOIN ai_providers p ON p.id = m.provider_id AND p.status = "active"
             WHERE r.task_code = ? AND r.is_active = 1
             LIMIT 1',
            [$taskCode]
        );

        if (!$rule) {
            return self::defaultRoute($taskCode);
        }

        $chain = [];
        $chainIds = json_decode($rule['fallback_chain'] ?? '[]', true);
        if (is_array($chainIds) && $chainIds) {
            $chainIds = array_values(array_filter(array_map('intval', $chainIds)));
            if ($chainIds) {
                $placeholders = implode(',', array_fill(0, count($chainIds), '?'));
                $rows = db_all(
                    "SELECT m.id, m.model_name, p.provider_name
                     FROM ai_models m
                     JOIN ai_providers p ON p.id = m.provider_id AND p.status = 'active'
                     WHERE m.id IN ({$placeholders}) AND m.status = 'active'",
                    $chainIds
                );
                // ترتيب الـ chain حسب ترتيب الـ ids
                $byId = [];
                foreach ($rows as $r) {
                    $byId[(int) $r['id']] = $r;
                }
                foreach ($chainIds as $id) {
                    if (isset($byId[$id])) {
                        $chain[] = ['model' => $byId[$id]['model_name'], 'provider' => $byId[$id]['provider_name']];
                    }
                }
            }
        }

        return [
            'model'      => $rule['model_name'],
            'provider'   => $rule['provider_name'],
            'max_tokens' => $rule['max_tokens'] ? (int) $rule['max_tokens'] : null,
            'fallback'   => $chain,
        ];
    }

    /**
     * لو مفيش rule: الموديل الافتراضي (image tasks تاخد أول image model)
     */
    private static function defaultRoute(string $taskCode): array
    {
        $isImage = (strpos($taskCode, 'image') !== false || strpos($taskCode, 'design') !== false);

        $row = db_one(
            'SELECT m.model_name, m.max_tokens, p.provider_name
             FROM ai_models m
             JOIN ai_providers p ON p.id = m.provider_id AND p.status = "active"
             WHERE m.status = "active" AND m.model_type ' . ($isImage ? '= "image"' : 'IN ("text","vision")') . '
             ORDER BY m.is_default DESC, p.priority ASC, m.priority_order ASC
             LIMIT 1'
        );

        if (!$row) {
            throw new \RuntimeException('No active AI model configured — فعّل موفر وموديل من إدارة الـ AI');
        }

        return [
            'model'      => $row['model_name'],
            'provider'   => $row['provider_name'],
            'max_tokens' => $row['max_tokens'] ? (int) $row['max_tokens'] : null,
            'fallback'   => [],
        ];
    }
}

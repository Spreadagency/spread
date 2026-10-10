<?php
/**
 * Spread AI — App-level functions (content, brand, etc.)
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

// Phase 1: Smart AI bridge (بيشتغل فقط لو مفعّل من الأدمن)
if (is_file(__DIR__ . '/smart-ai.php')) {
    require_once __DIR__ . '/smart-ai.php';
}

/**
 * Get content with related brand (for current user)
 */
function get_user_content(int $contentId, int $userId): ?array
{
    return db_one(
        'SELECT c.*, b.business_name FROM contents c
         LEFT JOIN brand_profiles b ON b.id = c.brand_profile_id
         WHERE c.id = ? AND c.user_id = ? LIMIT 1',
        [$contentId, $userId]
    );
}

/**
 * شروط فلترة المحتوى — مكان واحد للقائمة والعدّاد.
 * (كانوا منسوخين في دالتين، والعدّاد كان ناسي فلتر التاريخ → الترقيم بيطلع غلط)
 */
function user_contents_where(int $userId, array $filters): array
{
    $where = ['user_id = ?'];
    $params = [$userId];

    if (!empty($filters['platform'])) { $where[] = 'platform = ?'; $params[] = $filters['platform']; }
    if (!empty($filters['content_type'])) { $where[] = 'content_type = ?'; $params[] = $filters['content_type']; }
    if (!empty($filters['status'])) { $where[] = 'status = ?'; $params[] = $filters['status']; }
    if (!empty($filters['date_from'])) { $where[] = 'created_at >= ?'; $params[] = $filters['date_from']; }
    if (!empty($filters['date_to'])) { $where[] = 'created_at <= ?'; $params[] = $filters['date_to'] . ' 23:59:59'; }
    if (!empty($filters['q'])) {
        // بحث الشريط العلوي — بنهرّب % و _ عشان مايبقوش wildcard
        $like = '%' . addcslashes(mb_substr((string) $filters['q'], 0, 100), '%_\\') . '%';
        $where[] = '(generated_text LIKE ? OR hashtags LIKE ? OR cta LIKE ?)';
        array_push($params, $like, $like, $like);
    }
    return [implode(' AND ', $where), $params];
}

function list_user_contents(int $userId, array $filters = [], int $limit = 50, int $offset = 0): array
{
    [$whereSql, $params] = user_contents_where($userId, $filters);

    // الأعمدة اللي صفحة المحتويات بتعرضها (كانت ناقصة: الهاشتاجات · حالة النشر · الغلاف المختار · اتجاه التصميم)
    $sql = "SELECT id, content_type, platform, status, credits_used, created_at,
                   updated_at, generated_text, hashtags, cta, design_direction,
                   publish_status, selected_image_id
            FROM contents
            WHERE $whereSql
            ORDER BY created_at DESC
            LIMIT " . (int) $limit . " OFFSET " . (int) $offset;

    return db_all($sql, $params);
}

function count_user_contents(int $userId, array $filters = []): int
{
    [$whereSql, $params] = user_contents_where($userId, $filters);
    return db_count('SELECT COUNT(*) FROM contents WHERE ' . $whereSql, $params);
}

/**
 * Save a new version of content
 */
function save_content_version(int $contentId, string $text, ?string $hashtags = null, ?string $cta = null, string $type = 'edited', ?string $notes = null): int
{
    // Find next version number
    $row = db_one('SELECT MAX(version_number) AS v FROM content_versions WHERE content_id = ?', [$contentId]);
    $next = (int) ($row['v'] ?? 0) + 1;

    return db_insert(
        'INSERT INTO content_versions (content_id, version_number, content_text, hashtags, cta, version_type, notes) VALUES (?, ?, ?, ?, ?, ?, ?)',
        [$contentId, $next, $text, $hashtags, $cta, $type, $notes]
    );
}

/**
 * List versions for a content
 */
function get_content_versions(int $contentId): array
{
    return db_all(
        'SELECT * FROM content_versions WHERE content_id = ? ORDER BY version_number DESC',
        [$contentId]
    );
}

/**
 * Brand images of a brand profile
 */
function get_brand_images(int $brandProfileId): array
{
    return db_all(
        'SELECT * FROM brand_images WHERE brand_profile_id = ? ORDER BY created_at DESC',
        [$brandProfileId]
    );
}

/**
 * Image type label
 */
function image_type_label(string $type): string
{
    return match ($type) {
        'personal' => '🧑 شخصية',
        'reference' => '🔗 مرجعية',
        'design' => '🎨 ملهمة',
        default => $type
    };
}

function image_type_chip(string $type): string
{
    return match ($type) {
        'personal' => 'chip-mint',
        'reference' => 'chip-sky',
        'design' => 'chip-rose',
        default => 'chip-line'
    };
}

/**
 * Get user statistics for dashboard
 */
function user_stats(int $userId): array
{
    $totalContent = db_count('SELECT COUNT(*) FROM contents WHERE user_id = ?', [$userId]);
    $thisWeek = db_count(
        'SELECT COUNT(*) FROM contents WHERE user_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)',
        [$userId]
    );
    $regenCount = db_count(
        'SELECT COUNT(*) FROM credit_transactions WHERE user_id = ? AND reference_type = ?',
        [$userId, 'regenerate']
    );
    $totalSpent = (int) (db_one(
        'SELECT COALESCE(SUM(amount), 0) AS s FROM credit_transactions WHERE user_id = ? AND action_type = ?',
        [$userId, 'consume']
    )['s'] ?? 0);

    return [
        'total_content' => $totalContent,
        'this_week' => $thisWeek,
        'regen_count' => $regenCount,
        'total_spent' => $totalSpent,
        'balance' => credits_balance($userId)
    ];
}

require_once __DIR__ . '/credits.php'; // ensure credits_balance() is loaded

/**
 * Admin stats
 */
function admin_stats(): array
{
    return [
        'total_users' => db_count('SELECT COUNT(*) FROM users'),
        'active_users' => db_count('SELECT COUNT(*) FROM users WHERE status = ? AND email_verified_at IS NOT NULL', ['active']),
        'total_contents' => db_count('SELECT COUNT(*) FROM contents'),
        'credits_used' => (int) (db_one(
            'SELECT COALESCE(SUM(amount), 0) AS s FROM credit_transactions WHERE action_type = ?',
            ['consume']
        )['s'] ?? 0),
        'today_users' => db_count('SELECT COUNT(*) FROM users WHERE DATE(created_at) = CURDATE()'),
        'today_contents' => db_count('SELECT COUNT(*) FROM contents WHERE DATE(created_at) = CURDATE()'),
        'total_ideas' => (function () { try { return db_count('SELECT COUNT(*) FROM plan_ideas'); } catch (\Throwable $e) { return 0; } })(),
        'produced_ideas' => (function () { try { return db_count('SELECT COUNT(*) FROM plan_ideas WHERE status = "produced"'); } catch (\Throwable $e) { return 0; } })(),
        'total_designs' => (function () { try { return db_count('SELECT COUNT(*) FROM content_designs') + db_count('SELECT COUNT(*) FROM studio_designs'); } catch (\Throwable $e) { try { return db_count('SELECT COUNT(*) FROM content_designs'); } catch (\Throwable $e2) { return 0; } } })(),
        'studio_designs' => (function () { try { return db_count('SELECT COUNT(*) FROM studio_designs'); } catch (\Throwable $e) { return 0; } })(),
        'total_plans' => (function () { try { return db_count('SELECT COUNT(*) FROM content_plans'); } catch (\Throwable $e) { return 0; } })(),
        'pending_approvals' => (function () { try { return db_count('SELECT COUNT(*) FROM users WHERE approval_status = "pending"'); } catch (\Throwable $e) { return 0; } })(),
    ];
}

/**
 * إحصائيات لكل موديل AI: عدد العمليات حسب النوع
 */
function admin_model_stats(): array
{
    try {
        return db_all(
            "SELECT COALESCE(model, 'legacy') AS model, action, COUNT(*) AS c
             FROM ai_usage_log GROUP BY COALESCE(model, 'legacy'), action
             ORDER BY c DESC"
        );
    } catch (\Throwable $e) {
        return [];
    }
}

<?php
/**
 * Spread AI — مشاريع الاستوديو اللي لسه مكملتش (مسودات)
 *
 * العميل بيبدأ تصميم (طريقة · كلام · صور · Brief اتدفع فيه كريدت) وبيخرج من الصفحة —
 * كان كل ده بيضيع. دلوقتي الحالة بتتحفظ على السيرفر أول بأول، والصور بتترفع للمسودة
 * أول ما يختارها، ولما يرجع design-studio.php يلاقي المشروع ويكمّل من نفس الخطوة.
 * المسودة بتتمسح لما المشروع يخلص (اتحفظ في المحتويات / اتنشر) أو لما العميل يمسحها.
 */

const STUDIO_DRAFTS_MAX = 20;          // لكل عميل — الأقدم بيتمسح
const STUDIO_DRAFT_STATE_MAX = 60000;  // بايت

function studio_drafts_ensure(): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        db()->query('SELECT 1 FROM studio_drafts LIMIT 1');
        return $ok = true;
    } catch (\Throwable $e) { /* مش موجود — ننشئه */ }
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS `studio_drafts` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT NOT NULL,
            `title` VARCHAR(160) NOT NULL DEFAULT '',
            `method` VARCHAR(40) NOT NULL DEFAULT '',
            `step` VARCHAR(16) NOT NULL DEFAULT 'input',
            `state_json` MEDIUMTEXT NULL,
            `files_json` TEXT NULL,
            `design_id` INT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY `idx_user_updated` (`user_id`, `updated_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        return $ok = true;
    } catch (\Throwable $e) {
        error_log('[studio_drafts] ' . $e->getMessage());
        return $ok = false;
    }
}

function studio_draft_get(int $id, int $uid): ?array
{
    if (!$id || !studio_drafts_ensure()) return null;
    $r = db_one('SELECT * FROM studio_drafts WHERE id = ? AND user_id = ?', [$id, $uid]);
    return $r ?: null;
}

/** الصور المرفوعة للمسودة: [slot => مسار نسبي] — المسارات جوه storage/uploads بس */
function studio_draft_files(?array $d): array
{
    $f = $d ? (json_decode((string) ($d['files_json'] ?? ''), true) ?: []) : [];
    $out = [];
    foreach ($f as $slot => $path) {
        if (is_string($path) && preg_match('/^[a-z0-9_\-]+$/i', (string) $slot) && strpos($path, '..') === false && $path !== '') {
            $out[$slot] = $path;
        }
    }
    return $out;
}

function studio_draft_step_label(string $step): string
{
    return ['input' => 'بتكتب الفكرة', 'brief' => 'الـ Brief جاهز — فاضل التوليد', 'result' => 'التصميم جاهز — فاضل النشر'][$step] ?? 'لسه في الأول';
}

function studio_draft_row(array $d): array
{
    $files = studio_draft_files($d);
    $thumb = null;
    if (!empty($d['design_id'])) {
        $sd = db_one('SELECT image_path FROM studio_designs WHERE id = ? AND user_id = ?', [$d['design_id'], $d['user_id']]);
        if ($sd) $thumb = upload_url($sd['image_path']);
    }
    if (!$thumb && $files) $thumb = upload_url(reset($files));
    return [
        'id'    => (int) $d['id'],
        'title' => (string) ($d['title'] !== '' ? $d['title'] : 'مشروع تصميم'),
        'method'=> (string) $d['method'],
        'step'  => (string) $d['step'],
        'step_label' => studio_draft_step_label((string) $d['step']),
        'thumb' => $thumb,
        'ago'   => function_exists('ui_time_ago') ? ui_time_ago($d['updated_at']) : (string) $d['updated_at'],
    ];
}

function studio_drafts_list(int $uid): array
{
    if (!studio_drafts_ensure()) return [];
    $rows = db_all('SELECT * FROM studio_drafts WHERE user_id = ? ORDER BY updated_at DESC, id DESC LIMIT ' . STUDIO_DRAFTS_MAX, [$uid]);
    return array_map('studio_draft_row', $rows);
}

function studio_draft_delete(int $id, int $uid): void
{
    if (!studio_drafts_ensure()) return;
    db_run('DELETE FROM studio_drafts WHERE id = ? AND user_id = ?', [$id, $uid]);
}

/** بعد ما التصميم يتحوّل منشور/يتنشر — المسودة اللي مربوطة بيه (أو بأي نسخة منه) خلصت */
function studio_drafts_complete_design(int $designId, int $uid): void
{
    if (!$designId || !studio_drafts_ensure()) return;
    $r = db_one('SELECT id, parent_id FROM studio_designs WHERE id = ? AND user_id = ?', [$designId, $uid]);
    if (!$r) return;
    $root = (int) ($r['parent_id'] ?: $r['id']);
    db_run('DELETE FROM studio_drafts WHERE user_id = ? AND design_id IN (SELECT id FROM studio_designs WHERE user_id = ? AND (id = ? OR parent_id = ?))',
        [$uid, $uid, $root, $root]);
}

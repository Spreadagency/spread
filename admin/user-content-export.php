<?php
/**
 * Spread AI — الأدمن: استخراج محتوى عميل في جدول Excel (.xlsx)
 *   admin/user-content-export.php?id=العميل [&campaign=رقم الحملة] [&from=YYYY-MM-DD&to=YYYY-MM-DD]
 * صف لكل منشور: التاريخ · الحملة · الشكل · الـ Hook والنص · الـ CTA · الهاشتاجات · فكرة التصميم · الشرائح/السكريبت
 *              · الحالة والنشر · عدد التصميمات ولينكاتها · الدرجة
 */
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/content-formats.php';
require_once __DIR__ . '/../includes/xlsx.php';

require_admin();
require_admin_can('view_users');

$id = (int) ($_GET['id'] ?? 0);
$user = db_one('SELECT id, name, email FROM users WHERE id = ?', [$id]);
if (!$user) {
    flash_set('danger', 'العميل مش موجود');
    redirect('admin/users.php');
}
$w = ['c.user_id = ?'];
$p = [$id];
if (!empty($_GET['campaign'])) { $w[] = 'c.campaign_id = ?'; $p[] = (int) $_GET['campaign']; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['from'] ?? ''))) { $w[] = 'DATE(c.created_at) >= ?'; $p[] = $_GET['from']; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['to'] ?? ''))) { $w[] = 'DATE(c.created_at) <= ?'; $p[] = $_GET['to']; }

$rows = db_all('SELECT c.*, cp.title camp_title, ci.eval_score
                FROM contents c LEFT JOIN campaigns cp ON cp.id = c.campaign_id
                LEFT JOIN campaign_ideas ci ON ci.content_id = c.id
                WHERE ' . implode(' AND ', $w) . ' GROUP BY c.id ORDER BY c.created_at DESC, c.id DESC LIMIT 5000', $p);
$designs = [];
if ($rows) {
    foreach (db_all('SELECT content_id, image_path, slide_no FROM content_designs WHERE content_id IN (' . implode(',', array_map(fn($r) => (int) $r['id'], $rows)) . ') ORDER BY slide_no, id DESC') as $d) {
        $designs[(int) $d['content_id']][] = url('storage/' . $d['image_path']);
    }
}
$status = ['generated' => 'اتكتب', 'edited' => 'اتعدّل', 'saved' => 'محفوظ'];
$pub = ['published' => 'اتنشر', 'scheduled' => 'مجدول', 'pending' => 'في الطابور', 'processing' => 'بيتنشر', 'failed' => 'فشل', 'cancelled' => 'اتلغى', 'draft' => 'مسودة'];

$headers = ['#', 'التاريخ', 'الحملة', 'الشكل', 'النص (Hook + المحتوى)', 'الـ CTA', 'الهاشتاجات', 'فكرة التصميم', 'الشرائح / السكريبت', 'الحالة', 'النشر', 'موعد النشر', 'الدرجة', 'عدد التصميمات', 'لينكات التصميمات'];
$out = [];
foreach ($rows as $r) {
    $fmt = content_format_key($r['format'] ?? 'post');
    $extra = '';
    if ($fmt === 'carousel') {
        $extra = implode("\n", array_map(fn($s) => 'شريحة ' . $s['n'] . ': ' . trim(($s['title'] ?? '') . ' — ' . ($s['text'] ?? ''), ' —'), content_slides($r)));
    } elseif ($fmt === 'video') {
        $vb = json_decode((string) ($r['video_brief_json'] ?? ''), true) ?: [];
        $extra = trim((string) ($vb['script']['body'] ?? ''));
    }
    $ds = $designs[(int) $r['id']] ?? [];
    $out[] = [
        (int) $r['id'], substr((string) $r['created_at'], 0, 16), (string) ($r['camp_title'] ?? ''), content_format_label($fmt),
        (string) $r['generated_text'], (string) ($r['cta'] ?? ''), (string) ($r['hashtags'] ?? ''), (string) ($r['design_direction'] ?? ''),
        $extra, $status[$r['status']] ?? (string) $r['status'], $pub[(string) ($r['publish_status'] ?? '')] ?? (string) ($r['publish_status'] ?? ''),
        (string) ($r['scheduled_at'] ?? ''), $r['eval_score'] !== null ? (int) $r['eval_score'] : '', count($ds), implode("\n", $ds),
    ];
}
admin_log('export_user_content', 'user', $id, count($out) . ' منشور');
$name = 'محتوى-' . preg_replace('/[^\p{L}\p{N}_-]+/u', '-', (string) $user['name']) . '-' . date('Y-m-d');
xlsx_download($name, $headers, $out, ['sheet' => 'المحتوى', 'widths' => [8, 16, 24, 12, 60, 24, 30, 40, 50, 12, 12, 16, 8, 10, 60]]);

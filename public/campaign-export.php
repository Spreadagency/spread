<?php
/**
 * Spread AI v2 — تصدير محتوى الحملة CSV (⑥-ب)
 * للي باقته مافيهاش نشر مباشر: ينزّل كل حاجة ويرفعها بنفسه — بيفتح في Excel وGoogle Sheets بالعربي
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/campaign-flow.php';

require_login();
$user = current_user();

$c = db_one('SELECT * FROM campaigns WHERE id = ? AND user_id = ?', [(int) ($_GET['id'] ?? 0), (int) $user['id']]);
if (!$c) {
    http_response_code(404);
    exit('الحملة مش موجودة');
}

$ideas = array_filter(campaign_board($c), fn($i) => $i['selected'] && $i['content']);
$plat = ['facebook' => 'فيسبوك', 'instagram' => 'إنستجرام', 'both' => 'فيسبوك + إنستجرام'];

$name = preg_replace('/[^\p{L}\p{N}_-]+/u', '-', (string) $c['title']) ?: 'campaign';
header('Content-Type: text/csv; charset=utf-8');
header("Content-Disposition: attachment; filename=\"campaign.csv\"; filename*=UTF-8''" . rawurlencode($name . '.csv'));
header('Cache-Control: no-store');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");   // BOM علشان Excel يقرا العربي صح
fputcsv($out, ['#', 'الفكرة', 'الشكل', 'الزاوية', 'الموعد', 'المنصة', 'نص المنشور', 'CTA', 'الهاشتاجات', 'التصميم', 'فكرة التصميم', 'سكريبت الفيديو', 'الشرائح', 'حالة الفيديو', 'التقييم'], ",", "\"", "");
// حماية من CSV injection: خلية بتبدأ بـ = + - @ بيفتحها Excel كمعادلة
$safe = fn($v) => is_string($v) && $v !== '' && strpbrk($v[0], "=+-@\t\r") !== false ? "'" . $v : $v;
foreach ($ideas as $i) {
    $co = $i['content'];
    $design = '';
    if ($i['format'] === 'carousel') {
        // كل شريحة برابط تصميمها (أحدث نسخة)
        $design = implode("\n", array_map(fn($sl) => $sl['n'] . ') ' . ($sl['designs'][0]['url'] ?? '—'), $i['slides']));
    } else {
        foreach ($i['designs'] as $d) {
            if (!$co['selected_design'] || $d['id'] === $co['selected_design']) { $design = $d['url']; break; }
        }
    }
    $slidesTxt = $i['format'] === 'carousel' ? implode("\n", array_map(fn($sl) => $sl['n'] . ') ' . $sl['title'] . ($sl['text'] !== '' ? ' — ' . $sl['text'] : ''), $i['slides'])) : '';
    fputcsv($out, array_map($safe, [
        $i['n'], $i['title'], $i['format_label'] . ($i['format'] === 'carousel' ? ' (' . count($i['slides']) . ')' : ''), $i['angle'],
        $i['plan']['at'] ?? '', $plat[$i['plan']['platform'] ?? ''] ?? '',
        campaign_compose_text($co['hook'], $co['body']), $co['cta'], $co['tags'],
        $design, $co['design_idea'],
        $i['script'] ? trim(($i['script']['hook'] ?? '') . "\n" . ($i['script']['body'] ?? '')) : '',
        $slidesTxt, $i['video']['status']['label'] ?? '',
        $i['eval'] ? ($i['eval']['score'] . '/100') : '',
    ]), ',', '"', '');   // escape صريح (PHP 8.4 بيحذّر من الافتراضي)
}
fclose($out);

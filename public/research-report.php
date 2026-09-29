<?php
/**
 * Spread AI v2 — تقرير البحث العميق (⑦-ب)
 * ملف HTML مستقل بيفتح في أي متصفح: غلاف · ملخص · فهرس · الأقسام · الفرص · المصادر · تاريخ البحث
 *   ?id=&type=short|exec|full|html  · &dl=1 تحميل
 * المصادر المستبعدة مش بتظهر، والمعلومة اللي مصادرها كلها مستبعدة بتتعلّم «استنتاج AI»
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/research.php';
require_once __DIR__ . '/../includes/brand-brain.php';

require_login();
$user = current_user();
$r = research_row((int) ($_GET['id'] ?? 0), (int) $user['id']);
if (!$r || $r['status'] !== 'done') {
    http_response_code(404);
    exit('التقرير مش موجود');
}
$type = in_array($_GET['type'] ?? '', ['short', 'exec', 'full', 'html'], true) ? $_GET['type'] : 'full';
$kindName = ['short' => 'تقرير مختصر', 'exec' => 'تقرير تنفيذي', 'full' => 'تقرير كامل', 'html' => 'تقرير HTML تفاعلي'][$type];
$res = research_dec($r['result_json']);
$scope = research_dec($r['scope_json']);
$brand = brand_for_user((int) $user['id']) ?: [];
$src = array_values(array_filter(research_dec($r['sources_json']), fn($s) => empty($s['excluded'])));
$ok = array_flip(array_map(fn($s) => (int) $s['n'], $src));

$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$cite = function (array $c) use ($ok, $h): string {
    $c = array_values(array_filter($c, fn($n) => isset($ok[(int) $n])));
    if (!$c) return ' <span class="ai">استنتاج AI</span>';
    return ' ' . implode('', array_map(fn($n) => '<sup><a href="#src-' . (int) $n . '">[' . (int) $n . ']</a></sup>', $c));
};
$li = function (array $items) use ($h, $cite): string {
    if (!$items) return '<p class="muted">مفيش بيانات كفاية في المصادر.</p>';
    return '<ul>' . implode('', array_map(fn($i) => '<li>' . $h($i['t']) . $cite($i['c'] ?? []) . '</li>', $items)) . '</ul>';
};
$sections = [];
$sec = function (string $id, string $title, string $body) use (&$sections): string {
    $sections[$id] = $title;
    return '<section id="' . $id . '"><h2>' . $title . '</h2>' . $body . '</section>';
};

// ─── الأقسام ───
$html = '';
$sumLabels = ['market' => '📊 السوق', 'audience' => '👥 الجمهور', 'competitors' => '🥊 المنافسون', 'content' => '📱 المحتوى', 'opps' => '💡 الفرص'];
$sumBody = '<ul>';
foreach ($sumLabels as $k => $l) {
    $s = $res['summary'][$k] ?? ['t' => '', 'c' => []];
    if ($s['t'] !== '') $sumBody .= '<li><b>' . $l . ':</b> ' . $h($s['t']) . $cite($s['c']) . '</li>';
}
$sumBody .= '</ul>' . (!empty($res['conclusion']) ? '<p class="box">' . $h($res['conclusion']) . '</p>' : '');
$html .= $sec('sum', 'الملخص التنفيذي', $sumBody);

if ($type !== 'short') {
    $m = $res['market'] ?? [];
    $kl = ['trend' => 'اتجاه', 'demand' => 'إشارة طلب', 'opportunity' => 'فرصة', 'risk' => 'مخاطر'];
    $body = ($m['overview'] ?? '') !== '' ? '<p>' . $h($m['overview']) . '</p>' : '';
    $body .= $li(array_map(fn($p) => ['t' => ($kl[$p['k']] ?? '') . ': ' . $p['t'], 'c' => $p['c']], $m['points'] ?? []));
    if (!empty($m['indicators'])) {
        $body .= '<div class="kpis">' . implode('', array_map(fn($i) => '<div><small>' . $h($i['label']) . '</small><b>' . $h($i['value']) . '</b>' . $cite($i['c']) . '</div>', $m['indicators'])) . '</div>';
    }
    $html .= $sec('market', 'تحليل السوق', $body);
}
if ($type === 'full' || $type === 'html') {
    $a = $res['audience'] ?? [];
    $body = '<h3>مؤكد من المصادر</h3>' . $li($a['confirmed'] ?? []);
    if (!empty($a['inferred'])) $body .= '<h3>استنتاج AI</h3><ul>' . implode('', array_map(fn($t) => '<li>' . $h($t) . '</li>', $a['inferred'])) . '</ul>';
    $pl = ['demographics' => 'الديموغرافيا', 'needs' => 'الاحتياجات', 'pains' => 'نقاط الألم', 'triggers' => 'محفزات الشراء'];
    $prof = array_filter($a['profile'] ?? []);
    if ($prof) $body .= '<table>' . implode('', array_map(fn($k) => '<tr><th>' . $pl[$k] . '</th><td>' . $h($prof[$k]) . '</td></tr>', array_keys($prof))) . '</table>';
    $html .= $sec('aud', 'الجمهور المستهدف', $body);

    $comps = $res['competitors'] ?? [];
    if ($comps) {
        $body = '';
        foreach ($comps as $c) {
            $rows = ['التموضع' => $c['positioning'], 'الخدمات' => $c['services'], 'الأسعار' => $c['prices'], 'العروض' => $c['offers'],
                     'السوشيال' => $c['social'], 'نقطة القوة' => $c['strength'], 'الفجوة' => $c['gap']];
            $inner = '<table>' . implode('', array_map(fn($k) => $rows[$k] !== '' ? '<tr><th>' . $k . '</th><td>' . $h($rows[$k]) . '</td></tr>' : '', array_keys($rows))) . '</table>';
            $head = $h($c['name']) . $cite($c['c']) . ($c['site'] ? ' · <a href="' . $h($c['site']) . '" target="_blank" rel="noopener nofollow noreferrer">' . $h(research_host($c['site'])) . '</a>' : '');
            $body .= $type === 'html' ? '<details><summary>' . $head . '</summary>' . $inner . '</details>' : '<h3>' . $head . '</h3>' . $inner;
        }
        if (!empty($res['criteria'])) {
            $body .= '<h3>مقارنة سريعة</h3><div class="tw"><table><tr><th>المعيار</th>' . implode('', array_map(fn($c) => '<th>' . $h($c['name']) . '</th>', $comps)) . '</tr>';
            foreach ($res['criteria'] as $ci => $cr) {
                $body .= '<tr><th>' . $h($cr) . '</th>' . implode('', array_map(fn($c) => '<td>' . $h($c['matrix'][$ci] ?? '—') . '</td>', $comps)) . '</tr>';
            }
            $body .= '</table></div>';
        }
        $html .= $sec('comp', 'تحليل المنافسين', $body);
    }
    $ct = $res['content'] ?? [];
    $body = '<h3>الموضوعات والصيغ</h3>' . $li($ct['formats'] ?? []) . '<h3>Hooks وأنماط CTA</h3>' . $li($ct['hooks'] ?? []);
    if (!empty($ct['ideas'])) $body .= '<h3>فرص محتوى محتملة</h3><ul>' . implode('', array_map(fn($t) => '<li>' . $h($t) . '</li>', $ct['ideas'])) . '</ul>';
    $html .= $sec('content', 'تحليل المحتوى', $body);

    if (!empty($res['pricing'])) {
        $body = '<p class="muted">مش بنألّف أسعار — أي سعر مش معلن في مصدر واضح مكتوب «غير متاح».</p><div class="tw"><table><tr><th>المنافس</th><th>السعر</th><th>هيكل العرض</th><th>الحالة</th></tr>';
        foreach ($res['pricing'] as $p) {
            $body .= '<tr><td>' . $h($p['competitor']) . '</td><td>' . $h($p['price']) . ($p['c'] ? $cite($p['c']) : '') . '</td><td>' . $h($p['structure']) . '</td><td>' . $h($p['status']) . '</td></tr>';
        }
        $html .= $sec('price', 'الأسعار والعروض', $body . '</table></div>');
    }
    if (!empty($res['keywords']) || !empty($res['trends'])) {
        $body = !empty($res['keywords']) ? '<p class="tags">' . implode('', array_map(fn($k) => '<span>' . $h($k) . '</span>', $res['keywords'])) . '</p>' : '';
        $html .= $sec('trends', 'الكلمات والاتجاهات', $body . $li($res['trends'] ?? []));
    }
}
if (!empty($res['opps'])) {
    $body = '';
    foreach ($res['opps'] as $o) {
        $body .= '<div class="opp"><small>' . $h($o['type']) . '</small><b>' . $h($o['headline']) . '</b>'
            . ($o['why'] ? '<p><b>ليه مهمة:</b> ' . $h($o['why']) . $cite($o['evidence']) . '</p>' : '')
            . ($o['use'] ? '<p><b>استخدامها:</b> ' . $h($o['use']) . '</p>' : '') . '</div>';
    }
    $html .= $sec('opps', 'الفرص التي اكتشفناها 💡', $body);
}
$body = '<ol class="src">';
foreach ($src as $s) {
    $body .= '<li id="src-' . (int) $s['n'] . '" value="' . (int) $s['n'] . '"><a href="' . $h($s['url']) . '" target="_blank" rel="noopener nofollow noreferrer">' . $h($s['title'] ?: $s['site']) . '</a>'
        . ' <small>— ' . $h($s['site']) . (($s['date'] ?? '') !== '' ? ' · ' . $h($s['date']) : '') . ($s['took'] ? ' · اتاخد منه: ' . $h($s['took']) : '') . '</small></li>';
}
$html .= $sec('src', 'المصادر (' . count($src) . ')', $body . '</ol>');

$toc = '<ol>' . implode('', array_map(fn($id, $t) => '<li><a href="#' . $id . '">' . $t . '</a></li>', array_keys($sections), $sections)) . '</ol>';
$date = research_ar_date(strtotime((string) $r['completed_at']));
$title = (string) $r['title'];
$doc = '<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
    . '<meta name="robots" content="noindex"><title>' . $h($title) . '</title><style>'
    . 'body{margin:0;font-family:"IBM Plex Sans Arabic",system-ui,"Segoe UI",Tahoma,sans-serif;background:#F4F8FC;color:#0B1526;line-height:1.85}'
    . 'main{max-width:900px;margin:0 auto;padding:28px 18px}header.cover{background:linear-gradient(135deg,#0B1526,#0B3C5D 60%,#0A6B8A);color:#fff;border-radius:24px;padding:36px 30px;margin-bottom:20px}'
    . 'header h1{margin:6px 0 10px;font-size:28px;line-height:1.4}header p{margin:2px 0;opacity:.9}section{background:#fff;border-radius:18px;padding:20px 24px;margin:14px 0;box-shadow:0 8px 24px rgba(12,70,130,.07)}'
    . 'h2{margin:0 0 10px;color:#0A5FCF;font-size:20px}h3{font-size:15.5px;margin:16px 0 6px}a{color:#0A63C9}sup a{text-decoration:none;font-weight:700;font-size:11px}'
    . '.ai{display:inline-block;font-size:11px;font-weight:700;color:#6E5BE0;background:#F1EEFF;border-radius:999px;padding:0 8px}.muted{color:#5B6478;font-size:14px}'
    . '.box{background:#EEF7FF;border-radius:12px;padding:12px 16px}table{width:100%;border-collapse:collapse;font-size:14px;margin:6px 0}td,th{border-bottom:1px solid #E6EEF6;padding:8px;text-align:right;vertical-align:top}th{color:#27324A;white-space:nowrap}'
    . '.tw{overflow-x:auto}.kpis{display:flex;gap:10px;flex-wrap:wrap}.kpis div{flex:1;min-width:150px;background:#F2F8FE;border-radius:12px;padding:10px 14px}.kpis small{display:block;color:#5B6478}'
    . '.tags span{display:inline-block;margin:3px;padding:2px 12px;border-radius:999px;background:#E3F1FF;color:#0A5FCF;font-size:13px}.opp{border:1px solid #E6EEF6;border-radius:14px;padding:12px 16px;margin:10px 0}'
    . '.opp small{color:#10A8A0;font-weight:700}.opp b{display:block;font-size:15.5px}.opp p{margin:6px 0 0;font-size:14px}.src li{margin:6px 0}.src small{color:#5B6478}'
    . 'details{border:1px solid #E6EEF6;border-radius:12px;padding:8px 14px;margin:8px 0}summary{cursor:pointer;font-weight:700}.foot{text-align:center;color:#5B6478;font-size:13px}'
    . '.print{position:fixed;left:16px;bottom:16px;border:0;border-radius:999px;padding:10px 18px;background:#0A5FCF;color:#fff;font:inherit;cursor:pointer}'
    . '@media print{.print{display:none}body{background:#fff}section{box-shadow:none;border:1px solid #E6EEF6}}</style></head><body><main>'
    . '<header class="cover"><div style="opacity:.8">Spread AI — ' . $kindName . '</div><h1>' . $h($title) . '</h1>'
    . ($brand && ($brand['business_name'] ?? '') !== '' ? '<p>' . $h($brand['business_name']) . (($brand['industry'] ?? '') !== '' ? ' · ' . $h($brand['industry']) : '') . '</p>' : '')
    . '<p>السوق: ' . $h($scope['market'] ?? '') . ' · ' . $h($scope['city'] ?? '') . ' · الفترة: ' . $h($scope['period'] ?? '') . ' · العمق: ' . $h(research_depths()[$scope['depth'] ?? 'medium'][0] ?? '') . '</p>'
    . '<p>تاريخ البحث: ' . $date . ' · ' . count($src) . ' مصادر</p></header>'
    . '<p class="muted">كل معلومة جنبها رقم مصدرها [n]، واللي من غير مصدر مكتوب عليها «استنتاج AI». مفيش أسعار أو أرقام متألفة.</p>'
    . '<section id="toc"><h2>المحتويات</h2>' . $toc . '</section>' . $html
    . '<p class="foot">تم الإنشاء بواسطة Spread AI · ' . $date . '</p>'
    . ($type === 'html' ? '<button class="print" onclick="window.print()">🖨 طباعة / PDF</button>' : '')
    . '</main></body></html>';

header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; script-src 'unsafe-inline'; img-src data:; base-uri 'none'; form-action 'none'");
if (!empty($_GET['dl'])) {
    $name = preg_replace('/[^\p{L}\p{N}_-]+/u', '-', $title) ?: 'research';
    header("Content-Disposition: attachment; filename=\"spread-research.html\"; filename*=UTF-8''" . rawurlencode($name . '.html'));
}
echo $doc;

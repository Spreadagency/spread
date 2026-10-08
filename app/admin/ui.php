<?php
declare(strict_types=1);

/* =========================================================
   Admin layout + small view helpers (same design as the prototype)
   ========================================================= */

const ADMIN_NAV = [
    ['الحملة', null, null, null],
    ['لوحة التحكم', 'index.php', 'grid', 'view'],
    ['المسجلين', 'leads.php', 'users', 'view'],
    ['الصور', 'images.php', 'image', 'view'],
    ['الإعدادات', null, null, null],
    ['SEO', 'seo.php', 'search', 'view'],
    ['التتبع (Pixel)', 'tracking.php', 'target', 'owner'],
    ['الربط والـ API', 'integrations.php', 'plug', 'owner'],
    ['محتوى الصفحة', 'content.php', 'file', 'view'],
    ['الإعدادات', 'settings.php', 'cog', 'owner'],
];

const STATUS_LABELS = ['new' => 'جديد', 'contacted' => 'اتكلم', 'booked' => 'حجز', 'not_interested' => 'مهتمش'];
const STATUS_CLASS = ['new' => 'b-new', 'contacted' => 'b-contacted', 'booked' => 'b-booked', 'not_interested' => 'b-lost'];
const GEN_LABELS = ['done' => 'اتولدت', 'failed' => 'فشلت', 'rejected' => 'اترفضت', 'processing' => 'جاري', 'uploaded' => 'اترفعت', 'none' => 'من غير صورة'];

function ic(string $name, string $cls = ''): string
{
    return '<svg class="ic ' . e($cls) . '"><use href="#i-' . e($name) . '"/></svg>';
}

function admin_page_start(string $title, string $active, array $opts = []): void
{
    $user = AdminAuth::user();
    $newCount = $user ? (int) q_value("SELECT COUNT(*) FROM leads WHERE status = 'new'") : 0;
    $usedToday = $user ? (int) q_value("SELECT COUNT(*) FROM generations WHERE status IN ('processing','done') AND started_at >= ?", [day_start_utc()]) : 0;
    $cap = Settings::int('limit_daily_global', 300);
    $flashes = take_flashes();
    ?><!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="csrf" content="<?= e(csrf_token()) ?>">
<title><?= e($title) ?> — لوحة التحكم</title>
<link rel="icon" href="<?= e(asset((string) Settings::get('logo'))) ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(asset('admin/assets/admin.css')) ?>">
</head>
<body<?= $user ? '' : ' class="guest"' ?>>
<?php require __DIR__ . '/icons.php'; ?>
<?php if (!$user): ?>
<main class="login-wrap" id="view">
<?php else: ?>
<div class="app" id="app">
  <aside class="sidebar" id="sidebar" aria-label="القائمة الرئيسية">
    <div class="sb-brand">
      <img src="<?= e(asset('assets/img/logo-white.png')) ?>" alt="">
      <div class="t"><b>لوحة التحكم</b><small><?= e(Settings::get('hero_title')) ?></small></div>
    </div>
    <nav class="nav">
      <?php foreach (ADMIN_NAV as [$label, $href, $icon, $perm]): ?>
        <?php if ($href === null): ?><span class="nav-label"><?= e($label) ?></span><?php continue; endif; ?>
        <?php if (!AdminAuth::can($perm)) continue; ?>
        <a href="<?= e($href) ?>" class="<?= $active === $href ? 'active' : '' ?>" title="<?= e($label) ?>"<?= $active === $href ? ' aria-current="page"' : '' ?>><?= ic($icon) ?><span><?= e($label) ?></span><?php if ($href === 'leads.php' && $newCount): ?><em class="count"><?= $newCount ?></em><?php endif; ?></a>
      <?php endforeach; ?>
    </nav>
    <div class="sb-foot">
      <div class="sb-usage">
        <div class="row between"><span>توليد النهارده</span><b class="ltr" style="color:#fff"><?= $usedToday ?> / <?= $cap ?></b></div>
        <div class="bar gold"><i style="width:<?= min(100, $cap ? round($usedToday / $cap * 100) : 0) ?>%"></i></div>
      </div>
      <button class="collapse-btn" id="collapseBtn" type="button" aria-label="طي القائمة"><?= ic('sidebar') ?><span>طي القائمة</span></button>
      <p class="made">صنعت بواسطة <a href="https://spreadagency.net" target="_blank" rel="noopener">Spread</a> · <a href="https://spreadagency.net" target="_blank" rel="noopener">Spreadagency.net</a></p>
    </div>
  </aside>
  <div class="main">
    <header class="topbar">
      <button class="icon-btn menu-btn" id="menuBtn" type="button" aria-label="فتح القائمة"><?= ic('menu') ?></button>
      <h1><?= e($title) ?></h1>
      <div class="tb-spacer"></div>
      <?php if (!empty($opts['range'])): ?>
      <form method="get" class="daterange-form">
        <?php foreach ($opts['keep'] ?? [] as $k => $v): ?><input type="hidden" name="<?= e($k) ?>" value="<?= e($v) ?>"><?php endforeach; ?>
        <label class="daterange"><?= ic('cal', 'sm') ?><span class="sr-only">المدة</span>
          <select name="range" data-autosubmit aria-label="المدة">
            <?php foreach (RANGE_OPTIONS as $k => $l): ?><option value="<?= $k ?>"<?= ($opts['range'] === $k) ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
          </select>
        </label>
      </form>
      <?php endif; ?>
      <form class="search" method="get" action="leads.php" role="search"><span class="sr-only">بحث</span><?= ic('search') ?><input name="q" placeholder="ابحث بالاسم أو الرقم…" value="<?= e($_GET['q'] ?? '') ?>" id="globalSearch"><kbd>/</kbd></form>
      <div class="menu-wrap">
        <button class="me" id="meBtn" type="button" aria-haspopup="true" aria-expanded="false"><span class="avatar"><?= e(initials((string) $user['name'])) ?></span><span class="t"><b><?= e($user['name']) ?></b><small class="ltr"><?= e(ucfirst($user['role'])) ?></small></span></button>
        <div class="menu card" id="meMenu" hidden>
          <a href="account.php"><?= ic('key', 'sm') ?>الحساب والباسورد</a>
          <a href="../" target="_blank" rel="noopener"><?= ic('globe', 'sm') ?>افتح الصفحة</a>
          <form method="post" action="logout.php"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><button type="submit"><?= ic('logout', 'sm') ?>تسجيل خروج</button></form>
        </div>
      </div>
    </header>
    <main class="content" id="view" tabindex="-1">
<?php endif; ?>
<div id="flashes" hidden><?php foreach ($flashes as [$t, $m]): ?><span data-type="<?= e($t) ?>"><?= e($m) ?></span><?php endforeach; ?></div>
<?php
}

function admin_page_end(): void
{
    $user = AdminAuth::user();
    if ($user) {
        echo "    </main>\n  </div>\n</div>\n";
    } else {
        echo "</main>\n";
    }
    ?>
<div class="toasts" id="toasts" aria-live="polite"></div>
<div id="layer"></div>
<script src="<?= e(asset('admin/assets/admin.js')) ?>" defer></script>
</body>
</html>
<?php
}

/* ---------------- formatting ---------------- */

function initials(string $name): string
{
    $parts = preg_split('/\s+/u', trim($name)) ?: [];
    $out = '';
    foreach (array_slice($parts, 0, 2) as $p) {
        $out .= mb_substr($p, 0, 1);
    }
    return $out ?: '؟';
}

/** UTC DATETIME → site time zone string. */
function local_dt(?string $utc, string $fmt = 'j/n · g:i A'): string
{
    if (!$utc) {
        return '—';
    }
    $d = (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone(date_default_timezone_get()));
    return strtr($d->format($fmt), ['AM' => 'ص', 'PM' => 'م']);
}

function rel_time(?string $utc): string
{
    if (!$utc) {
        return 'أبدًا';
    }
    $s = time() - strtotime($utc . ' UTC');
    return match (true) {
        $s < 60 => 'دلوقتي',
        $s < 3600 => 'من ' . intdiv($s, 60) . ' دقيقة',
        $s < 86400 => 'من ' . intdiv($s, 3600) . ' ساعة',
        $s < 86400 * 30 => 'من ' . intdiv($s, 86400) . ' يوم',
        default => local_dt($utc, 'j/n/Y'),
    };
}

function phone_fmt(string $p): string
{
    return substr($p, 0, 3) . ' ' . substr($p, 3, 4) . ' ' . substr($p, 7);
}

function status_badge(string $s): string
{
    return '<span class="badge ' . (STATUS_CLASS[$s] ?? 'b-lost') . '">' . e(STATUS_LABELS[$s] ?? $s) . '</span>';
}

function gen_badge(?string $g): string
{
    $g = $g ?: 'none';
    $cls = match ($g) {
        'done' => 'ok',
        'failed', 'rejected' => 'err',
        'processing', 'uploaded' => 'warn',
        default => '',
    };
    return '<span class="status ' . $cls . '"><i></i>' . e(GEN_LABELS[$g] ?? $g) . '</span>';
}

function phone_cell(string $phone, string $name = ''): string
{
    $wa = 'https://wa.me/' . phone_international($phone) . '?text=' . rawurlencode('أهلًا ' . first_name($name) . '، معاك فريق ' . Settings::get('doctor_name') . ' بخصوص محاكاة التخسيس.');
    return '<span class="phone"><span class="ltr mono">' . e(phone_fmt($phone)) . '</span>'
        . '<a class="wa-mini" href="' . e($wa) . '" target="_blank" rel="noopener" title="افتح واتساب" aria-label="واتساب">' . ic('wa', 'sm') . '</a>'
        . '<button type="button" class="copy-mini" data-copy="' . e($phone) . '" title="انسخ الرقم" aria-label="انسخ الرقم">' . ic('copy', 'sm') . '</button></span>';
}

function empty_block(string $icon, string $title, string $body, string $cta = ''): string
{
    return '<div class="empty"><span class="eico">' . ic($icon, 'lg') . '</span><h3>' . e($title) . '</h3><p>' . e($body) . '</p>' . $cta . '</div>';
}

/* ---------------- form helpers ---------------- */

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function ro(): string
{
    return AdminAuth::can('edit') ? '' : ' disabled';
}

function f_text(string $name, string $label, ?string $value, array $o = []): string
{
    $id = 'f_' . $name;
    $type = $o['type'] ?? 'text';
    $dir = !empty($o['ltr']) ? ' dir="ltr"' : '';
    $cls = 'input' . (!empty($o['mono']) ? ' mono' : '');
    $attrs = '';
    foreach (['placeholder', 'maxlength', 'min', 'max', 'pattern', 'inputmode'] as $a) {
        if (isset($o[$a])) {
            $attrs .= ' ' . $a . '="' . e((string) $o[$a]) . '"';
        }
    }
    if (!empty($o['counter'])) {
        $attrs .= ' data-counter="' . (int) $o['counter'] . '"';
    }
    $head = '<div class="row between"><label for="' . $id . '">' . e($label) . '</label>' . (!empty($o['counter']) ? '<span class="counter" data-for="' . $id . '"></span>' : '') . '</div>';
    return '<div class="field' . (!empty($o['full']) ? ' full' : '') . '">' . $head
        . '<input class="' . $cls . '" type="' . e($type) . '" id="' . $id . '" name="' . e($name) . '" value="' . e($value) . '"' . $dir . $attrs . (!empty($o['required']) ? ' required' : '') . ro() . '>'
        . (!empty($o['hint']) ? '<span class="hint">' . $o['hint'] . '</span>' : '') . '</div>';
}

function f_textarea(string $name, string $label, ?string $value, array $o = []): string
{
    $id = 'f_' . $name;
    $cls = !empty($o['code']) ? 'code' : 'textarea';
    $attrs = !empty($o['counter']) ? ' data-counter="' . (int) $o['counter'] . '"' : '';
    $attrs .= !empty($o['ltr']) || !empty($o['code']) ? ' dir="ltr"' : '';
    $head = '<div class="row between"><label for="' . $id . '">' . e($label) . '</label>' . (!empty($o['counter']) ? '<span class="counter" data-for="' . $id . '"></span>' : '') . '</div>';
    return '<div class="field' . (!empty($o['full']) ? ' full' : '') . '">' . $head
        . '<textarea class="' . $cls . '" id="' . $id . '" name="' . e($name) . '" rows="' . (int) ($o['rows'] ?? 3) . '" spellcheck="' . (!empty($o['code']) ? 'false' : 'true') . '"' . $attrs . ro() . '>' . e($value) . '</textarea>'
        . (!empty($o['hint']) ? '<span class="hint">' . $o['hint'] . '</span>' : '') . '</div>';
}

function f_toggle(string $name, string $label, bool $on, string $hint = ''): string
{
    return '<label class="switch"><input type="hidden" name="' . e($name) . '" value="0"><input type="checkbox" name="' . e($name) . '" value="1"' . ($on ? ' checked' : '') . ro() . '><span class="tr"></span><span class="small">' . e($label) . ($hint ? ' <span class="muted">— ' . e($hint) . '</span>' : '') . '</span></label>';
}

function f_select(string $name, string $label, string $value, array $options, array $o = []): string
{
    $id = 'f_' . $name;
    $html = '<div class="field' . (!empty($o['full']) ? ' full' : '') . '"><label for="' . $id . '">' . e($label) . '</label><select class="select" id="' . $id . '" name="' . e($name) . '"' . ro() . '>';
    foreach ($options as $k => $l) {
        $html .= '<option value="' . e((string) $k) . '"' . ((string) $k === $value ? ' selected' : '') . '>' . e($l) . '</option>';
    }
    return $html . '</select>' . (!empty($o['hint']) ? '<span class="hint">' . $o['hint'] . '</span>' : '') . '</div>';
}

/** Secret input: never prints the value; empty = keep. */
function f_secret(string $name, string $label, string $hint = ''): string
{
    $id = 'f_' . $name;
    $masked = Settings::masked($name);
    return '<div class="field"><label for="' . $id . '">' . e($label) . '</label><div class="input-group">'
        . '<input class="input mono" dir="ltr" type="password" id="' . $id . '" name="' . e($name) . '" autocomplete="new-password" placeholder="' . ($masked ? 'محفوظ — اكتب قيمة جديدة لتغييره' : 'مش متضاف') . '"' . ro() . '>'
        . '<span class="addon"><button type="button" data-reveal="' . $id . '" aria-label="إظهار">' . ic('eye', 'sm') . '</button><span class="ltr">' . e($masked ?: '—') . '</span></span></div>'
        . ($masked && AdminAuth::can('edit') ? '<label class="row small muted"><input type="checkbox" class="cbx" name="' . e($name) . '__clear" value="1"> امسح القيمة المحفوظة</label>' : '')
        . ($hint ? '<span class="hint">' . $hint . '</span>' : '') . '</div>';
}

function f_image(string $name, string $label, string $current, string $hint = ''): string
{
    $src = $current ? asset($current) : '';
    return '<div class="field"><label>' . e($label) . '</label><div class="row-16">'
        . ($src ? '<img src="' . e($src) . '" alt="" class="img-prev">' : '<span class="img-prev empty">' . ic('image') . '</span>')
        . (AdminAuth::can('edit') ? '<label class="btn btn-secondary btn-sm">' . ic('upload', 'sm') . 'ارفع صورة<input type="file" name="' . e($name) . '" accept="image/png,image/jpeg,image/webp" class="sr-only" data-preview></label>' : '')
        . '</div>' . ($hint ? '<span class="hint">' . $hint . '</span>' : '') . '</div>';
}

function save_bar(string $label = 'احفظ التغييرات'): string
{
    if (!AdminAuth::can('edit')) {
        return '<p class="hint">' . ic('info', 'sm') . ' صلاحيتك قراءة بس.</p>';
    }
    return '<button class="btn btn-primary" type="submit" data-loading>' . ic('check', 'sm') . e($label) . '</button>';
}

function pager(int $page, int $pages, array $query): string
{
    if ($pages <= 1) {
        return '';
    }
    $html = '<div class="pages">';
    $win = array_unique(array_filter([1, $page - 2, $page - 1, $page, $page + 1, $page + 2, $pages], static fn ($p) => $p >= 1 && $p <= $pages));
    sort($win);
    $prev = 0;
    foreach ($win as $p) {
        if ($prev && $p > $prev + 1) {
            $html .= '<span class="muted">…</span>';
        }
        $html .= '<a href="?' . e(http_build_query(['page' => $p] + $query)) . '" class="' . ($p === $page ? 'on' : '') . '">' . $p . '</a>';
        $prev = $p;
    }
    return $html . '</div>';
}

/* ---------------- date ranges ---------------- */

const RANGE_OPTIONS = ['today' => 'النهارده', '7d' => 'آخر 7 أيام', '30d' => 'آخر 30 يوم', '90d' => 'آخر 90 يوم', 'all' => 'من البداية'];

/** Start of today in the site time zone, as UTC. */
function day_start_utc(string $modify = 'today'): string
{
    return (new DateTimeImmutable($modify))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

/** @return array{0:string,1:string,2:int} [fromUtc, toUtc, days] */
function range_bounds(string $range): array
{
    $to = utc_now();
    return match ($range) {
        'today' => [day_start_utc(), $to, 1],
        '7d' => [day_start_utc('today -6 days'), $to, 7],
        '90d' => [day_start_utc('today -89 days'), $to, 90],
        'all' => ['2000-01-01 00:00:00', $to, max(1, (int) ceil((time() - strtotime((string) (q_value('SELECT MIN(created_at) FROM leads') ?: utc_now()) . ' UTC')) / 86400) + 1)],
        default => [day_start_utc('today -29 days'), $to, 30],
    };
}

function tz_offset(): string
{
    return (new DateTimeImmutable('now'))->format('P');
}

/* ---------------- charts (inline SVG, RTL: newest on the left) ---------------- */

function svg_line_chart(array $series, string $label): string
{
    $n = count($series);
    if ($n < 2) {
        $series[] = ['d' => '', 'v' => 0];
        $n = 2;
    }
    $W = 760;
    $H = 260;
    $pt = 16;
    $pr = 40;
    $pb = 28;
    $pl = 24;
    $max = max(5, (int) (ceil(max(array_column($series, 'v')) / 5) * 5));
    $x = static fn ($i) => $W - $pr - $i * (($W - $pr - $pl) / ($n - 1));
    $y = static fn ($v) => $pt + ($H - $pt - $pb) * (1 - $v / $max);
    $grid = '';
    foreach ([0, .25, .5, .75, 1] as $f) {
        $gy = $y($max * $f);
        $grid .= '<line x1="' . $pl . '" x2="' . ($W - $pr) . '" y1="' . $gy . '" y2="' . $gy . '" stroke="#E2EAF2"' . ($f ? ' stroke-dasharray="3 4"' : '') . '/>'
            . '<text x="' . ($W - $pr + 8) . '" y="' . ($gy + 4) . '" font-size="11" fill="#8597A8">' . round($max * $f) . '</text>';
    }
    $pts = [];
    $dots = '';
    $labels = '';
    $step = max(1, (int) ceil($n / 7));
    foreach ($series as $i => $s) {
        $pts[] = round($x($i), 1) . ',' . round($y($s['v']), 1);
        $dots .= '<circle cx="' . round($x($i), 1) . '" cy="' . round($y($s['v']), 1) . '" r="3.5" fill="#fff" stroke="#1F73B7" stroke-width="2"><title>' . e($s['d']) . ': ' . (int) $s['v'] . '</title></circle>';
        if ($i % $step === 0 || $i === $n - 1) {
            $labels .= '<text x="' . round($x($i), 1) . '" y="' . ($H - 6) . '" font-size="11" fill="#8597A8" text-anchor="middle">' . e($s['d']) . '</text>';
        }
    }
    $line = implode(' L', $pts);
    $area = 'M' . $line . ' L' . round($x($n - 1), 1) . ',' . $y(0) . ' L' . round($x(0), 1) . ',' . $y(0) . 'Z';
    return '<svg viewBox="0 0 ' . $W . ' ' . $H . '" width="100%" height="100%" preserveAspectRatio="none" role="img" aria-label="' . e($label) . '">'
        . '<defs><linearGradient id="lg" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#1F73B7" stop-opacity=".22"/><stop offset="1" stop-color="#1F73B7" stop-opacity="0"/></linearGradient></defs>'
        . $grid . '<path d="' . $area . '" fill="url(#lg)"/><path d="M' . $line . '" fill="none" stroke="#1F73B7" stroke-width="2.5" stroke-linejoin="round"/>' . $dots . $labels . '</svg>';
}

function spark(array $vals, string $color = '#1F73B7'): string
{
    $vals = array_values($vals);
    if (count($vals) < 2) {
        return '<div class="spark"></div>';
    }
    $w = 120;
    $h = 32;
    $mx = max($vals);
    $mn = min($vals);
    $n = count($vals);
    $pts = [];
    foreach ($vals as $i => $v) {
        $pts[] = round($w - $i * ($w / ($n - 1)), 1) . ',' . round($h - 3 - ($v - $mn) / (($mx - $mn) ?: 1) * ($h - 6), 1);
    }
    return '<svg class="spark" viewBox="0 0 ' . $w . ' ' . $h . '" preserveAspectRatio="none" width="100%" aria-hidden="true"><path d="M' . implode('L', $pts) . '" fill="none" stroke="' . $color . '" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" vector-effect="non-scaling-stroke"/></svg>';
}

<?php
/** قالب لوحة تحكم الموقع */
require_once __DIR__ . '/auth.php';
sa_require();
$__me = sa_admin();
$nav = [
    ''               => ['📊', 'اللوحة',            'dashboard.php'],
    'settings'       => ['⚙️', 'الهيدر والفوتر',    'settings.php'],
    'sections'       => ['👁', 'الأقسام والعناوين', 'sections.php'],
    'slides'         => ['🖼', 'السلايدر',          'slides.php'],
    'brands'         => ['🏷', 'العلامات التجارية', 'brands.php'],
    'promos'         => ['📣', 'الإعلانات والعروض', 'promos.php'],
    'problems'       => ['❓', 'المشاكل',           'problems.php'],
    'solutions'      => ['💡', 'عن المنصة والحلول', 'solutions.php'],
    'steps'          => ['🪜', 'خطوات العمل',       'steps.php'],
    'services'       => ['🛠', 'الخدمات',           'services.php'],
    'gallery'        => ['🎨', 'التصميمات',         'gallery.php'],
    'packages'       => ['📦', 'الباقات',           'packages.php'],
    'videos'         => ['🎬', 'فيديوهات الشرح',    'videos.php'],
    'pages'          => ['📄', 'الصفحات الداخلية',  'pages.php'],
];
?><!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($__t ?? 'لوحة الموقع') ?> — إدارة الموقع</title>
<link href="https://fonts.googleapis.com/css2?family=Almarai:wght@400;700;800&family=IBM+Plex+Sans+Arabic:wght@400;500;600&display=swap" rel="stylesheet">
<style>
:root{--bg:#F5F2EC;--ink:#0B0B0F;--dim:#6B6862;--blue:#0F3CC9;--turq:#14B8A6;--line:rgba(11,11,15,.13);--card:#fff}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--ink);font-family:"IBM Plex Sans Arabic",system-ui,sans-serif;line-height:1.7}
a{color:inherit;text-decoration:none}
.wrap{display:grid;grid-template-columns:250px 1fr;min-height:100vh}
aside{background:#0E0E14;color:#F5F2EC;padding:20px 14px;position:sticky;top:0;height:100vh;overflow-y:auto}
aside .lg{font-family:Almarai;font-weight:800;letter-spacing:.14em;font-size:14px;padding:0 10px 16px;border-bottom:1px solid rgba(255,255,255,.12);margin-bottom:12px}
aside a{display:flex;gap:10px;align-items:center;padding:9px 11px;border-radius:10px;font-size:13.5px;color:rgba(245,242,236,.72);margin-bottom:2px}
aside a:hover{background:rgba(255,255,255,.07);color:#fff}
aside a.on{background:var(--blue);color:#fff}
main{padding:26px clamp(14px,2.5vw,34px)}
.top{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:22px}
.top h1{font-family:Almarai;font-size:24px;margin:0}
.card{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:20px;margin-bottom:18px}
.card h3{font-family:Almarai;font-size:16px;margin:0 0 14px}
label{display:block;font-size:13px;font-weight:600;margin-bottom:5px}
.f{margin-bottom:13px}
input[type=text],input[type=email],input[type=password],input[type=url],input[type=number],input[type=date],select,textarea{
width:100%;padding:9px 12px;border:1px solid var(--line);border-radius:10px;font-family:inherit;font-size:14px;background:#fff}
textarea{resize:vertical;min-height:78px}
.row{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px}
.btn{display:inline-flex;align-items:center;gap:7px;padding:10px 17px;border:0;border-radius:10px;background:var(--blue);color:#fff;font-family:inherit;font-size:13.5px;font-weight:600;cursor:pointer;transition:opacity .2s}
.btn:hover{opacity:.88}
.btn.g{background:transparent;color:var(--ink);border:1px solid var(--line)}
.btn.d{background:#c0392b}
.btn.s{padding:6px 11px;font-size:12.5px}
table{width:100%;border-collapse:collapse;font-size:13.5px}
th,td{padding:10px 8px;text-align:start;border-bottom:1px solid var(--line);vertical-align:middle}
th{font-size:12px;color:var(--dim);font-weight:600}
.tw{overflow-x:auto}
.thumb{width:52px;height:52px;object-fit:cover;border-radius:8px;background:#eee}
.chip{display:inline-block;padding:3px 10px;border-radius:99px;font-size:11px;background:rgba(11,11,15,.07)}
.chip.on{background:var(--turq);color:#fff}
.hint{font-size:12px;color:var(--dim);margin-top:4px}
@media(max-width:820px){.wrap{grid-template-columns:1fr}aside{position:static;height:auto}}
</style>
<script src="<?= e(s_url('site-assets/js/admin.js')) ?>?v=<?= @filemtime(dirname(__DIR__) . '/site-assets/js/admin.js') ?: time() ?>" defer></script>
</head>
<body>
<div class="wrap">
  <aside>
    <div class="lg">SPREAD — إدارة الموقع</div>
    <?php $cur = basename($_SERVER['SCRIPT_NAME']); foreach ($nav as $item): ?>
      <a href="<?= e($item[2]) ?>" class="<?= $cur === $item[2] ? 'on' : '' ?>"><span><?= $item[0] ?></span><span><?= e($item[1]) ?></span></a>
    <?php endforeach; ?>
    <div style="border-top:1px solid rgba(255,255,255,.12);margin-top:12px;padding-top:12px">
      <a href="<?= e(s_url('index.php')) ?>" target="_blank">🌐 شوف الموقع</a>
      <a href="logout.php">⏻ خروج</a>
    </div>
  </aside>
  <main>
    <div class="top">
      <h1><?= e($__t ?? 'لوحة الموقع') ?></h1>
      <span style="font-size:13px;color:var(--dim)"><?= e($__me['name']) ?></span>
    </div>
    <?= s_flash_render() ?>

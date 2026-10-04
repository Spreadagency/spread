<?php
require_once __DIR__ . '/auth.php';
sa_require();
$stats = [
    ['🖼', 'شرائح السلايدر', (int) (s_one('SELECT COUNT(*) c FROM site_slides')['c'] ?? 0), 'slides.php'],
    ['🎨', 'تصميمات المعرض', (int) (s_one('SELECT COUNT(*) c FROM site_gallery')['c'] ?? 0), 'gallery.php'],
    ['📦', 'الباقات', (int) (s_one('SELECT COUNT(*) c FROM site_packages')['c'] ?? 0), 'packages.php'],
    ['📄', 'الصفحات', (int) (s_one('SELECT COUNT(*) c FROM site_pages')['c'] ?? 0), 'pages.php'],
    ['📣', 'العروض', (int) (s_one('SELECT COUNT(*) c FROM site_promos')['c'] ?? 0), 'promos.php'],
    ['🎬', 'الفيديوهات', (int) (s_one('SELECT COUNT(*) c FROM site_videos')['c'] ?? 0), 'videos.php'],
];
$hidden = s_all('SELECT label_ar FROM site_sections WHERE is_visible = 0');
$__t = 'لوحة الموقع';
include __DIR__ . '/layout.php';
?>
<div class="card" style="background:linear-gradient(135deg,#0F3CC9,#14B8A6);color:#fff;border:0">
  <h3 style="color:#fff;margin-bottom:6px">أهلًا <?= e($__me['name']) ?> 👋</h3>
  <p style="margin:0;opacity:.9;font-size:14px">من هنا بتتحكم في كل حاجة في الموقع التعريفي — من غير ما تلمس المنصة.</p>
  <div style="margin-top:14px;display:flex;gap:8px;flex-wrap:wrap">
    <a href="<?= e(s_url('index.php')) ?>" target="_blank" class="btn" style="background:#fff;color:#0F3CC9">🌐 شوف الموقع</a>
    <a href="settings.php" class="btn" style="background:rgba(255,255,255,.18)">⚙️ الإعدادات</a>
  </div>
</div>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:14px;margin-bottom:18px">
  <?php foreach ($stats as [$ic, $lb, $n, $lnk]): ?>
    <a href="<?= e($lnk) ?>" class="card" style="margin:0;display:flex;gap:12px;align-items:center;transition:transform .3s">
      <span style="font-size:26px"><?= $ic ?></span>
      <span><b style="font-size:22px;font-family:Almarai"><?= $n ?></b><br><span style="font-size:12.5px;color:var(--dim)"><?= e($lb) ?></span></span>
    </a>
  <?php endforeach; ?>
</div>

<?php if ($hidden): ?>
  <div class="card" style="background:#fdf6e3;border-color:#f0c36d">
    ⚠️ فيه أقسام مخفية من الصفحة الرئيسية:
    <b><?= e(implode('، ', array_column($hidden, 'label_ar'))) ?></b>
    — <a href="sections.php" style="color:var(--blue);text-decoration:underline">تحكّم فيها</a>
  </div>
<?php endif; ?>

<div class="card">
  <h3>ابدأ من هنا</h3>
  <ol style="margin:0;padding-inline-start:20px;line-height:2.2;font-size:14px">
    <li><a href="settings.php" style="color:var(--blue)">الإعدادات</a> — حط اللوجو واسم الموقع وبيانات التواصل وروابط المنصة</li>
    <li><a href="slides.php" style="color:var(--blue)">السلايدر</a> — ارفع صور الخدمات اللي هتظهر في أول الصفحة</li>
    <li><a href="gallery.php" style="color:var(--blue)">التصميمات</a> — استورد شغل حقيقي من المنصة بضغطة</li>
    <li><a href="packages.php" style="color:var(--blue)">الباقات</a> — أسعارك ومميزات كل باقة</li>
    <li><a href="sections.php" style="color:var(--blue)">الأقسام</a> — اخفي أي قسم مش عايزه دلوقتي</li>
  </ol>
</div>
<?php include __DIR__ . '/layout-end.php'; ?>

<?php
/**
 * Spread AI v2 — الشريط العلوي الجديد
 * ديسكتوب: التاريخ + عنوان الصفحة · بحث · جرس · الحساب
 * موبايل: اللوجو · جرس · الحساب
 * + تبويبات القسم (الصفحات المدمجة)
 */
require_once __DIR__ . '/../../includes/ui-v2.php';

$user = $user ?? current_user();
$active = $active ?? '';
$__section = ui_section_of($active);
$__notif = ui_notifications((int) $user['id']);
$__secLabel = $__section ? ui_sections()[$__section][0] : '';
$__title = $page_title ?? $__secLabel;
if ($__section === 'dashboard') $__title = 'لوحة التحكم';
$__name = trim((string) ($user['name'] ?? '')) ?: 'مستخدم';
$__first = explode(' ', $__name)[0];
$__tabs = $__section ? ui_subtabs($__section) : [];
?>
<header class="v2-top">
    <!-- موبايل: اللوجو -->
    <a href="<?= url('dashboard.php') ?>" class="v2-logo v2-logo-m">
        <img src="<?= url('assets/img/spread-mark-128.png') ?>" alt="" width="34" height="28">
        <span><b>Spread <i>AI</i></b><small>CREATE · PLAN · PUBLISH</small></span>
    </a>

    <!-- ديسكتوب: التاريخ والعنوان -->
    <div class="v2-title">
        <small><?= e(ui_arabic_date()) ?></small>
        <h1><?= e($__title) ?></h1>
    </div>

    <form class="v2-search" action="<?= url('content-history.php') ?>" method="get" role="search">
        <?= ui_icon('search', 18) ?>
        <input type="search" name="q" placeholder="ابحث في حملاتك ومحتواك..." aria-label="بحث" value="<?= e($_GET['q'] ?? '') ?>">
    </form>

    <div class="v2-top-act">
        <div class="v2-bell-wrap">
            <button type="button" class="v2-iconbtn v2-bell" aria-label="الإشعارات" aria-expanded="false" aria-haspopup="true"
                    onclick="v2Bell(event)">
                <?= ui_icon('bell', 20) ?>
                <?php if ($__notif['badge'] > 0): ?><em class="v2-dot"><?= $__notif['badge'] ?></em><?php endif; ?>
            </button>
            <div class="v2-pop" id="v2-bell-pop" hidden>
                <div class="v2-pop-head">الإشعارات</div>
                <?php foreach ($__notif['items'] as $n): ?>
                    <?php $tag = $n['url'] ? 'a' : 'div'; ?>
                    <<?= $tag ?> class="v2-note v2-note-<?= e($n['tone']) ?>" <?= $n['url'] ? 'href="' . e(preg_match('#^https?://#', $n['url']) ? $n['url'] : url($n['url'])) . '"' : '' ?>>
                        <span class="v2-note-ic"><?= ui_icon($n['icon'], 18) ?></span>
                        <span class="v2-note-txt">
                            <b><?= e($n['title']) ?></b>
                            <?php if (!empty($n['sub'])): ?><small><?= e($n['sub']) ?></small><?php endif; ?>
                            <?php if ($n['type'] === 'credits'): ?>
                                <span class="bar"><span class="bar-fill" style="width:<?= 100 - (int) $__notif['usage']['pct'] ?>%"></span></span>
                            <?php endif; ?>
                        </span>
                    </<?= $tag ?>>
                <?php endforeach; ?>
                <?php if (count(array_filter($__notif['items'], fn($i) => $i['type'] === 'action')) === 0): ?>
                    <div class="v2-note-empty">✓ مفيش حاجة محتاجة تعديل</div>
                <?php endif; ?>
            </div>
        </div>

        <div class="v2-me-wrap">
        <button type="button" class="v2-me" aria-label="قائمة الحساب" aria-haspopup="true" aria-expanded="false" aria-controls="v2-me-pop" onclick="v2Me(event)">
            <?php
            // صورة الحساب (من الإعدادات أو جوجل) — وإلا الحروف الأولى
            $__av = (string) ($user['avatar_url'] ?? '');
            if ($__av !== '' && !preg_match('#^https?://#i', $__av)) { require_once __DIR__ . '/../../includes/uploader.php'; $__av = (string) upload_url($__av); }
            ?>
            <span class="v2-avatar"><?php if ($__av !== ''): ?><img src="<?= e($__av) ?>" alt="" referrerpolicy="no-referrer"><?php else: ?><?= e(function_exists('initials') ? initials($__name) : mb_substr($__name, 0, 1)) ?><?php endif; ?></span>
            <span class="v2-me-txt"><b><?= e(mb_substr($__first, 0, 14)) ?></b><small><?= ($__notif['usage']['show'] ?? true) ? (int) $__notif['usage']['balance'] . ' كريدت' : 'استخدمت ' . (int) $__notif['usage']['pct'] . '%' ?></small></span>
        </button>
        <?php
        // 10: الإعدادات خرجت من الشريط السفلي ← قائمة الحساب
        $__menu = [
            ['profile.php', 'user', 'حسابي'],
            ['profile.php#sx-h-sec', 'lock', 'كلمة المرور والأمان'],
            ['packages.php', 'star', 'الاشتراك والباقات'],
            ['payments.php', 'file', 'المدفوعات والفواتير'],
            ['credits.php', 'coin', 'الرصيد'],
            ['profile.php#sx-h-acc', 'link', 'الحسابات المرتبطة'],
            ['social-accounts.php', 'send', 'ربط السوشيال'],
            ['help.php', 'help', 'دليل المنصة'],
        ];
        ?>
        <div class="v2-pop v2-me-pop" id="v2-me-pop" hidden>
            <div class="v2-me-head"><b><?= e($__name) ?></b><small dir="ltr"><?= e((string) ($user['email'] ?? '')) ?></small></div>
            <?php foreach ($__menu as [$href, $icon, $label]): ?>
                <a href="<?= e(url($href)) ?>" class="v2-me-item"><?= ui_icon($icon, 18) ?><span><?= e($label) ?></span></a>
            <?php endforeach; ?>
            <a href="<?= url('logout.php') ?>" class="v2-me-item danger"><?= ui_icon('logout', 18) ?><span>تسجيل الخروج</span></a>
        </div>
        </div>
    </div>
</header>

<?php if (count($__tabs) > 1): ?>
<nav class="v2-tabs" aria-label="<?= e($__secLabel) ?>">
    <?php foreach ($__tabs as [$lbl, $href, $key]): ?>
        <a href="<?= url($href) ?>" class="<?= $active === $key ? 'on' : '' ?>"><?= e($lbl) ?></a>
    <?php endforeach; ?>
</nav>
<?php endif; ?>

<?= render_flash() ?>

<script>
function v2Bell(e) {
  e.stopPropagation();
  var pop = document.getElementById('v2-bell-pop'), btn = e.currentTarget;
  var open = pop.hasAttribute('hidden');
  if (open) { pop.removeAttribute('hidden'); } else { pop.setAttribute('hidden', ''); }
  btn.setAttribute('aria-expanded', open ? 'true' : 'false');
}
function v2Me(e) {
  e.stopPropagation();
  var pop = document.getElementById('v2-me-pop'), btn = e.currentTarget;
  var bell = document.getElementById('v2-bell-pop'); if (bell) bell.setAttribute('hidden', '');
  var open = pop.hasAttribute('hidden');
  if (open) { pop.removeAttribute('hidden'); var f = pop.querySelector('a'); if (f) f.focus(); } else { pop.setAttribute('hidden', ''); }
  btn.setAttribute('aria-expanded', open ? 'true' : 'false');
}
document.addEventListener('click', function (e) {
  var mp = document.getElementById('v2-me-pop');
  if (mp && !mp.hasAttribute('hidden') && !mp.contains(e.target)) {
    mp.setAttribute('hidden', '');
    var mb = document.querySelector('.v2-me'); if (mb) mb.setAttribute('aria-expanded', 'false');
  }
  var pop = document.getElementById('v2-bell-pop');
  if (pop && !pop.hasAttribute('hidden') && !pop.contains(e.target)) {
    pop.setAttribute('hidden', '');
    var b = document.querySelector('.v2-bell'); if (b) b.setAttribute('aria-expanded', 'false');
  }
});
document.addEventListener('keydown', function (e) {
  if (e.key === 'Escape') {
    var p = document.getElementById('v2-bell-pop'); if (p) p.setAttribute('hidden', '');
    var m = document.getElementById('v2-me-pop');
    if (m && !m.hasAttribute('hidden')) { m.setAttribute('hidden', ''); var b = document.querySelector('.v2-me'); if (b) { b.setAttribute('aria-expanded', 'false'); b.focus(); } }
  }
});
</script>

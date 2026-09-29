<?php
/**
 * Spread AI v2 — منتقي صورة الستايل المرجعية (partial مشترك)
 * الترتيب المطلوب: صور العميل اللي رفعها أولًا ← بعدين مفضلاته من معرض الإلهام
 * معرض الإلهام بيظهر منه المفضلات (❤) فقط — مش كل الصور
 * الاختيار في data-ref: brand:ID / lib:ID / personal
 */
$pickerBrand = $brand ?? (function_exists('user_brand') ? user_brand() : null);
$pickerUser = current_user();

$pickerItems = [];

// 1) صور البراند اللي العميل رفعها (أو ضافها بلينك) — الأول
if ($pickerBrand) {
    try {
        foreach (array_slice(get_brand_images((int) $pickerBrand['id']), 0, 12) as $bi) {
            $src = !empty($bi['image_url']) ? $bi['image_url'] : url('storage/' . $bi['image_path']);
            $pickerItems[] = ['ref' => 'brand:' . $bi['id'], 'src' => $src, 'tag' => '◈', 'title' => 'صورتك'];
        }
    } catch (\Throwable $e) {
    }
    if (!empty($pickerBrand['personal_image_path'])) {
        $pickerItems[] = ['ref' => 'personal', 'src' => url('storage/' . $pickerBrand['personal_image_path']), 'tag' => '🤳', 'title' => 'صورتك الشخصية'];
    }
}

// 2) مفضلات معرض الإلهام فقط (اللي حاططها ❤)
try {
    $favs = db_all(
        'SELECT m.id, m.image_path, m.image_url FROM user_media_selections s
         JOIN media_library m ON m.id = s.media_id AND m.is_active = 1
         WHERE s.user_id = ? ORDER BY s.id DESC LIMIT 12',
        [$pickerUser['id']]
    );
    foreach ($favs as $f) {
        $pickerItems[] = ['ref' => 'lib:' . $f['id'], 'src' => media_display_url($f), 'tag' => '❤', 'title' => 'مفضلتك'];
    }
} catch (\Throwable $e) {
}
?>
<div class="field">
    <label style="display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap;font-size:13px">
        <span>أو اختار ستايل من صورك ومفضلاتك <span class="text-mute" style="font-size:11px">(◈ صورك · ❤ مفضلاتك من المعرض)</span></span>
        <button type="button" class="btn ghost sm" onclick="addRefByLink(this)">🔗 أضف صورة بلينك</button>
    </label>

    <?php if ($pickerItems): ?>
        <div class="ref-strip" style="display:flex;gap:8px;overflow-x:auto;padding:6px 2px">
            <?php foreach ($pickerItems as $it): ?>
                <div class="ref-thumb" data-ref="<?= e($it['ref']) ?>" onclick="pickStyleRef(this)" title="<?= e($it['title']) ?>"
                     style="position:relative;flex:0 0 auto;width:74px;height:74px;border-radius:10px;overflow:hidden;cursor:pointer;border:3px solid transparent">
                    <img src="<?= e($it['src']) ?>" style="width:100%;height:100%;object-fit:cover" loading="lazy" alt=""
                         onerror="this.parentElement.style.opacity='.3'">
                    <span style="position:absolute;top:2px;right:4px;font-size:11px;text-shadow:0 1px 3px rgba(0,0,0,.6)"><?= $it['tag'] ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="ref-strip" style="display:flex;gap:8px;overflow-x:auto;padding:6px 2px"></div>
        <div class="field-help" style="font-size:12px">
            مفيش صور لسه — ارفع صور لبراندك من <a href="<?= url('brand-profile.php') ?>">صفحة الهوية</a>،
            أو حط ❤ على تصميمات في <a href="<?= url('studio.php') ?>">معرض الإلهام</a>، أو أضف صورة بلينك 👆
        </div>
    <?php endif; ?>
</div>

<script>
if (typeof window.pickStyleRef !== 'function') {
    window.pickStyleRef = function (el) {
        const was = el.classList.contains('sel');
        el.closest('.ref-strip').querySelectorAll('.ref-thumb').forEach(t => {
            t.classList.remove('sel');
            t.style.borderColor = 'transparent';
        });
        if (!was) {
            el.classList.add('sel');
            el.style.borderColor = 'var(--primary, #7c6df2)';
        }
    };
    window.selectedStyleRef = function (scope) {
        const root = scope || document;
        const el = root.querySelector('.ref-thumb.sel');
        if (!el) return '';
        return el.dataset.ref || '';
    };
    // إضافة صورة مرجعية بلينك — من غير رفع
    window.addRefByLink = function (btn) {
        const u = prompt('حط لينك الصورة (لازم يكون لينك صورة مباشر):');
        if (!u) return;
        const url = u.trim();
        if (!/^https?:\/\//i.test(url)) { alert('اللينك لازم يبدأ بـ http أو https'); return; }
        const strip = btn.closest('.field').querySelector('.ref-strip');
        const d = document.createElement('div');
        d.className = 'ref-thumb';
        d.dataset.ref = 'url:' + url;
        d.title = 'صورة بلينك';
        d.style.cssText = 'position:relative;flex:0 0 auto;width:74px;height:74px;border-radius:10px;overflow:hidden;cursor:pointer;border:3px solid transparent';
        d.innerHTML = '<img src="' + url.replace(/"/g, '&quot;') + '" style="width:100%;height:100%;object-fit:cover" onerror="this.parentElement.style.opacity=\'.3\';this.parentElement.title=\'اللينك مش شغال\'">'
                    + '<span style="position:absolute;top:2px;right:4px;font-size:11px;text-shadow:0 1px 3px rgba(0,0,0,.6)">🔗</span>';
        d.onclick = function () { window.pickStyleRef(d); };
        strip.prepend(d);
        window.pickStyleRef(d);
        const help = btn.closest('.field').querySelector('.field-help');
        if (help) help.style.display = 'none';
    };
}
</script>

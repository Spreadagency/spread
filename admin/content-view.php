<?php
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_admin();

$id = (int) ($_GET['id'] ?? 0);
$content = db_one(
    'SELECT c.*, u.name AS user_name, u.email AS user_email
     FROM contents c JOIN users u ON c.user_id = u.id
     WHERE c.id = ?',
    [$id]
);

if (!$content) {
    flash_set('danger', 'المحتوى غير موجود');
    redirect('admin/content-history.php');
}

$versions = get_content_versions($id);
// ⑦-ج نوع المحتوى: Post / Story = تصميم · Carousel = شرائح · Video = سكريبت + طلب تنفيذ
require_once __DIR__ . '/../includes/content-formats.php';
$fmt = content_format_key($content['format'] ?? 'post');
$bySlide = content_designs_by_slide($id);
$prog = content_design_progress($content, $bySlide);
$notes = db_all('SELECT n.*, u.name AS user_name FROM content_notes n LEFT JOIN users u ON n.user_id = u.id WHERE n.content_id = ? ORDER BY n.created_at DESC', [$id]);

$active = 'content';
$page_title = 'منشور #' . $id;
include __DIR__ . '/../templates/admin-header.php';
?>

<div class="app">
    <?php include __DIR__ . '/../templates/admin-sidebar.php'; ?>
    <main class="main">
        <div class="topbar">
            <button class="menu-toggle" onclick="toggleSidebar()">☰</button>
            <div style="flex:1"></div>
            <span class="badge-admin">ADMIN</span>
        </div>

        <?= render_flash() ?>

        <div class="page-head">
            <a href="<?= url('admin/content-history.php') ?>" class="text-mute" style="font-size:13px">← العودة</a>
            <h1>منشور #<?= $id ?></h1>
            <div class="sub" style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;margin-top:6px">
                <a href="<?= url('admin/user-view.php?id=' . $content['user_id']) ?>" style="text-decoration:none">
                    <b><?= e($content['user_name']) ?></b>
                </a>
                <span class="text-mute">·</span>
                <span class="chip chip-sky"><?= content_formats()[$fmt]['emoji'] ?> <?= e(content_formats()[$fmt]['en']) ?> · <?= e(content_format_label($fmt)) ?></span>
                <span class="chip chip-primary"><?= e(content_type_label($content['content_type'])) ?></span>
                <span class="chip chip-line"><?= e(platform_label($content['platform'])) ?></span>
                <span class="chip <?= e(status_chip($content['status'])) ?>"><?= e(status_label($content['status'])) ?></span>
                <span class="text-mute">· <?= e(fmt_date($content['created_at'], true)) ?></span>
            </div>
        </div>

        <div class="split split-flex" style="--c1:1.5fr;--c2:1fr;gap:20px;align-items:flex-start">

            <div>
                <div class="card">
                    <div class="card-head"><h3>النص النهائي</h3></div>
                    <div style="background:var(--surface-2);border-radius:14px;padding:18px;font-size:14px;line-height:1.9;white-space:pre-wrap;border:1px solid var(--line)"><?= e($content['generated_text']) ?></div>

                    <?php if (!empty($content['hashtags'])): ?>
                        <h3 style="font-size:13px;margin-top:14px;margin-bottom:6px">الهاشتاجات</h3>
                        <div class="hashtags"><?= e($content['hashtags']) ?></div>
                    <?php endif; ?>

                    <?php if (!empty($content['cta'])): ?>
                        <h3 style="font-size:13px;margin-top:12px;margin-bottom:6px">CTA</h3>
                        <div class="cta-box"><?= e($content['cta']) ?></div>
                    <?php endif; ?>
                </div>

                <!-- ⑦-ج نوع المحتوى -->
                <div class="card mt-20" id="format">
                    <div class="card-head"><h3>Content Type: <?= e(content_formats()[$fmt]['en']) ?></h3>
                        <?php if ($fmt !== 'video'): ?><span class="chip <?= $prog['complete'] ? 'chip-mint' : 'chip-amber' ?>"><?= e($prog['label']) ?></span><?php endif; ?></div>

                    <?php if ($fmt === 'carousel'): ?>
                        <p class="sub"><?= (int) $content['slides_count'] ?> Designs — تصميم لكل شريحة (<?= $prog['done'] ?> Generated)</p>
                        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px">
                            <?php foreach (content_slides($content) as $sl): $d = $bySlide[$sl['n']][0] ?? null; ?>
                                <a href="#slide-<?= $sl['n'] ?>" style="text-decoration:none;text-align:center;width:74px">
                                    <span style="display:block;aspect-ratio:1;border-radius:10px;overflow:hidden;background:var(--surface-2);border:1.5px <?= $d ? 'solid var(--mint,#10A8A0)' : 'dashed var(--line)' ?>">
                                        <?php if ($d): ?><img src="<?= url('storage/' . $d['image_path']) ?>" style="width:100%;height:100%;object-fit:cover" alt=""><?php endif; ?></span>
                                    <b style="font-size:12px">[<?= str_pad((string) $sl['n'], 2, '0', STR_PAD_LEFT) ?>]</b>
                                    <small style="display:block;font-size:10.5px;color:<?= $d ? '#0A7A5C' : 'var(--mute)' ?>"><?= $d ? '✓ Generated' : '○ Pending' ?></small>
                                </a>
                            <?php endforeach; ?>
                        </div>
                        <?php foreach (content_slides($content) as $sl): $ds = $bySlide[$sl['n']] ?? []; ?>
                            <div id="slide-<?= $sl['n'] ?>" style="display:flex;gap:12px;padding:12px;border-radius:12px;background:var(--surface-2);margin-bottom:8px;border:1px solid var(--line)">
                                <div style="width:110px;flex:none">
                                    <?php if ($ds): ?><img src="<?= url('storage/' . $ds[0]['image_path']) ?>" class="zoomable" style="width:100%;border-radius:10px;cursor:zoom-in" alt="">
                                    <?php else: ?><div style="aspect-ratio:1;border-radius:10px;border:1.5px dashed var(--line);display:grid;place-items:center;color:var(--mute)">—</div><?php endif; ?>
                                </div>
                                <div style="flex:1;min-width:0;font-size:12.5px;line-height:1.7">
                                    <b>Slide <?= $sl['n'] ?><?= $sl['title'] !== '' ? ' — ' . e($sl['title']) : '' ?></b>
                                    <?php if ($sl['text'] !== ''): ?><div><?= e($sl['text']) ?></div><?php endif; ?>
                                    <?php if ($sl['design'] !== ''): ?><div class="text-mute">🎨 <?= e($sl['design']) ?></div><?php endif; ?>
                                    <div style="margin-top:6px;display:flex;gap:6px;flex-wrap:wrap">
                                        <?php foreach ($ds as $k => $d): ?>
                                            <a class="btn ghost sm" href="<?= url('admin/prompt-view.php?type=design&id=' . (int) $d['id']) ?>">🔍 <?= $k === 0 ? 'Prompt + Reference Images' : 'نسخة ' . (count($ds) - $k) ?></a>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>

                    <?php elseif ($fmt === 'video'):
                        $vb = video_brief($content); $vs = video_status_meta($content['video_status'] ?? null); ?>
                        <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:10px">
                            <?php foreach (video_statuses() as $k => [$lbl]): $i = array_search($k, array_keys(video_statuses()), true) + 1; ?>
                                <span class="chip <?= $i < $vs['step'] ? 'chip-mint' : ($i === $vs['step'] ? 'chip-primary' : 'chip-line') ?>"><?= $i < $vs['step'] ? '✓' : $i ?> <?= e($lbl) ?></span>
                            <?php endforeach; ?>
                        </div>
                        <table class="table" style="font-size:12.5px"><tbody>
                            <tr><td class="text-mute">نوع الفيديو</td><td><b><?= e($vb['type']) ?></b></td><td class="text-mute">المدة</td><td><b><?= e($vb['duration']) ?></b></td></tr>
                            <tr><td class="text-mute">المنصة</td><td><b><?= e($vb['platform']) ?></b></td><td class="text-mute">المقاس</td><td><b><?= e($vb['ratio']) ?></b></td></tr>
                        </tbody></table>
                        <h3 style="font-size:13px;margin:12px 0 6px">Script</h3>
                        <div style="background:var(--surface-2);border-radius:12px;padding:14px;font-size:13px;line-height:1.9;white-space:pre-wrap;border:1px solid var(--line)"><?= e(video_script_text($vb) ?: '—') ?></div>
                        <?php if ($vb['notes'] !== ''): ?><h3 style="font-size:13px;margin:12px 0 6px">ملاحظات العميل</h3><div class="cta-box"><?= e($vb['notes']) ?></div><?php endif; ?>
                        <?php if (admin_can('view_content')): ?>
                        <form method="POST" action="<?= url('admin/video-requests.php') ?>" style="margin-top:14px;display:grid;gap:8px;background:var(--surface-2);border-radius:12px;padding:12px">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="update"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="back" value="content">
                            <b style="font-size:13px">🎬 حالة التنفيذ</b>
                            <select name="status" class="select">
                                <?php foreach (video_statuses() as $k => [$lbl]): ?><option value="<?= $k ?>" <?= $k === $vs['key'] ? 'selected' : '' ?>><?= e($lbl) ?></option><?php endforeach; ?>
                            </select>
                            <input name="delivery_url" class="input" dir="ltr" placeholder="رابط الفيديو النهائي (Drive / WeTransfer …)" value="<?= e($content['video_delivery_url'] ?? '') ?>">
                            <input name="note" class="input" maxlength="500" placeholder="ملاحظة تظهر للعميل" value="<?= e($content['video_admin_note'] ?? '') ?>">
                            <button class="btn sm">حفظ الحالة</button>
                        </form>
                        <?php endif; ?>

                    <?php else: $all = array_merge(...array_values($bySlide ?: [[]])); ?>
                        <p class="sub"><?= $fmt === 'story' ? 'ستوري — تصميم واحد رأسي 9:16' : 'منشور — تصميم واحد' ?><?= count($all) > 1 ? ' (' . count($all) . ' نسخ)' : '' ?></p>
                        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:10px">
                            <?php foreach ($all as $d): ?>
                                <a href="<?= url('admin/prompt-view.php?type=design&id=' . (int) $d['id']) ?>" style="display:block;border-radius:10px;overflow:hidden;border:<?= (int) $d['id'] === (int) ($content['selected_image_id'] ?? 0) ? '2px solid var(--primary)' : '1px solid var(--line)' ?>">
                                    <img src="<?= url('storage/' . $d['image_path']) ?>" style="width:100%;aspect-ratio:1;object-fit:cover" alt="" loading="lazy">
                                </a>
                            <?php endforeach; ?>
                            <?php if (!$all): ?><div class="text-mute" style="font-size:12.5px">لسه مفيش تصميم.</div><?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Final Prompt (admin only) -->
                <div class="card mt-20">
                    <div class="card-head"><h3>الـ Prompt المرسل (للإدارة فقط)</h3></div>
                    <pre style="background:var(--surface-2);border:1px solid var(--line);border-radius:12px;padding:14px;font-size:11.5px;line-height:1.7;color:var(--ink-2);white-space:pre-wrap;max-height:300px;overflow-y:auto;direction:ltr;text-align:start"><?= e($content['final_prompt'] ?? 'لم يحفظ') ?></pre>
                </div>

                <!-- Versions -->
                <?php if (count($versions) > 0): ?>
                    <div class="card mt-20">
                        <div class="card-head"><h3>الإصدارات السابقة <span class="count">• <?= count($versions) ?></span></h3></div>
                        <div style="display:grid;gap:10px">
                            <?php foreach ($versions as $v): ?>
                                <div style="background:var(--surface-2);border-radius:12px;padding:14px;border:1px solid var(--line)">
                                    <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px">
                                        <span class="chip chip-primary">#<?= $v['version_number'] ?></span>
                                        <span class="chip <?= $v['version_type'] === 'ai' ? 'chip-mint' : 'chip-amber' ?>"><?= $v['version_type'] === 'ai' ? '🤖' : '✎' ?></span>
                                        <span style="margin-inline-start:auto;font-size:11px;color:var(--mute)"><?= e(time_ago($v['created_at'])) ?></span>
                                    </div>
                                    <div style="font-size:12.5px;line-height:1.7;white-space:pre-wrap;color:var(--ink-2)"><?= e(str_limit($v['content_text'], 300)) ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Notes -->
            <div>
                <div class="card">
                    <div class="card-head"><h3>الملاحظات</h3></div>
                    <?php if (empty($notes)): ?>
                        <div class="text-mute" style="font-size:12.5px;text-align:center;padding:14px">لا توجد ملاحظات</div>
                    <?php else: ?>
                        <div style="display:grid;gap:10px">
                            <?php foreach ($notes as $n): ?>
                                <div style="background:var(--surface-2);border-radius:12px;padding:12px;border-inline-start:3px solid var(--primary)">
                                    <div style="font-size:13px;line-height:1.6"><?= nl2br(e($n['note'])) ?></div>
                                    <div style="font-size:10.5px;color:var(--mute);margin-top:6px">
                                        <?= e($n['user_name'] ?? 'الإدارة') ?> · <?= e(time_ago($n['created_at'])) ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="card compact mt-20" style="font-size:12.5px">
                    <div class="card-head"><h3 style="font-size:13px">تفاصيل التوليد</h3></div>
                    <div style="display:grid;gap:8px">
                        <div style="display:flex;justify-content:space-between"><span class="text-mute">طول النص:</span><b><?= e($content['length']) ?></b></div>
                        <div style="display:flex;justify-content:space-between"><span class="text-mute">النبرة:</span><b><?= e(tone_label($content['tone'] ?? '')) ?></b></div>
                        <div style="display:flex;justify-content:space-between"><span class="text-mute">الكريدت:</span><b><?= $content['credits_used'] ?> ◇</b></div>
                        <div style="display:flex;justify-content:space-between"><span class="text-mute">يستخدم اللوجو:</span><b><?= $content['use_logo'] ? 'نعم' : 'لا' ?></b></div>
                        <div style="display:flex;justify-content:space-between"><span class="text-mute">يستخدم صورة شخصية:</span><b><?= $content['use_personal_image'] ? 'نعم' : 'لا' ?></b></div>
                    </div>
                    <?php if (!empty($content['extra_notes'])): ?>
                        <div style="margin-top:12px;padding-top:12px;border-top:1px dashed var(--line)">
                            <div class="text-mute" style="font-size:11px;margin-bottom:4px">تعليمات إضافية:</div>
                            <div style="font-size:12px"><?= e($content['extra_notes']) ?></div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>
</div>

<?php include __DIR__ . '/../templates/admin-footer.php'; ?>

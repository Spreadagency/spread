<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_login();

$user = current_user();
$id = (int) ($_GET['id'] ?? 0);

$content = get_user_content($id, (int) $user['id']);
if (!$content) {
    flash_set('danger', 'المحتوى غير موجود');
    redirect('content-history.php');
}

$versions = get_content_versions($id);
$brand = user_brand();
$notes = db_all('SELECT * FROM content_notes WHERE content_id = ? ORDER BY created_at DESC', [$id]);
$designs = db_all('SELECT * FROM content_designs WHERE content_id = ? ORDER BY id DESC', [$id]);
require_once __DIR__ . '/../includes/credits.php';
require_once __DIR__ . '/../includes/social.php';

// اتصالات النشر بتاعة العميل (لو الميزة مفعّلة له)
$socialOn = feature_allows((int) $user['id']);
$myConnections = $socialOn ? user_connections((int) $user['id'], 'active') : [];
$designCost = cost_for('content_design_cost');
// ⑦-ج أشكال المحتوى
require_once __DIR__ . '/../includes/content-formats.php';
$__fmt = content_format_key($content['format'] ?? 'post');
$__prog = content_design_progress($content);

$active = 'history';
$page_title = 'عرض المنشور #' . $id;
include __DIR__ . '/../templates/header.php';
?>

<div class="app">
    <?php include __DIR__ . '/../templates/sidebar.php'; ?>
    <main class="main">
        <?php include __DIR__ . '/../templates/topbar.php'; ?>

        <div class="page-head with-actions">
            <div>
                <a href="<?= url('content-history.php') ?>" class="text-mute" style="font-size:13px">← العودة للسجل</a>
                <h1>منشور #<?= $id ?></h1>
                <div class="sub" style="display:flex;gap:6px;margin-top:6px;flex-wrap:wrap">
                    <span class="chip chip-primary"><?= e(content_type_label($content['content_type'])) ?></span>
                    <span class="chip chip-line"><?= e(platform_label($content['platform'])) ?></span>
                    <span class="chip <?= e(status_chip($content['status'])) ?>"><?= e(status_label($content['status'])) ?></span>
                    <span class="text-mute">· <?= e(fmt_date($content['created_at'], true)) ?></span>
                </div>
            </div>
            <div style="display:flex;gap:8px">
                <a href="<?= url('content-edit.php?id=' . $id) ?>" class="btn">✎ تعديل</a>
            </div>
        </div>

        <div class="split split-flex" style="--c1:1.5fr;--c2:1fr;gap:20px;align-items:flex-start">

            <!-- Main content -->
            <div>
                <div class="card">
                    <div class="card-head">
                        <h3>النص النهائي</h3>
                        <button class="btn ghost sm" onclick="copyToClipboard(document.getElementById('main-text').textContent, this)">📋 نسخ</button>
                    </div>
                    <div id="main-text" style="background:var(--surface-2);border-radius:14px;padding:18px;font-size:14px;line-height:1.9;white-space:pre-wrap;border:1px solid var(--line)"><?= e($content['generated_text']) ?></div>

                    <?php if (!empty($content['hashtags'])): ?>
                        <h3 style="font-size:14px;margin-top:18px;margin-bottom:8px">الهاشتاجات</h3>
                        <div class="hashtags" id="hashtags-text"><?= e($content['hashtags']) ?></div>
                    <?php endif; ?>

                    <?php if (!empty($content['cta'])): ?>
                        <h3 style="font-size:14px;margin-top:14px;margin-bottom:8px">Call-to-Action</h3>
                        <div class="cta-box"><?= e($content['cta']) ?></div>
                    <?php endif; ?>

                    <div class="action-row">
                        <button class="btn" onclick="copyAll()">📋 نسخ الكل</button>
                        <button class="btn ghost" onclick="regenerate()">⟲ إعادة توليد</button>
                        <a href="<?= url('content-edit.php?id=' . $id) ?>" class="btn ghost">✎ تعديل وحفظ</a>
                    </div>
                </div>

                <?php if ($__fmt === 'video'): ?>
                <!-- ⑦-ج الفيديو مابيتصممش — سكريبت ← اعتماد ← تنفيذ يدوي -->
                <div class="card mt-20" id="design">
                    <div class="card-head"><h3>🎬 الفيديو</h3><span class="chip"><?= e(video_status_meta($content['video_status'] ?? null)['label']) ?></span></div>
                    <p class="text-mute" style="font-size:13.5px">الـ AI جهّز السكريبت، والتنفيذ بيتم يدويًا بواسطة فريق Spread AI. راجع السكريبت واعتمده واطلب التنفيذ من هنا:</p>
                    <a class="btn" href="<?= url('content-history.php?open=' . $id) ?>">🎬 إنشاء الفيديو</a>
                </div>
                <?php else: ?>
                <!-- Designs (feature 21) -->
                <div class="card mt-20">
                    <div class="card-head">
                        <h3>🎨 التصميمات</h3>
                        <button class="btn sm" id="design-btn" onclick="generateDesign()">＋ توليد تصميم (<?= $designCost ?> كريدت)</button>
                    </div>
                    <!-- Phase 3: فكرة التصميم -->
                    <div class="field" style="margin-bottom:12px">
                        <label>💡 فكرة التصميم <span class="text-mute" style="font-size:12px"><?= !empty($content['design_direction']) ? '(اقتراح الـ AI — عدّلها أو اكتب فكرتك وبعدين ولّد)' : '(اختياري — سيبها فاضية والـ AI يقرر)' ?></span></label>
                        <textarea id="design-idea" class="textarea" rows="3" placeholder="مثال: خلفية بيج فاتحة، صورة ابتسامة قريبة، اسم العيادة أعلى اليمين"><?= e($content['design_direction'] ?? '') ?></textarea>
                    </div>
                    <!-- خيارات الهوية في التصميم -->
                    <div style="display:flex;gap:18px;flex-wrap:wrap;align-items:center;margin-bottom:12px;background:var(--surface-2);border-radius:12px;padding:10px 14px">
                        <?php if (!empty($brand['logo_path'])): ?>
                        <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:13px">
                            <input type="checkbox" id="design-use-logo" checked> إضافة لوجو البراند على التصميم
                        </label>
                        <?php endif; ?>
                        <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:13px">
                            <input type="checkbox" id="design-use-images"> إضافة صورة العميل / المنتج في التصميم
                        </label>
                        <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer">
                            📎 صورة ستايل مرجعية (الموديل هيلتزم بستايلها):
                            <input type="file" id="design-source-image" accept="image/jpeg,image/png,image/webp" style="font-size:12px;max-width:200px">
                        </label>
                    </div>
                    <?php include __DIR__ . '/../templates/style-ref-picker.php'; ?>
                    <?php if ($__fmt === 'carousel'): ?>
                    <div class="field" style="margin-bottom:12px">
                        <label>🎠 الشريحة <span class="text-mute" style="font-size:12px">(كاروسيل <?= (int) $content['slides_count'] ?> شرائح — <?= $__prog['done'] ?> متصممة · كل شريحة = تصميم)</span></label>
                        <select id="design-slide" class="select" style="max-width:320px">
                            <?php foreach (content_slides($content) as $sl): ?>
                                <option value="<?= $sl['n'] ?>" <?= $sl['n'] === ($__prog['missing'][0] ?? 1) ? 'selected' : '' ?>><?= in_array($sl['n'], $__prog['missing'], true) ? '○' : '✓' ?> شريحة <?= $sl['n'] ?><?= $sl['title'] !== '' ? ' — ' . e(mb_substr($sl['title'], 0, 40)) : '' ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="field-help">الشرائح بعد الأولى بتاخد الشريحة الأولى مرجع علشان يطلعوا بنفس الشكل والمقاس.</div>
                    </div>
                    <?php endif; ?>
                    <?php if ($__fmt === 'story'): ?><p class="text-mute" style="font-size:12.5px">📱 ستوري — التصميم بيتعمل رأسي 9:16 تلقائيًا.</p><?php endif; ?>
                    <?php $ratioScope = 'post'; $ratioCurrent = '1:1'; if ($__fmt !== 'story') include __DIR__ . '/../templates/ratio-picker.php'; ?>
                    <div id="designs-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:12px">
                        <?php if (empty($designs)): ?>
                            <div class="text-mute" id="no-designs" style="font-size:13px;grid-column:1/-1">مفيش تصميمات لسه — ولّد أول تصميم بالذكاء الاصطناعي مبني على نص المنشور وهوية البراند.</div>
                        <?php endif; ?>
                        <?php foreach ($designs as $d): ?>
                            <a href="<?= url('storage/' . $d['image_path']) ?>" data-lightbox style="display:block;border-radius:12px;overflow:hidden;border:1px solid var(--line)">
                                <img src="<?= url('storage/' . $d['image_path']) ?>" style="width:100%;aspect-ratio:1;object-fit:cover" loading="lazy" alt="design">
                                <?php if (!empty($d['slide_no'])): ?><small style="display:block;text-align:center;font-size:11.5px;padding:3px">شريحة <?= (int) $d['slide_no'] ?></small><?php endif; ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Scheduling (feature 23) -->
                <div class="card mt-20">
                    <div class="card-head"><h3>🗓️ الجدولة والنشر</h3></div>

                    <?php if (!empty($content['published_at'])): ?>
                        <div class="alert success">
                            ✓ تم النشر <?= e(fmt_date($content['published_at'], true)) ?>
                            <?php if (!empty($content['publish_post_id'])): ?> · <span dir="ltr" style="font-size:11px"><?= e($content['publish_post_id']) ?></span><?php endif; ?>
                        </div>
                    <?php elseif (($content['publish_status'] ?? '') === 'scheduled'): ?>
                        <div class="alert success">
                            📅 <b>مجدول على فيسبوك نفسه</b> — هينتشر <?= e(fmt_date($content['scheduled_at'], true)) ?>
                            <?php if (!empty($content['publish_post_id'])): ?>
                                · <a href="<?= e(fb_post_url(str_replace('facebook:', '', explode(' | ', $content['publish_post_id'])[0]))) ?>" target="_blank" rel="noopener">شوفه على فيسبوك ↗</a>
                            <?php endif; ?>
                            <div class="sub" style="font-size:12px;margin-top:4px">البوست موجود دلوقتي في «المنشورات المجدولة» على صفحتك — فيسبوك هو اللي هينشره في معاده.</div>
                        </div>
                    <?php elseif (($content['publish_status'] ?? '') === 'pending' && !empty($content['connection_id'])): ?>
                        <div class="alert info">
                            ⏰ <b>محجوز على المنصة</b> — هيتبعت لفيسبوك وينتشر <?= e(fmt_date($content['scheduled_at'], true)) ?>
                            <button class="btn ghost sm" style="margin-inline-start:10px" onclick="scheduleContent(true)">إلغاء</button>
                            <div class="sub" style="font-size:12px;margin-top:4px">لسه مظهرش على فيسبوك — تقدر تعدّل البوست لحد الموعد.</div>
                        </div>
                    <?php elseif (!empty($content['scheduled_at'])): ?>
                        <div class="alert info">
                            ⏰ مجدول للنشر: <b><?= e(fmt_date($content['scheduled_at'], true)) ?></b>
                            على <?= e(platform_label($content['publish_platform'] ?? 'facebook')) ?>
                            <button class="btn ghost sm" style="margin-inline-start:10px" onclick="scheduleContent(true)">إلغاء الجدولة</button>
                        </div>
                    <?php endif; ?>

                    <?php if ($myConnections && empty($content['published_at']) && ($content['publish_status'] ?? '') !== 'scheduled'): ?>
                        <?php
                            $schedVal = $content['scheduled_at']
                                ? date('Y-m-d\TH:i', strtotime($content['scheduled_at']))
                                : date('Y-m-d\T', strtotime('+1 day')) . str_pad((string) default_publish_hour(), 2, '0', STR_PAD_LEFT) . ':00';
                            $schedNice = date('d/m/Y — H:i', strtotime(str_replace('T', ' ', $schedVal)));
                        ?>
                        <div class="pub-options" style="display:grid;gap:12px;margin-bottom:14px">

                            <!-- 1) نشر فوري -->
                            <div class="pub-opt" style="border:1.5px solid var(--line);border-radius:14px;padding:14px">
                                <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
                                    <div>
                                        <b>🚀 نشر فوري الآن</b>
                                        <div class="sub" style="font-size:12px">بيتنشر على الصفحة في نفس اللحظة</div>
                                    </div>
                                    <button class="btn" id="pub-now-btn" onclick="publishDirect('now', this)">انشر دلوقتي</button>
                                </div>
                            </div>

                            <!-- الموعد المشترك -->
                            <div style="border:1.5px dashed var(--primary-soft);border-radius:14px;padding:14px;background:var(--surface-2)">
                                <div class="field" style="margin:0 0 10px">
                                    <label style="font-size:13px">🕘 ميعاد النشر المختار</label>
                                    <input type="datetime-local" id="pub-time" class="input" value="<?= e($schedVal) ?>"
                                           onchange="document.querySelectorAll('.pub-time-label').forEach(el => el.textContent = fmtPubTime(this.value))">
                                    <div class="field-help" style="font-size:11px">
                                        الافتراضي <?= default_publish_hour() ?>:00 بتوقيت مصر<?= !empty($content['plan_idea_id']) ? ' — التاريخ جاي من تقويم خطة المحتوى' : '' ?>
                                    </div>
                                </div>

                                <!-- 2) مجدول على فيسبوك -->
                                <div class="pub-opt" style="border:1.5px solid var(--line);border-radius:14px;padding:14px;background:var(--surface);margin-bottom:10px">
                                    <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
                                        <div style="flex:1;min-width:200px">
                                            <b>📅 مجدول على فيسبوك</b>
                                            <div class="sub" style="font-size:12px">
                                                يتبعت لفيسبوك <b>دلوقتي</b> وفيسبوك ينشره
                                                <span class="pub-time-label" style="color:var(--primary-ink);font-weight:700"><?= e($schedNice) ?></span>
                                            </div>
                                            <div class="sub" style="font-size:11px">✓ مش محتاج السيرفر يفضل شغال · هيظهر في «المنشورات المجدولة» على صفحتك</div>
                                        </div>
                                        <button class="btn" id="pub-sched-btn" onclick="publishDirect('schedule', this)" style="background:#1877f2;border-color:#1877f2">ابعته لفيسبوك</button>
                                    </div>
                                </div>

                                <!-- 3) مجدول على المنصة -->
                                <div class="pub-opt" style="border:1.5px solid var(--line);border-radius:14px;padding:14px;background:var(--surface)">
                                    <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
                                        <div style="flex:1;min-width:200px">
                                            <b>⏰ مجدول على المنصة</b>
                                            <div class="sub" style="font-size:12px">
                                                المنصة تمسكه وتبعته لفيسبوك <b>في الموعد نفسه</b>
                                                <span class="pub-time-label" style="color:var(--primary-ink);font-weight:700"><?= e($schedNice) ?></span>
                                            </div>
                                            <div class="sub" style="font-size:11px">✓ مش هيظهر على فيسبوك قبل الموعد · تقدر تعدّله لحد آخر لحظة</div>
                                        </div>
                                        <button class="btn ghost" id="pub-queue-btn" onclick="publishDirect('queue', this)">احجزه للموعد</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div id="pub-direct-status" class="field-help" style="display:none;margin-bottom:10px"></div>
                    <?php endif; ?>

                    <?php if (!empty($content['publish_error'])): ?>
                        <div class="alert danger" style="font-size:12px">⚠ آخر محاولة نشر فشلت: <?= e($content['publish_error']) ?></div>
                    <?php endif; ?>

                    <?php if (empty($content['published_at'])): ?>
                    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
                        <div class="field" style="margin:0;flex:1;min-width:180px">
                            <label>وقت النشر</label>
                            <input type="datetime-local" id="sched-time" class="input" value="<?= $content['scheduled_at'] ? date('Y-m-d\TH:i', strtotime($content['scheduled_at'])) : date('Y-m-d\T', strtotime('+1 day')) . str_pad((string) default_publish_hour(), 2, '0', STR_PAD_LEFT) . ':00' ?>">
                            <div class="field-help" style="font-size:11px">الافتراضي <?= default_publish_hour() ?>:00 مساءً بتوقيت مصر<?= !empty($content['plan_idea_id']) ? ' — التاريخ جاي من خطة المحتوى' : '' ?></div>
                        </div>
                        <?php if ($myConnections): ?>
                        <div class="field" style="margin:0;min-width:170px">
                            <label>انشر على</label>
                            <select id="sched-connection" class="input">
                                <?php foreach ($myConnections as $mc): ?>
                                    <option value="<?= $mc['id'] ?>" data-has-ig="<?= $mc['ig_user_id'] ? 1 : 0 ?>" <?= ((int) ($content['connection_id'] ?? 0)) === (int) $mc['id'] ? 'selected' : '' ?>>
                                        📘 <?= e($mc['page_name']) ?><?= $mc['ig_user_id'] ? ' (+IG)' : '' ?>
                                    </option>
                                <?php endforeach; ?>
                                <option value="0" <?= empty($content['connection_id']) && !empty($content['scheduled_at']) ? 'selected' : '' ?>>حساب المنصة العام</option>
                            </select>
                        </div>
                        <?php endif; ?>
                        <div class="field" style="margin:0">
                            <label>المنصة</label>
                            <select id="sched-platform" class="input">
                                <option value="facebook" <?= ($content['publish_platform'] ?? '') === 'facebook' ? 'selected' : '' ?>>فيسبوك</option>
                                <option value="instagram" <?= ($content['publish_platform'] ?? '') === 'instagram' ? 'selected' : '' ?>>إنستجرام (يتطلب تصميم)</option>
                                <option value="both" <?= ($content['publish_platform'] ?? '') === 'both' ? 'selected' : '' ?>>الاثنين</option>
                            </select>
                        </div>
                        <button class="btn" id="sched-btn" onclick="scheduleContent(false)">⏰ جدولة النشر</button>
                    </div>
                    <?php if ($socialOn && !$myConnections): ?>
                        <div class="field-help mt-10">💡 <a href="<?= url('social-accounts.php') ?>">اربط صفحتك بضغطة واحدة ←</a> والنشر هيبقى تلقائي على صفحتك انت.</div>
                    <?php elseif (!$socialOn): ?>
                        <div class="field-help mt-10">النشر التلقائي يتطلب تفعيل تكامل Meta من إدارة المنصة.</div>
                    <?php endif; ?>
                    <?php endif; ?>
                </div>

                <!-- Versions history -->
                <?php if (count($versions) > 0): ?>
                    <div class="card mt-20">
                        <div class="card-head">
                            <h3>الإصدارات السابقة <span class="count">• <?= count($versions) ?></span></h3>
                        </div>

                        <div style="display:grid;gap:10px">
                            <?php foreach ($versions as $v): ?>
                                <div style="background:var(--surface-2);border-radius:14px;padding:14px;border:1px solid var(--line)">
                                    <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px">
                                        <span class="chip chip-primary">#<?= $v['version_number'] ?></span>
                                        <span class="chip <?= $v['version_type'] === 'ai' ? 'chip-mint' : 'chip-amber' ?>">
                                            <?= $v['version_type'] === 'ai' ? '🤖 توليد' : '✎ تعديل' ?>
                                        </span>
                                        <span style="margin-inline-start:auto;font-size:11px;color:var(--mute)"><?= e(time_ago($v['created_at'])) ?></span>
                                    </div>
                                    <div style="font-size:12.5px;line-height:1.7;color:var(--ink-2);white-space:pre-wrap"><?= e(str_limit($v['content_text'], 220)) ?></div>
                                    <?php if (!empty($v['notes'])): ?>
                                        <div style="font-size:11px;color:var(--mute);margin-top:8px">📝 <?= e($v['notes']) ?></div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Side: Notes only (NOT prompt) -->
            <div>
                <div class="card">
                    <div class="card-head">
                        <h3>الملاحظات</h3>
                    </div>

                    <p class="text-mute mb-16" style="font-size:12px">
                        ضيف ملاحظاتك على المحتوى ده. مفيدة للأرشفة والمراجعة.
                    </p>

                    <form id="note-form">
                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="content_id" value="<?= $id ?>">
                        <textarea name="note" class="textarea auto" rows="2" required placeholder="مثال: راجع المقدمة، خليه أقصر، استبدل النبرة..."></textarea>
                        <button class="btn full sm mt-10">＋ إضافة ملاحظة</button>
                    </form>

                    <div style="margin-top:18px;display:grid;gap:10px" id="notes-list">
                        <?php foreach ($notes as $n): ?>
                            <div style="background:var(--surface-2);border-radius:12px;padding:12px;border-inline-start:3px solid var(--primary)">
                                <div style="font-size:13px;line-height:1.6;color:var(--ink)"><?= nl2br(e($n['note'])) ?></div>
                                <div style="font-size:10.5px;color:var(--mute);margin-top:6px"><?= e(time_ago($n['created_at'])) ?></div>
                            </div>
                        <?php endforeach; ?>
                        <?php if (empty($notes)): ?>
                            <div style="font-size:12px;color:var(--mute);text-align:center;padding:14px">لا توجد ملاحظات بعد</div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Settings used -->
                <div class="card compact mt-20" style="font-size:12.5px">
                    <div class="card-head"><h3 style="font-size:13px">الإعدادات المستخدمة</h3></div>
                    <div style="display:grid;gap:8px;color:var(--ink-2)">
                        <div style="display:flex;justify-content:space-between"><span class="text-mute">الكريدت:</span><b><?= $content['credits_used'] ?> ◇</b></div>
                        <div style="display:flex;justify-content:space-between"><span class="text-mute">طول النص:</span><b><?= e($content['length']) ?></b></div>
                        <div style="display:flex;justify-content:space-between"><span class="text-mute">نبرة:</span><b><?= e(tone_label($content['tone'] ?? '')) ?></b></div>
                        <div style="display:flex;justify-content:space-between"><span class="text-mute">يستخدم اللوجو:</span><b><?= $content['use_logo'] ? '✓ نعم' : '✗ لا' ?></b></div>
                    </div>

                    <?php if (!empty($content['extra_notes'])): ?>
                        <div style="margin-top:12px;padding-top:12px;border-top:1px dashed var(--line)">
                            <div class="text-mute" style="font-size:11px;margin-bottom:4px">تعليمات إضافية كانت معطاة:</div>
                            <div style="font-size:12px;color:var(--ink-2)"><?= e($content['extra_notes']) ?></div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>
</div>

<script>
function copyAll() {
    const t = [
        document.getElementById('main-text').textContent,
        '',
        document.getElementById('hashtags-text')?.textContent || '',
    ].join('\n');
    copyToClipboard(t.trim());
}

async function generateDesign() {
    const btn = document.getElementById('design-btn');
    btn.disabled = true;
    const orig = btn.textContent;
    btn.textContent = '⟳ جاري توليد التصميم...';

    let result;
    try {
        const fd = new FormData();
        fd.append('csrf', '<?= e(csrf_token()) ?>');
        fd.append('content_id', '<?= $id ?>');
        fd.append('custom_prompt', (document.getElementById('design-idea')?.value || '').trim());
        fd.append('include_logo', document.getElementById('design-use-logo')?.checked ? '1' : '0');
        fd.append('include_brand_images', document.getElementById('design-use-images')?.checked ? '1' : '0');
        fd.append('style_ref', (typeof selectedStyleRef === 'function') ? selectedStyleRef() : '');
        fd.append('ratio', (typeof selectedRatio === 'function') ? selectedRatio('post') : '1:1');
        const slideSel = document.getElementById('design-slide');
        if (slideSel) fd.append('slide_no', slideSel.value);
        const srcInput = document.getElementById('design-source-image');
        if (srcInput && srcInput.files && srcInput.files[0]) {
            fd.append('source_image', srcInput.files[0]);
        }
        if (typeof safeFormData === 'function') safeFormData(fd, ['custom_prompt']);
        const resp = await fetch('<?= url('ajax/generate-design.php') ?>', { method: 'POST', body: fd, credentials: 'same-origin' });
        result = await resp.json();
    } catch (e) {
        result = { ok: false, error: 'خطأ في الاتصال' };
    }

    btn.disabled = false;
    btn.textContent = orig;

    if (result.ok) {
        const no = document.getElementById('no-designs');
        if (no) no.remove();
        const a = document.createElement('a');
        a.href = result.image_url;
        a.target = '_blank';
        a.style.cssText = 'display:block;border-radius:12px;overflow:hidden;border:1px solid var(--line)';
        a.innerHTML = '<img src="' + result.image_url + '" style="width:100%;aspect-ratio:1;object-fit:cover" alt="design">';
        document.getElementById('designs-grid').prepend(a);
        showToast('تم توليد التصميم ✓', 'success');
    } else {
        showToast(result.error || 'فشل توليد التصميم', 'danger');
    }
}

async function scheduleContent(cancel) {
    const payload = {
        csrf: '<?= e(csrf_token()) ?>',
        content_id: <?= $id ?>
    };
    if (cancel) {
        payload.cancel = 1;
    } else {
        payload.scheduled_at = document.getElementById('sched-time').value;
        payload.publish_platform = document.getElementById('sched-platform').value;
        const connSel = document.getElementById('sched-connection');
        if (connSel) payload.connection_id = connSel.value;
        if (!payload.scheduled_at) { showToast('اختر وقت النشر', 'danger'); return; }
    }
    const result = await ajaxPost('<?= url('ajax/schedule-content.php') ?>', payload);
    if (result.ok) {
        showToast(result.msg || 'تم ✓', 'success');
        setTimeout(() => location.reload(), 800);
    } else {
        showToast(result.error || 'فشل', 'danger');
    }
}

async function regenerate() {
    if (!confirm('إعادة التوليد هتكلفك كريدت إضافية. متأكد؟')) return;
    const result = await ajaxPost('<?= url('ajax/regenerate-content.php') ?>', {
        csrf: '<?= e(csrf_token()) ?>',
        content_id: <?= $id ?>
    });
    if (result.ok) {
        showToast('تم إعادة التوليد ✓', 'success');
        setTimeout(() => location.reload(), 800);
    } else {
        showToast(result.error || 'فشل', 'danger');
    }
}

document.getElementById('note-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const fd = new FormData(e.target);
    const data = {};
    fd.forEach((v, k) => data[k] = v);

    const result = await ajaxPost('<?= url('ajax/add-note.php') ?>', data);
    if (result.ok) {
        showToast('تمت الإضافة ✓', 'success');
        e.target.reset();
        setTimeout(() => location.reload(), 500);
    } else {
        showToast(result.error || 'فشل', 'danger');
    }
});
</script>

<script>
function fmtPubTime(v) {
    if (!v) return '';
    const d = new Date(v.replace('T', ' ').replace(/-/g, '/'));
    if (isNaN(d)) return v;
    const p = n => String(n).padStart(2, '0');
    return p(d.getDate()) + '/' + p(d.getMonth() + 1) + '/' + d.getFullYear() + ' — ' + p(d.getHours()) + ':' + p(d.getMinutes());
}

async function publishDirect(mode, btn) {
    const st = document.getElementById('pub-direct-status');
    const connSel = document.getElementById('sched-connection');
    const connId = connSel ? connSel.value : '0';
    if (!connId || connId === '0') { showToast('اختر صفحة من «انشر على» الأول', 'danger'); return; }

    const timeEl = document.getElementById('pub-time');
    if ((mode === 'schedule' || mode === 'queue') && (!timeEl || !timeEl.value)) {
        showToast('حدد ميعاد النشر الأول', 'danger'); return;
    }
    if (mode === 'now' && !confirm('هينتشر على فيسبوك دلوقتي فورًا. متأكد؟')) return;

    const original = btn.textContent;
    btn.disabled = true;
    btn.textContent = mode === 'now' ? '⏳ بينشر...' : (mode === 'schedule' ? '⏳ بيتبعت لفيسبوك...' : '⏳ بيتحجز...');
    st.style.display = '';
    st.textContent = mode === 'queue' ? 'جاري الحجز...' : 'جاري الاتصال بفيسبوك...';

    try {
        const fd = new FormData();
        fd.append('csrf', CSRF);
        fd.append('content_id', <?= (int) $content['id'] ?>);
        fd.append('connection_id', connId);
        fd.append('mode', mode);
        fd.append('platform', document.getElementById('sched-platform').value);
        if (timeEl && timeEl.value) fd.append('scheduled_at', timeEl.value);

        const r = await fetch('<?= url('ajax/publish-direct.php') ?>', { method: 'POST', body: fd, credentials: 'same-origin' });
        const d = await r.json();
        if (d.ok) {
            st.innerHTML = '✓ ' + d.msg + (d.post_url ? ' — <a href="' + d.post_url + '" target="_blank">شوف البوست ↗</a>' : '');
            showToast(d.queued ? 'اتحجز للموعد ✓' : (d.scheduled ? 'اتجدول على فيسبوك ✓' : 'اتنشر ✓'), 'success');
            setTimeout(() => location.reload(), 2200);
        } else {
            st.textContent = '⚠️ ' + (d.error || 'فشل النشر');
            showToast(d.error || 'فشل النشر', 'danger');
        }
    } catch (e) {
        st.textContent = '⚠️ خطأ في الاتصال';
    }
    btn.disabled = false;
    btn.textContent = original;
}
</script>
<?php include __DIR__ . '/../templates/footer.php'; ?>

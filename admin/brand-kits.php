<?php
/**
 * الأدمن — هويات العملاء (Brand Kit)
 *   القائمة: الاكتمال · القطاع · آخر تحديث
 *   عميل: الأقسام · الخدمات والمواضيع · الشخصيات · النسخ · تعديل يدوي (changed_by=admin)
 *         «جرّب على عميل»: بوست تجريبي بالهوية + البرومبت كامل — من غير حفظ ومن غير ما يتخصم من العميل
 *   مراجعة الجودة: أكتر حقول اتعدلت (brand_feedback) مقسّمة حسب القطاع
 *   برومبتات الـ Agents: في «مكتبة البرومبتات» (أنواع agent_*) — بنسخ وتفعيل
 */
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/prompt-builder.php';
require_once __DIR__ . '/../includes/brand-kit.php';

require_admin();
require_admin_can('view_users');
bk_ensure_schema();
$canEdit = admin_can('ai_settings');
$tab = in_array($_GET['tab'] ?? '', ['list', 'quality', 'agents'], true) ? $_GET['tab'] : 'list';
$uid = (int) ($_GET['user'] ?? 0);
$test = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    decode_b64_fields();
    $uid = (int) ($_POST['user'] ?? 0);
    if (!$uid || !db_one('SELECT id FROM users WHERE id = ?', [$uid])) { flash_set('danger', 'العميل مش موجود'); redirect('admin/brand-kits.php'); }

    if (!empty($_POST['save_section']) && $canEdit) {
        $sec = (string) $_POST['save_section'];
        $val = json_decode((string) ($_POST['value'] ?? ''), true);
        if ($val === null && trim((string) ($_POST['value'] ?? '')) !== 'null') {
            flash_set('danger', 'الـ JSON مش صحيح');
        } else {
            $r = bk_save_section($uid, $sec, $val, 'admin', 'تعديل من فريق Spread: ' . bk_section_label($sec));
            admin_log('brand_kit_edit', 'brand_kits', $uid, $sec);
            flash_set($r['ok'] ? 'success' : 'danger', $r['ok'] ? 'اتحفظ ✓ (نسخة جديدة باسم فريق Spread)' : $r['error']);
        }
        redirect('admin/brand-kits.php?user=' . $uid);
    }
    if (!empty($_POST['sector']) && $canEdit) {
        db_run('UPDATE brand_kits SET sector_template_id = ? WHERE user_id = ?', [(int) $_POST['sector'] > 0 ? (int) $_POST['sector'] : null, $uid]);
        admin_log('brand_kit_sector', 'brand_kits', $uid, (string) $_POST['sector']);
        flash_set('success', 'اتغيّر القطاع');
        redirect('admin/brand-kits.php?user=' . $uid);
    }
    if (!empty($_POST['restore']) && $canEdit) {
        $kit = bk_get($uid);
        $v = db_one('SELECT data FROM brand_kit_versions WHERE brand_kit_id = ? AND version = ?', [$kit['id'], (int) $_POST['restore']]);
        if ($v) {
            bk_write($kit['id'], $uid, bk_arr($v['data']), 'admin', 'رجوع لنسخة ' . (int) $_POST['restore'] . ' (فريق Spread)');
            admin_log('brand_kit_restore', 'brand_kits', $uid, (string) $_POST['restore']);
            flash_set('success', 'رجعنا للنسخة ' . (int) $_POST['restore']);
        }
        redirect('admin/brand-kits.php?user=' . $uid);
    }
    // «جرّب على عميل»: بوست تجريبي — مابيتحفظش ومابيتخصمش من العميل
    if (!empty($_POST['test'])) {
        require_once __DIR__ . '/../includes/campaign-flow.php';
        BrandContext::flush($uid);
        $brand = db_one('SELECT * FROM brand_profiles WHERE user_id = ? ORDER BY id LIMIT 1', [$uid]) ?: ['user_id' => $uid];
        $brand['user_id'] = $uid;
        $o = array_filter(['topic' => mb_substr(trim((string) ($_POST['topic'] ?? '')), 0, 255), 'request' => mb_substr(trim((string) ($_POST['request'] ?? '')), 0, 500)]);
        $brief = BrandContext::brief($uid, $o);
        $prompt = build_final_prompt($brand, ['content_type' => 'introductory', 'platform' => 'both', 'length' => $brief['caption_length'] ?: 'medium',
            'tone' => $brand['tone'] ?? 'simple', 'dialect' => 'egyptian', 'bk_brief' => $brief], (string) ($o['request'] ?? ''));
        $ai = campaign_ai($prompt, 1400, 'content', ['user_id' => null, 'reference_type' => 'admin_test', 'temperature' => 0.8]);
        $parsed = $ai['ok'] ? parse_ai_response(BrandContext::stripWhy((string) $ai['response'])) : ['content' => '', 'hashtags' => '', 'cta' => ''];
        $test = ['brief' => $brief, 'prompt' => $prompt, 'ok' => $ai['ok'], 'error' => $ai['error'] ?? '',
                 'content' => BrandContext::enforce((string) $parsed['content'], $uid), 'hashtags' => $parsed['hashtags'], 'cta' => $parsed['cta'],
                 'why' => $ai['ok'] ? BrandContext::parseWhy((string) $ai['response']) : []];
        admin_log('brand_kit_test', 'brand_kits', $uid, $ai['ok'] ? 'ok' : 'fail');
    }
}

$active = 'brand-kits';
$page_title = 'هويات العملاء';
include __DIR__ . '/../templates/admin-header.php';
?>
<div class="app">
    <?php include __DIR__ . '/../templates/admin-sidebar.php'; ?>
    <main class="main">
        <?php if (is_file(__DIR__ . '/../templates/admin-topbar.php')) include __DIR__ . '/../templates/admin-topbar.php'; ?>
        <div class="page-head"><h1>هويات العملاء (Brand Kit) 🧬</h1><div class="sub">الهوية المنظّمة اللي بتأثر على كل بوست — عرض وتعديل وتجربة ومراجعة جودة.</div></div>
        <?= render_flash() ?>
        <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:14px">
            <a class="btn sm <?= $tab === 'list' && !$uid ? '' : 'ghost' ?>" href="?">العملاء</a>
            <a class="btn sm <?= $tab === 'quality' ? '' : 'ghost' ?>" href="?tab=quality">مراجعة الجودة</a>
            <a class="btn sm <?= $tab === 'agents' ? '' : 'ghost' ?>" href="?tab=agents">برومبتات الـ Agents</a>
            <a class="btn sm ghost" href="<?= url('admin/brand-learning.php') ?>">مكتبة التعلّم</a>
            <a class="btn sm ghost" href="<?= url('admin/sector-templates.php') ?>">قوالب القطاعات</a>
            <a class="btn sm ghost" href="<?= url('admin/style-presets.php') ?>">الـ Presets</a>
        </div>

<?php if ($uid):
    $u = db_one('SELECT id, name, email FROM users WHERE id = ?', [$uid]);
    if (!$u) { echo '<div class="alert danger">العميل مش موجود</div>'; } else {
    BrandContext::flush($uid);
    $kit = bk_get($uid);
    $comp = bk_completeness($uid, $kit);
    $services = bk_services($uid);
    $topics = bk_topics($uid, null, false);
    $chars = bk_characters($uid);
    $versions = db_all('SELECT version, changed_by, note, created_at FROM brand_kit_versions WHERE brand_kit_id = ? ORDER BY version DESC LIMIT 30', [$kit['id']]);
    $sectors = bk_sectors(false);
    $editable = ['business', 'audience', 'personality', 'voice', 'pillars', 'content', 'colors', 'typography', 'design_style', 'visuals', 'logo', 'offers', 'forbidden_topics'];
?>
        <div class="card" style="margin-bottom:16px">
            <div class="card-head"><h3><?= e($u['name']) ?> <small class="text-mute" style="font-weight:400"><?= e($u['email']) ?></small></h3>
                <div style="display:flex;gap:6px;align-items:center"><span class="chip chip-mint">اكتمال <?= $comp['pct'] ?>%</span><span class="chip">نسخة <?= $kit['version'] ?></span>
                    <a class="btn ghost sm" href="<?= url('admin/user-view.php?id=' . $uid) ?>">ملف العميل</a></div></div>
            <?php if ($comp['missing']): ?><p class="text-mute" style="margin:0 0 8px;font-size:13px">ناقص: <?= e(implode('، ', array_column($comp['missing'], 'label'))) ?></p><?php endif; ?>
            <form method="POST" style="display:flex;gap:8px;align-items:center"><?= csrf_field() ?><input type="hidden" name="user" value="<?= $uid ?>">
                <label class="text-mute" style="font-size:13px">القطاع</label>
                <select name="sector" class="select" style="max-width:240px" <?= $canEdit ? '' : 'disabled' ?>><option value="-1">— من غير قالب —</option><?php foreach ($sectors as $s): ?><option value="<?= (int) $s['id'] ?>" <?= (int) $kit['sector_template_id'] === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?></select>
                <?php if ($canEdit): ?><button class="btn ghost sm">حفظ</button><?php endif; ?>
            </form>
        </div>

        <div style="display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:16px;align-items:start" id="bk-grid">
            <div>
                <div class="card" style="margin-bottom:16px">
                    <div class="card-head"><h3>🧪 جرّب على العميل ده</h3></div>
                    <p class="text-mute" style="font-size:12.5px;margin:0 0 8px">بيولّد بوست تجريبي بالهوية الحالية + برومبتات الـ Agents المفعّلة — مابيتحفظش ومابيتخصمش من العميل.</p>
                    <form method="POST" data-safe-post style="display:grid;gap:8px"><?= csrf_field() ?><input type="hidden" name="user" value="<?= $uid ?>">
                        <input name="topic" class="input" placeholder="موضوع (اختياري — وإلا بيختار من الهوية)">
                        <input name="request" class="input" placeholder="طلب العميل (اختياري)">
                        <button class="btn sm" name="test" value="1">ولّد بوست تجريبي</button>
                    </form>
                    <?php if ($test): ?>
                        <div style="margin-top:12px">
                            <div class="text-mute" style="font-size:12.5px">البريف: <?= e(implode(' · ', array_filter([$test['brief']['service'], $test['brief']['topic'], $test['brief']['pillar'], $test['brief']['method'], bk_format_labels()[$test['brief']['format']] ?? '']))) ?></div>
                            <?php if ($test['ok']): ?>
                                <div style="white-space:pre-wrap;background:var(--surface-2,#F7FAFD);border-radius:12px;padding:12px;margin-top:8px;font-size:13.5px;line-height:1.8"><?= e($test['content']) ?>

<?= e((string) $test['cta']) ?>

<?= e((string) $test['hashtags']) ?></div>
                                <?php if ($test['why']): ?><ul style="font-size:12.5px;margin:8px 0"><?php foreach ($test['why'] as $w): ?><li><?= e($w) ?></li><?php endforeach; ?></ul><?php endif; ?>
                            <?php else: ?><div class="alert danger" style="margin-top:8px"><?= e($test['error'] ?: 'فشل التوليد') ?></div><?php endif; ?>
                            <details style="margin-top:8px"><summary class="text-mute" style="cursor:pointer;font-size:12.5px">البرومبت كامل</summary><pre style="white-space:pre-wrap;font-size:11.5px;max-height:420px;overflow:auto;background:#0B1526;color:#E3EAF3;padding:12px;border-radius:10px"><?= e($test['prompt']) ?></pre></details>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="card" style="margin-bottom:16px">
                    <div class="card-head"><h3>الخدمات والمواضيع</h3></div>
                    <?php foreach ($services as $s): ?>
                        <div style="padding:8px 0;border-bottom:1px solid var(--line)"><b><?= e($s['name']) ?></b> <span class="chip"><?= e($s['priority']) ?></span><?= $s['is_focus'] ? ' <span class="chip chip-amber">خدمة الشهر</span>' : '' ?>
                            <div class="text-mute" style="font-size:12.5px"><?= e(implode('، ', array_map(fn($t) => $t['title'] . ($t['times_used'] ? ' ×' . $t['times_used'] : ''), array_filter($topics, fn($t) => (int) $t['service_id'] === (int) $s['id'])))) ?: 'مفيش مواضيع' ?></div></div>
                    <?php endforeach; ?>
                    <?php $gen = array_filter($topics, fn($t) => $t['service_id'] === null); if ($gen): ?><div class="text-mute" style="font-size:12.5px;margin-top:8px">عامة: <?= e(implode('، ', array_column($gen, 'title'))) ?></div><?php endif; ?>
                    <?php if (!$services && !$topics): ?><p class="text-mute">مفيش خدمات لسه</p><?php endif; ?>
                </div>

                <div class="card" style="margin-bottom:16px">
                    <div class="card-head"><h3>الشخصيات</h3></div>
                    <?php foreach ($chars as $c): ?>
                        <div style="display:flex;gap:10px;align-items:center;padding:6px 0">
                            <?php foreach (array_slice($c['images'], 0, 3) as $im): ?><img src="<?= e(upload_url($im['image_path'])) ?>" alt="" style="width:40px;height:40px;border-radius:50%;object-fit:cover"><?php endforeach; ?>
                            <div><b><?= e($c['name']) ?></b> <small class="text-mute"><?= e($c['type']) ?> · <?= (int) $c['share_percent'] ?>%</small>
                                <?= $c['type'] === 'real_person' ? ((int) $c['consent_confirmed'] ? '<span class="chip chip-mint">موافقة ✓ ' . e((string) $c['consent_at']) . '</span>' : '<span class="chip chip-rose">من غير موافقة</span>') : '' ?></div>
                        </div>
                    <?php endforeach; ?>
                    <?php if (!$chars): ?><p class="text-mute">مفيش شخصيات</p><?php endif; ?>
                </div>

                <div class="card">
                    <div class="card-head"><h3>النسخ</h3></div>
                    <div class="table-wrap"><table class="table"><tbody>
                    <?php foreach ($versions as $v): ?>
                        <tr><td><b><?= (int) $v['version'] ?></b></td><td><span class="chip"><?= e(['user' => 'العميل', 'ai' => 'AI', 'admin' => 'الأدمن'][$v['changed_by']]) ?></span></td><td style="font-size:12.5px"><?= e((string) $v['note']) ?></td><td class="text-mute" style="font-size:12px"><?= e($v['created_at']) ?></td>
                            <td><?php if ($canEdit && (int) $v['version'] !== $kit['version']): ?><form method="POST" onsubmit="return confirm('ترجع للنسخة دي؟')"><?= csrf_field() ?><input type="hidden" name="user" value="<?= $uid ?>"><button class="btn ghost sm" name="restore" value="<?= (int) $v['version'] ?>">ارجع</button></form><?php endif; ?></td></tr>
                    <?php endforeach; ?>
                    </tbody></table></div>
                </div>
            </div>

            <div class="card">
                <div class="card-head"><h3>أقسام الهوية<?= $canEdit ? ' — تعديل يدوي' : '' ?></h3></div>
                <?php foreach ($editable as $sec): ?>
                    <details style="border-bottom:1px solid var(--line);padding:8px 0">
                        <summary style="cursor:pointer;font-weight:600"><?= e(bk_section_label($sec)) ?><?= isset($kit['data']['confidence'][$sec]) ? ' <span class="chip chip-amber">مش متأكد</span>' : '' ?></summary>
                        <form method="POST" data-safe-post style="margin-top:8px"><?= csrf_field() ?><input type="hidden" name="user" value="<?= $uid ?>">
                            <textarea name="value" class="textarea" rows="7" dir="ltr" style="font-family:monospace;font-size:12px" <?= $canEdit ? '' : 'readonly' ?>><?= e(json_encode($kit['data'][$sec], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) ?></textarea>
                            <?php if ($canEdit): ?><button class="btn ghost sm mt-10" name="save_section" value="<?= e($sec) ?>">حفظ القسم</button><?php endif; ?>
                        </form>
                    </details>
                <?php endforeach; ?>
            </div>
        </div>
<?php } elseif ($tab === 'quality'):
    $rows = db_all("SELECT COALESCE(s.name, 'من غير قطاع') sector, f.action, COALESCE(f.field, '-') field, COUNT(*) n, COUNT(DISTINCT f.user_id) users
                    FROM brand_feedback f LEFT JOIN brand_kits k ON k.user_id = f.user_id LEFT JOIN sector_templates s ON s.id = k.sector_template_id
                    WHERE f.created_at >= NOW() - INTERVAL 90 DAY GROUP BY sector, f.action, field ORDER BY n DESC LIMIT 100");
    $likes = db_one('SELECT SUM(feedback = 1) l, SUM(feedback = -1) d FROM contents WHERE feedback IS NOT NULL AND created_at >= NOW() - INTERVAL 90 DAY') ?: [];
    $sugs = db_one('SELECT SUM(status = "pending") p, SUM(status = "accepted") a, SUM(status = "rejected") r FROM brand_suggestions') ?: [];
    $actL = ['edit' => 'تعديل', 'regenerate' => 'نسخة تانية', 'chip' => 'تغيير chip', 'like' => '👍', 'dislike' => '👎'];
?>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;margin-bottom:16px">
            <div class="card compact"><div class="text-mute" style="font-size:12px">👍 / 👎 (90 يوم)</div><b style="font-size:22px"><?= (int) ($likes['l'] ?? 0) ?> / <?= (int) ($likes['d'] ?? 0) ?></b></div>
            <div class="card compact"><div class="text-mute" style="font-size:12px">اقتراحات التعلّم (مستنية/مقبولة/مرفوضة)</div><b style="font-size:22px"><?= (int) ($sugs['p'] ?? 0) ?> / <?= (int) ($sugs['a'] ?? 0) ?> / <?= (int) ($sugs['r'] ?? 0) ?></b></div>
        </div>
        <div class="card"><div class="card-head"><h3>أكتر الحقول اللي العملاء بيعدّلوها — حسب القطاع (90 يوم)</h3></div>
            <div class="table-wrap"><table class="table"><thead><tr><th>القطاع</th><th>النوع</th><th>الحقل</th><th>المرات</th><th>عملاء</th></tr></thead><tbody>
            <?php foreach ($rows as $r): ?><tr><td><?= e($r['sector']) ?></td><td><?= e($actL[$r['action']] ?? $r['action']) ?></td><td><?= e($r['field']) ?></td><td><b><?= (int) $r['n'] ?></b></td><td><?= (int) $r['users'] ?></td></tr><?php endforeach; ?>
            <?php if (!$rows): ?><tr><td colspan="5" class="text-mute">لسه مفيش تعديلات مسجّلة</td></tr><?php endif; ?>
            </tbody></table></div>
            <p class="text-mute" style="font-size:12.5px">نصيحة: لو حقل معين بيتعدّل كتير في قطاع، عدّل قالب القطاع أو برومبت الـ Agent بتاعه.</p>
        </div>
<?php elseif ($tab === 'agents'): ?>
        <div class="card"><div class="card-head"><h3>برومبتات الـ Agents</h3></div>
            <p class="text-mute" style="font-size:13px">كل Agent بياخد الجزء اللي يخصه من الهوية (عن طريق BrandContext) — وفوقه التعليمات دي. التعديل بنسخ وتقدر تفعّل أي نسخة، وتجرّبها من «جرّب على عميل».</p>
            <div class="table-wrap"><table class="table"><thead><tr><th>Agent</th><th>بياخد من الهوية</th><th>النسخة المفعّلة</th><th></th></tr></thead><tbody>
            <?php $takes = ['brand' => 'كل الهوية + القطاع (مسودة الـ Onboarding)', 'ideas' => 'الخدمات والمواضيع وتركيز الشهر والمواسم والاتجاهات والشخصيات والأشكال', 'copy' => 'النشاط والجمهور وطريقة الكلام كاملة والخدمة والشخصية والـ Hooks والطول والهدف', 'design' => 'الألوان والخطوط والاستايل واللوجو وطبيعة الصور والشخصية وصورها', 'video' => 'طبيعة الصور والألوان والشخصية وطريقة الكلام ومدة الريل', 'schedule' => 'الأشكال في الأسبوع والاتجاهات وتركيز الشهر والمواسم ونسبة الشخصيات'];
            foreach (BK_AGENTS as $a): $pt = 'agent_' . $a; $cur = db_one('SELECT version, LEFT(prompt_text, 120) t FROM prompts WHERE prompt_type = ? AND is_active = 1 ORDER BY version DESC LIMIT 1', [$pt]); ?>
                <tr><td><b><?= e(prompt_types()[$pt][0] ?? $a) ?></b></td><td style="font-size:12.5px"><?= e($takes[$a]) ?></td>
                    <td style="font-size:12.5px"><?= $cur ? 'v' . (int) $cur['version'] . ' — ' . e($cur['t']) . '…' : '<span class="text-mute">مفيش — بيشتغل بالسياق بس</span>' ?></td>
                    <td><a class="btn ghost sm" href="<?= url('admin/prompts.php?type=' . $pt) ?>">تعديل</a></td></tr>
            <?php endforeach; ?>
            </tbody></table></div>
        </div>
<?php else:
    $q = trim((string) ($_GET['q'] ?? ''));
    $p = [];
    $w = '';
    if ($q !== '') { $w = 'WHERE u.name LIKE ? OR u.email LIKE ?'; $p = ["%$q%", "%$q%"]; }
    $rows = db_all("SELECT u.id, u.name, u.email, k.completeness, k.version, k.updated_at, s.name sector,
                           (SELECT COUNT(*) FROM brand_services x WHERE x.user_id = u.id) services,
                           (SELECT COUNT(*) FROM brand_suggestions g WHERE g.user_id = u.id AND g.status = 'pending') sugs
                    FROM brand_kits k JOIN users u ON u.id = k.user_id LEFT JOIN sector_templates s ON s.id = k.sector_template_id
                    $w ORDER BY k.updated_at DESC LIMIT 200", $p);
?>
        <div class="card"><div class="card-head"><h3>العملاء اللي عندهم Brand Kit (<?= count($rows) ?>)</h3>
            <form method="GET"><input name="q" class="input" value="<?= e($q) ?>" placeholder="بحث بالاسم أو الإيميل"></form></div>
            <div class="table-wrap"><table class="table"><thead><tr><th>العميل</th><th>القطاع</th><th>الاكتمال</th><th>خدمات</th><th>اقتراحات</th><th>آخر تحديث</th><th></th></tr></thead><tbody>
            <?php foreach ($rows as $r): ?>
                <tr><td><b><?= e($r['name']) ?></b><div class="text-mute" style="font-size:12px"><?= e($r['email']) ?></div></td><td><?= e((string) ($r['sector'] ?? '—')) ?></td>
                    <td><div style="width:90px;height:8px;border-radius:9px;background:#EEF3F9;overflow:hidden;display:inline-block;vertical-align:middle"><i style="display:block;height:100%;width:<?= (int) $r['completeness'] ?>%;background:#13A89E"></i></div> <?= (int) $r['completeness'] ?>%</td>
                    <td><?= (int) $r['services'] ?></td><td><?= (int) $r['sugs'] ?></td><td class="text-mute" style="font-size:12px"><?= e((string) $r['updated_at']) ?></td>
                    <td><a class="btn ghost sm" href="?user=<?= (int) $r['id'] ?>">عرض</a></td></tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?><tr><td colspan="7" class="text-mute">لسه مفيش</td></tr><?php endif; ?>
            </tbody></table></div>
        </div>
<?php endif; ?>
    </main>
</div>
<style>@media (max-width:1100px){#bk-grid{grid-template-columns:1fr !important}}</style>
<?php include __DIR__ . '/../templates/admin-footer.php'; ?>

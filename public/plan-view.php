<?php
/**
 * Spread AI v2 — عرض الخطة: الأفكار + الإنتاج + التقويم (Phase 3)
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/credits.php';
require_once __DIR__ . '/../includes/plan-functions.php';

require_login();

$user = current_user();
$planId = (int) ($_GET['id'] ?? 0);

$plan = db_one('SELECT * FROM content_plans WHERE id = ? AND user_id = ?', [$planId, $user['id']]);
if (!$plan) {
    flash_set('danger', 'الخطة غير موجودة');
    redirect('content-plan.php');
}

$ideas = db_all(
    'SELECT pi.*, c.publish_status AS c_pub
     FROM plan_ideas pi
     LEFT JOIN contents c ON c.id = pi.content_id
     WHERE pi.plan_id = ? ORDER BY pi.sort_order, pi.id',
    [$planId]
);
// شارة حالة النشر التلقائي للتقويم
function pub_badge(?string $st): string
{
    return match ($st) {
        'pending' => ' <span title="مجدول للنشر التلقائي" style="color:#2471c9">⏰</span>',
        'processing' => ' <span title="جاري النشر" style="color:#a06c1e">⏳</span>',
        'published' => ' <span title="منشور على السوشيال" style="color:#2a7d5f">📤</span>',
        'failed' => ' <span title="فشل النشر" style="color:#c0392b">⚠</span>',
        'cancelled' => ' <span title="النشر ملغى" style="color:#888">⊘</span>',
        default => '',
    };
}
$stats = plan_stats($planId);
$ideasCost = (int) get_setting('plan_ideas_cost', 2);
$produceCost = cost_for('content_generation_cost');
$balance = user_credits();

// شهر التقويم المعروض
$calMonth = preg_match('/^\d{4}-\d{2}$/', $_GET['m'] ?? '') ? $_GET['m'] : date('Y-m');
$monthStart = $calMonth . '-01';
$daysInMonth = (int) date('t', strtotime($monthStart));
$firstDow = (int) date('w', strtotime($monthStart)); // 0=أحد ... 6=سبت
$prevMonth = date('Y-m', strtotime($monthStart . ' -1 month'));
$nextMonth = date('Y-m', strtotime($monthStart . ' +1 month'));
$monthNames = [1=>'يناير',2=>'فبراير',3=>'مارس',4=>'أبريل',5=>'مايو',6=>'يونيو',7=>'يوليو',8=>'أغسطس',9=>'سبتمبر',10=>'أكتوبر',11=>'نوفمبر',12=>'ديسمبر'];
$monthLabel = $monthNames[(int) date('n', strtotime($monthStart))] . ' ' . date('Y', strtotime($monthStart));

// أفكار حسب اليوم
$byDate = [];
foreach ($ideas as $i) {
    if ($i['scheduled_date'] && in_array($i['status'], ['selected', 'produced'], true)) {
        $byDate[$i['scheduled_date']][] = $i;
    }
}

$angleColors = [
    'storytelling' => '#8e44ad', 'promotional' => '#e67e22', 'awareness' => '#2980b9',
    'educational' => '#16a085', 'engaging' => '#c2185b', 'offer' => '#c0392b', 'trend' => '#f39c12',
];

$active = 'plan';
$page_title = e($plan['title']);
include __DIR__ . '/../templates/header.php';
?>

<div class="app">
    <?php include __DIR__ . '/../templates/sidebar.php'; ?>
    <main class="main">
        <?php include __DIR__ . '/../templates/topbar.php'; ?>

        <div class="page-head with-actions">
            <div>
                <h1><?= e($plan['title']) ?></h1>
                <div class="sub">
                    <span id="stat-line"><?= $stats['total'] ?> فكرة · <span id="c-selected"><?= $stats['selected'] ?></span> مختارة · <span id="c-produced"><?= $stats['produced'] ?></span> منتَجة</span>
                    <span class="cr">· رصيدك: <b><?= $balance ?></b> ◇</span>
                </div>
            </div>
            <div style="display:flex;gap:8px">
                <a href="<?= url('content-plan.php') ?>" class="btn">→ كل الخطط</a>
                <button class="btn btn-primary" id="gen-ideas-btn" onclick="generateIdeas()">
                    ✦ <?= $stats['total'] ? 'توليد أفكار إضافية' : 'توليد الأفكار' ?> (<?= $ideasCost ?> ◇)
                </button>
            </div>
        </div>

        <?= render_flash() ?>

        <!-- Tabs -->
        <div class="seg" style="margin-bottom:16px;max-width:400px">
            <button type="button" class="on" id="tab-ideas" onclick="showTab('ideas')">💡 الأفكار</button>
            <button type="button" id="tab-cal" onclick="showTab('cal')">🗓 التقويم</button>
        </div>

        <!-- ═══ TAB: الأفكار ═══ -->
        <div id="pane-ideas">

            <?php if ($stats['selected'] > 0): ?>
            <div class="card" style="margin-bottom:16px;display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
                <div>
                    <b>جاهز للإنتاج:</b> <span id="prod-count"><?= $stats['selected'] ?></span> فكرة مختارة
                    <span class="sub">— التكلفة: <span id="prod-cost"><?= $stats['selected'] * $produceCost ?></span> ◇ (<?= $produceCost ?> لكل بوست)</span>
                    <div id="prod-progress" class="sub" style="display:none;margin-top:6px"></div>
                </div>
                <button class="btn btn-primary" id="produce-btn" onclick="produceAll()">🚀 إنتاج المختار (محتوى + فكرة تصميم)</button>
            </div>
            <?php endif; ?>

            <?php if (!$ideas): ?>
                <div class="card"><p class="sub">اضغط «توليد الأفكار» فوق — الـ AI هيقترح <?= (int) $plan['ideas_count'] ?> فكرة متنوعة مبنية على هويتك ومستنداتك، وتختار منها اللي يعجبك ✦</p></div>
            <?php else: ?>
                <div class="auto-grid">
                <?php foreach ($ideas as $i):
                    $c = $angleColors[$i['angle']] ?? '#666';
                ?>
                    <div class="card idea-card" id="idea-<?= $i['id'] ?>" data-status="<?= $i['status'] ?>"
                         style="<?= $i['status'] === 'rejected' ? 'opacity:.45;' : '' ?><?= $i['status'] === 'selected' ? 'outline:2px solid var(--primary,#4a3db8);' : '' ?><?= $i['status'] === 'produced' ? 'outline:2px solid #27ae60;' : '' ?>">
                        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px">
                            <span class="chip" style="background:<?= $c ?>1a;color:<?= $c ?>;border:1px solid <?= $c ?>44"><?= e(plan_angle_label($i['angle'])) ?></span>
                            <span class="st-badge sub" style="font-size:11px">
                                <?= $i['status'] === 'produced' ? '✓ منتَجة' : ($i['status'] === 'selected' ? '★ مختارة' : ($i['status'] === 'rejected' ? 'مرفوضة' : '')) ?>
                            </span>
                        </div>
                        <b style="display:block;margin:8px 0 6px"><?= e($i['title']) ?></b>
                        <p class="sub" style="font-size:13px;min-height:36px"><?= e($i['description'] ?? '') ?></p>

                        <div style="display:flex;gap:6px;margin-top:10px;flex-wrap:wrap" class="idea-actions">
                            <?php if ($i['status'] === 'produced' && $i['content_id']): ?>
                                <a class="btn btn-sm btn-primary" href="<?= url('content-view.php?id=' . $i['content_id']) ?>">فتح البوست ←</a>
                                <input type="date" class="input" style="max-width:150px;padding:4px 8px" value="<?= e($i['scheduled_date'] ?? '') ?>"
                                       onchange="setDate(<?= $i['id'] ?>, this.value)">
                            <?php elseif ($i['status'] === 'selected'): ?>
                                <button class="btn btn-sm" onclick="ideaAction(<?= $i['id'] ?>, 'restore')">إلغاء الاختيار</button>
                                <button class="btn btn-sm btn-primary" onclick="produceOne(<?= $i['id'] ?>, this)">⚡ إنتاج (<?= $produceCost ?> ◇)</button>
                            <?php elseif ($i['status'] === 'rejected'): ?>
                                <button class="btn btn-sm" onclick="ideaAction(<?= $i['id'] ?>, 'restore')">↩ استرجاع</button>
                            <?php else: ?>
                                <button class="btn btn-sm btn-primary" onclick="ideaAction(<?= $i['id'] ?>, 'select')">✓ اختيار</button>
                                <button class="btn btn-sm" onclick="ideaAction(<?= $i['id'] ?>, 'reject')">✕</button>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- ═══ TAB: التقويم ═══ -->
        <div id="pane-cal" style="display:none">

            <div class="card" style="margin-bottom:16px;display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
                <div style="display:flex;align-items:center;gap:10px">
                    <a class="btn btn-sm" href="?id=<?= $planId ?>&m=<?= $prevMonth ?>#cal">‹</a>
                    <b style="min-width:130px;text-align:center"><?= $monthLabel ?></b>
                    <a class="btn btn-sm" href="?id=<?= $planId ?>&m=<?= $nextMonth ?>#cal">›</a>
                </div>
                <form onsubmit="autoSchedule(event)" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                    <label class="sub">جدولة تلقائية من:</label>
                    <input type="date" id="as-start" class="input" style="max-width:150px;padding:6px 8px" value="<?= e($plan['start_date'] ?: date('Y-m-d')) ?>" required>
                    <select id="as-every" class="input" style="max-width:120px;padding:6px 8px">
                        <option value="1">كل يوم</option>
                        <option value="2" selected>كل يومين</option>
                        <option value="3">كل 3 أيام</option>
                        <option value="7">أسبوعي</option>
                    </select>
                    <button type="submit" class="btn btn-sm btn-primary">↯ توزيع</button>
                </form>
            </div>

            <div class="card">
                <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:10px">
                    <p class="sub" style="margin:0">اسحب أي بوست وأفلته على يوم تاني لتغيير موعده 👇</p>
                    <div class="seg cal-switch" style="display:none">
                        <button type="button" class="on" onclick="setCalView('grid', this)">▦ شبكة</button>
                        <button type="button" onclick="setCalView('list', this)">☰ قائمة</button>
                    </div>
                </div>
                <div class="cal-grid" style="font-size:12px">
                    <?php foreach (['الأحد','الاثنين','الثلاثاء','الأربعاء','الخميس','الجمعة','السبت'] as $d): ?>
                        <div class="sub" style="text-align:center;font-weight:bold;padding:4px"><?= $d ?></div>
                    <?php endforeach; ?>

                    <?php for ($i = 0; $i < $firstDow; $i++): ?><div></div><?php endfor; ?>

                    <?php for ($day = 1; $day <= $daysInMonth; $day++):
                        $date = sprintf('%s-%02d', $calMonth, $day);
                        $isToday = $date === date('Y-m-d');
                    ?>
                        <div class="cal-day" data-date="<?= $date ?>"
                             ondragover="event.preventDefault();this.style.background='var(--primary-soft,#eee)'"
                             ondragleave="this.style.background=''"
                             ondrop="dropIdea(event, '<?= $date ?>', this)"
                             style="min-height:86px;border:1px solid <?= $isToday ? 'var(--primary,#4a3db8)' : '#e5e2f0' ?>;border-radius:8px;padding:4px">
                            <div class="sub" style="font-size:11px;<?= $isToday ? 'color:var(--primary,#4a3db8);font-weight:bold' : '' ?>"><?= $day ?></div>
                            <?php foreach ($byDate[$date] ?? [] as $ci):
                                $c = $angleColors[$ci['angle']] ?? '#666';
                            ?>
                                <div draggable="true" ondragstart="event.dataTransfer.setData('text/plain', '<?= $ci['id'] ?>')"
                                     title="<?= e($ci['title']) ?>"
                                     style="background:<?= $c ?>1a;border-inline-start:3px solid <?= $c ?>;border-radius:4px;padding:3px 5px;margin-top:3px;cursor:grab;font-size:11px;line-height:1.3;overflow:hidden">
                                    <?= $ci['status'] === 'produced' ? '✓' : '★' ?><?= pub_badge($ci['c_pub'] ?? null) ?>
                                    <?php if ($ci['content_id']): ?>
                                        <a href="<?= url('content-view.php?id=' . $ci['content_id']) ?>"><?= e(mb_substr($ci['title'], 0, 34)) ?></a>
                                    <?php else: ?>
                                        <?= e(mb_substr($ci['title'], 0, 34)) ?>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endfor; ?>
                </div>
                <!-- عرض القائمة (موبايل) -->
                <div class="cal-list" style="display:none">
                    <?php
                    $upcoming = [];
                    for ($day = 1; $day <= $daysInMonth; $day++) {
                        $date = sprintf('%s-%02d', $calMonth, $day);
                        if (!empty($byDate[$date])) {
                            $upcoming[$date] = $byDate[$date];
                        }
                    }
                    ?>
                    <?php if (!$upcoming): ?>
                        <p class="sub">مفيش بوستات متجدولة الشهر ده — استخدم «توزيع تلقائي» فوق</p>
                    <?php else: ?>
                        <?php foreach ($upcoming as $date => $items):
                            $isToday = $date === date('Y-m-d');
                            $dayName = ['Sun'=>'الأحد','Mon'=>'الاثنين','Tue'=>'الثلاثاء','Wed'=>'الأربعاء','Thu'=>'الخميس','Fri'=>'الجمعة','Sat'=>'السبت'][date('D', strtotime($date))] ?? '';
                        ?>
                            <div style="margin-bottom:14px">
                                <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;<?= $isToday ? 'color:var(--primary-ink);font-weight:700' : '' ?>">
                                    <span style="background:<?= $isToday ? 'var(--primary)' : 'var(--surface-2)' ?>;color:<?= $isToday ? '#fff' : 'inherit' ?>;border-radius:8px;padding:3px 9px;font-size:12px;font-weight:700"><?= (int) date('j', strtotime($date)) ?></span>
                                    <b style="font-size:13px"><?= e($dayName) ?></b>
                                    <?php if ($isToday): ?><span class="chip chip-primary" style="font-size:10px">النهارده</span><?php endif; ?>
                                </div>
                                <?php foreach ($items as $ci): $c = $angleColors[$ci['angle']] ?? '#666'; ?>
                                    <div style="background:<?= $c ?>14;border-inline-start:3px solid <?= $c ?>;border-radius:8px;padding:9px 11px;margin-bottom:6px;font-size:13px;line-height:1.5">
                                        <?= $ci['status'] === 'produced' ? '✓' : '★' ?><?= pub_badge($ci['c_pub'] ?? null) ?>
                                        <?php if ($ci['content_id']): ?>
                                            <a href="<?= url('content-view.php?id=' . $ci['content_id']) ?>"><?= e($ci['title']) ?></a>
                                        <?php else: ?>
                                            <?= e($ci['title']) ?>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <p class="sub" style="margin-top:10px;font-size:12px">
                    ⏰ مجدول تلقائي · ⏳ جاري النشر · 📤 منشور · ⚠ فشل · ⊘ ملغى
                </p>
                <p class="sub" style="margin-top:6px;font-size:12px">
                    💡 التاريخ هنا هو موعد الخطة التحريري — للنشر التلقائي الفعلي افتح البوست واضغط «جدولة النشر» (زي ما انت متعود).
                </p>
            </div>
        </div>

    </main>
</div>

<script>
const CSRF = '<?= e(csrf_token()) ?>';
const PLAN_ID = <?= $planId ?>;

function post(url, data) {
    const fd = new FormData();
    fd.append('csrf', CSRF);
    for (const k in data) fd.append(k, data[k]);
    return fetch(url, { method: 'POST', body: fd }).then(r => r.json());
}

function showTab(t) {
    document.getElementById('pane-ideas').style.display = t === 'ideas' ? '' : 'none';
    document.getElementById('pane-cal').style.display = t === 'cal' ? '' : 'none';
    document.getElementById('tab-ideas').classList.toggle('on', t === 'ideas');
    document.getElementById('tab-cal').classList.toggle('on', t === 'cal');
}
if (location.hash === '#cal') showTab('cal');

function generateIdeas() {
    const btn = document.getElementById('gen-ideas-btn');
    btn.disabled = true; btn.textContent = '⏳ الـ AI بيفكر في الأفكار...';
    post('<?= url('ajax/generate-plan-ideas.php') ?>', { plan_id: PLAN_ID })
        .then(d => { if (d.ok) location.reload(); else { alert(d.error || 'حصل خطأ'); btn.disabled = false; btn.textContent = '✦ توليد الأفكار'; } })
        .catch(() => { alert('خطأ في الاتصال'); btn.disabled = false; });
}

function ideaAction(id, action) {
    post('<?= url('ajax/plan-idea-action.php') ?>', { action, idea_id: id })
        .then(d => { if (d.ok) location.reload(); else alert(d.error || 'حصل خطأ'); });
}

function setDate(id, date) {
    post('<?= url('ajax/plan-idea-action.php') ?>', { action: 'set_date', idea_id: id, date });
}

function dropIdea(ev, date, cell) {
    ev.preventDefault(); cell.style.background = '';
    const id = ev.dataTransfer.getData('text/plain');
    if (!id) return;
    post('<?= url('ajax/plan-idea-action.php') ?>', { action: 'set_date', idea_id: id, date })
        .then(d => { if (d.ok) location.href = '?id=' + PLAN_ID + '&m=<?= $calMonth ?>#cal'; else alert(d.error || 'حصل خطأ'); });
}

function autoSchedule(ev) {
    ev.preventDefault();
    post('<?= url('ajax/plan-idea-action.php') ?>', {
        action: 'auto_schedule', plan_id: PLAN_ID,
        start_date: document.getElementById('as-start').value,
        every_days: document.getElementById('as-every').value
    }).then(d => { if (d.ok) location.href = '?id=' + PLAN_ID + '#cal'; else alert(d.error || 'حصل خطأ'); });
}

function produceOne(id, btn) {
    btn.disabled = true; btn.textContent = '⏳ جاري الإنتاج...';
    post('<?= url('ajax/produce-idea.php') ?>', { idea_id: id })
        .then(d => { if (d.ok) location.reload(); else { alert(d.error || 'حصل خطأ'); btn.disabled = false; btn.textContent = '⚡ إنتاج'; } });
}

// إنتاج كل المختار — فكرة فكرة عشان مفيش timeout
async function produceAll() {
    const cards = [...document.querySelectorAll('.idea-card[data-status="selected"]')];
    if (!cards.length) return;
    if (!confirm('إنتاج ' + cards.length + ' بوست؟ التكلفة ' + (cards.length * <?= $produceCost ?>) + ' كريدت')) return;

    const btn = document.getElementById('produce-btn');
    const prog = document.getElementById('prod-progress');
    btn.disabled = true; prog.style.display = '';
    let done = 0, failed = 0;

    for (const card of cards) {
        const id = card.id.replace('idea-', '');
        const title = card.querySelector('b').textContent;
        prog.textContent = '⏳ (' + (done + failed + 1) + '/' + cards.length + ') بينتج: ' + title;
        try {
            const d = await post('<?= url('ajax/produce-idea.php') ?>', { idea_id: id });
            if (d.ok) { done++; card.style.outline = '2px solid #27ae60'; }
            else {
                failed++;
                if ((d.error || '').includes('رصيد')) { alert(d.error); break; }
            }
        } catch (e) { failed++; }
    }
    prog.textContent = '✓ خلص: ' + done + ' اتنتج' + (failed ? ' — ' + failed + ' فشل' : '');
    setTimeout(() => location.reload(), 1200);
}
</script>

<script>
function setCalView(mode, btn) {
    document.querySelectorAll('.cal-switch button').forEach(b => b.classList.toggle('on', b === btn));
    document.querySelector('.cal-grid').style.display = mode === 'grid' ? '' : 'none';
    document.querySelector('.cal-list').style.display = mode === 'list' ? '' : 'none';
    try { localStorage.setItem('calView', mode); } catch (e) {}
}
(function () {
    if (window.innerWidth <= 720) {
        const sw = document.querySelector('.cal-switch');
        if (sw) {
            sw.style.display = 'flex';
            setCalView('list', sw.querySelectorAll('button')[1]);
        }
    }
})();
</script>
<?php include __DIR__ . '/../templates/footer.php'; ?>

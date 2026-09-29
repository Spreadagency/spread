<?php
/**
 * Spread AI v2 — مساعد الهوية الذكي
 * محادثة تفاعلية: الايجنت يسأل ويظبط هوية البراند بالكامل
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/credits.php';

require_login();
$user = current_user();
$agentCost = (int) get_setting('brand_agent_cost', 0);

$active = 'brand-agent';
$page_title = 'مساعد الهوية';
include __DIR__ . '/../templates/header.php';
?>

<div class="app">
    <?php include __DIR__ . '/../templates/sidebar.php'; ?>
    <main class="main">
        <?php include __DIR__ . '/../templates/topbar.php'; ?>

        <div class="page-head" style="display:flex;justify-content:space-between;align-items:flex-start;gap:10px;flex-wrap:wrap">
            <div>
                <h1>مساعد الهوية 🤖</h1>
                <div class="sub">جاوب على أسئلته وهو هيظبطلك هوية براندك كاملة — وتقدر تعدلها بعدين من صفحة الهوية<?= $agentCost > 0 ? " ({$agentCost} ◇ لكل رسالة)" : '' ?></div>
            </div>
            <button class="btn ghost sm" onclick="restartAgent()">⟲ ابدأ من جديد</button>
        </div>

        <div class="card" style="max-width:760px;display:flex;flex-direction:column;height:calc(100vh - 220px);min-height:420px">
            <div id="chat-box" style="flex:1;overflow-y:auto;padding:6px 2px;display:flex;flex-direction:column;gap:12px"></div>

            <div style="display:flex;gap:8px;border-top:1px solid var(--line);padding-top:12px;margin-top:10px">
                <input type="text" id="chat-input" class="input" placeholder="اكتب إجابتك هنا..." style="flex:1"
                       onkeydown="if(event.key==='Enter'){sendAgent()}">
                <button class="btn" id="chat-send" onclick="sendAgent()">إرسال ↵</button>
            </div>
        </div>

    </main>
</div>

<script>
const CSRF = '<?= e(csrf_token()) ?>';
const box = document.getElementById('chat-box');

function mdLite(t) {
    return t.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/\*\*(.+?)\*\*/g, '<b>$1</b>')
            .replace(/\n/g, '<br>');
}

function addBubble(role, text) {
    const d = document.createElement('div');
    d.style.cssText = role === 'user'
        ? 'align-self:flex-start;background:var(--primary);color:#fff;border-radius:16px 16px 16px 4px;padding:10px 14px;max-width:80%;font-size:14px;line-height:1.7'
        : 'align-self:flex-end;background:var(--surface-2);border-radius:16px 16px 4px 16px;padding:10px 14px;max-width:85%;font-size:14px;line-height:1.8';
    d.innerHTML = mdLite(text);
    box.appendChild(d);
    box.scrollTop = box.scrollHeight;
    return d;
}

async function api(data) {
    const fd = new FormData();
    fd.append('csrf', CSRF);
    for (const k in data) fd.append(k, data[k]);
    if (typeof safeFormData === 'function' && data.message) safeFormData(fd, ['message']);
    const r = await fetch('<?= url('ajax/brand-agent.php') ?>', { method: 'POST', body: fd, credentials: 'same-origin' });
    return await r.json();
}

async function loadAgent() {
    try {
        const d = await api({ action: 'load' });
        if (d.ok) {
            box.innerHTML = '';
            d.messages.forEach(m => addBubble(m.role, m.content));
        }
    } catch (e) {
        addBubble('assistant', 'حصل خطأ في التحميل — حدّث الصفحة');
    }
}

async function sendAgent() {
    const input = document.getElementById('chat-input');
    const btn = document.getElementById('chat-send');
    const text = input.value.trim();
    if (!text) return;
    input.value = '';
    addBubble('user', text);
    const typing = addBubble('assistant', '⏳ بيكتب...');
    btn.disabled = true; input.disabled = true;
    try {
        const d = await api({ action: 'send', message: text });
        typing.innerHTML = d.ok ? mdLite(d.reply) : ('⚠️ ' + (d.error || 'حصل خطأ'));
        if (d.ok && d.profile_saved) {
            const a = document.createElement('div');
            a.style.cssText = 'align-self:center;margin-top:4px';
            a.innerHTML = '<a class="btn sm" href="<?= url('brand-brain.php') ?>">◈ شوف هويتك</a> <a class="btn ghost sm" href="<?= url('content-plan.php') ?>">🗓 ابدأ خطة</a>';
            box.appendChild(a);
        }
    } catch (e) {
        typing.textContent = '⚠️ خطأ في الاتصال — جرب تاني';
    }
    btn.disabled = false; input.disabled = false; input.focus();
    box.scrollTop = box.scrollHeight;
}

async function restartAgent() {
    if (!confirm('نبدأ محادثة جديدة من الأول؟')) return;
    const d = await api({ action: 'restart' });
    if (d.ok) {
        box.innerHTML = '';
        d.messages.forEach(m => addBubble(m.role, m.content));
    }
}

loadAgent();
</script>

<?php include __DIR__ . '/../templates/footer.php'; ?>

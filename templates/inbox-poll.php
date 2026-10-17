<?php
/**
 * الجرس: polling كل 30 ثانية على api/inbox.php + تنبيه صغير لما توصل رسالة جديدة
 * العنصر: [data-inbox data-badge=… data-unread=… data-dot=class] وجواه [data-inbox-btn]
 *   data-badge  = العداد وقت الرسم · data-unread = غير المقروء وقتها ← العداد الجديد = badge − unread + الحالي
 */
?>
<style>
.sp-toast { position: fixed; inset-inline-start: 16px; top: 16px; z-index: 9999; max-width: min(360px, calc(100vw - 32px)); background: #fff; border: 1px solid #DCE6F0;
    border-radius: 16px; box-shadow: 0 18px 44px rgba(12, 70, 130, .18); padding: 12px 14px; display: flex; gap: 10px; align-items: flex-start; animation: sp-toast-in .25s ease-out; }
.sp-toast b { display: block; font-size: 14px; color: #0B1526; }
.sp-toast small { color: #5B6478; font-size: 12px; margin-top: 2px; overflow: hidden; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; }
.sp-toast a { flex: none; align-self: center; padding: 7px 12px; border-radius: 10px; background: linear-gradient(135deg, #0A8FA3 0%, #0A5FCF 100%); color: #fff; font-size: 13px; font-weight: 700; text-decoration: none; }
.sp-toast button { flex: none; border: 0; background: none; font-size: 18px; line-height: 1; color: #5B6478; cursor: pointer; padding: 2px 4px; }
@keyframes sp-toast-in { from { opacity: 0; transform: translateY(-8px); } }
@media (prefers-reduced-motion: reduce) { .sp-toast { animation: none; } }
</style>
<script>
(function () {
  var wrap = document.querySelector('[data-inbox]'); if (!wrap || !window.fetch) return;
  var base = parseInt(wrap.dataset.badge, 10) || 0, unread0 = parseInt(wrap.dataset.unread, 10) || 0, dotCls = wrap.dataset.dot || 'sp-dot';
  var KEY = 'sp_inbox_max', API = <?= json_encode(url('api/inbox.php')) ?>;
  function setBadge(unread) {
    var n = Math.max(0, base - unread0 + unread), btn = wrap.querySelector('[data-inbox-btn]') || wrap, dot = btn.querySelector('.' + dotCls);
    if (n > 0) { if (!dot) { dot = document.createElement('em'); dot.className = dotCls; btn.appendChild(dot); } dot.textContent = n; }
    else if (dot) dot.remove();
  }
  function toast(m) {
    var old = document.querySelector('.sp-toast'); if (old) old.remove();
    var t = document.createElement('div'); t.className = 'sp-toast'; t.setAttribute('role', 'status');
    var tx = document.createElement('div'); tx.style.flex = '1'; tx.style.minWidth = '0';
    var b = document.createElement('b'); b.textContent = m.title; tx.appendChild(b);
    if (m.body) { var s = document.createElement('small'); s.textContent = m.body; tx.appendChild(s); }
    var a = document.createElement('a'); a.href = m.open; a.textContent = 'فتح';
    var x = document.createElement('button'); x.type = 'button'; x.setAttribute('aria-label', 'إغلاق'); x.textContent = '×'; x.onclick = function () { t.remove(); };
    t.appendChild(tx); t.appendChild(a); t.appendChild(x); document.body.appendChild(t);
    setTimeout(function () { if (t.parentNode) t.remove(); }, 9000);
  }
  function poll() {
    if (document.hidden) return;
    fetch(API + '?action=unread', { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.json(); }).then(function (d) {
        if (!d || !d.ok) return;
        setBadge(d.count);
        var seen = 0; try { seen = parseInt(sessionStorage.getItem(KEY), 10) || 0; } catch (e) {}
        if (seen && d.max_id > seen) {
          var fresh = (d.latest || []).filter(function (m) { return m.id > seen && !m.read; });
          if (fresh.length) toast(fresh[0]);
        }
        try { sessionStorage.setItem(KEY, String(d.max_id)); } catch (e) {}
      }).catch(function () {});
  }
  window.spInbox = { set: function (n) { setBadge(n); }, poll: poll };
  poll();
  setInterval(poll, 30000);
  document.addEventListener('visibilitychange', function () { if (!document.hidden) poll(); });
})();
</script>

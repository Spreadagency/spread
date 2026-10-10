/* ============================================================
   Spread AI — Main JS
   ============================================================ */

// ─── Sidebar toggle (mobile) ──────────────────────────────
function toggleSidebar() {
    const sb = document.querySelector('.sidebar');
    if (!sb) return;
    const open = sb.classList.toggle('open');
    let bd = document.querySelector('.sidebar-backdrop');
    if (!bd) {
        bd = document.createElement('div');
        bd.className = 'sidebar-backdrop';
        bd.addEventListener('click', toggleSidebar);
        document.body.appendChild(bd);
    }
    setTimeout(() => bd.classList.toggle('on', open), 10);
    document.body.style.overflow = open ? 'hidden' : '';
}

// إغلاق الدرج عند اختيار لينك أو الضغط على Escape
document.addEventListener('click', function (e) {
    const link = e.target.closest && e.target.closest('.sidebar a');
    if (link && window.innerWidth <= 980) {
        const sb = document.querySelector('.sidebar');
        if (sb && sb.classList.contains('open')) setTimeout(toggleSidebar, 60);
    }
});
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
        const sb = document.querySelector('.sidebar');
        if (sb && sb.classList.contains('open')) toggleSidebar();
    }
});

// زرار الواتساب العائم يرتفع فوق الشريط السفلي في الموبايل
document.addEventListener('DOMContentLoaded', function () {
    if (window.innerWidth <= 980 && document.querySelector('.tabbar')) {
        const wa = document.querySelector('a[href*="wa.me"][style*="position:fixed"]');
        if (wa) wa.style.bottom = '86px';
    }
});

// ─── Toasts ────────────────────────────────────────────────
function showToast(message, type = 'info', duration = 3500) {
    let container = document.querySelector('.toast-container');
    if (!container) {
        container = document.createElement('div');
        container.className = 'toast-container';
        document.body.appendChild(container);
    }
    const toast = document.createElement('div');
    toast.className = `toast ${type}`;
    toast.textContent = message;
    container.appendChild(toast);
    setTimeout(() => {
        toast.style.transition = 'opacity .25s';
        toast.style.opacity = '0';
        setTimeout(() => toast.remove(), 250);
    }, duration);
}

// ─── AJAX helper ───────────────────────────────────────────
async function ajaxPost(url, data) {
    try {
        const formData = new FormData();
        for (const k in data) {
            if (data[k] instanceof File) {
                formData.append(k, data[k]);
            } else if (Array.isArray(data[k])) {
                data[k].forEach(v => formData.append(k + '[]', v));
            } else {
                formData.append(k, data[k]);
            }
        }
        const res = await fetch(url, { method: 'POST', body: formData });
        const ct = res.headers.get('content-type') || '';
        if (ct.includes('application/json')) {
            const j = await res.json();
            // شرط الاشتراك قبل التصميم: السيرفر رفض قبل أي توليد ← شاشة الاشتراك (مرة واحدة)
            if (j && j.code === 'subscription' && j.paywall) spreadPaywall(j.paywall);
            return j;
        }
        const text = await res.text();
        return { ok: res.ok, text };
    } catch (e) {
        console.error('AJAX error:', e);
        return { ok: false, error: e.message };
    }
}

// ─── شاشة الاشتراك (التصميم والنشر للمشتركين) ─────────────────
function spreadPaywall(p) {
    if (document.getElementById('sp-paywall')) { document.getElementById('sp-paywall').style.display = 'flex'; return; }
    const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const w = document.createElement('div');
    w.id = 'sp-paywall';
    w.setAttribute('role', 'dialog'); w.setAttribute('aria-modal', 'true'); w.setAttribute('aria-labelledby', 'sp-pw-t');
    w.style.cssText = 'position:fixed;inset:0;z-index:9999;display:flex;align-items:center;justify-content:center;padding:18px;background:rgba(15,25,45,.45);backdrop-filter:blur(3px)';
    w.innerHTML = '<div style="background:#fff;border-radius:26px;max-width:440px;width:100%;padding:28px 24px;text-align:center;box-shadow:0 24px 60px rgba(12,40,90,.25);font-family:inherit">'
        + '<div style="width:64px;height:64px;margin:0 auto 12px;border-radius:20px;background:linear-gradient(150deg,#E6FBF7,#DCEBFF);display:flex;align-items:center;justify-content:center;font-size:30px">🎨</div>'
        + '<h3 id="sp-pw-t" style="margin:0 0 6px;font-size:20px">' + esc(p.title) + '</h3>'
        + '<p style="margin:0 0 14px;color:#4A5468;font-size:14px;line-height:1.8">' + esc(p.body) + '</p>'
        + '<div style="display:flex;flex-direction:column;gap:6px;text-align:start;margin:0 auto 18px;max-width:300px">'
        + (p.benefits || []).map(b => '<span style="font-size:13.5px"><b style="color:#0B7A66">✓</b> ' + esc(b) + '</span>').join('') + '</div>'
        + '<a href="' + esc(p.url) + '" style="display:flex;align-items:center;justify-content:center;height:48px;border-radius:14px;background:linear-gradient(100deg,#0A9FB0,#0A6FD8 70%);color:#fff;font-weight:700;text-decoration:none">' + esc(p.cta) + ' ←</a>'
        + '<button type="button" data-pw-close style="margin-top:8px;height:44px;width:100%;border:0;background:transparent;color:#5B6478;font-weight:600;cursor:pointer;font-family:inherit">العودة للمنشور</button></div>';
    w.addEventListener('click', e => { if (e.target === w || e.target.closest('[data-pw-close]')) w.style.display = 'none'; });
    document.addEventListener('keydown', e => { if (e.key === 'Escape') w.style.display = 'none'; });
    document.body.appendChild(w);
    const a = w.querySelector('a'); if (a) a.focus();
}

// ─── Modal helpers ─────────────────────────────────────────
function openModal(id) {
    const m = document.getElementById(id);
    if (m) m.classList.add('on');
}

function closeModal(id) {
    const m = document.getElementById(id);
    if (m) m.classList.remove('on');
}

// click outside to close
document.addEventListener('click', e => {
    if (e.target.classList.contains('modal-bg')) {
        e.target.classList.remove('on');
    }
});

// ─── Form helpers ──────────────────────────────────────────
function copyToClipboard(text, btn) {
    navigator.clipboard.writeText(text).then(() => {
        showToast('تم النسخ بنجاح ✓', 'success', 1500);
        if (btn) {
            const orig = btn.textContent;
            btn.textContent = '✓ تم النسخ';
            setTimeout(() => btn.textContent = orig, 1500);
        }
    }).catch(() => showToast('فشل النسخ', 'danger'));
}

// ─── Confirm dialog ────────────────────────────────────────
function confirmAction(message, onYes) {
    if (confirm(message)) onYes();
}

// ─── Time/date formatting ─────────────────────────────────
function timeAgo(dateStr) {
    const d = new Date(dateStr);
    const diff = Math.floor((Date.now() - d.getTime()) / 1000);
    if (diff < 60) return 'الآن';
    if (diff < 3600) return `منذ ${Math.floor(diff / 60)} دقيقة`;
    if (diff < 86400) return `منذ ${Math.floor(diff / 3600)} ساعة`;
    if (diff < 2592000) return `منذ ${Math.floor(diff / 86400)} يوم`;
    return d.toLocaleDateString('ar-EG');
}

// ─── Auto-resize textareas ────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.textarea.auto').forEach(t => {
        t.addEventListener('input', () => {
            t.style.height = 'auto';
            t.style.height = t.scrollHeight + 'px';
        });
    });
});

// ─── Image upload preview ──────────────────────────────────
function previewImage(input, previewId) {
    const file = input.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = e => {
        const preview = document.getElementById(previewId);
        if (preview) preview.src = e.target.result;
    };
    reader.readAsDataURL(file);
}


/* ─── تفادي حجب mod_security (403) للنصوص العربية الطويلة ───
   أي فورم فيه data-safe-post بيتشفّر محتوى حقوله النصية base64 قبل الإرسال،
   والسيرفر بيفكّها عن طريق decode_b64_fields() */
(function () {
    function b64(str) {
        return btoa(String.fromCharCode.apply(null, new TextEncoder().encode(str)))
            .replace(/\+/g, '-').replace(/\//g, '_');
    }
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form || !form.hasAttribute || !form.hasAttribute('data-safe-post')) return;
        if (form.dataset.safeDone === '1') return;

        var names = [];
        form.querySelectorAll('textarea, input[type="text"], input[type="tel"], input[type="url"], input[type="search"]').forEach(function (el) {
            if (!el.name || el.disabled || el.dataset.noEncode === '1') return;
            if (!el.value || el.value.length === 0) return;
            try {
                el.value = b64(el.value);
                names.push(el.name);
            } catch (err) { /* لو فشل التشفير نسيبه زي ما هو */ }
        });

        if (names.length) {
            var h = document.createElement('input');
            h.type = 'hidden';
            h.name = '_b64';
            h.value = names.join(',');
            form.appendChild(h);
        }
        form.dataset.safeDone = '1';
    }, true);
})();


/* تشفير حقول FormData الطويلة قبل fetch (نفس منطق data-safe-post) */
window.safeFormData = function (fd, fields) {
    try {
        var names = [];
        fields.forEach(function (n) {
            var v = fd.get(n);
            if (typeof v === 'string' && v.length > 0) {
                fd.set(n, btoa(String.fromCharCode.apply(null, new TextEncoder().encode(v)))
                    .replace(/\+/g, '-').replace(/\//g, '_'));
                names.push(n);
            }
        });
        if (names.length) fd.append('_b64', names.join(','));
    } catch (e) { /* fallback: تبعت زي ما هي */ }
    return fd;
};

/* ─── عارض الصور (Lightbox) مع زرار تحميل ───
   أي صورة جوه <a data-lightbox> أو أي <img class="zoomable"> بتفتح في العارض */
(function () {
    let overlay = null;

    function buildOverlay() {
        overlay = document.createElement('div');
        overlay.id = 'img-lightbox';
        overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.88);z-index:9999;display:none;align-items:center;justify-content:center;flex-direction:column;gap:14px;padding:20px';
        overlay.innerHTML =
            '<img id="lb-img" src="" alt="" style="max-width:92vw;max-height:76vh;border-radius:10px;box-shadow:0 10px 40px rgba(0,0,0,.5);background:#fff">' +
            '<div style="display:flex;gap:10px;flex-wrap:wrap;justify-content:center">' +
              '<a id="lb-download" class="btn" href="" download style="background:#fff;color:#222;text-decoration:none">⬇ تحميل الصورة</a>' +
              '<a id="lb-open" class="btn" href="" target="_blank" rel="noopener" style="background:transparent;color:#fff;border:1px solid rgba(255,255,255,.5);text-decoration:none">↗ فتح في تبويب</a>' +
              '<button id="lb-close" class="btn" style="background:transparent;color:#fff;border:1px solid rgba(255,255,255,.5)">✕ إغلاق</button>' +
            '</div>';
        document.body.appendChild(overlay);

        overlay.addEventListener('click', function (e) {
            if (e.target === overlay || e.target.id === 'lb-close') closeLightbox();
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') closeLightbox();
        });
    }

    window.openLightbox = function (src, filename) {
        if (!overlay) buildOverlay();
        overlay.querySelector('#lb-img').src = src;
        const dl = overlay.querySelector('#lb-download');
        dl.href = src;
        dl.setAttribute('download', filename || (src.split('/').pop() || 'image.png'));
        overlay.querySelector('#lb-open').href = src;
        overlay.style.display = 'flex';
        document.body.style.overflow = 'hidden';
    };

    window.closeLightbox = function () {
        if (overlay) overlay.style.display = 'none';
        document.body.style.overflow = '';
    };

    // اعتراض النقر على أي صورة مؤهلة
    document.addEventListener('click', function (e) {
        const img = e.target.closest && e.target.closest('img');
        if (!img) return;
        const link = img.closest('a');
        const isImageLink = link && /\.(png|jpe?g|webp|gif|svg)(\?|$)/i.test(link.getAttribute('href') || '');
        if (img.classList.contains('zoomable') || isImageLink || (link && link.hasAttribute('data-lightbox'))) {
            e.preventDefault();
            openLightbox(isImageLink ? link.href : img.src, img.dataset.filename);
        }
    }, true);
})();

/* ═══════════ تحسين تجربة المستخدم ═══════════ */

/**
 * ① حالة تحميل تلقائية لأزرار الإرسال
 * بيمنع الضغط المزدوج (اللي بيعمل منشورين أو خصم مرتين)
 */
(function () {
  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!form || form.dataset.noBusy === '1') return;

    var btn = form.querySelector('button[type="submit"], button:not([type])');
    if (!btn || btn.disabled) return;

    // نسيب الفورم يتبعت الأول، وبعدين نقفل الزرار
    setTimeout(function () {
      if (form.dataset.invalid === '1') return;
      btn.dataset.originalHtml = btn.innerHTML;
      btn.disabled = true;
      btn.setAttribute('aria-busy', 'true');
      var busy = btn.getAttribute('data-busy-text') || 'لحظة...';
      btn.innerHTML = '<span class="btn-spin" aria-hidden="true"></span> ' + busy.replace(/[<>&]/g, '');
    }, 0);

    // شبكة أمان: لو الصفحة ما اتغيرتش (خطأ شبكة) نفك الزرار
    setTimeout(function () {
      if (btn.disabled && btn.dataset.originalHtml) {
        btn.disabled = false;
        btn.removeAttribute('aria-busy');
        btn.innerHTML = btn.dataset.originalHtml;
      }
    }, 25000);
  }, true);
})();

/**
 * ② تأكيد قبل مغادرة صفحة فيها تعديلات مش محفوظة
 */
(function () {
  var dirty = false;
  document.addEventListener('input', function (e) {
    var f = e.target.closest('form[data-warn-unsaved]');
    if (f) dirty = true;
  });
  document.addEventListener('submit', function () { dirty = false; });
  window.addEventListener('beforeunload', function (e) {
    if (!dirty) return;
    e.preventDefault();
    e.returnValue = '';
  });
})();

/**
 * ③ الرسائل بتختفي لوحدها بعد 6 ثواني (ما عدا الأخطاء)
 */
(function () {
  document.querySelectorAll('.alert.success, .alert.info').forEach(function (el) {
    setTimeout(function () {
      el.style.transition = 'opacity .5s, transform .5s';
      el.style.opacity = '0';
      el.style.transform = 'translateY(-8px)';
      setTimeout(function () { el.remove(); }, 520);
    }, 6000);
  });
})();

/**
 * ④ عدّاد حروف للحقول اللي ليها حد
 * بتفويض الأحداث على مستوى الصفحة — فبيفضل شغال حتى لو الواجهة اتعاد
 * رسمها (petite-vue بيستبدل العناصر جوه v-if). spreadRecountChars() لتحديث الكل.
 */
(function () {
  var SEL = 'textarea[maxlength], input[maxlength]';
  function counterFor(el) {
    var n = el.nextElementSibling;
    if (n && n.classList.contains('char-count')) return n;
    var c = document.createElement('div');
    c.className = 'char-count';
    el.parentNode.insertBefore(c, el.nextSibling);
    return c;
  }
  function upd(el) {
    var max = parseInt(el.getAttribute('maxlength'), 10);
    if (!max || max < 40 || !el.parentNode) return;
    var c = counterFor(el);
    var n = el.value.length;
    var txt = n + ' / ' + max;
    // نكتب بس لو اتغيّر: الكتابة بتعمل mutation، والـ MutationObserver بتاع SpreadApp.mount
    // كان بيرجع يعدّ تاني ← لفّة لا نهائية كل 30ms بتتقّل الصفحة
    if (c.textContent !== txt) c.textContent = txt;
    var near = n > max * 0.9;
    if (c.classList.contains('near') !== near) c.classList.toggle('near', near);
  }
  window.spreadRecountChars = function (root) {
    (root || document).querySelectorAll(SEL).forEach(upd);
  };
  document.addEventListener('input', function (e) {
    if (e.target && e.target.matches && e.target.matches(SEL)) upd(e.target);
  });
  window.spreadRecountChars();
})();

/**
 * ⑤ تأكيد للأفعال الخطيرة (حذف · استرجاع)
 */
(function () {
  document.addEventListener('click', function (e) {
    var el = e.target.closest('[data-confirm]');
    if (!el) return;
    if (!window.confirm(el.getAttribute('data-confirm'))) {
      e.preventDefault();
      e.stopPropagation();
    }
  }, true);
})();

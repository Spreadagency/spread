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
            return await res.json();
        }
        const text = await res.text();
        return { ok: res.ok, text };
    } catch (e) {
        console.error('AJAX error:', e);
        return { ok: false, error: e.message };
    }
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
    c.textContent = n + ' / ' + max;
    c.classList.toggle('near', n > max * 0.9);
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

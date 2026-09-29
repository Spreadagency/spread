/* ═══ تفادي حجب mod_security (403) في لوحة الموقع ═══
   أي فورم فيه data-safe-post بيتشفّر محتوى حقوله base64 قبل الإرسال،
   والسيرفر بيفكّها بـ s_decode_b64() */
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
        var sel = 'textarea, input[type="text"], input[type="tel"], input[type="url"], input[type="search"], input:not([type])';
        form.querySelectorAll(sel).forEach(function (el) {
            if (!el.name || el.disabled || el.readOnly) return;
            if (el.dataset.noEncode === '1') return;
            if (!el.value || el.value.length === 0) return;
            try {
                el.value = b64(el.value);
                names.push(el.name);
            } catch (err) { /* لو فشل نسيبه زي ما هو */ }
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

#!/usr/bin/env python3
"""
Spread AI — أداة الترجمة (للتطوير بس): بتلف النصوص العربي في أجزاء الـ HTML بتاعة ملف PHP بـ __()

    python3 tests/i18n-wrap.py templates/v2/auth.php [--dry]

- بتشتغل على الـ HTML اللي برّه <?php ... ?> بس (الكود نفسه مابيتلمسش)
- النص بين التاجات ← <?= e(__('النص')) ?>  (المسافات حواليه زي ما هي)
- الخصائص placeholder · aria-label · title · alt · data-busy-text ← نفس الشيء جوه القيمة
- جوه <script> و <style> مابتلمسش حاجة
- بتطبع النصوص اللي لفّتها (علشان تتضاف لـ lang/en/*.php)
بعد التشغيل: راجع الـ diff بعينك — دي أداة مساعدة مش بديل للمراجعة.
"""
import re
import sys

AR = re.compile('[؀-ۿ]')
ATTRS = ('placeholder', 'aria-label', 'title', 'alt', 'data-busy-text', 'data-confirm', 'data-done-text')


def php_str(s: str) -> str:
    return "'" + s.replace('\\', '\\\\').replace("'", "\\'") + "'"


def wrap(s: str) -> str:
    return '<?= e(__(' + php_str(s) + ')) ?>'


def split_php(src: str):
    """[(is_php, chunk)]"""
    out, i = [], 0
    for m in re.finditer(r'<\?(?:php|=)?.*?(?:\?>|\Z)', src, re.S):
        if m.start() > i:
            out.append((False, src[i:m.start()]))
        out.append((True, m.group(0)))
        i = m.end()
    if i < len(src):
        out.append((False, src[i:]))
    return out


def main():
    path = sys.argv[1]
    dry = '--dry' in sys.argv
    src = open(path, encoding='utf-8').read()
    keys = []
    in_tag = False
    skip_until = None  # '</script' | '</style'
    res = []
    for is_php, chunk in split_php(src):
        if is_php:
            res.append(chunk)
            continue
        buf, text = [], []

        def flush():
            t = ''.join(text)
            text.clear()
            if t and AR.search(t):
                lead = t[:len(t) - len(t.lstrip())]
                trail = t[len(t.rstrip()):]
                core = t.strip()
                keys.append(core)
                buf.append(lead + wrap(core) + trail)
            else:
                buf.append(t)

        j = 0
        while j < len(chunk):
            c = chunk[j]
            if skip_until:
                k = chunk.lower().find(skip_until, j)
                if k == -1:
                    buf.append(chunk[j:])
                    j = len(chunk)
                    break
                buf.append(chunk[j:k])
                j = k
                skip_until = None
                continue
            if not in_tag and c == '<':
                flush()
                in_tag = True
                low = chunk[j:j + 7].lower()
                tagstart = j
                buf.append(c)
                j += 1
                if low.startswith('<script'):
                    pending_skip = '</script'
                elif low.startswith('<style'):
                    pending_skip = '</style'
                else:
                    pending_skip = None
                # نكمّل لحد آخر التاج (أو آخر الجزء)
                while j < len(chunk) and chunk[j] != '>':
                    buf.append(chunk[j])
                    j += 1
                if j < len(chunk):
                    buf.append('>')
                    j += 1
                    in_tag = False
                    if pending_skip:
                        skip_until = pending_skip
                continue
            if in_tag:
                buf.append(c)
                if c == '>':
                    in_tag = False
                j += 1
                continue
            text.append(c)
            j += 1
        flush()
        html = ''.join(buf)

        def attr(m):
            v = m.group(2)
            if '<?' in v or not AR.search(v):
                return m.group(0)
            keys.append(v.strip())
            return m.group(1) + '="' + wrap(v.strip()) + '"'
        html = re.sub(r'\b(' + '|'.join(ATTRS) + r')="([^"<]*)"', attr, html)
        res.append(html)
    out = ''.join(res)
    if not dry:
        open(path, 'w', encoding='utf-8').write(out)
    seen = []
    for k in keys:
        if k not in seen:
            seen.append(k)
    for k in seen:
        print(k)


if __name__ == '__main__':
    main()

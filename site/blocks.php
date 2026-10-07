<?php
/**
 * Spread AI — Page Builder: أنواع البلوكات · الرسم · تنظيف الـ HTML
 *
 *  الصفحة = blocks_json: [{id, type, hidden, d:{…}}] — بيتعدّل من لوحة الموقع (site-admin/page-edit.php)
 *  كل بلوك بيترسم بنفس مكوّنات الموقع (home-v2.css + pages.css) — وبلوك «قسم من الرئيسية» بيعرض القسم نفسه.
 *
 *  الأمان (Custom HTML):
 *   ① safe  (افتراضي): s_sanitize_html — قائمة بيضا للوسوم والخصائص: مفيش <script> ولا on* ولا javascript: ولا <form>/<object>/<embed>،
 *      والـ iframe مسموح بس من يوتيوب/فيميو/خرائط جوجل.
 *   ② frame: الـ HTML كامل جوه <iframe sandbox srcdoc> من غير allow-scripts و allow-same-origin —
 *      يعني أي سكريبت مش هيشتغل، ومفيش وصول للكوكيز أو الجلسة أو الصفحة أو قاعدة البيانات.
 */
require_once __DIR__ . '/home.php';

/** أنواع البلوكات: type => [الاسم, أيقونة الأدمن, وصف, الحقول] — الحقل: [key, label, type, extra] */
function s_block_types(): array
{
    $btn = [['btn_text', 'نص الزرار', 'text'], ['btn_url', 'رابط الزرار', 'url']];
    $sections = ['trial' => 'اصنع منشورك الآن', 'services' => 'الخدمات', 'steps' => 'خطوات العمل', 'problems' => 'المشاكل', 'about' => 'الحلول',
                 'brands' => 'البراندات', 'promos' => 'العروض', 'gallery' => 'التصميمات', 'pricing' => 'الأسعار', 'testimonials' => 'آراء العملاء', 'faq' => 'الأسئلة الشائعة'];
    return [
        'hero' => ['Hero', 'sparkle', 'عنوان كبير + وصف + أزرار + صورة', [
            ['badge', 'شارة صغيرة', 'text'], ['title', 'العنوان', 'text', ['req' => 1]], ['subtitle', 'الوصف', 'textarea'],
            ['image', 'صورة (اختياري)', 'image'], ['style', 'الشكل', 'select', ['options' => ['light' => 'فاتح', 'dark' => 'داكن']]],
            ['btn_text', 'نص الزرار الأساسي', 'text'], ['btn_url', 'رابط الزرار الأساسي', 'url'], ['btn2_text', 'نص الزرار التاني', 'text'], ['btn2_url', 'رابط الزرار التاني', 'url'],
        ]],
        'text' => ['نص', 'type', 'عنوان وفقرات (عناوين · قوائم · روابط)', [
            ['title', 'العنوان (اختياري)', 'text'], ['body', 'المحتوى', 'richtext', ['hint' => 'تقدر تستخدم HTML بسيط: <h2> <p> <ul><li> <a> <b> — أي سكريبت بيتشال تلقائيًا.']],
            ['align', 'المحاذاة', 'select', ['options' => ['start' => 'يمين', 'center' => 'وسط']]], ['width', 'العرض', 'select', ['options' => ['narrow' => 'مقروء (ضيق)', 'wide' => 'عريض']]],
        ]],
        'image' => ['صورة', 'image', 'صورة بعرض الصفحة مع تعليق', [
            ['image', 'الصورة', 'image', ['req' => 1]], ['alt', 'وصف الصورة (Alt)', 'text'], ['caption', 'تعليق تحت الصورة', 'text'], ['link', 'رابط عند الضغط (اختياري)', 'url'],
            ['width', 'العرض', 'select', ['options' => ['wide' => 'عريض', 'narrow' => 'متوسط']]],
        ]],
        'image_text' => ['صورة + نص', 'columns', 'صورة جنب عنوان ووصف وزرار', array_merge([
            ['title', 'العنوان', 'text'], ['body', 'الوصف', 'richtext'], ['image', 'الصورة', 'image'], ['alt', 'وصف الصورة (Alt)', 'text'],
            ['side', 'مكان الصورة', 'select', ['options' => ['end' => 'شمال', 'start' => 'يمين']]],
        ], $btn)],
        'features' => ['مميزات', 'star', 'شبكة مميزات بأيقونات', [
            ['title', 'العنوان', 'text'], ['subtitle', 'الوصف', 'textarea'], ['columns', 'عدد الأعمدة', 'select', ['options' => ['3' => '3', '2' => '2', '4' => '4']]],
            ['items', 'المميزات', 'items', ['fields' => [['icon', 'الأيقونة', 'icon'], ['title', 'العنوان', 'text'], ['body', 'الوصف', 'textarea']]]],
        ]],
        'cards' => ['كروت', 'grid', 'كروت بصورة وعنوان ولينك', [
            ['title', 'العنوان', 'text'], ['subtitle', 'الوصف', 'textarea'], ['columns', 'عدد الأعمدة', 'select', ['options' => ['3' => '3', '2' => '2', '4' => '4']]],
            ['items', 'الكروت', 'items', ['fields' => [['image', 'الصورة', 'image'], ['title', 'العنوان', 'text'], ['body', 'الوصف', 'textarea'], ['link_text', 'نص اللينك', 'text'], ['link_url', 'اللينك', 'url']]]],
        ]],
        'pricing' => ['الأسعار', 'card', 'الباقات الحقيقية من المنصة (نفس قسم الرئيسية)', [
            ['title', 'العنوان (فاضي = عنوان القسم)', 'text'], ['subtitle', 'الوصف', 'textarea'],
        ]],
        'testimonials' => ['آراء العملاء', 'quote', 'آراء العملاء المنشورة', [
            ['title', 'العنوان (فاضي = عنوان القسم)', 'text'], ['subtitle', 'الوصف', 'textarea'], ['limit', 'العدد', 'number', ['min' => 1, 'max' => 24, 'default' => 6]],
            ['featured', 'المميزة بس', 'checkbox'],
        ]],
        'faq' => ['أسئلة شائعة', 'help', 'من «الأسئلة الشائعة» أو أسئلة خاصة بالصفحة', [
            ['title', 'العنوان (فاضي = عنوان القسم)', 'text'], ['subtitle', 'الوصف', 'textarea'],
            ['source', 'الأسئلة', 'select', ['options' => ['all' => 'كل الأسئلة المنشورة', 'category' => 'تصنيف معيّن', 'custom' => 'أسئلة خاصة بالصفحة دي']]],
            ['category', 'التصنيف (لو اخترت تصنيف)', 'text'],
            ['items', 'الأسئلة الخاصة', 'items', ['fields' => [['q', 'السؤال', 'text'], ['a', 'الإجابة', 'textarea']]]],
        ]],
        'cta' => ['CTA', 'rocket', 'دعوة لاتخاذ إجراء بخلفية داكنة', [
            ['title', 'العنوان', 'text', ['req' => 1]], ['body', 'النص', 'textarea'],
            ['btn_text', 'نص الزرار', 'text'], ['btn_url', 'رابط الزرار (فاضي = التسجيل)', 'url'], ['btn2_text', 'نص الزرار التاني', 'text'], ['btn2_url', 'رابط الزرار التاني', 'url'],
        ]],
        'video' => ['فيديو', 'video', 'يوتيوب أو فيميو أو ملف mp4', [
            ['title', 'العنوان (اختياري)', 'text'], ['url', 'رابط الفيديو', 'url', ['req' => 1]], ['caption', 'وصف', 'textarea'],
        ]],
        'gallery' => ['معرض صور', 'image', 'من معرض التصميمات أو صور خاصة', [
            ['title', 'العنوان', 'text'], ['subtitle', 'الوصف', 'textarea'],
            ['source', 'الصور', 'select', ['options' => ['site' => 'من «التصميمات»', 'custom' => 'صور خاصة بالصفحة دي']]],
            ['category', 'تصنيف من «التصميمات» (اختياري)', 'text'], ['limit', 'العدد', 'number', ['min' => 1, 'max' => 48, 'default' => 12]],
            ['items', 'الصور الخاصة', 'items', ['fields' => [['image', 'الصورة', 'image'], ['caption', 'تعليق', 'text']]]],
        ]],
        'videos' => ['فيديوهات الشرح', 'play', 'من «الفيديوهات» في اللوحة', [
            ['title', 'العنوان', 'text'], ['subtitle', 'الوصف', 'textarea'], ['category', 'تصنيف (اختياري)', 'text'], ['limit', 'العدد', 'number', ['min' => 1, 'max' => 48, 'default' => 24]],
        ]],
        'section' => ['قسم من الرئيسية', 'layout', 'أي قسم من أقسام الرئيسية بنفس شكله ومحتواه', [
            ['key', 'القسم', 'select', ['options' => $sections]], ['title', 'عنوان بديل (اختياري)', 'text'], ['subtitle', 'وصف بديل (اختياري)', 'textarea'],
        ]],
        'custom_html' => ['Custom HTML', 'code', 'HTML من عندك — آمن (السكريبتات ممنوعة)', [
            ['html', 'كود HTML', 'code', ['hint' => 'الوضع الآمن بيشيل السكريبتات والأحداث (onclick…) والروابط الخطيرة تلقائيًا.']],
            ['mode', 'طريقة العرض', 'select', ['options' => ['safe' => 'آمن — جوه الصفحة (بعد التنظيف)', 'frame' => 'إطار معزول — HTML و CSS كامل من غير سكريبتات']]],
            ['height', 'ارتفاع الإطار (بكسل — للإطار المعزول)', 'number', ['min' => 80, 'max' => 3000, 'default' => 480]],
        ]],
    ];
}

/** تنظيف بلوكات جاية من الفورم (أنواع وحقول معروفة بس · أطوال محدودة) */
function s_blocks_clean($raw): array
{
    $types = s_block_types();
    $in = is_array($raw) ? $raw : (json_decode((string) $raw, true) ?: []);
    $out = [];
    foreach (array_slice($in, 0, 80) as $b) {
        if (!is_array($b) || !isset($types[$b['type'] ?? ''])) continue;
        $t = $b['type'];
        $d = [];
        foreach ($types[$t][3] as $f) {
            [$k, , $ft] = $f;
            $v = $b['d'][$k] ?? null;
            if ($ft === 'items') {
                $items = [];
                foreach (array_slice(is_array($v) ? $v : [], 0, 40) as $it) {
                    if (!is_array($it)) continue;
                    $row = [];
                    foreach ($f[3]['fields'] as [$ik, , $ift]) $row[$ik] = mb_substr(trim((string) ($it[$ik] ?? '')), 0, $ift === 'textarea' ? 2000 : 700);
                    if (array_filter($row, fn($x) => $x !== '')) $items[] = $row;
                }
                $d[$k] = $items;
            } elseif ($ft === 'checkbox') {
                $d[$k] = !empty($v);
            } elseif ($ft === 'number') {
                $o = $f[3] ?? [];
                $d[$k] = $v === null || $v === '' ? (int) ($o['default'] ?? 0) : max((int) ($o['min'] ?? 0), min((int) ($o['max'] ?? 100000), (int) $v));
            } elseif ($ft === 'select') {
                $opts = $f[3]['options'] ?? [];
                $d[$k] = array_key_exists((string) $v, $opts) ? (string) $v : (string) array_key_first($opts);
            } else {
                $max = in_array($ft, ['code', 'richtext'], true) ? 200000 : ($ft === 'textarea' ? 4000 : 700);
                $d[$k] = mb_substr(trim((string) $v), 0, $max);
            }
        }
        $id = preg_replace('/[^a-z0-9]/i', '', (string) ($b['id'] ?? '')) ?: 'b' . bin2hex(random_bytes(4));
        $out[] = ['id' => substr($id, 0, 20), 'type' => $t, 'hidden' => !empty($b['hidden']), 'd' => $d];
    }
    return $out;
}

/* ═══════════════ تنظيف HTML (قائمة بيضا) ═══════════════ */

function s_sanitize_html(string $html): string
{
    if (trim($html) === '') return '';
    if (!class_exists('DOMDocument')) return nl2br(e(strip_tags($html)));

    $allowed = ['a', 'abbr', 'b', 'blockquote', 'br', 'caption', 'cite', 'code', 'col', 'colgroup', 'dd', 'del', 'details', 'div', 'dl', 'dt', 'em', 'figcaption',
        'figure', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'hr', 'i', 'img', 'ins', 'kbd', 'li', 'mark', 'ol', 'p', 'picture', 'pre', 'q', 's', 'section', 'article',
        'aside', 'header', 'footer', 'nav', 'main', 'small', 'source', 'span', 'strong', 'sub', 'summary', 'sup', 'table', 'tbody', 'td', 'tfoot', 'th', 'thead',
        'time', 'tr', 'u', 'ul', 'video', 'audio', 'iframe', 'button', 'style', 'center', 'font', 'label',
        'svg', 'path', 'circle', 'rect', 'g', 'line', 'polyline', 'polygon', 'ellipse', 'defs', 'lineargradient', 'radialgradient', 'stop', 'text', 'tspan', 'use', 'symbol', 'title'];
    // وسوم بتتشال هي ومحتواها
    $drop = ['script', 'noscript', 'object', 'embed', 'applet', 'form', 'input', 'textarea', 'select', 'option', 'meta', 'link', 'base', 'frame', 'frameset',
        'template', 'math', 'portal', 'foreignobject', 'animate', 'set', 'animatemotion', 'animatetransform', 'handler', 'listener', 'xml', 'head', 'title-x'];
    $globalAttrs = ['class', 'id', 'style', 'title', 'dir', 'lang', 'role', 'width', 'height', 'align', 'valign', 'colspan', 'rowspan', 'span', 'tabindex', 'hidden', 'open'];
    $tagAttrs = [
        'a' => ['href', 'target', 'rel', 'download', 'name'], 'img' => ['src', 'srcset', 'sizes', 'alt', 'loading', 'decoding'], 'source' => ['src', 'srcset', 'type', 'media', 'sizes'],
        'video' => ['src', 'poster', 'controls', 'autoplay', 'muted', 'loop', 'playsinline', 'preload'], 'audio' => ['src', 'controls', 'loop', 'muted', 'preload'],
        'iframe' => ['src', 'allow', 'allowfullscreen', 'loading', 'referrerpolicy', 'frameborder', 'title'], 'time' => ['datetime'], 'blockquote' => ['cite'], 'q' => ['cite'],
        'td' => ['headers', 'scope'], 'th' => ['headers', 'scope'], 'button' => ['type', 'aria-label'], 'font' => ['color', 'size', 'face'], 'ol' => ['start', 'reversed', 'type'], 'li' => ['value'],
        'svg' => ['viewbox', 'xmlns', 'fill', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin', 'preserveaspectratio', 'aria-hidden', 'focusable'],
    ];
    $svgAttrs = ['d', 'fill', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin', 'stroke-dasharray', 'stroke-dashoffset', 'stroke-opacity', 'fill-opacity', 'fill-rule',
        'clip-rule', 'opacity', 'cx', 'cy', 'r', 'rx', 'ry', 'x', 'y', 'x1', 'x2', 'y1', 'y2', 'points', 'transform', 'offset', 'stop-color', 'stop-opacity', 'gradientunits',
        'gradienttransform', 'viewbox', 'font-size', 'font-weight', 'text-anchor', 'dominant-baseline', 'href', 'xlink:href'];
    $iframeHosts = '~^https://(www\.)?(youtube\.com|youtube-nocookie\.com|player\.vimeo\.com|google\.com/maps|maps\.google\.com|www\.google\.com/maps)/~i';

    $safeUrl = function (string $u, bool $img = false): ?string {
        $u = trim(preg_replace('/[\x00-\x20]+/', ' ', html_entity_decode($u, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        $c = preg_replace('/\s+/', '', strtolower($u));
        if ($c === '') return '';
        if (preg_match('~^(javascript|vbscript|data|file|blob):~', $c)) {
            // صور data:image مسموحة (مش SVG)
            if ($img && preg_match('~^data:image/(png|jpe?g|gif|webp);base64,~', $c)) return $u;
            return null;
        }
        if (preg_match('~^[a-z][a-z0-9+.\-]*:~', $c) && !preg_match('~^(https?|mailto|tel):~', $c)) return null;
        return $u;
    };
    $safeCss = function (string $css): string {
        $c = strtolower(preg_replace('/\s+|\/\*.*?\*\//s', '', html_entity_decode($css, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        if (preg_match('/expression\(|javascript:|vbscript:|behavior:|-moz-binding|@import|url\((["\']?)\s*(javascript|data:text|vbscript)/', $c)) return '';
        return $css;
    };

    $doc = new DOMDocument('1.0', 'UTF-8');
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8"><div id="__s_root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
    libxml_clear_errors();
    $root = $doc->getElementById('__s_root');
    if (!$root) return '';

    $walk = function (DOMNode $node) use (&$walk, $allowed, $drop, $globalAttrs, $tagAttrs, $svgAttrs, $iframeHosts, $safeUrl, $safeCss) {
        for ($i = $node->childNodes->length - 1; $i >= 0; $i--) {
            $ch = $node->childNodes->item($i);
            if ($ch instanceof DOMComment || $ch instanceof DOMProcessingInstruction || $ch instanceof DOMCdataSection && $node->nodeName !== 'style') { $node->removeChild($ch); continue; }
            if (!($ch instanceof DOMElement)) continue;
            $tag = strtolower($ch->nodeName);
            if (in_array($tag, $drop, true)) { $node->removeChild($ch); continue; }
            if (!in_array($tag, $allowed, true)) {
                // وسم مش معروف: نشيله ونسيب محتواه
                $walk($ch);
                while ($ch->firstChild) $node->insertBefore($ch->firstChild, $ch);
                $node->removeChild($ch);
                continue;
            }
            if ($tag === 'style') {
                $ch->textContent = str_replace('<', '', $safeCss($ch->textContent));
                while ($ch->attributes->length) $ch->removeAttribute($ch->attributes->item(0)->nodeName);
                continue;
            }
            if ($tag === 'iframe') {
                $src = (string) $ch->getAttribute('src');
                if (!preg_match($iframeHosts, $src)) { $node->removeChild($ch); continue; }
            }
            $isSvg = in_array($tag, ['svg', 'path', 'circle', 'rect', 'g', 'line', 'polyline', 'polygon', 'ellipse', 'defs', 'lineargradient', 'radialgradient', 'stop', 'text', 'tspan', 'use', 'symbol', 'title'], true);
            $ok = array_merge($globalAttrs, $tagAttrs[$tag] ?? [], $isSvg ? $svgAttrs : []);
            for ($a = $ch->attributes->length - 1; $a >= 0; $a--) {
                $attr = $ch->attributes->item($a);
                $n = strtolower($attr->nodeName);
                $v = (string) $attr->nodeValue;
                $keep = in_array($n, $ok, true) || str_starts_with($n, 'aria-') || (str_starts_with($n, 'data-') && preg_match('/^data-[a-z0-9_\-]+$/', $n));
                if (!$keep || str_starts_with($n, 'on')) { $ch->removeAttribute($attr->nodeName); continue; }
                if (in_array($n, ['href', 'src', 'xlink:href', 'poster', 'cite'], true)) {
                    $u = $safeUrl($v, $n === 'src' && in_array($tag, ['img', 'source'], true));
                    if ($u === null) { $ch->removeAttribute($attr->nodeName); continue; }
                }
                if (in_array($n, ['srcset'], true) && preg_match('/javascript:|data:(?!image\/(png|jpe?g|gif|webp))/i', $v)) { $ch->removeAttribute($attr->nodeName); continue; }
                if ($n === 'style') {
                    $css = $safeCss($v);
                    if ($css === '') { $ch->removeAttribute('style'); continue; }
                }
            }
            if ($tag === 'a' && strtolower((string) $ch->getAttribute('target')) === '_blank') $ch->setAttribute('rel', 'noopener noreferrer');
            if ($tag === 'iframe') { $ch->setAttribute('loading', 'lazy'); $ch->setAttribute('referrerpolicy', 'strict-origin-when-cross-origin'); }
            if ($tag === 'img' && !$ch->hasAttribute('loading')) $ch->setAttribute('loading', 'lazy');
            $walk($ch);
        }
    };
    $walk($root);
    $out = '';
    foreach ($root->childNodes as $c) $out .= $doc->saveHTML($c);
    return $out;
}

/** HTML في إطار معزول (sandbox من غير سكريبتات ولا same-origin) */
function s_html_frame(string $html, int $height = 480): string
{
    $doc = '<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@400;600&family=Readex+Pro:wght@600;700&display=swap" rel="stylesheet">'
        . '<style>body{margin:0;font-family:"IBM Plex Sans Arabic",sans-serif;color:#0B1526}h1,h2,h3{font-family:"Readex Pro",sans-serif}img{max-width:100%}</style></head><body>'
        . $html . '</body></html>';
    return '<iframe class="pg-frame" sandbox="allow-popups allow-popups-to-escape-sandbox" referrerpolicy="no-referrer" loading="lazy" title="محتوى مخصص" style="height:'
        . max(80, min(3000, $height)) . 'px" srcdoc="' . htmlspecialchars($doc, ENT_QUOTES, 'UTF-8') . '"></iframe>';
}

/* ═══════════════ الرسم ═══════════════ */

/** رابط آمن للعرض (http/https/مسار/# · وإلا #) */
function s_link(string $u): string
{
    $u = trim($u);
    if ($u === '') return '';
    if (preg_match('~^(https?://|/|#|mailto:|tel:)~i', $u)) return $u;
    if (preg_match('~^[a-z][a-z0-9+.\-]*:~i', $u)) return '#';
    return s_url($u);
}

function s_block_btns(array $d, string $k1 = 'btn', string $k2 = 'btn2', bool $dark = false): string
{
    $h = '';
    if (($d[$k1 . '_text'] ?? '') !== '') $h .= '<a class="ws-btn ws-pri" href="' . e(s_link((string) ($d[$k1 . '_url'] ?? '')) ?: '#') . '">' . e($d[$k1 . '_text']) . s_icon('arrow', 18, 2.2) . '</a>';
    if (($d[$k2 . '_text'] ?? '') !== '') $h .= '<a class="ws-btn ' . ($dark ? 'ws-ghost-d' : 'ws-ghost') . '" href="' . e(s_link((string) ($d[$k2 . '_url'] ?? '')) ?: '#') . '">' . e($d[$k2 . '_text']) . '</a>';
    return $h !== '' ? '<div class="pg-acts">' . $h . '</div>' : '';
}

function s_block_head(array $d, bool $center = true): string
{
    $t = trim((string) ($d['title'] ?? ''));
    $s = trim((string) ($d['subtitle'] ?? ''));
    if ($t === '' && $s === '') return '';
    return '<div class="' . ($center ? 'pg-headc ' : '') . 'rv">' . ($t !== '' ? '<h2 class="pg-title">' . e($t) . '</h2>' : '') . ($s !== '' ? '<p class="pg-sub">' . nl2br(e($s)) . '</p>' : '') . '</div>';
}

/** فيديو → embed */
function s_video_html(string $url, string $title = ''): string
{
    $url = trim($url);
    if ($url === '') return '';
    if (preg_match('~\.(mp4|webm|ogg)(\?|$)~i', $url) && s_link($url) !== '#') {
        return '<div class="pg-video"><video src="' . e(s_link($url)) . '" controls preload="metadata" playsinline></video></div>';
    }
    $emb = s_video_embed($url);
    if (!preg_match('~^https://(www\.youtube\.com/embed/|player\.vimeo\.com/video/)~', $emb)) {
        return '<p><a class="ws-btn ws-ghost" href="' . e(s_link($url)) . '" target="_blank" rel="noopener">شاهد الفيديو</a></p>';
    }
    return '<div class="pg-video"><iframe src="' . e($emb) . '" title="' . e($title ?: 'فيديو') . '" loading="lazy" allow="accelerometer; encrypted-media; gyroscope; picture-in-picture; fullscreen" allowfullscreen></iframe></div>';
}

/** رسم البلوكات — الأقسام اللي محتاجة بيانات الرئيسية بتتحمّل مرة واحدة */
function s_render_blocks(array $blocks): string
{
    $need = [];
    foreach ($blocks as $b) {
        if (!empty($b['hidden'])) continue;
        if ($b['type'] === 'section') $need[] = $b['d']['key'] ?? '';
        if (in_array($b['type'], ['pricing', 'testimonials', 'faq'], true)) $need[] = $b['type'];
    }
    $ctx = $need ? s_home_ctx(array_values(array_unique($need))) : null;
    $out = '';
    foreach ($blocks as $b) {
        if (!empty($b['hidden'])) continue;
        $out .= s_render_block($b, $ctx);
    }
    return $out;
}

function s_render_block(array $b, ?array $ctx): string
{
    $d = $b['d'] ?? [];
    $id = 'blk-' . e($b['id'] ?? '');
    $wrap = fn(string $inner, string $cls = '') => '<section class="pg-sec ' . $cls . '" id="' . $id . '"><div class="pg-w">' . $inner . '</div></section>';
    switch ($b['type']) {
        case 'hero':
            $img = s_link((string) ($d['image'] ?? ''));
            $dark = ($d['style'] ?? '') === 'dark';
            return $wrap('<div class="pg-hero rv rv-s' . ($dark ? ' dark' : '') . ($img === '' ? ' noimg' : '') . '"><div class="t">'
                . (($d['badge'] ?? '') !== '' ? '<span class="ws-chip">' . s_icon('sparkle', 15) . e($d['badge']) . '</span>' : '')
                . '<h2>' . e($d['title'] ?? '') . '</h2>' . (($d['subtitle'] ?? '') !== '' ? '<p>' . nl2br(e($d['subtitle'])) . '</p>' : '')
                . s_block_btns($d, 'btn', 'btn2', $dark) . '</div>'
                . ($img !== '' ? '<div class="im"><img src="' . e($img) . '" alt="" loading="lazy"></div>' : '') . '</div>');
        case 'text':
            $w = ($d['width'] ?? 'narrow') === 'wide' ? '' : ' narrow';
            return '<section class="pg-sec" id="' . $id . '"><div class="pg-w' . $w . '">' . (($d['title'] ?? '') !== '' ? '<h2 class="pg-title rv' . (($d['align'] ?? '') === 'center' ? '" style="text-align:center' : '') . '">' . e($d['title']) . '</h2>' : '')
                . '<div class="prose rv' . (($d['align'] ?? '') === 'center' ? ' center' : '') . '">' . s_sanitize_html((string) ($d['body'] ?? '')) . '</div></div></section>';
        case 'image':
            $img = s_link((string) ($d['image'] ?? ''));
            if ($img === '') return '';
            $im = '<img src="' . e($img) . '" alt="' . e($d['alt'] ?? '') . '" loading="lazy">';
            if (($l = s_link((string) ($d['link'] ?? ''))) !== '') $im = '<a href="' . e($l) . '">' . $im . '</a>';
            return $wrap('<figure class="pg-img rv' . (($d['width'] ?? '') === 'narrow' ? ' narrow' : '') . '">' . $im . (($d['caption'] ?? '') !== '' ? '<figcaption>' . e($d['caption']) . '</figcaption>' : '') . '</figure>');
        case 'image_text':
            $img = s_link((string) ($d['image'] ?? ''));
            return $wrap('<div class="pg-it' . (($d['side'] ?? 'end') === 'start' ? ' rev' : '') . '"><div class="pg-it-t rv rv-r">'
                . (($d['title'] ?? '') !== '' ? '<h2>' . e($d['title']) . '</h2>' : '') . '<div class="prose">' . s_sanitize_html((string) ($d['body'] ?? '')) . '</div>' . s_block_btns($d)
                . '</div><div class="pg-it-m rv rv-l">' . ($img !== '' ? '<img src="' . e($img) . '" alt="' . e($d['alt'] ?? '') . '" loading="lazy">' : '') . '</div></div>');
        case 'features':
            $items = '';
            foreach (($d['items'] ?? []) as $i => $it) {
                $items .= '<div class="pg-card ws-lift rv" style="transition-delay:' . (($i % 4) * .08) . 's"><span class="ic">' . s_icon($it['icon'] ?? '', 24) . '</span><b>' . e($it['title'] ?? '') . '</b>'
                    . (($it['body'] ?? '') !== '' ? '<p>' . nl2br(e($it['body'])) . '</p>' : '') . '</div>';
            }
            return $wrap(s_block_head($d) . '<div class="pg-feat" style="--c:' . (int) ($d['columns'] ?? 3) . '">' . $items . '</div>');
        case 'cards':
            $items = '';
            foreach (($d['items'] ?? []) as $i => $it) {
                $im = s_link((string) ($it['image'] ?? ''));
                $lk = s_link((string) ($it['link_url'] ?? ''));
                $items .= '<div class="pg-card ws-lift rv" style="transition-delay:' . (($i % 4) * .08) . 's">' . ($im !== '' ? '<span class="cim" style="background-image:url(\'' . e($im) . '\')" role="img" aria-label="' . e($it['title'] ?? '') . '"></span>' : '')
                    . '<b>' . e($it['title'] ?? '') . '</b>' . (($it['body'] ?? '') !== '' ? '<p>' . nl2br(e($it['body'])) . '</p>' : '')
                    . ($lk !== '' ? '<a class="lk" href="' . e($lk) . '">' . e(($it['link_text'] ?? '') ?: 'اعرف أكتر') . s_icon('arrow', 15, 2) . '</a>' : '') . '</div>';
            }
            return $wrap(s_block_head($d) . '<div class="pg-feat" style="--c:' . (int) ($d['columns'] ?? 3) . '">' . $items . '</div>');
        case 'pricing':
        case 'testimonials':
            if (!$ctx) return '';
            if ($b['type'] === 'testimonials') $ctx['testimonials'] = s_testimonials((int) ($d['limit'] ?? 6), !empty($d['featured']));
            return s_render_section($b['type'], $ctx, [$d['title'] ?? '', $d['subtitle'] ?? '']);
        case 'faq':
            if (!$ctx) return '';
            $src = $d['source'] ?? 'all';
            if ($src === 'custom') {
                $ctx['faqs'] = array_map(fn($it) => ['question' => $it['q'] ?? '', 'answer' => $it['a'] ?? ''], array_filter($d['items'] ?? [], fn($it) => ($it['q'] ?? '') !== ''));
            } elseif ($src === 'category') {
                $ctx['faqs'] = s_faqs((string) ($d['category'] ?? ''));
            }
            return s_render_section('faq', $ctx, [$d['title'] ?? '', $d['subtitle'] ?? '']);
        case 'cta':
            $reg = s_setting('platform_register_url', PLATFORM_REGISTER);
            if (($d['btn_text'] ?? '') !== '' && ($d['btn_url'] ?? '') === '') $d['btn_url'] = $reg;
            return $wrap('<div class="pg-cta rv rv-s"><h2>' . e($d['title'] ?? '') . '</h2>' . (($d['body'] ?? '') !== '' ? '<p>' . nl2br(e($d['body'])) . '</p>' : '')
                . s_block_btns($d, 'btn', 'btn2', true) . '</div>');
        case 'video':
            return $wrap((($d['title'] ?? '') !== '' ? '<h2 class="pg-title rv" style="text-align:center">' . e($d['title']) . '</h2>' : '') . '<div class="rv">' . s_video_html((string) ($d['url'] ?? ''), (string) ($d['title'] ?? '')) . '</div>'
                . (($d['caption'] ?? '') !== '' ? '<p class="pg-sub" style="text-align:center;margin:16px auto 0">' . nl2br(e($d['caption'])) . '</p>' : ''));
        case 'gallery':
            $figs = '';
            if (($d['source'] ?? 'site') === 'custom') {
                foreach (($d['items'] ?? []) as $it) {
                    $im = s_link((string) ($it['image'] ?? ''));
                    if ($im === '') continue;
                    $figs .= '<figure class="rv"><img src="' . e($im) . '" alt="' . e($it['caption'] ?? '') . '" loading="lazy">' . (($it['caption'] ?? '') !== '' ? '<figcaption>' . e($it['caption']) . '</figcaption>' : '') . '</figure>';
                }
            } else {
                $cat = trim((string) ($d['category'] ?? ''));
                $lim = max(1, min(48, (int) ($d['limit'] ?? 12)));
                $rows = $cat !== '' ? s_all("SELECT * FROM site_gallery WHERE is_active = 1 AND category = ? ORDER BY sort_order, id LIMIT {$lim}", [$cat])
                                    : s_all("SELECT * FROM site_gallery WHERE is_active = 1 ORDER BY sort_order, id LIMIT {$lim}");
                foreach ($rows as $g) {
                    $im = s_img($g);
                    if ($im === '') continue;
                    $figs .= '<figure class="rv"><img src="' . e($im) . '" alt="' . e($g['title'] ?: 'تصميم من Spread AI') . '" loading="lazy">' . ($g['title'] ? '<figcaption>' . e($g['title']) . '</figcaption>' : '') . '</figure>';
                }
            }
            return $figs !== '' ? $wrap(s_block_head($d) . '<div class="pg-gal">' . $figs . '</div>') : '';
        case 'videos':
            $cat = trim((string) ($d['category'] ?? ''));
            $lim = max(1, min(48, (int) ($d['limit'] ?? 24)));
            $rows = $cat !== '' ? s_all("SELECT * FROM site_videos WHERE is_active = 1 AND category = ? ORDER BY sort_order, id LIMIT {$lim}", [$cat])
                                : s_all("SELECT * FROM site_videos WHERE is_active = 1 ORDER BY sort_order, id LIMIT {$lim}");
            if (!$rows) return $wrap(s_block_head($d) . '<p class="pg-sub" style="text-align:center;margin:0 auto">فيديوهات الشرح هتتضاف قريبًا.</p>');
            $v = '';
            foreach ($rows as $i => $r) {
                $v .= '<article class="pg-vid rv" style="transition-delay:' . (($i % 2) * .1) . 's">' . s_video_html((string) $r['video_url'], (string) $r['title'])
                    . '<div class="tx">' . ($r['category'] ? '<span class="ws-chip">' . e($r['category']) . '</span>' : '') . '<b>' . e($r['title']) . '</b>'
                    . ($r['description'] ? '<p>' . nl2br(e($r['description'])) . '</p>' : '') . '</div></article>';
            }
            return $wrap(s_block_head($d) . '<div class="pg-vids">' . $v . '</div>');
        case 'section':
            if (!$ctx) return '';
            return s_render_section((string) ($d['key'] ?? ''), $ctx, [$d['title'] ?? '', $d['subtitle'] ?? '']);
        case 'custom_html':
            $html = (string) ($d['html'] ?? '');
            if (trim($html) === '') return '';
            if (($d['mode'] ?? 'safe') === 'frame') return $wrap(s_html_frame($html, (int) ($d['height'] ?? 480)));
            return $wrap('<div class="prose pg-custom">' . s_sanitize_html($html) . '</div>');
    }
    return '';
}

/** وصف مختصر للبلوك في اللوحة */
function s_block_summary(array $b): string
{
    $d = $b['d'] ?? [];
    foreach (['title', 'key', 'url', 'caption', 'alt'] as $k) {
        if (!empty($d[$k]) && is_string($d[$k])) return mb_substr(strip_tags($d[$k]), 0, 80);
    }
    if (!empty($d['items'])) return count($d['items']) . ' عنصر';
    if (!empty($d['html'])) return mb_substr(trim(strip_tags($d['html'])), 0, 60) ?: 'HTML';
    return '';
}

/**
 * محتوى الصفحات الأساسية (احنا مين · الخدمات · التصميمات · الشرح · الأسعار · اصنع منشورك) كبلوكات —
 * بيتعرض لو الصفحة لسه ماتعدّلتش من الـ Page Builder، وبيظهر جاهز في المحرر علشان تعدّل عليه.
 */
function s_builtin_blocks(string $slug): array
{
    $sec = fn(string $key, string $title = '', string $sub = '') => ['type' => 'section', 'd' => ['key' => $key, 'title' => $title, 'subtitle' => $sub]];
    $map = [
        'about' => [$sec('about', 'ليه Spread AI؟'), $sec('steps', 'رحلتك معانا'), $sec('brands', 'بيثقوا فينا'), $sec('testimonials')],
        'services' => [$sec('services'), $sec('steps', 'إزاي بنشتغل')],
        'designs' => [$sec('gallery')],
        'tutorials' => [['type' => 'videos', 'd' => ['title' => '', 'subtitle' => '', 'category' => '', 'limit' => 24]]],
        'pricing' => [$sec('promos'), $sec('pricing'), ['type' => 'faq', 'd' => ['title' => '', 'subtitle' => '', 'source' => 'all', 'category' => '', 'items' => []]]],
        'create-post' => [$sec('trial'), ['type' => 'features', 'd' => ['title' => 'وبعدين؟', 'subtitle' => 'لما تسجّل، كل اللي عملته هنا بيتنقل معاك', 'columns' => '3', 'items' => [
            ['icon' => 'brain', 'title' => 'هويتك اتسجّلت', 'body' => 'اسم بيزنسك ومجالك وجمهورك وخدماتك — كلها اتحفظت وهتلاقيها جاهزة في حسابك.'],
            ['icon' => 'pen', 'title' => 'منشورك محفوظ', 'body' => 'المنشور اللي اتولد هنا بيتنقل لحسابك وتقدر تعدّله وتنشره.'],
            ['icon' => 'image', 'title' => 'التصميم والنشر', 'body' => 'مع الاشتراك: تصميم بألوان هويتك، واربط صفحتك وانشر تلقائيًا في معاده.'],
        ]]]],
    ];
    $out = [];
    foreach ($map[$slug] ?? [] as $i => $b) $out[] = ['id' => 'd' . $i, 'type' => $b['type'], 'hidden' => false, 'd' => $b['d']];
    return s_blocks_clean($out);
}

/** بلوكات الصفحة الفعلية (المحفوظة · أو محتوى الصفحة الأساسية · والـ HTML القديم كبلوك نص في الأول) */
function s_page_blocks(array $page): array
{
    $saved = json_decode((string) ($page['blocks_json'] ?? ''), true);
    if (is_array($saved) && $saved) return s_blocks_clean($saved);
    $blocks = [];
    if (trim((string) ($page['content_html'] ?? '')) !== '') {
        $blocks[] = ['id' => 'legacy', 'type' => 'text', 'hidden' => false, 'd' => ['title' => '', 'body' => (string) $page['content_html'], 'align' => 'start', 'width' => 'wide']];
    }
    if (!empty($page['is_builtin'])) $blocks = array_merge($blocks, s_builtin_blocks((string) $page['slug']));
    return s_blocks_clean($blocks);
}

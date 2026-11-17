<?php
/**
 * Spread AI — اللوجو والتصميمات من صفحة العميل (رحلة «ابدأ» · المرحلة 1 من تقرير الاختبار)
 *
 * بنجمع اقتراحات (مش بنحفظ حاجة لوحدنا — العميل بيختار):
 *   ① الموقع: أيقونات الموقع · صور فيها «logo» · og:image · صور الصفحة الكبيرة
 *   ② صفحة فيسبوك (لينك بس): صورة البروفايل العامة من Graph (لو متاحة من غير توكن)
 *   ③ صفحة مربوطة بميتا: صورة الصفحة + صور آخر البوستات · وحساب إنستجرام المربوط (صورته + آخر الصور)
 * صفحات فيسبوك/إنستجرام مقفولة قدام الزوار ← التصميمات منها محتاجة الربط (③).
 *
 * الأمان: الصور اللي بنعرضها بتتحفظ في الجلسة، والحفظ بيقبل بس صورة من اللي اتعرضت (مش أي لينك)،
 * والتحميل من safe_http_get (بيمنع العناوين الداخلية) وبنتأكد إنها صورة فعلًا قبل الحفظ.
 */
require_once __DIR__ . '/safe-http.php';

const PA_MAX_LOGOS = 4;
const PA_MAX_IMAGES = 12;

/** لينك نسبي ← مطلق */
function pa_abs(string $u, string $base): string
{
    $u = trim(html_entity_decode($u, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if ($u === '' || str_starts_with($u, 'data:') || str_starts_with($u, 'javascript:')) return '';
    if (str_starts_with($u, '//')) return 'https:' . $u;
    if (preg_match('#^https?://#i', $u)) return $u;
    $p = parse_url($base);
    if (empty($p['host'])) return '';
    $root = ($p['scheme'] ?? 'https') . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
    if (str_starts_with($u, '/')) return $root . $u;
    $dir = isset($p['path']) ? preg_replace('#/[^/]*$#', '/', $p['path']) : '/';
    return $root . ($dir ?: '/') . $u;
}

/**
 * من HTML الموقع: اقتراحات لوجو + صور (من غير تحميل — بس لينكات)
 * @return array{logos:string[], images:string[]}
 */
function pa_from_html(string $html, string $baseUrl): array
{
    $html = substr($html, 0, 2000000);
    $logos = [];
    $images = [];
    $attr = function (string $tag, string $name): string {
        return preg_match('/\b' . $name . '\s*=\s*(["\'])(.*?)\1/is', $tag, $m) ? $m[2] : '';
    };
    // أيقونات الموقع (الأكبر الأول: apple-touch-icon ← icon بمقاس كبير)
    if (preg_match_all('/<link\b[^>]*>/i', $html, $lm)) {
        $icons = [];
        foreach ($lm[0] as $tag) {
            $rel = strtolower($attr($tag, 'rel'));
            if (!preg_match('/\b(apple-touch-icon|icon)\b/', $rel)) continue;
            $href = pa_abs($attr($tag, 'href'), $baseUrl);
            if ($href === '' || preg_match('/\.ico(\?|$)/i', $href)) continue;   // .ico صغيرة ومش بتتقري صح
            $size = preg_match('/(\d+)x\d+/', $attr($tag, 'sizes'), $sm) ? (int) $sm[1] : (str_contains($rel, 'apple') ? 180 : 32);
            $icons[$href] = $size;
        }
        arsort($icons);
        foreach ($icons as $href => $size) if ($size >= 64) $logos[] = $href;
    }
    // <img> فيه «logo» (src · alt · class · id) ← لوجو · غير كده صورة من الصفحة
    if (preg_match_all('/<img\b[^>]*>/i', $html, $im)) {
        foreach ($im[0] as $tag) {
            $src = $attr($tag, 'src') ?: $attr($tag, 'data-src');
            if ($src === '' && ($ss = $attr($tag, 'srcset'))) $src = trim(explode(' ', trim(explode(',', $ss)[0]))[0]);
            $src = pa_abs($src, $baseUrl);
            if ($src === '' || preg_match('/\.(svg|gif|ico)(\?|$)/i', $src) || preg_match('/(pixel|spacer|tracking|sprite|loader|blank)\b/i', $src)) continue;
            $hay = strtolower($src . ' ' . $attr($tag, 'alt') . ' ' . $attr($tag, 'class') . ' ' . $attr($tag, 'id'));
            $w = (int) $attr($tag, 'width');
            $h = (int) $attr($tag, 'height');
            if (str_contains($hay, 'logo')) { $logos[] = $src; continue; }
            if (($w && $w < 200) || ($h && $h < 150)) continue;   // أيقونات وصور صغيرة
            $images[] = $src;
        }
    }
    // الصورة الرئيسية للصفحة (og:image / twitter:image)
    if (preg_match_all('/<meta\b[^>]*>/i', $html, $mm)) {
        foreach ($mm[0] as $tag) {
            $k = strtolower($attr($tag, 'property') ?: $attr($tag, 'name'));
            if (in_array($k, ['og:image', 'og:image:secure_url', 'twitter:image'], true) && ($u = pa_abs($attr($tag, 'content'), $baseUrl)) !== '') {
                array_unshift($images, $u);
            }
        }
    }
    $logos = array_values(array_slice(array_unique($logos), 0, PA_MAX_LOGOS));
    $images = array_values(array_slice(array_diff(array_unique($images), $logos), 0, PA_MAX_IMAGES));
    return ['logos' => $logos, 'images' => $images];
}

/** لينك صفحة فيسبوك ← اسم الصفحة/رقمها (لصورة البروفايل العامة) */
function pa_fb_page_ref(string $url): string
{
    $p = parse_url(preg_match('#^https?://#i', $url) ? $url : 'https://' . $url);
    if (!preg_match('/(^|\.)(facebook|fb)\.com$/i', (string) ($p['host'] ?? ''))) return '';
    if (preg_match('/(^|&)id=(\d+)/', (string) ($p['query'] ?? ''), $m)) return $m[2];
    $seg = array_values(array_filter(explode('/', (string) ($p['path'] ?? ''))));
    if (!$seg || in_array(strtolower($seg[0]), ['profile.php', 'groups', 'events', 'watch', 'share', 'sharer', 'login'], true)) return '';
    if (strtolower($seg[0]) === 'pages' && count($seg) >= 3) return preg_match('/^\d+$/', end($seg)) ? end($seg) : '';
    return preg_match('/^[A-Za-z0-9.\-_]{3,80}$/', $seg[0]) ? $seg[0] : '';
}

/**
 * اقتراحات من لينك (موقع أو صفحة فيسبوك)
 * @return array{logos:string[], images:string[], social:bool}
 */
function pa_from_url(string $url): array
{
    $out = ['logos' => [], 'images' => [], 'social' => false];
    $url = trim($url);
    if ($url === '') return $out;
    if (!preg_match('#^https?://#i', $url)) $url = 'https://' . ltrim($url, '/');
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    if (preg_match('/(^|\.)(facebook|fb|instagram|tiktok)\.com$/', $host)) {
        $out['social'] = true;
        if (($ref = pa_fb_page_ref($url)) !== '' && function_exists('graph_request')) {
            $r = graph_request('GET', rawurlencode($ref) . '/picture', ['type' => 'large', 'redirect' => 'false'], ['action' => 'page_assets_picture']);
            $u = (string) ($r['data']['data']['url'] ?? '');
            if ($u !== '' && empty($r['data']['data']['is_silhouette'])) $out['logos'][] = $u;
        }
        return $out;
    }
    $v = safe_http_validate($url);
    if (!$v['ok']) return $out;
    $g = safe_http_get($url, 2000000, 12, 3);
    if (!$g['ok'] || stripos((string) $g['content_type'], 'html') === false) return $out;
    return pa_from_html((string) $g['body'], (string) ($g['final_url'] ?: $url)) + $out;
}

/**
 * اقتراحات من الصفحات المربوطة بميتا (فيسبوك + إنستجرام): صورة الصفحة + صور آخر البوستات
 * @return array{logos:string[], images:string[], connected:bool}
 */
function pa_from_meta(int $uid): array
{
    $out = ['logos' => [], 'images' => [], 'connected' => false];
    if (!function_exists('user_connections')) require_once __DIR__ . '/social.php';
    foreach (array_slice(user_connections($uid, 'active'), 0, 3) as $c) {
        $token = social_token_decrypt((string) $c['access_token_enc']);
        if (!$token) continue;
        $out['connected'] = true;
        $ctx = ['user_id' => $uid, 'action' => 'page_assets'];
        if (!empty($c['provider_page_id'])) {
            $r = graph_request('GET', $c['provider_page_id'] . '/picture', ['type' => 'large', 'redirect' => 'false', 'access_token' => $token], $ctx);
            if (!empty($r['data']['data']['url'])) $out['logos'][] = (string) $r['data']['data']['url'];
            $r = graph_request('GET', $c['provider_page_id'] . '/posts', ['fields' => 'full_picture', 'limit' => 20, 'access_token' => $token], $ctx);
            foreach ((array) ($r['data']['data'] ?? []) as $p) if (!empty($p['full_picture'])) $out['images'][] = (string) $p['full_picture'];
        }
        if (!empty($c['ig_user_id'])) {
            $r = graph_request('GET', $c['ig_user_id'], ['fields' => 'profile_picture_url', 'access_token' => $token], $ctx);
            if (!empty($r['data']['profile_picture_url'])) $out['logos'][] = (string) $r['data']['profile_picture_url'];
            $r = graph_request('GET', $c['ig_user_id'] . '/media', ['fields' => 'media_type,media_url,thumbnail_url', 'limit' => 20, 'access_token' => $token], $ctx);
            foreach ((array) ($r['data']['data'] ?? []) as $m) {
                $u = ($m['media_type'] ?? '') === 'VIDEO' ? ($m['thumbnail_url'] ?? '') : ($m['media_url'] ?? '');
                if ($u !== '') $out['images'][] = (string) $u;
            }
        } elseif (!empty($c['page_avatar_url'])) {
            $out['logos'][] = (string) $c['page_avatar_url'];
        }
    }
    return $out;
}

/**
 * كل الاقتراحات (المربوط الأول — أدق) ← بتتحفظ في الجلسة علشان الحفظ يقبل بس منها
 * @return array{logos:string[], images:string[], connected:bool, social:bool}
 */
function page_assets(int $uid, string $url = ''): array
{
    $m = pa_from_meta($uid);
    $u = pa_from_url($url);
    $res = [
        'logos' => array_values(array_slice(array_unique(array_merge($m['logos'], $u['logos'])), 0, PA_MAX_LOGOS)),
        'images' => array_values(array_slice(array_unique(array_merge($m['images'], $u['images'])), 0, PA_MAX_IMAGES)),
        'connected' => $m['connected'], 'social' => $u['social'],
    ];
    // الـ API بيقفل الجلسة بدري (session_release) ← نفتحها لحظة نحفظ اللي اتعرض ونقفلها
    $reopen = session_status() !== PHP_SESSION_ACTIVE && !headers_sent();
    if ($reopen) @session_start();
    $_SESSION['pa_offer'] = ['uid' => $uid, 'urls' => array_merge($res['logos'], $res['images']), 'at' => time()];
    if ($reopen) session_write_close();
    return $res;
}

/** الصورة دي من اللي اتعرضوا على العميل ده؟ (من غير كده الحفظ مرفوض) */
function pa_offered(int $uid, string $url): bool
{
    $o = $_SESSION['pa_offer'] ?? null;
    return is_array($o) && (int) ($o['uid'] ?? 0) === $uid && time() - (int) ($o['at'] ?? 0) < 7200 && in_array($url, (array) ($o['urls'] ?? []), true);
}

/**
 * تحميل صورة مقترحة ← storage (بعد التأكد إنها صورة فعلًا)
 * @return string|null المسار نسبةً لـ storage
 */
function pa_store(string $url, string $folder): ?string
{
    if (safe_http_validate($url)['ok'] === false) return null;
    $g = safe_http_get($url, 6000000, 15, 3);
    return $g['ok'] ? pa_store_bytes((string) $g['body'], $folder) : null;
}

/** حفظ صورة (بعد التأكد إنها JPG/PNG/WEBP فعلًا ومش صغيرة أوي) */
function pa_store_bytes(string $bin, string $folder): ?string
{
    if ($bin === '' || strlen($bin) > 6 * 1024 * 1024 || !preg_match('/^[a-z\-]+$/', $folder)) return null;
    $info = @getimagesizefromstring($bin);
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$info['mime'] ?? ''] ?? null;
    if (!$ext || ($info[0] ?? 0) < 48 || ($info[1] ?? 0) < 48) return null;
    $dir = UPLOADS_PATH . '/' . $folder;
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $name = 'page-' . bin2hex(random_bytes(10)) . '.' . $ext;
    if (!@file_put_contents($dir . '/' . $name, $bin)) return null;
    return 'uploads/' . $folder . '/' . $name;
}

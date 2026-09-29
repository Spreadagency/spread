<?php
/**
 * Spread AI v2 — Brand Brain (المرحلة 2)
 *
 *  • نسبة صحة الهوية (أوزان لكل حقل)
 *  • حقائق البراند بمصدرها — مقترح ← العميل يطبّق أو يرفض
 *  • تحليل رابط (موقع / سوشيال) → معلومات مقترحة
 *  • ألوان اللوجو (من غير AI — حساب مباشر على الصورة)
 *
 * ⚠️ القيم الفعلية مكانها brand_profiles زي ما هي (كل المنصة بتقرا منها).
 *    brand_facts بتخزّن الاقتراحات ومصدر كل قيمة اتطبّقت.
 */

if (!function_exists('db_one')) {
    require_once __DIR__ . '/db.php';
}
require_once __DIR__ . '/safe-http.php';

/* ═══════════ الحقول ═══════════ */

function brand_fields(): array
{
    // key => [label, weight, group, question]
    return [
        'business_name'    => ['اسم النشاط',          10, 'basic',    'إيه اسم نشاطك؟'],
        'industry'         => ['المجال',               10, 'basic',    'بتشتغل في أنهي مجال؟'],
        'description'      => ['وصف النشاط',           15, 'basic',    'اوصف نشاطك في جملتين'],
        'audience'         => ['الجمهور المستهدف',     12, 'audience', 'مين عملاءك؟'],
        'services'         => ['الخدمات والمنتجات',    12, 'audience', 'إيه أهم خدماتك؟'],
        'tone'             => ['نبرة الكلام',           6, 'style',    'تحب تتكلم مع جمهورك إزاي؟'],
        'dialect'          => ['اللهجة',                3, 'style',    'بتكتب بأنهي لهجة؟'],
        'keywords_use'     => ['كلمات مميزة',           3, 'style',    'فيه كلمات بتحب تستخدمها؟'],
        'colors'           => ['ألوان البراند',         8, 'visual',   'إيه ألوان براندك؟'],
        'logo_path'        => ['اللوجو',               10, 'visual',   'ارفع اللوجو'],
        'phones'           => ['أرقام التواصل',         0, 'contact',  ''],
        'whatsapp'         => ['واتساب',                0, 'contact',  ''],
        'address'          => ['العنوان',               0, 'contact',  ''],
        'working_hours'    => ['مواعيد العمل',          0, 'contact',  ''],
        'website'          => ['الموقع',                0, 'links',    ''],
        'social_facebook'  => ['فيسبوك',                0, 'links',    ''],
        'social_instagram' => ['انستجرام',              0, 'links',    ''],
        'social_tiktok'    => ['تيك توك',               0, 'links',    ''],
        'social_linkedin'  => ['لينكدإن',               0, 'links',    ''],
    ];
}

/** حقول بتتحسب مجمّعة: أي واحد فيهم يكفي */
function brand_group_weights(): array
{
    return [
        'contact' => ['label' => 'بيانات التواصل', 'weight' => 6, 'fields' => ['phones', 'whatsapp', 'address']],
        'links'   => ['label' => 'الموقع أو السوشيال', 'weight' => 5, 'fields' => ['website', 'social_facebook', 'social_instagram', 'social_tiktok', 'social_linkedin']],
    ];
}

function brand_source_labels(): array
{
    return [
        'manual'  => ['مؤكد',        '#10A8A0'],
        'website' => ['من الموقع',   '#0C87EF'],
        'social'  => ['من السوشيال', '#0A5FCF'],
        'file'    => ['من الملف',    '#6E5BE0'],
        'logo'    => ['من اللوجو',   '#13B5C1'],
        'ai'      => ['اقتراح AI',   '#D98A1F'],
    ];
}

function brand_filled($v): bool
{
    return trim((string) $v) !== '';
}

/* ═══════════ صحة الهوية ═══════════ */

function brand_gate_pct(): int
{
    return max(0, min(100, (int) (function_exists('get_setting') ? get_setting('brand_gate_pct', 75) : 75)));
}

/**
 * @return array{pct:int, missing:array, filled:array, gate:int, unlocked:bool}
 */
function brand_health(?array $brand): array
{
    $brand = $brand ?: [];
    $pct = 0;
    $missing = [];
    $filled = [];
    foreach (brand_fields() as $k => [$label, $w]) {
        if ($w <= 0) continue;
        if (brand_filled($brand[$k] ?? '')) {
            $pct += $w;
            $filled[] = $k;
        } else {
            $missing[] = ['key' => $k, 'label' => $label, 'weight' => $w];
        }
    }
    foreach (brand_group_weights() as $g => $meta) {
        $has = false;
        foreach ($meta['fields'] as $f) {
            if (brand_filled($brand[$f] ?? '')) { $has = true; break; }
        }
        if ($has) {
            $pct += $meta['weight'];
            $filled[] = $g;
        } else {
            $missing[] = ['key' => $g, 'label' => $meta['label'], 'weight' => $meta['weight']];
        }
    }
    usort($missing, fn($a, $b) => $b['weight'] <=> $a['weight']);
    $gate = brand_gate_pct();
    $pct = min(100, $pct);
    return ['pct' => $pct, 'missing' => $missing, 'filled' => $filled, 'gate' => $gate, 'unlocked' => $gate === 0 || $pct >= $gate];
}

function brand_for_user(int $userId): ?array
{
    return db_one('SELECT * FROM brand_profiles WHERE user_id = ? ORDER BY id LIMIT 1', [$userId]) ?: null;
}

/* ═══════════ الحقائق ومصادرها ═══════════ */

/**
 * إضافة معلومة مقترحة (بتتجاهل المكرر واللي مطابق للقيمة الحالية)
 * @return int|null id الاقتراح الجديد
 */
function brand_fact_suggest(array $brand, int $userId, string $field, string $value, string $source, ?string $ref = null, int $confidence = 70): ?int
{
    if (!array_key_exists($field, brand_fields()) || $field === 'logo_path') {
        return null;
    }
    $value = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $value));
    if ($value === '' || mb_strlen($value) > 3000) {
        return null;
    }
    // نفس القيمة الموجودة؟ مفيش داعي
    if (mb_strtolower(trim((string) ($brand[$field] ?? ''))) === mb_strtolower($value)) {
        return null;
    }
    $dup = db_one('SELECT id FROM brand_facts WHERE brand_profile_id = ? AND field = ? AND status = "suggested" AND value = ?',
        [$brand['id'], $field, $value]);
    if ($dup) {
        return null;
    }
    if (!array_key_exists($source, brand_source_labels())) {
        $source = 'ai';
    }
    return db_insert(
        'INSERT INTO brand_facts (brand_profile_id, user_id, field, value, source, source_ref, confidence) VALUES (?,?,?,?,?,?,?)',
        [$brand['id'], $userId, $field, $value, $source, $ref ? mb_substr($ref, 0, 500) : null, max(0, min(100, $confidence))]
    );
}

/** تطبيق اقتراح على الهوية (مع إمكانية تعديل القيمة قبل التطبيق) */
function brand_fact_apply(int $factId, int $userId, ?string $editedValue = null): array
{
    $f = db_one('SELECT f.*, b.user_id AS owner FROM brand_facts f JOIN brand_profiles b ON b.id = f.brand_profile_id
                 WHERE f.id = ?', [$factId]);
    if (!$f || (int) $f['owner'] !== $userId) {
        return ['ok' => false, 'error' => 'المعلومة دي مش موجودة'];
    }
    if ($f['status'] !== 'suggested') {
        return ['ok' => false, 'error' => 'اتاخد فيها قرار قبل كده'];
    }
    $field = $f['field'];
    if (!array_key_exists($field, brand_fields()) || $field === 'logo_path') {
        return ['ok' => false, 'error' => 'حقل غير مسموح'];
    }
    $value = $editedValue !== null ? trim($editedValue) : (string) $f['value'];
    if ($value === '') {
        return ['ok' => false, 'error' => 'القيمة فاضية'];
    }
    // حدود أطوال الأعمدة
    $limits = ['business_name' => 255, 'industry' => 150, 'tone' => 100, 'colors' => 100, 'dialect' => 50,
               'address' => 500, 'phones' => 300, 'whatsapp' => 50, 'website' => 300, 'working_hours' => 300,
               'social_facebook' => 300, 'social_instagram' => 300, 'social_tiktok' => 300, 'social_linkedin' => 300];
    if (isset($limits[$field])) {
        $value = mb_substr($value, 0, $limits[$field]);
    }

    db_run("UPDATE brand_profiles SET `{$field}` = ?, updated_at = NOW() WHERE id = ?", [$value, $f['brand_profile_id']]);
    db_run('UPDATE brand_facts SET status = "applied", value = ?, decided_at = NOW(),
                   source = IF(? = 1, "manual", source) WHERE id = ?',
        [$value, $editedValue !== null && $editedValue !== $f['value'] ? 1 : 0, $factId]);
    // باقي الاقتراحات لنفس الحقل بقت قديمة
    db_run('UPDATE brand_facts SET status = "rejected", decided_at = NOW()
            WHERE brand_profile_id = ? AND field = ? AND status = "suggested" AND id <> ?',
        [$f['brand_profile_id'], $field, $factId]);
    return ['ok' => true, 'field' => $field, 'value' => $value];
}

function brand_fact_reject(int $factId, int $userId): bool
{
    $f = db_one('SELECT f.id FROM brand_facts f JOIN brand_profiles b ON b.id = f.brand_profile_id
                 WHERE f.id = ? AND b.user_id = ? AND f.status = "suggested"', [$factId, $userId]);
    if (!$f) return false;
    db_run('UPDATE brand_facts SET status = "rejected", decided_at = NOW() WHERE id = ?', [$factId]);
    return true;
}

/** مصدر كل حقل عنده قيمة (آخر اقتراح اتطبّق، وإلا «مؤكد» = العميل كتبه) */
function brand_field_sources(array $brand): array
{
    $src = [];
    foreach (db_all('SELECT field, source, source_ref FROM brand_facts
                     WHERE brand_profile_id = ? AND status = "applied" ORDER BY decided_at ASC, id ASC', [$brand['id']]) as $r) {
        $src[$r['field']] = ['source' => $r['source'], 'ref' => $r['source_ref']];
    }
    $out = [];
    foreach (brand_fields() as $k => $meta) {
        if (!brand_filled($brand[$k] ?? '')) continue;
        $out[$k] = $src[$k] ?? ['source' => 'manual', 'ref' => null];
    }
    return $out;
}

/** تسجيل إن العميل عدّل حقل بنفسه (المصدر يبقى «مؤكد») */
function brand_mark_manual(array $brand, int $userId, array $fields): void
{
    foreach ($fields as $f) {
        if (!array_key_exists($f, brand_fields()) || !brand_filled($brand[$f] ?? '')) continue;
        $last = db_one('SELECT source, value FROM brand_facts WHERE brand_profile_id = ? AND field = ? AND status = "applied"
                        ORDER BY decided_at DESC, id DESC LIMIT 1', [$brand['id'], $f]);
        if ($last && ($last['source'] === 'manual' || $last['value'] === (string) $brand[$f])) continue;
        db_insert('INSERT INTO brand_facts (brand_profile_id, user_id, field, value, source, status, confidence, decided_at)
                   VALUES (?,?,?,?, "manual", "applied", 100, NOW())',
            [$brand['id'], $userId, $f, mb_substr((string) $brand[$f], 0, 3000)]);
    }
}

/* ═══════════ الروابط ═══════════ */

/** نوع الرابط + الحقل المناسب في الهوية */
function brand_detect_link(string $url): array
{
    $url = trim($url);
    if ($url !== '' && !preg_match('#^https?://#i', $url)) {
        $url = 'https://' . $url;
    }
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    $host = preg_replace('/^(www\.|m\.|mobile\.|web\.)/', '', $host);
    $map = [
        'facebook.com' => ['facebook', 'فيسبوك', 'social_facebook'],  'fb.com' => ['facebook', 'فيسبوك', 'social_facebook'],
        'instagram.com' => ['instagram', 'انستجرام', 'social_instagram'],
        'tiktok.com' => ['tiktok', 'تيك توك', 'social_tiktok'],
        'linkedin.com' => ['linkedin', 'لينكدإن', 'social_linkedin'],
        'youtube.com' => ['youtube', 'يوتيوب', null], 'youtu.be' => ['youtube', 'يوتيوب', null],
        'wa.me' => ['whatsapp', 'واتساب', 'whatsapp'], 'api.whatsapp.com' => ['whatsapp', 'واتساب', 'whatsapp'],
        'x.com' => ['x', 'X', null], 'twitter.com' => ['x', 'X', null],
    ];
    foreach ($map as $d => [$type, $label, $field]) {
        if ($host === $d || str_ends_with($host, '.' . $d)) {
            return ['type' => $type, 'label' => $label, 'field' => $field, 'url' => $url, 'host' => $host];
        }
    }
    return ['type' => 'website', 'label' => 'موقع', 'field' => 'website', 'url' => $url, 'host' => $host];
}

/** رقم الواتساب من رابط wa.me */
function brand_wa_number(string $url): ?string
{
    if (preg_match('#wa\.me/(\+?\d{8,15})#', $url, $m)) return $m[1];
    if (preg_match('#phone=(\+?\d{8,15})#', $url, $m)) return $m[1];
    return null;
}

/**
 * قراءة صفحة HTML: العنوان · الوصف · og · العناوين · النص · روابط السوشيال · التليفونات
 */
function brand_parse_html(string $html, string $baseUrl): array
{
    $html = mb_substr($html, 0, 1500000);
    // الترميز
    if (!mb_check_encoding($html, 'UTF-8')) {
        $html = mb_convert_encoding($html, 'UTF-8', 'Windows-1256, ISO-8859-1, UTF-8');
    }
    $meta = function (string $name) use ($html): string {
        $p1 = '#<meta[^>]+(?:name|property)\s*=\s*["\']' . preg_quote($name, '#') . '["\'][^>]*content\s*=\s*["\']([^"\']*)["\']#i';
        $p2 = '#<meta[^>]+content\s*=\s*["\']([^"\']*)["\'][^>]*(?:name|property)\s*=\s*["\']' . preg_quote($name, '#') . '["\']#i';
        if (preg_match($p1, $html, $m) || preg_match($p2, $html, $m)) {
            return trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
        return '';
    };

    $title = preg_match('#<title[^>]*>(.*?)</title>#si', $html, $m) ? trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8')) : '';
    $out = [
        'title'       => mb_substr($title, 0, 200),
        'site_name'   => $meta('og:site_name'),
        'description' => $meta('description') ?: $meta('og:description'),
        'og_title'    => $meta('og:title'),
        'og_image'    => $meta('og:image'),
        'headings'    => [],
        'text'        => '',
        'links'       => [],
        'phones'      => [],
        'emails'      => [],
    ];

    if (preg_match_all('#<h[1-3][^>]*>(.*?)</h[1-3]>#si', $html, $hm)) {
        foreach ($hm[1] as $h) {
            $h = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($h), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
            if ($h !== '' && mb_strlen($h) < 160) $out['headings'][] = $h;
        }
        $out['headings'] = array_slice(array_values(array_unique($out['headings'])), 0, 20);
    }

    // الروابط (سوشيال · واتساب · تليفون · إيميل)
    if (preg_match_all('#href\s*=\s*["\']([^"\']+)["\']#i', $html, $lm)) {
        foreach (array_unique($lm[1]) as $href) {
            $href = html_entity_decode(trim($href), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (stripos($href, 'tel:') === 0) {
                $out['phones'][] = preg_replace('/[^\d+]/', '', substr($href, 4));
                continue;
            }
            if (stripos($href, 'mailto:') === 0) {
                $out['emails'][] = strtolower(trim(strtok(substr($href, 7), '?')));
                continue;
            }
            if (!preg_match('#^https?://#i', $href)) continue;
            $d = brand_detect_link($href);
            if ($d['type'] !== 'website') {
                // نتجاهل روابط المشاركة (sharer) وصفحات الدومين الرئيسية
                if (preg_match('#sharer|share\?|intent/|/plugins/|dialog/#i', $href)) continue;
                $path = trim((string) parse_url($href, PHP_URL_PATH), '/');
                if ($path === '' && $d['type'] !== 'whatsapp') continue;
                $out['links'][$d['type']] = $out['links'][$d['type']] ?? $href;
            }
        }
    }
    $out['phones'] = array_slice(array_values(array_unique(array_filter($out['phones'], fn($p) => strlen(preg_replace('/\D/', '', $p)) >= 8))), 0, 3);
    $out['emails'] = array_slice(array_values(array_unique(array_filter($out['emails'], fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL)))), 0, 2);

    // النص
    $body = preg_replace('#<(script|style|noscript|svg|iframe|template)[^>]*>.*?</\1>#si', ' ', $html);
    $body = preg_replace('#<br\s*/?>|</p>|</div>|</li>|</h[1-6]>|</section>#i', "\n", $body);
    $text = html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/[ \t\x{00A0}]+/u', ' ', $text);
    $text = preg_replace('/\n\s*\n+/u', "\n", $text);
    $out['text'] = trim(mb_substr($text, 0, 9000));
    return $out;
}

/* ═══════════ الاستخراج بالـ AI ═══════════ */

function brand_json_from_ai(string $raw): ?array
{
    $raw = trim(preg_replace('/^```(?:json)?|```$/m', '', $raw));
    $a = strpos($raw, '{');
    $b = strrpos($raw, '}');
    if ($a === false || $b === false || $b <= $a) return null;
    $j = json_decode(substr($raw, $a, $b - $a + 1), true);
    return is_array($j) ? $j : null;
}

/**
 * استخراج معلومات منظّمة من نص — «لا تخترع»: المش مذكور صراحة = null
 * @return array{ok:bool, facts:array, error:?string}
 */
function brand_ai_extract(string $text, int $userId, string $sourceLabel): array
{
    $text = trim(mb_substr($text, 0, 7000));
    if (mb_strlen($text) < 60) {
        return ['ok' => false, 'facts' => [], 'error' => 'النص قليل جدًا للتحليل'];
    }
    $prompt = "انت محلل هوية تجارية. اقرا النص التالي ({$sourceLabel}) واستخرج معلومات عن النشاط التجاري.\n"
        . "رجّع JSON بس — من غير أي كلام قبله أو بعده — بالمفاتيح دي بالظبط:\n"
        . '{"business_name":null,"industry":null,"description":null,"audience":null,"services":[],'
        . '"tone":null,"working_hours":null,"address":null}' . "\n\n"
        . "القواعد:\n"
        . "- ممنوع تخترع أي معلومة. أي حاجة مش مذكورة صراحة في النص رجّعها null أو مصفوفة فاضية.\n"
        . "- description: جملتين بالعربي بتوصف النشاط.\n"
        . "- audience: مين العملاء المستهدفين لو واضح من النص.\n"
        . "- services: أهم الخدمات أو المنتجات (حد أقصى 10، كل واحدة سطر قصير، ومعاها السعر لو مذكور).\n"
        . "- tone: واحدة بس من: simple, formal, fun, professional — حسب أسلوب كتابة النص.\n"
        . "- اكتب القيم بالعربي حتى لو النص إنجليزي، ما عدا الأسماء.\n\n"
        . "---\n" . $text;

    if (function_exists('ai_log_set_context')) {
        ai_log_set_context(['kind' => 'brand', 'user_id' => $userId, 'reference_type' => 'brand_profiles', 'options' => ['source' => $sourceLabel]]);
    }
    if (function_exists('smart_ai_enabled') && smart_ai_enabled()) {
        $r = smart_ai_generate($prompt, [], 'brand_analysis', [
            'user_id' => $userId, 'job_type' => 'brand_analysis', 'max_tokens' => 1200, 'temperature' => 0.2,
        ]);
    } else {
        $r = ai_generate($prompt);
    }
    if (empty($r['ok'])) {
        return ['ok' => false, 'facts' => [], 'error' => $r['error'] ?? 'تعذّر التحليل'];
    }
    $j = brand_json_from_ai((string) $r['response']);
    if (!$j) {
        return ['ok' => false, 'facts' => [], 'error' => 'الرد مكانش بالشكل المتوقع'];
    }
    $facts = [];
    foreach (['business_name', 'industry', 'description', 'audience', 'working_hours', 'address'] as $k) {
        if (!empty($j[$k]) && is_string($j[$k])) $facts[$k] = trim($j[$k]);
    }
    if (!empty($j['services']) && is_array($j['services'])) {
        $list = array_slice(array_filter(array_map(fn($s) => is_string($s) ? trim($s) : '', $j['services'])), 0, 10);
        if ($list) $facts['services'] = implode("\n", $list);
    }
    if (!empty($j['tone']) && in_array($j['tone'], ['simple', 'formal', 'fun', 'professional'], true)) {
        $facts['tone'] = $j['tone'];
    }
    return ['ok' => true, 'facts' => $facts, 'error' => null];
}

/* ═══════════ تحليل رابط ═══════════ */

/**
 * @return array{ok:bool, suggested:int, type:string, error:?string, ai:bool, title?:string}
 */
function brand_analyze_url(array $brand, int $userId, string $url): array
{
    $d = brand_detect_link($url);
    $url = $d['url'];
    $n = 0;
    $src = in_array($d['type'], ['website'], true) ? 'website' : 'social';

    // ① الرابط نفسه (مضمون — من غير أي تحليل)
    if ($d['field'] === 'whatsapp') {
        if ($num = brand_wa_number($url)) {
            if (brand_fact_suggest($brand, $userId, 'whatsapp', $num, 'social', $url, 95)) $n++;
        }
        return ['ok' => true, 'suggested' => $n, 'type' => $d['type'], 'error' => null, 'ai' => false];
    }
    if ($d['field']) {
        if (brand_fact_suggest($brand, $userId, $d['field'], $url, $src, $url, 95)) $n++;
    }

    // ② جلب الصفحة (بأمان)
    $r = safe_http_get($url, 1500000, 12);
    if (!$r['ok'] || $r['body'] === '' || ($r['content_type'] !== '' && !preg_match('#html|text/plain#', $r['content_type']))) {
        // صفحات السوشيال غالبًا بتطلب تسجيل دخول — الرابط نفسه اتسجّل، وده كفاية
        return ['ok' => $n > 0, 'suggested' => $n, 'type' => $d['type'], 'ai' => false,
                'error' => $n > 0 ? null : ($r['error'] ?: 'تعذّر قراءة الصفحة')];
    }
    $page = brand_parse_html($r['body'], $r['final_url']);

    // ③ الحاجات المضمونة من الصفحة (من غير AI)
    foreach ($page['links'] as $type => $href) {
        $ld = brand_detect_link($href);
        if ($ld['field'] === 'whatsapp') {
            if (($num = brand_wa_number($href)) && brand_fact_suggest($brand, $userId, 'whatsapp', $num, $src, $url, 90)) $n++;
        } elseif ($ld['field'] && brand_fact_suggest($brand, $userId, $ld['field'], $href, $src, $url, 90)) {
            $n++;
        }
    }
    if ($page['phones'] && brand_fact_suggest($brand, $userId, 'phones', implode(' · ', $page['phones']), $src, $url, 90)) $n++;
    $name = $page['site_name'] ?: $page['og_title'];
    if ($name !== '' && mb_strlen($name) <= 80 && !brand_filled($brand['business_name'] ?? '')) {
        if (brand_fact_suggest($brand, $userId, 'business_name', $name, $src, $url, 75)) $n++;
    }

    // ④ نحفظ النص كمصدر معرفة (بيستخدمه الـ AI في كل المحتوى بعد كده)
    $knowledge = trim(implode("\n", array_filter([
        $page['title'], $page['description'], implode(' · ', $page['headings']), $page['text'],
    ])));
    if (mb_strlen($knowledge) > 80) {
        $exists = db_one('SELECT id FROM brand_sources WHERE brand_profile_id = ? AND source_url = ?', [$brand['id'], $url]);
        if ($exists) {
            db_run('UPDATE brand_sources SET raw_text = ?, extract_status = "extracted", extracted_at = NOW() WHERE id = ?',
                [mb_substr($knowledge, 0, 60000), $exists['id']]);
        } else {
            db_insert('INSERT INTO brand_sources (brand_profile_id, user_id, type, title, source_url, raw_text, extract_status, extracted_at)
                       VALUES (?,?, "link", ?, ?, ?, "extracted", NOW())',
                [$brand['id'], $userId, mb_substr($page['title'] ?: $d['host'], 0, 200), $url, mb_substr($knowledge, 0, 60000)]);
        }
    }

    // ⑤ الاستخراج بالـ AI
    $ai = brand_ai_extract($knowledge, $userId, $d['type'] === 'website' ? 'صفحة موقع النشاط' : 'صفحة ' . $d['label']);
    if ($ai['ok']) {
        foreach ($ai['facts'] as $field => $value) {
            if (brand_fact_suggest($brand, $userId, $field, $value, $src, $url, 70)) $n++;
        }
    }
    return ['ok' => true, 'suggested' => $n, 'type' => $d['type'], 'ai' => $ai['ok'],
            'ai_error' => $ai['ok'] ? null : $ai['error'], 'error' => null, 'title' => $page['title']];
}

/* ═══════════ ألوان اللوجو (من غير AI) ═══════════ */

/**
 * أهم ألوان الصورة — بيتجاهل الشفاف والخلفيات البيضا
 * @return string[] hex
 */
function brand_logo_colors(string $absPath, int $max = 4): array
{
    if (!is_file($absPath) || !function_exists('imagecreatefromstring')) {
        return [];
    }
    $bin = @file_get_contents($absPath, false, null, 0, 8 * 1024 * 1024);
    $src = $bin ? @imagecreatefromstring($bin) : false;
    if (!$src) return [];

    $s = 64;
    $img = imagecreatetruecolor($s, $s);
    imagealphablending($img, false);
    imagesavealpha($img, true);
    imagefill($img, 0, 0, imagecolorallocatealpha($img, 0, 0, 0, 127));
    imagecopyresampled($img, $src, 0, 0, 0, 0, $s, $s, imagesx($src), imagesy($src));
    imagedestroy($src);

    $buckets = [];
    for ($x = 0; $x < $s; $x++) {
        for ($y = 0; $y < $s; $y++) {
            $c = imagecolorat($img, $x, $y);
            $a = ($c >> 24) & 0x7F;
            if ($a > 80) continue;                         // شفاف
            $r = ($c >> 16) & 0xFF; $g = ($c >> 8) & 0xFF; $b = $c & 0xFF;
            if ($r > 238 && $g > 238 && $b > 238) continue; // خلفية بيضا
            $k = (($r >> 3) << 10) | (($g >> 3) << 5) | ($b >> 3);
            if (!isset($buckets[$k])) $buckets[$k] = [0, 0, 0, 0];
            $buckets[$k][0]++; $buckets[$k][1] += $r; $buckets[$k][2] += $g; $buckets[$k][3] += $b;
        }
    }
    imagedestroy($img);
    uasort($buckets, fn($a, $b) => $b[0] <=> $a[0]);

    // الحواف المخلوطة (لون × أبيض من التنعيم) بتعمل ألوان وهمية قليلة جدًا —
    // أي لون أقل من 4% من البكسلات الملونة مش لون حقيقي في اللوجو
    $total = array_sum(array_column($buckets, 0));
    $minShare = max(3, (int) ceil($total * 0.04));

    $picked = [];
    foreach ($buckets as $bk) {
        if ($bk[0] < $minShare) break;
        $rgb = [(int) round($bk[1] / $bk[0]), (int) round($bk[2] / $bk[0]), (int) round($bk[3] / $bk[0])];
        $far = true;
        foreach ($picked as $p) {
            $dist = sqrt(($p[0] - $rgb[0]) ** 2 + ($p[1] - $rgb[1]) ** 2 + ($p[2] - $rgb[2]) ** 2);
            if ($dist < 70) { $far = false; break; }         // قريب من لون اخترناه
        }
        if ($far) $picked[] = $rgb;
        if (count($picked) >= $max) break;
    }
    return array_map(fn($p) => sprintf('#%02X%02X%02X', $p[0], $p[1], $p[2]), $picked);
}

/** تحويل صف البراند لشكل الـ API */
function brand_to_api(array $brand): array
{
    $health = brand_health($brand);
    $sources = brand_field_sources($brand);
    $labels = brand_source_labels();
    $fields = [];
    foreach (brand_fields() as $k => [$label, $w, $group, $q]) {
        $v = (string) ($brand[$k] ?? '');
        $s = $sources[$k] ?? null;
        $fields[] = [
            'key' => $k, 'label' => $label, 'group' => $group, 'weight' => $w, 'question' => $q,
            'value' => $k === 'logo_path'
                ? ($v !== '' ? (function_exists('upload_url') ? upload_url($v) : APP_URL . '/storage/' . ltrim($v, '/')) : '')
                : $v,
            'filled' => brand_filled($v),
            'source' => $s ? ['key' => $s['source'], 'label' => $labels[$s['source']][0], 'color' => $labels[$s['source']][1], 'ref' => $s['ref']] : null,
        ];
    }
    $sug = db_all('SELECT * FROM brand_facts WHERE brand_profile_id = ? AND status = "suggested" ORDER BY id DESC LIMIT 60', [$brand['id']]);
    $all = brand_fields();
    return [
        'id'          => (int) $brand['id'],
        'name'        => (string) ($brand['business_name'] ?? ''),
        'health'      => $health,
        'fields'      => $fields,
        'approved_at' => $brand['brand_approved_at'] ?? null,
        'suggestions' => array_map(fn($f) => [
            'id' => (int) $f['id'], 'field' => $f['field'], 'label' => $all[$f['field']][0] ?? $f['field'],
            'value' => $f['value'], 'current' => (string) ($brand[$f['field']] ?? ''),
            'source' => ['key' => $f['source'], 'label' => $labels[$f['source']][0], 'color' => $labels[$f['source']][1]],
            'ref' => $f['source_ref'], 'confidence' => (int) $f['confidence'],
        ], $sug),
    ];
}

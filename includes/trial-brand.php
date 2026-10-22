<?php
/**
 * Spread AI — التجربة المجانية: «عرّفنا على مشروعك» من رابط
 *
 * الزائر بيحط لينك موقعه أو صفحة الفيسبوك ← بنقرا الصفحة (بأمان) ← الـ AI بيستخرج معلومات النشاط
 * ← بتتحفظ في trial_sessions، ولما يسجّل بتتنقل لهوية البراند بتاعته (trial_claim).
 *
 * الاستكمال: الزائر لما يرجع بيلاقي نفس المرحلة اللي وصلها —
 * بالكوكي sp_trial الأول، وإلا بالـ IP (+ نفس المتصفح علشان الـ IP المشترك).
 */

require_once __DIR__ . '/brand-brain.php';

const TRIAL_SCHEMA_V = '1';
const TRIAL_COOKIE = 'sp_trial';
const TRIAL_RESUME_DAYS = 14;
const TRIAL_STAGES = ['profile', 'ideas', 'post', 'design', 'save'];

/** أعمدة جديدة في trial_sessions (بتتضاف مرة واحدة · مفيش حذف ولا تعديل لبيانات) */
function trial_ensure_schema(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        if (get_setting('trial_schema_v', '0') === TRIAL_SCHEMA_V) return;
        $have = array_column(db_all('SHOW COLUMNS FROM trial_sessions'), 'Field');
        $add = [
            'source_url'   => 'VARCHAR(500) NULL',
            'source_type'  => 'VARCHAR(20) NULL',
            'profile_json' => 'MEDIUMTEXT NULL',
            'source_text'  => 'MEDIUMTEXT NULL',
            'stage'        => "VARCHAR(20) NULL",
            'ua_hash'      => 'CHAR(40) NULL',
            'updated_at'   => 'DATETIME NULL',
        ];
        foreach ($add as $col => $def) {
            if (!in_array($col, $have, true)) db_run("ALTER TABLE trial_sessions ADD COLUMN `$col` $def");
        }
        $idx = array_column(db_all('SHOW INDEX FROM trial_sessions'), 'Key_name');
        if (!in_array('idx_trial_ip', $idx, true)) db_run('ALTER TABLE trial_sessions ADD INDEX idx_trial_ip (ip, claimed_user_id)');
        set_setting('trial_schema_v', TRIAL_SCHEMA_V);
    } catch (\Throwable $e) {
        error_log('[trial] schema: ' . $e->getMessage());
    }
}

function trial_client_ip(): string
{
    return mb_substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45); // هيدر CF-Connecting-IP بيتزوّر لو الطلب مش جاي من Cloudflare
}

/** بصمة المتصفح — بتفرّق بين أكتر من زائر على نفس الـ IP (شبكات الموبايل) */
function trial_ua_hash(): string
{
    return sha1('spread-trial|' . (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
}

function trial_set_cookie(string $token): void
{
    @setcookie(TRIAL_COOKIE, $token, [
        'expires'  => $token === '' ? time() - 3600 : time() + TRIAL_RESUME_DAYS * 86400,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/**
 * آخر تجربة مفتوحة للزائر ده: الكوكي الأول، وإلا نفس الـ IP ونفس المتصفح
 * @param int $days أقصى عمر للتجربة
 */
function trial_find_open(int $days = TRIAL_RESUME_DAYS): ?array
{
    trial_ensure_schema();
    $since = date('Y-m-d H:i:s', time() - $days * 86400);
    $cookie = preg_replace('/[^a-f0-9]/', '', (string) ($_COOKIE[TRIAL_COOKIE] ?? ''));
    try {
        if ($cookie !== '') {
            $t = db_one("SELECT * FROM trial_sessions WHERE token = ? AND claimed_user_id IS NULL
                         AND (stage IS NULL OR stage <> 'closed') AND created_at >= ?", [$cookie, $since]);
            if ($t) return $t;
        }
        $ip = trial_client_ip();
        if ($ip === '') return null;
        return db_one("SELECT * FROM trial_sessions WHERE ip = ? AND ua_hash = ? AND claimed_user_id IS NULL
                       AND (stage IS NULL OR stage <> 'closed') AND created_at >= ?
                       ORDER BY COALESCE(updated_at, created_at) DESC, id DESC LIMIT 1",
            [$ip, trial_ua_hash(), $since]) ?: null;
    } catch (\Throwable $e) {
        return null;
    }
}

/** المعلومات اللي بتظهر للزائر (من غير النص الخام) */
function trial_profile(array $t): array
{
    $p = json_decode((string) ($t['profile_json'] ?? ''), true);
    $p = is_array($p) ? $p : [];
    return [
        'business_name' => (string) ($t['business_name'] ?? ''),
        'industry'      => (string) ($t['industry'] ?? ''),
        'audience'      => (string) ($t['audience'] ?? ''),
        'services'      => (string) ($t['services'] ?? ''),
        'description'   => (string) ($p['description'] ?? ''),
        'image'         => (string) ($p['image'] ?? ''),
        'phones'        => (string) ($p['phones'] ?? ''),
        'source_url'    => (string) ($t['source_url'] ?? ''),
        'source_type'   => (string) ($t['source_type'] ?? ''),
        'source_label'  => (string) ($p['source_label'] ?? ''),
        'manual'        => !empty($p['manual']),
    ];
}

/** حالة التجربة كاملة للواجهة (للاستكمال) */
function trial_state(array $t): array
{
    $ideas = json_decode((string) ($t['ideas_json'] ?? ''), true) ?: [];
    $chosen = json_decode((string) ($t['chosen_idea'] ?? ''), true) ?: null;
    $pick = -1;
    if ($chosen) {
        foreach ($ideas as $i => $it) {
            if (($it['title'] ?? null) === ($chosen['title'] ?? '')) { $pick = $i; break; }
        }
    }
    $post = trim((string) ($t['generated_text'] ?? '')) !== ''
        ? ['content' => (string) $t['generated_text'], 'hashtags' => (string) $t['hashtags'], 'cta' => (string) $t['cta']]
        : null;

    // المرحلة: اللي اتسجّلت، بس عمرها ما تعدّي اللي البيانات تسمح بيه
    $stage = in_array($t['stage'] ?? '', TRIAL_STAGES, true) ? (string) $t['stage'] : '';
    $max = $post ? 'save' : ($ideas ? 'ideas' : 'profile');
    $order = array_flip(TRIAL_STAGES);
    if ($stage === '' || $order[$stage] > $order[$max]) $stage = $post ? 'post' : $max;

    return [
        'token'   => (string) $t['token'],
        'stage'   => $stage,
        'profile' => trial_profile($t),
        'ideas'   => array_values($ideas),
        'pick'    => $pick,
        'idea'    => $chosen,
        'post'    => $post,
    ];
}

function trial_set_stage(int $id, string $stage): void
{
    if (!in_array($stage, array_merge(TRIAL_STAGES, ['closed']), true)) return;
    try { db_run('UPDATE trial_sessions SET stage = ?, updated_at = NOW() WHERE id = ?', [$stage, $id]); } catch (\Throwable $e) {}
}

/**
 * قراءة رابط الزائر واستخراج معلومات النشاط — مابيخصمش كريدت ومابيحفظش حاجة
 * @return array{ok:bool, error:?string, code?:string, url?:string, type?:string, label?:string, profile?:array, text?:string}
 */
function trial_analyze_url(string $url): array
{
    $url = trim($url);
    if ($url === '') return ['ok' => false, 'error' => 'حط لينك موقعك أو صفحة الفيسبوك', 'code' => 'empty'];
    $d = brand_detect_link($url);
    $url = $d['url'];
    if ($d['type'] === 'whatsapp') return ['ok' => false, 'error' => 'حط لينك الموقع أو صفحة الفيسبوك (مش لينك واتساب)', 'code' => 'invalid'];
    $base = ['url' => $url, 'type' => $d['type'], 'label' => $d['label'], 'guess' => trial_name_from_url($url, $d)];
    $v = safe_http_validate($url);
    if (empty($v['ok'])) {
        // الدومين مش بيرد (DNS) ← نكمّل يدوي · أي رفض تاني (رابط داخلي/غلط) ← رسالة الخطأ بس
        if (preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/i', (string) $d['host']) && strpos((string) ($v['error'] ?? ''), 'مش موجود') !== false) {
            return $base + ['ok' => false, 'code' => 'unreadable', 'error' => 'مقدرناش نوصل للينك ده — اكتب اسم نشاطك ومجاله وهنكمّل'];
        }
        return ['ok' => false, 'error' => $v['error'] ?? 'الرابط مش صحيح', 'code' => 'invalid'];
    }
    $r = safe_http_get($url, 1500000, 12);
    if (!$r['ok'] || $r['body'] === '' || ($r['content_type'] !== '' && !preg_match('#html|text/plain#', $r['content_type']))) {
        return $base + ['ok' => false, 'code' => 'unreadable',
            'error' => $d['type'] === 'facebook'
                ? 'فيسبوك مش مخلّينا نقرا الصفحة — اكتب اسم نشاطك ومجاله وهنكمّل'
                : 'مقدرناش نقرا الصفحة — اكتب اسم نشاطك ومجاله وهنكمّل'];
    }
    $page = brand_parse_html($r['body'], $r['final_url']);
    $text = trim(implode("\n", array_filter([
        $page['site_name'], $page['og_title'], $page['title'], $page['description'], implode(' · ', $page['headings']), $page['text'],
    ])));

    // الاسم من الصفحة نفسها (مضمون) — صفحات فيسبوك: «اسم الصفحة | Facebook»
    $clean = fn(string $s) => trim(preg_replace('/\s*[|\-–·]\s*(Facebook|فيسبوك|Instagram|Home|الرئيسية).*$/iu', '', $s));
    $name = $clean($page['site_name'] ?: ($page['og_title'] ?: $page['title']));
    if (mb_strlen($name) > 80 || preg_match('/^(log ?in|facebook|تسجيل الدخول)/iu', $name)) $name = '';

    $profile = [
        'business_name' => $name,
        'industry'      => '',
        'description'   => mb_substr($page['description'], 0, 400),
        'audience'      => '',
        'services'      => '',
        'tone'          => '',
        'phones'        => implode(' · ', $page['phones']),
        'image'         => preg_match('#^https://#i', $page['og_image']) ? mb_substr($page['og_image'], 0, 500) : '',
        'links'         => $page['links'],
    ];

    if (mb_strlen($text) >= 60) {
        $ai = trial_ai_extract($text, $d['type'] === 'website' ? 'صفحة موقع النشاط' : 'صفحة ' . $d['label']);
        foreach ($ai as $k => $val) {
            if ($val !== '' && ($k !== 'business_name' || $profile['business_name'] === '' || mb_strlen($val) <= 60)) $profile[$k] = $val;
        }
    }
    if ($profile['business_name'] === '') {
        return $base + ['ok' => false, 'code' => 'unreadable', 'error' => 'مقدرناش نعرف اسم النشاط من الصفحة — اكتبه هنا وهنكمّل'];
    }
    return $base + ['ok' => true, 'error' => null, 'profile' => $profile, 'text' => mb_substr($text, 0, 60000)];
}

/** اسم مبدئي من اللينك نفسه (facebook.com/Beit.Restaurant ← Beit Restaurant) — للخانة اليدوي بس */
function trial_name_from_url(string $url, array $d): string
{
    if ($d['type'] === 'website') {
        $h = preg_replace('/\.(com|net|org|eg|co|io|store|shop|online|site)(\.[a-z]{2})?$/i', '', (string) $d['host']);
        $slug = (string) explode('.', $h)[0];
    } else {
        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');
        $slug = (string) explode('/', $path)[0];
        if (in_array(strtolower($slug), ['profile.php', 'pages', 'people', 'p', 'pg', 'groups', ''], true)) return '';
    }
    $name = trim(preg_replace('/[._\-]+/', ' ', urldecode($slug)));
    $name = preg_replace('/\s+\d{6,}$/', '', $name);
    return mb_strlen($name) >= 2 && mb_strlen($name) <= 60 ? $name : '';
}

/** استخراج بالـ AI — «لا تخترع» (نفس قواعد Brand Brain، من غير ما يتحسب على حساب حد) */
function trial_ai_extract(string $text, string $sourceLabel): array
{
    $prompt = "انت محلل هوية تجارية. اقرا النص التالي ({$sourceLabel}) واستخرج معلومات عن النشاط التجاري.\n"
        . "رجّع JSON بس — من غير أي كلام قبله أو بعده — بالمفاتيح دي بالظبط:\n"
        . '{"business_name":null,"industry":null,"description":null,"audience":null,"services":[],"tone":null}' . "\n\n"
        . "القواعد:\n"
        . "- ممنوع تخترع أي معلومة. أي حاجة مش مذكورة أو مش واضحة من النص رجّعها null أو مصفوفة فاضية.\n"
        . "- industry: المجال في كلمتين أو تلاتة (مثلاً: مطعم مشويات، عيادة أسنان، متجر ملابس أطفال).\n"
        . "- description: جملتين بالعربي بتوصف النشاط.\n"
        . "- audience: مين العملاء المستهدفين لو واضح من النص.\n"
        . "- services: أهم الخدمات أو المنتجات (حد أقصى 8، كل واحدة سطر قصير).\n"
        . "- tone: واحدة بس من: simple, formal, fun, professional.\n"
        . "- اكتب القيم بالعربي حتى لو النص إنجليزي، ما عدا الأسماء.\n\n"
        . "---\n" . mb_substr($text, 0, 7000);
    try {
        $r = ai_generate($prompt);
    } catch (\Throwable $e) {
        return [];
    }
    if (empty($r['ok'])) return [];
    $j = brand_json_from_ai((string) $r['response']);
    if (!$j) return [];
    $out = [];
    foreach (['business_name' => 120, 'industry' => 150, 'description' => 600, 'audience' => 300] as $k => $max) {
        if (!empty($j[$k]) && is_string($j[$k])) $out[$k] = mb_substr(trim($j[$k]), 0, $max);
    }
    if (!empty($j['services']) && is_array($j['services'])) {
        $list = array_slice(array_filter(array_map(fn($s) => is_string($s) ? trim($s) : '', $j['services'])), 0, 8);
        if ($list) $out['services'] = mb_substr(implode("\n", $list), 0, 1000);
    }
    $tones = ['simple' => 'بسيط وودّي', 'formal' => 'رسمي', 'fun' => 'مرح', 'professional' => 'احترافي'];
    if (!empty($j['tone']) && isset($tones[$j['tone']])) $out['tone'] = $tones[$j['tone']];
    return $out;
}

/**
 * بعد التسجيل: معلومات الرابط ← هوية البراند (الحقول الفاضية بس) + مصدر معرفة للـ AI
 * بتتنادى من trial_claim
 */
function trial_claim_profile(array $t, int $brandId, int $userId): void
{
    if (empty($t['source_url']) && empty($t['profile_json'])) return;
    try {
        $brand = db_one('SELECT * FROM brand_profiles WHERE id = ?', [$brandId]);
        if (!$brand) return;
        $p = json_decode((string) ($t['profile_json'] ?? ''), true) ?: [];
        $fill = [
            'description' => (string) ($p['description'] ?? ''),
            'phones'      => mb_substr((string) ($p['phones'] ?? ''), 0, 300),
        ];
        if (!empty($t['source_url'])) {
            $d = brand_detect_link((string) $t['source_url']);
            if ($d['field'] && $d['field'] !== 'whatsapp') $fill[$d['field']] = mb_substr($d['url'], 0, 300);
        }
        foreach ((array) ($p['links'] ?? []) as $href) {
            $ld = brand_detect_link((string) $href);
            if ($ld['field'] === 'whatsapp') {
                if ($num = brand_wa_number((string) $href)) $fill['whatsapp'] = $fill['whatsapp'] ?? $num;
            } elseif ($ld['field'] && $ld['field'] !== 'website') {
                $fill[$ld['field']] = $fill[$ld['field']] ?? mb_substr((string) $href, 0, 300);
            }
        }
        $set = [];
        $vals = [];
        foreach ($fill as $col => $val) {
            if ($val === '' || !array_key_exists($col, $brand) || trim((string) $brand[$col]) !== '') continue;
            $set[] = "`$col` = ?";
            $vals[] = $val;
        }
        if ($set) {
            $vals[] = $brandId;
            db_run('UPDATE brand_profiles SET ' . implode(', ', $set) . ' WHERE id = ?', $vals);
        }
        // النص اللي اتقرا من الصفحة ← مصدر معرفة (بيستخدمه الـ AI في المحتوى بعد كده)
        $text = trim((string) ($t['source_text'] ?? ''));
        if ($text !== '' && !empty($t['source_url'])
            && !db_one('SELECT id FROM brand_sources WHERE brand_profile_id = ? AND source_url = ?', [$brandId, $t['source_url']])) {
            db_insert('INSERT INTO brand_sources (brand_profile_id, user_id, type, title, source_url, raw_text, extract_status, extracted_at)
                       VALUES (?, ?, "link", ?, ?, ?, "extracted", NOW())',
                [$brandId, $userId, mb_substr((string) ($t['business_name'] ?: $t['source_url']), 0, 200), $t['source_url'], mb_substr($text, 0, 60000)]);
        }
    } catch (\Throwable $e) {
        error_log('[trial] claim profile: ' . $e->getMessage());
    }
}

<?php
/**
 * Spread AI v2 — جلب روابط خارجية بأمان (حماية من SSRF)
 *
 * الكود القديم (extract_link_text) كان بيجيب أي رابط يكتبه العميل،
 * يعني ممكن حد يطلب http://127.0.0.1 أو http://169.254.169.254 (بيانات
 * السيرفر السرية في الاستضافات السحابية) أو أي جهاز جوه الشبكة الداخلية.
 *
 * هنا:
 *  • http / https بس، على البورت 80 أو 443
 *  • بنحل اسم الدومين بنفسنا ونرفض أي IP داخلي أو محجوز
 *  • بنثبّت الـ IP اللي فحصناه (CURLOPT_RESOLVE) ضد DNS rebinding
 *  • التحويلات (redirects) بنتابعها بإيدينا ونفحص كل خطوة
 *  • حد أقصى للحجم والوقت
 */

/** هل الـ IP ده عام (مش داخلي ولا محجوز)؟ */
function safe_http_ip_public(string $ip): bool
{
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        return false;
    }
    // الفلتر ده بيرفض: 10/8 · 172.16/12 · 192.168/16 · 127/8 · 169.254/16 · 0/8 · ::1 · fc00::/7 · fe80::/10 ...
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        return false;
    }
    // نطاقات مش بيغطيها الفلتر في كل إصدارات PHP
    $blocked4 = ['100.64.0.0/10', '192.0.0.0/24', '198.18.0.0/15', '224.0.0.0/4', '240.0.0.0/4'];
    if (strpos($ip, ':') === false) {
        $long = ip2long($ip);
        foreach ($blocked4 as $cidr) {
            [$net, $bits] = explode('/', $cidr);
            $mask = -1 << (32 - (int) $bits);
            if (($long & $mask) === (ip2long($net) & $mask)) {
                return false;
            }
        }
    } else {
        $l = strtolower($ip);
        // IPv4 مغلّف جوه IPv6 (::ffff:127.0.0.1)
        if (strpos($l, '::ffff:') === 0) {
            return safe_http_ip_public(substr($l, 7));
        }
    }
    return true;
}

/**
 * التحقق من رابط وتحويل اسمه لـ IP آمن
 * @return array{ok:bool, error?:string, host?:string, port?:int, ip?:string, scheme?:string}
 */
function safe_http_validate(string $url): array
{
    $url = trim($url);
    if (strlen($url) > 2000) {
        return ['ok' => false, 'error' => 'الرابط طويل جدًا'];
    }
    $p = parse_url($url);
    if (!$p || empty($p['host']) || empty($p['scheme'])) {
        return ['ok' => false, 'error' => 'الرابط مش صحيح'];
    }
    $scheme = strtolower($p['scheme']);
    if (!in_array($scheme, ['http', 'https'], true)) {
        return ['ok' => false, 'error' => 'الرابط لازم يبدأ بـ http أو https'];
    }
    if (isset($p['user']) || isset($p['pass'])) {
        return ['ok' => false, 'error' => 'الرابط مش مقبول'];
    }
    $port = (int) ($p['port'] ?? ($scheme === 'https' ? 443 : 80));
    if (!in_array($port, [80, 443], true)) {
        return ['ok' => false, 'error' => 'الرابط مش مقبول'];
    }
    $host = strtolower(rtrim($p['host'], '.'));
    if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
        return ['ok' => false, 'error' => 'الرابط مش مقبول'];
    }

    // الدومين ممكن يكون IP مكتوب مباشرة
    $host = trim($host, '[]');
    $ips = [];
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        $ips = [$host];
    } else {
        $recs = @dns_get_record($host, DNS_A + DNS_AAAA) ?: [];
        foreach ($recs as $r) {
            if (!empty($r['ip'])) $ips[] = $r['ip'];
            if (!empty($r['ipv6'])) $ips[] = $r['ipv6'];
        }
        if (!$ips) {
            $v4 = @gethostbynamel($host) ?: [];
            $ips = $v4;
        }
    }
    if (!$ips) {
        return ['ok' => false, 'error' => 'الموقع ده مش موجود أو مش متاح'];
    }
    // أي IP داخلي = رفض (حتى لو فيه IPs عامة معاه)
    foreach ($ips as $ip) {
        if (!safe_http_ip_public($ip)) {
            return ['ok' => false, 'error' => 'الرابط مش مقبول'];
        }
    }
    // بنفضّل IPv4 لو موجود
    $pick = $ips[0];
    foreach ($ips as $ip) {
        if (strpos($ip, ':') === false) { $pick = $ip; break; }
    }
    return ['ok' => true, 'host' => $host, 'port' => $port, 'ip' => $pick, 'scheme' => $scheme];
}

/**
 * GET آمن
 * @return array{ok:bool, status:int, body:string, final_url:string, content_type:string, error:?string}
 */
function safe_http_get(string $url, int $maxBytes = 1500000, int $timeout = 12, int $maxRedirects = 3): array
{
    $fail = fn(string $e, int $s = 0) => ['ok' => false, 'status' => $s, 'body' => '', 'final_url' => $url, 'content_type' => '', 'error' => $e];

    if (!function_exists('curl_init')) {
        return $fail('الخدمة مش متاحة على السيرفر');
    }

    for ($hop = 0; $hop <= $maxRedirects; $hop++) {
        $v = safe_http_validate($url);
        if (!$v['ok']) {
            return $fail($v['error']);
        }

        $body = '';
        $tooBig = false;
        $headers = [];
        $ch = curl_init($url);
        $resolveHost = strpos($v['ip'], ':') !== false ? '[' . $v['ip'] . ']' : $v['ip'];
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,           // بنتابع بإيدينا عشان نفحص كل خطوة
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_RESOLVE        => [$v['host'] . ':' . $v['port'] . ':' . $resolveHost],   // ضد DNS rebinding
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_ENCODING       => '',
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; SpreadAI-BrandBrain/2.0; +https://ai.spreadagency.net)',
            CURLOPT_HTTPHEADER     => ['Accept: text/html,application/xhtml+xml;q=0.9,*/*;q=0.5', 'Accept-Language: ar,en;q=0.8'],
            CURLOPT_HEADERFUNCTION => function ($ch, $h) use (&$headers) {
                $parts = explode(':', $h, 2);
                if (count($parts) === 2) {
                    $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($h);
            },
            CURLOPT_WRITEFUNCTION  => function ($ch, $chunk) use (&$body, &$tooBig, $maxBytes) {
                if (strlen($body) + strlen($chunk) > $maxBytes) {
                    $tooBig = true;
                    $body .= substr($chunk, 0, max(0, $maxBytes - strlen($body)));
                    return 0;                           // وقف التحميل
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_errno($ch);
        $errMsg = curl_error($ch);
        curl_close($ch);

        if ($err && !$tooBig) {
            return $fail($err === CURLE_OPERATION_TIMEDOUT ? 'الموقع اتأخر في الرد' : 'تعذّر الوصول للموقع');
        }

        // تحويل
        if ($status >= 300 && $status < 400 && !empty($headers['location'])) {
            $loc = $headers['location'];
            if (!preg_match('#^https?://#i', $loc)) {
                $base = parse_url($url);
                $loc = $base['scheme'] . '://' . $base['host'] . (isset($base['port']) ? ':' . $base['port'] : '')
                     . (str_starts_with($loc, '/') ? $loc : '/' . $loc);
            }
            $url = $loc;
            continue;
        }

        return [
            'ok'           => $status >= 200 && $status < 300,
            'status'       => $status,
            'body'         => $body,
            'final_url'    => $url,
            'content_type' => strtolower($headers['content-type'] ?? ''),
            'error'        => $status >= 200 && $status < 300 ? null : ('الموقع رد بخطأ ' . $status),
            'truncated'    => $tooBig,
        ];
    }
    return $fail('تحويلات كتير من الموقع');
}

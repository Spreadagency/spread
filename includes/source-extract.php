<?php
/**
 * Spread AI v2 — Source Text Extraction (Phase 2)
 *
 * استخراج النصوص من مستندات الهوية بدون أي مكتبات خارجية (شغال على shared hosting):
 * - PDF:  pdftotext binary لو متاح → وإلا استخراج native (FlateDecode)
 * - DOCX: ZipArchive → word/document.xml
 * - TXT:  مباشرة
 * - Link: fetch + strip tags
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

/**
 * استخراج نص من مصدر وتحديث السجل. يرجع ['ok', 'chars', 'error']
 */
function source_extract(int $sourceId): array
{
    $src = db_one('SELECT * FROM brand_sources WHERE id = ?', [$sourceId]);
    if (!$src) {
        return ['ok' => false, 'chars' => 0, 'error' => 'المصدر غير موجود'];
    }

    $text = '';
    $error = null;

    try {
        switch ($src['type']) {
            case 'text':
                $text = (string) $src['raw_text']; // محفوظ أصلًا
                break;

            case 'txt':
                $path = STORAGE_PATH . '/' . $src['file_path'];
                $text = is_file($path) ? (string) file_get_contents($path) : '';
                if ($text !== '' && !mb_check_encoding($text, 'UTF-8')) {
                    $conv = @iconv('WINDOWS-1256', 'UTF-8//IGNORE', $text);
                    if ($conv !== false) $text = $conv;
                }
                break;

            case 'docx':
                $text = extract_docx_text(STORAGE_PATH . '/' . $src['file_path']);
                break;

            case 'pdf':
                $text = extract_pdf_text(STORAGE_PATH . '/' . $src['file_path']);
                break;

            case 'link':
                $text = extract_link_text((string) $src['source_url']);
                break;
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }

    $text = source_clean_text($text);

    if ($text === '' && !$error) {
        $error = 'لم يتم العثور على نص قابل للاستخراج' . ($src['type'] === 'pdf' ? ' (ممكن يكون PDF ممسوح ضوئيًا — ارفعه كنص)' : '');
    }

    db_run(
        'UPDATE brand_sources SET raw_text = ?, extract_status = ?, extract_error = ?, extracted_at = NOW() WHERE id = ?',
        [
            $text !== '' ? mb_substr($text, 0, 200000) : $src['raw_text'],
            $text !== '' ? 'extracted' : 'failed',
            $error ? mb_substr($error, 0, 250) : null,
            $sourceId,
        ]
    );

    return ['ok' => $text !== '', 'chars' => mb_strlen($text), 'error' => $error];
}

/**
 * DOCX → نص (ZipArchive مدمج في PHP)
 */
function extract_docx_text(string $path): string
{
    if (!is_file($path) || !class_exists('ZipArchive')) {
        return '';
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        return '';
    }
    $xml = $zip->getFromName('word/document.xml');
    $zip->close();
    if (!$xml) {
        return '';
    }
    // فواصل الفقرات والأسطر
    $xml = str_replace(['</w:p>', '<w:br/>', '<w:tab/>'], ["\n", "\n", ' '], $xml);
    $text = strip_tags($xml);
    return html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

/**
 * PDF → نص: pdftotext أولًا، وإلا native fallback
 */
function extract_pdf_text(string $path): string
{
    if (!is_file($path)) {
        return '';
    }

    // 1) pdftotext binary (أدق طريقة لو متاح على السيرفر)
    if (function_exists('shell_exec')) {
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        if (!in_array('shell_exec', $disabled, true)) {
            $out = @shell_exec('pdftotext -enc UTF-8 ' . escapeshellarg($path) . ' - 2>/dev/null');
            if (is_string($out) && trim($out) !== '') {
                return $out;
            }
        }
    }

    // 2) Native fallback: فك ضغط FlateDecode streams واستخراج نصوص Tj/TJ
    $raw = (string) file_get_contents($path);
    if ($raw === '') {
        return '';
    }

    $texts = [];
    if (preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $raw, $streams)) {
        foreach ($streams[1] as $stream) {
            $data = @gzuncompress($stream);
            if ($data === false) {
                $data = @gzinflate($stream);
            }
            if ($data === false) {
                $data = $stream; // stream غير مضغوط
            }
            // BT ... ET text blocks → (…) Tj أو [ (…) ] TJ
            if (preg_match_all('/\((?:\\\\.|[^()\\\\])*\)\s*Tj|\[(?:[^\[\]]|\\\\.)*\]\s*TJ/s', $data, $ops)) {
                foreach ($ops[0] as $op) {
                    if (preg_match_all('/\(((?:\\\\.|[^()\\\\])*)\)/s', $op, $strs)) {
                        foreach ($strs[1] as $s) {
                            $s = str_replace(['\\(', '\\)', '\\\\'], ['(', ')', '\\'], $s);
                            $s = preg_replace_callback('/\\\\(\d{1,3})/', fn ($m) => chr((int) octdec($m[1])), $s);
                            $texts[] = $s;
                        }
                    }
                }
                $texts[] = "\n";
            }
        }
    }

    $text = implode(' ', $texts);
    // UTF-16 PDFs
    if (strpos($text, "\x00") !== false) {
        $conv = @iconv('UTF-16BE', 'UTF-8//IGNORE', $text);
        if ($conv !== false) $text = $conv;
    }
    return $text;
}

/**
 * رابط → نص الصفحة
 */
function extract_link_text(string $url): string
{
    // ⚠️ كان بيجيب أي رابط من غير فحص (ثغرة SSRF) — دلوقتي عن طريق الجالب الآمن
    require_once __DIR__ . '/safe-http.php';
    $r = safe_http_get($url, 1500000, 15);
    if (!$r['ok'] || $r['body'] === '') {
        return '';
    }
    if ($r['content_type'] !== '' && !preg_match('#text/|html|xml#', $r['content_type'])) {
        return '';   // مش صفحة نصية (صورة · فيديو · ملف)
    }
    $html = $r['body'];
    // شيل script/style ثم tags
    $html = preg_replace('#<(script|style|noscript|nav|footer|header)[^>]*>.*?</\1>#si', ' ', $html);
    $html = preg_replace('#<br\s*/?>|</p>|</div>|</li>|</h[1-6]>#i', "\n", $html);
    $text = strip_tags($html);
    return html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/**
 * تنظيف النص المستخرج
 */
function source_clean_text(string $text): string
{
    $text = preg_replace('/[ \t]+/u', ' ', $text);
    $text = preg_replace('/\n{3,}/u', "\n\n", $text);
    // شيل الرموز الغريبة الناتجة عن استخراج PDF
    $text = preg_replace('/[^\P{C}\n\t]+/u', '', $text) ?? $text;
    return trim($text);
}

/**
 * تلخيص مصدر بالـ AI (اختياري — بيوفر tokens في كل توليد بعد كده)
 * يستخدم smart_ai_generate لو مفعّل، وإلا ai_generate القديمة.
 */
function source_summarize(int $sourceId, ?int $userId = null): array
{
    $src = db_one('SELECT * FROM brand_sources WHERE id = ?', [$sourceId]);
    if (!$src || empty($src['raw_text'])) {
        return ['ok' => false, 'error' => 'لا يوجد نص مستخرج للتلخيص — استخرج النص أولًا'];
    }

    $chunk = mb_substr($src['raw_text'], 0, 12000);
    $prompt = "لخّص المستند التالي في نقاط مركزة (200-350 كلمة كحد أقصى) تركز على: معلومات البيزنس، الخدمات، نقاط القوة، الجمهور، الأسلوب، وأي أرقام أو حقائق مهمة. الملخص سيُستخدم كمرجع لكتابة محتوى تسويقي.\n\n---\n" . $chunk;

    if (function_exists('smart_ai_enabled') && smart_ai_enabled()) {
        $r = smart_ai_generate($prompt, [], 'source_summary', [
            'user_id' => $userId, 'reference_type' => 'brand_source', 'reference_id' => $sourceId,
            'job_type' => 'source_summary', 'max_tokens' => 800, 'temperature' => 0.3,
        ]);
    } else {
        $r = ai_generate($prompt);
    }

    if (!$r['ok']) {
        return ['ok' => false, 'error' => $r['error']];
    }

    db_run(
        'UPDATE brand_sources SET summary = ?, extract_status = "summarized" WHERE id = ?',
        [mb_substr(trim($r['response']), 0, 4000), $sourceId]
    );

    return ['ok' => true, 'summary' => $r['response'], 'error' => null];
}

/**
 * قسم المعرفة اللي بيدخل في البرومبت:
 * الملخص لو موجود، وإلا مقتطف من النص الخام — بحد أقصى إجمالي knowledge_max_chars
 */
function get_brand_knowledge(int $brandProfileId): string
{
    try {
        $sources = db_all(
            'SELECT title, type, summary, raw_text FROM brand_sources
             WHERE brand_profile_id = ? AND use_in_prompts = 1
               AND (summary IS NOT NULL OR raw_text IS NOT NULL)
             ORDER BY (summary IS NOT NULL) DESC, created_at DESC LIMIT 10',
            [$brandProfileId]
        );
    } catch (Throwable $e) {
        return ''; // الجدول لسه متعملش
    }

    if (!$sources) {
        return '';
    }

    $maxTotal = function_exists('get_setting') ? (int) get_setting('knowledge_max_chars', 4000) : 4000;
    $out = [];
    $used = 0;

    foreach ($sources as $s) {
        $body = trim((string) ($s['summary'] ?: $s['raw_text']));
        if ($body === '') {
            continue;
        }
        $remaining = $maxTotal - $used;
        if ($remaining < 200) {
            break;
        }
        $body = mb_substr($body, 0, min(mb_strlen($body), $remaining, $s['summary'] ? 2000 : 1200));
        $title = trim((string) $s['title']) ?: 'مستند';
        $out[] = "• {$title}:\n{$body}";
        $used += mb_strlen($body);
    }

    return $out ? implode("\n\n", $out) : '';
}

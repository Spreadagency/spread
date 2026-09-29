<?php
/**
 * Spread AI v2 — كاتب ملفات Excel (.xlsx) بدون أي مكتبات خارجية
 *
 * بيستخدم ZipArchive لو متاح، وإلا بيبني الـ ZIP يدويًا (store بدون ضغط)
 * — فبيشتغل على أي استضافة.
 */

/**
 * تحويل رقم العمود لحرف: 1→A · 27→AA
 */
function xlsx_col(int $n): string
{
    $s = '';
    while ($n > 0) {
        $m = ($n - 1) % 26;
        $s = chr(65 + $m) . $s;
        $n = (int) (($n - $m) / 26);
    }
    return $s;
}

function xlsx_esc(string $v): string
{
    // إزالة المحارف اللي بتكسّر XML
    $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $v);
    return htmlspecialchars($v, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

/**
 * بناء ZIP يدويًا بطريقة store (بدون ضغط) — بديل ZipArchive
 * @param array<string,string> $files المسار => المحتوى
 */
function xlsx_zip_raw(array $files): string
{
    $out = '';
    $central = '';
    $offset = 0;

    foreach ($files as $name => $content) {
        $crc = crc32($content);
        $len = strlen($content);
        $nameLen = strlen($name);

        // Local file header
        $local = "\x50\x4b\x03\x04"
            . pack('v', 20)          // version needed
            . pack('v', 0)           // flags
            . pack('v', 0)           // method: store
            . pack('v', 0)           // mod time
            . pack('v', 0)           // mod date
            . pack('V', $crc)
            . pack('V', $len)        // compressed size
            . pack('V', $len)        // uncompressed size
            . pack('v', $nameLen)
            . pack('v', 0)           // extra len
            . $name;

        $out .= $local . $content;

        // Central directory entry
        $central .= "\x50\x4b\x01\x02"
            . pack('v', 20) . pack('v', 20)
            . pack('v', 0) . pack('v', 0)
            . pack('v', 0) . pack('v', 0)
            . pack('V', $crc)
            . pack('V', $len) . pack('V', $len)
            . pack('v', $nameLen)
            . pack('v', 0) . pack('v', 0)
            . pack('v', 0) . pack('v', 0)
            . pack('V', 32)          // external attrs
            . pack('V', $offset)
            . $name;

        $offset += strlen($local) + $len;
    }

    $out .= $central;
    $out .= "\x50\x4b\x05\x06"
        . pack('v', 0) . pack('v', 0)
        . pack('v', count($files)) . pack('v', count($files))
        . pack('V', strlen($central))
        . pack('V', $offset)
        . pack('v', 0);

    return $out;
}

/**
 * إنشاء ملف Excel
 *
 * @param array $headers  عناوين الأعمدة
 * @param array $rows     الصفوف (كل صف مصفوفة قيم)
 * @param array $opts     ['sheet'=>'اسم الورقة', 'widths'=>[20,30,...], 'numeric'=>[3] أرقام الأعمدة الرقمية (1-based)]
 * @return string محتوى الملف الثنائي
 */
function xlsx_build(array $headers, array $rows, array $opts = []): string
{
    $sheetName = xlsx_esc(mb_substr((string) ($opts['sheet'] ?? 'البيانات'), 0, 30));
    $widths    = $opts['widths'] ?? [];
    $numeric   = array_flip($opts['numeric'] ?? []);

    // ─── أعمدة العرض ───
    $colsXml = '';
    if ($widths) {
        $colsXml = '<cols>';
        foreach ($widths as $i => $w) {
            $colsXml .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . (float) $w . '" customWidth="1"/>';
        }
        $colsXml .= '</cols>';
    }

    // ─── الصفوف ───
    $rowsXml = '';

    // صف العناوين (style 1 = عريض بخلفية)
    $rowsXml .= '<row r="1" ht="22" customHeight="1">';
    foreach (array_values($headers) as $i => $h) {
        $ref = xlsx_col($i + 1) . '1';
        $rowsXml .= '<c r="' . $ref . '" s="1" t="inlineStr"><is><t>' . xlsx_esc((string) $h) . '</t></is></c>';
    }
    $rowsXml .= '</row>';

    $r = 2;
    foreach ($rows as $row) {
        $rowsXml .= '<row r="' . $r . '">';
        foreach (array_values($row) as $i => $val) {
            $ref = xlsx_col($i + 1) . $r;
            $isNum = isset($numeric[$i + 1]) && is_numeric($val);
            if ($isNum) {
                $rowsXml .= '<c r="' . $ref . '" s="2"><v>' . (0 + $val) . '</v></c>';
            } else {
                $v = (string) ($val ?? '');
                if ($v === '') {
                    $rowsXml .= '<c r="' . $ref . '" s="2"/>';
                } else {
                    $rowsXml .= '<c r="' . $ref . '" s="2" t="inlineStr"><is><t xml:space="preserve">'
                        . xlsx_esc($v) . '</t></is></c>';
                }
            }
        }
        $rowsXml .= '</row>';
        $r++;
    }

    $lastCol = xlsx_col(max(1, count($headers)));

    // ─── الورقة (rightToLeft للعربي) ───
    $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<sheetViews><sheetView rightToLeft="1" tabSelected="1" workbookViewId="0">'
        . '<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>'
        . '</sheetView></sheetViews>'
        . '<sheetFormatPr defaultRowHeight="18"/>'
        . $colsXml
        . '<sheetData>' . $rowsXml . '</sheetData>'
        . '<autoFilter ref="A1:' . $lastCol . '1"/>'
        . '</worksheet>';

    // ─── الأنماط ───
    $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="3">'
        . '<font><sz val="11"/><name val="Calibri"/></font>'
        . '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
        . '<font><sz val="11"/><name val="Calibri"/></font>'
        . '</fonts>'
        . '<fills count="3">'
        . '<fill><patternFill patternType="none"/></fill>'
        . '<fill><patternFill patternType="gray125"/></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FF0F3CC9"/><bgColor indexed="64"/></patternFill></fill>'
        . '</fills>'
        . '<borders count="2">'
        . '<border><left/><right/><top/><bottom/><diagonal/></border>'
        . '<border><left style="thin"><color rgb="FFD9D9D9"/></left><right style="thin"><color rgb="FFD9D9D9"/></right>'
        . '<top style="thin"><color rgb="FFD9D9D9"/></top><bottom style="thin"><color rgb="FFD9D9D9"/></bottom><diagonal/></border>'
        . '</borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="3">'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        . '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
        . '<xf numFmtId="0" fontId="2" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment vertical="center"/></xf>'
        . '</cellXfs>'
        . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
        . '</styleSheet>';

    $files = [
        '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>',

        '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>',

        'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="' . $sheetName . '" sheetId="1" r:id="rId1"/></sheets></workbook>',

        'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>',

        'xl/styles.xml' => $styles,
        'xl/worksheets/sheet1.xml' => $sheet,
    ];

    // ZipArchive لو متاح (أخف على الذاكرة)، وإلا البناء اليدوي
    if (class_exists('ZipArchive')) {
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::OVERWRITE) === true) {
            foreach ($files as $name => $content) {
                $zip->addFromString($name, $content);
            }
            $zip->close();
            $data = (string) file_get_contents($tmp);
            @unlink($tmp);
            return $data;
        }
        @unlink($tmp);
    }

    return xlsx_zip_raw($files);
}

/** إرسال الملف للتحميل */
function xlsx_download(string $filename, array $headers, array $rows, array $opts = []): void
{
    $data = xlsx_build($headers, $rows, $opts);
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '.xlsx"; filename*=UTF-8\'\'' . rawurlencode($filename) . '.xlsx');
    header('Content-Length: ' . strlen($data));
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    echo $data;
    exit;
}

/** بديل CSV (بـ BOM عشان العربي يفتح صح في إكسل) */
function csv_download(string $filename, array $headers, array $rows): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, $headers);
    foreach ($rows as $r) {
        fputcsv($out, array_values($r));
    }
    fclose($out);
    exit;
}

<?php
declare(strict_types=1);

/**
 * V5.24 - Nationlink Settlement Report import (DTQR "QR TRANSACTIONS REPORT").
 *
 * Reads the daily settlement report Nationlink sends (PDF, Excel .xlsx or CSV),
 * matches every line to the client's Nationlink Static QR Ph (MemberID, e.g. A10103)
 * and records the payments that the webhook missed as "Cash In" transactions, so they
 * show in the client's dashboard / ledger / settlement. Lines already recorded (same
 * Nationlink trace / reference) are only reconciled, never recorded twice.
 *
 * Pure PHP (no Composer): zlib for PDF streams, ZipArchive + SimpleXML for .xlsx.
 */

const SB_STL_CODE_RE = '/^[A-Z][0-9]{5}$/';
// Amounts: "1,100.00" in the PDF, but Excel cells may come back as plain 20 / 1100.5.
const SB_STL_MONEY_RE = '\(?-?[0-9][0-9,]*(?:\.[0-9]+)?\)?';

// =====================================================================
// 1. File -> rows (array of string cells)
// =====================================================================

/** Detects the format and returns [rows, format]. Throws RuntimeException on unreadable files. */
function sb_stl_read_file(string $path, string $originalName): array
{
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $head = (string) file_get_contents($path, false, null, 0, 8);
    if ($ext === 'pdf' && !str_contains((string) file_get_contents($path, false, null, 0, 1024), '%PDF')) {
        throw new RuntimeException('This is not a valid PDF file. Download the report again from Nationlink, or upload the Excel/CSV version.');
    }
    if (str_starts_with($head, '%PDF') || str_contains((string) file_get_contents($path, false, null, 0, 1024), '%PDF-')) {
        return [sb_pdf_rows((string) file_get_contents($path)), 'pdf'];
    }
    if (str_starts_with($head, "PK\x03\x04")) {
        return [sb_xlsx_rows($path), 'xlsx'];
    }
    if (str_starts_with($head, "\xD0\xCF\x11\xE0")) {
        throw new RuntimeException('Old Excel 97-2003 (.xls) files are not supported. Open it in Excel and "Save As" Excel Workbook (.xlsx) or CSV, then upload again.');
    }
    $text = (string) file_get_contents($path);
    $trim = ltrim(preg_replace('/^\xEF\xBB\xBF/', '', $text) ?? $text);
    if ($trim !== '' && $trim[0] === '<') {
        // "Excel" exports from web reports are often an HTML table or SpreadsheetML saved as .xls.
        return [sb_markup_table_rows($trim), 'excel-html'];
    }
    if (in_array($ext, ['csv', 'txt', 'xls', ''], true) || $ext === 'tsv') {
        return [sb_csv_rows($text), 'csv'];
    }
    throw new RuntimeException('Unsupported file type. Upload the Nationlink report as PDF, Excel (.xlsx) or CSV.');
}

function sb_csv_rows(string $text): array
{
    $text = preg_replace('/^\xEF\xBB\xBF/', '', $text) ?? $text;
    if (!mb_check_encoding($text, 'UTF-8')) {
        $text = mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
    }
    $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];
    // Delimiter: the one that appears most in the first non-empty lines.
    $sample = implode("\n", array_slice(array_filter($lines, fn($l) => trim($l) !== ''), 0, 15));
    $delim = ',';
    $best = 0;
    foreach ([',', ';', "\t", '|'] as $d) {
        $c = substr_count($sample, $d);
        if ($c > $best) {
            $best = $c;
            $delim = $d;
        }
    }
    $rows = [];
    $fh = fopen('php://temp', 'r+');
    fwrite($fh, $text);
    rewind($fh);
    while (($r = fgetcsv($fh, 0, $delim, '"', '')) !== false) {
        if ($r === [null]) {
            continue;
        }
        $rows[] = array_map(fn($v) => trim((string) $v), $r);
    }
    fclose($fh);
    return $rows;
}

/** HTML <table> or SpreadsheetML (<Row><Cell><Data>) -> rows. */
function sb_markup_table_rows(string $markup): array
{
    $rows = [];
    $prev = libxml_use_internal_errors(true);
    if (stripos($markup, '<Workbook') !== false && ($xml = simplexml_load_string($markup)) !== false) {
        $xml->registerXPathNamespace('ss', 'urn:schemas-microsoft-com:office:spreadsheet');
        foreach ($xml->xpath('//ss:Worksheet[1]//ss:Row') ?: [] as $row) {
            $cells = [];
            foreach ($row->children('urn:schemas-microsoft-com:office:spreadsheet') as $cell) {
                $attrs = $cell->attributes('urn:schemas-microsoft-com:office:spreadsheet');
                if (isset($attrs['Index'])) {
                    while (count($cells) < (int) $attrs['Index'] - 1) {
                        $cells[] = '';
                    }
                }
                $cells[] = trim((string) $cell->children('urn:schemas-microsoft-com:office:spreadsheet')->Data);
            }
            $rows[] = $cells;
        }
    } else {
        $dom = new DOMDocument();
        $dom->loadHTML('<?xml encoding="UTF-8">' . $markup);
        foreach ($dom->getElementsByTagName('tr') as $tr) {
            $cells = [];
            foreach ($tr->childNodes as $td) {
                if ($td instanceof DOMElement && in_array(strtolower($td->tagName), ['td', 'th'], true)) {
                    $cells[] = trim(preg_replace('/\s+/u', ' ', $td->textContent) ?? '');
                    for ($i = 1, $n = (int) $td->getAttribute('colspan'); $i < $n; $i++) {
                        $cells[] = '';
                    }
                }
            }
            $rows[] = $cells;
        }
    }
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    return $rows;
}

/** First worksheet of an .xlsx -> rows. Date-formatted cells come back as "m/d/Y h:i:s A". */
function sb_xlsx_rows(string $path): array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('Excel import needs the PHP zip extension. Save the report as CSV and upload that instead.');
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('Could not open the Excel file.');
    }
    $read = function (string $name) use ($zip): ?SimpleXMLElement {
        $x = $zip->getFromName($name);
        if ($x === false) {
            return null;
        }
        $prev = libxml_use_internal_errors(true);
        $el = simplexml_load_string($x);
        libxml_use_internal_errors($prev);
        return $el === false ? null : $el;
    };

    // First sheet path from the workbook relationships.
    $sheetPath = 'xl/worksheets/sheet1.xml';
    $wb = $read('xl/workbook.xml');
    $rels = $read('xl/_rels/workbook.xml.rels');
    if ($wb && $rels && isset($wb->sheets->sheet[0])) {
        $rid = (string) $wb->sheets->sheet[0]->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
        foreach ($rels->Relationship as $rel) {
            if ((string) $rel['Id'] === $rid) {
                $t = ltrim((string) $rel['Target'], '/');
                $sheetPath = str_starts_with($t, 'xl/') ? $t : 'xl/' . $t;
            }
        }
    }

    $shared = [];
    if ($ss = $read('xl/sharedStrings.xml')) {
        foreach ($ss->si as $si) {
            if (isset($si->t)) {
                $shared[] = (string) $si->t;
            } else {
                $s = '';
                foreach ($si->r as $r) {
                    $s .= (string) $r->t;
                }
                $shared[] = $s;
            }
        }
    }

    // Which style indexes are dates.
    $dateStyles = [];
    if ($st = $read('xl/styles.xml')) {
        $customDate = [];
        if (isset($st->numFmts)) {
            foreach ($st->numFmts->numFmt as $f) {
                $code = strtolower(preg_replace('/"[^"]*"|\[[^\]]*\]/', '', (string) $f['formatCode']) ?? '');
                if (preg_match('/[dmyhs]/', $code)) {
                    $customDate[(int) $f['numFmtId']] = true;
                }
            }
        }
        $i = 0;
        foreach ($st->cellXfs->xf ?? [] as $xf) {
            $id = (int) $xf['numFmtId'];
            if (($id >= 14 && $id <= 22) || ($id >= 45 && $id <= 47) || isset($customDate[$id])) {
                $dateStyles[$i] = true;
            }
            $i++;
        }
    }

    $sheet = $read($sheetPath);
    $zip->close();
    if (!$sheet) {
        throw new RuntimeException('The Excel file has no readable worksheet.');
    }
    $rows = [];
    foreach ($sheet->sheetData->row as $row) {
        $cells = [];
        foreach ($row->c as $c) {
            $ref = (string) $c['r'];
            if ($ref !== '' && preg_match('/^([A-Z]+)/', $ref, $m)) {
                $col = 0;
                foreach (str_split($m[1]) as $ch) {
                    $col = $col * 26 + (ord($ch) - 64);
                }
                while (count($cells) < $col - 1) {
                    $cells[] = '';
                }
            }
            $type = (string) $c['t'];
            if ($type === 's') {
                $v = $shared[(int) $c->v] ?? '';
            } elseif ($type === 'inlineStr') {
                $v = (string) ($c->is->t ?? '');
                if ($v === '' && isset($c->is->r)) {
                    foreach ($c->is->r as $r) {
                        $v .= (string) $r->t;
                    }
                }
            } else {
                $v = (string) $c->v;
                if ($v !== '' && is_numeric($v) && isset($dateStyles[(int) $c['s']]) && (float) $v > 20000 && (float) $v < 80000) {
                    $v = sb_excel_serial_to_string((float) $v);
                }
            }
            $cells[] = trim($v);
        }
        $rows[] = $cells;
    }
    return $rows;
}

function sb_excel_serial_to_string(float $serial): string
{
    $secs = (int) round(($serial - 25569) * 86400);
    return gmdate('m/d/Y h:i:s A', $secs);
}

// =====================================================================
// 2. Minimal PDF text extractor (positions -> lines -> cells)
// =====================================================================

/** Extracts every page of a PDF as rows of cells, top to bottom, left to right. */
function sb_pdf_rows(string $pdf): array
{
    $doc = new SbPdfDoc($pdf);
    $rows = [];
    foreach ($doc->pages() as $page) {
        foreach (sb_pdf_group_lines($doc->pageFragments($page)) as $cells) {
            $rows[] = $cells;
        }
    }
    if (!$rows) {
        throw new RuntimeException('No text could be read from the PDF (scanned image?). Ask Nationlink for the Excel/CSV version of the report.');
    }
    return $rows;
}

/** @param array<int,array{x:float,y:float,w:float,size:float,text:string}> $frags */
function sb_pdf_group_lines(array $frags): array
{
    $frags = array_values(array_filter($frags, fn($f) => trim($f['text']) !== ''));
    usort($frags, fn($a, $b) => [-$a['y'], $a['x']] <=> [-$b['y'], $b['x']]);
    $lines = [];
    foreach ($frags as $f) {
        $tol = max(1.0, $f['size'] * 0.45);
        $placed = false;
        foreach ($lines as &$ln) {
            if (abs($ln['y'] - $f['y']) <= $tol) {
                $ln['items'][] = $f;
                $placed = true;
                break;
            }
        }
        unset($ln);
        if (!$placed) {
            $lines[] = ['y' => $f['y'], 'items' => [$f]];
        }
    }
    usort($lines, fn($a, $b) => $b['y'] <=> $a['y']);
    $out = [];
    foreach ($lines as $ln) {
        usort($ln['items'], fn($a, $b) => $a['x'] <=> $b['x']);
        $cells = [];
        $cur = null;
        $end = -INF;
        foreach ($ln['items'] as $it) {
            $gap = $it['x'] - $end;
            // Same cell when the gap is at most about two spaces wide.
            if ($cur !== null && $gap < $it['size'] * 0.6) {
                $cur .= ($gap > $it['size'] * 0.15 && !str_ends_with($cur, ' ') && !str_starts_with($it['text'], ' ') ? ' ' : '') . $it['text'];
            } else {
                if ($cur !== null) {
                    $cells[] = trim($cur);
                }
                $cur = $it['text'];
            }
            $end = max($end, $it['x'] + $it['w']);
        }
        if ($cur !== null) {
            $cells[] = trim($cur);
        }
        $out[] = array_values(array_filter($cells, fn($c) => $c !== ''));
    }
    return $out;
}

final class SbPdfDoc
{
    private string $raw;
    /** @var array<int,string> object number -> raw object body */
    private array $objs = [];
    private array $fontCache = [];
    private float $curSize = 10.0;

    public function __construct(string $raw)
    {
        $this->raw = $raw;
        if (preg_match('/\/Encrypt\s/', $raw)) {
            throw new RuntimeException('The PDF is password-protected/encrypted. Ask Nationlink for an unprotected copy or the Excel/CSV version.');
        }
        if (preg_match_all('/(?<![0-9])(\d+)\s+(\d+)\s+obj\b/', $raw, $m, PREG_OFFSET_CAPTURE)) {
            $count = count($m[0]);
            for ($i = 0; $i < $count; $i++) {
                $start = $m[0][$i][1] + strlen($m[0][$i][0]);
                $next = $i + 1 < $count ? $m[0][$i + 1][1] : strlen($raw);
                $chunk = substr($raw, $start, $next - $start);
                $e = strrpos($chunk, 'endobj');
                $this->objs[(int) $m[1][$i][0]] = $e !== false ? substr($chunk, 0, $e) : $chunk;
            }
        }
        // PDF 1.5 compressed object streams.
        foreach ($this->objs as $body) {
            if (preg_match('/\/Type\s*\/ObjStm\b/', $this->dictPart($body))) {
                $data = $this->streamData($body);
                $dict = $this->dictPart($body);
                $n = (int) $this->dictNum($dict, 'N');
                $first = (int) $this->dictNum($dict, 'First');
                $hdr = preg_split('/\s+/', trim(substr($data, 0, $first))) ?: [];
                for ($k = 0; $k + 1 < count($hdr) && $k < $n * 2; $k += 2) {
                    $num = (int) $hdr[$k];
                    $off = $first + (int) $hdr[$k + 1];
                    $nextOff = $k + 3 < count($hdr) ? $first + (int) $hdr[$k + 3] : strlen($data);
                    if (!isset($this->objs[$num])) {
                        $this->objs[$num] = substr($data, $off, $nextOff - $off);
                    }
                }
            }
        }
    }

    private function dictPart(string $body): string
    {
        $p = strpos($body, 'stream');
        return $p === false ? $body : substr($body, 0, $p);
    }

    private function dictNum(string $dict, string $key): ?float
    {
        if (preg_match('/\/' . $key . '\s+(\d+)\s+(\d+)\s+R/', $dict, $m)) {
            return (float) trim($this->objs[(int) $m[1]] ?? '0');
        }
        return preg_match('/\/' . $key . '\s+(-?[0-9.]+)/', $dict, $m) ? (float) $m[1] : null;
    }

    private function streamData(string $body): string
    {
        if (!preg_match('/stream\r?\n/', $body, $m, PREG_OFFSET_CAPTURE)) {
            return '';
        }
        $dict = substr($body, 0, $m[0][1]);
        $start = $m[0][1] + strlen($m[0][0]);
        $len = $this->dictNum($dict, 'Length');
        $data = $len !== null && $len > 0 && $start + (int) $len <= strlen($body) ? substr($body, $start, (int) $len) : null;
        if ($data === null) {
            $e = strrpos($body, 'endstream');
            $data = rtrim(substr($body, $start, ($e === false ? strlen($body) : $e) - $start), "\r\n");
        }
        if (preg_match('/\/Filter\s*\[?\s*\/FlateDecode/', $dict) || preg_match('/\/Filter\s*\/Fl\b/', $dict)) {
            $out = @gzuncompress($data);
            if ($out === false) {
                $out = @gzinflate(substr($data, 2));
            }
            if ($out === false) {
                $out = @gzinflate($data);
            }
            $data = $out === false ? '' : $out;
        } elseif (preg_match('/\/Filter\s*\[?\s*\/(\w+)/', $dict, $fm)) {
            return ''; // other filters (images etc.) carry no text we need
        }
        return $data;
    }

    private function deref(string $v): string
    {
        $v = trim($v);
        if (preg_match('/^(\d+)\s+\d+\s+R$/', $v, $m)) {
            return trim($this->dictPart($this->objs[(int) $m[1]] ?? ''));
        }
        return $v;
    }

    /** Value of /Key in a dictionary string: nested dict, array, ref or scalar. */
    private function dictGet(string $dict, string $key): ?string
    {
        $pos = 0;
        $len = strlen($dict);
        $depth = 0;
        while ($pos < $len) {
            $ch = $dict[$pos];
            if ($ch === '<' && ($dict[$pos + 1] ?? '') === '<') {
                $depth++;
                $pos += 2;
                continue;
            }
            if ($ch === '>' && ($dict[$pos + 1] ?? '') === '>') {
                $depth--;
                $pos += 2;
                continue;
            }
            if ($ch === '(') {
                $pos = $this->skipLiteral($dict, $pos);
                continue;
            }
            if ($ch === '/' && $depth === 1 && preg_match('/\G\/' . preg_quote($key, '/') . '(?=[\s\/\[<(]|$)/', $dict, $mm, 0, $pos)) {
                $p = $pos + strlen($mm[0]);
                while ($p < $len && ctype_space($dict[$p])) {
                    $p++;
                }
                return $this->readValue($dict, $p);
            }
            $pos++;
        }
        return null;
    }

    private function skipLiteral(string $s, int $pos): int
    {
        $d = 0;
        $len = strlen($s);
        for (; $pos < $len; $pos++) {
            $c = $s[$pos];
            if ($c === '\\') {
                $pos++;
            } elseif ($c === '(') {
                $d++;
            } elseif ($c === ')') {
                $d--;
                if ($d === 0) {
                    return $pos + 1;
                }
            }
        }
        return $len;
    }

    private function readValue(string $s, int $p): string
    {
        $len = strlen($s);
        if (substr($s, $p, 2) === '<<') {
            $d = 0;
            for ($i = $p; $i < $len - 1; $i++) {
                if ($s[$i] === '<' && $s[$i + 1] === '<') {
                    $d++;
                    $i++;
                } elseif ($s[$i] === '>' && $s[$i + 1] === '>') {
                    $d--;
                    $i++;
                    if ($d === 0) {
                        return substr($s, $p, $i - $p + 1);
                    }
                }
            }
            return substr($s, $p);
        }
        if (($s[$p] ?? '') === '[') {
            $d = 0;
            for ($i = $p; $i < $len; $i++) {
                if ($s[$i] === '[') {
                    $d++;
                } elseif ($s[$i] === ']') {
                    $d--;
                    if ($d === 0) {
                        return substr($s, $p, $i - $p + 1);
                    }
                }
            }
            return substr($s, $p);
        }
        if (preg_match('/\G(\d+\s+\d+\s+R|\/[^\s\/\[\]<>()]+|[^\s\/\[\]<>()]+)/', $s, $m, 0, $p)) {
            return $m[1];
        }
        return '';
    }

    /** @return string[] page dictionaries in document order */
    public function pages(): array
    {
        $root = null;
        if (preg_match_all('/\/Root\s+(\d+)\s+\d+\s+R/', $this->raw, $m)) {
            $root = (int) end($m[1]);
        }
        $pages = [];
        if ($root !== null && isset($this->objs[$root])) {
            $pagesRef = $this->dictGet($this->dictPart($this->objs[$root]), 'Pages');
            if ($pagesRef !== null) {
                $this->collectPages($this->deref($pagesRef), $pages, [], 0);
            }
        }
        if (!$pages) {
            foreach ($this->objs as $body) {
                $d = $this->dictPart($body);
                if (preg_match('/\/Type\s*\/Page\b(?!s)/', $d)) {
                    $pages[] = ['dict' => $d, 'resources' => $this->dictGet($d, 'Resources')];
                }
            }
        }
        return $pages;
    }

    private function collectPages(string $node, array &$pages, array $inherited, int $depth): void
    {
        if ($depth > 32) {
            return;
        }
        $res = $this->dictGet($node, 'Resources') ?? ($inherited['resources'] ?? null);
        $kids = $this->dictGet($node, 'Kids');
        if ($kids !== null) {
            if (preg_match_all('/(\d+)\s+\d+\s+R/', $this->deref($kids), $m)) {
                foreach ($m[1] as $num) {
                    $this->collectPages(trim($this->dictPart($this->objs[(int) $num] ?? '')), $pages, ['resources' => $res], $depth + 1);
                }
            }
            return;
        }
        $pages[] = ['dict' => $node, 'resources' => $res];
    }

    private function contentOf(array $page): string
    {
        $c = $this->dictGet($page['dict'], 'Contents');
        if ($c === null) {
            return '';
        }
        $c = trim($c);
        if (preg_match('/^(\d+)\s+\d+\s+R$/', $c, $m)) {
            $body = $this->objs[(int) $m[1]] ?? '';
            if (str_starts_with(ltrim($this->dictPart($body)), '[')) {
                $c = trim($this->dictPart($body));
            } else {
                return $this->streamData($body);
            }
        }
        $out = '';
        if (preg_match_all('/(\d+)\s+\d+\s+R/', $c, $m)) {
            foreach ($m[1] as $num) {
                $out .= $this->streamData($this->objs[(int) $num] ?? '') . "\n";
            }
        }
        return $out;
    }

    /** Font resource name -> ['map' => code=>unicode, 'bytes' => 1|2] */
    private function fonts(?string $resources): array
    {
        if ($resources === null) {
            return [];
        }
        $fontDict = $this->dictGet($this->deref($resources), 'Font');
        if ($fontDict === null) {
            return [];
        }
        $fontDict = $this->deref($fontDict);
        $fonts = [];
        if (preg_match_all('/\/([^\s\/<>\[\]()]+)\s+(\d+)\s+\d+\s+R/', $fontDict, $m, PREG_SET_ORDER)) {
            foreach ($m as $f) {
                $fonts[$f[1]] = $this->font((int) $f[2]);
            }
        }
        return $fonts;
    }

    private function font(int $num): array
    {
        if (isset($this->fontCache[$num])) {
            return $this->fontCache[$num];
        }
        $d = trim($this->dictPart($this->objs[$num] ?? ''));
        $twoByte = (bool) preg_match('/\/Subtype\s*\/Type0/', $d);
        $map = [];
        $tu = $this->dictGet($d, 'ToUnicode');
        if ($tu !== null && preg_match('/^(\d+)\s+\d+\s+R$/', trim($tu), $m)) {
            $map = $this->parseCMap($this->streamData($this->objs[(int) $m[1]] ?? ''));
        }
        // Glyph widths (1/1000 text space units) so text positions/gaps are exact.
        $widths = [];
        $dw = 500.0;
        if ($twoByte) {
            $dw = 1000.0;
            $desc = $this->dictGet($d, 'DescendantFonts');
            if ($desc !== null && preg_match('/(\d+)\s+\d+\s+R/', $this->deref($desc), $m)) {
                $cid = trim($this->dictPart($this->objs[(int) $m[1]] ?? ''));
                $dw = (float) ($this->dictGet($cid, 'DW') ?? 1000);
                $w = $this->dictGet($cid, 'W');
                if ($w !== null) {
                    $w = $this->deref($w);
                    $tok = $this->tokenize(substr(trim($w), 1, -1));
                    for ($i = 0, $n = count($tok); $i < $n;) {
                        $first = (int) ($tok[$i][1] ?? 0);
                        if (($tok[$i + 1][0] ?? '') === 'arr') {
                            foreach ($tok[$i + 1][1] as $k => $el) {
                                $widths[$first + $k] = (float) $el[1];
                            }
                            $i += 2;
                        } elseif ($i + 2 < $n) {
                            $last = min((int) $tok[$i + 1][1], $first + 65535);
                            for ($c = $first; $c <= $last; $c++) {
                                $widths[$c] = (float) $tok[$i + 2][1];
                            }
                            $i += 3;
                        } else {
                            break;
                        }
                    }
                }
            }
        } else {
            $fc = (int) ($this->dictGet($d, 'FirstChar') ?? 0);
            $ws = $this->dictGet($d, 'Widths');
            if ($ws !== null && preg_match_all('/-?[0-9.]+/', $this->deref($ws), $m)) {
                foreach ($m[0] as $k => $v) {
                    $widths[$fc + $k] = (float) $v;
                }
            }
        }
        return $this->fontCache[$num] = ['map' => $map, 'bytes' => $twoByte ? 2 : 1, 'widths' => $widths, 'dw' => $dw];
    }

    private function parseCMap(string $cmap): array
    {
        $map = [];
        $hexToUtf8 = function (string $hex): string {
            $hex = preg_replace('/\s+/', '', $hex) ?? '';
            $bin = (string) hex2bin(strlen($hex) % 2 ? '0' . $hex : $hex);
            return (string) mb_convert_encoding($bin, 'UTF-8', 'UTF-16BE');
        };
        if (preg_match_all('/beginbfchar(.*?)endbfchar/s', $cmap, $blocks)) {
            foreach ($blocks[1] as $b) {
                if (preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f\s]+)>/', $b, $mm, PREG_SET_ORDER)) {
                    foreach ($mm as $x) {
                        $map[hexdec($x[1])] = $hexToUtf8($x[2]);
                    }
                }
            }
        }
        if (preg_match_all('/beginbfrange(.*?)endbfrange/s', $cmap, $blocks)) {
            foreach ($blocks[1] as $b) {
                if (preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>\s*(<[0-9A-Fa-f]+>|\[[^\]]*\])/', $b, $mm, PREG_SET_ORDER)) {
                    foreach ($mm as $x) {
                        $lo = hexdec($x[1]);
                        $hi = min(hexdec($x[2]), $lo + 65535);
                        if ($x[3][0] === '[') {
                            preg_match_all('/<([0-9A-Fa-f]+)>/', $x[3], $arr);
                            foreach ($arr[1] as $i => $h) {
                                $map[$lo + $i] = $hexToUtf8($h);
                            }
                        } else {
                            $dst = trim($x[3], '<>');
                            $base = hexdec($dst);
                            for ($c = $lo; $c <= $hi; $c++) {
                                $map[$c] = $hexToUtf8(str_pad(dechex($base + $c - $lo), strlen($dst), '0', STR_PAD_LEFT));
                            }
                        }
                    }
                }
            }
        }
        return $map;
    }

    /** @return array{0:string,1:float} text + advance width in 1/1000 units */
    private function decodeText(string $bytes, ?array $font): array
    {
        $font ??= ['map' => [], 'bytes' => 1, 'widths' => [], 'dw' => 500.0];
        $out = '';
        $adv = 0.0;
        $step = $font['bytes'];
        for ($i = 0, $n = strlen($bytes); $i + $step - 1 < $n; $i += $step) {
            $code = $step === 2 ? (ord($bytes[$i]) << 8) | ord($bytes[$i + 1]) : ord($bytes[$i]);
            $adv += $font['widths'][$code] ?? $font['dw'];
            if (isset($font['map'][$code])) {
                $out .= $font['map'][$code];
            } elseif ($step === 1) {
                $out .= mb_convert_encoding(chr($code), 'UTF-8', 'Windows-1252');
            }
        }
        return [$out, $adv];
    }

    /** @return array<int,array{x:float,y:float,w:float,size:float,text:string}> */
    public function pageFragments(array $page): array
    {
        $fonts = $this->fonts($page['resources']);
        $tokens = $this->tokenize($this->contentOf($page));
        $frags = [];
        $ctm = [1, 0, 0, 1, 0, 0];
        $stack = [];
        $tm = $tlm = [1, 0, 0, 1, 0, 0];
        $font = null;
        $size = 10.0;
        $leading = 0.0;
        $ops = [];
        $mul = fn(array $a, array $b): array => [
            $a[0] * $b[0] + $a[1] * $b[2], $a[0] * $b[1] + $a[1] * $b[3],
            $a[2] * $b[0] + $a[3] * $b[2], $a[2] * $b[1] + $a[3] * $b[3],
            $a[4] * $b[0] + $a[5] * $b[2] + $b[4], $a[4] * $b[1] + $a[5] * $b[3] + $b[5],
        ];
        // $adv = text-space advance (glyph widths/1000 * font size), already including TJ kerning.
        $show = function (string $text, float $adv) use (&$frags, &$tm, &$ctm, $mul) {
            $m = $mul($tm, $ctm);
            $scale = sqrt(abs($m[0] * $m[3] - $m[1] * $m[2]));
            $hs = sqrt($m[0] * $m[0] + $m[1] * $m[1]);
            if ($text !== '') {
                $frags[] = ['x' => $m[4], 'y' => $m[5], 'w' => $adv * $hs, 'size' => max(0.1, $this->curSize * $scale), 'text' => $text];
            }
            $tm = $mul([1, 0, 0, 1, $adv, 0], $tm);
        };
        foreach ($tokens as $tok) {
            if ($tok[0] !== 'op') {
                $ops[] = $tok;
                continue;
            }
            $op = $tok[1];
            $num = fn(int $i): float => (float) ($ops[$i][1] ?? 0);
            switch ($op) {
                case 'q':
                    $stack[] = $ctm;
                    break;
                case 'Q':
                    $ctm = array_pop($stack) ?? [1, 0, 0, 1, 0, 0];
                    break;
                case 'cm':
                    if (count($ops) >= 6) {
                        $o = array_slice($ops, -6);
                        $ctm = $mul(array_map(fn($t) => (float) $t[1], $o), $ctm);
                    }
                    break;
                case 'BT':
                    $tm = $tlm = [1, 0, 0, 1, 0, 0];
                    break;
                case 'Tf':
                    $n = count($ops);
                    if ($n >= 2) {
                        $font = $fonts[ltrim((string) $ops[$n - 2][1], '/')] ?? null;
                        $size = $this->curSize = (float) $ops[$n - 1][1];
                    }
                    break;
                case 'TL':
                    $leading = $num(count($ops) - 1);
                    break;
                case 'Tm':
                    if (count($ops) >= 6) {
                        $tm = $tlm = array_map(fn($t) => (float) $t[1], array_slice($ops, -6));
                    }
                    break;
                case 'Td':
                case 'TD':
                    $n = count($ops);
                    if ($n >= 2) {
                        $tx = (float) $ops[$n - 2][1];
                        $ty = (float) $ops[$n - 1][1];
                        if ($op === 'TD') {
                            $leading = -$ty;
                        }
                        $tm = $tlm = $mul([1, 0, 0, 1, $tx, $ty], $tlm);
                    }
                    break;
                case 'T*':
                    $tm = $tlm = $mul([1, 0, 0, 1, 0, -$leading], $tlm);
                    break;
                case 'Tj':
                case "'":
                case '"':
                    if ($op !== 'Tj') {
                        $tm = $tlm = $mul([1, 0, 0, 1, 0, -$leading], $tlm);
                    }
                    $last = end($ops);
                    if ($last && $last[0] === 'str') {
                        [$t, $u] = $this->decodeText($last[1], $font);
                        $show($t, $u / 1000 * $size);
                    }
                    break;
                case 'TJ':
                    $last = end($ops);
                    if ($last && $last[0] === 'arr') {
                        $text = '';
                        $units = 0.0;
                        foreach ($last[1] as $el) {
                            if ($el[0] === 'str') {
                                [$t, $u] = $this->decodeText($el[1], $font);
                                $text .= $t;
                                $units += $u;
                            } elseif ($el[0] === 'num') {
                                $units -= (float) $el[1];
                                if ((float) $el[1] < -250) {
                                    $text .= ' ';
                                }
                            }
                        }
                        $show($text, $units / 1000 * $size);
                    }
                    break;
            }
            $ops = [];
        }
        return $frags;
    }

    /** Content stream -> tokens: ['num',v] ['name',v] ['str',bytes] ['arr',[...]] ['op',v]. */
    private function tokenize(string $s): array
    {
        $tokens = [];
        $stack = [];
        $len = strlen($s);
        $i = 0;
        $push = function (array $t) use (&$tokens, &$stack) {
            if ($stack) {
                $stack[count($stack) - 1][] = $t;
            } else {
                $tokens[] = $t;
            }
        };
        while ($i < $len) {
            $c = $s[$i];
            if (ctype_space($c)) {
                $i++;
                continue;
            }
            if ($c === '%') {
                $e = strpos($s, "\n", $i);
                $i = $e === false ? $len : $e + 1;
                continue;
            }
            if ($c === '(') {
                $depth = 0;
                $buf = '';
                for ($i++; $i < $len; $i++) {
                    $ch = $s[$i];
                    if ($ch === '\\') {
                        $nx = $s[++$i] ?? '';
                        $esc = ['n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08", 'f' => "\f", '(' => '(', ')' => ')', '\\' => '\\'];
                        if (isset($esc[$nx])) {
                            $buf .= $esc[$nx];
                        } elseif (ctype_digit($nx)) {
                            $oct = $nx;
                            while (strlen($oct) < 3 && ctype_digit($s[$i + 1] ?? '')) {
                                $oct .= $s[++$i];
                            }
                            $buf .= chr(octdec($oct) & 0xFF);
                        } elseif ($nx === "\r" || $nx === "\n") {
                            if ($nx === "\r" && ($s[$i + 1] ?? '') === "\n") {
                                $i++;
                            }
                        } else {
                            $buf .= $nx;
                        }
                        continue;
                    }
                    if ($ch === '(') {
                        $depth++;
                    } elseif ($ch === ')') {
                        if ($depth === 0) {
                            break;
                        }
                        $depth--;
                    }
                    $buf .= $ch;
                }
                $i++;
                $push(['str', $buf]);
                continue;
            }
            if ($c === '<' && ($s[$i + 1] ?? '') === '<') {
                // inline dictionary (e.g. BDC properties): skip
                $d = 0;
                for (; $i < $len - 1; $i++) {
                    if ($s[$i] === '<' && $s[$i + 1] === '<') {
                        $d++;
                        $i++;
                    } elseif ($s[$i] === '>' && $s[$i + 1] === '>') {
                        $d--;
                        $i++;
                        if ($d === 0) {
                            $i++;
                            break;
                        }
                    }
                }
                $push(['dict', '']);
                continue;
            }
            if ($c === '<') {
                $e = strpos($s, '>', $i);
                $e = $e === false ? $len : $e;
                $hex = preg_replace('/[^0-9A-Fa-f]/', '', substr($s, $i + 1, $e - $i - 1)) ?? '';
                if (strlen($hex) % 2) {
                    $hex .= '0';
                }
                $push(['str', (string) hex2bin($hex)]);
                $i = $e + 1;
                continue;
            }
            if ($c === '[') {
                $stack[] = [];
                $i++;
                continue;
            }
            if ($c === ']') {
                $arr = array_pop($stack) ?? [];
                $push(['arr', $arr]);
                $i++;
                continue;
            }
            if ($c === '/') {
                preg_match('/\G\/[^\s\/\[\]<>()%{}]*/', $s, $m, 0, $i);
                $push(['name', $m[0]]);
                $i += strlen($m[0]);
                continue;
            }
            if (preg_match('/\G[+-]?(?:\d+\.?\d*|\.\d+)/', $s, $m, 0, $i)) {
                $push(['num', $m[0]]);
                $i += strlen($m[0]);
                continue;
            }
            if (preg_match('/\G[^\s\/\[\]<>()%{}]+/', $s, $m, 0, $i)) {
                $word = $m[0];
                $i += strlen($word);
                if ($word === 'BI') {
                    // skip inline image data
                    $e = strpos($s, 'EI', $i);
                    $i = $e === false ? $len : $e + 2;
                    continue;
                }
                if ($word === 'true' || $word === 'false' || $word === 'null') {
                    $push(['num', '0']);
                    continue;
                }
                $tokens[] = ['op', $word];
                continue;
            }
            $i++;
        }
        return $tokens;
    }
}

// =====================================================================
// 3. Rows -> Nationlink settlement lines
// =====================================================================

function sb_stl_money(string $v): ?float
{
    $v = trim(str_replace(['₱', 'PHP', 'Php', ',', ' '], '', $v));
    $neg = false;
    if (preg_match('/^\((.*)\)$/', $v, $m)) {
        $neg = true;
        $v = $m[1];
    }
    if ($v === '' || !is_numeric($v)) {
        return null;
    }
    return round(($neg ? -1 : 1) * (float) $v, 2);
}

/** Report timestamp ("10/6/2026 3:23:27 PM", "2026-10-06 15:23:27", Excel serial) -> Y-m-d H:i:s. */
function sb_stl_datetime(string $v, ?string $fallbackDate = null): ?string
{
    $v = trim(preg_replace('/\s+/', ' ', $v) ?? $v);
    if ($v === '') {
        return $fallbackDate ? $fallbackDate . ' 00:00:00' : null;
    }
    if (is_numeric($v) && (float) $v > 20000 && (float) $v < 80000) {
        $v = sb_excel_serial_to_string((float) $v);
    }
    foreach (['n/j/Y g:i:s A', 'n/j/Y g:i A', 'n/j/Y H:i:s', 'n/j/Y H:i', 'Y-m-d H:i:s', 'Y-m-d\TH:i:s', 'Y-m-d H:i', 'YmdHis', 'n/j/Y', 'Y-m-d'] as $f) {
        $d = DateTimeImmutable::createFromFormat('!' . $f, $v);
        $e = DateTimeImmutable::getLastErrors();
        if ($d && (!$e || (!$e['warning_count'] && !$e['error_count']))) {
            return $d->format('Y-m-d H:i:s');
        }
    }
    try {
        return (new DateTimeImmutable($v))->format('Y-m-d H:i:s');
    } catch (Throwable $e) {
        return null;
    }
}

function sb_stl_norm_header(string $h): string
{
    return preg_replace('/[^A-Z0-9]/', '', strtoupper($h)) ?? '';
}

/** Column index map from a header row, or null if it is not the report header. */
function sb_stl_header_map(array $cells): ?array
{
    $syn = [
        'time' => ['TIMESTAMP', 'TIME', 'DATE', 'DATETIME', 'TRANDATE', 'TRANTIME', 'TRANSACTIONDATE', 'TRANSACTIONTIME', 'TXNDATE'],
        'trace' => ['TRACENO', 'TRACE', 'TRACENUMBER', 'REFERENCE', 'REFERENCENO', 'REFNO', 'REFERENCENUMBER', 'RRN'],
        'seq' => ['SEQNO', 'SEQ', 'SEQUENCE', 'SEQUENCENO', 'TRANSEQUENCE'],
        'payer' => ['SOURCEACCOUNTNO', 'SOURCEACCOUNT', 'SOURCEACCTNAME', 'SOURCEACCOUNTNAME', 'ACCOUNTSENDER', 'SENDER', 'PAYER', 'PAYERNAME', 'SOURCEACCTNO'],
        'amount' => ['TRANAMOUNT', 'AMOUNT', 'TRANSACTIONAMOUNT', 'GROSSAMOUNT', 'TXNAMOUNT'],
        'tag' => ['TAG'],
        'rate' => ['RATE'],
        'discount' => ['DISCOUNT', 'DISCOUNTRATE', 'FEE', 'MDR'],
        'net' => ['NETSETTLEMENT', 'NET', 'NETAMOUNT', 'SETTLEMENT', 'NETSETTLEMENTAMOUNT'],
        'member' => ['MEMBERID', 'MEMBER', 'MEMBERCODE', 'SUBMEMBER', 'SERVICECODE', 'ACCOUNTCODE', 'QRCODE', 'CODE', 'MERCHANTID', 'SUBMERCHANT'],
        'member_name' => ['MEMBERNAME', 'SERVICENAME', 'SERVICE', 'QRNAME', 'DESCRIPTION'],
    ];
    $map = [];
    foreach ($cells as $i => $c) {
        $n = sb_stl_norm_header((string) $c);
        foreach ($syn as $key => $names) {
            if (!isset($map[$key]) && in_array($n, $names, true)) {
                $map[$key] = $i;
                break;
            }
        }
    }
    return isset($map['trace'], $map['amount']) ? $map : null;
}

/**
 * Parse report rows into settlement lines.
 * @return array{meta:array, lines:array, checks:array, warnings:array}
 */
function sb_stl_parse_rows(array $rows, string $fileName = '', string $format = ''): array
{
    $meta = ['report_type' => null, 'report_date' => null, 'run_date' => null, 'main_org' => null, 'main_org_name' => null, 'branches' => []];
    $lines = [];
    $checks = [];
    $warnings = [];
    $map = null;
    $org = null;
    $branch = null;
    $branchName = null;
    $member = null;
    $memberName = null;
    $m = [];
    $M = SB_STL_MONEY_RE;

    if (preg_match('/(20\d{2})(\d{2})(\d{2})/', $fileName, $fm)) {
        $meta['report_date'] = "$fm[1]-$fm[2]-$fm[3]";
    }

    foreach ($rows as $rowNo => $cells) {
        $cells = array_map(fn($c) => trim((string) $c), $cells);
        if (!array_filter($cells, fn($c) => $c !== '')) {
            continue;
        }
        $line = trim(preg_replace('/\s+/u', ' ', implode('  ', array_filter($cells, fn($c) => $c !== ''))) ?? '');
        $compact = strtoupper(str_replace(' ', '', $line));

        // Report header info.
        if (preg_match('/REPORTTYPE:?([A-Z0-9]+)/', $compact, $m)) {
            $meta['report_type'] = $m[1];
        }
        if (preg_match('/RUNDATE:?(\d{1,2}\/\d{1,2}\/\d{4})/', $compact, $m)) {
            $meta['run_date'] = sb_stl_datetime($m[1]) ? substr((string) sb_stl_datetime($m[1]), 0, 10) : null;
        }
        if (preg_match('/^FOR(\d{1,2}\/\d{1,2}\/\d{4})$/', $compact, $m) || preg_match('/REPORT.*FOR(\d{1,2}\/\d{1,2}\/\d{4})/', $compact, $m)) {
            $meta['report_date'] = substr((string) sb_stl_datetime($m[1]), 0, 10) ?: $meta['report_date'];
            continue;
        }
        if (($hm = sb_stl_header_map($cells)) !== null) {
            // PDF cells are positional (not real columns): PDF lines are read as text lines instead.
            $map = $format === 'pdf' ? null : $hm;
            continue;
        }
        if (preg_match('/^MAIN\s*ORG\s*:?\s*([A-Z][0-9]{5})\s*(.*)$/i', $line, $m)) {
            $org = strtoupper($m[1]);
            $meta['main_org'] = $org;
            $meta['main_org_name'] = trim($m[2]);
            continue;
        }
        if (preg_match('/^BRANCH\s*:?\s*([A-Z][0-9]{5})\s*(.*)$/i', $line, $m)) {
            $branch = strtoupper($m[1]);
            $branchName = trim($m[2]);
            $meta['branches'][$branch] = $branchName;
            $member = null;
            continue;
        }
        if (preg_match('/^(SUB-?\s*TOTAL|GRAND\s*TOTAL|TOTAL)\s*:?\s*(?:([A-Z][0-9]{5})\b)?.*?(' . $M . ')\s+(' . $M . ')\s+(' . $M . ')\s*$/i', $line, $m)) {
            $checks[] = [
                'kind' => strtoupper(preg_replace('/\s+/', '', $m[1]) ?? $m[1]),
                'code' => isset($m[2]) && $m[2] !== '' ? strtoupper($m[2]) : null,
                'amount' => sb_stl_money($m[3]),
                'discount' => sb_stl_money($m[4]),
                'net' => sb_stl_money($m[5]),
            ];
            continue;
        }

        $rec = null;
        if ($map !== null) {
            $get = fn(string $k) => isset($map[$k]) ? trim((string) ($cells[$map[$k]] ?? '')) : '';
            $amount = sb_stl_money($get('amount'));
            $trace = preg_replace('/\s+/', '', $get('trace')) ?? '';
            if (preg_match('/^[A-Z0-9-]{6,}$/i', $trace) && $amount !== null && !preg_match('/TOTAL/i', $line)) {
                $rec = [
                    'time' => $get('time'), 'trace' => $trace, 'seq' => $get('seq'), 'payer' => $get('payer'),
                    'amount' => $amount, 'tag' => $get('tag'), 'rate' => sb_stl_money($get('rate')),
                    'discount' => sb_stl_money($get('discount')), 'net' => sb_stl_money($get('net')),
                    'member' => strtoupper($get('member')), 'member_name' => $get('member_name'),
                ];
            }
        }
        if ($rec === null && preg_match(
            '/^(\d{1,2}\/\d{1,2}\/\d{4}\s+\d{1,2}:\d{2}(?::\d{2})?(?:\s*[AP]M)?|\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(?::\d{2})?)\s+([A-Z0-9-]{8,})\s+(\d{1,10})\s+(.*?)\s+(' . $M . ')(?:\s+([A-Z]{2,5}))?(?:\s+(' . $M . '))?\s+(' . $M . ')\s+(' . $M . ')$/i',
            $line,
            $m
        )) {
            $rec = [
                'time' => $m[1], 'trace' => $m[2], 'seq' => $m[3], 'payer' => trim($m[4]),
                'amount' => sb_stl_money($m[5]), 'tag' => $m[6] ?? '', 'rate' => isset($m[7]) && $m[7] !== '' ? sb_stl_money($m[7]) : null,
                'discount' => sb_stl_money($m[8]), 'net' => sb_stl_money($m[9]), 'member' => '', 'member_name' => '',
            ];
        }
        if ($rec !== null) {
            if ($rec['member'] === '' || !sb_nl_member_valid($rec['member'])) {
                $rec['member'] = (string) ($member ?? $branch ?? $org ?? '');
                $rec['member_name'] = $rec['member_name'] !== '' ? $rec['member_name'] : (string) ($memberName ?? $branchName ?? '');
            }
            $rec['branch'] = $branch;
            $rec['org'] = $org;
            $rec['row'] = $rowNo + 1;
            $rec['paid_at'] = sb_stl_datetime($rec['time'], $meta['report_date']);
            if ($rec['net'] === null && $rec['discount'] !== null) {
                $rec['net'] = round($rec['amount'] - $rec['discount'], 2);
            }
            $lines[] = $rec;
            continue;
        }
        // Group header: "A10103  Donations" (Nationlink service / QR MemberID).
        if (preg_match('/^([A-Z][0-9]{5})\s+(.+)$/', $line, $m) && !preg_match('/\s[0-9][0-9,]*\.[0-9]{2}\b/', $line)) {
            $member = strtoupper($m[1]);
            $memberName = trim($m[2]);
            continue;
        }
        if (preg_match('/^([A-Z][0-9]{5})$/', $line, $m)) {
            $member = $m[1];
            $memberName = '';
        }
    }

    if (!$meta['report_date'] && $lines && $lines[0]['paid_at']) {
        $meta['report_date'] = substr($lines[0]['paid_at'], 0, 10);
    }

    // Cross-check the report's own SUB-TOTAL / TOTAL lines against the parsed lines.
    foreach ($checks as &$c) {
        $sel = array_filter($lines, function ($l) use ($c) {
            if ($c['code'] === null) {
                return true;
            }
            return $l['member'] === $c['code'] || $l['branch'] === $c['code'] || $l['org'] === $c['code'];
        });
        $sumA = round(array_sum(array_column($sel, 'amount')), 2);
        $sumN = round(array_sum(array_map(fn($l) => (float) $l['net'], $sel)), 2);
        $c['parsed_amount'] = $sumA;
        $c['parsed_net'] = $sumN;
        $c['ok'] = abs($sumA - (float) $c['amount']) < 0.005 && ($c['net'] === null || abs($sumN - (float) $c['net']) < 0.005);
        if (!$c['ok']) {
            $warnings[] = sprintf('%s %s: report says %s / net %s but %s / net %s was read from the file.', $c['kind'], $c['code'] ?? '', number_format((float) $c['amount'], 2), number_format((float) $c['net'], 2), number_format($sumA, 2), number_format($sumN, 2));
        }
    }
    unset($c);

    $seen = [];
    foreach ($lines as $l) {
        if (isset($seen[$l['trace']])) {
            $warnings[] = 'Trace no. ' . $l['trace'] . ' appears more than once in the file; it will only be recorded once.';
        }
        $seen[$l['trace']] = true;
        if ($l['member'] === '') {
            $warnings[] = 'Row ' . $l['row'] . ' (' . $l['trace'] . ') has no MemberID / QR code group.';
        }
    }
    return ['meta' => $meta, 'lines' => $lines, 'checks' => $checks, 'warnings' => array_values(array_unique($warnings))];
}

// =====================================================================
// 4. Match against the database + record
// =====================================================================

/** The ledger reference a Nationlink trace no. is stored under (same as the webhook). */
function sb_stl_reference(string $trace): string
{
    return mb_substr(str_starts_with($trace, 'NL-') ? $trace : 'NL-' . $trace, 0, 120);
}

function sb_stl_existing_tx(string $trace): ?array
{
    $s = db()->prepare('SELECT id, organization_id, amount, fee, net_amount, reference_no, qr_ph_trace_no, transaction_date FROM sb_transactions WHERE reference_no IN (?, ?) OR qr_ph_trace_no = ? ORDER BY id LIMIT 1');
    $s->execute([sb_stl_reference($trace), $trace, $trace]);
    return $s->fetch() ?: null;
}

/** Resolve the client + QR a line belongs to: service MemberID first, then branch, then main org. */
function sb_stl_resolve(array $l, ?int $orgId): ?array
{
    foreach (array_unique(array_filter([$l['member'] ?? '', $l['branch'] ?? '', $l['org'] ?? ''])) as $code) {
        if (!sb_nl_member_valid($code)) {
            continue;
        }
        $r = sb_nl_resolve($code);
        if ($r && $r['gateway'] && (!$orgId || (int) $r['gateway']['organization_id'] === $orgId)) {
            $r['via'] = $code;
            return $r;
        }
    }
    if ($orgId) {
        // Admin picked the client explicitly: book it on the client's Nationlink gateway.
        $gw = sb_nl_gateway($orgId);
        if ($gw) {
            return ['gateway' => $gw, 'qr' => null, 'via' => 'client'];
        }
    }
    return null;
}

/** Annotate every parsed line with what the import will do. */
function sb_stl_preview(array $parsed, ?int $orgId): array
{
    $orgNames = [];
    $seen = [];
    foreach ($parsed['lines'] as &$l) {
        $l['reference_no'] = sb_stl_reference($l['trace']);
        $l['transaction_id'] = null;
        $l['organization_id'] = null;
        $l['organization_name'] = null;
        $l['qr_label'] = null;
        if (isset($seen[$l['trace']])) {
            $l['status'] = 'Duplicate';
            $l['message'] = 'Repeated in this file';
            continue;
        }
        $seen[$l['trace']] = true;
        $ex = sb_stl_existing_tx($l['trace']);
        $res = sb_stl_resolve($l, $orgId);
        if ($res) {
            $oid = (int) $res['gateway']['organization_id'];
            $l['organization_id'] = $oid;
            if (!isset($orgNames[$oid])) {
                $o = sb_org_row($oid);
                $orgNames[$oid] = $o['organization_name'] ?? ('#' . $oid);
            }
            $l['organization_name'] = $orgNames[$oid];
            $l['qr_label'] = $res['qr']['label'] ?? null;
            if ($res['gateway']['status'] === 'Disabled') {
                $res = null;
            }
        }
        if ($ex) {
            $l['transaction_id'] = (int) $ex['id'];
            if (abs((float) $ex['amount'] - (float) $l['amount']) >= 0.005) {
                $l['status'] = 'Mismatch';
                $l['message'] = 'Already recorded (tx #' . $ex['id'] . ') with amount ' . number_format((float) $ex['amount'], 2) . ' - check manually';
            } elseif ($l['net'] !== null && abs((float) $ex['net_amount'] - (float) $l['net']) >= 0.005) {
                $l['status'] = 'Matched';
                $l['message'] = 'Already recorded (tx #' . $ex['id'] . '); ledger net ' . number_format((float) $ex['net_amount'], 2) . ' vs report net ' . number_format((float) $l['net'], 2);
            } else {
                $l['status'] = 'Matched';
                $l['message'] = 'Already recorded (tx #' . $ex['id'] . ')';
            }
            continue;
        }
        if (!$res) {
            $l['status'] = 'Unmatched';
            $l['message'] = $l['organization_id'] ? 'Client\'s Nationlink gateway is Disabled' : 'No client / Nationlink QR Ph found for MemberID ' . ($l['member'] ?: '-');
            continue;
        }
        if ($l['amount'] <= 0) {
            $l['status'] = 'Unmatched';
            $l['message'] = 'Amount must be above zero';
            continue;
        }
        $l['status'] = 'New';
        $l['message'] = 'Will be recorded' . ($l['qr_label'] ? ' on "' . $l['qr_label'] . '"' : '');
    }
    unset($l);

    $sum = fn(array $ls, string $k) => round(array_sum(array_map(fn($x) => (float) $x[$k], $ls)), 2);
    $byStatus = [];
    foreach ($parsed['lines'] as $l) {
        $byStatus[$l['status']] = ($byStatus[$l['status']] ?? 0) + 1;
    }
    $parsed['summary'] = [
        'rows' => count($parsed['lines']),
        'amount' => $sum($parsed['lines'], 'amount'),
        'discount' => $sum($parsed['lines'], 'discount'),
        'net' => $sum($parsed['lines'], 'net'),
        'by_status' => $byStatus,
        'organizations' => $orgNames,
    ];
    return $parsed;
}

/** Record the "New" lines. Returns the preview with final statuses + the import id. */
function sb_stl_commit(array $parsed, ?int $orgId, array $file, int $userId): array
{
    $result = sb_stl_preview($parsed, $orgId); // re-check against the live ledger
    foreach ($result['lines'] as &$l) {
        if ($l['status'] !== 'New') {
            continue;
        }
        $res = sb_stl_resolve($l, $orgId);
        if (!$res) {
            $l['status'] = 'Unmatched';
            continue;
        }
        $qr = $res['qr'];
        $amount = (float) $l['amount'];
        $net = $l['net'] !== null ? (float) $l['net'] : null;
        $raw = [
            'source' => 'nationlink_settlement_report',
            'report_type' => $result['meta']['report_type'],
            'report_date' => $result['meta']['report_date'],
            'file' => $file['name'],
            'row' => $l['row'],
            'TranCode' => 'RFI',
            'TranTime' => $l['time'],
            'TranAmount' => number_format($amount, 2, '.', ''),
            'TranSequence' => $l['seq'],
            'MemberID' => $l['member'],
            'Reference' => $l['trace'],
            'SourceAcctName' => $l['payer'],
            'Tag' => $l['tag'],
            'Rate' => $l['rate'],
            'Discount' => $l['discount'],
            'NetSettlement' => $net,
        ];
        try {
            $r = sb_record_gateway_payment($res['gateway'], [
                'id' => $l['reference_no'],
                'amount' => $amount,
                // Nationlink's settlement is the source of truth for the deducted fee.
                'fee' => $net !== null ? max(0.0, round($amount - $net, 2)) : null,
                'paid_at' => $l['paid_at'] ?? date('Y-m-d H:i:s'),
                'trace_no' => $l['seq'] !== '' ? $l['seq'] : $l['trace'],
                'payer_name' => $l['payer'] !== '' ? $l['payer'] : null,
                'source_type' => 'qrph',
                'label' => $qr ? $qr['label'] : ($l['member_name'] ?: 'Nationlink QR Ph'),
                'label_ref' => $qr ? 'NLQR-' . $qr['id'] : ($l['member'] ?: 'NLQR'),
                'nl_qr_id' => $qr ? (int) $qr['id'] : null,
                'recorded_via' => 'settlement_import',
            ], null, $raw);
            $l['transaction_id'] = $r['transaction_id'];
            $l['status'] = $r['created'] ? 'Imported' : 'Matched';
            $l['message'] = $r['created'] ? 'Recorded as tx #' . $r['transaction_id'] : 'Already recorded (tx #' . $r['transaction_id'] . ')';
        } catch (Throwable $e) {
            error_log('[SurgeBox settlement import] ' . $e->getMessage());
            $l['status'] = 'Error';
            $l['message'] = 'Could not record: ' . $e->getMessage();
        }
    }
    unset($l);

    $byStatus = [];
    foreach ($result['lines'] as $l) {
        $byStatus[$l['status']] = ($byStatus[$l['status']] ?? 0) + 1;
    }
    $result['summary']['by_status'] = $byStatus;
    $result['import_id'] = sb_stl_log_import($result, $orgId, $file, $userId);
    return $result;
}

/** History (database/migration_v5_24_settlement_import.sql). Never blocks the import itself. */
function sb_stl_log_import(array $result, ?int $orgId, array $file, int $userId): ?int
{
    try {
        $pdo = db();
        $by = $result['summary']['by_status'];
        $pdo->prepare('INSERT INTO sb_settlement_imports (provider, organization_id, report_type, report_date, file_name, file_format, file_sha256, rows_total, rows_imported, rows_matched, rows_mismatch, rows_unmatched, total_amount, total_discount, total_net, imported_by) VALUES (\'nationlink\', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([
                $orgId ?: null,
                $result['meta']['report_type'],
                $result['meta']['report_date'],
                mb_substr($file['name'], 0, 190),
                $file['format'],
                $file['sha256'],
                $result['summary']['rows'],
                (int) ($by['Imported'] ?? 0),
                (int) ($by['Matched'] ?? 0),
                (int) ($by['Mismatch'] ?? 0),
                (int) ($by['Unmatched'] ?? 0) + (int) ($by['Error'] ?? 0),
                $result['summary']['amount'],
                $result['summary']['discount'],
                $result['summary']['net'],
                $userId,
            ]);
        $id = (int) $pdo->lastInsertId();
        $ins = $pdo->prepare('INSERT INTO sb_settlement_import_rows (import_id, row_no, member_id, trace_no, seq_no, payer_name, txn_time, amount, discount, net_settlement, organization_id, transaction_id, status, message) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($result['lines'] as $l) {
            $ins->execute([
                $id, $l['row'], $l['member'] ?: null, mb_substr($l['trace'], 0, 120), $l['seq'] ?: null,
                mb_substr((string) $l['payer'], 0, 190) ?: null, $l['paid_at'], $l['amount'], $l['discount'], $l['net'],
                $l['organization_id'], $l['transaction_id'], $l['status'], mb_substr((string) $l['message'], 0, 255),
            ]);
        }
        return $id;
    } catch (Throwable $e) {
        error_log('[SurgeBox settlement import log] ' . $e->getMessage() . ' (run database/migration_v5_24_settlement_import.sql)');
        return null;
    }
}

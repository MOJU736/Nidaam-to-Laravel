<?php

namespace App\Services\Documents;

use ZipArchive;

/**
 * Writes a simple one-sheet .xlsx (text and numbers, a bold style for headings) with PHP's zip support - no spreadsheet library.
 * Rows are lists of cells; a cell is a string, a number, null, or ['text', 'b'] for a bold cell.
 */
class XlsxWriter
{
    /** The file's bytes. @param array<int, array<int, mixed>> $rows */
    public function make(array $rows, string $sheetName = 'Sheet1'): string
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $name = htmlspecialchars(mb_substr(preg_replace('/[\\\\\/?*\[\]:]/', ' ', $sheetName) ?: 'Sheet1', 0, 31), ENT_XML1);
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="'.$name.'" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
        $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs></styleSheet>');

        $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        foreach (array_values($rows) as $r => $cells) {
            $x .= '<row r="'.($r + 1).'">';
            foreach (array_values($cells) as $c => $cell) {
                $bold = is_array($cell);
                $v = $bold ? ($cell[0] ?? '') : $cell;
                if ($v === null || $v === '') {
                    continue;
                }
                $ref = $this->col($c).($r + 1);
                $s = $bold ? ' s="1"' : '';
                if (is_int($v) || is_float($v)) {
                    $x .= "<c r=\"$ref\"$s><v>$v</v></c>";
                } else {
                    $x .= "<c r=\"$ref\"$s t=\"inlineStr\"><is><t xml:space=\"preserve\">".htmlspecialchars((string) $v, ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8').'</t></is></c>';
                }
            }
            $x .= '</row>';
        }
        $zip->addFromString('xl/worksheets/sheet1.xml', $x.'</sheetData></worksheet>');
        $zip->close();
        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return $bytes;
    }

    protected function col(int $i): string
    {
        $s = '';
        for ($i++; $i > 0; $i = intdiv($i - 1, 26)) {
            $s = chr(65 + ($i - 1) % 26).$s;
        }

        return $s;
    }

    /** Every <table> in an HTML fragment as sheet rows (header cells bold, a blank row between tables, an optional title block first). */
    public function rowsFromHtml(string $html, ?string $title = null, ?string $subtitle = null): array
    {
        $rows = [];
        if ($title) {
            $rows[] = [[$title]];
        }
        if ($subtitle) {
            $rows[] = [$subtitle];
        }
        if ($rows) {
            $rows[] = [];
        }
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8"><body>'.$html.'</body>', LIBXML_NOWARNING | LIBXML_NOERROR);
        foreach ($dom->getElementsByTagName('table') as $table) {
            foreach ($table->getElementsByTagName('tr') as $tr) {
                $line = [];
                foreach ($tr->childNodes as $cell) {
                    if (! in_array($cell->nodeName, ['td', 'th'], true)) {
                        continue;
                    }
                    $text = trim(preg_replace('/\s+/u', ' ', str_replace("\xC2\xA0", ' ', $cell->textContent)));
                    $isHead = $cell->nodeName === 'th' || $tr->parentNode->nodeName === 'thead' || $tr->parentNode->nodeName === 'tfoot' || str_contains((string) $tr->getAttribute('class'), 'ds-group');
                    $v = preg_match('/^-?\d+(\.\d+)?$/', $text) && strlen($text) < 15 && ! preg_match('/^0\d/', $text) ? (str_contains($text, '.') ? (float) $text : (int) $text) : $text;
                    $line[] = $isHead ? [$v] : $v;
                    for ($s = 1; $s < max(1, (int) $cell->getAttribute('colspan')); $s++) {
                        $line[] = null;
                    }
                }
                if ($line) {
                    $rows[] = $line;
                }
            }
            $rows[] = [];
        }

        return $rows;
    }
}

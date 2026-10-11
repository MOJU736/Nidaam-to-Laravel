<?php

namespace App\Services\Settings;

use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

/**
 * Reads the cell values of an .xlsx workbook with nothing but PHP's zip and XML support (no spreadsheet library needed).
 * Gives each sheet as a list of rows; each row is a list of values (string, number-as-string or null) by column position.
 * Formulas give their last saved result; dates come back as Excel serial numbers (the caller turns them into dates).
 */
class XlsxReader
{
    /** @return array<string, array<int, array<int, ?string>>> sheet name (lower case) => rows */
    public function read(string $path): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new RuntimeException('not a zip file');
        }
        try {
            $workbook = $this->xml($zip, 'xl/workbook.xml');
            $rels = [];
            foreach ($this->xml($zip, 'xl/_rels/workbook.xml.rels')->Relationship as $r) {
                $rels[(string) $r['Id']] = (string) $r['Target'];
            }
            $strings = $this->sharedStrings($zip);
            $out = [];
            $ns = $workbook->getNamespaces(true);
            foreach ($workbook->sheets->sheet as $sheet) {
                $rid = (string) $sheet->attributes($ns['r'] ?? 'http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
                $target = $rels[$rid] ?? null;
                if (! $target) {
                    continue;
                }
                $file = str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/'.$target;
                $out[strtolower(trim((string) $sheet['name']))] = $this->rows($this->xml($zip, $file), $strings);
            }

            return $out;
        } finally {
            $zip->close();
        }
    }

    protected function xml(ZipArchive $zip, string $name): SimpleXMLElement
    {
        $raw = $zip->getFromName($name);
        if ($raw === false) {
            throw new RuntimeException("missing $name");
        }
        $x = simplexml_load_string($raw, SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT);
        if ($x === false) {
            throw new RuntimeException("unreadable $name");
        }

        return $x;
    }

    protected function sharedStrings(ZipArchive $zip): array
    {
        if ($zip->locateName('xl/sharedStrings.xml') === false) {
            return [];
        }
        $out = [];
        foreach ($this->xml($zip, 'xl/sharedStrings.xml')->si as $si) {
            $out[] = $this->text($si);
        }

        return $out;
    }

    /** Plain text of a string item: either <t> or several rich-text <r><t> runs (phonetic runs skipped). */
    protected function text(SimpleXMLElement $si): string
    {
        if (isset($si->t)) {
            return (string) $si->t;
        }
        $s = '';
        foreach ($si->r as $run) {
            $s .= (string) $run->t;
        }

        return $s;
    }

    protected function rows(SimpleXMLElement $sheet, array $strings): array
    {
        $rows = [];
        foreach ($sheet->sheetData->row ?? [] as $row) {
            $r = (int) $row['r'] ?: count($rows) + 1;
            $cells = [];
            $next = 0;
            foreach ($row->c as $c) {
                $col = isset($c['r']) ? $this->column((string) $c['r']) : $next;
                $next = $col + 1;
                $cells[$col] = $this->value($c, $strings);
            }
            if ($cells) {
                $line = array_fill(0, max(array_keys($cells)) + 1, null);
                foreach ($cells as $i => $v) {
                    $line[$i] = $v;
                }
                $rows[$r - 1] = $line;
            }
        }
        if (! $rows) {
            return [];
        }
        // rows that were empty in the sheet still count for the row numbers shown in messages
        $all = [];
        for ($i = 0; $i <= max(array_keys($rows)); $i++) {
            $all[] = $rows[$i] ?? [];
        }

        return $all;
    }

    protected function value(SimpleXMLElement $c, array $strings): ?string
    {
        $type = (string) $c['t'];
        if ($type === 'inlineStr') {
            return isset($c->is) ? $this->text($c->is) : null;
        }
        if (! isset($c->v)) {
            return null;
        }
        $v = (string) $c->v;
        if ($type === 's') {
            return $strings[(int) $v] ?? null;
        }
        if ($type === 'b') {
            return $v === '1' ? '1' : '0';
        }

        return $v === '' ? null : $v;
    }

    /** "C7" → 2 (zero based column). */
    protected function column(string $ref): int
    {
        preg_match('/^([A-Za-z]+)/', $ref, $m);
        $n = 0;
        foreach (str_split(strtoupper($m[1] ?? 'A')) as $ch) {
            $n = $n * 26 + (ord($ch) - 64);
        }

        return $n - 1;
    }
}

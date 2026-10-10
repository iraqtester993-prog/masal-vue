<?php

namespace App\Services;

use DateTimeImmutable;
use DOMDocument;
use DOMXPath;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use XMLReader;
use ZipArchive;

class ImportFileReader
{
    private const MAX_BYTES = 15000000;

    private const MAX_XML_BYTES = 40000000;

    private const MAX_RAW_ROWS = 53000;

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['file' => $message]);
    }

    /** @return array<array{name:string,rows:array<array<string>>}> */
    public function read(UploadedFile $file): array
    {
        if (! $file->isValid() || $file->getSize() > self::MAX_BYTES) {
            $this->invalid('الحد الأقصى للملف 15 ميغابايت.');
        }
        $extension = mb_strtolower(pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION));
        if (! in_array($extension, ['txt', 'csv', 'tsv', 'xlsx'], true)) {
            $this->invalid('اختر ملف TXT أو CSV أو XLSX.');
        }
        if ($extension === 'xlsx') {
            return $this->excel($file);
        }
        $text = file_get_contents($file->getPathname());
        if (str_starts_with($text, "\xFF\xFE") || str_starts_with($text, "\xFE\xFF")) {
            $text = mb_convert_encoding(substr($text, 2), 'UTF-8', str_starts_with($text, "\xFF\xFE") ? 'UTF-16LE' : 'UTF-16BE');
        }
        if (! mb_check_encoding($text, 'UTF-8')) {
            $this->invalid('احفظ النص بترميز UTF-8 أو Unicode قبل الاستيراد.');
        }

        return [['name' => basename($file->getClientOriginalName()), 'rows' => $this->delimited($text)]];
    }

    /** @return array<array<string>> */
    public function delimited(string $text): array
    {
        $text = preg_replace('/^\x{FEFF}/u', '', $text);
        $first = preg_split('/\r?\n/', $text, 2)[0];
        $delimiter = str_contains($first, "\t") ? "\t" : (str_contains($first, ';') ? ';' : ',');
        $rows = [];
        $row = [];
        $cell = '';
        $quoted = false;
        for ($index = 0, $length = strlen($text); $index < $length; $index++) {
            $character = $text[$index];
            if ($character === '"') {
                if ($quoted && ($text[$index + 1] ?? '') === '"') {
                    $cell .= '"';
                    $index++;
                } else {
                    $quoted = ! $quoted;
                }
            } elseif ($character === $delimiter && ! $quoted) {
                $row[] = trim($cell);
                $cell = '';
                if (count($row) > 128) {
                    $this->invalid('عدد أعمدة الملف يتجاوز الحد المسموح.');
                }
            } elseif (($character === "\r" || $character === "\n") && ! $quoted) {
                if ($character === "\r" && ($text[$index + 1] ?? '') === "\n") {
                    $index++;
                }
                $row[] = trim($cell);
                $this->appendRow($rows, $row);
                $row = [];
                $cell = '';
            } else {
                $cell .= $character;
            }
            if (strlen($cell) > 20000) {
                $this->invalid('قيمة في الملف تتجاوز الحد المسموح.');
            }
        }
        if ($quoted) {
            $this->invalid('علامة اقتباس غير مغلقة.');
        }
        $row[] = trim($cell);
        $this->appendRow($rows, $row);

        return $rows;
    }

    private function appendRow(array &$rows, array $row): void
    {
        if (array_filter($row, fn (string $cell): bool => $cell !== '')) {
            $rows[] = $row;
        }
        if (count($rows) > self::MAX_RAW_ROWS) {
            $this->invalid('الحد الأقصى 50,000 بطاقة لكل رفع.');
        }
    }

    private function document(string $xml): DOMDocument
    {
        if (preg_match('/<!\s*(DOCTYPE|ENTITY)/i', $xml)) {
            $this->invalid('تعريف الكيانات الخارجية غير مسموح في الملف.');
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument;
            if (! $document->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT)) {
                $this->invalid('بنية Excel غير صالحة.');
            }

            return $document;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /** @return array<array{name:string,rows:array<array<string>>}> */
    private function excel(UploadedFile $file): array
    {
        $zip = new ZipArchive;
        if ($zip->open($file->getPathname(), ZipArchive::RDONLY) !== true) {
            $this->invalid('ملف Excel غير صالح.');
        }
        try {
            if ($zip->numFiles > 3000) {
                $this->invalid('ملف Excel كبير جدًا.');
            }
            $files = [];
            $total = 0;
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                $total += $stat['size'];
                if ($total > self::MAX_XML_BYTES) {
                    $this->invalid('محتوى Excel يتجاوز 40 ميغابايت.');
                }
                if (preg_match('#^xl/(worksheets/sheet\d+|sharedStrings|styles|workbook)\.xml$#', $stat['name'])) {
                    if (isset($files[$stat['name']])) {
                        $this->invalid('ملف Excel يتضمن أجزاء مكررة.');
                    }
                    $data = $zip->getFromIndex($index, $stat['size'] + 1);
                    if ($data === false || strlen($data) !== $stat['size'] || preg_match('/<!\s*(DOCTYPE|ENTITY)/i', $data)) {
                        $this->invalid('بنية Excel غير صالحة.');
                    }
                    $files[$stat['name']] = $data;
                }
            }
        } finally {
            $zip->close();
        }
        $strings = [];
        if (isset($files['xl/sharedStrings.xml'])) {
            $document = $this->document($files['xl/sharedStrings.xml']);
            foreach ((new DOMXPath($document))->query('//*[local-name()="si"]') as $item) {
                $strings[] = $item->textContent;
            }
            unset($document);
        }
        $dateStyles = [];
        $formats = [];
        if (isset($files['xl/styles.xml'])) {
            $document = $this->document($files['xl/styles.xml']);
            $xpath = new DOMXPath($document);
            foreach ($xpath->query('//*[local-name()="numFmt"]') as $format) {
                $formats[$format->getAttribute('numFmtId')] = $format->getAttribute('formatCode');
            }
            foreach ($xpath->query('//*[local-name()="cellXfs"]/*') as $style) {
                $id = (int) $style->getAttribute('numFmtId');
                $dateStyles[] = ($id >= 14 && $id <= 22) || (bool) preg_match('/[yd]/i', $formats[$id] ?? '');
            }
            unset($document);
        }
        $date1904 = false;
        if (isset($files['xl/workbook.xml'])) {
            $document = $this->document($files['xl/workbook.xml']);
            foreach ((new DOMXPath($document))->query('//*[local-name()="workbookPr"]') as $properties) {
                $date1904 = in_array($properties->getAttribute('date1904'), ['1', 'true'], true);
            }
            unset($document);
        }
        $result = [];
        $rowCount = 0;
        foreach ($files as $name => $content) {
            if (! str_starts_with($name, 'xl/worksheets/')) {
                continue;
            }
            $reader = new XMLReader;
            $rows = [];
            $previous = libxml_use_internal_errors(true);
            try {
                if (! $reader->XML($content, null, LIBXML_NONET | LIBXML_COMPACT)) {
                    $this->invalid('بنية Excel غير صالحة.');
                }
                while ($reader->read()) {
                    if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'row') {
                        continue;
                    }
                    $document = $this->document($reader->readOuterXml());
                    $xpath = new DOMXPath($document);
                    $cells = [];
                    foreach ($xpath->query('//*[local-name()="row"]/*[local-name()="c"]') as $cell) {
                        $column = preg_replace('/\d/', '', $cell->getAttribute('r') ?: 'A');
                        $offset = 0;
                        foreach (str_split($column) as $letter) {
                            $offset = $offset * 26 + ord($letter) - 64;
                        }
                        if ($offset < 1 || $offset > 128) {
                            $this->invalid('عدد أعمدة الملف يتجاوز الحد المسموح.');
                        }
                        if ($xpath->query('*[local-name()="f"]', $cell)->length) {
                            $this->invalid('استبدل المعادلات بقيم ثابتة قبل الاستيراد.');
                        }
                        $raw = $xpath->query('*[local-name()="v"]', $cell)->item(0)?->textContent ?? '';
                        $type = $cell->getAttribute('t');
                        $value = match ($type) {
                            's' => $strings[(int) $raw] ?? '', 'inlineStr' => $xpath->query('*[local-name()="is"]', $cell)->item(0)?->textContent ?? '', default => $raw
                        };
                        if (($type === '' || $type === 'n') && preg_match('/^\d{16,}$/', $raw)) {
                            $this->invalid('الأرقام الطويلة يجب حفظها كنص في Excel لحماية رموز البطاقات.');
                        }
                        if (! in_array($type, ['s', 'inlineStr'], true) && ($dateStyles[(int) $cell->getAttribute('s')] ?? false) && $raw !== '') {
                            if (! is_numeric($raw) || abs((float) $raw) > 3650000) {
                                $this->invalid('تاريخ Excel غير صالح.');
                            }
                            $value = (new DateTimeImmutable($date1904 ? '1904-01-01' : '1899-12-30'))->modify(((int) floor((float) $raw)).' days')->format('Y-m-d');
                        }
                        $cells[$offset - 1] = (string) $value;
                    }
                    if ($cells) {
                        $row = array_fill(0, max(array_keys($cells)) + 1, '');
                        foreach ($cells as $offset => $value) {
                            $row[$offset] = $value;
                        } $this->appendRow($rows, $row);
                    }
                }
                if (libxml_get_errors()) {
                    $this->invalid('بنية Excel غير صالحة.');
                }
            } finally {
                $reader->close();
                libxml_clear_errors();
                libxml_use_internal_errors($previous);
            }
            $rowCount += count($rows);
            if ($rowCount > self::MAX_RAW_ROWS) {
                $this->invalid('الحد الأقصى 50,000 بطاقة لكل رفع.');
            }
            $result[] = ['name' => basename($file->getClientOriginalName()).' / '.basename($name), 'rows' => $rows];
        }
        if (! $result) {
            $this->invalid('ملف Excel لا يحتوي أوراق بيانات.');
        }

        return $result;
    }
}

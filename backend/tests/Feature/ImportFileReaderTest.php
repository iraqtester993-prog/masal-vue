<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Services\ImportFileReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Tests\Support\CreatesAccounts;
use Tests\TestCase;
use ZipArchive;

class ImportFileReaderTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    private function excel(string $rows, array $extra = []): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'masal-reader-');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('xl/worksheets/sheet1.xml', '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.$rows.'</sheetData></worksheet>');
        foreach ($extra as $name => $xml) {
            $zip->addFromString($name, $xml);
        }
        $zip->close();
        $bytes = file_get_contents($path);
        unlink($path);

        return UploadedFile::fake()->createWithContent('recorded.xlsx', $bytes);
    }

    public function test_csv_preserves_leading_zero_and_quoted_newline_and_escaped_quotes(): void
    {
        $rows = app(ImportFileReader::class)->delimited("\xEF\xBB\xBFSerial,Pin,Reference\r\n000012,0000012345,\"line1\nline2 \"\"quoted\"\"\"\r\n");
        $this->assertSame([['Serial', 'Pin', 'Reference'], ['000012', '0000012345', "line1\nline2 \"quoted\""]], $rows);
    }

    public function test_unclosed_csv_quotes_fail_without_accepting_partial_rows(): void
    {
        $this->expectException(ValidationException::class);
        app(ImportFileReader::class)->delimited('Serial,Pin'."\n".'0001,"0002');
    }

    public function test_xlsx_shared_and_inline_text_keep_long_codes_and_sparse_columns(): void
    {
        $file = $this->excel('<row r="1"><c r="A1" t="s"><v>0</v></c><c r="C1" t="inlineStr"><is><t>0000123456789012345678</t></is></c></row>', ['xl/sharedStrings.xml' => '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si><t>00000001</t></si></sst>']);
        $this->assertSame(['00000001', '', '0000123456789012345678'], app(ImportFileReader::class)->read($file)[0]['rows'][0]);
    }

    public function test_xlsx_date_cell_and_1904_calendar_are_resolved_without_changing_text(): void
    {
        $file = $this->excel('<row r="1"><c r="A1" s="1"><v>1</v></c></row>', ['xl/styles.xml' => '<styleSheet><cellXfs><xf numFmtId="0"/><xf numFmtId="14"/></cellXfs></styleSheet>', 'xl/workbook.xml' => '<workbook><workbookPr date1904="1"/></workbook>']);
        $this->assertSame('1904-01-02', app(ImportFileReader::class)->read($file)[0]['rows'][0][0]);
    }

    public function test_xlsx_formula_even_with_cached_value_is_rejected(): void
    {
        $file = $this->excel('<row r="1"><c r="A1"><f>1+1</f><v>2</v></c></row>');
        $this->expectException(ValidationException::class);
        app(ImportFileReader::class)->read($file);
    }

    public function test_xlsx_long_numeric_cell_is_rejected_instead_of_silently_rounding_pin(): void
    {
        $file = $this->excel('<row r="1"><c r="A1" t="n"><v>1234567890123456</v></c></row>');
        $this->expectException(ValidationException::class);
        app(ImportFileReader::class)->read($file);
    }

    public function test_external_entity_and_excessive_excel_columns_are_rejected(): void
    {
        foreach ([$this->excel('<row r="1"><c r="XFD1"><v>1</v></c></row>'), $this->excel('', ['xl/sharedStrings.xml' => '<!DOCTYPE sst [<!ENTITY x SYSTEM "file:///not-readable-fixture">]><sst><si>&x;</si></sst>'])] as $file) {
            try {
                app(ImportFileReader::class)->read($file);
                $this->fail('Unsafe workbook accepted.');
            } catch (ValidationException $error) {
                $this->assertArrayHasKey('file', $error->errors());
            }
        }
    }

    public function test_zip_expansion_limit_checks_unused_entries_before_reading_workbook(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'masal-zip-');
        $large = tempnam(sys_get_temp_dir(), 'masal-expanded-');
        try {
            $stream = fopen($large, 'wb');
            $chunk = str_repeat('X', 1000000);
            for ($index = 0; $index < 40; $index++) {
                fwrite($stream, $chunk);
            } fwrite($stream, 'X');
            fclose($stream);
            $zip = new ZipArchive;
            $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
            $zip->addFromString('xl/worksheets/sheet1.xml', '<worksheet><sheetData><row><c r="A1"><v>1</v></c></row></sheetData></worksheet>');
            $zip->addFile($large, 'unused.txt');
            $zip->close();
            $file = UploadedFile::fake()->createWithContent('recorded.xlsx', file_get_contents($path));
        } finally {
            unlink($path);
            unlink($large);
        }
        $this->expectException(ValidationException::class);
        app(ImportFileReader::class)->read($file);
    }

    public function test_read_endpoint_enforces_main_scope_and_returns_private_utf8_sheets(): void
    {
        $root = $this->account(AccountType::System);
        $main = $this->account(AccountType::MainAgent, $root);
        $foreign = $this->account(AccountType::MainAgent, $root);
        $this->asPortalUser($this->userFor($main));
        $response = $this->post('/api/v1/stock/read', ['account_id' => $main->id, 'file' => UploadedFile::fake()->createWithContent('cards.csv', "Serial,Pin\n0001,0002")], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.sheets.0.rows.1.1', '0002');
        $this->assertEqualsCanonicalizing(['private', 'no-store'], array_map('trim', explode(',', $response->headers->get('Cache-Control'))));
        $this->post('/api/v1/stock/read', ['account_id' => $foreign->id, 'file' => UploadedFile::fake()->createWithContent('cards.csv', '001,002')], ['Accept' => 'application/json'])->assertNotFound();
    }

    public function test_fifteen_megabyte_boundary_and_unsupported_extension_fail_cleanly(): void
    {
        foreach ([UploadedFile::fake()->create('cards.csv', 14649), UploadedFile::fake()->createWithContent('cards.exe', '1,2')] as $file) {
            try {
                app(ImportFileReader::class)->read($file);
                $this->fail('Invalid file accepted.');
            } catch (ValidationException $error) {
                $this->assertArrayHasKey('file', $error->errors());
            }
        }
    }
}

<?php

namespace App\Services\Backups;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class SnapshotArchive
{
    public function __construct(private SnapshotSchema $schema) {}

    public function create(Connection $db, string $path): array
    {
        $disk = Storage::disk('local');
        $disk->makeDirectory(dirname($path));
        $stream = fopen($disk->path($path), 'xb');
        if ($stream === false) {
            throw new RuntimeException('تعذر إنشاء ملف النسخة.');
        }
        @chmod($disk->path($path), 0600);
        $hash = hash_init('sha256');
        $counts = [];
        $files = 0;
        $description = $this->schema->describe($db);
        $write = function (array $record) use ($stream, $hash): void {
            $line = json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
            $this->write($stream, $line);
            hash_update($hash, $line);
        };
        try {
            $write(['format' => SnapshotSchema::FORMAT, 'version' => SnapshotSchema::VERSION, 'created_at' => now()->toIso8601String(), 'schema' => $description]);
            $db->transaction(function () use ($db, $description, $write, &$counts, &$files): void {
                foreach ($this->schema->order($description['tables']) as $table) {
                    $counts[$table] = 0;
                    $write($this->encrypted(['kind' => 'table', 'table' => $table]));
                    foreach ($this->schema->rows($db, $table, $description['tables'][$table]['primary']) as $row) {
                        $this->row($table, $row, $write);
                        $counts[$table]++;
                        foreach ($this->schema->assetPaths($table, $row) as $asset) {
                            $this->asset($asset, $write);
                            $files++;
                        }
                    }
                }
            });
            $digest = hash_final($hash);
            $manifest = ['format' => SnapshotSchema::FORMAT, 'version' => SnapshotSchema::VERSION, 'schema_fingerprint' => $description['fingerprint'], 'driver' => $description['driver'], 'migrations' => $description['migrations'], 'counts' => $counts, 'files' => $files, 'content_sha256' => $digest];
            $signature = $this->signature($manifest);
            $this->write($stream, json_encode(['manifest' => $manifest, 'signature' => $signature], JSON_THROW_ON_ERROR)."\n");
            fclose($stream);

            return ['manifest' => $manifest, 'sha256' => hash_file('sha256', $disk->path($path)), 'bytes' => filesize($disk->path($path))];
        } catch (\Throwable $failure) {
            fclose($stream);
            $disk->delete($path);
            throw $failure;
        }
    }

    /** @param callable(array): void|null $consume */
    public function read(string $path, ?callable $consume = null): array
    {
        $stream = fopen(Storage::disk('local')->path($path), 'rb');
        if ($stream === false) {
            throw new RuntimeException('ملف النسخة غير موجود.');
        }
        $hash = hash_init('sha256');
        $header = null;
        $footer = null;
        $counts = [];
        $files = 0;
        $maxLine = 1024 * 1024;
        $partialRow = null;
        $partialField = null;
        $fieldLength = 0;
        $rowBytes = 0;
        try {
            while (($line = $this->line($stream, $maxLine)) !== null) {
                if ($header === null && ! str_ends_with($line, "\n")) {
                    throw new RuntimeException('هذه ليست نسخة Laravel موقعة كاملة. النسخ المحلية القديمة لا يمكن استرجاعها هنا.');
                }
                if (strlen($line) > $maxLine || ! str_ends_with($line, "\n")) {
                    throw new RuntimeException('سجل النسخة ناقص أو يتجاوز الحد المدعوم.');
                }
                $record = json_decode($line, true, 128, JSON_THROW_ON_ERROR);
                if (! is_array($record)) {
                    throw new RuntimeException('صيغة النسخة غير صالحة.');
                }
                if ($header === null) {
                    if (($record['format'] ?? null) !== SnapshotSchema::FORMAT || ($record['version'] ?? null) !== SnapshotSchema::VERSION || ! is_array($record['schema']['tables'] ?? null)) {
                        throw new RuntimeException('هذه ليست نسخة Laravel موقعة. النسخ المحلية القديمة لا يمكن استرجاعها هنا.');
                    }
                    $header = $record;
                    hash_update($hash, $line);

                    continue;
                }
                if (isset($record['manifest'])) {
                    if ($footer !== null || ! feof($stream) && fgets($stream, 2) !== false) {
                        throw new RuntimeException('يوجد محتوى بعد توقيع النسخة.');
                    }
                    $footer = $record;
                    break;
                }
                hash_update($hash, $line);
                $decoded = $this->decrypt($record);
                if (($decoded['kind'] ?? null) === 'row-start') {
                    if ($partialRow !== null || ! is_array($decoded['row'] ?? null)) {
                        throw new RuntimeException('سجل مجزأ غير صالح.');
                    }
                    $partialRow = ['kind' => 'row', 'table' => $decoded['table'], 'row' => $decoded['row']];
                    $rowBytes = strlen(json_encode($decoded['row'], JSON_THROW_ON_ERROR));

                    continue;
                }
                if (($decoded['kind'] ?? null) === 'field-start') {
                    if ($partialRow === null || $partialField !== null || ! is_string($decoded['field'] ?? null) || array_key_exists($decoded['field'], $partialRow['row']) || ! is_int($decoded['bytes'] ?? null) || $decoded['bytes'] < 1 || config('backups.record_bytes') < $decoded['bytes'] + $rowBytes) {
                        throw new RuntimeException('حقل السجل المجزأ غير صالح.');
                    }
                    $partialField = $decoded['field'];
                    $fieldLength = $decoded['bytes'];
                    $partialRow['row'][$partialField] = '';
                    $rowBytes += $fieldLength;

                    continue;
                }
                if (($decoded['kind'] ?? null) === 'field-chunk') {
                    $chunk = base64_decode($decoded['data'] ?? '', true);
                    if ($partialField === null || $chunk === false || strlen($chunk) > 65536 || strlen($partialRow['row'][$partialField]) + strlen($chunk) > $fieldLength) {
                        throw new RuntimeException('جزء الحقل غير صالح.');
                    }
                    $partialRow['row'][$partialField] .= $chunk;

                    continue;
                }
                if (($decoded['kind'] ?? null) === 'field-end') {
                    if ($partialField === null || strlen($partialRow['row'][$partialField]) !== $fieldLength) {
                        throw new RuntimeException('حقل النسخة ناقص.');
                    }
                    $partialField = null;

                    continue;
                }
                if (($decoded['kind'] ?? null) === 'row-end') {
                    if ($partialRow === null || $partialField !== null) {
                        throw new RuntimeException('سجل النسخة ناقص.');
                    }
                    $decoded = $partialRow;
                    $partialRow = null;
                } elseif ($partialRow !== null) {
                    throw new RuntimeException('انقطع تسلسل السجل المجزأ.');
                }
                if (($decoded['kind'] ?? null) === 'table') {
                    $table = $decoded['table'] ?? '';
                    if (! isset($header['schema']['tables'][$table]) || isset($counts[$table])) {
                        throw new RuntimeException('ترتيب الجداول أو تعريفها غير صالح.');
                    }
                    $counts[$table] = 0;
                } elseif (($decoded['kind'] ?? null) === 'row') {
                    $table = $decoded['table'] ?? '';
                    if (! isset($counts[$table]) || ! is_array($decoded['row'] ?? null)) {
                        throw new RuntimeException('سجل جدول غير معروف.');
                    }
                    $counts[$table]++;
                } elseif (($decoded['kind'] ?? null) === 'file-start') {
                    $files++;
                } elseif (! in_array($decoded['kind'] ?? null, ['file-chunk', 'file-end'], true)) {
                    throw new RuntimeException('نوع سجل النسخة غير معروف.');
                }
                if ($consume !== null) {
                    $consume($decoded);
                }
            }
            if ($partialRow !== null || $header === null || ! is_array($footer['manifest'] ?? null) || ! is_string($footer['signature'] ?? null)) {
                throw new RuntimeException('النسخة ناقصة أو بدون توقيع.');
            }
            $manifest = $footer['manifest'];
            if (! hash_equals($this->signature($manifest), $footer['signature']) ||
                ! hash_equals(hash_final($hash), $manifest['content_sha256'] ?? '') ||
                ($manifest['schema_fingerprint'] ?? null) !== $header['schema']['fingerprint'] ||
                ($manifest['counts'] ?? null) !== $counts || ($manifest['files'] ?? null) !== $files ||
                array_diff_key($header['schema']['tables'], $counts) !== []) {
                throw new RuntimeException('فشل فحص توقيع النسخة أو عدد سجلاتها.');
            }

            return ['header' => $header, 'manifest' => $manifest];
        } finally {
            fclose($stream);
        }
    }

    private function encrypted(array $record): array
    {
        $json = json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (strlen($json) > 256 * 1024) {
            throw new RuntimeException('أحد السجلات يتجاوز حد النسخ المدعوم.');
        }

        return ['encrypted' => Crypt::encryptString(base64_encode(gzencode($json, 6)))];
    }

    private function decrypt(array $record): array
    {
        if (! is_string($record['encrypted'] ?? null)) {
            throw new RuntimeException('سجل غير مشفر في النسخة.');
        }
        try {
            $packed = base64_decode(Crypt::decryptString($record['encrypted']), true);
            $json = $packed === false ? false : gzdecode($packed, 256 * 1024);
            if ($json === false) {
                throw new RuntimeException('سجل النسخة غير صالح.');
            }

            return json_decode($json, true, 128, JSON_THROW_ON_ERROR);
        } catch (\Throwable $failure) {
            throw new RuntimeException('فشل فك السجل؛ النسخة تالفة أو تخص مفتاح تشفير مختلف.', previous: $failure);
        }
    }

    private function asset(string $path, callable $write): void
    {
        $disk = Storage::disk('local');
        if (! $disk->exists($path)) {
            throw new RuntimeException('مرفق مسجل في قاعدة البيانات غير موجود؛ لم تكتمل النسخة.');
        }
        $actual = str_replace('\\', '/', realpath($disk->path($path)) ?: '');
        $expected = rtrim(str_replace('\\', '/', realpath($disk->path('')) ?: ''), '/').'/'.$path;
        if (DIRECTORY_SEPARATOR === '\\') {
            $actual = strtolower($actual);
            $expected = strtolower($expected);
        }
        if ($actual === '' || $actual !== $expected || is_link($disk->path($path))) {
            throw new RuntimeException('المرفق خارج المسار الخاص المعتمد أو يمر برابط رمزي.');
        }
        $file = fopen($disk->path($path), 'rb');
        if ($file === false) {
            throw new RuntimeException('تعذر قراءة المرفق.');
        }
        $hash = hash_init('sha256');
        $bytes = 0;
        try {
            $write($this->encrypted(['kind' => 'file-start', 'path' => $path]));
            while (! feof($file)) {
                $chunk = fread($file, 65536);
                if ($chunk === false) {
                    throw new RuntimeException('تعذر إكمال قراءة المرفق.');
                }
                if ($chunk !== '') {
                    $bytes += strlen($chunk);
                    hash_update($hash, $chunk);
                    $write($this->encrypted(['kind' => 'file-chunk', 'data' => base64_encode($chunk)]));
                }
            }
            $write($this->encrypted(['kind' => 'file-end', 'bytes' => $bytes, 'sha256' => hash_final($hash)]));
        } finally {
            fclose($file);
        }
    }

    private function row(string $table, array $row, callable $write): void
    {
        $bytes = 0;
        foreach ($row as $value) {
            $bytes += is_string($value) ? strlen($value) : 16;
        }
        if ($bytes > config('backups.record_bytes') - 16384) {
            throw new RuntimeException('أحد سجلات قاعدة البيانات يتجاوز حد النسخ المدعوم.');
        }
        if ($bytes < 32768) {
            $write($this->encrypted(['kind' => 'row', 'table' => $table, 'row' => $row]));

            return;
        }
        $small = array_filter($row, fn ($value): bool => ! is_string($value) || strlen($value) <= 512);
        $write($this->encrypted(['kind' => 'row-start', 'table' => $table, 'row' => $small]));
        foreach (array_diff_key($row, $small) as $field => $value) {
            $write($this->encrypted(['kind' => 'field-start', 'field' => $field, 'bytes' => strlen($value)]));
            for ($position = 0; $position < strlen($value); $position += 65536) {
                $write($this->encrypted(['kind' => 'field-chunk', 'data' => base64_encode(substr($value, $position, 65536))]));
            }
            $write($this->encrypted(['kind' => 'field-end']));
        }
        $write($this->encrypted(['kind' => 'row-end']));
    }

    /** @param resource $stream */
    private function line($stream, int $limit): ?string
    {
        $line = '';
        while (($part = fgets($stream, 65537)) !== false) {
            $line .= $part;
            if (strlen($line) > $limit) {
                throw new RuntimeException('سطر النسخة يتجاوز الحد المدعوم.');
            }
            if (str_ends_with($part, "\n")) {
                return $line;
            }
        }

        return $line === '' ? null : $line;
    }

    private function signature(array $manifest): string
    {
        $key = config('app.key');
        if (str_starts_with($key, 'base64:')) {
            $key = base64_decode(substr($key, 7), true);
        }

        return hash_hmac('sha256', json_encode($manifest, JSON_THROW_ON_ERROR), hash_hmac('sha256', SnapshotSchema::FORMAT, $key, true));
    }

    /** @param resource $stream */
    private function write($stream, string $data): void
    {
        $position = 0;
        while ($position < strlen($data)) {
            $written = fwrite($stream, substr($data, $position));
            if ($written === false || $written === 0) {
                throw new RuntimeException('تعذر حفظ النسخة كاملة.');
            }
            $position += $written;
        }
    }
}

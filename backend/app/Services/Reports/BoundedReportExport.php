<?php

namespace App\Services\Reports;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BoundedReportExport
{
    public function __construct(private ReportAccess $access, private ReportCatalog $catalog, private ReportQueries $queries) {}

    private function prepare(Request $request, array $filters, int $maximum): array
    {
        $this->access->require($request->user(), 'reports.view');
        $this->access->require($request->user(), ($filters['purpose'] ?? 'export') === 'print' ? 'reports.print' : 'reports.export');
        $this->access->validateFilters($request->user(), $filters, ($filters['section_ids'] ?? null) === ['network-archive']);
        $schemas = $this->catalog->allowed($request->user());
        $ids = $filters['section_ids'] ?? array_column($schemas, 'id');
        abort_unless(array_diff($ids, array_column($schemas, 'id')) === [], 404);
        $sections = [];
        $total = 0;
        foreach ($schemas as $schema) {
            if (! in_array($schema['id'], $ids, true) || (($filters['kind'] ?? 'all') !== 'all' && explode('-', $schema['id'])[0] !== $filters['kind'])) {
                continue;
            }
            $dataset = $this->queries->build($request->user(), $schema, $filters);
            $columns = $dataset?->schema['columns'] ?? $schema['columns'];
            if (isset($filters['visible_columns'])) {
                abort_unless(count($ids) === 1 && array_diff($filters['visible_columns'], array_column($columns, 'key')) === [], 422, 'أعمدة التصدير غير متاحة.');
                $columns = array_values(array_filter($columns, fn ($column): bool => in_array($column['key'], $filters['visible_columns'], true)));
            }
            $count = $dataset ? $dataset->filtered($filters)->reorder()->count() : 0;
            $total += $count;
            abort_if($total > $maximum, 422, 'عدد سجلات التصدير يتجاوز الحد الآمن ('.$maximum.'). حدد فترة أو جهة أصغر؛ لم يتم تصدير جزء من النتائج.');
            $sections[] = compact('schema', 'dataset', 'columns', 'count');
        }

        return [$sections, $total];
    }

    public function create(Request $request, array $filters): JsonResponse
    {
        $lock = Cache::lock('report-export:'.$request->user()->id, 900);
        abort_unless($lock->get(), 429, 'يوجد تصدير قيد التنفيذ لهذا الحساب.');
        try {
            $print = ($filters['purpose'] ?? 'export') === 'print';
            [$sections, $total] = $this->prepare($request, $filters, (int) config($print ? 'reports.print_rows' : 'reports.download_rows'));
            if (! $print && $total > (int) config('reports.inline_rows')) {
                $id = (string) Str::uuid();
                Cache::put('report-ticket:'.$id, ['user_id' => $request->user()->id, 'session_version' => $request->user()->session_version,
                    'session_hash' => hash('sha256', $request->session()->getId()), 'filters' => $filters], (int) config('reports.ticket_seconds'));

                return response()->json(['data' => ['sections' => [], 'download' => ['url' => '/api/v1/reports/exports/'.$id,
                    'name' => 'dananeer-report-'.now()->format('Y-m-d').'.csv', 'format' => 'csv', 'rows' => $total], 'currency' => 'IQD', 'generated_at' => now()->toISOString()]]);
            }
            $result = [];
            $bytes = 0;
            $readRows = 0;
            foreach ($sections as $section) {
                ['schema' => $schema, 'dataset' => $dataset, 'columns' => $columns] = $section;
                $rows = [];
                $keys = array_flip(array_column($columns, 'key'));
                if ($dataset) {
                    foreach ($dataset->filtered($filters)->lazy(250) as $record) {
                        abort_if(++$readRows > (int) config($print ? 'reports.print_rows' : 'reports.inline_rows'), 422, 'تغير حجم التقرير؛ أعد إعداد التصدير.');
                        $row = array_intersect_key($dataset->row($record), $keys);
                        $bytes += strlen(json_encode($row, JSON_THROW_ON_ERROR));
                        abort_if($bytes > 8 * 1024 * 1024, 422, 'حجم التقرير كبير. قلل الفترة أو عدد الأعمدة قبل التصدير.');
                        $rows[] = $row;
                    }
                }
                $result[] = ['id' => $schema['id'], 'title' => $schema['title'], 'available' => $dataset !== null,
                    'reason' => $dataset ? null : $this->queries->unavailableReason($schema['id']), 'snapshot' => $schema['snapshot'] ?? false,
                    'note' => $schema['note'] ?? '', 'columns' => $columns, 'rows' => $rows];
            }

            return response()->json(['data' => ['sections' => $result, 'currency' => 'IQD', 'generated_at' => now()->toISOString(), 'timezone' => 'Asia/Baghdad']]);
        } finally {
            $lock->release();
        }
    }

    public function download(Request $request, string $id): StreamedResponse
    {
        $ticket = Cache::get('report-ticket:'.$id);
        abort_unless($ticket && (int) $ticket['user_id'] === (int) $request->user()->id
            && (int) $ticket['session_version'] === (int) $request->user()->session_version
            && hash_equals($ticket['session_hash'], hash('sha256', $request->session()->getId())), 404, 'رابط التصدير منتهٍ أو غير متاح لهذا الحساب.');
        $lock = Cache::lock('report-export:'.$request->user()->id, 900);
        abort_unless($lock->get(), 429, 'يوجد تصدير قيد التنفيذ لهذا الحساب.');
        $connection = DB::connection();
        $stream = null;
        $started = false;
        try {
            if ($connection->getDriverName() === 'mysql' && $connection->transactionLevel() === 0) {
                $connection->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            }
            $connection->beginTransaction();
            $started = true;
            [$sections, $total] = $this->prepare($request, $ticket['filters'], (int) config('reports.download_rows'));
            $stream = tmpfile();
            abort_unless(is_resource($stream), 503, 'تعذر تجهيز ملف التقرير الخاص.');
            $temporary = stream_get_meta_data($stream)['uri'] ?? null;
            if (is_string($temporary)) {
                @chmod($temporary, 0600);
            }
            fwrite($stream, "\xEF\xBB\xBF");
            $written = 3;
            $rows = 0;
            $write = function (array $values) use ($stream, &$written): void {
                $bytes = fputcsv($stream, $values, ',', '"', '');
                abort_if($bytes === false, 503, 'تعذر إكمال ملف التقرير.');
                $written += $bytes;
                abort_if($written > 128 * 1024 * 1024, 422, 'حجم التقرير يتجاوز الحد الآمن. حدد فترة أصغر.');
            };
            foreach ($sections as $section) {
                $write([self::cell($section['schema']['title'])]);
                $write(array_map(fn ($column): string => self::cell($column['label'] ?? $column['title'] ?? $column['key']), $section['columns']));
                if ($section['dataset']) {
                    foreach ($section['dataset']->filtered($ticket['filters'])->lazy(250) as $record) {
                        abort_if(++$rows > (int) config('reports.download_rows'), 422, 'تغير حجم التقرير؛ حدد فترة أصغر.');
                        $row = $section['dataset']->row($record);
                        $write(array_map(fn ($column): string => self::cell($row[$column['key']] ?? ''), $section['columns']));
                    }
                }
                $write([]);
            }
            abort_unless($rows === $total, 409, 'تغيرت بيانات التقرير أثناء إعداده؛ أعد المحاولة.');
            $write(['اكتمل التصدير', (string) $rows]);
            $connection->rollBack();
            $started = false;
            $request->user()->refresh();
            $request->user()->unsetRelation('membership');
            abort_unless((int) $ticket['session_version'] === (int) $request->user()->session_version, 401);
            $this->access->require($request->user(), 'reports.export');
            rewind($stream);
            $complete = $stream;
            $stream = null;

            return response()->streamDownload(function () use ($complete): void {
                try {
                    fpassthru($complete);
                } finally {
                    fclose($complete);
                }
            }, 'dananeer-report-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=utf-8', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'X-Accel-Buffering' => 'no']);
        } finally {
            try {
                if ($started) {
                    $connection->rollBack();
                }
            } finally {
                try {
                    if (is_resource($stream)) {
                        fclose($stream);
                    }
                } finally {
                    $lock->release();
                }
            }
        }
    }

    private static function cell(mixed $value): string
    {
        $text = is_scalar($value) || $value === null ? (string) $value : json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        return preg_match('/^[\s\x00-\x20]*[=+@-]/u', $text) ? "'".$text : $text;
    }
}

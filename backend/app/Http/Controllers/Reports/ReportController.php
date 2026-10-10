<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\ReportRequest;
use App\Services\Reports\DashboardSummary;
use App\Services\Reports\ReportAccess;
use App\Services\Reports\ReportCatalog;
use App\Services\Reports\ReportQueries;
use Illuminate\Http\JsonResponse;

class ReportController extends Controller
{
    public function __construct(private ReportAccess $access, private ReportCatalog $catalog, private ReportQueries $queries, private DashboardSummary $dashboard) {}

    private function filters(ReportRequest $request, string $permission = 'reports.view'): array
    {
        $this->access->require($request->user(), $permission);
        $filters = $request->validated() + ['currency' => 'IQD'];
        $archiveOnly = $request->route('section') === 'network-archive'
            || (($filters['section_ids'] ?? null) === ['network-archive']);
        $this->access->validateFilters($request->user(), $filters, $archiveOnly);

        return $filters;
    }

    public function options(ReportRequest $request): JsonResponse
    {
        $this->filters($request);
        $actor = $request->user();
        $products = $this->access->products($actor)->with('provider')->orderBy('display_order')->get(['id', 'name', 'provider_id', 'currency']);
        $accounts = $this->access->accounts($actor)->orderBy('name')->get(['id', 'name', 'type', 'parent_id', 'city']);

        return response()->json(['data' => ['accounts' => $accounts, 'products' => $products->map(fn ($p): array => ['id' => $p->id, 'name' => $p->name, 'provider_id' => $p->provider_id, 'currency' => $p->currency]), 'providers' => $products->map(fn ($p): array => ['id' => $p->provider_id, 'name' => $p->provider->name])->unique('id')->values(), 'cities' => $accounts->pluck('city')->filter()->unique()->values(), 'currencies' => ['IQD'], 'groups' => ReportCatalog::GROUPS, 'sections' => $this->catalog->allowed($actor), 'timezone' => 'Asia/Baghdad']]);
    }

    public function summary(ReportRequest $request): JsonResponse
    {
        $filters = $this->filters($request);
        $sections = [];
        $schemas = $this->catalog->allowed($request->user());
        $ids = $filters['section_ids'] ?? array_column($schemas, 'id');
        abort_unless(array_diff($ids, array_column($schemas, 'id')) === [], 404);
        foreach ($schemas as $schema) {
            if (! in_array($schema['id'], $ids, true)) {
                continue;
            }
            if (($filters['kind'] ?? 'all') !== 'all' && explode('-', $schema['id'])[0] !== $filters['kind']) {
                continue;
            }
            $dataset = $this->queries->build($request->user(), $schema, $filters);
            $base = array_diff_key($filters, array_flip(['detail_filter', 'sort', 'direction', 'q']));
            $item = ($dataset?->schema ?? $schema) + ['available' => $dataset !== null, 'count' => $dataset ? $dataset->filtered($base)->reorder()->count() : null, 'reason' => $dataset ? null : $this->queries->unavailableReason($schema['id'])];
            $item['subcounts'] = [];
            $subfilters = match ($schema['id']) {
                'network' => ['main', 'branch', 'nested'],'network-pos' => ['active', 'inactive'],'inventory-cards' => ['available', 'issued', 'held'],default => []
            };
            foreach ($subfilters as $filter) {
                $item['subcounts'][$filter] = $dataset ? $dataset->filtered($base + ['detail_filter' => $filter])->reorder()->count() : null;
            }
            $sections[] = $item;
        }

        return response()->json(['data' => ['sections' => $sections, 'currency' => $filters['currency'], 'generated_at' => now()->toISOString(), 'timezone' => 'Asia/Baghdad']]);
    }

    public function rows(ReportRequest $request, string $section): JsonResponse
    {
        $filters = $this->filters($request);
        $schema = $this->catalog->section($request->user(), $section);
        $dataset = $this->queries->build($request->user(), $schema, $filters);
        abort_unless($dataset, 409, $this->queries->unavailableReason($section));

        return response()->json($dataset->page($filters) + ['section' => $dataset->schema, 'currency' => $filters['currency'], 'generated_at' => now()->toISOString()]);
    }

    public function row(ReportRequest $request, string $section, string $rowKey): JsonResponse
    {
        $filters = $this->filters($request);
        abort_if(strlen($rowKey) > 100, 404);
        $dataset = $this->queries->build($request->user(), $this->catalog->section($request->user(), $section), $filters);
        abort_unless($dataset, 409, $this->queries->unavailableReason($section));
        $record = $dataset->filtered($filters)->where('_key', $rowKey)->first();
        abort_unless($record, 404);

        $row = $dataset->row($record);
        $links = match ($section) {
            'sales' => [['sales-printing', 'sale', $row['id'] ?? null], ['sales-deliveries', 'tx', $row['id'] ?? null]],
            'inventory' => [['inventory-cards', 'batch', $row['id'] ?? null], ['inventory-invoices', 'batch', $row['id'] ?? null], ['claims', 'batch', $row['id'] ?? null], ['inventory-cancellations', 'batch', $row['id'] ?? null]],
            'inventory-cards' => [['sales', 'id', $row['sale'] ?? null]],
            'wallets-requests' => [['wallets-transfers', 'id', $row['transfer'] ?? null], ['inventory', 'id', $row['batch'] ?? null]],
            'support' => [['support-replies', 'ticket', $row['id'] ?? null]],
            default => [],
        };
        $eligible = collect($this->catalog->allowed($request->user()))->keyBy('id');
        $related = [];
        $base = array_diff_key($filters, array_flip(['q', 'sort', 'direction', 'page', 'detail_filter']));
        foreach ($links as [$id,$column,$value]) {
            if ($value === null || ! isset($eligible[$id])) {
                continue;
            }
            $linked = $this->queries->build($request->user(), $eligible[$id], $base);
            if (! $linked || ! in_array($column, array_column($linked->schema['columns'], 'key'), true)) {
                continue;
            }
            $query = $linked->filtered($base)->where($column, $value);
            $total = (clone $query)->reorder()->count();
            $related[] = ['id' => $id, 'title' => $linked->schema['title'], 'columns' => $linked->schema['columns'], 'total' => $total, 'rows' => $query->limit(20)->get()->map(fn ($r): array => $linked->row($r))->all()];
        }

        return response()->json(['data' => $row, 'related' => $related, 'columns' => $dataset->schema['columns'], 'section' => $dataset->schema, 'currency' => $filters['currency']]);
    }

    public function export(ReportRequest $request): JsonResponse
    {
        $filters = $this->filters($request);
        $this->access->require($request->user(), ($filters['purpose'] ?? 'export') === 'print' ? 'reports.print' : 'reports.export');
        $schemas = $this->catalog->allowed($request->user());
        $ids = $filters['section_ids'] ?? array_column($schemas, 'id');
        abort_unless(array_diff($ids, array_column($schemas, 'id')) === [], 404);
        $sections = [];
        foreach ($schemas as $schema) {
            if (! in_array($schema['id'], $ids, true)) {
                continue;
            }
            if (($filters['kind'] ?? 'all') !== 'all' && explode('-', $schema['id'])[0] !== $filters['kind']) {
                continue;
            }
            $dataset = $this->queries->build($request->user(), $schema, $filters);
            if (! $dataset) {
                $sections[] = ['id' => $schema['id'], 'title' => $schema['title'], 'available' => false, 'reason' => $this->queries->unavailableReason($schema['id']), 'columns' => $schema['columns'], 'rows' => []];

                continue;
            }
            $columns = $dataset->schema['columns'];
            if (isset($filters['visible_columns'])) {
                abort_unless(count($ids) === 1 && array_diff($filters['visible_columns'], array_column($columns, 'key')) === [], 422, 'أعمدة التصدير غير متاحة.');
                $columns = array_values(array_filter($columns, fn ($c): bool => in_array($c['key'], $filters['visible_columns'], true)));
            }
            $rows = [];
            $keys = array_flip(array_column($columns, 'key'));
            foreach ($dataset->filtered($filters)->cursor() as $record) {
                $rows[] = array_intersect_key($dataset->row($record), $keys);
            }
            $sections[] = ['id' => $schema['id'], 'title' => $schema['title'], 'available' => true, 'snapshot' => $schema['snapshot'], 'note' => $schema['note'], 'columns' => $columns, 'rows' => $rows];
        }

        return response()->json(['data' => ['sections' => $sections, 'currency' => $filters['currency'], 'generated_at' => now()->toISOString(), 'timezone' => 'Asia/Baghdad']]);
    }

    public function dashboard(ReportRequest $request): JsonResponse
    {
        $filters = $this->filters($request, 'dashboard.view');

        return response()->json(['data' => $this->dashboard->summary($request->user(), $filters)]);
    }

    public function activity(ReportRequest $request): JsonResponse
    {
        $this->access->require($request->user(), 'dashboard.view');
        $limit = $request->validated('limit', '20');
        $rows = $this->dashboard->recentOperations($request->user(), 'IQD', $limit === 'all' ? null : (int) $limit);

        return response()->json(['data' => $rows, 'meta' => ['count' => count($rows), 'limit' => $limit]]);
    }
}

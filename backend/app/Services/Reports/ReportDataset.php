<?php

namespace App\Services\Reports;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class ReportDataset
{
    public function __construct(public Builder $query, public array $schema, private array $minorFields = [], private ?\Closure $transform = null) {}

    public static function decimal(mixed $minor): ?string
    {
        if ($minor === null) {
            return null;
        }
        $text = (string) $minor;
        if (! preg_match('/\A(-?)([0-9]+)\z/', $text, $match)) {
            throw new \UnexpectedValueException('Report monetary total must be an exact integer.');
        }
        $digits = str_pad(ltrim($match[2], '0') ?: '0', 3, '0', STR_PAD_LEFT);

        return ($match[1] && $digits !== '000' ? '-' : '').substr($digits, 0, -2).'.'.substr($digits, -2);
    }

    public function filtered(array $filters): Builder
    {
        $query = DB::query()->fromSub(clone $this->query, 'report_rows');
        $columns = array_column($this->schema['columns'], 'key');
        if (! empty($filters['q'])) {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $filters['q']).'%';
            $query->where(function ($search) use ($columns, $pattern): void {
                foreach ($columns as $key) {
                    $wrapped = $search->getGrammar()->wrap($key);
                    $search->orWhereRaw($wrapped." LIKE ? ESCAPE '!'", [$pattern]);
                }
            });
        }
        if (! empty($filters['detail_filter'])) {
            $allowed = match ($this->schema['id']) {
                'network' => ['main', 'branch', 'nested'],
                'network-pos' => ['active', 'inactive'],
                'inventory-cards' => ['available', 'issued', 'held'],
                default => [],
            };
            abort_unless(in_array($filters['detail_filter'], $allowed, true), 422, 'مرشح التقرير غير متاح.');
            match ($filters['detail_filter']) {
                'main' => $query->where('_type', 'main_agent'),
                'branch' => $query->where('_type', 'sub_agent'),
                'nested' => $query->where('_type', 'sub_branch'),
                'active' => $query->where('active', 'مفعل'),
                'inactive' => $query->where('active', 'موقوف'),
                'available' => $query->where('status', 'Available'),
                'issued' => $query->where('status', 'Issued'),
                'held' => $query->whereIn('status', ['Quarantined', 'Exported', 'Awaiting Replacement']),
            };
        }
        $sort = $filters['sort'] ?? (in_array('time', $columns, true) ? 'time' : '_key');
        abort_unless(in_array($sort, [...$columns, '_key'], true), 422, 'عمود الفرز غير متاح.');

        return $query->orderBy($sort, $filters['direction'] ?? ($sort === 'time' ? 'desc' : 'asc'))->orderBy('_key');
    }

    public function row(object $record): array
    {
        $raw = (array) $record;
        if ($this->transform) {
            $raw = ($this->transform)($raw);
        }
        foreach ($this->minorFields as $field) {
            if (array_key_exists($field, $raw)) {
                $raw[$field] = self::decimal($raw[$field]);
            }
        }
        $row = ['row_key' => (string) $record->_key];
        foreach ($this->schema['columns'] as $column) {
            $value = $raw[$column['key']] ?? null;
            if ($column['type'] === 'number' && is_string($value) && preg_match('/^-?\d+$/', $value)) {
                $integer = filter_var($value, FILTER_VALIDATE_INT);
                if ($integer !== false && abs($integer) <= 9007199254740991) {
                    $value = $integer;
                }
            }
            $row[$column['key']] = $value;
        }

        return $row;
    }

    public function page(array $filters): array
    {
        $page = $this->filtered($filters)->paginate((int) ($filters['per_page'] ?? 20), ['*'], 'page', (int) ($filters['page'] ?? 1));

        return ['data' => $page->getCollection()->map(fn ($record): array => $this->row($record))->all(), 'columns' => $this->schema['columns'], 'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()]];
    }
}

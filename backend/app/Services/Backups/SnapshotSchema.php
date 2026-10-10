<?php

namespace App\Services\Backups;

use Illuminate\Database\Connection;
use RuntimeException;

class SnapshotSchema
{
    public const FORMAT = 'masal-laravel-backup';

    public const VERSION = 1;

    public function tableSchema(Connection $db): ?string
    {
        return match ($db->getDriverName()) {
            'mysql', 'mariadb' => $db->getDatabaseName(),
            'sqlite' => 'main',
            default => null,
        };
    }

    /** @return array{driver: string, migrations: array, tables: array, fingerprint: string} */
    public function describe(Connection $db): array
    {
        $schema = $db->getSchemaBuilder();
        $tables = [];
        foreach ($schema->getTables($this->tableSchema($db)) as $table) {
            $name = $table['name'];
            if (in_array($name, config('backups.excluded_tables'), true)) {
                continue;
            }
            if (! preg_match('/^[a-z][a-z0-9_]*$/D', $name)) {
                throw new RuntimeException('اسم جدول غير مدعوم في النسخة.');
            }
            $columns = array_map(fn (array $c): array => ['name' => $c['name'], 'type' => $c['type_name'], 'nullable' => $c['nullable']], $schema->getColumns($name));
            $primary = collect($schema->getIndexes($name))->first(fn (array $i): bool => $i['primary'])['columns'] ?? [];
            if ($primary === []) {
                throw new RuntimeException('يحتاج كل جدول إلى مفتاح أساسي للنسخ المتدرج: '.$name);
            }
            $foreign = array_map(fn (array $f): array => ['columns' => $f['columns'], 'table' => $f['foreign_table'], 'foreign_columns' => $f['foreign_columns']], $schema->getForeignKeys($name));
            usort($foreign, fn (array $a, array $b): int => strcmp(json_encode($a), json_encode($b)));
            $tables[$name] = ['columns' => $columns, 'primary' => $primary, 'foreign' => $foreign];
        }
        ksort($tables);
        $migrations = $db->table('migrations')->orderBy('migration')->pluck('migration')->all();
        $description = ['driver' => $db->getDriverName(), 'migrations' => $migrations, 'tables' => $tables];

        return $description + ['fingerprint' => hash('sha256', json_encode($description, JSON_THROW_ON_ERROR))];
    }

    /** @return array<string> */
    public function order(array $tables): array
    {
        $ordered = [];
        $pending = array_keys($tables);
        while ($pending !== []) {
            $progress = false;
            foreach ($pending as $index => $table) {
                $dependencies = array_values(array_unique(array_filter(array_column($tables[$table]['foreign'], 'table'), fn (string $dependency): bool => $dependency !== $table && isset($tables[$dependency]))));
                if (array_diff($dependencies, $ordered) !== []) {
                    continue;
                }
                $ordered[] = $table;
                unset($pending[$index]);
                $progress = true;
            }
            if (! $progress) {
                throw new RuntimeException('تعذر ترتيب علاقات الجداول دون تعطيل قيودها.');
            }
        }

        return $ordered;
    }

    /** @return \Generator<array<string, mixed>> */
    public function rows(Connection $db, string $table, array $primary): \Generator
    {
        $last = null;
        do {
            $query = $db->table($table);
            foreach ($primary as $column) {
                $query->orderBy($column);
            }
            if ($last !== null) {
                $query->where(function ($outer) use ($primary, $last): void {
                    foreach ($primary as $position => $column) {
                        $outer->orWhere(function ($term) use ($primary, $last, $position, $column): void {
                            for ($i = 0; $i < $position; $i++) {
                                $term->where($primary[$i], $last[$primary[$i]]);
                            }
                            $term->where($column, '>', $last[$column]);
                        });
                    }
                });
            }
            $limit = in_array($table, ['stock_orders', 'account_archives'], true) ? 1 : 128;
            $chunk = $query->limit($limit)->get();
            foreach ($chunk as $record) {
                $last = (array) $record;
                yield $last;
            }
        } while ($chunk->count() === $limit);
    }

    public function assetPaths(string $table, array $row): array
    {
        $column = match ($table) {
            'company_assets', 'support_attachments' => 'path',
            'account_attachments', 'representative_photos' => 'storage_path',
            'catalog_products', 'catalog_providers' => 'image_path',
            default => null,
        };
        $paths = $column && ! empty($row[$column]) ? [$row[$column]] : [];
        if ($table === 'sales_receipt_layouts') {
            $layout = json_decode($row['layout'], true, 64, JSON_THROW_ON_ERROR);
            if (! empty($layout['agent_image_path'])) {
                $paths[] = $layout['agent_image_path'];
            }
        }
        foreach ($paths as $path) {
            $this->safeAsset($path);
        }

        return $paths;
    }

    public function safeAsset(string $path): void
    {
        if (strlen($path) > 255 || ! preg_match('~^(company/assets|support/attachments/[0-9]+|accounts/[0-9]+/attachments|representatives/[0-9]+/photos|catalog/(products|providers)|receipt-agent-images)/[a-zA-Z0-9][a-zA-Z0-9_.-]*$~D', $path) || str_contains($path, '..')) {
            throw new RuntimeException('مسار المرفق غير مسموح.');
        }
    }
}

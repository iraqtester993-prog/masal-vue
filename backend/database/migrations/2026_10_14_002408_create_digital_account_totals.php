<?php

use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();
        if (! in_array($driver, ['sqlite', 'mysql'], true)) {
            throw new RuntimeException('Unsupported totals database');
        }
        if ($driver === 'sqlite') {
            DB::transaction(fn () => $this->rebuild($driver));

            return;
        }
        $this->assertMySqlQuiesced(DB::connection());
        $this->rebuild($driver);
    }

    private function assertMySqlQuiesced(Connection $connection): void
    {
        if (app()->bound('masal.fresh_restore_database') && app('masal.fresh_restore_database') === $connection) {
            return;
        }
        $root = str_replace('\\', '/', realpath(base_path()) ?: '');
        if (app()->environment('testing') && $connection->getDatabaseName() === 'dananiriq_masalverify' && str_starts_with($root, '/home/dananiriq/masal-verification/')) {
            return;
        }
        if (! app()->isDownForMaintenance() || ! $connection->getSchemaBuilder()->hasTable('runtime_write_gate')) {
            throw new RuntimeException('Digital totals rebuild requires maintenance and a blocked write gate.');
        }
        $connection->transaction(function () use ($connection): void {
            $gate = $connection->table('runtime_write_gate')->where('id', 1)->lockForUpdate()->first();
            if (! $gate || ! $gate->blocked) {
                throw new RuntimeException('Digital totals rebuild requires a drained, blocked write gate.');
            }
        });
    }

    private function rebuild(string $driver): void
    {
        foreach (['insert', 'update', 'delete'] as $event) {
            DB::unprepared('DROP TRIGGER IF EXISTS digital_account_totals_'.$event);
        }
        if (! Schema::hasTable('digital_account_totals')) {
            Schema::create('digital_account_totals', function (Blueprint $table): void {
                $table->unsignedBigInteger('account_id');
                $table->unsignedBigInteger('main_account_id');
                $table->string('provider', 16);
                $table->string('status', 16);
                $table->string('cost_basis', 24);
                foreach (['order_count', 'cost_minor', 'retail_minor', 'unknown_cost_count', 'catalog_cost_count'] as $column) {
                    $table->bigInteger($column)->default(0);
                }
                $table->primary(['account_id', 'main_account_id', 'provider', 'status', 'cost_basis'], 'digital_account_total_group');
                $table->index(['provider', 'status', 'account_id'], 'digital_account_total_provider');
            });
        }
        DB::table('digital_account_totals')->delete();
        DB::statement("INSERT INTO digital_account_totals (account_id,main_account_id,provider,status,cost_basis,order_count,cost_minor,retail_minor,unknown_cost_count,catalog_cost_count) SELECT account_id,main_account_id,provider,status,COALESCE(cost_basis,''),COUNT(*),SUM(COALESCE(actual_cost_minor,0)),SUM(COALESCE(actual_retail_minor,0)),SUM(CASE WHEN status='succeeded' AND (actual_cost_minor IS NULL OR cost_basis IS NULL OR cost_basis<>'provider_response') THEN 1 ELSE 0 END),SUM(CASE WHEN status='succeeded' AND cost_basis='catalog_snapshot' THEN 1 ELSE 0 END) FROM digital_orders GROUP BY account_id,main_account_id,provider,status,COALESCE(cost_basis,'')");
        $columns = ['account_id', 'main_account_id', 'provider', 'status', 'cost_basis', 'actual_cost_minor', 'actual_retail_minor'];
        $changed = implode(' OR ', array_map(fn ($c) => $driver === 'mysql' ? "NOT (NEW.{$c} <=> OLD.{$c})" : "NEW.{$c} IS NOT OLD.{$c}", $columns));
        $new = $this->posting('NEW', 1, $driver);
        $old = $this->posting('OLD', -1, $driver);
        DB::unprepared('CREATE TRIGGER digital_account_totals_insert AFTER INSERT ON digital_orders '.($driver === 'mysql' ? 'FOR EACH ROW ' : '')."BEGIN {$new} END");
        DB::unprepared('CREATE TRIGGER digital_account_totals_delete AFTER DELETE ON digital_orders '.($driver === 'mysql' ? 'FOR EACH ROW ' : '')."BEGIN {$old} END");
        DB::unprepared($driver === 'mysql' ? "CREATE TRIGGER digital_account_totals_update AFTER UPDATE ON digital_orders FOR EACH ROW BEGIN IF {$changed} THEN {$old} {$new} END IF; END" : "CREATE TRIGGER digital_account_totals_update AFTER UPDATE ON digital_orders WHEN {$changed} BEGIN {$old} {$new} END");
    }

    private function posting(string $row, int $sign, string $driver): string
    {
        $columns = ['order_count', 'cost_minor', 'retail_minor', 'unknown_cost_count', 'catalog_cost_count'];
        $values = "{$row}.account_id,{$row}.main_account_id,{$row}.provider,{$row}.status,COALESCE({$row}.cost_basis,''),{$sign},{$sign}*COALESCE({$row}.actual_cost_minor,0),{$sign}*COALESCE({$row}.actual_retail_minor,0),{$sign}*CASE WHEN {$row}.status='succeeded' AND ({$row}.actual_cost_minor IS NULL OR {$row}.cost_basis IS NULL OR {$row}.cost_basis<>'provider_response') THEN 1 ELSE 0 END,{$sign}*CASE WHEN {$row}.status='succeeded' AND {$row}.cost_basis='catalog_snapshot' THEN 1 ELSE 0 END";
        $update = implode(',', array_map(fn ($c) => $driver === 'mysql' ? "{$c}={$c}+VALUES({$c})" : "{$c}={$c}+excluded.{$c}", $columns));

        return 'INSERT INTO digital_account_totals (account_id,main_account_id,provider,status,cost_basis,'.implode(',', $columns).") VALUES ({$values}) ".($driver === 'mysql' ? 'ON DUPLICATE KEY UPDATE ' : 'ON CONFLICT(account_id,main_account_id,provider,status,cost_basis) DO UPDATE SET ').$update.';';
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::transaction(fn () => $this->removeTotals());

            return;
        }
        $this->assertMySqlQuiesced(DB::connection());
        $this->removeTotals();
    }

    private function removeTotals(): void
    {
        foreach (['insert', 'update', 'delete'] as $event) {
            DB::unprepared('DROP TRIGGER IF EXISTS digital_account_totals_'.$event);
        }
        Schema::dropIfExists('digital_account_totals');
    }
};

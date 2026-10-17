<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Services\Finance\Ledger;
use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use ReflectionMethod;
use RuntimeException;
use Tests\Support\CreatesAccounts;
use Tests\TestCase;

class FinanceEntryChainTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    private function fixture(): array
    {
        $system = $this->account(AccountType::System);
        $main = $this->account(AccountType::MainAgent, $system);
        $actor = $this->userFor($system);
        [$debit, $credit] = DB::transaction(fn () => [app(Ledger::class)->wallet($system->id, 'cash', 'IQD', 'external'), app(Ledger::class)->wallet($main->id, 'cash', 'IQD')]);

        return compact('actor', 'debit', 'credit');
    }

    private function draft(array $fixture): int
    {
        return DB::table('finance_transactions')->insertGetId(['actor_id' => $fixture['actor']->id, 'kind' => 'chain-test', 'service' => 'cash', 'currency' => 'IQD', 'reference' => 'Entry chain regression', 'idempotency_key' => (string) Str::uuid(), 'payload_hash' => hash('sha256', 'chain-test'), 'posted' => false, 'created_at' => now()]);
    }

    private function entry(int $transaction, int $wallet, int $amount, int $after, ?int $id = null): array
    {
        return array_filter(['id' => $id, 'transaction_id' => $transaction, 'wallet_id' => $wallet, 'amount_minor' => $amount, 'balance_after_minor' => $after, 'created_at' => now()], fn ($value) => $value !== null);
    }

    private function opening(array $fixture): void
    {
        DB::transaction(function () use ($fixture): void {
            $transaction = $this->draft($fixture);
            DB::table('finance_entries')->insert([$this->entry($transaction, $fixture['debit']->id, -1, -1, 100), $this->entry($transaction, $fixture['credit']->id, 1, 1, 101)]);
            $fixture['debit']->update(['balance_minor' => -1, 'version' => 2]);
            $fixture['credit']->update(['balance_minor' => 1, 'version' => 2]);
            DB::table('finance_transactions')->where('id', $transaction)->update(['posted' => true]);
        });
    }

    private function rejected(callable $write, string $message): void
    {
        $before = DB::table('finance_entries')->count();
        try {
            DB::transaction($write);
            $this->fail('Invalid financial write was accepted.');
        } catch (QueryException $failure) {
            $this->assertStringContainsString($message, $failure->getMessage());
        }
        $this->assertSame($before, DB::table('finance_entries')->count());
    }

    public function test_existing_wallet_lookup_never_uses_duplicate_insert(): void
    {
        $fixture = $this->fixture();
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $found = DB::transaction(fn () => app(Ledger::class)->wallet($fixture['debit']->account_id, 'cash', 'IQD', 'external'));
            $queries = array_column(DB::getQueryLog(), 'query');
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
        $this->assertSame($fixture['debit']->id, $found->id);
        $this->assertCount(1, $queries);
        $this->assertStringStartsWith('select', $queries[0]);
        $this->assertDatabaseCount('finance_entries', 0);
    }

    public function test_chain_rejects_forged_balance_and_out_of_order_actual_ids(): void
    {
        $fixture = $this->fixture();
        $this->opening($fixture);
        foreach ([[1, 9, null, 'Invalid financial entry chain'], [1, 2, 50, 'Financial entries must append in order'], [1, 2, 0, 'Financial entries must append in order']] as [$amount, $after, $id, $message]) {
            $this->rejected(fn () => DB::table('finance_entries')->insert($this->entry($this->draft($fixture), $fixture['credit']->id, $amount, $after, $id)), $message);
        }
        $this->rejected(fn () => DB::table('finance_wallets')->where('id', $fixture['credit']->id)->update(['balance_minor' => 2, 'version' => 3]), 'Invalid financial wallet balance');
        $this->assertSame(1, (int) $fixture['credit']->fresh()->balance_minor);
        $this->assertDatabaseCount('finance_transactions', 1);
    }

    public function test_bad_second_bulk_entry_rolls_back_the_first_entry_too(): void
    {
        $fixture = $this->fixture();
        $this->opening($fixture);
        $this->rejected(function () use ($fixture): void {
            $first = $this->draft($fixture);
            $second = $this->draft($fixture);
            DB::table('finance_entries')->insert([$this->entry($first, $fixture['credit']->id, 1, 2), $this->entry($second, $fixture['credit']->id, 1, 99)]);
        }, 'Invalid financial entry chain');
        $this->assertDatabaseCount('finance_transactions', 1);
        $this->assertSame(0, (int) DB::table('finance_entries')->sum('amount_minor'));
    }

    public function test_bulk_restore_can_rebuild_an_entry_chain_before_final_wallet_updates(): void
    {
        $fixture = $this->fixture();
        DB::transaction(function () use ($fixture): void {
            $first = $this->draft($fixture);
            $second = $this->draft($fixture);
            DB::table('finance_entries')->insert([
                $this->entry($first, $fixture['debit']->id, -7, -7), $this->entry($first, $fixture['credit']->id, 7, 7),
                $this->entry($second, $fixture['debit']->id, -3, -10), $this->entry($second, $fixture['credit']->id, 3, 10),
            ]);
            $this->assertSame(0, (int) $fixture['credit']->fresh()->balance_minor);
            $fixture['debit']->update(['balance_minor' => -10, 'version' => 2]);
            $fixture['credit']->update(['balance_minor' => 10, 'version' => 2]);
            DB::table('finance_transactions')->whereIn('id', [$first, $second])->update(['posted' => true]);
        });
        $this->assertDatabaseCount('finance_entries', 4);
        $this->assertSame(2, DB::table('finance_transactions')->where('posted', true)->count());
        $this->assertSame(10, (int) $fixture['credit']->fresh()->balance_minor);
    }

    public function test_legacy_invalid_history_is_rejected_before_any_guard_is_replaced(): void
    {
        $fixture = $this->fixture();
        $migration = require database_path('migrations/2026_10_14_073138_enforce_finance_entry_chain.php');
        $migration->down();
        DB::transaction(function () use ($fixture): void {
            $transaction = $this->draft($fixture);
            DB::table('finance_entries')->insert([$this->entry($transaction, $fixture['debit']->id, -1, -9), $this->entry($transaction, $fixture['credit']->id, 1, 9)]);
            $fixture['debit']->update(['balance_minor' => -1, 'version' => 2]);
            $fixture['credit']->update(['balance_minor' => 1, 'version' => 2]);
            DB::table('finance_transactions')->where('id', $transaction)->update(['posted' => true]);
        });
        $before = DB::table('sqlite_master')->where('type', 'trigger')->orderBy('name')->pluck('sql', 'name')->all();
        try {
            $migration->up();
            $this->fail('Invalid legacy history was accepted.');
        } catch (RuntimeException $failure) {
            $this->assertStringContainsString('no guards were changed', $failure->getMessage());
        }
        $this->assertSame($before, DB::table('sqlite_master')->where('type', 'trigger')->orderBy('name')->pluck('sql', 'name')->all());
        $this->assertSame(0, (int) DB::table('finance_entries')->sum('amount_minor'));
    }

    public function test_unbalanced_posting_is_rejected_and_entries_remain_immutable(): void
    {
        $fixture = $this->fixture();
        $this->opening($fixture);
        $this->rejected(function () use ($fixture): void {
            $id = $this->draft($fixture);
            DB::table('finance_entries')->insert($this->entry($id, $fixture['credit']->id, 1, 2));
            DB::table('finance_transactions')->where('id', $id)->update(['posted' => true]);
        }, 'Invalid or unbalanced financial posting');
        $this->rejected(fn () => DB::table('finance_entries')->where('id', 101)->update(['balance_after_minor' => 999]), 'Immutable financial entry');
        $this->rejected(fn () => DB::table('finance_entries')->where('id', 101)->delete(), 'Immutable financial record');

        $this->assertSame(1, (int) $fixture['credit']->fresh()->balance_minor);
    }

    public function test_guard_replacement_requires_maintenance_and_a_drained_gate(): void
    {
        $migration = require database_path('migrations/2026_10_14_073138_enforce_finance_entry_chain.php');
        $check = new ReflectionMethod($migration, 'assertQuiesced');
        foreach ([[false, false], [true, false]] as [$maintenance, $blocked]) {
            $mode = Mockery::mock(MaintenanceMode::class);
            $mode->shouldReceive('active')->andReturn($maintenance);
            app()->instance(MaintenanceMode::class, $mode);
            DB::table('runtime_write_gate')->where('id', 1)->update(['blocked' => $blocked]);
            try {
                $check->invoke($migration, DB::connection());
                $this->fail('An active or undrained runtime was accepted.');
            } catch (RuntimeException $failure) {
                $this->assertStringContainsString('blocked write gate', $failure->getMessage());
            }
        }
        DB::table('runtime_write_gate')->where('id', 1)->update(['blocked' => true, 'version' => 19]);
        $before = DB::table('runtime_write_gate')->first();
        $check->invoke($migration, DB::connection());
        $this->assertEquals($before, DB::table('runtime_write_gate')->first());
    }

    public function test_restore_bypass_requires_the_exact_verified_fresh_connection(): void
    {
        $migration = require database_path('migrations/2026_10_14_073138_enforce_finance_entry_chain.php');
        $check = new ReflectionMethod($migration, 'assertQuiesced');
        app()->instance('masal.fresh_restore_database', DB::connection());
        $check->invoke($migration, DB::connection());
        app()->instance('masal.fresh_restore_database', new \stdClass);
        try {
            $check->invoke($migration, DB::connection());
            $this->fail('An unrelated restore connection was accepted.');
        } catch (RuntimeException $failure) {
            $this->assertStringContainsString('require maintenance', $failure->getMessage());
        }
    }
}

<?php

namespace Tests\Feature;

use App\Models\Digital\DigitalConnection;
use App\Models\Digital\DigitalOffer;
use App\Models\Digital\DigitalOrder;
use App\Models\Digital\TopupGrant;
use App\Models\Finance\Invoice;
use App\Models\Finance\Wallet;
use App\Services\Backups\BackupWorkflow;
use App\Services\Backups\SnapshotArchive;
use App\Services\Backups\SnapshotImporter;
use App\Services\Backups\SnapshotSchema;
use App\Services\Digital\TopupWallets;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\CreatesSupport;
use Tests\TestCase;

class TopupFundedBackupTest extends TestCase
{
    use CreatesSupport, RefreshDatabase;

    private const FINANCIAL_TABLES = ['finance_wallets', 'finance_transactions', 'finance_entries', 'finance_invoices', 'topup_grants', 'digital_orders', 'topup_wallet_bindings', 'topup_finance_events'];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Http::preventStrayRequests();
    }

    private function fundedFixture(): array
    {
        $fixture = $this->supportFixture();
        $connection = DigitalConnection::factory()->create(['account_id' => $fixture['system']->id, 'provider' => 'topup', 'company_balance_minor' => 9400000]);
        $grant = TopupGrant::factory()->create(['from_account_id' => $fixture['system']->id, 'target_account_id' => $fixture['main']->id, 'main_account_id' => $fixture['main']->id, 'connection_id' => $connection->id, 'balance_minor' => 10000000, 'held_minor' => 0, 'spent_minor' => 0]);
        $offer = DigitalOffer::factory()->create(['connection_id' => $connection->id, 'type' => 'topup', 'cost_minor' => 600000, 'retail_minor' => 600000]);
        DB::transaction(function () use ($fixture, $connection, $grant, $offer): void {
            $wallets = app(TopupWallets::class);
            $wallets->bootstrap($grant, $fixture['admin']);
            $wallets->fund($fixture['agent'], $fixture['main'], $fixture['pos'], 1000000, 'backup-topup-funding', ['fixture' => true], 'تمويل لفحص النسخ');
            $point = Wallet::where('account_id', $fixture['pos']->id)->where('service', 'topup')->where('kind', 'account')->sole();
            $order = DigitalOrder::factory()->create(['connection_id' => $connection->id, 'offer_id' => $offer->id, 'product_id' => $offer->product_id, 'provider' => 'topup', 'type' => 'topup', 'account_id' => $fixture['pos']->id, 'main_account_id' => $fixture['main']->id, 'creator_id' => $fixture['posUser']->id, 'topup_grant_id' => $grant->id, 'admin_price_minor' => 600000, 'quoted_cost_minor' => 600000, 'quoted_retail_minor' => 650000, 'actual_cost_minor' => 600000, 'actual_retail_minor' => 650000, 'cost_basis' => 'provider_response', 'status' => 'succeeded', 'reservation_active' => false, 'accounted_at' => now(), 'resolved_at' => now(), 'company_transaction_id' => (string) Str::uuid(), 'receipt_ref' => (string) Str::uuid(), 'budget_basis' => 'point_budget_v1', 'budget_wallet_id' => $point->id]);
            $wallets->settle($order, $grant);
            $grant->update(['balance_minor' => 9400000, 'spent_minor' => 600000]);
            Invoice::create(['account_id' => $fixture['pos']->id, 'creditor_account_id' => $fixture['main']->id, 'creator_id' => $fixture['posUser']->id, 'kind' => 'receivable', 'supplier' => $fixture['main']->name, 'service' => 'topup', 'currency' => 'IQD', 'amount_minor' => 650000, 'reference' => 'Topup '.$order->request_id, 'source_type' => 'digital_topup', 'source_id' => $order->id]);
        });
        $this->assertTrue(app(TopupWallets::class)->auditInvariants()['ok']);
        $this->asPortalUser($fixture['admin']);

        return $fixture + ['connection' => $connection, 'grant' => $grant];
    }

    private function money(): array
    {
        $rows = [];
        foreach (self::FINANCIAL_TABLES as $table) {
            $rows[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all();
        }

        return $rows;
    }

    public function test_current_snapshot_restores_funded_balances_order_basis_margin_and_events_without_source_writes(): void
    {
        $fixture = $this->fundedFixture();
        $source = $this->money();
        $backup = app(BackupWorkflow::class)->create($fixture['admin']);
        $folder = 'backups/funded-fixture/'.Str::uuid();
        Storage::disk('local')->makeDirectory($folder);
        $database = Storage::disk('local')->path($folder.'/target.sqlite');
        touch($database);
        $alias = 'funded_restore_'.str_replace('-', '', Str::uuid());
        config(['database.connections.'.$alias => ['driver' => 'sqlite', 'database' => $database, 'prefix' => '', 'foreign_key_constraints' => true]]);
        try {
            $order = app(SnapshotSchema::class)->order(app(SnapshotSchema::class)->describe(DB::connection())['tables']);
            $this->assertLessThan(array_search('topup_finance_events', $order, true), array_search('finance_entries', $order, true));
            $this->assertLessThan(array_search('digital_orders', $order, true), array_search('topup_wallet_bindings', $order, true));
            app(SnapshotImporter::class)->restore($backup->path, $alias, DB::connection(), $folder.'/assets');
            $target = DB::connection($alias);
            foreach ($source as $table => $rows) {
                $this->assertSame($rows, $target->table($table)->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all(), $table);
            }
            $target->enableQueryLog();
            $this->assertSame(['ok' => true, 'issues' => [], 'read_only' => true], app(TopupWallets::class)->auditInvariants($target));
            $queries = $target->getQueryLog();
            $target->disableQueryLog();
            $this->assertNotEmpty($queries);
            foreach ($queries as $query) {
                $this->assertDoesNotMatchRegularExpression('/\A\s*(?:INSERT|UPDATE|DELETE|REPLACE|ALTER|CREATE|DROP)\b/i', $query['query']);
            }
            $this->assertSame(350000, (int) $target->table('finance_wallets')->where('account_id', $fixture['pos']->id)->where('service', 'topup')->where('kind', 'account')->value('balance_minor'));
            $this->assertSame(9000000, (int) $target->table('finance_wallets')->where('account_id', $fixture['main']->id)->where('service', 'topup')->where('kind', 'account')->value('balance_minor'));
            $this->assertSame(50000, (int) $target->table('finance_wallets')->where('account_id', $fixture['main']->id)->where('service', 'topup')->where('kind', 'external')->value('balance_minor'));
            foreach (['topup_finance_events', 'topup_wallet_bindings'] as $immutable) {
                try {
                    $target->table($immutable)->delete();
                    $this->fail('Restored Topup financial metadata was mutable.');
                } catch (QueryException $failure) {
                    $this->assertStringContainsString('immutable', $failure->getMessage());
                }
                $this->assertSame($source[$immutable], $target->table($immutable)->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all());
            }
            $this->assertSame($source, $this->money());
            Http::assertNothingSent();
        } finally {
            DB::purge($alias);
            config(['database.connections.'.$alias => null]);
            Storage::disk('local')->deleteDirectory($folder);
        }
    }

    public function test_signed_snapshot_with_balanced_ledger_but_incorrect_topup_pool_is_rejected(): void
    {
        $fixture = $this->fundedFixture();
        $fixture['grant']->update(['balance_minor' => 9400001]);
        $source = $this->money();
        $backup = app(BackupWorkflow::class)->create($fixture['admin']);
        try {
            app(BackupWorkflow::class)->preview($fixture['admin'], null, $backup->id);
            $this->fail('An inconsistent funded Topup pool was accepted.');
        } catch (\RuntimeException $failure) {
            $this->assertStringContainsString('Topup', $failure->getMessage());
        }
        $this->assertSame($source, $this->money());
        $this->assertSame([], Storage::disk('local')->allFiles('backups/validation'));
        Http::assertNothingSent();
    }

    public function test_financial_topup_migration_is_never_ignored_as_a_derived_or_index_only_change(): void
    {
        $fixture = $this->fundedFixture();
        $migration = (array) DB::table('migrations')->where('migration', '2026_10_14_081325_create_topup_funded_wallets')->sole();
        $path = 'backups/funded-fixture/'.Str::uuid().'.json';
        try {
            DB::table('migrations')->where('id', $migration['id'])->delete();
            app(SnapshotArchive::class)->create(DB::connection(), $path);
        } finally {
            DB::table('migrations')->insert($migration);
        }
        $source = $this->money();
        try {
            app(SnapshotImporter::class)->preview($path, DB::connection());
            $this->fail('A financial schema migration was treated as compatible.');
        } catch (\RuntimeException $failure) {
            $this->assertStringContainsString('مخطط', $failure->getMessage());
        }
        $this->assertSame($source, $this->money());
        Http::assertNothingSent();
    }
}

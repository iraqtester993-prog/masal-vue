<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Digital\DigitalConnection;
use App\Models\Digital\DigitalOffer;
use App\Models\Digital\DigitalOrder;
use App\Models\Digital\TopupGrant;
use App\Models\Operations\DirectStop;
use App\Models\Sales\PrintPolicy;
use App\Models\Sales\Sale;
use App\Models\Stock\StockCard;
use App\Models\User;
use App\Services\Digital\TopupAccounting;
use App\Services\Digital\TopupWallets;
use App\Services\Finance\Money;
use App\Services\Operations\MutationGuard;
use App\Services\Operations\OperationGuard;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\CreatesAccounts;
use Tests\TestCase;

class StockCurrentReadNativeTest extends TestCase
{
    use CreatesAccounts;

    private function guard(): void
    {
        if (getenv('MASAL_STOCK_CURRENT_READ_TEST') !== '1') {
            $this->markTestSkipped('Requires the existing disposable MySQL scale fixture and isolated explicit execution.');
        }
        $this->assertTrue(app()->environment('testing'));
        $this->assertSame('mysql', DB::getDriverName());
        $this->assertSame('dananiriq_masalverify', DB::connection()->getDatabaseName());
        $this->assertSame('/home/dananiriq/masal-verification/finance-stock-20261006-01', realpath(base_path()));
        $this->assertSame(base_path('storage'), realpath(storage_path()));
        $this->assertGreaterThanOrEqual(3000000, DB::table('digital_orders')->count());
    }

    public function test_three_points_issue_distinct_stock_cards_after_a_competing_connection_commits_inside_repeatable_read(): void
    {
        $this->guard();
        $cacheReads = [];
        $observeCache = true;
        DB::listen(function (QueryExecuted $query) use (&$cacheReads, &$observeCache): void {
            if ($observeCache && str_contains($query->sql, '`finance_prices`') && str_contains(strtolower($query->sql), 'for update')) {
                $cacheReads[] = request()->isMethod('POST') && request()->attributes->has('masal.locked_authorization_cache');
            }
        });
        $groups = DB::table('stock_cards as card')->join('catalog_products as product', 'product.id', '=', 'card.product_id')->where('product.name', 'like', 'QA pressure %')->where('card.status', 'Available')
            ->where('card.credit_held', false)->whereNull('card.sale_id')->groupBy('card.account_id', 'card.product_id')->havingRaw('COUNT(*) >= 3')->orderByDesc('card.product_id')->get(['card.account_id', 'card.product_id']);
        $actors = collect();
        foreach ($groups as $group) {
            $price = DB::table('finance_prices')->where('account_id', $group->account_id)->where('product_id', $group->product_id)->first();
            $points = DB::table('finance_wallets as wallet')->join('accounts as point', 'point.id', '=', 'wallet.account_id')->where('point.parent_id', $group->account_id)->where('point.type', AccountType::Pos->value)->where('wallet.service', 'voucher')->where('wallet.kind', 'account')
                ->whereRaw('wallet.balance_minor-wallet.held_minor >= ?', [$price->price_minor])->whereIn('point.id', DB::table('catalog_account_rules as rule')->join('catalog_rule_products as granted', 'granted.rule_id', '=', 'rule.id')->where('granted.product_id', $group->product_id)->select('rule.target_account_id'))->pluck('point.id');
            $actors = User::whereHas('membership', fn ($query) => $query->where('kind', 'owner')->whereIn('account_id', $points))->orderBy('id')->limit(3)->get();
            if ($actors->count() === 3) {
                break;
            }
        }
        $this->assertCount(3, $actors);
        $this->assertTrue($actors->every(fn ($actor): bool => str_ends_with((string) $actor->email, '@example.invalid')), 'Only synthetic creators may issue native QA stock.');
        $this->assertNotNull($price);
        $original = DB::getDefaultConnection();
        $alias = 'stock_current_read_competitor';
        config(['database.connections.'.$alias => config('database.connections.'.$original)]);
        $connection = DB::connection($original);
        $beforeCards = StockCard::where('account_id', $group->account_id)->where('product_id', $group->product_id)->where('status', 'Available')->count();
        $beforeWallets = DB::table('finance_wallets')->whereIn('account_id', $actors->map(fn ($actor) => $actor->membership->account_id))->where('service', 'voucher')->where('kind', 'account')->pluck('balance_minor', 'account_id');
        $policy = PrintPolicy::findOrFail(1);
        $settings = $policy->settings;
        $policy->update(['settings' => array_replace($settings, ['velocity_seconds' => 0])]);
        $run = 'current-read-'.Str::uuid();
        $sales = [];
        $input = fn ($slot) => ['product_id' => $group->product_id, 'quantity' => 1, 'price_version' => $price->version, 'expected_price' => Money::decimal($price->price_minor), 'idempotency_key' => $run.'-'.$slot];
        try {
            $this->asPortalUser($actors[0]);
            $this->postJson('/api/v1/sales/device-session', ['app_version' => '1.0.0'])->assertOk();
            $this->withCredentials()->withCookie(config('session.cookie'), app('session')->getId());
            $connection->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $connection->beginTransaction();
            $candidate = StockCard::where('account_id', $group->account_id)->where('product_id', $group->product_id)->where('status', 'Available')->where('credit_held', false)->whereNull('sale_id')
                ->where('expiry', '>', now('Asia/Baghdad')->toDateString())->orderBy('expiry')->orderBy('created_at')->orderBy('id')->value('id');
            DB::setDefaultConnection($alias);
            $this->asPortalUser($actors[1]);
            $this->postJson('/api/v1/sales/device-session', ['app_version' => '1.0.0'])->assertOk();
            $this->withCredentials()->withCookie(config('session.cookie'), app('session')->getId());
            $competing = $this->postJson('/api/v1/sales', $input(1));
            $this->assertSame(201, $competing->status(), (string) $competing->json('message'));
            $sales[] = $competing->json('data.id');
            $this->assertSame('Issued', DB::table('stock_cards')->where('id', $candidate)->value('status'));
            DB::setDefaultConnection($original);
            $this->assertSame('Available', DB::table('stock_cards')->where('id', $candidate)->value('status'));
            $this->asPortalUser($actors[0]);
            $response = $this->postJson('/api/v1/sales', $input(0));
            if (getenv('MASAL_STOCK_CURRENT_READ_BEFORE') === '1') {
                $response->assertConflict()->assertJsonPath('message', 'تغير المخزون أثناء الاختيار؛ أعد المحاولة.');
                $connection->rollBack();
            } else {
                $sales[] = $response->assertCreated()->json('data.id');
                $connection->commit();
            }
            $this->asPortalUser($actors[2]);
            $this->postJson('/api/v1/sales/device-session', ['app_version' => '1.0.0'])->assertOk();
            $this->withCredentials()->withCookie(config('session.cookie'), app('session')->getId());
            $sales[] = $this->postJson('/api/v1/sales', $input(2))->assertCreated()->json('data.id');
            $cards = DB::table('stock_cards')->whereIn('sale_id', $sales)->get();
            $this->assertCount(count($sales), $cards);
            $this->assertCount(count($sales), $cards->pluck('id')->unique());
            $this->assertSame($beforeCards - count($sales), StockCard::where('account_id', $group->account_id)->where('product_id', $group->product_id)->where('status', 'Available')->count());
            foreach ($actors as $slot => $actor) {
                $account = $actor->membership->account_id;
                $issued = $slot !== 0 || getenv('MASAL_STOCK_CURRENT_READ_BEFORE') !== '1';
                $this->assertSame((int) $beforeWallets[$account] - ($issued ? (int) $price->price_minor : 0), (int) DB::table('finance_wallets')->where('account_id', $account)->where('service', 'voucher')->where('kind', 'account')->value('balance_minor'));
                if ($issued) {
                    $this->asPortalUser($actor);
                    $id = $this->postJson('/api/v1/sales', $input($slot))->assertCreated()->json('data.id');
                    $this->assertContains($id, $sales);
                }
            }
            $this->assertSame(0, (int) DB::table('finance_entries')->sum('amount_minor'));
            $observeCache = false;
            $this->assertNotEmpty($cacheReads);
            $this->assertNotContains(false, $cacheReads);
            $entriesAfterIssuing = DB::table('finance_entries')->count();
            foreach ($sales as $saleId) {
                $sale = Sale::findOrFail($saleId);
                $this->asPortalUser($actors->firstWhere('id', $sale->creator_id));
                $this->postJson('/api/v1/sales/device-session', ['app_version' => '1.0.0'])->assertOk();
                $this->withCredentials()->withCookie(config('session.cookie'), app('session')->getId());
                $receipt = $this->getJson('/api/v1/sales/'.$saleId.'/receipt')->assertOk()->json('data');
                $attempt = $this->postJson('/api/v1/sales/'.$saleId.'/print/start', ['version' => $receipt['sale']['version'], 'idempotency_key' => $run.'-print-start-'.$saleId])->assertOk()->json('data');
                $this->postJson('/api/v1/sales/'.$saleId.'/print/result', ['version' => $attempt['version'], 'attempt_id' => $attempt['attempt_id'], 'success' => true, 'idempotency_key' => $run.'-print-result-'.$saleId])->assertOk();
                $this->assertFalse(request()->attributes->has('masal.locked_authorization_cache'));
            }
            $this->assertSame($entriesAfterIssuing, DB::table('finance_entries')->count());
            fwrite(STDOUT, 'STOCK_CURRENT_READ '.json_encode(['before' => getenv('MASAL_STOCK_CURRENT_READ_BEFORE') === '1', 'real_mysql_rr_snapshot_was_stale' => true, 'competing_committed_sale' => true, 'points' => 3, 'issued_sales' => count($sales), 'distinct_cards' => $cards->count(), 'exact_debits_and_replays' => true, 'physical_printing' => false, 'post_locked_authorization_cache_observed' => true, 'owned_receipts_simulated_complete' => true]).PHP_EOL);
        } finally {
            DB::setDefaultConnection($original);
            while ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
            $policy->update(['settings' => $settings]);
            DB::purge($alias);
            config(['database.connections.'.$alias => null]);
        }

    }

    public function test_provider_hold_budget_sees_a_competing_committed_reservation_inside_repeatable_read(): void
    {
        $this->guard();
        $points = collect();
        foreach (TopupGrant::whereNotNull('connection_id')->where('active', true)->whereRaw('balance_minor-held_minor > 2000000')->orderByDesc('id')->get() as $grant) {
            $central = DigitalConnection::findOrFail($grant->connection_id);
            $offer = DigitalOffer::where('connection_id', $central->id)->whereIn('topup_category_id', $grant->category_ids)->where('active', true)->first();
            if (! $offer || $offer->cost_minor <= 0) {
                continue;
            }
            $retail = max($offer->retail_minor, (int) ($grant->retail_prices[(string) $offer->topup_category_id] ?? $offer->retail_minor));
            $pointIds = DB::table('topup_wallet_bindings as b')->join('finance_wallets as w', 'w.id', '=', 'b.wallet_id')
                ->where('b.grant_id', $grant->id)->where('b.role', 'point')->whereRaw('w.balance_minor-w.held_minor >= ?', [$retail])->select('w.account_id');
            $points = Account::whereIn('id', $pointIds)->where('parent_id', $grant->target_account_id)->where('type', AccountType::Pos)->where('status', 'active')->orderBy('id')->limit(2)->get();
            if ($points->count() === 2) {
                break;
            }
        }
        $this->assertCount(2, $points, 'The retained funded fixture must contain two sufficiently funded points; this test never mints or transfers funds.');
        $beforePointMoney = DB::table('finance_wallets')->whereIn('account_id', $points->pluck('id'))->where('service', 'topup')->where('kind', 'account')->orderBy('id')->get(['id', 'balance_minor', 'held_minor'])->toArray();
        $this->assertTrue(str_starts_with((string) $central->credential, 'isolated-'), 'Only an explicitly isolated mock connection is accepted.');
        $creator = User::whereHas('membership', fn ($query) => $query->where('account_id', $points[1]->id)->where('kind', 'owner'))->firstOrFail();
        $this->assertTrue(str_ends_with((string) $creator->email, '@example.invalid'), 'Only a synthetic point creator may reserve native QA funds.');
        $original = DB::getDefaultConnection();
        $alias = 'topup_hold_current_read_competitor';
        config(['database.connections.'.$alias => config('database.connections.'.$original)]);
        $source = DB::connection($original);
        $originalBalance = $central->company_balance_minor;
        $initialHeld = $grant->held_minor;
        $entries = DB::table('finance_entries')->count();
        $orderId = null;
        $source->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $source->beginTransaction();
        $this->assertSame(0, (int) DigitalOrder::where('connection_id', $central->id)->where('reservation_active', true)->sum('quoted_cost_minor'));
        try {
            DB::setDefaultConnection($alias);
            DB::transaction(function () use ($central, $offer, $points, $creator, &$orderId): void {
                $current = DigitalConnection::whereKey($central->id)->lockForUpdate()->firstOrFail();
                $current->update(['company_balance_minor' => 2 * $offer->cost_minor - 1]);
                $reserved = app(TopupAccounting::class)->reserve($current, $points[1], $offer);
                $orderId = DigitalOrder::factory()->create(['connection_id' => $current->id, 'offer_id' => $offer->id, 'product_id' => $offer->product_id,
                    'account_id' => $points[1]->id, 'main_account_id' => $reserved['grant']->main_account_id, 'topup_grant_id' => $reserved['grant']->id, 'admin_price_minor' => $offer->retail_minor,
                    'budget_basis' => 'point_budget_v1', 'budget_wallet_id' => $reserved['wallet_id'],
                    'creator_id' => $creator->id, 'provider' => 'topup', 'request_id' => 'qa-held-current-'.Str::uuid(), 'quoted_cost_minor' => $offer->cost_minor,
                    'quoted_retail_minor' => app(TopupAccounting::class)->retail($offer, $points[1]), 'status' => 'pending', 'reservation_active' => true])->id;
            });
            DB::setDefaultConnection($original);
            $this->assertSame(0, (int) DigitalOrder::where('connection_id', $central->id)->where('reservation_active', true)->sum('quoted_cost_minor'));
            $current = DigitalConnection::whereKey($central->id)->lockForUpdate()->firstOrFail();
            if (getenv('MASAL_STOCK_CURRENT_READ_BEFORE') === '1') {
                $reserved = app(TopupAccounting::class)->reserve($current, $points[0], $offer);
                $this->assertSame($initialHeld + 2 * $offer->retail_minor, $reserved['grant']->held_minor);
            } else {
                try {
                    app(TopupAccounting::class)->reserve($current, $points[0], $offer);
                    $this->fail('A stale snapshot must not over-reserve the provider balance.');
                } catch (HttpException $failure) {
                    $this->assertSame(422, $failure->getStatusCode());
                }
            }
            $source->rollBack();
            $this->assertSame($initialHeld + $offer->retail_minor, TopupGrant::findOrFail($grant->id)->held_minor);
            $this->assertSame($entries, DB::table('finance_entries')->count());
            fwrite(STDOUT, 'TOPUP_HOLD_CURRENT_READ '.json_encode(['before' => getenv('MASAL_STOCK_CURRENT_READ_BEFORE') === '1', 'real_mysql_stale_snapshot' => true,
                'competing_reservation_committed' => true, 'provider_held_minor' => $offer->cost_minor, 'available_minor' => $offer->cost_minor - 1,
                'second_reservation_rejected' => getenv('MASAL_STOCK_CURRENT_READ_BEFORE') !== '1', 'provider_calls' => 0, 'ledger_entries_added' => 0]).PHP_EOL);
        } finally {
            DB::setDefaultConnection($original);
            while ($source->transactionLevel() > 0) {
                $source->rollBack();
            }
            if ($orderId !== null) {
                DB::transaction(function () use ($orderId): void {
                    $order = DigitalOrder::findOrFail($orderId);
                    app(TopupAccounting::class)->resolve($order, 'failed');
                    $order->update(['status' => 'failed', 'reservation_active' => false, 'resolved_at' => now(), 'acknowledged_at' => now()]);
                });
            }
            DigitalConnection::whereKey($central->id)->update(['company_balance_minor' => $originalBalance]);
            DB::purge($alias);
            config(['database.connections.'.$alias => null]);
        }
        $this->assertSame($initialHeld, TopupGrant::findOrFail($grant->id)->held_minor);
        $this->assertSame($originalBalance, DigitalConnection::findOrFail($central->id)->company_balance_minor);
        $this->assertEquals($beforePointMoney, DB::table('finance_wallets')->whereIn('account_id', $points->pluck('id'))->where('service', 'topup')->where('kind', 'account')->orderBy('id')->get(['id', 'balance_minor', 'held_minor'])->toArray());
        $this->assertTrue(app(TopupWallets::class)->auditInvariants()['ok']);
    }

    public function test_descendant_writes_share_system_lock_but_system_pause_is_exclusive(): void
    {
        $this->guard();
        $point = Account::where('type', AccountType::Pos)->where('name', 'like', 'Scale point %')->first();
        $point ??= Account::where('type', AccountType::Pos)->where('status', 'active')->firstOrFail();
        $actor = User::whereHas('membership', fn ($query) => $query->where('account_id', $point->id)->where('kind', 'owner'))->firstOrFail();
        $system = Account::where('type', AccountType::System)->firstOrFail();
        $admin = User::whereHas('membership', fn ($query) => $query->where('account_id', $system->id)->where('kind', 'owner'))->firstOrFail();
        $original = DB::getDefaultConnection();
        $alias = 'system_lock_competitor';
        config(['database.connections.'.$alias => config('database.connections.'.$original)]);
        $source = DB::connection($original);
        $competitor = DB::connection($alias);
        $competitor->statement('SET SESSION innodb_lock_wait_timeout=1');
        $entries = DB::table('finance_entries')->count();
        try {
            $source->beginTransaction();
            app(MutationGuard::class)->lock($actor);
            $competitor->beginTransaction();
            $this->assertSame($system->id, $competitor->table('accounts')->where('id', $system->id)->sharedLock()->value('id'));
            $competitor->rollBack();
            $this->assertLockTimeout(function () use ($competitor, $system): void {
                $competitor->table('accounts')->where('id', $system->id)->lockForUpdate()->first();
            });
            $source->rollBack();

            $source->beginTransaction();
            app(MutationGuard::class)->lock($admin);
            $this->assertLockTimeout(function () use ($competitor, $system): void {
                $competitor->table('accounts')->where('id', $system->id)->sharedLock()->first();
            });
            $source->rollBack();
            $this->assertSame($entries, DB::table('finance_entries')->count());
            fwrite(STDOUT, 'SYSTEM_LOCK_ORDER '.json_encode(['real_mysql_two_connections' => true, 'descendant_system_shared' => true, 'system_pause_exclusive' => true, 'ledger_entries_added' => 0]).PHP_EOL);
        } finally {
            while ($source->transactionLevel() > 0) {
                $source->rollBack();
            }
            while ($competitor->transactionLevel() > 0) {
                $competitor->rollBack();
            }
            DB::purge($alias);
            config(['database.connections.'.$alias => null]);
        }
    }

    private function assertLockTimeout(callable $callback): void
    {
        try {
            $callback();
            $this->fail('An incompatible system lock must wait for the current writer.');
        } catch (QueryException $failure) {
            $this->assertSame(1205, (int) $failure->errorInfo[1]);
        }
    }

    public function test_committed_direct_stop_overrides_an_older_repeatable_read_snapshot(): void
    {
        $this->guard();
        $point = Account::where('type', AccountType::Pos)->where('name', 'like', 'Scale point %')->firstOrFail();
        $actor = User::whereHas('membership', fn ($query) => $query->where('account_id', $point->id)->where('kind', 'owner'))->firstOrFail();
        $original = DB::getDefaultConnection();
        $alias = 'operation_stop_current_read_competitor';
        config(['database.connections.'.$alias => config('database.connections.'.$original)]);
        $source = DB::connection($original);
        $before = DirectStop::where('account_id', $point->id)->first();
        $this->assertNull($before);
        $entries = DB::table('finance_entries')->count();
        $source->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $source->beginTransaction();
        try {
            $this->assertNull(app(OperationGuard::class)->reason($point->id, 'sales'));
            DB::setDefaultConnection($alias);
            DB::transaction(function () use ($point): void {
                $ancestors = DB::table('account_closure')->where('descendant_id', $point->id)->pluck('ancestor_id')->all();
                $system = Account::where('type', AccountType::System)->firstOrFail();
                app(MutationGuard::class)->lockAccounts($ancestors, $system->id, false);
                DirectStop::create(['account_id' => $point->id, 'stops' => ['sales' => true], 'version' => 1]);
            });
            DB::setDefaultConnection($original);
            $this->assertNull(DirectStop::where('account_id', $point->id)->first());
            $this->assertNull(app(OperationGuard::class)->reason($point->id, 'sales'));
            try {
                app(MutationGuard::class)->lock($actor, [], 'sales.create');
                $this->fail('A stop committed before account admission must prevent a new sale.');
            } catch (HttpException $failure) {
                $this->assertSame(423, $failure->getStatusCode());
            }
            $source->rollBack();
            $this->assertSame($entries, DB::table('finance_entries')->count());
            fwrite(STDOUT, 'OPERATION_ADMISSION_CURRENT_READ '.json_encode(['real_mysql_two_connections' => true, 'old_snapshot_was_stale' => true, 'new_sale_rejected' => true, 'ledger_entries_added' => 0]).PHP_EOL);
        } finally {
            DB::setDefaultConnection($original);
            while ($source->transactionLevel() > 0) {
                $source->rollBack();
            }
            DirectStop::where('account_id', $point->id)->delete();
            DB::purge($alias);
            config(['database.connections.'.$alias => null]);
        }
    }
}

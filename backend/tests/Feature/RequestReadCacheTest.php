<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Models\Digital\DigitalConnection;
use App\Models\Digital\DigitalOffer;
use App\Models\Operations\DirectStop;
use App\Services\CatalogAccess;
use App\Services\Digital\DigitalAccess;
use App\Services\Operations\MutationGuard;
use App\Services\Operations\OperationGuard;
use App\Support\RequestReadCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PDOException;
use RuntimeException;
use Tests\Support\CreatesAccounts;
use Tests\TestCase;

class RequestReadCacheTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    public function test_locked_post_phase_reuses_only_authorization_reads_after_the_current_guard(): void
    {
        $account = $this->account(AccountType::System);
        $actor = $this->userFor($account);
        $request = Request::create('/api/v1/sales', 'POST');
        $this->app->instance('request', $request);
        app(MutationGuard::class)->lock($actor);
        $permissions = $actor->membership->permissions();
        DB::enableQueryLog();
        for ($i = 0; $i < 3; $i++) {
            $actor->membership->permissions();
            $actor->membership->scopeRoots();
            $account->isOperational();
            app(CatalogAccess::class)->forAccount($account->id);
        }
        $uncached = count(DB::getQueryLog());
        $this->assertGreaterThan(20, $uncached);
        RequestReadCache::withinLockedAuthorization(function () use ($actor, $account, $permissions): void {
            $actor->membership->permissions();
            $actor->membership->scopeRoots();
            $account->isOperational();
            app(CatalogAccess::class)->forAccount($account->id);
            DB::flushQueryLog();
            for ($i = 0; $i < 20; $i++) {
                $this->assertSame($permissions, $actor->membership->permissions());
                $this->assertSame([], $actor->membership->scopeRoots());
                $this->assertTrue($account->isOperational());
                app(CatalogAccess::class)->forAccount($account->id);
            }
            $this->assertCount(0, DB::getQueryLog());
        });
        DB::disableQueryLog();
        $this->assertFalse($request->attributes->has('masal.locked_authorization_cache'));
    }

    public function test_locked_post_never_reuses_get_values_or_financial_and_operation_values(): void
    {
        $request = Request::create('/api/v1/digital/orders', 'POST');
        $request->attributes->set('masal.read_cache', ['Account.isOperational:fixture' => 'stale-get']);
        $this->app->instance('request', $request);
        RequestReadCache::withinLockedAuthorization(function (): void {
            $this->assertSame('fresh-post', RequestReadCache::remember('Account.isOperational:fixture', fn () => 'fresh-post'));
            foreach (['TopupWallets.balance:1', 'TopupDistribution.effective:1', 'OperationGuard.reason:1:sales', 'Account.isOperationalUnexpected:1'] as $key) {
                $value = 0;
                $resolve = function () use (&$value): int {
                    return ++$value;
                };
                $this->assertSame(1, RequestReadCache::remember($key, $resolve));
                $this->assertSame(2, RequestReadCache::remember($key, $resolve));
            }
        });
        $this->assertSame(['Account.isOperational:fixture' => 'stale-get'], $request->attributes->get('masal.read_cache'));
        $this->assertFalse($request->attributes->has('masal.locked_authorization_cache'));
    }

    public function test_failed_write_phase_clears_its_cache_and_the_next_phase_sees_changed_permissions(): void
    {
        $account = $this->account(AccountType::System);
        $actor = $this->userFor($account);
        $request = Request::create('/api/v1/digital/orders', 'POST');
        $this->app->instance('request', $request);
        app(MutationGuard::class)->lock($actor);
        try {
            RequestReadCache::withinLockedAuthorization(function () use ($actor): void {
                $this->assertContains('digital.create', $actor->membership->permissions());
                throw new RuntimeException('Fixture phase failed.');
            });
            $this->fail('The fixture phase must throw.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Fixture phase failed.', $exception->getMessage());
        }
        $this->assertFalse($request->attributes->has('masal.locked_authorization_cache'));
        DB::table('membership_permissions')->insert(['membership_id' => $actor->membership->id, 'permission_id' => DB::table('permissions')->where('name', 'digital.create')->value('id'), 'allowed' => false]);
        app(MutationGuard::class)->lock($actor);
        RequestReadCache::withinLockedAuthorization(function () use ($actor): void {
            $this->assertNotContains('digital.create', $actor->membership->permissions());
        });
        $this->assertFalse($request->attributes->has('masal.locked_authorization_cache'));
    }

    public function test_database_retry_starts_a_fresh_authorization_cache_and_restores_nested_scope(): void
    {
        $request = Request::create('/api/v1/sales', 'POST');
        $this->app->instance('request', $request);
        $original = DB::getDefaultConnection();
        config(['database.connections.cache_retry' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
        DB::setDefaultConnection('cache_retry');
        $attempts = 0;
        $reads = 0;
        try {
            $result = DB::transaction(function () use (&$attempts, &$reads): int {
                $attempts++;

                return RequestReadCache::withinLockedAuthorization(function () use (&$attempts, &$reads): int {
                    $resolve = function () use (&$reads): int {
                        return ++$reads;
                    };
                    $value = RequestReadCache::remember('Account.isOperational:retry', $resolve);
                    $this->assertSame($value, RequestReadCache::remember('Account.isOperational:retry', $resolve));
                    if ($attempts === 1) {
                        throw new PDOException('Fixture serialization retry.', 40001);
                    }
                    RequestReadCache::withinLockedAuthorization(function () use ($resolve): void {
                        $this->assertSame(3, RequestReadCache::remember('Account.isOperational:retry', $resolve));
                    });
                    $this->assertSame(2, RequestReadCache::remember('Account.isOperational:retry', $resolve));

                    return $value;
                });
            }, 2);
            $this->assertSame(2, $result);
            $this->assertSame(2, $attempts);
            $this->assertSame(3, $reads);
            $this->assertFalse($request->attributes->has('masal.locked_authorization_cache'));
        } finally {
            DB::setDefaultConnection($original);
            DB::purge('cache_retry');
            config(['database.connections.cache_retry' => null]);
        }
    }

    public function test_repeated_authorization_reads_reuse_queries_only_in_the_same_get(): void
    {
        $account = $this->account(AccountType::System);
        $user = $this->userFor($account);
        $request = Request::create('/api/v1/dashboard/summary', 'GET');
        $request->attributes->set('masal.read_cache', []);
        $this->app->instance('request', $request);
        $permissions = $user->membership->permissions();
        $this->assertTrue($account->isOperational());
        DB::enableQueryLog();
        for ($i = 0; $i < 20; $i++) {
            $this->assertSame($permissions, $user->membership->permissions());
            $this->assertTrue($account->isOperational());
        }
        $this->assertCount(0, DB::getQueryLog());
        DB::disableQueryLog();
        DB::table('accounts')->where('id', $account->id)->update(['archived_at' => now()]);
        $next = Request::create('/api/v1/dashboard/summary', 'GET');
        $next->attributes->set('masal.read_cache', []);
        $this->app->instance('request', $next);
        $this->assertFalse($account->isOperational());
    }

    public function test_writes_and_reads_outside_middleware_never_reuse_values(): void
    {
        foreach (['POST', 'PATCH', 'DELETE', 'GET'] as $method) {
            $request = Request::create('/api/v1/accounts', $method);
            if ($method !== 'GET') {
                $request->attributes->set('masal.read_cache', []);
            }
            $this->app->instance('request', $request);
            $counter = 0;
            $resolve = function () use (&$counter): int {
                return ++$counter;
            };
            $this->assertSame(1, RequestReadCache::remember('test', $resolve));
            $this->assertSame(2, RequestReadCache::remember('test', $resolve));
        }
    }

    public function test_permission_revocation_is_effective_on_the_next_http_request(): void
    {
        $system = $this->account(AccountType::System);
        $admin = $this->userFor($system);
        $this->asPortalUser($admin);
        $this->getJson('/api/v1/reports/summary')->assertOk();
        DB::table('membership_permissions')->insert([
            'membership_id' => $admin->membership->id,
            'permission_id' => DB::table('permissions')->where('name', 'reports.view')->value('id'),
            'allowed' => false,
        ]);
        $this->getJson('/api/v1/reports/summary')->assertForbidden();
    }

    public function test_operation_guards_share_context_but_a_new_request_and_writes_see_new_stops(): void
    {
        $account = $this->account(AccountType::System);
        $guard = app(OperationGuard::class);
        $request = Request::create('/api/v1/accounts', 'GET');
        $request->attributes->set('masal.read_cache', []);
        $this->app->instance('request', $request);
        $this->assertNull($guard->reason($account->id, 'login'));
        DB::enableQueryLog();
        $this->assertNull($guard->reason($account->id, 'app'));
        $this->assertNull($guard->reason($account->id, 'sales'));
        $this->assertCount(0, DB::getQueryLog());
        DB::disableQueryLog();
        DirectStop::create(['account_id' => $account->id, 'stops' => ['app' => true]]);
        $next = Request::create('/api/v1/accounts', 'GET');
        $next->attributes->set('masal.read_cache', []);
        $this->app->instance('request', $next);
        $this->assertNotNull($guard->reason($account->id, 'app'));
        $write = Request::create('/api/v1/accounts', 'POST');
        $write->attributes->set('masal.read_cache', []);
        $this->app->instance('request', $write);
        $this->assertNotNull($guard->reason($account->id, 'sales'));
        DirectStop::whereKey($account->id)->update(['stops' => json_encode(['app' => false])]);
        $this->assertNull($guard->reason($account->id, 'sales'));
    }

    public function test_digital_catalog_provider_reads_are_bounded_as_offer_count_grows(): void
    {
        $account = $this->account(AccountType::System);
        $connection = DigitalConnection::factory()->create(['account_id' => $account->id, 'provider' => 'rabiaa']);
        for ($i = 0; $i < 24; $i++) {
            DigitalOffer::factory()->create(['connection_id' => $connection->id, 'remote_id' => (string) $i, 'remote_key' => hash('sha256', $i.':')]);
        }
        $connection->load('offers.product');
        $request = Request::create('/api/v1/digital/offers', 'GET');
        $request->attributes->set('masal.read_cache', []);
        $this->app->instance('request', $request);
        DB::enableQueryLog();
        $offers = app(DigitalAccess::class)->offers($connection, $account);
        $queries = collect(DB::getQueryLog())->filter(fn (array $row): bool => str_contains($row['query'], 'catalog_providers'));
        DB::disableQueryLog();
        $this->assertCount(24, $offers);
        $this->assertLessThanOrEqual(2, $queries->count());
        DB::table('catalog_providers')->where('id', $connection->provider_id)->update(['status' => 'inactive']);
        $request->attributes->set('masal.read_cache', []);
        $this->assertCount(0, app(DigitalAccess::class)->offers($connection->fresh(), $account));
    }

    public function test_loaded_offers_reuse_their_connection_without_a_query_per_category(): void
    {
        $connection = DigitalConnection::factory()->create();
        for ($i = 0; $i < 24; $i++) {
            DigitalOffer::factory()->create(['connection_id' => $connection->id, 'remote_id' => (string) $i, 'remote_key' => hash('sha256', 'inverse:'.$i)]);
        }
        $connection->load('offers');
        DB::enableQueryLog();
        foreach ($connection->offers as $offer) {
            $this->assertSame($connection, $offer->connection);
        }
        $this->assertCount(0, DB::getQueryLog());
        DB::disableQueryLog();
    }
}

<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Support\RequestReadCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesAccounts;
use Tests\TestCase;

class RequestReadCacheTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

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
}

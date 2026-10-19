<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\CatalogProduct;
use App\Models\Digital\DigitalConnection;
use App\Models\Digital\DigitalOffer;
use App\Models\Digital\DigitalOrder;
use App\Models\Digital\TopupCategory;
use App\Models\Sales\PrintPolicy;
use App\Models\User;
use App\Services\Finance\Money;
use App\Support\AccountPhone;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Handler\CurlMultiHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Promise\Utils;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;
use Tests\Support\CreatesSupport;
use Tests\TestCase;

class LargeDataReadTest extends TestCase
{
    use CreatesSupport;

    private array $registeredAgents = [];

    private array $registeredPointIds = [];

    private array $registeredPointParents = [];

    private array $registeredPointUsers = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (! app()->environment('testing') || ! in_array(DB::connection()->getDatabaseName(), [':memory:', 'dananiriq_masalverify'], true)) {
            throw new \RuntimeException('Scale tests require the disposable test database');
        }
        if (getenv('MASAL_SCALE_KEEP_FIXTURE') === '1' && (DB::getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== 'dananiriq_masalverify')) {
            throw new \RuntimeException('Keeping scale fixtures requires the disposable MySQL verification database');
        }
        RefreshDatabaseState::$migrated = false;
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    }

    protected function tearDown(): void
    {
        if ($this->app && app()->environment('testing') && DB::connection()->getDatabaseName() === 'dananiriq_masalverify' && getenv('MASAL_SCALE_KEEP_FIXTURE') !== '1') {
            $this->artisan('db:wipe', ['--force' => true])->assertSuccessful();
        }
        RefreshDatabaseState::$migrated = false;
        parent::tearDown();
    }

    public function test_scoped_reads_at_ten_thousand_points_and_one_hundred_thousand_operations(): void
    {
        $f = $this->supportFixture();
        $operationCount = (int) (getenv('MASAL_SCALE_OPERATIONS') ?: 100000);
        $digitalCount = (int) (getenv('MASAL_SCALE_DIGITAL_ORDERS') ?: 0);
        $workers = (int) (getenv('MASAL_SCALE_WORKERS') ?: 0);
        $agentCount = (int) (getenv('MASAL_SCALE_AGENTS') ?: 1);
        $this->assertContains($agentCount, [1, 100]);
        $this->assertContains($operationCount, [100000, 1000000, 3000000]);
        $this->assertContains($digitalCount, [0, 100000, 1000000, 3000000]);
        $this->assertContains($workers, [0, 2, 4, 8, 16]);
        if ($workers || $operationCount > 100000 || $digitalCount) {
            $this->assertTrue(app()->environment('testing'));
            $this->assertSame('dananiriq_masalverify', DB::connection()->getDatabaseName());
            $this->assertTrue(function_exists('pcntl_fork'));
        }
        $baseTime = time();
        if ($agentCount === 100) {
            $firstId = $this->distributedAccounts($f);
        } else {
            $firstId = (int) DB::table('accounts')->max('id') + 1;
            $template = $f['pos']->getAttributes();
            unset($template['id']);
            $points = [];
            $closure = [];
            for ($i = 0; $i < 10000; $i++) {
                $id = $firstId + $i;
                $points[] = array_replace($template, ['id' => $id, 'name' => 'Scale point '.$i, 'parent_id' => $f['main']->id]);
                $closure[] = ['ancestor_id' => $id, 'descendant_id' => $id, 'depth' => 0];
                $closure[] = ['ancestor_id' => $f['main']->id, 'descendant_id' => $id, 'depth' => 1];
                $closure[] = ['ancestor_id' => $f['system']->id, 'descendant_id' => $id, 'depth' => 2];
            }
            foreach (array_chunk($points, 200) as $chunk) {
                DB::table('accounts')->insert($chunk);
            }
            foreach (array_chunk($closure, 1000) as $chunk) {
                DB::table('account_closure')->insert($chunk);
            }
        }
        $operations = [];
        for ($i = 0; $i < $operationCount; $i++) {
            $pointIndex = $i % 10000;
            $pointId = $this->registeredPointIds[$pointIndex] ?? ($pointIndex === 0 ? $f['pos']->id : $firstId + $pointIndex);
            $operations[] = ['user_id' => $this->registeredPointUsers[$pointIndex] ?? $f['agent']->id, 'account_id' => $pointId, 'subject_account_id' => $pointId, 'action' => 'wallets.deposit', 'portal' => 'agents', 'created_at' => gmdate('Y-m-d H:i:s', $baseTime - $i), 'details' => $this->registeredAgents ? json_encode(['test_only' => true, 'reference' => 'Synthetic capacity history', 'context' => str_repeat('scale ', 24)]) : '{}'];
            if (count($operations) === 500) {
                DB::table('audit_logs')->insert($operations);
                $operations = [];
                if (($i + 1) % 250000 === 0) {
                    fwrite(STDOUT, 'SCALE_SEEDED audit '.($i + 1).PHP_EOL);
                }
            }
        }
        unset($points, $closure, $template, $operations);
        if ($digitalCount) {
            $connection = DigitalConnection::factory()->create(['account_id' => $f['main']->id, 'provider' => 'topup']);
            $offer = DigitalOffer::factory()->create(['connection_id' => $connection->id, 'type' => 'topup']);
            $historyOffers = [$offer];
            if ($this->registeredAgents) {
                for ($sku = 1; $sku < 86; $sku++) {
                    $product = CatalogProduct::factory()->create(['provider_id' => $offer->product->provider_id, 'name' => 'Scale history SKU '.$sku]);
                    $historyOffers[] = DigitalOffer::factory()->create(['connection_id' => $connection->id, 'catalog_snapshot_id' => $offer->catalog_snapshot_id, 'remote_id' => 'scale-history-'.$sku, 'remote_key' => hash('sha256', 'scale-history-'.$sku.':'), 'product_id' => $product->id, 'type' => 'topup', 'cost_minor' => 430000, 'retail_minor' => 500000]);
                }
            }
            $template = DigitalOrder::factory()->create(['offer_id' => $offer->id, 'connection_id' => $connection->id, 'product_id' => $offer->product_id, 'main_account_id' => $f['main']->id, 'account_id' => $f['pos']->id, 'creator_id' => $f['posUser']->id, 'provider' => 'topup', 'status' => 'succeeded', 'reservation_active' => false, 'actual_cost_minor' => 430000, 'actual_retail_minor' => 500000, 'cost_basis' => 'provider_response', 'company_transaction_id' => 'scale-provider-initial', 'receipt_ref' => 'scale-receipt-initial', 'resolved_at' => now(), 'type' => 'topup', 'acknowledged_at' => now(), 'receipt' => $this->registeredAgents ? ['test_only' => true, 'payload' => str_repeat('synthetic receipt ', 24)] : null, 'provider_purchase_response' => $this->registeredAgents ? ['test_only' => true, 'status' => 'Succeed', 'payload' => str_repeat('synthetic response ', 16)] : null])->getAttributes();
            unset($template['id']);
            $batch = [];
            for ($i = 1; $i < $digitalCount; $i++) {
                $pointIndex = $i % 10000;
                $historyOffer = $historyOffers[intdiv($i, 10000) % count($historyOffers)];
                $batch[] = array_replace($template, ['offer_id' => $historyOffer->id, 'product_id' => $historyOffer->product_id, 'remote_id' => $historyOffer->remote_id, 'request_id' => 'scale-digital-'.$i, 'company_transaction_id' => 'scale-provider-'.$i, 'receipt_ref' => 'scale-receipt-'.$i, 'account_id' => $this->registeredPointIds[$pointIndex] ?? ($pointIndex === 0 ? $f['pos']->id : $firstId + $pointIndex), 'main_account_id' => $this->registeredPointParents[$pointIndex] ?? $f['main']->id, 'creator_id' => $this->registeredPointUsers[$pointIndex] ?? $f['posUser']->id, 'created_at' => gmdate('Y-m-d H:i:s', $baseTime - $i)]);
                if (count($batch) === 200 || $i === $digitalCount - 1) {
                    DB::table('digital_orders')->insert($batch);
                    $batch = [];
                }
                if (($i + 1) % 250000 === 0) {
                    fwrite(STDOUT, 'SCALE_SEEDED digital '.($i + 1).PHP_EOL);
                }
            }
            unset($template, $batch);
        }
        $queries = [];
        DB::listen(function ($event) use (&$queries): void {
            if (count($queries) < 500) {
                $queries[] = ['sql' => $event->sql, 'ms' => $event->time];
            }
        });
        $results = [];
        $readers = ['admin' => $f['admin'], 'agent' => $f['agent'], 'pos' => $f['posUser']];
        if ($this->registeredAgents) {
            $readers['agent_last'] = $this->registeredAgents[99]['user'];
            $readers['pos_last'] = User::findOrFail($this->registeredPointUsers[9999]);
            $readers['agent_employee_point_scope'] = $this->supportEmployee($f['main'], $f['pos'], ['account.view', 'dashboard.view', 'digital.view', 'data.cost', 'wallets.view', 'sales.view', 'reports.view']);
        }
        foreach ($readers as $level => $user) {
            $this->asPortalUser($user);
            $paths = ['/accounts?per_page=25', '/account-lookup?per_page=25', '/reports/options?compact=1', '/digital/options?compact=1', '/dashboard/activity?limit=20', '/dashboard/activity?limit=all', '/dashboard/summary?view=cards', '/dashboard/summary', '/finance/options?compact=1', '/finance/accounts?per_page=25', '/finance/accounts?account_query=Scale%20point%209999&per_page=25', '/finance/wallets?per_page=25&summary=1&metrics=0', '/digital/orders?per_page=25', '/digital/orders/summary?provider=topup'];
            if ($level === 'agent_employee_point_scope') {
                $paths = ['/dashboard/activity?limit=20', '/dashboard/summary?view=cards', '/dashboard/summary', '/digital/orders?per_page=25', '/digital/orders/summary?provider=topup', '/digital/connections?provider=topup'];
            }
            foreach ($paths as $path) {
                $queries = [];
                $start = hrtime(true);
                $response = $this->getJson('/api/v1'.$path)->assertOk();
                $slow = collect($queries)->sortByDesc('ms')->take(3)->values()->all();
                $results[] = ['slow_queries' => $slow, 'level' => $level, 'path' => $path, 'ms' => round((hrtime(true) - $start) / 1000000, 2), 'bytes' => strlen($response->getContent())];
                if (str_starts_with($path, '/accounts') || str_starts_with($path, '/finance/accounts')) {
                    $this->assertLessThanOrEqual(25, count($response->json('data')));
                }
                if (str_starts_with($level, 'pos') && $digitalCount && $path === '/digital/orders?per_page=25') {
                    $this->assertNotEmpty($response->json('data'));
                    $this->assertSame((int) ($digitalCount / 10000), $response->json('meta.total'));
                    $this->assertLessThanOrEqual(25, count($response->json('data')));
                }
                if (str_starts_with($path, '/finance/options')) {
                    $this->assertLessThanOrEqual(1, count($response->json('data.accounts')));
                    $this->assertLessThan(10000, strlen($response->getContent()));
                }
                if (str_starts_with($path, '/dashboard/activity')) {
                    $this->assertLessThanOrEqual(str_contains($path, 'limit=all') ? 100 : 20, count($response->json('data')));
                }
            }
        }
        if ($this->registeredAgents) {
            $this->assertDistributedIsolation($f, $digitalCount, $results);
        }
        $this->assertSame((int) DB::table('digital_orders')->count(), (int) DB::table('digital_order_totals')->sum('order_count'));
        $this->assertSame((int) DB::table('digital_orders')->sum('actual_retail_minor'), (int) DB::table('digital_order_totals')->sum('retail_minor'));
        $this->assertSame((int) DB::table('digital_orders')->count(), (int) DB::table('digital_account_totals')->sum('order_count'));
        $this->assertSame((int) DB::table('digital_orders')->sum('actual_cost_minor'), (int) DB::table('digital_account_totals')->sum('cost_minor'));
        $this->assertSame((int) DB::table('digital_orders')->sum('actual_retail_minor'), (int) DB::table('digital_account_totals')->sum('retail_minor'));
        $this->assertDatabaseCount('finance_transactions', 0);
        fwrite(STDOUT, 'SCALE_READ '.json_encode(['main_agents' => $agentCount, 'history_products' => isset($historyOffers) ? count($historyOffers) : 0, 'registered_point_owners' => count($this->registeredPointUsers), 'points' => 10000, 'operations' => $operationCount, 'digital_orders' => $digitalCount, 'results' => $results], JSON_UNESCAPED_SLASHES).PHP_EOL);
        if ($workers) {
            $this->concurrentReadsAndLedger($f, $firstId, $workers);
            $this->httpTopupLoad($f, $workers);
        }
        if (DB::getDriverName() === 'mysql') {
            $sizes = DB::table('information_schema.tables')->where('TABLE_SCHEMA', DB::connection()->getDatabaseName())->whereIn('TABLE_NAME', ['digital_orders', 'digital_order_totals', 'digital_account_totals', 'audit_logs', 'users', 'accounts'])->selectRaw('TABLE_NAME AS name,DATA_LENGTH AS data_bytes,INDEX_LENGTH AS index_bytes,TABLE_ROWS AS estimated_rows')->get();
            fwrite(STDOUT, 'SCALE_STORAGE '.json_encode(['tables' => $sizes, 'rows_are_engine_estimates' => true]).PHP_EOL);
        }

    }

    private function distributedAccounts(array $fixture): int
    {
        $mainIds = [$fixture['main']->id];
        $agentOwners = [$fixture['agent']->id];
        $nextAccountId = (int) DB::table('accounts')->max('id') + 1;
        $nextUserId = (int) DB::table('users')->max('id') + 1;
        $mainTemplate = $fixture['main']->getAttributes();
        $pointTemplate = $fixture['pos']->getAttributes();
        $userTemplate = $fixture['posUser']->getAttributes();
        $memberTemplate = $fixture['posUser']->membership->getAttributes();
        unset($mainTemplate['id'], $pointTemplate['id'], $userTemplate['id'], $memberTemplate['id']);
        $roles = DB::table('roles')->pluck('id', 'name');
        $accounts = $users = $members = $closure = [];
        for ($agent = 1; $agent < 99; $agent++) {
            $accountId = $nextAccountId++;
            $userId = $nextUserId++;
            $mainIds[] = $accountId;
            $agentOwners[] = $userId;
            $phone = '0780'.str_pad((string) (10000 + $agent), 7, '0', STR_PAD_LEFT);
            $accounts[] = array_replace($mainTemplate, ['id' => $accountId, 'name' => 'Scale agent '.$agent, 'phone' => $phone, 'phone_key' => AccountPhone::key($phone)]);
            $users[] = array_replace($userTemplate, ['id' => $userId, 'name' => 'Scale agent '.$agent, 'login' => 'scale-agent-'.$agent, 'email' => 'scale-agent-'.$agent.'@example.invalid', 'remember_token' => null]);
            $members[] = array_replace($memberTemplate, ['user_id' => $userId, 'account_id' => $accountId, 'role_id' => $roles['agent']]);
            $closure[] = ['ancestor_id' => $accountId, 'descendant_id' => $accountId, 'depth' => 0];
            $closure[] = ['ancestor_id' => $fixture['system']->id, 'descendant_id' => $accountId, 'depth' => 1];
        }
        $mainIds[] = $fixture['foreign']->id;
        $agentOwners[] = $fixture['foreignUser']->id;
        $firstPointId = $nextAccountId;
        $this->registeredPointIds = [$fixture['pos']->id];
        $this->registeredPointParents = [$fixture['main']->id];
        $this->registeredPointUsers = [$fixture['posUser']->id];
        for ($point = 1; $point < 10000; $point++) {
            $accountId = $nextAccountId++;
            $userId = $nextUserId++;
            $parentId = $mainIds[intdiv($point, 100)];
            $phone = '0770'.str_pad((string) $point, 7, '0', STR_PAD_LEFT);
            $this->registeredPointIds[] = $accountId;
            $this->registeredPointParents[] = $parentId;
            $this->registeredPointUsers[] = $userId;
            $accounts[] = array_replace($pointTemplate, ['id' => $accountId, 'name' => 'Scale point '.$point, 'parent_id' => $parentId, 'device_lock_enabled' => false, 'phone' => $phone, 'phone_key' => AccountPhone::key($phone)]);
            $users[] = array_replace($userTemplate, ['id' => $userId, 'name' => 'Scale point '.$point, 'login' => 'scale-point-'.$point, 'email' => 'scale-point-'.$point.'@example.invalid', 'remember_token' => null]);
            $members[] = array_replace($memberTemplate, ['user_id' => $userId, 'account_id' => $accountId, 'role_id' => $roles['pos']]);
            $closure[] = ['ancestor_id' => $accountId, 'descendant_id' => $accountId, 'depth' => 0];
            $closure[] = ['ancestor_id' => $parentId, 'descendant_id' => $accountId, 'depth' => 1];
            $closure[] = ['ancestor_id' => $fixture['system']->id, 'descendant_id' => $accountId, 'depth' => 2];
        }
        foreach (['accounts' => $accounts, 'users' => $users, 'account_memberships' => $members, 'account_closure' => $closure] as $table => $rows) {
            foreach (array_chunk($rows, $table === 'account_closure' ? 1000 : 200) as $chunk) {
                DB::table($table)->insert($chunk);
            }
        }
        $accountModels = Account::whereIn('id', $mainIds)->get()->keyBy('id');
        $userModels = User::whereIn('id', $agentOwners)->get()->keyBy('id');
        foreach ($mainIds as $index => $id) {
            $this->registeredAgents[] = ['account' => $accountModels[$id], 'user' => $userModels[$agentOwners[$index]]];
        }
        $this->assertSame(100, Account::where('type', 'main_agent')->count());
        $this->assertSame(10000, Account::where('type', 'pos')->count());
        $this->assertSame(10100, AccountMembership::where('kind', 'owner')->whereIn('account_id', array_merge($mainIds, $this->registeredPointIds))->count());
        fwrite(STDOUT, 'SCALE_REGISTERED '.json_encode(['main_agents' => 100, 'points' => 10000, 'point_owners' => 10000, 'points_per_agent' => 100]).PHP_EOL);

        return $firstPointId;
    }

    private function assertDistributedIsolation(array $fixture, int $digitalCount, array &$results): void
    {
        foreach ($this->registeredAgents as $index => $registered) {
            $this->asPortalUser($registered['user']);
            $this->getJson('/api/v1/account-lookup?account_type=pos&per_page=1')->assertOk()->assertJsonPath('meta.total', 100);
            if ($digitalCount) {
                $this->getJson('/api/v1/digital/orders/summary?provider=topup')->assertOk()->assertJsonPath('data.quantity', (int) ($digitalCount / 100))
                    ->assertJsonPath('data.retail', Money::decimal((int) ($digitalCount / 100) * 500000));
            }
        }
        $this->asPortalUser($fixture['agent']);
        $foreignPoint = $this->registeredPointIds[9999];
        $this->getJson('/api/v1/account-lookup?account_id='.$foreignPoint)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/accounts/'.$foreignPoint)->assertNotFound();
        if ($digitalCount) {
            $foreignOrder = DB::table('digital_orders')->where('account_id', $foreignPoint)->value('id');
            $this->getJson('/api/v1/digital/orders/'.$foreignOrder)->assertNotFound();
            $this->getJson('/api/v1/digital/orders?account_id='.$foreignPoint)->assertNotFound();
        }
        foreach (['admin' => $fixture['admin'], 'agent' => $fixture['agent'], 'pos' => $fixture['posUser']] as $level => $actor) {
            $this->asPortalUser($actor);
            $total = $digitalCount / ($level === 'admin' ? 1 : ($level === 'agent' ? 100 : 10000));
            if ($total) {
                $page = max(1, (int) ceil($total / 25));
                $start = hrtime(true);
                $response = $this->getJson('/api/v1/digital/orders?per_page=25&page='.$page)->assertOk();
                $results[] = ['level' => $level, 'path' => '/digital/orders?per_page=25&page='.$page, 'ms' => round((hrtime(true) - $start) / 1000000, 2), 'bytes' => strlen($response->getContent())];
                $this->assertNotEmpty($response->json('data'));
                $this->assertLessThanOrEqual(25, count($response->json('data')));
            }
        }
        fwrite(STDOUT, 'SCALE_ISOLATION '.json_encode(['agents_checked' => 100, 'each_agent_visible_points' => 100, 'foreign_point_and_order_denied' => true]).PHP_EOL);
    }

    private function httpTopupLoad(array $fixture, int $workers): void
    {
        if (! getenv('MASAL_SCALE_HTTP')) {
            return;
        }
        $this->assertTrue(function_exists('posix_kill'));
        Http::preventStrayRequests();
        config(['digital.topup.driver' => 'masal_v2_1', 'digital.purchases_enabled' => true]);
        Http::fake(function ($request) {
            if (str_ends_with($request->url(), '/inventory')) {
                return Http::response('100000000', 200, ['Content-Type' => 'application/json']);
            }

            return Http::response(['status' => 'ok', 'products' => [['id' => 49, 'title' => '6000', 'type' => 'topup', 'price' => 6000, 'show2site' => 1]]]);
        });
        $this->asPortalUser($fixture['admin']);
        $connection = $this->postJson('/api/v1/digital/connections', ['provider' => 'topup', 'account_id' => $fixture['system']->id, 'version' => 0, 'active' => true, 'credential' => 'isolated-scale-central-token', 'offers' => [], 'idempotency_key' => 'scale-central-connect'])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/digital/connections/'.$connection.'/sync', ['version' => 1])->assertOk();
        $category = TopupCategory::where('connection_id', $connection)->firstOrFail();
        $allocationAmount = $this->registeredAgents ? '500000.00' : '5000000.00';
        $allocationTargets = $this->registeredAgents ?: [['account' => $fixture['main'], 'user' => $fixture['agent']]];
        foreach ($allocationTargets as $index => $recipient) {
            $this->asPortalUser($fixture['admin']);
            $this->putJson('/api/v1/topup/distribution/'.$recipient['account']->id, ['version' => 0, 'connection_id' => $connection, 'allocation_balance' => $allocationAmount, 'category_ids' => [$category->id], 'active' => true, 'idempotency_key' => 'scale-central-allocation-'.$index])->assertOk();
        }
        $buyers = $buyerMainIds = [];
        for ($worker = 0; $worker < $workers; $worker++) {
            if ($this->registeredAgents) {
                $pointIndex = $worker * 100 + 1;
                $point = Account::findOrFail($this->registeredPointIds[$pointIndex]);
                $buyer = User::findOrFail($this->registeredPointUsers[$pointIndex]);
                $agent = $this->registeredAgents[$worker]['user'];
                $buyerMainIds[] = $this->registeredPointParents[$pointIndex];
            } else {
                $point = $this->account(AccountType::Pos, $fixture['main']);
                $point->update(['device_lock_enabled' => false]);
                $buyer = $this->userFor($point);
                $agent = $fixture['agent'];
                $buyerMainIds[] = $fixture['main']->id;
            }
            $buyers[] = $buyer;
            $this->asPortalUser($agent);
            $this->putJson('/api/v1/topup/distribution/'.$point->id, ['version' => 0, 'category_ids' => [$category->id], 'active' => true, 'idempotency_key' => 'scale-point-grant-'.$worker])->assertOk();
        }
        $policy = PrintPolicy::findOrFail(1);
        $settings = $policy->settings;
        $settings['velocity_seconds'] = 0;
        $settings['provider_daily'] = ['IQD' => null];
        $policy->update(['settings' => $settings]);
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $port = (int) substr(strrchr($address, ':'), 1);
        $environment = ['APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'APP_CONFIG_CACHE' => base_path('tests/Support/nonexistent-config-cache.php'), 'APP_ROUTES_CACHE' => base_path('tests/Support/nonexistent-routes-cache.php'), 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'dananiriq_masalverify', 'DB_URL' => '', 'SESSION_DRIVER' => 'file', 'SESSION_SECURE_COOKIE' => 'false', 'CACHE_STORE' => 'array', 'MAPS_REQUIRE_LOCATION' => 'false', 'MAIL_MAILER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'BROADCAST_CONNECTION' => 'null', 'PHP_CLI_SERVER_WORKERS' => (string) min(8, $workers)];
        foreach (['host', 'port', 'username', 'password'] as $key) {
            $environment['DB_'.strtoupper($key)] = config('database.connections.mysql.'.$key);
        }
        $providerDelay = (int) (getenv('MASAL_SCALE_PROVIDER_DELAY_MS') ?: 100);
        $this->assertContains($providerDelay, [100, 1000]);
        $environment['MASAL_SCALE_PROVIDER_DELAY_MS'] = (string) $providerDelay;
        $server = new Process(['setsid', PHP_BINARY, '-S', '127.0.0.1:'.$port, base_path('tests/Support/scale-http-router.php')], base_path(), $environment, null, 600);
        $server->disableOutput();
        $server->start();
        $folder = storage_path('framework/testing/http-scale-'.bin2hex(random_bytes(8)));
        mkdir($folder, 0700, true);
        $pids = [];
        $loops = (int) (getenv('MASAL_SCALE_HTTP_LOOPS') ?: 20);
        $this->assertContains($loops, [20, 100, 200]);
        $wall = hrtime(true);
        try {
            for ($attempt = 0; $attempt < 100; $attempt++) {
                if ($probe = @fsockopen('127.0.0.1', $port, $errno, $error, .1)) {
                    fclose($probe);
                    break;
                }usleep(100000);
            }
            DB::disconnect();
            for ($worker = 0; $worker < $workers; $worker++) {
                $pid = pcntl_fork();
                if ($pid === -1) {
                    throw new \RuntimeException('Unable to create HTTP client');
                }
                if ($pid === 0) {
                    // Forked HTTP clients must not terminate the parent's server during shutdown.
                    (new \ReflectionProperty(Process::class, 'status'))->setValue($server, Process::STATUS_TERMINATED);
                    $errors = [];
                    $timings = [];
                    $sales = 0;
                    $requests = 0;
                    $saleTimes = $readTimes = [];
                    try {
                        $makeClient = static function (string $portal) use ($port): Client {
                            $jar = new CookieJar;
                            $headers = ['Accept' => 'application/json', 'Host' => config('portals.hosts.'.$portal), 'X-Masal-Portal' => $portal, 'X-Requested-With' => 'XMLHttpRequest'];
                            $options = ['base_uri' => 'http://127.0.0.1:'.$port, 'cookies' => $jar, 'http_errors' => false, 'timeout' => 30, 'connect_timeout' => 5, 'headers' => $headers];
                            $csrf = (new Client($options))->get('/sanctum/csrf-cookie');
                            if ($csrf->getStatusCode() !== 204) {
                                throw new \RuntimeException('HTTP CSRF initialization failed');
                            }
                            $token = $jar->getCookieByName('XSRF-TOKEN');
                            if (! $token) {
                                throw new \RuntimeException('HTTP CSRF cookie missing');
                            }
                            $stack = HandlerStack::create();
                            $stack->push(Middleware::mapRequest(function ($request) use ($jar) {
                                $token = $jar->getCookieByName('XSRF-TOKEN');

                                return $token ? $request->withHeader('X-XSRF-TOKEN', rawurldecode($token->getValue())) : $request;
                            }));
                            $options['handler'] = $stack;

                            return new Client($options);
                        };
                        $client = $makeClient('pos');
                        $login = $client->post('/api/v1/auth/login', ['json' => ['login' => $buyers[$worker]->login, 'password' => 'password']]);
                        if ($login->getStatusCode() !== 200) {
                            throw new \RuntimeException('HTTP test login failed: '.$login->getStatusCode());
                        }
                        $available = json_decode((string) $client->get('/api/v1/topup/available')->getBody(), true, 512, JSON_THROW_ON_ERROR);
                        $offer = $available['data']['categories'][0]['offer'];
                        $readActor = $this->registeredAgents ? [$fixture['admin'], $this->registeredAgents[$worker]['user'], $buyers[$worker]][$worker % 3] : [$fixture['admin'], $fixture['agent'], $fixture['posUser']][$worker % 3];
                        $readPortal = ['admin', 'agents', 'pos'][$worker % 3];
                        $readClient = $makeClient($readPortal);
                        $readLogin = $readClient->post('/api/v1/auth/login', ['json' => ['login' => $readActor->login, 'password' => 'password']]);
                        if ($readLogin->getStatusCode() !== 200) {
                            throw new \RuntimeException('HTTP reader login failed: '.$readLogin->getStatusCode());
                        }
                        for ($i = 0; $i < $loops; $i++) {
                            $start = hrtime(true);
                            if ($i % 5 === 0) {
                                $requests++;
                                $heartbeat = $client->post('/api/v1/sales/device-session', ['json' => ['app_version' => '1.0.0']]);
                                if ($heartbeat->getStatusCode() !== 200) {
                                    throw new \RuntimeException('HTTP device heartbeat failed: '.$heartbeat->getStatusCode());
                                }
                                $body = ['request_id' => 'http-scale-'.$worker.'-'.$i, 'connection_id' => $connection, 'offer_id' => $offer['id'], 'offer_version' => $offer['version'], 'expected_retail' => $offer['retail'], 'mobile' => '07701234567', 'confirm_mobile' => '07701234567'];
                                $requests++;
                                $saleStart = hrtime(true);
                                $response = $client->post('/api/v1/digital/orders', ['json' => $body]);
                                $result = json_decode((string) $response->getBody(), true);
                                if ($response->getStatusCode() !== 201 || ($result['data']['status'] ?? null) !== 'succeeded') {
                                    throw new \RuntimeException('HTTP purchase failed: '.($result['message'] ?? $response->getStatusCode()));
                                }
                                $saleTimes[] = round((hrtime(true) - $saleStart) / 1000000, 2);
                                $requests++;
                                $retry = $client->post('/api/v1/digital/orders', ['json' => $body]);
                                $replayed = json_decode((string) $retry->getBody(), true);
                                if ($retry->getStatusCode() !== 201 || ($replayed['data']['id'] ?? null) !== $result['data']['id']) {
                                    throw new \RuntimeException('HTTP duplicate purchase was not stable');
                                }
                                $requests++;
                                $ack = $client->post('/api/v1/digital/orders/'.$result['data']['id'].'/acknowledge', ['json' => []]);
                                if ($ack->getStatusCode() !== 200) {
                                    throw new \RuntimeException('HTTP acknowledgment failed');
                                }$sales++;
                            } else {
                                $paths = ['/finance/options?compact=1', '/account-lookup?per_page=25', '/dashboard/activity?limit=20', '/digital/orders?per_page=25'];
                                $requests++;
                                $readStart = hrtime(true);
                                $response = $readClient->get('/api/v1'.$paths[$i % 4]);
                                $readTimes[] = round((hrtime(true) - $readStart) / 1000000, 2);
                                if ($response->getStatusCode() !== 200) {
                                    throw new \RuntimeException('HTTP read failed: '.$response->getStatusCode());
                                }
                            }
                            $timings[] = round((hrtime(true) - $start) / 1000000, 2);
                        }
                    } catch (\Throwable $failed) {
                        $errors[] = ['class' => get_class($failed), 'message' => $failed->getMessage()];
                    }
                    file_put_contents($folder.'/'.$worker.'.json', json_encode(compact('timings', 'errors', 'sales', 'requests', 'saleTimes', 'readTimes'), JSON_THROW_ON_ERROR));
                    exit($errors ? 1 : 0);
                }
                $pids[] = $pid;
            }
            $statuses = [];
            foreach ($pids as $pid) {
                pcntl_waitpid($pid, $status);
                $statuses[] = pcntl_wifexited($status) ? pcntl_wexitstatus($status) : -1;
            }
            DB::reconnect();
            $elapsed = (hrtime(true) - $wall) / 1000000000;
            $times = [];
            $errors = [];
            $sales = $requests = 0;
            $saleTimes = $readTimes = [];
            foreach (glob($folder.'/*.json') as $file) {
                $row = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
                $times = [...$times, ...$row['timings']];
                $errors = [...$errors, ...$row['errors']];
                $sales += $row['sales'];
                $requests += $row['requests'];
                $saleTimes = [...$saleTimes, ...$row['saleTimes']];
                $readTimes = [...$readTimes, ...$row['readTimes']];
                unlink($file);
            }rmdir($folder);
            sort($times);
            sort($saleTimes);
            sort($readTimes);
            fwrite(STDOUT, 'SCALE_HTTP '.json_encode(['clients' => $workers, 'server_workers' => min(8, $workers), 'main_agents_selling' => count(array_unique($buyerMainIds)), 'workflow_count' => count($times), 'completed_topup_sales' => $sales, 'requests' => $requests, 'elapsed_seconds' => round($elapsed, 2), 'requests_per_second' => round($requests / $elapsed, 2), 'p95_workflow_ms' => $times[(int) floor(count($times) * .95)] ?? null, 'p95_sale_request_ms' => $saleTimes[(int) floor(count($saleTimes) * .95)] ?? null, 'p95_read_request_ms' => $readTimes[(int) floor(count($readTimes) * .95)] ?? null, 'provider_delay_ms' => $providerDelay, 'errors' => $errors, 'provider' => 'mock; no live recharge', 'includes_bootstrap_sessions_and_auth' => true], JSON_UNESCAPED_SLASHES).PHP_EOL);
            $this->assertSame(array_fill(0, $workers, 0), $statuses);
            $this->assertSame([], $errors);
            $this->assertSame($workers * (int) ($loops / 5), $sales);
            $this->assertSame($workers * (int) ($loops / 5), DigitalOrder::where('connection_id', $connection)->count());
            foreach ($allocationTargets as $recipient) {
                $spent = count(array_filter($buyerMainIds, fn ($id) => $id === $recipient['account']->id)) * (int) ($loops / 5) * 600000;
                $this->assertDatabaseHas('topup_grants', ['target_account_id' => $recipient['account']->id, 'balance_minor' => ($this->registeredAgents ? 50000000 : 500000000) - $spent, 'spent_minor' => $spent, 'held_minor' => 0]);
            }
            $this->assertDatabaseHas('digital_connections', ['id' => $connection, 'company_balance_minor' => 10000000000 - $sales * 600000]);
            $this->assertSame((int) DB::table('digital_orders')->count(), (int) DB::table('digital_order_totals')->sum('order_count'));
            $this->artisan('topup:audit-accounting')->assertSuccessful();
            $this->fixedRateSales($port, $connection, $category->id, $sales);
        } finally {
            if ($server->getPid()) {
                posix_kill(-$server->getPid(), SIGTERM);
            }$server->stop(2);
            DB::reconnect();
        }
    }

    private function fixedRateSales(int $port, int $connection, int $categoryId, int $previousSales): void
    {
        $rate = (int) (getenv('MASAL_SCALE_PEAK_RATE') ?: 0);
        if (! $rate) {
            return;
        }
        $this->assertSame(50, $rate);
        $count = 80;
        $multi = new CurlMultiHandler(['select_timeout' => .001]);
        $clients = $offers = [];
        $this->assertCount(100, $this->registeredAgents);
        foreach ($this->registeredAgents as $worker => $recipient) {
            $pointIndex = $worker * 100 + 2;
            $this->asPortalUser($recipient['user']);
            $this->putJson('/api/v1/topup/distribution/'.$this->registeredPointIds[$pointIndex], ['version' => 0, 'category_ids' => [$categoryId], 'active' => true, 'idempotency_key' => 'peak-grant-'.$worker])->assertOk();
            $buyer = User::findOrFail($this->registeredPointUsers[$pointIndex]);
            $jar = new CookieJar;
            $stack = HandlerStack::create($multi);
            $stack->push(Middleware::mapRequest(function ($request) use ($jar) {
                $token = $jar->getCookieByName('XSRF-TOKEN');

                return $token ? $request->withHeader('X-XSRF-TOKEN', rawurldecode($token->getValue())) : $request;
            }));
            $client = new Client(['handler' => $stack, 'cookies' => $jar, 'base_uri' => 'http://127.0.0.1:'.$port, 'http_errors' => false, 'timeout' => 30, 'connect_timeout' => 5, 'headers' => ['Accept' => 'application/json', 'Host' => config('portals.hosts.pos'), 'X-Masal-Portal' => 'pos', 'X-Requested-With' => 'XMLHttpRequest']]);
            $this->assertSame(204, $client->get('/sanctum/csrf-cookie')->getStatusCode());
            $this->assertSame(200, $client->post('/api/v1/auth/login', ['json' => ['login' => $buyer->login, 'password' => 'password']])->getStatusCode());
            $available = json_decode((string) $client->get('/api/v1/topup/available')->getBody(), true, 512, JSON_THROW_ON_ERROR);
            $offers[] = $available['data']['categories'][0]['offer'];
            $this->assertSame(200, $client->post('/api/v1/sales/device-session', ['json' => ['app_version' => '1.0.0']])->getStatusCode());
            $clients[] = $client;
        }
        $start = microtime(true);
        $next = $completed = 0;
        $promises = $results = $dispatches = [];
        while ($completed < $count) {
            while ($next < $count && microtime(true) >= $start + $next / $rate) {
                $index = $next++;
                $worker = $index % count($clients);
                $offer = $offers[$worker];
                $sent = microtime(true);
                $dispatches[] = round(($sent - $start) * 1000, 2);
                $body = ['request_id' => 'peak-scale-'.$index, 'connection_id' => $connection, 'offer_id' => $offer['id'], 'offer_version' => $offer['version'], 'expected_retail' => $offer['retail'], 'mobile' => '07701234567', 'confirm_mobile' => '07701234567'];
                $promises[] = $clients[$worker]->postAsync('/api/v1/digital/orders', ['json' => $body])->then(
                    function ($response) use (&$completed, &$results, $index, $sent): void {
                        $data = json_decode((string) $response->getBody(), true);
                        $results[] = ['index' => $index, 'http' => $response->getStatusCode(), 'status' => $data['data']['status'] ?? null, 'message' => $data['message'] ?? null, 'ms' => round((microtime(true) - $sent) * 1000, 2)];
                        $completed++;
                    },
                    function ($error) use (&$completed, &$results, $index, $sent): void {
                        $results[] = ['index' => $index, 'http' => null, 'error' => get_class($error), 'message' => $error->getMessage(), 'ms' => round((microtime(true) - $sent) * 1000, 2)];
                        $completed++;
                    }
                );
            }
            $multi->tick();
            Utils::queue()->run();
            usleep(500);
        }
        $elapsed = microtime(true) - $start;
        $times = array_column($results, 'ms');
        sort($times);
        $success = count(array_filter($results, fn ($row) => $row['http'] === 201 && $row['status'] === 'succeeded'));
        $onTime = count(array_filter($results, fn ($row) => $row['http'] === 201 && $row['status'] === 'succeeded' && $row['ms'] <= 5000));
        fwrite(STDOUT, 'SCALE_PEAK '.json_encode(['offered_sales_per_second' => $rate, 'offered_sales' => $count, 'dispatch_duration_ms' => end($dispatches), 'dispatch_times_ms' => $dispatches, 'completed_sales' => $success, 'within_5_seconds' => $onTime, 'p95_sale_ms' => $times[(int) floor(count($times) * .95)], 'elapsed_seconds' => round($elapsed, 2), 'drained_sales_per_second' => round($success / $elapsed, 2), 'target_met' => $onTime === $count, 'server_workers' => 8, 'generator_connection_cap_reason' => 'SSH hard open-file limit 100; burst 80 distinct points', 'provider_delay_ms' => (int) getenv('MASAL_SCALE_PROVIDER_DELAY_MS'), 'results' => $results], JSON_UNESCAPED_SLASHES).PHP_EOL);
        // All requests must settle before accounting; a timeout must never trigger a new purchase.
        $this->assertSame($count, $success);
        $this->assertSame($count, DigitalOrder::where('connection_id', $connection)->where('request_id', 'like', 'peak-scale-%')->where('status', 'succeeded')->count());
        $this->assertSame($count, (int) DB::table('digital_orders')->where('connection_id', $connection)->where('request_id', 'like', 'peak-scale-%')->distinct()->count('request_id'));
        foreach ($this->registeredAgents as $recipient) {
            $mainId = $recipient['account']->id;
            $spent = (int) DigitalOrder::where('connection_id', $connection)->where('main_account_id', $mainId)->where('status', 'succeeded')->sum('actual_cost_minor');
            $this->assertDatabaseHas('topup_grants', ['target_account_id' => $mainId, 'balance_minor' => 50000000 - $spent, 'spent_minor' => $spent, 'held_minor' => 0]);
        }
        $this->assertDatabaseHas('digital_connections', ['id' => $connection, 'company_balance_minor' => 10000000000 - ($previousSales + $count) * 600000]);
        $this->artisan('topup:audit-accounting')->assertSuccessful();
    }

    private function concurrentReadsAndLedger(array $fixture, int $firstId, int $workers): void
    {
        $folder = storage_path('framework/testing/scale-'.bin2hex(random_bytes(8)));
        mkdir($folder, 0700, true);
        $loops = (int) (getenv('MASAL_SCALE_REQUESTS_PER_WORKER') ?: 20);
        $this->assertContains($loops, [20, 200, 1000]);
        $pids = [];
        DB::disconnect();
        $wall = hrtime(true);
        for ($worker = 0; $worker < $workers; $worker++) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                throw new \RuntimeException('Unable to start isolated load worker');
            }
            if ($pid === 0) {
                $timings = [];
                $errors = [];
                try {
                    DB::reconnect();
                    $this->asPortalUser($fixture['admin']);
                    $paths = ['/finance/options?compact=1', '/finance/accounts?per_page=25', '/dashboard/activity?limit=20', '/dashboard/activity?limit=all', '/finance/wallets?summary=1&metrics=0&per_page=25'];
                    for ($i = 0; $i < $loops; $i++) {
                        $start = hrtime(true);
                        if ($i % 5 === 0) {
                            $this->asPortalUser($fixture['admin']);
                            $invoiceBody = ['account_id' => $this->registeredPointIds[$worker * 100 + 1] ?? $firstId + $worker, 'service' => 'cash', 'currency' => 'IQD', 'amount' => '1.00', 'kind' => 'receivable', 'reference' => 'Isolated scale invoice', 'idempotency_key' => 'scale-invoice-'.$worker.'-'.$i];
                            $invoice = $this->postJson('/api/v1/finance/invoices', $invoiceBody);
                            if ($invoice->getStatusCode() !== 201) {
                                $errors[] = ['invoice' => $invoice->getStatusCode()];
                            }
                            $body = ['version' => 1, 'amount' => '1.00', 'reference' => 'Isolated scale settlement', 'idempotency_key' => 'scale-worker-'.$worker.'-'.$i];
                            $path = '/api/v1/finance/invoices/'.$invoice->json('data.id').'/settle';
                            $response = $this->postJson($path, $body);
                            $retry = $this->postJson($path, $body);
                            if ($retry->getStatusCode() !== 200 || $retry->json('data.id') !== $response->json('data.id')) {
                                $errors[] = ['retry' => $retry->getStatusCode()];
                            }
                            $expected = 200;
                        } else {
                            $this->asPortalUser($this->registeredAgents ? [$fixture['admin'], $this->registeredAgents[$worker]['user'], User::findOrFail($this->registeredPointUsers[$worker * 100 + 1])][$worker % 3] : [$fixture['admin'], $fixture['agent'], $fixture['posUser']][$worker % 3]);
                            $response = $this->getJson('/api/v1'.$paths[(int) floor($i / 2) % count($paths)]);
                            $expected = 200;
                        }
                        if ($response->getStatusCode() !== $expected) {
                            $errors[] = ['status' => $response->getStatusCode(), 'message' => $response->json('message')];
                        }
                        $timings[] = round((hrtime(true) - $start) / 1000000, 2);
                    }
                } catch (\Throwable $failed) {
                    $errors[] = ['exception' => get_class($failed), 'message' => $failed->getMessage()];
                }
                file_put_contents($folder.'/'.$worker.'.json', json_encode(compact('timings', 'errors'), JSON_THROW_ON_ERROR));
                DB::disconnect();
                exit($errors ? 1 : 0);
            }
            $pids[] = $pid;
        }
        $statuses = [];
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            $statuses[] = pcntl_wifexited($status) ? pcntl_wexitstatus($status) : -1;
        }
        DB::reconnect();
        $elapsed = (hrtime(true) - $wall) / 1000000000;
        $timings = [];
        $errors = [];
        foreach (glob($folder.'/*.json') as $file) {
            $row = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            $timings = [...$timings, ...$row['timings']];
            $errors = [...$errors, ...$row['errors']];
            unlink($file);
        }
        rmdir($folder);
        sort($timings);
        $summary = ['workers' => $workers, 'requests' => $workers * $loops * 1.4, 'elapsed_seconds' => round($elapsed, 2), 'requests_per_second' => round($workers * $loops * 1.4 / $elapsed, 2), 'p50_ms' => $timings[(int) floor(count($timings) * .5)] ?? null, 'p95_workflow_ms' => $timings[(int) floor(count($timings) * .95)] ?? null, 'errors' => $errors, 'worker_statuses' => $statuses, 'unique_financial_operations' => $workers * (int) ($loops / 5)];
        fwrite(STDOUT, 'SCALE_CONCURRENT '.json_encode($summary, JSON_UNESCAPED_SLASHES).PHP_EOL);
        $this->assertSame(array_fill(0, $workers, 0), $statuses);
        $this->assertSame([], $errors);
        $this->assertCount($workers * $loops, $timings);
        $this->assertDatabaseCount('finance_transactions', $workers * (int) ($loops / 5));
        $this->assertSame(0, (int) DB::table('finance_entries')->sum('amount_minor'));
        $this->assertSame($workers * (int) ($loops / 5) * 100, (int) DB::table('finance_wallets')->where('kind', 'account')->sum('balance_minor'));
    }
}

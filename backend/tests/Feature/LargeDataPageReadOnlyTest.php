<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\CreatesAccounts;
use Tests\TestCase;

class LargeDataPageReadOnlyTest extends TestCase
{
    use CreatesAccounts;

    public function test_large_data_last_pages_preserve_order_without_loading_the_whole_history(): void
    {
        if (getenv('MASAL_SCALE_READ_EXISTING') !== '1') {
            $this->markTestSkipped('Requires the existing disposable scale dataset; never seeds or changes it.');
        }
        $this->assertTrue(app()->environment('testing'));
        $this->assertSame('dananiriq_masalverify', DB::connection()->getDatabaseName());
        if (! Schema::hasTable('digital_orders') || DB::table('digital_orders')->count() < 3000000) {
            $this->markTestSkipped('The disposable large-data fixture has not been seeded or was already cleaned.');
        }
        $this->assertGreaterThanOrEqual(3000000, DB::table('digital_orders')->count());
        $admin = User::whereHas('membership.account', fn ($query) => $query->where('type', AccountType::System))->firstOrFail();
        $agent = User::whereHas('membership.account', fn ($query) => $query->where('type', AccountType::MainAgent))->firstOrFail();
        $pos = User::where('login', 'scale-point-9999')->firstOrFail();
        $results = [];
        DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        DB::beginTransaction();
        try {
            foreach (['admin' => $admin, 'agent' => $agent, 'pos' => $pos] as $level => $actor) {
                $this->asPortalUser($actor);
                $first = $this->getJson('/api/v1/digital/orders?per_page=25')->assertOk();
                $page = $first->json('meta.last_page');
                $total = $first->json('meta.total');
                $started = hrtime(true);
                $last = $this->getJson('/api/v1/digital/orders?per_page=25&page='.$page)->assertOk();
                $ms = round((hrtime(true) - $started) / 1000000, 2);
                $expected = DB::table('digital_orders');
                if ($level === 'pos') {
                    $expected->where('account_id', $actor->membership->account_id);
                } elseif ($level === 'agent') {
                    $expected->where('main_account_id', $actor->membership->account_id);
                }
                $ids = $expected->orderBy('id')->limit($total % 25 ?: 25)->pluck('id')->map(fn ($id) => (int) $id)->reverse()->values()->all();
                $this->assertSame($ids, array_column($last->json('data'), 'id'));
                $this->assertSame($total, $last->json('meta.total'));
                $results[] = compact('level', 'page', 'total', 'ms');
            }
            fwrite(STDOUT, 'SCALE_LAST_PAGE '.json_encode($results).PHP_EOL);
            if (getenv('MASAL_SCALE_MATRIX') === '1') {
                $readers = ['admin' => $admin, 'agent' => $agent, 'pos' => $pos];
                $employee = User::whereHas('membership', fn ($query) => $query->where('kind', 'employee'))->first();
                if ($employee && getenv('MASAL_SCALE_MATRIX_AFTER') === '1') {
                    $readers['agent_employee_point_scope'] = $employee;
                }
                $matrix = [];
                foreach ($readers as $level => $actor) {
                    $this->asPortalUser($actor);
                    foreach (['/dashboard/summary?view=cards', '/dashboard/summary', '/dashboard/activity?limit=20', '/dashboard/activity?limit=all', '/digital/orders?per_page=25', '/digital/orders/summary?provider=topup', '/digital/connections?provider=topup'] as $path) {
                        $started = hrtime(true);
                        $response = $this->getJson('/api/v1'.$path)->assertOk();
                        $matrix[] = ['level' => $level, 'path' => $path, 'ms' => round((hrtime(true) - $started) / 1000000, 2), 'bytes' => strlen($response->getContent())];
                        if ($level === 'agent_employee_point_scope' && str_starts_with($path, '/dashboard/summary')) {
                            $card = collect($response->json('data.cards'))->firstWhere('key', 'digital:topup');
                            $this->assertNull($card['remaining']);
                            $this->assertSame([], $card['balanceRows']);
                        }
                        if ($level === 'agent_employee_point_scope' && str_starts_with($path, '/digital/connections')) {
                            $response->assertJsonPath('balance.known', 0)->assertJsonPath('balance.amount', null)->assertJsonCount(0, 'data');
                        }
                    }
                }
                fwrite(STDOUT, 'SCALE_EXISTING_MATRIX '.json_encode(['after' => getenv('MASAL_SCALE_MATRIX_AFTER') === '1', 'results' => $matrix]).PHP_EOL);
            }
        } finally {
            DB::rollBack();
        }
    }
}

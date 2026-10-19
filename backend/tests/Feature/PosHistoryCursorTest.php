<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Models\CatalogProduct;
use App\Models\Digital\DigitalConnection;
use App\Models\Digital\DigitalOffer;
use App\Models\Digital\DigitalOrder;
use App\Models\Sales\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\CreatesAccounts;
use Tests\TestCase;

class PosHistoryCursorTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    private function fixture(): array
    {
        Http::preventStrayRequests();
        $system = $this->account(AccountType::System);
        $main = $this->account(AccountType::MainAgent, $system);
        $point = $this->account(AccountType::Pos, $main);
        $foreign = $this->account(AccountType::Pos, $main);
        $user = $this->userFor($point);
        $other = $this->userFor($foreign);
        $product = CatalogProduct::factory()->create();
        $connection = DigitalConnection::factory()->create(['account_id' => $main->id]);
        $offer = DigitalOffer::factory()->create(['connection_id' => $connection->id, 'product_id' => $product->id]);
        foreach (range(1, 13) as $index) {
            Sale::factory()->create(['main_account_id' => $main->id, 'account_id' => $point->id, 'creator_id' => $user->id, 'product_id' => $product->id, 'created_at' => '2026-10-17 09:30:00', 'issued_at' => $index === 1 ? null : '2026-10-17 09:30:00']);
            DigitalOrder::factory()->create(['offer_id' => $offer->id, 'connection_id' => $connection->id, 'main_account_id' => $main->id, 'account_id' => $point->id, 'creator_id' => $user->id, 'product_id' => $product->id, 'created_at' => '2026-10-17 09:30:00', 'provider' => 'topup', 'type' => $index % 2 ? 'topup' : 'bundle', 'status' => 'succeeded', 'reservation_active' => false, 'actual_cost_minor' => 430000, 'actual_retail_minor' => 500000, 'cost_basis' => 'provider_response', 'company_transaction_id' => 'cursor-company-'.$index, 'receipt_ref' => 'cursor-receipt-'.$index, 'resolved_at' => '2026-10-17 09:30:01']);
        }
        DigitalOrder::factory()->create(['offer_id' => $offer->id, 'connection_id' => $connection->id, 'main_account_id' => $main->id, 'account_id' => $foreign->id, 'creator_id' => $other->id, 'product_id' => $product->id, 'created_at' => '2026-10-17 10:30:00']);
        $this->asPortalUser($user);

        return compact('system', 'main', 'point', 'foreign', 'user', 'other', 'product', 'connection', 'offer');
    }

    private function keys(array $rows): array
    {
        return array_map(fn ($row): string => $row['kind'].':'.$row['record']['id'], $rows);
    }

    public function test_cursor_feed_has_no_count_or_offset_and_preserves_all_time_id_source_ties(): void
    {
        $this->fixture();
        $expected = $this->getJson('/api/v1/digital/history?per_page=100')->assertOk()->assertJsonPath('meta.total', 26)->json('data');
        $seen = [];
        $cursor = null;
        foreach ([10, 10, 6] as $count) {
            DB::enableQueryLog();
            DB::flushQueryLog();
            $response = $this->getJson('/api/v1/digital/history?pagination=cursor&per_page=10'.($cursor ? '&cursor='.urlencode($cursor) : ''))->assertOk()->assertJsonCount($count, 'data')->assertJsonMissingPath('meta.total')->assertJsonMissingPath('meta.last_page');
            $queries = collect(DB::getQueryLog())->pluck('query')->filter(fn ($sql): bool => str_contains($sql, '"history"'));
            DB::disableQueryLog();
            $this->assertCount(1, $queries);
            $this->assertStringNotContainsString('count(', strtolower($queries->first()));
            $this->assertStringNotContainsString('offset ', strtolower($queries->first()));
            $seen = [...$seen, ...$response->json('data')];
            $cursor = $response->json('meta.next_cursor');
            $this->assertSame($count === 10, $response->json('meta.has_more'));
        }
        $this->assertNull($cursor);
        $this->assertSame($this->keys($expected), $this->keys($seen));
        $this->assertCount(26, array_unique($this->keys($seen)));
        $this->assertDatabaseCount('finance_transactions', 0);
        $this->assertDatabaseCount('finance_entries', 0);
        Http::assertNothingSent();
    }

    public function test_one_row_cursor_pages_keep_both_sources_when_their_ids_and_timestamps_match(): void
    {
        $this->fixture();
        $first = $this->getJson('/api/v1/digital/history?pagination=cursor&per_page=1')->assertOk()->assertJsonPath('data.0.kind', 'digital')->json();
        $this->getJson('/api/v1/digital/history?pagination=cursor&per_page=1&cursor='.urlencode($first['meta']['next_cursor']))->assertOk()->assertJsonPath('data.0.kind', 'stock')->assertJsonPath('data.0.record.id', $first['data'][0]['record']['id']);
    }

    public function test_cursor_is_bound_to_the_user_filter_page_size_and_permissions_and_rejects_tampering(): void
    {
        $f = $this->fixture();
        $cursor = $this->getJson('/api/v1/digital/history?pagination=cursor&per_page=10')->assertOk()->json('meta.next_cursor');
        foreach (['&kind=topup', '&q=changed', '&from=2026-10-01', '&to=2026-10-18'] as $filter) {
            $this->getJson('/api/v1/digital/history?pagination=cursor&per_page=10&cursor='.urlencode($cursor).$filter)->assertUnprocessable()->assertJsonValidationErrors('cursor');
        }
        $this->getJson('/api/v1/digital/history?pagination=cursor&per_page=1&cursor='.urlencode($cursor))->assertUnprocessable();
        $altered = substr($cursor, 0, -1).(str_ends_with($cursor, 'a') ? 'b' : 'a');
        $this->getJson('/api/v1/digital/history?pagination=cursor&per_page=10&cursor='.urlencode($altered))->assertUnprocessable();
        $this->asPortalUser($f['other']);
        $this->getJson('/api/v1/digital/history?pagination=cursor&per_page=10&cursor='.urlencode($cursor))->assertUnprocessable();
        $this->asPortalUser($f['user']);
        DB::table('membership_permissions')->insert(['membership_id' => $f['user']->membership->id, 'permission_id' => DB::table('permissions')->where('name', 'sales.view')->value('id'), 'allowed' => false]);
        $this->getJson('/api/v1/digital/history?pagination=cursor&per_page=10&cursor='.urlencode($cursor))->assertUnprocessable();
        $this->getJson('/api/v1/digital/history?pagination=cursor&per_page=10')->assertOk()->assertJsonPath('data.0.kind', 'digital');
    }

    public function test_newer_operations_between_pages_do_not_shift_or_duplicate_the_existing_cursor_chain(): void
    {
        $f = $this->fixture();
        $expected = $this->getJson('/api/v1/digital/history?per_page=100')->assertOk()->json('data');
        $first = $this->getJson('/api/v1/digital/history?pagination=cursor&per_page=10')->assertOk()->json();
        Sale::factory()->create(['main_account_id' => $f['main']->id, 'account_id' => $f['point']->id, 'creator_id' => $f['user']->id, 'product_id' => $f['product']->id, 'created_at' => '2026-10-18 10:00:00']);
        $seen = $first['data'];
        $cursor = $first['meta']['next_cursor'];
        while ($cursor) {
            $result = $this->getJson('/api/v1/digital/history?pagination=cursor&per_page=10&cursor='.urlencode($cursor))->assertOk()->json();
            $seen = [...$seen, ...$result['data']];
            $cursor = $result['meta']['next_cursor'];
        }
        $this->assertSame($this->keys($expected), $this->keys($seen));
        $this->getJson('/api/v1/digital/history?kind=topup&per_page=10&page=2')->assertOk()->assertJsonPath('meta.total', 13)->assertJsonCount(3, 'data');
    }
}

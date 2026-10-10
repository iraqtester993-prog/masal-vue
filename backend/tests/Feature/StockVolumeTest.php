<?php

namespace Tests\Feature;

use App\Models\Stock\StockBatch;
use App\Models\Stock\StockCard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\CreatesStock;
use Tests\TestCase;

class StockVolumeTest extends TestCase
{
    use CreatesStock, RefreshDatabase;

    public function test_fifty_thousand_card_preview_submission_and_approval_stays_bounded_and_posts_exact_money(): void
    {
        if (getenv('MASAL_STOCK_VOLUME_TEST') !== '1') {
            $this->markTestSkipped('Run explicitly with MASAL_STOCK_VOLUME_TEST=1 and memory_limit=512M.');
        }
        $started = microtime(true);
        $fixture = $this->stockFixture();
        $this->withSession(['_token' => 'stock-volume-test-csrf']);
        $this->withHeader('X-CSRF-TOKEN', 'stock-volume-test-csrf');
        $peaks = [];
        $this->asPortalUser($fixture['agent']);
        memory_reset_peak_usage();
        $draft = $this->stockDraft($fixture, 50000, 'volume');
        $previewResponse = $this->sendPayload('/api/v1/stock/orders/preview', $draft)->assertOk();
        $preview = $previewResponse->baseResponse->original['data'];
        $this->assertSame(50000, $preview['quantity']);
        $input = $preview['draft'] + ['preview_hash' => $preview['preview_hash'], 'exclude_rejected' => true, 'idempotency_key' => 'volume-submit'];
        $peaks['preview'] = memory_get_peak_usage(true);
        unset($draft, $preview, $previewResponse);
        gc_collect_cycles();
        gc_mem_caches();
        memory_reset_peak_usage();
        $submission = $this->sendPayload('/api/v1/stock/orders', $input)->assertSuccessful();
        $orderId = (int) $submission->baseResponse->original->id;
        $peaks['submit'] = memory_get_peak_usage(true);
        unset($input, $submission);
        gc_collect_cycles();
        gc_mem_caches();
        $this->asPortalUser($fixture['admin']);
        memory_reset_peak_usage();
        $approval = $this->postJson('/api/v1/stock/orders/'.$orderId.'/review', ['version' => 1, 'decision' => 'approve', 'idempotency_key' => 'volume-approve'])->assertOk();
        $this->assertSame('approved', $approval->baseResponse->original->status);
        $peaks['approve'] = memory_get_peak_usage(true);
        unset($approval);
        gc_collect_cycles();
        $batch = StockBatch::where('order_id', $orderId)->sole();
        $this->assertSame(50000, StockCard::count());
        $this->assertSame(22501250000, $this->voucherBalance($fixture['main']));
        $this->assertSame(20000500003, (int) StockCard::sum('cost_minor'));
        $this->assertDatabaseHas('stock_batches', ['id' => $batch->id, 'quantity' => 50000, 'amount_minor' => 22501250000]);
        $this->assertSame(0, (int) DB::table('finance_entries')->sum('amount_minor'));
        $this->assertLessThan(512 * 1024 * 1024, max($peaks));
        fwrite(STDERR, PHP_EOL.'Stock volume: '.round(microtime(true) - $started, 2).'s, phase peaks MiB '.json_encode(array_map(fn (int $bytes): float => round($bytes / 1024 / 1024, 1), $peaks), JSON_THROW_ON_ERROR).PHP_EOL);
    }

    private function sendPayload(string $url, array &$payload): TestResponse
    {
        $content = json_encode($payload, JSON_THROW_ON_ERROR);
        $payload = [];
        $headers = $this->transformHeadersToServerVars($this->defaultHeaders + ['Content-Type' => 'application/json', 'Accept' => 'application/json']);

        return $this->call('POST', $url, [], [], [], $headers, $content);
    }
}

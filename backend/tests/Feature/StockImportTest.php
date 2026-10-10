<?php

namespace Tests\Feature;

use App\Models\Stock\StockBatch;
use App\Models\Stock\StockCard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesStock;
use Tests\TestCase;

class StockImportTest extends TestCase
{
    use CreatesStock, RefreshDatabase;

    public function test_import_line_metadata_is_strict_without_expanding_all_card_fields_and_detail_preserves_single_secret_draft(): void
    {
        $fixture = $this->stockFixture();
        $draft = $this->stockDraft($fixture, 1);
        $this->asPortalUser($fixture['agent']);
        $preview = $this->postJson('/api/v1/stock/orders/preview', $draft)->assertOk()->assertJsonPath('data.lines.0.checked.0.source_row', 1)->assertJsonMissingPath('data.lines.0.checked.0.pin')->json('data');
        $duplicate = $draft;
        $duplicate['lines'][] = $duplicate['lines'][0];
        $this->postJson('/api/v1/stock/orders/preview', $duplicate)->assertUnprocessable()->assertJsonValidationErrors('lines.1.key');
        $unknown = $draft;
        $unknown['lines'][0]['approved'] = true;
        $this->postJson('/api/v1/stock/orders/preview', $unknown)->assertUnprocessable();
        $submission = $this->postJson('/api/v1/stock/orders', $preview['draft'] + ['preview_hash' => $preview['preview_hash'], 'exclude_rejected' => true, 'idempotency_key' => 'metadata-submit'])->assertSuccessful()->assertJsonMissingPath('data.draft')->assertJsonMissingPath('data.summary.lines.0.checked');
        $id = $submission->json('data.id');
        $this->getJson('/api/v1/stock/orders')->assertOk()->assertJsonMissingPath('data.0.draft')->assertJsonMissingPath('data.0.summary.lines.0.checked');
        $this->getJson('/api/v1/stock/orders/'.$id)->assertOk()->assertJsonPath('data.draft.lines.0.rows.0.pin', 'SECRET-PIN-first-1')->assertJsonPath('data.summary.lines.0.checked.0.source_row', 1);
    }

    public function test_preview_and_pending_submission_create_no_stock_or_balance_and_encrypt_sensitive_payload(): void
    {
        $fixture = $this->stockFixture();
        $orderId = $this->submitStock($fixture);
        $this->assertDatabaseHas('stock_orders', ['id' => $orderId, 'status' => 'pending', 'quantity' => 3]);
        $this->assertDatabaseCount('stock_batches', 0);
        $this->assertDatabaseCount('stock_cards', 0);
        $this->assertDatabaseCount('finance_entries', 0);
        $this->assertStringNotContainsString('SECRET-PIN', DB::table('stock_orders')->value('payload'));
        $this->assertStringNotContainsString('SECRET-PIN', json_encode(DB::table('finance_operations')->get()));
        $this->assertDatabaseHas('audit_logs', ['action' => 'import.submit', 'subject_account_id' => $fixture['main']->id]);
    }

    public function test_approval_posts_once_exact_balanced_credit_and_two_invoices_with_distributed_expenses(): void
    {
        $fixture = $this->stockFixture();
        $batch = $this->approvedStock($fixture);
        $this->assertSame(1350075, $this->voucherBalance($fixture['main']));
        $this->assertSame(1200033, (int) StockCard::sum('cost_minor'));
        $this->assertSame(0, (int) DB::table('finance_entries')->sum('amount_minor'));
        $this->assertDatabaseCount('finance_transactions', 1);
        $this->assertDatabaseHas('finance_invoices', ['id' => $batch->invoice_id, 'kind' => 'receivable', 'account_id' => $fixture['main']->id, 'amount_minor' => 1350075, 'paid_minor' => 0]);
        $this->assertDatabaseHas('finance_invoices', ['kind' => 'payable', 'account_id' => $fixture['system']->id, 'amount_minor' => 1200033]);
        $this->assertSame('SECRET-PIN-first-1', StockCard::orderBy('id')->first()->secret['pin']);
        $this->assertStringNotContainsString('SECRET-PIN', DB::table('stock_cards')->value('secret'));
        $this->postJson('/api/v1/stock/orders/'.$batch->order_id.'/review', ['version' => 1, 'decision' => 'approve', 'idempotency_key' => 'approve-stock-001'])->assertOk();
        $this->assertDatabaseCount('stock_cards', 3);
        $this->assertDatabaseCount('finance_transactions', 1);
        $this->postJson('/api/v1/stock/orders/'.$batch->order_id.'/review', ['version' => 2, 'decision' => 'approve', 'idempotency_key' => 'another-approval-001'])->assertConflict();
        $this->assertSame(1350075, $this->voucherBalance($fixture['main']));
    }

    public function test_duplicate_missing_and_expired_rows_are_reported_and_require_explicit_exclusion(): void
    {
        $fixture = $this->stockFixture();
        $draft = $this->stockDraft($fixture, 4);
        $draft['lines'][0]['rows'][1]['pin'] = $draft['lines'][0]['rows'][0]['pin'];
        $draft['lines'][0]['rows'][2]['pin'] = '';
        $draft['lines'][0]['rows'][3]['expiry'] = '2030-01-01';
        $this->asPortalUser($fixture['agent']);
        $preview = $this->postJson('/api/v1/stock/orders/preview', $draft)->assertOk()->assertJsonPath('data.quantity', 1)->assertJsonPath('data.rejected', 3)->json('data');
        $this->postJson('/api/v1/stock/orders', $preview['draft'] + ['preview_hash' => $preview['preview_hash'], 'exclude_rejected' => false, 'idempotency_key' => 'reject-confirm-001'])->assertUnprocessable()->assertJsonValidationErrors('exclude_rejected');
        $this->assertDatabaseCount('stock_orders', 0);
        $orderId = $this->submitStock($fixture, $draft);
        $this->asPortalUser($fixture['admin']);
        $this->postJson('/api/v1/stock/orders/'.$orderId.'/review', ['version' => 1, 'decision' => 'approve', 'idempotency_key' => 'approved-good-001'])->assertOk();
        $this->assertDatabaseCount('stock_cards', 1);
        $this->assertSame(450025, $this->voucherBalance($fixture['main']));
    }

    public function test_approval_detects_new_duplicates_and_price_change_without_any_partial_write(): void
    {
        $fixture = $this->stockFixture();
        $orderId = $this->submitStock($fixture);
        DB::table('finance_prices')->where('account_id', $fixture['main']->id)->update(['price_minor' => 460000, 'version' => 2]);
        $this->asPortalUser($fixture['admin']);
        $this->postJson('/api/v1/stock/orders/'.$orderId.'/review', ['version' => 1, 'decision' => 'approve', 'idempotency_key' => 'price-conflict-001'])->assertConflict();
        $this->assertDatabaseHas('stock_orders', ['id' => $orderId, 'status' => 'pending', 'version' => 1]);
        $this->assertDatabaseCount('stock_cards', 0);
        $this->assertDatabaseCount('finance_invoices', 0);
        $this->assertDatabaseCount('finance_transactions', 0);
    }

    public function test_returned_order_can_be_repreviewed_and_resubmitted_by_original_creator(): void
    {
        $fixture = $this->stockFixture();
        $orderId = $this->submitStock($fixture);
        $this->asPortalUser($fixture['admin']);
        $this->postJson('/api/v1/stock/orders/'.$orderId.'/review', ['version' => 1, 'decision' => 'return', 'reason' => 'صحح الملف', 'idempotency_key' => 'return-order-001'])->assertOk()->assertJsonPath('data.version', 2);
        $this->asPortalUser($fixture['agent']);
        $preview = $this->postJson('/api/v1/stock/orders/preview', $this->stockDraft($fixture))->assertOk()->json('data');
        $this->postJson('/api/v1/stock/orders/'.$orderId.'/resubmit', $preview['draft'] + ['preview_hash' => $preview['preview_hash'], 'exclude_rejected' => true, 'version' => 2, 'idempotency_key' => 'resubmit-order-001'])->assertOk()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.version', 3);
        $this->assertDatabaseCount('stock_orders', 1);
    }

    public function test_invalid_headers_money_unknown_fields_and_undeclared_category_return_422_without_writes(): void
    {
        $fixture = $this->stockFixture();
        $this->asPortalUser($fixture['agent']);
        $draft = $this->stockDraft($fixture);
        $this->postJson('/api/v1/stock/orders/preview', $draft + ['status' => 'approved'])->assertUnprocessable();
        $draft['lines'][0]['declared_count'] = 5;
        $this->postJson('/api/v1/stock/orders/preview', $draft)->assertUnprocessable();
        $draft = $this->stockDraft($fixture);
        $draft['lines'][0]['cost'] = '1e3';
        $this->postJson('/api/v1/stock/orders/preview', $draft)->assertUnprocessable()->assertJsonValidationErrors('cost');
        $draft = $this->stockDraft($fixture);
        $draft['category_count'] = 2;
        $this->postJson('/api/v1/stock/orders/preview', $draft)->assertUnprocessable()->assertJsonValidationErrors('category_count');
        $draft = $this->stockDraft($fixture);
        $draft['lines'][0]['rows'][0]['balance_minor'] = 100;
        $this->postJson('/api/v1/stock/orders/preview', $draft)->assertUnprocessable();
        $this->assertDatabaseCount('stock_orders', 0);
        $this->assertDatabaseCount('finance_entries', 0);
    }

    public function test_duplicate_orders_cannot_approve_same_pin_twice_and_automatic_serial_is_stable_between_previews(): void
    {
        $fixture = $this->stockFixture();
        $draft = $this->stockDraft($fixture, 1);
        unset($draft['lines'][0]['rows'][0]['serial']);
        $this->asPortalUser($fixture['agent']);
        $first = $this->postJson('/api/v1/stock/orders/preview', $draft)->assertOk()->json('data');
        $second = $this->postJson('/api/v1/stock/orders/preview', $draft)->assertOk()->json('data');
        $this->assertSame($first['preview_hash'], $second['preview_hash']);
        $this->assertStringStartsWith('AUTO-', $first['draft']['lines'][0]['rows'][0]['serial']);
        $firstId = $this->submitStock($fixture, $draft, 'dupe-submit-first');
        $secondId = $this->submitStock($fixture, $draft, 'dupe-submit-second');
        $this->asPortalUser($fixture['admin']);
        $approval = $this->postJson('/api/v1/stock/orders/'.$firstId.'/review', ['version' => 1, 'decision' => 'approve', 'idempotency_key' => 'dupe-approve-first'])->assertOk();
        $approval->assertJsonPath('data.summary.lines.0.batch_id', (int) StockBatch::where('order_id', $firstId)->sole()->id);
        $this->postJson('/api/v1/stock/orders/'.$secondId.'/review', ['version' => 1, 'decision' => 'approve', 'idempotency_key' => 'dupe-approve-second'])->assertConflict();
        $this->assertDatabaseCount('stock_cards', 1);
        $this->assertSame(450025, $this->voucherBalance($fixture['main']));
    }

    public function test_product_city_and_required_extra_field_policy_are_enforced_using_actual_rows(): void
    {
        $fixture = $this->stockFixture();
        $fixture['product']->update(['allowed_cities' => ['البصرة'], 'extra_fields' => [['key' => 'activation', 'label' => 'رمز التفعيل', 'required' => true]]]);
        $this->asPortalUser($fixture['agent']);
        $draft = $this->stockDraft($fixture, 1);
        $this->postJson('/api/v1/stock/orders/preview', $draft)->assertUnprocessable()->assertJsonValidationErrors('lines');
        $draft['city'] = 'البصرة';
        $this->postJson('/api/v1/stock/orders/preview', $draft)->assertOk()->assertJsonPath('data.quantity', 0)->assertJsonPath('data.lines.0.checked.0.error', 'حقل مطلوب: رمز التفعيل');
        $draft['lines'][0]['rows'][0]['extra_fields'] = ['activation' => '0'];
        $draft['lines'][0]['rows'][0]['pin'] = '0';
        $draft['lines'][0]['rows'][0]['expiry'] = '1/1/2031';
        $this->postJson('/api/v1/stock/orders/preview', $draft)->assertOk()->assertJsonPath('data.quantity', 1)->assertJsonPath('data.draft.lines.0.rows.0.expiry', '2031-01-01');
        $fixture['product']->update(['status' => 'disabled']);
        $this->postJson('/api/v1/stock/orders/preview', $draft)->assertUnprocessable();
    }

    public function test_voucher_funding_binds_exact_matching_approved_batch_without_second_credit(): void
    {
        $fixture = $this->stockFixture();
        $this->asPortalUser($fixture['agent']);
        $id = $this->postJson('/api/v1/finance/funding-requests', ['service' => 'voucher', 'currency' => 'IQD', 'amount' => '13500.75', 'purpose' => 'طلبية كارتات', 'idempotency_key' => 'voucher-funding-001'])->assertCreated()->json('data.id');
        $this->travel(1)->seconds();
        $batch = $this->approvedStock($fixture);
        $input = ['version' => 1, 'decision' => 'approve', 'reference' => 'ربط طلبية الكارتات', 'stock_batch_id' => $batch->id, 'idempotency_key' => 'voucher-funding-bind'];
        $this->postJson('/api/v1/finance/funding-requests/'.$id.'/review', $input)->assertOk()->assertJsonPath('data.status', 'approved');
        $this->postJson('/api/v1/finance/funding-requests/'.$id.'/review', $input)->assertOk();
        $this->assertSame(1350075, $this->voucherBalance($fixture['main']));
        $this->assertDatabaseCount('finance_transactions', 1);
        $this->assertDatabaseHas('finance_funding_requests', ['id' => $id, 'stock_batch_id' => $batch->id, 'transaction_id' => $batch->transaction_id]);
        $this->getJson('/api/v1/stock/orders/'.$batch->order_id)->assertOk()->assertJsonCount(2, 'data.events')->assertJsonPath('data.events.0.label', 'إرسال')->assertJsonPath('data.events.1.label', 'اعتماد');
    }
}

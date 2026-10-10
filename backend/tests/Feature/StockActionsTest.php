<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Models\Finance\Invoice;
use App\Models\Stock\StockCard;
use App\Models\Stock\StockClaim;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesStock;
use Tests\TestCase;

class StockActionsTest extends TestCase
{
    use CreatesStock, RefreshDatabase;

    private function action(int $batchId, int $version, string $action, string $key): array
    {
        return ['version' => $version, 'action' => $action, 'reason' => 'معالجة بطاقات المورد', 'idempotency_key' => $key];
    }

    public function test_quarantine_debits_once_and_resume_revalues_at_current_price_without_creating_invoice(): void
    {
        $fixture = $this->stockFixture();
        $batch = $this->approvedStock($fixture);
        $this->asPortalUser($fixture['agent']);
        $input = $this->action($batch->id, 1, 'quarantine', 'quarantine-batch-001');
        $this->postJson('/api/v1/stock/batches/'.$batch->id.'/actions', $input)->assertOk()->assertJsonPath('data.status', 'Quarantined')->assertJsonPath('data.damaged_count', 3)->assertJsonPath('data.version', 2);
        $this->postJson('/api/v1/stock/batches/'.$batch->id.'/actions', $input)->assertOk();
        $this->assertSame(0, $this->voucherBalance($fixture['main']));
        DB::table('finance_prices')->where('account_id', $fixture['main']->id)->update(['price_minor' => 460000, 'version' => 2]);
        $this->postJson('/api/v1/stock/batches/'.$batch->id.'/actions', $this->action($batch->id, 2, 'resume', 'resume-batch-001'))->assertOk()->assertJsonPath('data.available_count', 3);
        $this->assertSame(1380000, $this->voucherBalance($fixture['main']));
        $this->assertSame([460000], StockCard::pluck('credit_minor')->unique()->all());
        $this->assertDatabaseCount('finance_invoices', 2);
        $this->assertSame(0, (int) DB::table('finance_entries')->sum('amount_minor'));
    }

    public function test_partial_cancellation_adjusts_only_unsold_cards_and_invoice_and_owner_can_restore_once(): void
    {
        $fixture = $this->stockFixture();
        $batch = $this->approvedStock($fixture);
        $sold = StockCard::orderBy('id')->first();
        $sold->update(['status' => 'Sold', 'sale_id' => 42]);
        $this->asPortalUser($fixture['agent']);
        $this->postJson('/api/v1/stock/batches/'.$batch->id.'/actions', $this->action($batch->id, 1, 'cancel', 'cancel-partial-001'))->assertOk()->assertJsonPath('data.cancelled_count', 2)->assertJsonPath('data.sold_count', 1);
        $this->assertSame(450025, $this->voucherBalance($fixture['main']));
        $this->assertSame(450025, Invoice::findOrFail($batch->invoice_id)->amount_minor);
        $this->assertDatabaseHas('stock_adjustments', ['kind' => 'cancellation', 'quantity' => 2, 'credit_minor' => 900050, 'invoice_amount_minor' => 900050]);
        $this->postJson('/api/v1/stock/batches/'.$batch->id.'/actions', $this->action($batch->id, 2, 'restore', 'restore-agent-001'))->assertForbidden();
        $this->asPortalUser($fixture['admin']);
        $input = $this->action($batch->id, 2, 'restore', 'restore-owner-001');
        $this->postJson('/api/v1/stock/batches/'.$batch->id.'/actions', $input)->assertOk()->assertJsonPath('data.available_count', 2);
        $this->postJson('/api/v1/stock/batches/'.$batch->id.'/actions', $input)->assertOk();
        $this->assertSame(1350075, $this->voucherBalance($fixture['main']));
        $this->assertSame(1350075, Invoice::findOrFail($batch->invoice_id)->amount_minor);
        $this->assertDatabaseHas('stock_cards', ['id' => $sold->id, 'status' => 'Sold', 'sale_id' => 42]);
        $this->assertSame(0, (int) DB::table('finance_entries')->sum('amount_minor'));
    }

    public function test_paid_invoice_cancellation_is_rejected_without_touching_stock_or_wallet(): void
    {
        $fixture = $this->stockFixture();
        $batch = $this->approvedStock($fixture);
        Invoice::findOrFail($batch->invoice_id)->update(['paid_minor' => 100, 'status' => 'partial']);
        $this->asPortalUser($fixture['agent']);
        $this->postJson('/api/v1/stock/batches/'.$batch->id.'/actions', $this->action($batch->id, 1, 'cancel', 'cancel-prepaid-001'))->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->assertSame(1350075, $this->voucherBalance($fixture['main']));
        $this->assertDatabaseCount('stock_adjustments', 0);
        $this->assertSame(3, StockCard::where('status', 'Available')->count());
        $this->assertSame(1, $batch->fresh()->version);
    }

    public function test_stale_batch_version_and_foreign_card_selection_cannot_change_money_or_claims(): void
    {
        $fixture = $this->stockFixture();
        $batch = $this->approvedStock($fixture);
        $this->asPortalUser($fixture['agent']);
        $this->postJson('/api/v1/stock/batches/'.$batch->id.'/actions', $this->action($batch->id, 9, 'quarantine', 'stale-batch-001'))->assertConflict();
        $this->postJson('/api/v1/stock/batches/'.$batch->id.'/claims', ['version' => 1, 'reason' => 'تالفة', 'card_ids' => [999999], 'idempotency_key' => 'invalid-card-001'])->assertUnprocessable()->assertJsonValidationErrors('card_ids');
        $this->assertSame(1350075, $this->voucherBalance($fixture['main']));
        $this->assertDatabaseCount('stock_claims', 0);
    }

    public function test_claim_holds_credit_and_compensation_uses_original_purchase_price_once(): void
    {
        $fixture = $this->stockFixture();
        $batch = $this->approvedStock($fixture);
        $card = StockCard::orderBy('id')->first();
        $this->asPortalUser($fixture['agent']);
        $claimId = $this->postJson('/api/v1/stock/batches/'.$batch->id.'/claims', ['version' => 1, 'reason' => 'بطاقة تالفة', 'card_ids' => [$card->id], 'idempotency_key' => 'claim-one-001'])->assertOk()->assertJsonPath('data.quantity', 1)->json('data.id');
        $this->assertSame(900050, $this->voucherBalance($fixture['main']));
        $this->postJson('/api/v1/stock/batches/'.$batch->id.'/actions', $this->action($batch->id, 2, 'resume', 'linked-resume-001'))->assertConflict();
        $input = ['version' => 1, 'reason' => 'تعويض المورد', 'decision' => 'compensate', 'idempotency_key' => 'compensate-one-001'];
        $this->postJson('/api/v1/stock/claims/'.$claimId.'/settle', $input)->assertForbidden();
        $this->asPortalUser($fixture['admin']);
        $this->postJson('/api/v1/stock/claims/'.$claimId.'/settle', $input)->assertOk()->assertJsonPath('data.status', 'compensate');
        $this->postJson('/api/v1/stock/claims/'.$claimId.'/settle', $input)->assertOk();
        $this->assertSame(1350075, $this->voucherBalance($fixture['main']));
        $this->assertDatabaseHas('stock_cards', ['id' => $card->id, 'status' => 'Compensated', 'credit_held' => true]);
        $this->assertDatabaseCount('finance_invoices', 2);
    }

    public function test_replacement_requires_exact_valid_rows_and_has_zero_invoice_without_double_credit(): void
    {
        $fixture = $this->stockFixture();
        $batch = $this->approvedStock($fixture);
        $card = StockCard::orderBy('id')->first();
        $this->asPortalUser($fixture['agent']);
        $claimId = $this->postJson('/api/v1/stock/batches/'.$batch->id.'/claims', ['version' => 1, 'reason' => 'استبدال بطاقة', 'card_ids' => [$card->id], 'idempotency_key' => 'claim-replace-001'])->assertOk()->json('data.id');
        $this->asPortalUser($fixture['admin']);
        $input = ['version' => 1, 'reason' => 'بطاقة بديلة من المورد', 'decision' => 'replace', 'replacement_rows' => [['serial' => 'NEW-SERIAL-1', 'pin' => 'SECRET-PIN-first-1', 'expiry' => '2031-01-01']], 'idempotency_key' => 'replace-card-001'];
        $this->postJson('/api/v1/stock/claims/'.$claimId.'/settle', $input)->assertUnprocessable()->assertJsonValidationErrors('replacement_rows');
        $this->assertDatabaseCount('stock_batches', 1);
        $input['replacement_rows'][0]['pin'] = 'NEW-SECRET-PIN-1';
        $this->postJson('/api/v1/stock/claims/'.$claimId.'/settle', $input)->assertOk()->assertJsonPath('data.status', 'replace');
        $this->postJson('/api/v1/stock/claims/'.$claimId.'/settle', $input)->assertOk();
        $replacementId = StockClaim::findOrFail($claimId)->replacement_batch_id;
        $this->assertDatabaseHas('stock_batches', ['id' => $replacementId, 'replacement_claim_id' => $claimId, 'amount_minor' => 0, 'quantity' => 1]);
        $this->assertDatabaseHas('finance_invoices', ['source_id' => $replacementId, 'source_type' => 'stock_batch', 'kind' => 'receivable', 'amount_minor' => 0, 'status' => 'paid']);
        $this->assertSame(1350075, $this->voucherBalance($fixture['main']));
        $this->assertDatabaseCount('stock_cards', 4);
        $this->postJson('/api/v1/stock/batches/'.$replacementId.'/actions', $this->action($replacementId, 1, 'cancel', 'cancel-replacement-001'))->assertOk();
        $this->assertSame(900050, $this->voucherBalance($fixture['main']));
        $this->assertDatabaseHas('finance_invoices', ['source_id' => $replacementId, 'source_type' => 'stock_batch', 'amount_minor' => 0]);
    }

    public function test_withdrawal_approval_and_download_export_cards_without_plaintext_operation_cache_and_blocks_restore(): void
    {
        $fixture = $this->stockFixture();
        $batch = $this->approvedStock($fixture);
        $this->asPortalUser($fixture['agent']);
        $id = $this->postJson('/api/v1/stock/withdrawals', ['version' => 1, 'batch_id' => $batch->id, 'reason' => 'إرجاع للمورد', 'idempotency_key' => 'withdraw-request-001'])->assertOk()->json('data.id');
        $this->assertSame(0, $this->voucherBalance($fixture['main']));
        $this->assertDatabaseCount('stock_claims', 1);
        $this->postJson('/api/v1/stock/withdrawals/'.$id.'/download', ['version' => 1, 'reason' => 'تنزيل الإرجاع', 'idempotency_key' => 'download-before-001'])->assertConflict();
        $this->asPortalUser($fixture['admin']);
        $this->postJson('/api/v1/stock/withdrawals/'.$id.'/review', ['version' => 1, 'reason' => 'اعتماد الإرجاع', 'decision' => 'approve', 'hours' => 1, 'idempotency_key' => 'withdraw-approve-001'])->assertOk()->assertJsonPath('data.status', 'approved');
        $this->asPortalUser($fixture['agent']);
        $input = ['version' => 2, 'reason' => 'تنزيل الإرجاع', 'idempotency_key' => 'download-approved-001'];
        $this->postJson('/api/v1/stock/withdrawals/'.$id.'/download', $input)->assertOk()->assertJsonPath('data.cards.0.pin', 'SECRET-PIN-first-1')->assertHeader('Cache-Control', 'no-store, private');
        $this->postJson('/api/v1/stock/withdrawals/'.$id.'/download', $input)->assertOk();
        $this->assertSame(3, StockCard::where('status', 'Exported')->count());
        $this->assertStringNotContainsString('SECRET-PIN', json_encode(DB::table('finance_operations')->get()));
        $claim = StockClaim::firstOrFail();
        $this->asPortalUser($fixture['admin']);
        $this->postJson('/api/v1/stock/claims/'.$claim->id.'/settle', ['version' => 1, 'reason' => 'إعادة للمخزون', 'decision' => 'restore', 'idempotency_key' => 'export-restore-001'])->assertNotFound();
        $this->getJson('/api/v1/stock/claims')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/stock/withdrawals?status=history')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.status', 'downloaded');
        $this->getJson('/api/v1/stock/withdrawals?status=active')->assertOk()->assertJsonCount(0, 'data');
        $this->asPortalUser($fixture['agent']);
        $this->travel(2)->hours();
        $before = DB::table('finance_transactions')->count();
        $this->postJson('/api/v1/stock/withdrawals/'.$id.'/download', $input)->assertOk()->assertJsonPath('data.cards.0.pin', 'SECRET-PIN-first-1');
        $this->postJson('/api/v1/stock/withdrawals/'.$id.'/download', ['version' => 3, 'reason' => 'إعادة تنزيل من السجل', 'idempotency_key' => 'history-download-001'])->assertOk()->assertJsonPath('data.version', 3);
        $this->assertSame($before, DB::table('finance_transactions')->count());
        $this->assertDatabaseHas('stock_withdrawals', ['id' => $id, 'version' => 3, 'status' => 'downloaded']);
    }

    public function test_metadata_copy_records_history_without_secrets_or_stock_state_change(): void
    {
        $fixture = $this->stockFixture();
        $batch = $this->approvedStock($fixture);
        $this->asPortalUser($fixture['agent']);
        $this->postJson('/api/v1/stock/batches/'.$batch->id.'/copy', ['version' => 1, 'reason' => 'مطابقة الملف', 'secrets' => false, 'card_ids' => StockCard::pluck('id')->all()])->assertOk()->assertJsonMissingPath('data.cards.0.pin')->assertJsonCount(3, 'data.cards');
        $this->assertDatabaseHas('stock_adjustments', ['kind' => 'copy', 'status' => 'copied']);
        $this->assertSame(3, StockCard::where('status', 'Available')->count());
        $this->assertSame(1350075, $this->voucherBalance($fixture['main']));
    }

    public function test_rejected_selected_supplier_return_restores_original_states_and_refunds_actual_debit_once(): void
    {
        $fixture = $this->stockFixture();
        $batch = $this->approvedStock($fixture);
        $card = StockCard::orderBy('id')->first();
        $this->asPortalUser($fixture['agent']);
        $id = $this->postJson('/api/v1/stock/withdrawals', ['version' => 1, 'batch_id' => $batch->id, 'card_ids' => [$card->id], 'reason' => 'طلب المورد الأصلي', 'idempotency_key' => 'selected-return-001'])->assertOk()->assertJsonPath('data.quantity', 1)->assertJsonPath('data.debit', '4500.25')->json('data.id');
        $this->assertSame(900050, $this->voucherBalance($fixture['main']));
        $this->asPortalUser($fixture['admin']);
        $input = ['version' => 1, 'decision' => 'reject', 'reason' => 'رفض المورد الإرجاع', 'idempotency_key' => 'reject-selected-001'];
        $this->postJson('/api/v1/stock/withdrawals/'.$id.'/review', $input)->assertOk()->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.reason', 'طلب المورد الأصلي')->assertJsonPath('data.review_reason', 'رفض المورد الإرجاع');
        $this->postJson('/api/v1/stock/withdrawals/'.$id.'/review', $input)->assertOk();
        $this->assertSame(1350075, $this->voucherBalance($fixture['main']));
        $this->assertDatabaseHas('stock_cards', ['id' => $card->id, 'status' => 'Available', 'credit_held' => false, 'claim_id' => null]);
        $this->assertDatabaseHas('stock_claims', ['batch_id' => $batch->id, 'status' => 'cancelled']);
        $this->assertSame(0, (int) DB::table('finance_entries')->sum('amount_minor'));
        $this->assertSame(3, StockCard::where('status', 'Available')->count());
    }

    public function test_rejected_return_of_previously_quarantined_cards_does_not_resurrect_or_refund_held_credit(): void
    {
        $fixture = $this->stockFixture();
        $batch = $this->approvedStock($fixture);
        $this->asPortalUser($fixture['agent']);
        $this->postJson('/api/v1/stock/batches/'.$batch->id.'/actions', $this->action($batch->id, 1, 'quarantine', 'pre-return-quarantine'))->assertOk();
        $card = StockCard::orderBy('id')->first();
        $id = $this->postJson('/api/v1/stock/withdrawals', ['version' => 2, 'batch_id' => $batch->id, 'card_ids' => [$card->id], 'reason' => 'إرجاع بطاقة موقوفة', 'idempotency_key' => 'held-return-request'])->assertOk()->assertJsonPath('data.debit', '0.00')->json('data.id');
        $this->asPortalUser($fixture['admin']);
        $this->postJson('/api/v1/stock/withdrawals/'.$id.'/review', ['version' => 1, 'decision' => 'reject', 'reason' => 'الإرجاع غير مقبول', 'idempotency_key' => 'held-return-reject'])->assertOk();
        $this->assertSame(0, $this->voucherBalance($fixture['main']));
        $this->assertSame(3, StockCard::where('status', 'Quarantined')->where('credit_held', true)->whereNull('claim_id')->count());
        $this->assertSame('Quarantined', $batch->fresh()->status);
        $this->assertDatabaseCount('finance_transactions', 2);
    }

    public function test_inventory_action_preview_is_read_only_exact_and_checks_distributed_balance_and_versions(): void
    {
        $fixture = $this->stockFixture();
        $batch = $this->approvedStock($fixture);
        $this->asPortalUser($fixture['agent']);
        $card = StockCard::orderBy('id')->first();
        $this->postJson('/api/v1/stock/batches/'.$batch->id.'/actions/preview', ['version' => 1, 'action' => 'damage', 'reason' => 'معاينة التالف', 'card_ids' => [$card->id]])->assertOk()->assertJsonPath('data.quantity', 1)->assertJsonPath('data.debit', '4500.25');
        $this->postJson('/api/v1/stock/batches/'.$batch->id.'/actions/preview', ['version' => 1, 'action' => 'cancel', 'reason' => 'معاينة المتبقي'])->assertOk()->assertJsonPath('data.quantity', 3)->assertJsonPath('data.debit', '13500.75');
        $this->postJson('/api/v1/stock/batches/'.$batch->id.'/actions/preview', ['version' => 99, 'action' => 'cancel', 'reason' => 'نسخة قديمة'])->assertConflict();
        $this->assertDatabaseCount('stock_claims', 0);
        $this->assertDatabaseCount('stock_adjustments', 0);
        $this->assertDatabaseCount('finance_transactions', 1);
        $sub = $this->account(AccountType::SubAgent, $fixture['main']);
        $this->postJson('/api/v1/finance/transfers', ['from_account_id' => $fixture['main']->id, 'to_account_id' => $sub->id, 'service' => 'voucher', 'currency' => 'IQD', 'amount' => '13500.75', 'reference' => 'رصيد موزع للتابع', 'idempotency_key' => 'distribute-voucher-001'])->assertCreated();
        $this->postJson('/api/v1/stock/batches/'.$batch->id.'/actions/preview', ['version' => 1, 'action' => 'quarantine', 'reason' => 'رصيد موزع'])->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->postJson('/api/v1/stock/batches/'.$batch->id.'/actions', $this->action($batch->id, 1, 'quarantine', 'quarantine-no-balance'))->assertUnprocessable();
        $this->assertSame(3, StockCard::where('status', 'Available')->count());
        $this->assertDatabaseCount('stock_claims', 0);
        $this->assertDatabaseCount('finance_transactions', 2);
    }
}

<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Models\AccountAttachment;
use App\Models\Digital\DigitalConnection;
use App\Models\Digital\DigitalOffer;
use App\Models\Digital\DigitalOrder;
use App\Models\Digital\ProviderAttempt;
use App\Models\Finance\FundingRequest;
use App\Models\Finance\Invoice;
use App\Models\Operations\AccountArchive;
use App\Models\OrderSource;
use App\Models\Sales\PrintAttempt;
use App\Models\Sales\Sale;
use App\Models\Stock\StockBatch;
use App\Models\Stock\StockCard;
use App\Services\AccountScope;
use App\Services\Finance\Ledger;
use App\Services\Operations\ArchiveBlockers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CreatesSupport;
use Tests\TestCase;

class AccountArchiveTest extends TestCase
{
    use CreatesSupport, RefreshDatabase;

    private function archiveInput(int $version = 1, string $password = 'password', string $key = 'archive-1'): array
    {
        return ['reason' => 'إغلاق الحساب بطلب صاحبه', 'password' => $password, 'version' => $version, 'idempotency_key' => $key];
    }

    public function test_leaf_archive_preserves_passwords_memberships_and_records_encrypts_snapshot_revokes_access_and_replays_once(): void
    {
        $f = $this->supportFixture();
        $this->asPortalUser($f['admin']);
        $hash = $f['foreignUser']->password;
        $accountCount = DB::table('accounts')->count();
        $memberCount = DB::table('account_memberships')->count();
        $id = $this->postJson('/api/v1/operations/archive/accounts/'.$f['foreign']->id, $this->archiveInput())->assertOk()->json('data.id');
        $this->postJson('/api/v1/operations/archive/accounts/'.$f['foreign']->id, $this->archiveInput())->assertOk()->assertJsonPath('data.id', $id);
        $this->assertDatabaseCount('account_archives', 1);
        $this->assertDatabaseCount('operation_mutations', 1);
        $this->assertDatabaseCount('accounts', $accountCount);
        $this->assertDatabaseCount('account_memberships', $memberCount);
        $this->assertSame($hash, $f['foreignUser']->fresh()->password);
        $this->assertFalse($f['foreignUser']->fresh()->isOperational());
        $this->assertSame('disabled', $f['foreign']->fresh()->status);
        $this->assertNotNull($f['foreign']->fresh()->archived_at);
        $this->assertFalse(app(AccountScope::class)->query($f['admin'])->whereKey($f['foreign']->id)->exists());
        $this->assertTrue(app(AccountScope::class)->query($f['admin'], true)->whereKey($f['foreign']->id)->exists());
        $raw = (array) DB::table('account_archives')->where('id', $id)->first();
        $this->assertStringNotContainsString($f['foreign']->name, $raw['before']);
        $this->assertStringNotContainsString($f['foreignUser']->email, $raw['users']);
        $response = $this->getJson('/api/v1/operations/archive/'.$id)->assertOk()->assertJsonPath('data.users.0.email', $f['foreignUser']->email)->assertJsonPath('data.before.status', 'active');
        $this->assertStringNotContainsString($hash, $response->getContent());
        $this->assertStringNotContainsString('password', $response->getContent());
        $this->getJson('/api/v1/operations/archive')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.before', null)->assertJsonCount(0, 'data.0.users');
        $this->assertDatabaseCount('finance_entries', 0);
    }

    public function test_foreign_self_system_stale_version_unknown_fields_and_missing_permission_cannot_archive(): void
    {
        $f = $this->supportFixture();
        $this->supportGrant($f['agent'], 'agents.archive');
        $this->asPortalUser($f['agent']);
        $this->postJson('/api/v1/operations/archive/accounts/'.$f['foreign']->id, $this->archiveInput())->assertNotFound();
        $this->postJson('/api/v1/operations/archive/accounts/'.$f['main']->id, $this->archiveInput())->assertForbidden();
        $this->asPortalUser($f['admin']);
        $this->postJson('/api/v1/operations/archive/accounts/'.$f['system']->id, $this->archiveInput())->assertForbidden();
        $this->postJson('/api/v1/operations/archive/accounts/'.$f['foreign']->id, $this->archiveInput(2))->assertConflict();
        $this->postJson('/api/v1/operations/archive/accounts/'.$f['foreign']->id, $this->archiveInput() + ['role' => 'system'])->assertUnprocessable();
        $this->supportGrant($f['admin'], 'agents.archive', false);
        $this->asPortalUser($f['admin']);
        $this->postJson('/api/v1/operations/archive/accounts/'.$f['foreign']->id, $this->archiveInput())->assertForbidden();
        $this->assertDatabaseCount('account_archives', 0);
        $this->assertDatabaseCount('operation_mutations', 0);
    }

    public function test_password_confirmation_is_actual_actor_and_rate_limited_without_partial_writes_or_secret_audit(): void
    {
        $f = $this->supportFixture();
        $this->asPortalUser($f['admin']);
        RateLimiter::clear('account-archive-password:'.$f['admin']->id);
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/operations/archive/accounts/'.$f['foreign']->id, $this->archiveInput(1, 'wrong-credential'))->assertUnprocessable()->assertJsonValidationErrors('password');
        }
        $this->postJson('/api/v1/operations/archive/accounts/'.$f['foreign']->id, $this->archiveInput())->assertTooManyRequests();
        $this->assertDatabaseCount('account_archives', 0);
        $this->assertDatabaseCount('operation_mutations', 0);
        $this->assertSame('active', $f['foreign']->fresh()->status);
        $this->assertStringNotContainsString('wrong-credential', json_encode(DB::table('audit_logs')->get()->all()));
        RateLimiter::clear('account-archive-password:'.$f['admin']->id);
    }

    public function test_descendants_open_support_and_exact_financial_balance_block_without_changes(): void
    {
        $f = $this->supportFixture();
        $this->asPortalUser($f['admin']);
        $this->postJson('/api/v1/operations/archive/accounts/'.$f['main']->id, $this->archiveInput())->assertUnprocessable()->assertJsonValidationErrors('account');
        $ticket = $this->openSupport($f['foreignUser'], $f['system'], 'archive-support');
        $this->asPortalUser($f['admin']);
        $this->postJson('/api/v1/operations/archive/accounts/'.$f['foreign']->id, $this->archiveInput())->assertUnprocessable();
        DB::table('support_tickets')->where('id', $ticket)->update(['status' => 'closed']);
        DB::transaction(function () use ($f): void {
            $ledger = app(Ledger::class);
            $from = $ledger->wallet($f['system']->id, 'cash', 'IQD', 'external');
            $to = $ledger->wallet($f['foreign']->id, 'cash', 'IQD');
            $ledger->pair($f['admin'], 'deposit', 'archive-balance', [], $from, $to, 1, 'Bank verified');
        });
        $entries = DB::table('finance_entries')->get()->all();
        $this->postJson('/api/v1/operations/archive/accounts/'.$f['foreign']->id, $this->archiveInput())->assertUnprocessable();
        $this->assertEquals($entries, DB::table('finance_entries')->get()->all());
        $this->assertDatabaseHas('finance_wallets', ['account_id' => $f['foreign']->id, 'balance_minor' => 1]);
        $this->assertDatabaseCount('account_archives', 0);
    }

    public function test_archived_staff_snapshot_cannot_leak_foreign_forest_or_password_and_scope_read_is_explicit(): void
    {
        $f = $this->supportFixture();
        $staff = $this->supportEmployee($f['system'], $f['main'], ['account.view', 'agents.archiveView']);
        $hidden = $this->supportEmployee($f['main'], $f['pos'], ['account.view']);
        $staff->membership->update(['include_descendants' => false]);
        $record = AccountArchive::factory()->create(['account_id' => $f['main']->id, 'actor_id' => $f['admin']->id, 'before' => ['name' => $f['main']->name, 'type' => 'main_agent'], 'users' => [['id' => $hidden->id, 'email' => 'HIDDEN_STAFF_EMAIL', 'name' => 'hidden'], ['id' => $f['agent']->id, 'email' => $f['agent']->email, 'name' => 'owner']]]);
        $this->asPortalUser($staff);
        $r = $this->getJson('/api/v1/operations/archive/'.$record->id)->assertOk()->assertJsonCount(1, 'data.users');
        $this->assertStringNotContainsString('HIDDEN_STAFF_EMAIL', $r->getContent());
        $this->getJson('/api/v1/operations/archive?query='.urlencode($hidden->email))->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/operations/archive?query='.urlencode($f['agent']->email))->assertOk()->assertJsonCount(1, 'data');
        $foreign = AccountArchive::factory()->create(['account_id' => $f['foreign']->id, 'actor_id' => $f['admin']->id]);
        $this->getJson('/api/v1/operations/archive/'.$foreign->id)->assertNotFound();
    }

    public function test_pending_funding_and_unpaid_invoices_block_but_closed_historical_rows_do_not(): void
    {
        $f = $this->supportFixture();
        $funding = FundingRequest::factory()->create(['from_account_id' => $f['system']->id, 'to_account_id' => $f['foreign']->id, 'creator_id' => $f['admin']->id]);
        $invoice = Invoice::factory()->create(['account_id' => $f['foreign']->id, 'creator_id' => $f['admin']->id]);
        $guard = app(ArchiveBlockers::class);
        $this->assertContains('يوجد طلب تمويل غير مغلق.', $guard->reasons($f['foreign']));
        $this->assertContains('يوجد رصيد فاتورة غير مسدد.', $guard->reasons($f['foreign']));
        $funding->update(['status' => 'rejected']);
        $invoice->update(['status' => 'cancelled']);
        $this->assertSame([], $guard->reasons($f['foreign']));
    }

    public function test_unsold_cards_reserved_sales_and_print_attempts_block_but_completed_history_does_not(): void
    {
        $f = $this->supportFixture();
        $source = OrderSource::factory()->create(['network_account_id' => $f['foreign']->id]);
        $batch = StockBatch::factory()->create(['source_id' => $source->id, 'account_id' => $f['foreign']->id]);
        $card = StockCard::factory()->create(['batch_id' => $batch->id]);
        $sale = Sale::factory()->create(['account_id' => $f['foreign']->id, 'main_account_id' => $f['foreign']->id, 'creator_id' => $f['foreignUser']->id, 'product_id' => $batch->product_id]);
        $guard = app(ArchiveBlockers::class);
        $reasons = $guard->reasons($f['foreign']);
        $this->assertContains('يجب تسوية المخزون المتبقي أولًا.', $reasons);
        $this->assertContains('أكمل البيع والحجوزات والطباعة المعلقة أولًا.', $reasons);
        $card->update(['status' => 'Sold']);
        $sale->update(['status' => 'Printed']);
        $attempt = PrintAttempt::factory()->create(['sale_id' => $sale->id, 'actor_id' => $f['foreignUser']->id]);
        $this->assertContains('أكمل عمليات الطباعة المعلقة أولًا.', $guard->reasons($f['foreign']));
        $attempt->update(['status' => 'success']);
        $this->assertSame([], $guard->reasons($f['foreign']));
    }

    public function test_digital_history_does_not_block_but_reservation_and_active_provider_attempt_block_actual_pos_and_main(): void
    {
        $f = $this->supportFixture();
        $point = $this->account(AccountType::Pos, $f['foreign']);
        $seller = $this->userFor($point);
        $connection = DigitalConnection::factory()->create(['account_id' => $f['foreign']->id]);
        $offer = DigitalOffer::factory()->create(['connection_id' => $connection->id]);
        $order = DigitalOrder::factory()->create(['offer_id' => $offer->id, 'main_account_id' => $f['foreign']->id, 'account_id' => $point->id, 'creator_id' => $seller->id]);
        $guard = app(ArchiveBlockers::class);
        $this->assertContains('يوجد حجز خدمة لم يُحرر بعد.', $guard->reasons($f['foreign']));
        $this->assertContains('يوجد حجز خدمة لم يُحرر بعد.', $guard->reasons($point));
        $order->update(['status' => 'succeeded', 'reservation_active' => false, 'actual_cost_minor' => $order->quoted_cost_minor, 'actual_retail_minor' => $order->quoted_retail_minor, 'cost_basis' => 'provider_response', 'company_transaction_id' => 'confirmed-external-id', 'receipt_ref' => 'confirmed-external-receipt', 'resolved_at' => now()]);
        $this->assertSame([], $guard->reasons($point));
        $this->assertNotContains('يوجد طلب خدمة غير مغلق.', $guard->reasons($f['foreign']));
        $attempt = ProviderAttempt::factory()->create(['order_id' => $order->id, 'active_order_id' => $order->id, 'actor_id' => $f['foreignUser']->id]);
        $this->assertContains('توجد محاولة مزوّد غير محسومة؛ راجع الطلب قبل الأرشفة.', $guard->reasons($f['foreign']));
        $attempt->update(['status' => 'received', 'active_order_id' => null]);
        $this->assertSame([], $guard->reasons($point));
        $this->assertNotContains('توجد محاولة مزوّد غير محسومة؛ راجع الطلب قبل الأرشفة.', $guard->reasons($f['foreign']));
    }

    public function test_private_archive_image_is_scoped_and_no_storage_path_is_exposed(): void
    {
        $f = $this->supportFixture();
        Storage::fake('local');
        $image = AccountAttachment::factory()->create(['account_id' => $f['foreign']->id, 'uploaded_by' => $f['admin']->id, 'kind' => 'agent_image', 'storage_path' => 'accounts/private-archive.png', 'mime_type' => 'image/png']);
        Storage::disk('local')->put($image->storage_path, 'private archive pixels');
        $this->asPortalUser($f['admin']);
        $id = $this->postJson('/api/v1/operations/archive/accounts/'.$f['foreign']->id, $this->archiveInput())->assertOk()->json('data.id');
        $r = $this->getJson('/api/v1/operations/archive/'.$id)->assertJsonPath('data.image_url', '/api/v1/operations/archive/'.$id.'/image');
        $this->assertStringNotContainsString($image->storage_path, $r->getContent());
        $this->get('/api/v1/operations/archive/'.$id.'/image')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Cache-Control', 'no-store, private');
        $this->supportGrant($f['agent'], 'agents.archiveView');
        $this->asPortalUser($f['agent']);
        $this->get('/api/v1/operations/archive/'.$id.'/image')->assertNotFound();
    }
}

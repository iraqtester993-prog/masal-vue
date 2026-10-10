<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\PermissionProfile;
use App\Models\Stock\StockAdjustment;
use App\Models\Stock\StockCard;
use App\Models\User;
use App\Services\ManagementAuthority;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesStock;
use Tests\TestCase;

class StockScopeTest extends TestCase
{
    use CreatesStock, RefreshDatabase;

    private function employee(Account $employer, Account $scope, array $permissions): User
    {
        $profile = PermissionProfile::factory()->create(['account_id' => $employer->id]);
        app(ManagementAuthority::class)->replaceProfilePermissions($profile->id, $permissions);
        $user = User::factory()->create();
        $membership = AccountMembership::create(['user_id' => $user->id, 'account_id' => $employer->id, 'role_id' => DB::table('roles')->where('name', 'employee')->value('id'), 'kind' => 'employee', 'permission_profile_id' => $profile->id, 'include_descendants' => true, 'status' => 'active']);
        DB::table('membership_scope_roots')->insert(['membership_id' => $membership->id, 'account_id' => $scope->id]);

        return $user;
    }

    public function test_active_inventory_is_paginated_and_scoped(): void
    {
        $f = $this->stockFixture();
        $batch = $this->approvedStock($f);
        $partial = $batch->replicate();
        $partial->line_key = 'partial-page';
        $partial->status = 'Partially Used';
        $partial->save();
        $foreign = $batch->replicate();
        $foreign->line_key = 'foreign-page';
        $foreign->account_id = $this->account(AccountType::MainAgent, $f['system'])->id;
        $foreign->save();
        $stopped = $batch->replicate();
        $stopped->line_key = 'stopped-page';
        $stopped->status = 'Quarantined';
        $stopped->save();
        $this->asPortalUser($f['agent']);
        $this->getJson('/api/v1/stock/batches?status=active&per_page=1')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 2)->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('data.0.id', $partial->id);
        $this->getJson('/api/v1/stock/batches?status=active&per_page=1&page=2')->assertOk()
            ->assertJsonPath('data.0.id', $batch->id);
        $this->getJson('/api/v1/stock/batches?status=Quarantined')->assertOk()->assertJsonPath('meta.total', 1);
    }

    public function test_unauthenticated_stock_endpoints_return_401(): void
    {
        $this->withHeader('X-Masal-Portal', 'admin')->getJson('/api/v1/stock/orders')->assertUnauthorized();
        $this->postJson('/api/v1/stock/orders/preview', [])->assertUnauthorized();
    }

    public function test_foreign_network_cannot_read_order_batch_cards_or_mutate_by_known_id(): void
    {
        $fixture = $this->stockFixture();
        $batch = $this->approvedStock($fixture);
        $foreign = $this->account(AccountType::MainAgent, $fixture['system']);
        $this->asPortalUser($this->userFor($foreign));
        $this->getJson('/api/v1/stock/orders/'.$batch->order_id)->assertNotFound();
        $this->getJson('/api/v1/stock/batches/'.$batch->id)->assertNotFound();
        $this->getJson('/api/v1/stock/batches/'.$batch->id.'/cards')->assertNotFound();
        $this->postJson('/api/v1/stock/batches/'.$batch->id.'/claims', ['version' => 1, 'reason' => 'غير مسموح', 'card_ids' => StockCard::pluck('id')->all(), 'idempotency_key' => 'foreign-claim-001'])->assertNotFound();
        $this->postJson('/api/v1/stock/orders/preview', $this->stockDraft($fixture))->assertNotFound();
        $this->getJson('/api/v1/stock/batches')->assertOk()->assertJsonCount(0, 'data');
        $this->assertSame(1350075, $this->voucherBalance($fixture['main']));
        $this->assertDatabaseCount('stock_claims', 0);
    }

    public function test_subagents_and_pos_cannot_access_main_stock_or_approve_import_even_with_id(): void
    {
        $fixture = $this->stockFixture();
        $orderId = $this->submitStock($fixture);
        $sub = $this->account(AccountType::SubAgent, $fixture['main']);
        $this->asPortalUser($this->userFor($sub));
        $this->getJson('/api/v1/stock/orders')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/stock/orders/'.$orderId)->assertNotFound();
        $this->postJson('/api/v1/stock/orders/'.$orderId.'/review', ['version' => 1, 'decision' => 'approve', 'idempotency_key' => 'sub-approval-001'])->assertForbidden();
        $pos = $this->account(AccountType::Pos, $sub);
        $this->asPortalUser($this->userFor($pos));
        $this->getJson('/api/v1/stock/options')->assertForbidden();
        $this->getJson('/api/v1/stock/batches')->assertForbidden();
        $this->assertDatabaseCount('stock_cards', 0);
    }

    public function test_scoped_system_employee_can_approve_assigned_main_but_cannot_read_other_network(): void
    {
        $fixture = $this->stockFixture();
        $orderId = $this->submitStock($fixture);
        $foreign = $this->account(AccountType::MainAgent, $fixture['system']);
        $employee = $this->employee($fixture['system'], $fixture['main'], ['account.view', 'import.view', 'import.preview', 'import.approve', 'inventory.view', 'inventory.details']);
        $this->asPortalUser($employee);
        $this->postJson('/api/v1/stock/orders/'.$orderId.'/review', ['version' => 1, 'decision' => 'approve', 'idempotency_key' => 'employee-approval-001'])->assertOk();
        $this->getJson('/api/v1/stock/options')->assertOk()->assertJsonCount(1, 'data.accounts')->assertJsonPath('data.accounts.0.id', $fixture['main']->id)->assertJsonCount(0, 'data.prices');
        $this->getJson('/api/v1/stock/batches?account_id='.$foreign->id)->assertOk()->assertJsonCount(0, 'data');
        $this->assertSame(1350075, $this->voucherBalance($fixture['main']));
    }

    public function test_pin_and_cost_denials_redact_all_stored_drafts_cards_options_and_preview_prices(): void
    {
        $fixture = $this->stockFixture();
        $batch = $this->approvedStock($fixture);
        $employee = $this->employee($fixture['system'], $fixture['main'], ['account.view', 'import.view', 'import.preview', 'inventory.view', 'inventory.details', 'inventory.export', 'exports.view', 'exports.encrypt']);
        $this->asPortalUser($employee);
        $this->getJson('/api/v1/stock/orders/'.$batch->order_id)->assertOk()->assertJsonMissingPath('data.draft.lines.0.rows.0.pin')->assertJsonMissingPath('data.draft.lines.0.cost')->assertJsonMissingPath('data.summary.lines.0.load_price');
        $this->getJson('/api/v1/stock/batches/'.$batch->id.'/cards')->assertOk()->assertJsonMissingPath('data.0.pin')->assertJsonMissingPath('data.0.cost')->assertJsonMissingPath('data.0.credit');
        $this->getJson('/api/v1/stock/batches/'.$batch->id)->assertOk()->assertJsonMissingPath('data.cost')->assertJsonMissingPath('data.amount');
        $this->postJson('/api/v1/stock/orders/preview', $this->stockDraft($fixture, 1, 'new'))->assertOk()->assertJsonMissingPath('data.lines.0.load_price')->assertJsonMissingPath('data.amounts')->assertJsonMissingPath('data.lines.0.checked.0.pin');
        $this->postJson('/api/v1/stock/batches/'.$batch->id.'/copy', ['version' => 1, 'reason' => 'تصدير الرموز', 'secrets' => true, 'card_ids' => StockCard::pluck('id')->all()])->assertForbidden();
    }

    public function test_view_permission_alone_never_allows_mutating_inventory_and_explicit_parent_denial_applies(): void
    {
        $fixture = $this->stockFixture();
        $batch = $this->approvedStock($fixture);
        $employee = $this->employee($fixture['system'], $fixture['main'], ['account.view', 'inventory.view', 'inventory.details']);
        $this->asPortalUser($employee);
        $this->postJson('/api/v1/stock/batches/'.$batch->id.'/actions', ['version' => 1, 'action' => 'quarantine', 'reason' => 'غير مسموح', 'idempotency_key' => 'view-only-001'])->assertForbidden();
        $permissionId = DB::table('permissions')->where('name', 'inventory.quarantine')->value('id');
        DB::table('account_permission_rules')->insert(['target_account_id' => $fixture['main']->id, 'authority_account_id' => $fixture['system']->id, 'permission_id' => $permissionId, 'allowed' => false]);
        $this->asPortalUser($fixture['agent']);
        $this->postJson('/api/v1/stock/batches/'.$batch->id.'/actions', ['version' => 1, 'action' => 'quarantine', 'reason' => 'غير مسموح', 'idempotency_key' => 'parent-denied-001'])->assertForbidden();
        $this->assertSame(1350075, $this->voucherBalance($fixture['main']));
    }

    public function test_filters_are_literal_and_pagination_and_date_bounds_are_validated(): void
    {
        $fixture = $this->stockFixture();
        $batch = $this->approvedStock($fixture);
        $this->asPortalUser($fixture['agent']);
        $this->getJson('/api/v1/stock/batches?query=%25')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/stock/batches?provider_id='.$fixture['product']->provider_id.'&product_id='.$fixture['product']->id.'&from=2030-01-01&to=2030-01-01')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $batch->id);
        $this->getJson('/api/v1/stock/orders?product_id='.$fixture['product']->id)->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/stock/batches?per_page=101')->assertUnprocessable();
        $this->getJson('/api/v1/stock/batches?from=2030-02-01&to=2030-01-01')->assertUnprocessable()->assertJsonValidationErrors('to');
        $this->getJson('/api/v1/stock/batches?sort=balance')->assertUnprocessable();
    }

    public function test_inventory_only_profile_gets_scoped_options_and_summary_aggregate_with_cost_redaction(): void
    {
        $fixture = $this->stockFixture();
        $batch = $this->approvedStock($fixture);
        $this->asPortalUser($fixture['agent']);
        $this->getJson('/api/v1/stock/summary?product_id='.$fixture['product']->id)->assertOk()->assertJsonPath('data.available_count', 3)->assertJsonPath('data.quarantined_count', 0)->assertJsonPath('data.available_cost_by_currency.IQD', '12000.33');
        $this->getJson('/api/v1/stock/batches/'.$batch->id)->assertOk()->assertJsonPath('data.min_expiry', '2031-01-01')->assertJsonPath('data.max_expiry', '2031-01-01');
        $this->getJson('/api/v1/stock/summary?provider_id=999999')->assertOk()->assertJsonPath('data.available_count', 0);
        $employee = $this->employee($fixture['system'], $fixture['main'], ['account.view', 'inventory.view']);
        $this->asPortalUser($employee);
        $this->getJson('/api/v1/stock/options')->assertOk()->assertJsonCount(1, 'data.accounts')->assertJsonCount(0, 'data.prices');
        $this->getJson('/api/v1/stock/summary')->assertOk()->assertJsonPath('data.available_count', 3)->assertJsonMissingPath('data.available_cost_by_currency');
        $this->getJson('/api/v1/stock/summary?query=%25')->assertOk()->assertJsonPath('data.available_count', 0);
    }

    public function test_selection_returns_all_matching_cards_across_pages_and_rejects_duplicate_or_invalid_ids(): void
    {
        $fixture = $this->stockFixture();
        $batch = $this->approvedStock($fixture);
        $ids = StockCard::orderBy('id')->pluck('id')->all();
        StockCard::findOrFail($ids[0])->update(['status' => 'Sold', 'sale_id' => 1]);
        $this->asPortalUser($fixture['agent']);
        $this->getJson('/api/v1/stock/batches/'.$batch->id.'/selection?per_page=1')->assertOk()->assertJsonPath('data.ids', $ids)->assertJsonPath('data.total', 3);
        $this->getJson('/api/v1/stock/batches/'.$batch->id.'/selection?mode=remaining')->assertOk()->assertJsonPath('data.ids', array_slice($ids, 1))->assertJsonPath('data.total', 2);
        $this->getJson('/api/v1/stock/batches/'.$batch->id.'/selection?query=SERIAL-first-2')->assertOk()->assertJsonPath('data.ids', [$ids[1]]);
        foreach ([[$ids[1], $ids[1]], ['1'], [0], ['id' => $ids[1]]] as $invalidIds) {
            $this->postJson('/api/v1/stock/batches/'.$batch->id.'/claims', ['version' => 1, 'reason' => 'معرفات غير صحيحة', 'card_ids' => $invalidIds, 'idempotency_key' => 'invalid-set-001'])->assertUnprocessable()->assertJsonValidationErrors('card_ids');
        }
        $this->assertDatabaseCount('stock_claims', 0);
        $foreign = $this->account(AccountType::MainAgent, $fixture['system']);
        $this->asPortalUser($this->userFor($foreign));
        $this->getJson('/api/v1/stock/batches/'.$batch->id.'/selection')->assertNotFound();
    }

    public function test_adjustments_filter_batch_before_pagination_and_expose_real_currency_inside_account_scope(): void
    {
        $fixture = $this->stockFixture();
        $batch = $this->approvedStock($fixture);
        $foreign = StockAdjustment::factory()->create();
        $this->asPortalUser($fixture['agent']);
        $this->postJson('/api/v1/stock/batches/'.$batch->id.'/actions', ['version' => 1, 'action' => 'cancel', 'reason' => 'إلغاء موثق', 'idempotency_key' => 'adjustment-batch-filter'])->assertOk();
        $this->getJson('/api/v1/stock/adjustments?batch_id='.$batch->id.'&status=cancelled&per_page=1')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.batch_id', $batch->id)->assertJsonPath('data.0.currency', 'IQD');
        $this->getJson('/api/v1/stock/adjustments?batch_id='.$foreign->batch_id)->assertOk()->assertJsonPath('meta.total', 0);
    }
}

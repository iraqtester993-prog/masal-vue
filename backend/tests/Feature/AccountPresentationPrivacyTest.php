<?php

namespace Tests\Feature;

use App\Models\Finance\FundingRequest;
use App\Models\Notifications\Notice;
use App\Models\Operations\OperationStop;
use App\Models\Support\SupportMessage;
use App\Models\Support\SupportTicket;
use App\Services\Finance\Ledger;
use App\Services\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesSupport;
use Tests\TestCase;

class AccountPresentationPrivacyTest extends TestCase
{
    use CreatesSupport, RefreshDatabase;

    private function fixture(): array
    {
        $f = $this->supportFixture();
        $f['system']->update(['name' => 'PRIVATE_ACCOUNT_LABEL', 'phone' => '07701234567', 'owner_name' => 'PRIVATE_OWNER_CONTACT']);
        $f['admin']->update(['name' => 'PRIVATE_OWNER_NAME', 'email' => 'private-owner@example.test']);
        $f['manager'] = $this->supportEmployee($f['system'], $f['system'], array_keys(PermissionCatalog::LABELS));
        $f['manager']->update(['name' => 'Visible Manager']);

        return $f;
    }

    private function assertPrivateIdentityAbsent(string $payload): void
    {
        foreach (['PRIVATE_ACCOUNT_LABEL', 'PRIVATE_OWNER_NAME', 'PRIVATE_OWNER_CONTACT', 'private-owner@example.test'] as $private) {
            $this->assertStringNotContainsString($private, $payload);
        }
    }

    public function test_activity_excludes_only_protected_owner_before_limits_and_keeps_normal_system_employee(): void
    {
        $f = $this->fixture();
        foreach ([$f['manager'], $f['admin']] as $index => $user) {
            DB::table('audit_logs')->insert(['user_id' => $user->id, 'account_id' => $f['system']->id, 'subject_account_id' => $f['main']->id, 'action' => 'account.update', 'portal' => 'admin', 'created_at' => now()->addSeconds($index), 'details' => json_encode(['reason' => $index === 1 ? 'PRIVATE_OWNER_NAME' : 'Visible change'])]);
        }
        $this->asPortalUser($f['admin']);
        foreach (['20', 'all'] as $limit) {
            $response = $this->getJson('/api/v1/dashboard/activity?limit='.$limit)->assertOk()->assertJsonCount(1, 'data');
            $this->assertSame('Visible Manager', $response->json('data.0.actor_name'));
            $this->assertPrivateIdentityAbsent($response->getContent());
        }
        $audit = $this->getJson('/api/v1/reports/sections/audit/rows')->assertOk()->assertJsonCount(1, 'data');
        $this->assertPrivateIdentityAbsent($audit->getContent());
        $this->assertSame('Visible Manager', $audit->json('data.0.name'));
        $this->assertDatabaseCount('audit_logs', 2);
        $this->assertDatabaseHas('audit_logs', ['user_id' => $f['admin']->id]);
    }

    public function test_financial_rows_and_totals_remain_while_actor_and_counterparty_identity_are_neutral(): void
    {
        $f = $this->fixture();
        DB::transaction(function () use ($f): void {
            $ledger = app(Ledger::class);
            $source = $ledger->wallet($f['system']->id, 'voucher', 'IQD', 'external');
            $target = $ledger->wallet($f['pos']->id, 'voucher', 'IQD');
            $ledger->pair($f['admin'], 'funding', 'private-funding', [], $source, $target, 10000, 'Funding one');
            $ledger->pair($f['manager'], 'funding', 'visible-funding', [], $source, $target, 2500, 'Funding two');
        });
        $before = hash('sha256', serialize(DB::table('finance_entries')->orderBy('id')->get()->all()));
        $this->asPortalUser($f['admin']);
        $response = $this->getJson('/api/v1/reports/sections/wallets-transfers/rows')->assertOk()->assertJsonCount(2, 'data');
        $this->assertPrivateIdentityAbsent($response->getContent());
        $this->assertEqualsCanonicalizing(['100.00', '25.00'], array_column($response->json('data'), 'amount'));
        $this->assertContains('Visible Manager', array_column($response->json('data'), 'user'));
        foreach ($response->json('data') as $row) {
            $detail = $this->getJson('/api/v1/reports/sections/wallets-transfers/rows/'.$row['id'])->assertOk();
            $this->assertPrivateIdentityAbsent($detail->getContent());
        }
        $this->getJson('/api/v1/reports/sections/wallets-transfers/rows?q=PRIVATE_OWNER_NAME')->assertOk()->assertJsonCount(0, 'data');
        $export = $this->postJson('/api/v1/reports/export', ['section_ids' => ['wallets-transfers']])->assertOk();
        $this->assertPrivateIdentityAbsent($export->getContent());
        $this->assertSame($before, hash('sha256', serialize(DB::table('finance_entries')->orderBy('id')->get()->all())));
        $this->assertSame(0, (int) DB::table('finance_entries')->sum('amount_minor'));
        $this->assertDatabaseCount('finance_transactions', 2);
        $this->asPortalUser($f['posUser'], 'pos');
        $activity = $this->getJson('/api/v1/dashboard/activity?limit=all')->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame('Visible Manager', $activity->json('data.0.actor_name'));
        $this->assertPrivateIdentityAbsent($activity->getContent());
    }

    public function test_financial_and_operation_resources_keep_business_records_and_redact_private_metadata(): void
    {
        $f = $this->fixture();
        $funding = FundingRequest::factory()->create(['from_account_id' => $f['system']->id, 'to_account_id' => $f['main']->id, 'creator_id' => $f['admin']->id, 'reviewer_id' => $f['admin']->id]);
        OperationStop::factory()->create(['creator_id' => $f['admin']->id, 'authority_account_id' => $f['system']->id]);
        $this->asPortalUser($f['admin']);
        $response = $this->getJson('/api/v1/finance/funding-requests')->assertOk()->assertJsonCount(1, 'data');
        $this->assertPrivateIdentityAbsent($response->getContent());
        $this->assertSame('100.00', $response->json('data.0.amount'));
        $this->assertNull($response->json('data.0.creator_id'));
        $this->assertNull($response->json('data.0.reviewer_id'));
        $this->assertSame('الحساب المركزي', $response->json('data.0.from_account_name'));
        $this->getJson('/api/v1/finance/funding-requests?q=PRIVATE_OWNER_NAME')->assertOk()->assertJsonCount(0, 'data');
        $security = $this->getJson('/api/v1/operations/security')->assertOk();
        $this->assertPrivateIdentityAbsent($security->getContent());
        $this->assertDatabaseHas('finance_funding_requests', ['id' => $funding->id, 'creator_id' => $f['admin']->id, 'amount_minor' => 10000]);
        $this->assertDatabaseCount('operation_stops', 1);
    }

    public function test_stored_private_notifications_are_excluded_before_counts_search_and_export_without_deleting_them(): void
    {
        $f = $this->fixture();
        Notice::factory()->toUser($f['agent'])->create(['sender_id' => $f['admin']->id, 'body' => 'PRIVATE_OWNER_NAME · legacy operation']);
        Notice::factory()->toUser($f['agent'])->create(['sender_id' => $f['manager']->id, 'body' => 'Visible operational notice']);
        $this->asPortalUser($f['agent']);
        $response = $this->getJson('/api/v1/notifications')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 1);
        $this->assertSame('Visible Manager', $response->json('data.0.sender_name'));
        $this->assertPrivateIdentityAbsent($response->getContent());
        $this->getJson('/api/v1/notifications?query=PRIVATE_OWNER_NAME')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/notifications/summary')->assertOk()->assertJsonPath('data.unread', 1);
        $export = $this->getJson('/api/v1/notifications/export')->assertOk()->assertJsonPath('data.count', 1);
        $this->assertPrivateIdentityAbsent($export->getContent());
        $report = $this->getJson('/api/v1/reports/sections/operations-notifications/rows')->assertOk()->assertJsonCount(1, 'data');
        $this->assertPrivateIdentityAbsent($report->getContent());
        $this->assertDatabaseCount('notices', 2);
        $this->assertDatabaseCount('notice_recipients', 2);
    }

    public function test_support_keeps_conversation_but_uses_service_labels_without_private_user_details(): void
    {
        $f = $this->fixture();
        $ticket = SupportTicket::factory()->create(['sender_id' => $f['admin']->id, 'origin_id' => $f['system']->id, 'recipient_id' => $f['main']->id, 'route_stack' => [$f['system']->id, $f['main']->id], 'title' => 'Public support conversation', 'description' => 'Original message']);
        $message = SupportMessage::create(['ticket_id' => $ticket->id, 'user_id' => $f['manager']->id, 'account_id' => $f['system']->id, 'body' => 'Visible reply']);
        DB::table('support_message_audience')->insert(['message_id' => $message->id, 'account_id' => $f['main']->id]);
        DB::table('support_history')->insert(['ticket_id' => $ticket->id, 'user_id' => $f['admin']->id, 'action' => 'return', 'from_id' => $f['system']->id, 'to_id' => $f['main']->id, 'created_at' => now()]);
        $this->asPortalUser($f['agent']);
        $detail = $this->getJson('/api/v1/support/tickets/'.$ticket->id)->assertOk();
        $this->assertPrivateIdentityAbsent($detail->getContent());
        $this->assertNull($detail->json('data.sender_id'));
        $this->assertSame('فريق الدعم', $detail->json('data.messages.0.name'));
        $this->assertSame('Visible Manager', $detail->json('data.messages.1.name'));
        $this->getJson('/api/v1/support/tickets?query=PRIVATE_ACCOUNT_LABEL')->assertOk()->assertJsonCount(0, 'data');
        $options = $this->getJson('/api/v1/support/options')->assertOk();
        $this->assertPrivateIdentityAbsent($options->getContent());
        $this->asPortalUser($f['manager']);
        $options = $this->getJson('/api/v1/support/options')->assertOk();
        $this->assertNotContains($f['admin']->id, array_column($options->json('data.broadcast_users'), 'id'));
        $this->assertPrivateIdentityAbsent($options->getContent());
        $report = $this->getJson('/api/v1/reports/sections/support/rows/'.$ticket->id)->assertOk();
        $this->assertPrivateIdentityAbsent($report->getContent());
        $this->assertDatabaseHas('support_messages', ['user_id' => $f['admin']->id, 'body' => 'Original message']);
    }

    public function test_directories_maps_and_lookup_hide_owner_but_keep_normal_manager_and_business_account(): void
    {
        $f = $this->fixture();
        $this->asPortalUser($f['admin']);
        $staff = $this->getJson('/api/v1/accounts/'.$f['system']->id.'/staff')->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame($f['manager']->id, $staff->json('data.0.user_id'));
        $this->assertPrivateIdentityAbsent($staff->getContent());
        foreach (['/api/v1/reports/sections/users/rows', '/api/v1/reports/options', '/api/v1/accounts/'.$f['system']->id, '/api/v1/finance/options', '/api/v1/sales/options', '/api/v1/sales/configuration-options', '/api/v1/account-lookup?account_id='.$f['system']->id, '/api/v1/account-lookup?lookup_ids[]='.$f['system']->id, '/api/v1/account-lookup?context=staff_scope&ancestor_id='.$f['system']->id, '/api/v1/maps/users'] as $url) {
            $response = $this->getJson($url)->assertOk();
            $this->assertPrivateIdentityAbsent($response->getContent());
        }
        $lookup = $this->postJson('/api/v1/account-lookup', ['lookup_ids' => [$f['system']->id]])->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame('الحساب المركزي', $lookup->json('data.0.name'));
        $this->assertNull($lookup->json('data.0.phone'));
        $maps = $this->getJson('/api/v1/maps/users')->assertOk();
        $this->assertNotContains($f['admin']->id, array_column($maps->json('data'), 'id'));
        $this->assertContains($f['manager']->id, array_column($maps->json('data'), 'id'));
        $this->getJson('/api/v1/reports/sections/users/rows?q=private-owner%40example.test')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/account-lookup?account_query=PRIVATE_ACCOUNT_LABEL')->assertOk()->assertJsonCount(0, 'data');
        $this->assertTrue($f['admin']->fresh()->isOperational());
        $this->assertDatabaseHas('users', ['id' => $f['admin']->id, 'email' => 'private-owner@example.test']);
    }
}

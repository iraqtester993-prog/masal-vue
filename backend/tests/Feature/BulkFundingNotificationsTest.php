<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\Notifications\Notice;
use App\Models\PermissionProfile;
use App\Models\User;
use App\Services\ManagementAuthority;
use App\Services\Notifications\ActivityNotifications;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesStock;
use Tests\TestCase;

class BulkFundingNotificationsTest extends TestCase
{
    use CreatesStock, RefreshDatabase;

    private function fixture(): array
    {
        $fixture = $this->stockFixture();
        $fixture['main']->update(['name' => 'الوكيل الممول']);
        $this->approvedStock($fixture);
        $fixture['first'] = $this->account(AccountType::Pos, $fixture['main']);
        $fixture['first']->update(['name' => 'نقطة ألف']);
        $fixture['firstUser'] = $this->userFor($fixture['first']);
        $fixture['second'] = $this->account(AccountType::Pos, $fixture['main']);
        $fixture['second']->update(['name' => 'نقطة باء']);
        $fixture['secondUser'] = $this->userFor($fixture['second']);

        return $fixture;
    }

    private function payload(array $fixture, string $key = 'bulk-notifications-one'): array
    {
        return ['from_account_id' => $fixture['main']->id, 'service' => 'voucher', 'currency' => 'IQD', 'rows' => [['to_account_id' => $fixture['first']->id, 'amount' => '1000.00'], ['to_account_id' => $fixture['second']->id, 'amount' => '2000.00']], 'reference' => 'تمويل نقاط البيع', 'idempotency_key' => $key];
    }

    private function scopedEmployee(Account $funder, Account $root): User
    {
        $profile = PermissionProfile::factory()->create(['account_id' => $funder->id]);
        app(ManagementAuthority::class)->replaceProfilePermissions($profile->id, ['account.view', 'wallets.view', 'notifications.view']);
        $user = User::factory()->create();
        $member = AccountMembership::create(['user_id' => $user->id, 'account_id' => $funder->id, 'kind' => 'employee', 'role_id' => DB::table('roles')->where('name', 'employee')->value('id'), 'permission_profile_id' => $profile->id, 'include_descendants' => true, 'status' => 'active']);
        DB::table('membership_scope_roots')->insert(['membership_id' => $member->id, 'account_id' => $root->id]);

        return $user;
    }

    public function test_system_bulk_notifies_funder_and_each_beneficiary_once_without_repeating_the_debit_on_retry(): void
    {
        $fixture = $this->fixture();
        $before = $this->voucherBalance($fixture['main']);
        $payload = $this->payload($fixture);
        $this->asPortalUser($fixture['admin']);
        $response = $this->postJson('/api/v1/finance/bulk-transfers', $payload)->assertCreated()->assertJsonCount(2, 'data');
        $ids = array_column($response->json('data'), 'transaction_id');
        $auditId = (int) DB::table('audit_logs')->where('action', 'wallets.bulk')->value('id');
        $notices = Notice::whereIn('entity_id', $ids)->where('entity_type', 'transaction')->get();

        $this->assertCount(2, $notices);
        foreach ($notices as $notice) {
            $this->assertSame('wallets', $notice->page);
            $this->assertSame('تم تحويل الرصيد', $notice->title);
            $this->assertStringContainsString('الوكيل الممول', $notice->body);
            $this->assertStringContainsString('الكارتات', $notice->body);
            $this->assertStringContainsString('د.ع', $notice->body);
            $this->assertDatabaseHas('notice_recipients', ['notice_id' => $notice->id, 'user_id' => $fixture['agent']->id, 'read_at' => null]);
            $this->assertDatabaseMissing('notice_recipients', ['notice_id' => $notice->id, 'user_id' => $fixture['admin']->id]);
        }
        foreach ([['firstUser', 'first', 'second', '1000.00'], ['secondUser', 'second', 'first', '2000.00']] as [$user, $account, $other, $amount]) {
            $this->asPortalUser($fixture[$user]);
            $notice = $this->getJson('/api/v1/notifications')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.can_open', true)->assertJsonPath('data.0.page', 'wallets')->json('data.0');
            $this->assertStringContainsString($fixture[$account]->name, $notice['body']);
            $this->assertStringContainsString($amount.' د.ع', $notice['body']);
            $this->assertStringNotContainsString($fixture[$other]->name, $notice['body']);
            $this->getJson('/api/v1/notifications/summary')->assertOk()->assertJsonPath('data.unread', 1)->assertJsonPath('data.sidebar_counts.wallets', 1);
        }
        $this->asPortalUser($fixture['agent']);
        $this->getJson('/api/v1/notifications?query='.urlencode('تم تحويل الرصيد'))->assertOk()->assertJsonCount(2, 'data');
        $this->asPortalUser($fixture['admin']);
        $this->postJson('/api/v1/finance/bulk-transfers', $payload)->assertCreated()->assertJsonPath('data.0.transaction_id', $ids[0])->assertJsonPath('data.1.transaction_id', $ids[1]);
        app(ActivityNotifications::class)->publish($auditId);
        app(ActivityNotifications::class)->publish($auditId);

        $this->assertSame(2, Notice::whereIn('entity_id', $ids)->where('entity_type', 'transaction')->count());
        $this->assertSame(4, DB::table('notice_recipients')->whereIn('notice_id', $notices->pluck('id'))->count());
        $this->assertSame(2, DB::table('finance_transactions')->where('kind', 'transfer')->count());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'wallets.bulk')->count());
        $this->assertSame($before - 300000, $this->voucherBalance($fixture['main']));
        $this->assertSame(100000, $this->voucherBalance($fixture['first']));
        $this->assertSame(200000, $this->voucherBalance($fixture['second']));
        $this->assertDatabaseMissing('finance_wallets', ['account_id' => $fixture['system']->id, 'service' => 'voucher', 'kind' => 'account']);
        $this->assertSame(0, (int) DB::table('finance_entries')->sum('amount_minor'));
    }

    public function test_bulk_notifications_respect_employee_scope_recipient_wallet_permission_and_foreign_network(): void
    {
        $fixture = $this->fixture();
        $firstEmployee = $this->scopedEmployee($fixture['main'], $fixture['first']);
        $secondEmployee = $this->scopedEmployee($fixture['main'], $fixture['second']);
        $foreign = $this->userFor($this->account(AccountType::MainAgent, $fixture['system']));
        DB::table('membership_permissions')->insert(['membership_id' => $fixture['secondUser']->membership->id, 'permission_id' => DB::table('permissions')->where('name', 'wallets.view')->value('id'), 'allowed' => false]);
        $this->asPortalUser($fixture['admin']);
        $this->postJson('/api/v1/finance/bulk-transfers', $this->payload($fixture))->assertCreated();

        foreach ([[$firstEmployee, 'first', 'second'], [$secondEmployee, 'second', 'first']] as [$employee, $account, $other]) {
            $this->asPortalUser($employee);
            $notice = $this->getJson('/api/v1/notifications')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.can_open', true)->json('data.0');
            $this->assertStringContainsString($fixture[$account]->name, $notice['body']);
            $this->assertStringNotContainsString($fixture[$other]->name, $notice['body']);
        }
        foreach ([$fixture['secondUser'], $foreign] as $user) {
            $this->asPortalUser($user);
            $this->getJson('/api/v1/notifications')->assertOk()->assertJsonCount(0, 'data');
        }
    }

    public function test_insufficient_bulk_funding_rolls_back_all_transfers_and_creates_no_notification(): void
    {
        $fixture = $this->fixture();
        $before = $this->voucherBalance($fixture['main']);
        $payload = $this->payload($fixture, 'bulk-notifications-insufficient');
        $payload['rows'][1]['amount'] = '20000.00';
        $this->asPortalUser($fixture['admin']);
        $this->postJson('/api/v1/finance/bulk-transfers', $payload)->assertUnprocessable()->assertJsonValidationErrors('amount');

        $this->assertDatabaseMissing('finance_transactions', ['kind' => 'transfer']);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'wallets.bulk']);
        $this->assertDatabaseMissing('notices', ['entity_type' => 'transaction']);
        $this->assertSame($before, $this->voucherBalance($fixture['main']));
        $this->assertSame(0, $this->voucherBalance($fixture['first']));
        $this->assertSame(0, $this->voucherBalance($fixture['second']));
    }
}

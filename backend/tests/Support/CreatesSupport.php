<?php

namespace Tests\Support;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\PermissionProfile;
use App\Models\User;
use App\Services\ManagementAuthority;
use Illuminate\Support\Facades\DB;

trait CreatesSupport
{
    use CreatesAccounts;

    private function supportFixture(): array
    {
        $system = $this->account(AccountType::System);
        $main = $this->account(AccountType::MainAgent, $system);
        $sub = $this->account(AccountType::SubAgent, $main);
        $branch = $this->account(AccountType::SubBranch, $sub);
        $pos = $this->account(AccountType::Pos, $branch);
        $foreign = $this->account(AccountType::MainAgent, $system);
        $admin = $this->userFor($system);
        $agent = $this->userFor($main);
        $subUser = $this->userFor($sub);
        $branchUser = $this->userFor($branch);
        $posUser = $this->userFor($pos);
        $foreignUser = $this->userFor($foreign);

        return compact('system', 'main', 'sub', 'branch', 'pos', 'foreign', 'admin', 'agent', 'subUser', 'branchUser', 'posUser', 'foreignUser');
    }

    private function supportEmployee(Account $employer, Account $root, array $permissions): User
    {
        $profile = PermissionProfile::factory()->create(['account_id' => $employer->id]);
        app(ManagementAuthority::class)->replaceProfilePermissions($profile->id, $permissions);
        $user = User::factory()->create();
        $member = AccountMembership::create(['user_id' => $user->id, 'account_id' => $employer->id, 'kind' => 'employee', 'role_id' => DB::table('roles')->where('name', 'employee')->value('id'), 'permission_profile_id' => $profile->id, 'include_descendants' => true, 'status' => 'active']);
        DB::table('membership_scope_roots')->insert(['membership_id' => $member->id, 'account_id' => $root->id]);

        return $user;
    }

    private function supportGrant(User $user, string $permission, bool $allowed = true): void
    {
        DB::table('membership_permissions')->updateOrInsert(['membership_id' => $user->membership->id, 'permission_id' => DB::table('permissions')->where('name', $permission)->value('id')], ['allowed' => $allowed]);
    }

    private function openSupport(User $sender, Account $recipient, string $key = 'support-open'): int
    {
        $this->asPortalUser($sender);

        return $this->postJson('/api/v1/support/tickets', ['recipient_id' => $recipient->id, 'title' => 'دعم العملية', 'description' => 'يرجى مراجعة العملية', 'idempotency_key' => $key])->assertOk()->json('data.id');
    }
}

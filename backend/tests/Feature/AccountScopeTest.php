<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CreatesAccounts;
use Tests\TestCase;

class AccountScopeTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    public function test_agent_list_and_direct_lookup_never_cross_networks(): void
    {
        $root = $this->account(AccountType::System);
        $own = $this->account(AccountType::MainAgent, $root);
        $child = $this->account(AccountType::SubAgent, $own);
        $pos = $this->account(AccountType::Pos, $child);
        $foreign = $this->account(AccountType::MainAgent, $root);
        $foreignPos = $this->account(AccountType::Pos, $foreign);
        $this->asPortalUser($this->userFor($own));
        $response = $this->getJson('/api/v1/accounts?per_page=2')->assertOk()
            ->assertJsonPath('meta.total', 3)->assertJsonPath('meta.per_page', 2)->assertJsonCount(2, 'data');
        $this->assertEqualsCanonicalizing([$own->id, $child->id], array_column($response->json('data'), 'id'));
        $this->getJson('/api/v1/accounts?per_page=2&page=2')->assertOk()->assertJsonPath('data.0.id', $pos->id)->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/accounts/'.$pos->id)->assertOk()->assertJsonPath('data.id', $pos->id);
        foreach ([$root, $foreign, $foreignPos] as $outside) {
            $this->getJson('/api/v1/accounts/'.$outside->id)->assertNotFound();
        }
    }

    public function test_sub_agent_cannot_view_parent_or_sibling(): void
    {
        $root = $this->account(AccountType::System);
        $main = $this->account(AccountType::MainAgent, $root);
        $sub = $this->account(AccountType::SubAgent, $main);
        $sibling = $this->account(AccountType::SubAgent, $main);
        $branch = $this->account(AccountType::SubBranch, $sub);
        $this->asPortalUser($this->userFor($sub));
        $this->getJson('/api/v1/accounts')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/accounts/'.$branch->id)->assertOk();
        $this->getJson('/api/v1/accounts/'.$main->id)->assertNotFound();
        $this->getJson('/api/v1/accounts/'.$sibling->id)->assertNotFound();
    }

    public function test_sub_branch_sees_its_own_point_of_sale(): void
    {
        $root = $this->account(AccountType::System);
        $main = $this->account(AccountType::MainAgent, $root);
        $sub = $this->account(AccountType::SubAgent, $main);
        $branch = $this->account(AccountType::SubBranch, $sub);
        $pos = $this->account(AccountType::Pos, $branch);
        $this->asPortalUser($this->userFor($branch));
        $this->getJson('/api/v1/accounts')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/accounts/'.$pos->id)->assertOk();
        $this->getJson('/api/v1/accounts/'.$sub->id)->assertNotFound();
    }

    public function test_point_of_sale_can_only_read_itself(): void
    {
        $root = $this->account(AccountType::System);
        $main = $this->account(AccountType::MainAgent, $root);
        $own = $this->account(AccountType::Pos, $main);
        $other = $this->account(AccountType::Pos, $main);
        $this->asPortalUser($this->userFor($own));
        $this->getJson('/api/v1/accounts')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $own->id);
        $this->getJson('/api/v1/accounts/'.$other->id)->assertNotFound();
        $this->getJson('/api/v1/accounts/'.$main->id)->assertNotFound();
    }

    public function test_system_can_read_all_accounts(): void
    {
        $root = $this->account(AccountType::System);
        $main = $this->account(AccountType::MainAgent, $root);
        $this->account(AccountType::Pos, $main);
        $this->asPortalUser($this->userFor($root));
        $this->getJson('/api/v1/accounts')->assertOk()->assertJsonPath('meta.total', 3);
    }

    public function test_explicit_permission_deny_overrides_role_grant(): void
    {
        $root = $this->account(AccountType::System);
        $user = $this->userFor($root);
        DB::table('membership_permissions')->insert([
            'membership_id' => $user->membership->id,
            'permission_id' => DB::table('permissions')->where('name', 'account.view')->value('id'),
            'allowed' => false,
        ]);
        $this->asPortalUser($user);
        $response = $this->getJson('/api/v1/auth/me')->assertOk();
        $this->assertNotContains('account.view', $response->json('data.permissions'));
        $this->assertContains('account.create', $response->json('data.permissions'));
        $this->getJson('/api/v1/accounts')->assertForbidden();
        $this->getJson('/api/v1/accounts/'.$root->id)->assertForbidden();
    }

    public static function disabledCases(): array
    {
        return ['user' => ['user'], 'membership' => ['membership'], 'account' => ['account'], 'ancestor' => ['ancestor']];
    }

    #[DataProvider('disabledCases')]
    public function test_disabling_user_membership_account_or_ancestor_blocks_login_and_existing_session(string $target): void
    {
        $root = $this->account(AccountType::System);
        $main = $this->account(AccountType::MainAgent, $root);
        $pos = $this->account(AccountType::Pos, $main);
        $user = $this->userFor($pos);
        match ($target) {
            'user' => $user->update(['status' => 'disabled']),
            'membership' => $user->membership->update(['status' => 'disabled']),
            'account' => $pos->update(['status' => 'disabled']),
            'ancestor' => $main->update(['status' => 'disabled']),
        };
        $this->withHeader('X-Masal-Portal', 'pos')->postJson('/api/v1/auth/login', ['login' => $user->login, 'password' => 'password'])
            ->assertUnprocessable()->assertJsonValidationErrors('login');
        $this->asPortalUser($user);
        $this->getJson('/api/v1/auth/me')->assertForbidden();
        $this->getJson('/api/v1/accounts')->assertForbidden();
    }

    public function test_missing_ancestor_closure_row_fails_closed(): void
    {
        $root = $this->account(AccountType::System);
        $main = $this->account(AccountType::MainAgent, $root);
        $pos = $this->account(AccountType::Pos, $main);
        DB::table('account_closure')->where('ancestor_id', $main->id)->where('descendant_id', $pos->id)->delete();
        $this->asPortalUser($this->userFor($pos));
        $this->getJson('/api/v1/auth/me')->assertForbidden();
    }

    public static function invalidPaginationCases(): array
    {
        return ['zero page' => ['page=0', 'page'], 'negative page' => ['page=-1', 'page'], 'oversized page size' => ['per_page=101', 'per_page'], 'zero page size' => ['per_page=0', 'per_page'], 'non-numeric size' => ['per_page=bad', 'per_page']];
    }

    #[DataProvider('invalidPaginationCases')]
    public function test_pagination_rejects_invalid_bounds(string $query, string $field): void
    {
        $this->asPortalUser($this->userFor($this->account(AccountType::System)));
        $this->getJson('/api/v1/accounts?'.$query)->assertUnprocessable()->assertJsonValidationErrors($field);
    }
}

<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\CreateAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CreatesAccounts;
use Tests\TestCase;

class AccountCreationTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    private function payload(Account $parent, AccountType $type = AccountType::Pos): array
    {
        return [
            'name' => 'New Account',
            'type' => $type->value,
            'parent_id' => $parent->id,
            'city' => 'بغداد',
            'phone' => '07700000000',
            ...($type === AccountType::Pos ? ['owner_name' => 'Office Owner', 'address' => 'Baghdad Office', 'serial' => fake()->unique()->bothify('FIX-####-????'), 'device_model' => 'Recorded Device', 'app_version' => '1.0'] : ['color' => '#4F46E5']),
            'user' => [
                'name' => 'Account Manager',
                'login' => 'child.manager',
                'email' => 'child@example.test',
                'password' => 'TestFixture12345',
                'password_confirmation' => 'TestFixture12345',
            ],
        ];
    }

    public function test_admin_creates_main_agent_with_atomic_user_membership_and_closure(): void
    {
        $root = $this->account(AccountType::System);
        $admin = $this->userFor($root);
        $this->asPortalUser($admin);
        $payload = $this->payload($root, AccountType::MainAgent);
        $response = $this->postJson('/api/v1/accounts', $payload)->assertCreated()
            ->assertJsonPath('data.account.type', 'main_agent')->assertJsonPath('data.account.parent_id', $root->id)
            ->assertJsonPath('data.account.status', 'active')->assertJsonPath('data.user.login', 'child.manager')
            ->assertJsonMissingPath('data.user.password');
        $accountId = $response->json('data.account.id');
        $newUser = User::findOrFail($response->json('data.user.id'));
        $this->assertTrue(Hash::check($payload['user']['password'], $newUser->password));
        $this->assertSame($accountId, $newUser->membership->account_id);
        $this->assertContains('account.create', $newUser->membership->permissions());
        $this->assertContains('account.view', $newUser->membership->permissions());
        $this->assertDatabaseHas('account_closure', ['ancestor_id' => $root->id, 'descendant_id' => $accountId, 'depth' => 1]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'account.create', 'user_id' => $admin->id, 'subject_account_id' => $accountId]);
    }

    public function test_account_creation_accepts_nine_digits_and_rejects_eight(): void
    {
        $root = $this->account(AccountType::System);
        $this->asPortalUser($this->userFor($root));
        $payload = $this->payload($root, AccountType::MainAgent);
        $payload['user']['password'] = $payload['user']['password_confirmation'] = '12345678';
        $this->postJson('/api/v1/accounts', $payload)->assertUnprocessable()->assertJsonValidationErrors('user.password');
        $payload['user']['password'] = $payload['user']['password_confirmation'] = '123456789';
        $response = $this->postJson('/api/v1/accounts', $payload)->assertCreated();
        $this->assertTrue(Hash::check('123456789', User::findOrFail($response->json('data.user.id'))->password));
    }

    public static function agentCases(): array
    {
        return [
            'main creates sub' => [AccountType::MainAgent, AccountType::SubAgent],
            'main creates pos' => [AccountType::MainAgent, AccountType::Pos],
            'sub creates branch' => [AccountType::SubAgent, AccountType::SubBranch],
            'sub creates pos' => [AccountType::SubAgent, AccountType::Pos],
            'branch creates pos' => [AccountType::SubBranch, AccountType::Pos],
        ];
    }

    private function chain(): array
    {
        $root = $this->account(AccountType::System);
        $main = $this->account(AccountType::MainAgent, $root);
        $sub = $this->account(AccountType::SubAgent, $main);
        $branch = $this->account(AccountType::SubBranch, $sub);

        return ['system' => $root, 'main_agent' => $main, 'sub_agent' => $sub, 'sub_branch' => $branch];
    }

    #[DataProvider('agentCases')]
    public function test_agents_create_valid_direct_children(AccountType $actorType, AccountType $childType): void
    {
        $parent = $this->chain()[$actorType->value];
        $this->asPortalUser($this->userFor($parent));
        $response = $this->postJson('/api/v1/accounts', $this->payload($parent, $childType))->assertCreated();
        $newUser = User::findOrFail($response->json('data.user.id'));
        $this->assertSame($childType->portal(), $newUser->membership->account->type->portal());
        $this->assertContains('account.view', $newUser->membership->permissions());
        $this->assertSame($childType !== AccountType::Pos, in_array('account.create', $newUser->membership->permissions(), true));
    }

    public function test_main_agent_can_create_lower_child_under_a_descendant_parent(): void
    {
        $chain = $this->chain();
        $this->asPortalUser($this->userFor($chain['main_agent']));
        $this->postJson('/api/v1/accounts', $this->payload($chain['sub_agent'], AccountType::SubBranch))
            ->assertCreated()->assertJsonPath('data.account.parent_id', $chain['sub_agent']->id);
    }

    public function test_agent_cannot_create_under_foreign_sibling_or_ancestor_parent(): void
    {
        $chain = $this->chain();
        $foreign = $this->account(AccountType::MainAgent, $chain['system']);
        $sibling = $this->account(AccountType::SubAgent, $chain['main_agent']);
        $this->asPortalUser($this->userFor($chain['sub_agent']));
        $before = Account::count();
        foreach ([$foreign, $sibling, $chain['main_agent']] as $parent) {
            $this->postJson('/api/v1/accounts', $this->payload($parent))->assertNotFound();
        }
        $this->assertDatabaseCount('accounts', $before);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_invalid_child_level_does_not_create_any_records(): void
    {
        $chain = $this->chain();
        $this->asPortalUser($this->userFor($chain['main_agent']));
        $before = DB::table('account_closure')->count();
        $this->postJson('/api/v1/accounts', $this->payload($chain['main_agent'], AccountType::SubBranch))
            ->assertUnprocessable()->assertJsonValidationErrors('parent_id');
        $this->assertDatabaseCount('accounts', 4);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('account_closure', $before);
    }

    public function test_point_of_sale_cannot_create_even_with_forged_permission(): void
    {
        $chain = $this->chain();
        $pos = $this->account(AccountType::Pos, $chain['main_agent']);
        $user = $this->userFor($pos);
        DB::table('membership_permissions')->insert([
            'membership_id' => $user->membership->id,
            'permission_id' => DB::table('permissions')->where('name', 'account.create')->value('id'),
            'allowed' => true,
        ]);
        $this->asPortalUser($user);
        $this->postJson('/api/v1/accounts', $this->payload($pos))->assertForbidden();
    }

    public function test_creation_permission_can_be_explicitly_denied(): void
    {
        $root = $this->account(AccountType::System);
        $user = $this->userFor($root);
        DB::table('membership_permissions')->insert([
            'membership_id' => $user->membership->id,
            'permission_id' => DB::table('permissions')->where('name', 'account.create')->value('id'),
            'allowed' => false,
        ]);
        $this->asPortalUser($user);
        $this->postJson('/api/v1/accounts', $this->payload($root, AccountType::MainAgent))->assertForbidden();
    }

    public function test_duplicate_credentials_are_rejected_without_orphan_account(): void
    {
        $root = $this->account(AccountType::System);
        $user = $this->userFor($root);
        $this->asPortalUser($user);
        $payload = $this->payload($root, AccountType::MainAgent);
        $payload['user']['login'] = $user->login;
        $payload['user']['email'] = $user->email;
        $this->postJson('/api/v1/accounts', $payload)->assertUnprocessable()->assertJsonValidationErrors(['user.login', 'user.email']);
        $this->assertDatabaseCount('accounts', 1);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_concurrent_duplicate_failure_rolls_back_account_and_closure(): void
    {
        $root = $this->account(AccountType::System);
        $actor = $this->userFor($root);
        $payload = $this->payload($root, AccountType::MainAgent);
        User::factory()->create(['login' => $payload['user']['login']]);
        $this->expectException(ValidationException::class);
        try {
            app(CreateAccount::class)->execute($actor, $payload);
        } finally {
            $this->assertDatabaseCount('accounts', 1);
            $this->assertDatabaseCount('account_closure', 1);
            $this->assertDatabaseCount('account_memberships', 1);
        }
    }

    public function test_audit_failure_rolls_back_the_entire_creation(): void
    {
        $root = $this->account(AccountType::System);
        $this->asPortalUser($this->userFor($root));
        $this->mock(AuditLogger::class)->shouldReceive('record')->once()->andThrow(new \RuntimeException('Simulated audit failure'));
        $this->postJson('/api/v1/accounts', $this->payload($root, AccountType::MainAgent))->assertStatus(500);
        $this->assertDatabaseCount('accounts', 1);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('account_memberships', 1);
        $this->assertDatabaseCount('account_closure', 1);
    }

    public function test_password_confirmation_is_required_and_must_match(): void
    {
        $root = $this->account(AccountType::System);
        $this->asPortalUser($this->userFor($root));
        $payload = $this->payload($root, AccountType::MainAgent);
        $payload['user']['password_confirmation'] = 'DifferentTest1234';
        $this->postJson('/api/v1/accounts', $payload)->assertUnprocessable()->assertJsonValidationErrors('user.password');
        unset($payload['user']['password_confirmation']);
        $this->postJson('/api/v1/accounts', $payload)->assertUnprocessable()->assertJsonValidationErrors('user.password_confirmation');
        $this->assertDatabaseCount('accounts', 1);
    }

    public function test_nested_password_whitespace_is_preserved_for_later_login(): void
    {
        $root = $this->account(AccountType::System);
        $this->asPortalUser($this->userFor($root));
        $payload = $this->payload($root, AccountType::MainAgent);
        $payload['user']['password'] = '  TestFixture12345  ';
        $payload['user']['password_confirmation'] = $payload['user']['password'];
        $response = $this->postJson('/api/v1/accounts', $payload)->assertCreated();
        $user = User::findOrFail($response->json('data.user.id'));
        $this->assertTrue(Hash::check($payload['user']['password'], $user->password));
        $this->assertFalse(Hash::check(trim($payload['user']['password']), $user->password));
        $this->postJson('/api/v1/auth/logout')->assertNoContent();
        Auth::forgetGuards();
        $this->withHeader('X-Masal-Portal', 'agents')->postJson('/api/v1/auth/login', [
            'login' => $payload['user']['email'], 'password' => $payload['user']['password'],
        ])->assertOk()->assertJsonPath('data.user.id', $user->id);
    }

    public static function privilegedFieldCases(): array
    {
        return ['role' => ['role_id'], 'status' => ['status'], 'permission' => ['permissions'], 'actor' => ['user_id']];
    }

    #[DataProvider('privilegedFieldCases')]
    public function test_client_cannot_set_privileged_top_level_fields(string $field): void
    {
        $root = $this->account(AccountType::System);
        $this->asPortalUser($this->userFor($root));
        $payload = $this->payload($root, AccountType::MainAgent);
        $payload[$field] = 'forged';
        $this->postJson('/api/v1/accounts', $payload)->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('accounts', 1);
    }

    public function test_client_cannot_set_nested_user_role_or_create_system_account(): void
    {
        $root = $this->account(AccountType::System);
        $this->asPortalUser($this->userFor($root));
        $payload = $this->payload($root, AccountType::System);
        $payload['user']['role_id'] = 1;
        $this->postJson('/api/v1/accounts', $payload)->assertUnprocessable()->assertJsonValidationErrors(['type', 'user']);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_oversized_multibyte_password_is_rejected_before_bcrypt(): void
    {
        $root = $this->account(AccountType::System);
        $this->asPortalUser($this->userFor($root));
        $payload = $this->payload($root, AccountType::MainAgent);
        $payload['user']['password'] = str_repeat('ع', 40).'123';
        $payload['user']['password_confirmation'] = $payload['user']['password'];
        $this->postJson('/api/v1/accounts', $payload)->assertUnprocessable()->assertJsonValidationErrors('user.password');
    }

    public function test_disabled_parent_rejects_creation(): void
    {
        $root = $this->account(AccountType::System);
        $main = $this->account(AccountType::MainAgent, $root);
        $main->update(['status' => 'disabled']);
        $this->asPortalUser($this->userFor($root));
        $this->postJson('/api/v1/accounts', $this->payload($main))->assertUnprocessable()->assertJsonValidationErrors('parent_id');
        $this->assertDatabaseCount('users', 1);
    }

    public function test_missing_authentication_cannot_create_account(): void
    {
        $this->withHeader('X-Masal-Portal', 'admin')->postJson('/api/v1/accounts', [])->assertUnauthorized();
    }
}

<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Models\User;
use App\Services\AccountHierarchy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CreatesAccounts;
use Tests\TestCase;

class AccountHierarchyTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    public static function posParentCases(): array
    {
        return ['main agent' => [AccountType::MainAgent], 'sub agent' => [AccountType::SubAgent], 'sub branch' => [AccountType::SubBranch]];
    }

    #[DataProvider('posParentCases')]
    public function test_point_of_sale_can_attach_to_any_agent_level(AccountType $parentType): void
    {
        $root = $this->account(AccountType::System);
        $main = $this->account(AccountType::MainAgent, $root);
        $parent = $main;
        if ($parentType !== AccountType::MainAgent) {
            $parent = $this->account(AccountType::SubAgent, $main);
        }
        if ($parentType === AccountType::SubBranch) {
            $parent = $this->account(AccountType::SubBranch, $parent);
        }
        $pos = $this->account(AccountType::Pos, $parent);
        $this->assertSame($parent->id, $pos->parent_id);
        $this->assertTrue($pos->isOperational());
        $this->assertDatabaseHas('account_closure', ['ancestor_id' => $parent->id, 'descendant_id' => $pos->id, 'depth' => 1]);
        $this->assertDatabaseHas('account_closure', ['ancestor_id' => $root->id, 'descendant_id' => $pos->id]);
    }

    public function test_invalid_typed_parent_rolls_back_without_orphan_rows(): void
    {
        $root = $this->account(AccountType::System);
        $this->expectException(ValidationException::class);
        try {
            $this->account(AccountType::SubBranch, $root);
        } finally {
            $this->assertDatabaseCount('accounts', 1);
            $this->assertDatabaseCount('account_closure', 1);
        }
    }

    public function test_non_system_account_cannot_be_created_without_parent(): void
    {
        $this->expectException(ValidationException::class);
        app(AccountHierarchy::class)->create('Orphan', AccountType::MainAgent);
    }

    public function test_second_root_system_account_is_rejected(): void
    {
        $this->account(AccountType::System);
        $this->expectException(ValidationException::class);
        $this->account(AccountType::System);
    }

    public function test_cyclic_parent_chain_fails_closed(): void
    {
        $root = $this->account(AccountType::System);
        $main = $this->account(AccountType::MainAgent, $root);
        DB::table('accounts')->where('id', $root->id)->update(['parent_id' => $main->id]);
        $this->assertFalse($main->isOperational());
    }

    public function test_permission_seeder_never_creates_default_users(): void
    {
        $this->seed();
        $permissionsCount = DB::table('permissions')->count();
        $this->seed();
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('roles', 4);
        $this->assertDatabaseCount('permissions', $permissionsCount);
    }

    public function test_admin_command_refuses_non_interactive_password_creation(): void
    {
        $this->artisan('masal:create-admin --no-interaction')->assertExitCode(1);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_admin_command_creates_a_hashed_password_and_scoped_membership(): void
    {
        $testPassword = 'TestFixture12345';
        $this->artisan('masal:create-admin --login=test.admin --email=admin@example.test --name=Admin')
            ->expectsQuestion('Password (12+ characters, letters and numbers)', $testPassword)
            ->expectsQuestion('Confirm password', $testPassword)
            ->assertExitCode(0);
        $user = User::where('login', 'test.admin')->firstOrFail();
        $this->assertNotSame($testPassword, $user->password);
        $this->assertTrue(Hash::check($testPassword, $user->password));
        $this->assertSame(AccountType::System, $user->membership->account->type);
        $this->assertContains('account.create', $user->membership->permissions());
        $this->assertContains('account.view', $user->membership->permissions());
        $this->assertTrue($user->isOperational());
    }
}

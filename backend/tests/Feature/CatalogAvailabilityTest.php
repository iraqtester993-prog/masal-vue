<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\CatalogProduct;
use App\Models\PermissionProfile;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\CatalogAccess;
use App\Services\ManagementAuthority;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CreatesAccounts;
use Tests\TestCase;

class CatalogAvailabilityTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    private function allow(Account $target, Account $authority, array $products): void
    {
        $rule = DB::table('catalog_account_rules')->insertGetId(['target_account_id' => $target->id, 'authority_account_id' => $authority->id]);
        foreach ($products as $product) {
            DB::table('catalog_rule_products')->insert(['rule_id' => $rule, 'product_id' => $product->id]);
        }
    }

    private function payload(Account $target, array $products): array
    {
        return ['version' => $target->fresh()->version, 'catalog_version' => (int) DB::table('catalog_meta')->value('version'), 'product_ids' => array_map(static fn ($p): int => $p->id, $products)];
    }

    public function test_system_employee_reads_only_assigned_network_and_cannot_modify_global_catalog(): void
    {
        $system = $this->account(AccountType::System);
        $this->userFor($system);
        $main = $this->account(AccountType::MainAgent, $system);
        $foreign = $this->account(AccountType::MainAgent, $system);
        $allowed = CatalogProduct::factory()->create();
        $other = CatalogProduct::factory()->create();
        $this->allow($main, $system, [$allowed]);
        $this->allow($foreign, $system, [$other]);
        $profile = PermissionProfile::factory()->create(['account_id' => $system->id]);
        app(ManagementAuthority::class)->replaceProfilePermissions($profile->id, ['account.view', 'products.view', 'providers.view', 'providers.create']);
        $user = User::factory()->create();
        $member = AccountMembership::create(['user_id' => $user->id, 'account_id' => $system->id, 'role_id' => DB::table('roles')->where('name', 'employee')->value('id'), 'kind' => 'employee', 'permission_profile_id' => $profile->id, 'include_descendants' => true, 'status' => 'active']);
        DB::table('membership_scope_roots')->insert(['membership_id' => $member->id, 'account_id' => $main->id]);
        $this->asPortalUser($user);
        $this->getJson('/api/v1/catalog/products')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $allowed->id);
        $this->getJson('/api/v1/catalog/providers')->assertForbidden();
        $this->postJson('/api/v1/catalog/providers', ['name' => 'Employee global', 'supplier' => 'No', 'connection' => 'API'])->assertForbidden();
        $this->getJson('/api/v1/accounts/'.$foreign->id.'/categories')->assertNotFound();
    }

    public function test_product_list_query_count_does_not_grow_per_row(): void
    {
        $system = $this->account(AccountType::System);
        $this->asPortalUser($this->userFor($system));
        CatalogProduct::factory()->create();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->getJson('/api/v1/catalog/products?per_page=100')->assertOk();
        $one = count(DB::getQueryLog());
        CatalogProduct::factory()->count(24)->create();
        DB::flushQueryLog();
        $this->getJson('/api/v1/catalog/products?per_page=100')->assertOk()->assertJsonCount(25, 'data');
        $many = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertLessThanOrEqual($one + 3, $many, 'Provider loading must not issue a query for each row.');
    }

    public function test_new_main_agent_catalog_is_empty_until_explicitly_assigned(): void
    {
        $system = $this->account(AccountType::System);
        $main = $this->account(AccountType::MainAgent, $system);
        CatalogProduct::factory()->create();
        $this->asPortalUser($this->userFor($main));
        $this->getJson('/api/v1/catalog/products')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 0);
    }

    public function test_admin_assigns_categories_to_main_and_empty_selection_revokes_all(): void
    {
        $system = $this->account(AccountType::System);
        $main = $this->account(AccountType::MainAgent, $system);
        $product = CatalogProduct::factory()->create();
        $this->asPortalUser($this->userFor($system));
        $this->putJson('/api/v1/accounts/'.$main->id.'/categories', $this->payload($main, [$product]))->assertOk()->assertJsonPath('data.selected_ids.0', $product->id)->assertJsonPath('data.account_version', 2);
        $this->putJson('/api/v1/accounts/'.$main->id.'/categories', $this->payload($main, []))->assertOk()->assertJsonCount(0, 'data.selected_ids');
        $this->assertDatabaseCount('catalog_account_rules', 1);
        $this->assertDatabaseCount('catalog_rule_products', 0);
        $this->assertDatabaseHas('audit_logs', ['action' => 'agents.categories', 'subject_account_id' => $main->id]);
    }

    public function test_each_parent_limit_applies_through_all_agent_levels_and_any_pos_parent(): void
    {
        $system = $this->account(AccountType::System);
        $main = $this->account(AccountType::MainAgent, $system);
        $sub = $this->account(AccountType::SubAgent, $main);
        $branch = $this->account(AccountType::SubBranch, $sub);
        $allowed = CatalogProduct::factory()->create();
        $blocked = CatalogProduct::factory()->create();
        $this->allow($main, $system, [$allowed, $blocked]);
        $this->allow($sub, $main, [$allowed]);
        foreach ([$main, $sub, $branch] as $parent) {
            $point = $this->account(AccountType::Pos, $parent);
            $query = app(CatalogAccess::class)->forAccount($point->id);
            $this->assertSame($parent->id === $main->id ? [$allowed->id, $blocked->id] : [$allowed->id], $query->orderBy('id')->pluck('id')->all());
        }
        $this->asPortalUser($this->userFor($branch));
        $this->getJson('/api/v1/catalog/products')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $allowed->id);
    }

    public function test_main_cannot_grant_category_denied_by_system_or_manage_foreign_network(): void
    {
        $system = $this->account(AccountType::System);
        $main = $this->account(AccountType::MainAgent, $system);
        $sub = $this->account(AccountType::SubAgent, $main);
        $foreign = $this->account(AccountType::MainAgent, $system);
        $blocked = CatalogProduct::factory()->create();
        $allowed = CatalogProduct::factory()->create();
        $this->allow($main, $system, [$allowed]);
        $this->asPortalUser($this->userFor($main));
        $this->putJson('/api/v1/accounts/'.$sub->id.'/categories', $this->payload($sub, [$blocked]))->assertUnprocessable()->assertJsonValidationErrors('product_ids');
        $this->putJson('/api/v1/accounts/'.$foreign->id.'/categories', $this->payload($foreign, []))->assertNotFound();
        $this->getJson('/api/v1/accounts/'.$foreign->id.'/categories')->assertNotFound();
        $this->assertDatabaseCount('catalog_account_rules', 1);
    }

    public function test_lower_agent_cannot_remove_system_decision_on_same_target(): void
    {
        $system = $this->account(AccountType::System);
        $main = $this->account(AccountType::MainAgent, $system);
        $sub = $this->account(AccountType::SubAgent, $main);
        $one = CatalogProduct::factory()->create();
        $two = CatalogProduct::factory()->create();
        $this->allow($main, $system, [$one, $two]);
        $this->allow($sub, $system, [$one]);
        $this->asPortalUser($this->userFor($main));
        $this->putJson('/api/v1/accounts/'.$sub->id.'/categories', $this->payload($sub, [$two]))->assertUnprocessable();
        $this->putJson('/api/v1/accounts/'.$sub->id.'/categories', $this->payload($sub, [$one]))->assertOk();
        $this->assertDatabaseHas('catalog_account_rules', ['target_account_id' => $sub->id, 'authority_account_id' => $system->id]);
    }

    public function test_higher_authority_replaces_lower_target_decision_but_parent_limits_remain_live(): void
    {
        $system = $this->account(AccountType::System);
        $main = $this->account(AccountType::MainAgent, $system);
        $sub = $this->account(AccountType::SubAgent, $main);
        $one = CatalogProduct::factory()->create();
        $two = CatalogProduct::factory()->create();
        $this->allow($main, $system, [$one, $two]);
        $this->allow($sub, $main, [$one]);
        $this->asPortalUser($this->userFor($system));
        $this->putJson('/api/v1/accounts/'.$sub->id.'/categories', $this->payload($sub, [$two]))->assertOk()->assertJsonPath('data.selected_ids.0', $two->id);
        $this->assertDatabaseMissing('catalog_account_rules', ['target_account_id' => $sub->id, 'authority_account_id' => $main->id]);
        $this->putJson('/api/v1/accounts/'.$main->id.'/categories', $this->payload($main, [$one]))->assertOk();
        $this->assertSame([], app(CatalogAccess::class)->forAccount($sub->id)->pluck('id')->all());
    }

    public function test_stale_account_or_catalog_version_cannot_overwrite_assignments(): void
    {
        $system = $this->account(AccountType::System);
        $main = $this->account(AccountType::MainAgent, $system);
        $product = CatalogProduct::factory()->create();
        $this->asPortalUser($this->userFor($system));
        $payload = $this->payload($main, [$product]);
        $payload['catalog_version'] = 999;
        $this->putJson('/api/v1/accounts/'.$main->id.'/categories', $payload)->assertConflict();
        $payload = $this->payload($main, [$product]);
        $payload['version'] = 999;
        $this->putJson('/api/v1/accounts/'.$main->id.'/categories', $payload)->assertConflict();
        $this->assertDatabaseCount('catalog_account_rules', 0);
    }

    public static function networkTypes(): array
    {
        return [[AccountType::MainAgent], [AccountType::SubAgent], [AccountType::SubBranch], [AccountType::Pos]];
    }

    #[DataProvider('networkTypes')]
    public function test_network_account_cannot_modify_global_catalog_even_with_forged_direct_permission(AccountType $type): void
    {
        $system = $this->account(AccountType::System);
        $main = $this->account(AccountType::MainAgent, $system);
        $sub = $this->account(AccountType::SubAgent, $main);
        $branch = $this->account(AccountType::SubBranch, $sub);
        $target = match ($type) {
            AccountType::MainAgent => $main,AccountType::SubAgent => $sub,AccountType::SubBranch => $branch,default => $this->account(AccountType::Pos, $branch)
        };
        $user = $this->userFor($target);
        foreach (['providers.view', 'providers.create'] as $key) {
            DB::table('membership_permissions')->insert(['membership_id' => $user->membership->id, 'permission_id' => DB::table('permissions')->where('name', $key)->value('id'), 'allowed' => true]);
        }
        $this->asPortalUser($user);
        $this->postJson('/api/v1/catalog/providers', ['name' => 'Forged', 'supplier' => 'Recorded', 'connection' => 'API'])->assertForbidden();
        $this->assertDatabaseCount('catalog_providers', 0);
    }

    public function test_audit_failure_rolls_back_category_rule_and_account_version(): void
    {
        $system = $this->account(AccountType::System);
        $main = $this->account(AccountType::MainAgent, $system);
        $one = CatalogProduct::factory()->create();
        $this->asPortalUser($this->userFor($system));
        $this->mock(AuditLogger::class)->shouldReceive('record')->once()->andThrow(new \RuntimeException('Intentional audit failure'));
        $this->putJson('/api/v1/accounts/'.$main->id.'/categories', $this->payload($main, [$one]))->assertInternalServerError();
        $this->assertDatabaseCount('catalog_account_rules', 0);
        $this->assertDatabaseCount('catalog_rule_products', 0);
        $this->assertSame(1, $main->fresh()->version);
    }
}

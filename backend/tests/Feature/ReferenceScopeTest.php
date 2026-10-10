<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\CatalogProvider;
use App\Models\NetworkRepresentative;
use App\Models\OrderSource;
use App\Models\PermissionProfile;
use App\Models\PosType;
use App\Models\User;
use App\Services\ManagementAuthority;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CreatesAccounts;
use Tests\TestCase;

class ReferenceScopeTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    private function employee(Account $employer, Account $root, array $permissions): User
    {
        $this->userFor($employer);
        $profile = PermissionProfile::factory()->create(['account_id' => $employer->id]);
        app(ManagementAuthority::class)->replaceProfilePermissions($profile->id, $permissions);
        $user = User::factory()->create();
        $member = AccountMembership::create(['user_id' => $user->id, 'account_id' => $employer->id, 'role_id' => DB::table('roles')->where('name', 'employee')->value('id'), 'kind' => 'employee', 'permission_profile_id' => $profile->id, 'include_descendants' => true, 'status' => 'active']);
        DB::table('membership_scope_roots')->insert(['membership_id' => $member->id, 'account_id' => $root->id]);

        return $user;
    }

    public function test_missing_authentication_returns_401_for_reference_endpoints(): void
    {
        $this->withHeader('X-Masal-Portal', 'admin')->getJson('/api/v1/reference/sources')->assertUnauthorized();
    }

    public function test_main_cannot_read_mutate_or_export_other_network_representatives_or_sources(): void
    {
        $system = $this->account(AccountType::System);
        $main = $this->account(AccountType::MainAgent, $system);
        $foreign = $this->account(AccountType::MainAgent, $system);
        $rep = NetworkRepresentative::factory()->create(['agent_account_id' => $foreign->id]);
        $source = OrderSource::factory()->create(['network_account_id' => $foreign->id]);
        $this->asPortalUser($this->userFor($main));

        $this->patchJson('/api/v1/reference/representatives/'.$rep->id.'/status', ['version' => 1, 'status' => 'disabled'])->assertNotFound();
        $this->patchJson('/api/v1/reference/sources/'.$source->id.'/status', ['version' => 1, 'status' => 'disabled'])->assertNotFound();
        $this->getJson('/api/v1/reference/representatives/export')->assertOk()->assertJsonPath('data.count', 0);
        $this->getJson('/api/v1/reference/sources')->assertOk()->assertJsonCount(0, 'data');

        $this->assertSame('active', $rep->fresh()->status);
        $this->assertSame('active', $source->fresh()->status);
    }

    public function test_subagent_reads_network_sources_but_cannot_write_network_definition(): void
    {
        $system = $this->account(AccountType::System);
        $main = $this->account(AccountType::MainAgent, $system);
        $sub = $this->account(AccountType::SubAgent, $main);
        $source = OrderSource::factory()->create(['network_account_id' => $main->id]);
        $this->asPortalUser($this->userFor($sub));

        $this->getJson('/api/v1/reference/sources')->assertOk()->assertJsonPath('data.0.id', $source->id);
        $this->patchJson('/api/v1/reference/sources/'.$source->id.'/status', ['version' => 1, 'status' => 'disabled'])->assertForbidden();

        $this->assertSame(1, $source->fresh()->version);
    }

    public function test_system_employee_scope_reads_only_assigned_agents_and_can_manage_assigned_main_sources(): void
    {
        $system = $this->account(AccountType::System);
        $main = $this->account(AccountType::MainAgent, $system);
        $foreign = $this->account(AccountType::MainAgent, $system);
        $selected = NetworkRepresentative::factory()->create(['agent_account_id' => $main->id]);
        NetworkRepresentative::factory()->create(['agent_account_id' => $foreign->id]);
        $provider = CatalogProvider::factory()->create();
        $user = $this->employee($system, $main, ['account.view', 'sources.view', 'sources.create', 'representatives.view']);
        $this->asPortalUser($user);

        $this->getJson('/api/v1/reference/representatives')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $selected->id);
        $this->postJson('/api/v1/reference/sources', ['name' => 'Scoped source', 'provider_id' => $provider->id, 'network_account_id' => $main->id])->assertCreated();
        $this->postJson('/api/v1/reference/sources', ['name' => 'Foreign source', 'provider_id' => $provider->id, 'network_account_id' => $foreign->id])->assertNotFound();

        $this->assertDatabaseCount('order_sources', 1);
    }

    public function test_employee_granted_global_dictionary_permissions_without_system_scope_receives_403(): void
    {
        $system = $this->account(AccountType::System);
        $main = $this->account(AccountType::MainAgent, $system);
        $this->asPortalUser($this->employee($system, $main, ['account.view', 'governorates.view', 'governorates.toggle', 'posTypes.view', 'posTypes.create']));

        $this->getJson('/api/v1/reference/governorates')->assertForbidden();
        $this->postJson('/api/v1/reference/pos-types', ['name' => 'Forbidden'])->assertForbidden();

        $this->assertDatabaseCount('pos_types', 3);
    }

    public function test_governorate_management_is_denied_to_system_employee_even_with_full_system_scope_and_grant(): void
    {
        $system = $this->account(AccountType::System);
        $this->asPortalUser($this->employee($system, $system, ['account.view', 'governorates.view', 'governorates.toggle']));

        $this->getJson('/api/v1/reference/governorates')->assertForbidden();

        $this->assertDatabaseMissing('audit_logs', ['action' => 'governorates.toggle']);
    }

    public static function nonSystemTypes(): array
    {
        return [[AccountType::MainAgent], [AccountType::SubAgent], [AccountType::SubBranch], [AccountType::Pos]];
    }

    #[DataProvider('nonSystemTypes')]
    public function test_non_system_portal_cannot_manage_global_types_even_with_forged_direct_permissions(AccountType $type): void
    {
        $system = $this->account(AccountType::System);
        $main = $this->account(AccountType::MainAgent, $system);
        $sub = $this->account(AccountType::SubAgent, $main);
        $branch = $this->account(AccountType::SubBranch, $sub);
        $target = match ($type) {
            AccountType::MainAgent => $main, AccountType::SubAgent => $sub, AccountType::SubBranch => $branch, default => $this->account(AccountType::Pos, $branch)
        };
        $user = $this->userFor($target);
        foreach (['posTypes.view', 'posTypes.create'] as $key) {
            DB::table('membership_permissions')->insert(['membership_id' => $user->membership->id, 'permission_id' => DB::table('permissions')->where('name', $key)->value('id'), 'allowed' => true]);
        }
        $this->asPortalUser($user);

        $this->postJson('/api/v1/reference/pos-types', ['name' => 'Forbidden'])->assertForbidden();

        $this->assertDatabaseCount('pos_types', 3);
    }

    public function test_pos_profile_saves_type_and_representatives_with_account_version_and_audit(): void
    {
        $system = $this->account(AccountType::System);
        $main = $this->account(AccountType::MainAgent, $system);
        $sub = $this->account(AccountType::SubAgent, $main);
        $pos = $this->account(AccountType::Pos, $sub);
        $rep = NetworkRepresentative::factory()->create(['agent_account_id' => $sub->id]);
        $type = PosType::firstOrFail();
        $this->asPortalUser($this->userFor($main));

        $this->putJson('/api/v1/accounts/'.$pos->id.'/reference-profile', ['version' => 1, 'pos_type_id' => $type->id, 'representative_ids' => [$rep->id]])->assertOk()->assertJsonPath('data.version', 2)->assertJsonPath('data.pos_type_id', $type->id)->assertJsonPath('data.representative_ids.0', $rep->id);

        $this->assertDatabaseHas('pos_reference_profiles', ['account_id' => $pos->id, 'pos_type_id' => $type->id]);
        $this->assertDatabaseHas('pos_representatives', ['account_id' => $pos->id, 'representative_id' => $rep->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'pos.reference-profile', 'subject_account_id' => $pos->id]);
    }

    public function test_pos_profile_rejects_representative_outside_point_parent_branch_even_if_actor_can_view(): void
    {
        $system = $this->account(AccountType::System);
        $main = $this->account(AccountType::MainAgent, $system);
        $sub = $this->account(AccountType::SubAgent, $main);
        $sibling = $this->account(AccountType::SubAgent, $main);
        $pos = $this->account(AccountType::Pos, $sub);
        $rep = NetworkRepresentative::factory()->create(['agent_account_id' => $sibling->id]);
        $this->asPortalUser($this->userFor($main));

        $this->putJson('/api/v1/accounts/'.$pos->id.'/reference-profile', ['version' => 1, 'pos_type_id' => null, 'representative_ids' => [$rep->id]])->assertUnprocessable()->assertJsonValidationErrors('representative_ids');

        $this->assertDatabaseCount('pos_representatives', 0);
        $this->assertSame(1, $pos->fresh()->version);
    }

    public function test_profile_stale_version_and_foreign_account_return_409_and_404_without_writes(): void
    {
        $system = $this->account(AccountType::System);
        $main = $this->account(AccountType::MainAgent, $system);
        $pos = $this->account(AccountType::Pos, $main);
        $foreign = $this->account(AccountType::MainAgent, $system);
        $foreignPos = $this->account(AccountType::Pos, $foreign);
        $this->asPortalUser($this->userFor($main));
        $payload = ['version' => 99, 'pos_type_id' => null, 'representative_ids' => []];

        $this->putJson('/api/v1/accounts/'.$pos->id.'/reference-profile', $payload)->assertConflict();
        $this->putJson('/api/v1/accounts/'.$foreignPos->id.'/reference-profile', $payload)->assertNotFound();
        $this->getJson('/api/v1/accounts/'.$foreignPos->id.'/reference-profile')->assertNotFound();

        $this->assertDatabaseCount('pos_reference_profiles', 0);
    }

    public function test_moving_assigned_representative_to_another_branch_is_rejected_without_changing_binding(): void
    {
        $system = $this->account(AccountType::System);
        $main = $this->account(AccountType::MainAgent, $system);
        $sub = $this->account(AccountType::SubAgent, $main);
        $sibling = $this->account(AccountType::SubAgent, $main);
        $pos = $this->account(AccountType::Pos, $sub);
        $rep = NetworkRepresentative::factory()->create(['agent_account_id' => $sub->id]);
        DB::table('pos_representatives')->insert(['account_id' => $pos->id, 'representative_id' => $rep->id]);
        $this->asPortalUser($this->userFor($main));

        $this->patchJson('/api/v1/reference/representatives/'.$rep->id, ['version' => 1, 'agent_account_id' => $sibling->id, 'name' => 'Moved'])->assertUnprocessable()->assertJsonValidationErrors('agent_account_id');

        $this->assertSame($sub->id, $rep->fresh()->agent_account_id);
        $this->assertSame(1, $rep->fresh()->version);
    }

    public function test_pos_type_and_assignment_changes_require_independent_permissions(): void
    {
        $system = $this->account(AccountType::System);
        $main = $this->account(AccountType::MainAgent, $system);
        $pos = $this->account(AccountType::Pos, $main);
        $type = PosType::firstOrFail();
        $user = $this->userFor($main);
        DB::table('membership_permissions')->insert(['membership_id' => $user->membership->id, 'permission_id' => DB::table('permissions')->where('name', 'pos.type')->value('id'), 'allowed' => false]);
        $this->asPortalUser($user);

        $this->putJson('/api/v1/accounts/'.$pos->id.'/reference-profile', ['version' => 1, 'pos_type_id' => $type->id, 'representative_ids' => []])->assertForbidden();

        $this->assertDatabaseCount('pos_reference_profiles', 0);
    }

    public function test_existing_inactive_assignments_remain_visible_and_can_be_retained_but_not_added(): void
    {
        $system = $this->account(AccountType::System);
        $main = $this->account(AccountType::MainAgent, $system);
        $pos = $this->account(AccountType::Pos, $main);
        $rep = NetworkRepresentative::factory()->create(['agent_account_id' => $main->id, 'status' => 'disabled']);
        $newRep = NetworkRepresentative::factory()->create(['agent_account_id' => $main->id, 'status' => 'disabled']);
        $type = PosType::factory()->create(['status' => 'disabled']);
        DB::table('pos_reference_profiles')->insert(['account_id' => $pos->id, 'pos_type_id' => $type->id]);
        DB::table('pos_representatives')->insert(['account_id' => $pos->id, 'representative_id' => $rep->id]);
        $this->asPortalUser($this->userFor($main));

        $this->getJson('/api/v1/accounts/'.$pos->id.'/reference-profile')->assertOk()->assertJsonPath('data.selected_representatives.0.id', $rep->id)->assertJsonCount(0, 'data.available_representatives');
        $this->putJson('/api/v1/accounts/'.$pos->id.'/reference-profile', ['version' => 1, 'pos_type_id' => $type->id, 'representative_ids' => [$rep->id]])->assertOk()->assertJsonPath('data.version', 2);
        $this->putJson('/api/v1/accounts/'.$pos->id.'/reference-profile', ['version' => 2, 'pos_type_id' => $type->id, 'representative_ids' => [$rep->id, $newRep->id]])->assertUnprocessable()->assertJsonValidationErrors('representative_ids');

        $this->assertDatabaseCount('pos_representatives', 1);
        $this->assertSame(2, $pos->fresh()->version);
    }

    public function test_source_cannot_move_between_networks_and_preserves_old_network_on_rejection(): void
    {
        $system = $this->account(AccountType::System);
        $main = $this->account(AccountType::MainAgent, $system);
        $foreign = $this->account(AccountType::MainAgent, $system);
        $source = OrderSource::factory()->create(['network_account_id' => $main->id]);
        $this->asPortalUser($this->userFor($system));

        $this->patchJson('/api/v1/reference/sources/'.$source->id, ['version' => 1, 'name' => $source->name, 'provider_id' => $source->provider_id, 'network_account_id' => $foreign->id])->assertUnprocessable()->assertJsonValidationErrors('network_account_id');

        $this->assertSame($main->id, $source->fresh()->network_account_id);
        $this->assertSame(1, $source->fresh()->version);
    }

    public function test_options_identifies_writable_main_scope_and_excludes_other_networks(): void
    {
        $system = $this->account(AccountType::System);
        $main = $this->account(AccountType::MainAgent, $system);
        $sub = $this->account(AccountType::SubAgent, $main);
        $this->account(AccountType::MainAgent, $system);
        $this->asPortalUser($this->userFor($sub));

        $this->getJson('/api/v1/reference/options')->assertOk()->assertJsonPath('data.writable_network_ids', [])->assertJsonCount(1, 'data.networks')->assertJsonPath('data.networks.0.id', $main->id)->assertJsonPath('data.agents.0.id', $sub->id);
    }
}

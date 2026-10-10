<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\PermissionProfile;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\ManagementAuthority;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Support\CreatesAccounts;
use Tests\TestCase;

class StaffManagementTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    private function profile(Account $account, array $permissions = ['account.view']): PermissionProfile
    {
        $profile = PermissionProfile::factory()->create(['account_id' => $account->id]);
        app(ManagementAuthority::class)->replaceProfilePermissions($profile->id, $permissions);

        return $profile;
    }

    public function test_dashboard_only_profile_change_keeps_pos_employee_session(): void
    {
        $root = $this->account(AccountType::System);
        $main = $this->account(AccountType::MainAgent, $root);
        $pos = $this->account(AccountType::Pos, $main);
        $actor = $this->userFor($root);
        $this->userFor($pos);
        $profile = $this->profile($pos, ['dashboard.view', 'sell.create', 'sales.view', 'account.view']);
        $employee = $this->employee($pos, $profile, [$pos->id], false);
        $this->asPortalUser($actor);
        $route = '/api/v1/accounts/'.$pos->id.'/permission-profiles/'.$profile->id;
        $this->patchJson($route, ['version' => 1, 'permissions' => ['sell.create', 'sales.view', 'account.view'], 'reason' => 'Hide dashboard'])->assertOk();
        $this->assertSame(1, $employee->fresh()->session_version);
        $this->asPortalUser($employee);
        $this->getJson('/api/v1/auth/me')->assertOk();
        $this->getJson('/api/v1/dashboard/summary')->assertForbidden();
        $this->asPortalUser($actor);
        $this->patchJson($route, ['version' => 2, 'permissions' => ['account.view'], 'reason' => 'Revoke sale'])->assertOk();
        $this->assertSame(2, $employee->fresh()->session_version);
    }

    private function employee(Account $account, PermissionProfile $profile, array $roots, bool $descendants = true): User
    {
        $user = User::factory()->create();
        $member = AccountMembership::create(['account_id' => $account->id, 'user_id' => $user->id,
            'role_id' => DB::table('roles')->where('name', 'employee')->value('id'), 'kind' => 'employee',
            'permission_profile_id' => $profile->id, 'include_descendants' => $descendants, 'status' => 'active', 'version' => 1]);
        foreach ($roots as $root) {
            DB::table('membership_scope_roots')->insert(['membership_id' => $member->id, 'account_id' => $root]);
        }

        return $user;
    }

    private function payload(PermissionProfile $profile, Account $root): array
    {
        return ['name' => 'Employee', 'email' => 'employee@example.test',
            'password' => '  FixturePassword123  ', 'password_confirmation' => '  FixturePassword123  ',
            'permission_profile_id' => $profile->id, 'scope_roots' => [$root->id], 'include_descendants' => false, 'notes' => 'Staff notes'];
    }

    public function test_staff_creation_hashes_original_password_assigns_only_employee_and_returns_no_credentials(): void
    {
        $root = $this->account(AccountType::System);
        $main = $this->account(AccountType::MainAgent, $root);
        $actor = $this->userFor($main);
        $profile = $this->profile($main);
        $this->asPortalUser($actor);
        $response = $this->postJson('/api/v1/accounts/'.$main->id.'/staff', $this->payload($profile, $main))->assertCreated()
            ->assertJsonPath('data.kind', 'employee')->assertJsonPath('data.login', null)->assertJsonPath('data.permission_profile_id', $profile->id)
            ->assertJsonPath('data.scope_roots', [$main->id])->assertJsonMissingPath('data.password');
        $user = User::findOrFail($response->json('data.user_id'));
        $this->assertTrue(Hash::check('  FixturePassword123  ', $user->password));
        $this->assertFalse(Hash::check('FixturePassword123', $user->password));
        $this->assertSame(['account.view'], $user->membership->permissions());
        $this->assertDatabaseHas('audit_logs', ['action' => 'staff.create', 'user_id' => $actor->id, 'subject_account_id' => $main->id]);
        $this->asPortalUser($user);
        $this->getJson('/api/v1/accounts')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $main->id);
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.portal', 'agents');
    }

    public function test_staff_password_can_be_nine_digits_with_optional_letters(): void
    {
        $root = $this->account(AccountType::System);
        $main = $this->account(AccountType::MainAgent, $root);
        $this->asPortalUser($this->userFor($main));
        $payload = $this->payload($this->profile($main), $main);
        $payload['password'] = $payload['password_confirmation'] = '12345678';
        $this->postJson('/api/v1/accounts/'.$main->id.'/staff', $payload)->assertUnprocessable()->assertJsonValidationErrors('password');
        $payload['password'] = $payload['password_confirmation'] = '123456789';
        $response = $this->postJson('/api/v1/accounts/'.$main->id.'/staff', $payload)->assertCreated();
        $this->assertTrue(Hash::check('123456789', User::findOrFail($response->json('data.user_id'))->password));
    }

    public function test_staff_creation_rejects_foreign_roots_profiles_duplicates_and_privilege_keys(): void
    {
        $root = $this->account(AccountType::System);
        $main = $this->account(AccountType::MainAgent, $root);
        $foreign = $this->account(AccountType::MainAgent, $root);
        $actor = $this->userFor($main);
        $this->userFor($foreign);
        $profile = $this->profile($main);
        $foreignProfile = $this->profile($foreign);
        $this->asPortalUser($actor);
        $payload = $this->payload($profile, $main);
        $this->postJson('/api/v1/accounts/'.$main->id.'/staff', array_replace($payload, ['scope_roots' => [$foreign->id]]))->assertUnprocessable()->assertJsonValidationErrors('scope_roots');
        $this->postJson('/api/v1/accounts/'.$main->id.'/staff', array_replace($payload, ['permission_profile_id' => $foreignProfile->id]))->assertNotFound();
        $this->postJson('/api/v1/accounts/'.$foreign->id.'/staff', $payload)->assertNotFound();
        $this->postJson('/api/v1/accounts/'.$main->id.'/staff', $payload + ['kind' => 'owner'])->assertUnprocessable()->assertJsonValidationErrors('kind');
        $this->postJson('/api/v1/accounts/'.$main->id.'/staff', array_replace($payload, ['email' => $actor->email]))->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('account_memberships', 2);
        $this->assertDatabaseCount('membership_scope_roots', 0);
    }

    public function test_profile_grants_require_actor_and_account_ceiling_known_keys_and_view_dependencies(): void
    {
        $root = $this->account(AccountType::System);
        $main = $this->account(AccountType::MainAgent, $root);
        $actor = $this->userFor($main);
        $this->asPortalUser($actor);
        $route = '/api/v1/accounts/'.$main->id.'/permission-profiles';
        $this->postJson($route, ['name' => 'Unknown', 'permissions' => ['account.view', 'wallet.transfer']])->assertUnprocessable()->assertJsonValidationErrors('permissions');
        $this->postJson($route, ['name' => 'Escalate', 'permissions' => ['account.view', 'account.login']])->assertUnprocessable()->assertJsonValidationErrors('permissions');
        $this->postJson($route, ['name' => 'No section view', 'permissions' => ['account.view', 'staff.create']])->assertUnprocessable()->assertJsonValidationErrors('permissions');
        $this->postJson($route, ['name' => 'No account view', 'permissions' => ['staff.view']])->assertUnprocessable()->assertJsonValidationErrors('permissions');
        $this->postJson($route, ['name' => 'Owner forged', 'permissions' => ['account.view'], 'account_id' => $root->id])->assertUnprocessable()->assertJsonValidationErrors('account_id');
        $this->assertDatabaseCount('permission_profiles', 0);
    }

    public function test_profile_name_is_unique_per_account_and_versioned_update_is_atomic(): void
    {
        $root = $this->account(AccountType::System);
        $main = $this->account(AccountType::MainAgent, $root);
        $owner = $this->userFor($main);
        $this->asPortalUser($owner);
        $route = '/api/v1/accounts/'.$main->id.'/permission-profiles';
        $created = $this->postJson($route, ['name' => 'Read Only', 'permissions' => ['account.view']])->assertCreated()->assertJsonPath('data.builtin', false);
        $id = $created->json('data.id');
        $this->postJson($route, ['name' => ' read only ', 'permissions' => ['account.view']])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->patchJson($route.'/'.$id, ['version' => 1, 'name' => 'Changed', 'permissions' => ['account.view', 'staff.view'], 'reason' => 'Update profile'])
            ->assertOk()->assertJsonPath('data.version', 2)->assertJsonPath('data.permissions', ['account.view', 'staff.view']);
        $this->patchJson($route.'/'.$id, ['version' => 1, 'name' => 'Stale', 'reason' => 'Old edit'])->assertConflict();
        $this->assertDatabaseHas('permission_profiles', ['id' => $id, 'name' => 'Changed', 'version' => 2]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'permission_profile.update', 'user_id' => $owner->id]);
    }

    public function test_employee_roots_empty_and_descendants_false_never_expand_scope(): void
    {
        $root = $this->account(AccountType::System);
        $main = $this->account(AccountType::MainAgent, $root);
        $sub = $this->account(AccountType::SubAgent, $main);
        $pos = $this->account(AccountType::Pos, $sub);
        $this->userFor($main);
        $profile = $this->profile($main, ['account.view', 'account.create']);
        $employee = $this->employee($main, $profile, [$sub->id], false);
        $this->asPortalUser($employee);
        $this->getJson('/api/v1/accounts')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $sub->id);
        $this->getJson('/api/v1/accounts/'.$pos->id)->assertNotFound();
        $this->postJson('/api/v1/accounts', [])->assertForbidden();
        DB::table('membership_scope_roots')->where('membership_id', $employee->membership->id)->delete();
        $this->getJson('/api/v1/accounts')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_staff_and_profile_pagination_excludes_members_outside_actor_full_scope(): void
    {
        $root = $this->account(AccountType::System);
        $main = $this->account(AccountType::MainAgent, $root);
        $sub = $this->account(AccountType::SubAgent, $main);
        $owner = $this->userFor($main);
        $ownProfile = $this->profile($main, ['account.view', 'staff.view', 'permission_profile.view']);
        $foreignScopeProfile = $this->profile($main);
        $actor = $this->employee($main, $ownProfile, [$main->id], false);
        $hidden = $this->employee($main, $foreignScopeProfile, [$sub->id], false);
        $this->asPortalUser($actor);
        $response = $this->getJson('/api/v1/accounts/'.$main->id.'/staff')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 2);
        $this->assertNotContains($hidden->membership->id, array_column($response->json('data'), 'id'));
        $this->getJson('/api/v1/accounts/'.$main->id.'/permission-profiles')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $ownProfile->id);
        $this->assertTrue($owner->fresh()->isOperational());
    }

    public function test_staff_update_cannot_change_account_role_password_or_owner_and_revokes_session(): void
    {
        $root = $this->account(AccountType::System);
        $main = $this->account(AccountType::MainAgent, $root);
        $owner = $this->userFor($main);
        $profile = $this->profile($main);
        $employee = $this->employee($main, $profile, [$main->id], false);
        $member = $employee->membership;
        $this->asPortalUser($owner);
        $route = '/api/v1/accounts/'.$main->id.'/staff/';
        $this->patchJson($route.$owner->membership->id, ['version' => 1, 'name' => 'Owner changed', 'reason' => 'Protected'])->assertForbidden();
        $this->patchJson($route.$member->id, ['version' => 1, 'name' => 'Employee Changed', 'email' => 'changed@example.test', 'notes' => 'New notes', 'reason' => 'Update staff'])
            ->assertOk()->assertJsonPath('data.name', 'Employee Changed')->assertJsonPath('data.version', 2);
        $this->assertSame(2, $employee->fresh()->session_version);
        $this->patchJson($route.$member->id, ['version' => 2, 'password' => 'ChangedPassword123', 'reason' => 'Wrong action'])->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->patchJson($route.$member->id, ['version' => 2, 'account_id' => $root->id, 'reason' => 'Move employer'])->assertUnprocessable()->assertJsonValidationErrors('account_id');
        $this->patchJson($route.$member->id, ['version' => 1, 'name' => 'Stale', 'reason' => 'Old version'])->assertConflict();
        $this->assertSame('Employee Changed', $employee->fresh()->name);
        $this->asPortalUser($employee);
        $this->withSession(['masal.session_version' => 1])->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_staff_scope_reassignment_needs_separate_scope_permission(): void
    {
        $root = $this->account(AccountType::System);
        $main = $this->account(AccountType::MainAgent, $root);
        $owner = $this->userFor($main);
        $profile = $this->profile($main);
        $employee = $this->employee($main, $profile, [$main->id], false);
        DB::table('membership_permissions')->insert(['membership_id' => $owner->membership->id, 'permission_id' => DB::table('permissions')->where('name', 'staff.scope')->value('id'), 'allowed' => false]);
        $this->asPortalUser($owner);
        $this->patchJson('/api/v1/accounts/'.$main->id.'/staff/'.$employee->membership->id, ['version' => 1, 'scope_roots' => [$main->id], 'include_descendants' => true, 'reason' => 'Expand'])
            ->assertForbidden();
        $this->assertFalse($employee->fresh()->membership->include_descendants);
    }

    public function test_profile_reassignment_requires_role_permission_without_blocking_name_edit(): void
    {
        $root = $this->account(AccountType::System);
        $owner = $this->userFor($root);
        $oldProfile = $this->profile($root);
        $newProfile = $this->profile($root, ['account.view', 'staff.view']);
        $employee = $this->employee($root, $oldProfile, [$root->id]);
        DB::table('membership_permissions')->insert(['membership_id' => $owner->membership->id, 'permission_id' => DB::table('permissions')->where('name', 'staff.role')->value('id'), 'allowed' => false]);
        $this->asPortalUser($owner);
        $route = '/api/v1/accounts/'.$root->id.'/staff/'.$employee->membership->id;
        $this->patchJson($route, ['version' => 1, 'permission_profile_id' => $newProfile->id, 'reason' => 'Role change'])->assertForbidden();
        $this->patchJson($route, ['version' => 1, 'name' => 'Allowed Name', 'reason' => 'Name change'])->assertOk();
        $this->assertSame($oldProfile->id, $employee->fresh()->membership->permission_profile_id);
        $this->assertSame('Allowed Name', $employee->fresh()->name);
    }

    public function test_pos_employee_stays_in_pos_portal_and_never_reads_other_point(): void
    {
        $root = $this->account(AccountType::System);
        $main = $this->account(AccountType::MainAgent, $root);
        $pos = $this->account(AccountType::Pos, $main);
        $other = $this->account(AccountType::Pos, $main);
        $owner = $this->userFor($pos);
        $profile = $this->profile($pos, ['account.view', 'staff.view']);
        $employee = $this->employee($pos, $profile, [$pos->id]);
        $this->asPortalUser($employee);
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.portal', 'pos');
        $this->getJson('/api/v1/accounts')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $pos->id);
        $this->getJson('/api/v1/accounts/'.$other->id.'/staff')->assertNotFound();
        $this->withHeader('X-Masal-Portal', 'agents')->getJson('/api/v1/auth/me')->assertForbidden();
        $this->assertTrue($owner->fresh()->isOperational());
    }

    public function test_profile_audit_failure_rolls_back_permissions_version_and_revocation(): void
    {
        $root = $this->account(AccountType::System);
        $owner = $this->userFor($root);
        $profile = $this->profile($root);
        $employee = $this->employee($root, $profile, [$root->id]);
        $this->asPortalUser($owner);
        $this->mock(AuditLogger::class)->shouldReceive('record')->once()->andThrow(new \RuntimeException('Simulated audit failure'));
        $this->patchJson('/api/v1/accounts/'.$root->id.'/permission-profiles/'.$profile->id, ['version' => 1, 'permissions' => ['account.view', 'staff.view'], 'reason' => 'Failure'])->assertStatus(500);
        $this->assertSame(['account.view'], $profile->fresh()->permissions());
        $this->assertSame(1, $profile->fresh()->version);
        $this->assertSame(1, $employee->fresh()->session_version);
    }

    public function test_staff_and_profiles_cannot_modify_actor_own_profile_or_own_membership(): void
    {
        $root = $this->account(AccountType::System);
        $owner = $this->userFor($root);
        $profile = $this->profile($root, ['account.view', 'staff.view', 'staff.update', 'staff.toggle', 'permission_profile.view', 'permission_profile.update', 'permission_profile.toggle', 'permission_profile.delete']);
        $actor = $this->employee($root, $profile, [$root->id]);
        $this->asPortalUser($actor);
        $profileRoute = '/api/v1/accounts/'.$root->id.'/permission-profiles/'.$profile->id;
        $this->patchJson($profileRoute, ['version' => 1, 'name' => 'Own profile', 'reason' => 'Self edit'])->assertForbidden();
        $this->patchJson($profileRoute.'/status', ['version' => 1, 'status' => 'disabled'])->assertForbidden();
        $this->deleteJson($profileRoute, ['version' => 1])->assertForbidden();
        $this->patchJson('/api/v1/accounts/'.$root->id.'/staff/'.$actor->membership->id.'/status', ['version' => 1, 'status' => 'disabled', 'reason' => 'Self stop'])->assertForbidden();
        $this->patchJson('/api/v1/accounts/'.$root->id.'/staff/'.$owner->membership->id.'/status', ['version' => 1, 'status' => 'disabled', 'reason' => 'Owner stop'])->assertForbidden();
        $this->assertSame('active', $actor->fresh()->membership->status);
        $this->assertSame('active', $profile->fresh()->status);
    }

    public function test_disabled_profile_blocks_existing_session_and_reenable_does_not_restore_old_session(): void
    {
        $root = $this->account(AccountType::System);
        $owner = $this->userFor($root);
        $profile = $this->profile($root);
        $employee = $this->employee($root, $profile, [$root->id]);
        $this->asPortalUser($owner);
        $route = '/api/v1/accounts/'.$root->id.'/permission-profiles/'.$profile->id;
        $this->patchJson($route.'/status', ['version' => 1, 'status' => 'disabled'])->assertOk()->assertJsonPath('data.status', 'disabled');
        $this->asPortalUser($employee);
        $this->getJson('/api/v1/auth/me')->assertForbidden();
        $this->asPortalUser($owner);
        $this->patchJson($route.'/status', ['version' => 2, 'status' => 'active'])->assertOk();
        $this->asPortalUser($employee);
        $this->withSession(['masal.session_version' => 1])->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_linked_profile_delete_conflicts_and_unlinked_profile_delete_preserves_audit(): void
    {
        $root = $this->account(AccountType::System);
        $owner = $this->userFor($root);
        $linked = $this->profile($root);
        $unlinked = $this->profile($root);
        $this->employee($root, $linked, [$root->id]);
        $this->asPortalUser($owner);
        $route = '/api/v1/accounts/'.$root->id.'/permission-profiles/';
        $this->deleteJson($route.$linked->id, ['version' => 1])->assertConflict();
        $this->deleteJson($route.$unlinked->id, ['version' => 1])->assertNoContent();
        $this->assertDatabaseMissing('permission_profiles', ['id' => $unlinked->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'permission_profile.delete', 'user_id' => $owner->id]);
    }

    public function test_profile_affecting_any_user_outside_actor_scope_cannot_be_changed(): void
    {
        $root = $this->account(AccountType::System);
        $main = $this->account(AccountType::MainAgent, $root);
        $sub = $this->account(AccountType::SubAgent, $main);
        $this->userFor($main);
        $ownProfile = $this->profile($main, ['account.view', 'permission_profile.view', 'permission_profile.update']);
        $shared = $this->profile($main);
        $actor = $this->employee($main, $ownProfile, [$main->id], false);
        $this->employee($main, $shared, [$main->id], false);
        $this->employee($main, $shared, [$sub->id], false);
        $this->asPortalUser($actor);
        $this->patchJson('/api/v1/accounts/'.$main->id.'/permission-profiles/'.$shared->id, ['version' => 1, 'name' => 'Shared Changed', 'reason' => 'Out of scope'])
            ->assertUnprocessable()->assertJsonValidationErrors('scope_roots');
        $this->assertSame(1, $shared->fresh()->version);
    }

    public function test_system_employee_cannot_change_network_login_even_with_forged_capability(): void
    {
        $root = $this->account(AccountType::System);
        $main = $this->account(AccountType::MainAgent, $root);
        $this->userFor($root);
        $this->userFor($main);
        $profile = $this->profile($root, ['account.view', 'account.login']);
        $actor = $this->employee($root, $profile, [$root->id]);
        $this->asPortalUser($actor);
        $this->patchJson('/api/v1/accounts/'.$main->id.'/login', ['version' => 1, 'login' => 'forged@example.test', 'reason' => 'Privilege escalation'])->assertForbidden();
    }

    public function test_account_permission_delta_preserves_locked_rights_but_rejects_locked_changes_and_new_grants(): void
    {
        $root = $this->account(AccountType::System);
        $main = $this->account(AccountType::MainAgent, $root);
        $sub = $this->account(AccountType::SubAgent, $main);
        $this->userFor($main);
        $targetOwner = $this->userFor($sub);
        $profile = $this->profile($main, ['account.view', 'account.permissions', 'pos.device']);
        $actor = $this->employee($main, $profile, [$main->id]);
        $before = $targetOwner->membership->permissions();
        $desired = array_values(array_diff($before, ['pos.device']));
        $this->asPortalUser($actor);
        $route = '/api/v1/accounts/'.$sub->id.'/permissions';
        $this->patchJson($route, ['version' => 1, 'permissions' => $desired, 'reason' => 'Change delegated device key'])->assertOk();
        $this->assertContains('account.attachments.view', $targetOwner->fresh()->membership->permissions());
        $this->assertNotContains('pos.device', $targetOwner->fresh()->membership->permissions());
        $this->patchJson($route, ['version' => 2, 'permissions' => array_values(array_diff($desired, ['account.attachments.view'])), 'reason' => 'Remove locked key'])
            ->assertUnprocessable()->assertJsonValidationErrors('permissions');
        $this->patchJson($route, ['version' => 2, 'permissions' => [...$desired, 'account.login'], 'reason' => 'Grant higher privilege'])
            ->assertUnprocessable()->assertJsonValidationErrors('permissions');
        $this->assertSame(2, $sub->fresh()->version);
    }

    public function test_profile_metadata_edit_preserves_locked_rights_without_authorizing_their_change(): void
    {
        $root = $this->account(AccountType::System);
        $this->userFor($root);
        $actorProfile = $this->profile($root, ['account.view', 'permission_profile.view', 'permission_profile.update']);
        $target = $this->profile($root, ['account.view', 'staff.view']);
        $actor = $this->employee($root, $actorProfile, [$root->id]);
        $this->employee($root, $target, [$root->id]);
        $this->asPortalUser($actor);
        $route = '/api/v1/accounts/'.$root->id.'/permission-profiles/'.$target->id;
        $this->patchJson($route, ['version' => 1, 'name' => 'Metadata Only', 'reason' => 'Rename'])
            ->assertOk()->assertJsonPath('data.permissions', ['account.view', 'staff.view']);
        $this->patchJson($route, ['version' => 2, 'permissions' => ['account.view'], 'reason' => 'Revoke locked view'])->assertUnprocessable()->assertJsonValidationErrors('permissions');
        $this->patchJson($route, ['version' => 2, 'permissions' => ['account.view', 'staff.view', 'staff.toggle'], 'reason' => 'New grant'])->assertUnprocessable()->assertJsonValidationErrors('permissions');
        $this->assertSame('Metadata Only', $target->fresh()->name);
        $this->assertSame(2, $target->fresh()->version);
    }

    public function test_profile_change_is_immediate_and_never_grants_more_than_current_owner_ceiling(): void
    {
        $root = $this->account(AccountType::System);
        $main = $this->account(AccountType::MainAgent, $root);
        $admin = $this->userFor($root);
        $owner = $this->userFor($main);
        $profile = $this->profile($main, ['account.view', 'staff.view']);
        $employee = $this->employee($main, $profile, [$main->id]);
        $this->assertContains('staff.view', $employee->membership->permissions());
        $this->asPortalUser($owner);
        $this->patchJson('/api/v1/accounts/'.$main->id.'/permission-profiles/'.$profile->id, ['version' => 1, 'permissions' => ['account.view'], 'reason' => 'Reduce'])
            ->assertOk();
        $this->assertSame(['account.view'], $employee->fresh()->membership->permissions());
        $this->asPortalUser($admin);
        $this->patchJson('/api/v1/accounts/'.$main->id.'/permissions', ['version' => 1, 'permissions' => [], 'reason' => 'Remove owner authority'])->assertOk();
        $this->assertSame([], $employee->fresh()->membership->permissions());
    }

    public function test_audit_failure_rolls_back_employee_credentials_scope_and_membership(): void
    {
        $root = $this->account(AccountType::System);
        $owner = $this->userFor($root);
        $profile = $this->profile($root);
        $this->asPortalUser($owner);
        $this->mock(AuditLogger::class)->shouldReceive('record')->once()->andThrow(new \RuntimeException('Simulated audit failure'));
        $this->postJson('/api/v1/accounts/'.$root->id.'/staff', $this->payload($profile, $root))->assertStatus(500);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('account_memberships', 1);
        $this->assertDatabaseCount('membership_scope_roots', 0);
    }

    public function test_staff_status_is_versioned_denies_disabled_profile_and_preserves_owner(): void
    {
        $root = $this->account(AccountType::System);
        $owner = $this->userFor($root);
        $profile = $this->profile($root);
        $employee = $this->employee($root, $profile, [$root->id]);
        $this->asPortalUser($owner);
        $route = '/api/v1/accounts/'.$root->id.'/staff/'.$employee->membership->id.'/status';
        $this->patchJson($route, ['version' => 1, 'status' => 'disabled', 'reason' => 'Stop staff'])->assertOk()->assertJsonPath('data.version', 2);
        $this->patchJson($route, ['version' => 1, 'status' => 'active', 'reason' => 'Stale'])->assertConflict();
        $profile->update(['status' => 'disabled']);
        $this->patchJson($route, ['version' => 2, 'status' => 'active', 'reason' => 'Wrong profile'])->assertUnprocessable();
        $this->assertSame('disabled', $employee->fresh()->membership->status);
        $this->assertTrue($owner->fresh()->isOperational());
    }

    public function test_staff_and_profile_listing_queries_remain_bounded_as_page_size_grows(): void
    {
        $root = $this->account(AccountType::System);
        $owner = $this->userFor($root);
        for ($index = 0; $index < 30; $index++) {
            $profile = $this->profile($root);
            $this->employee($root, $profile, [$root->id], false);
        }
        $this->asPortalUser($owner);
        DB::enableQueryLog();
        foreach (['staff', 'permission-profiles'] as $endpoint) {
            DB::flushQueryLog();
            $this->getJson('/api/v1/accounts/'.$root->id.'/'.$endpoint.'?per_page=1')->assertOk()->assertJsonCount(1, 'data');
            $smallPageQueries = count(DB::getQueryLog());
            DB::flushQueryLog();
            $large = $this->getJson('/api/v1/accounts/'.$root->id.'/'.$endpoint.'?per_page=25')->assertOk()->assertJsonCount(25, 'data');
            $this->assertLessThanOrEqual($smallPageQueries + 2, count(DB::getQueryLog()), $endpoint.' must batch relation data.');
            if ($endpoint === 'staff') {
                $large->assertJsonPath('data.1.scope_accounts.0.id', $root->id)->assertJsonPath('data.1.scope_accounts.0.name', $root->name);
            } else {
                $large->assertJsonPath('data.0.members_count', 1)->assertJsonPath('data.0.permissions', ['account.view']);
            }
        }
        DB::disableQueryLog();
    }
}

<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CreatesAccounts;
use Tests\TestCase;

class AccountManagementTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    private function business(Account $account): array
    {
        return ['name' => $account->name, 'city' => 'بغداد', 'phone' => '07700000000']
            + ($account->type === AccountType::Pos ? ['owner_name' => 'Office Owner', 'address' => 'Office Address', 'serial' => 'TEST-'.$account->id, 'device_model' => 'Recorded Model', 'app_version' => '1.0'] : ['color' => '#4F46E5']);
    }

    public function test_hiding_dashboard_keeps_pos_session_and_rechecks_current_permissions(): void
    {
        $root = $this->account(AccountType::System);
        $main = $this->account(AccountType::MainAgent, $root);
        $pos = $this->account(AccountType::Pos, $main);
        $owner = $this->userFor($pos);
        $permissions = $owner->membership->permissions();
        $this->assertContains('dashboard.view', $permissions);
        $this->assertContains('sell.create', $permissions);
        $actor = $this->userFor($root);
        $this->asPortalUser($actor);
        $remaining = array_values(array_diff($permissions, ['dashboard.view']));
        $this->patchJson('/api/v1/accounts/'.$pos->id.'/permissions', ['version' => 1, 'permissions' => $remaining, 'reason' => 'Hide dashboard'])->assertOk();
        $this->assertSame(1, $owner->fresh()->session_version);
        $this->asPortalUser($owner);
        $response = $this->getJson('/api/v1/auth/me')->assertOk();
        $this->assertNotContains('dashboard.view', $response->json('data.permissions'));
        $this->assertContains('sell.create', $response->json('data.permissions'));
        $this->getJson('/api/v1/dashboard/summary')->assertForbidden();
        $this->asPortalUser($actor);
        $this->patchJson('/api/v1/accounts/'.$pos->id.'/permissions', ['version' => 2, 'permissions' => array_values(array_diff($remaining, ['sell.create'])), 'reason' => 'Remove sale permission'])->assertOk();
        $this->assertSame(2, $owner->fresh()->session_version);
        $this->asPortalUser($owner);
        $this->withSession(['masal.session_version' => 1])->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_original_single_email_identifier_creates_account_without_extra_user_fields(): void
    {
        $root = $this->account(AccountType::System);
        $this->asPortalUser($this->userFor($root));
        $response = $this->postJson('/api/v1/accounts', ['name' => 'Original Agent', 'type' => 'main_agent', 'parent_id' => $root->id,
            'city' => 'بغداد', 'phone' => '07700000000', 'color' => '#4F46E5',
            'user' => ['login' => ' NEW@example.test ', 'password' => 'FixturePassword123', 'password_confirmation' => 'FixturePassword123']])
            ->assertCreated()->assertJsonPath('data.account.version', 1)->assertJsonPath('data.user.name', 'Original Agent')->assertJsonPath('data.user.email', 'new@example.test')->assertJsonPath('data.user.login', null);
        $user = User::findOrFail($response->json('data.user.id'));
        $this->assertTrue(Hash::check('FixturePassword123', $user->password));
        $this->assertSame('بغداد', $user->membership->account->city);
    }

    public function test_business_create_requires_original_fields_and_active_city_on_server(): void
    {
        $root = $this->account(AccountType::System);
        $this->asPortalUser($this->userFor($root));
        $user = ['email' => 'new@example.test', 'password' => 'FixturePassword123', 'password_confirmation' => 'FixturePassword123'];
        $this->postJson('/api/v1/accounts', ['name' => 'Missing Details', 'type' => 'main_agent', 'parent_id' => $root->id, 'user' => $user])
            ->assertUnprocessable()->assertJsonValidationErrors(['city', 'phone', 'color']);
        $this->postJson('/api/v1/accounts', ['name' => 'Unknown City', 'type' => 'main_agent', 'parent_id' => $root->id, 'city' => 'Unknown', 'phone' => '07700000000', 'color' => '#4F46E5', 'user' => $user])
            ->assertUnprocessable()->assertJsonValidationErrors('city');
        $this->assertDatabaseCount('accounts', 1);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_account_update_is_scoped_versioned_and_syncs_owner_name_without_password_change(): void
    {
        $root = $this->account(AccountType::System);
        $main = $this->account(AccountType::MainAgent, $root);
        $owner = $this->userFor($main);
        $originalHash = $owner->password;
        $actor = $this->userFor($root);
        $this->asPortalUser($actor);
        $this->patchJson('/api/v1/accounts/'.$main->id, $this->business($main) + ['version' => 1, 'notes' => 'Original notes'])
            ->assertOk()->assertJsonPath('data.version', 2);
        $this->patchJson('/api/v1/accounts/'.$main->id, ['version' => 2, 'name' => 'Updated Agent'])->assertOk()->assertJsonPath('data.owner_user.name', 'Updated Agent');
        $this->assertSame($originalHash, $owner->fresh()->password);
        $this->assertDatabaseHas('audit_logs', ['action' => 'account.update', 'subject_account_id' => $main->id, 'user_id' => $actor->id]);
        $this->patchJson('/api/v1/accounts/'.$main->id, ['version' => 2, 'name' => 'Stale'])->assertConflict();
        $this->assertSame('Updated Agent', $main->fresh()->name);
    }

    public function test_profile_and_owner_login_are_saved_together_for_agents_and_points(): void
    {
        $root = $this->account(AccountType::System);
        $main = $this->account(AccountType::MainAgent, $root);
        $pos = $this->account(AccountType::Pos, $main);
        $this->asPortalUser($this->userFor($root));
        foreach ([$main, $pos] as $target) {
            $owner = $this->userFor($target);
            $target->update($this->business($target));
            $oldHash = $owner->password;
            $login = 'updated'.$target->id;
            $this->patchJson('/api/v1/accounts/'.$target->id, ['version' => 1, 'name' => 'Updated '.$target->id, 'login' => ' '.strtoupper($login).' ', 'reason' => 'Correct login'])
                ->assertOk()->assertJsonPath('data.version', 2)->assertJsonPath('data.owner_user.login', $login)->assertJsonPath('data.owner_user.email', null);
            $this->assertSame('Updated '.$target->id, $owner->fresh()->name);
            $this->assertSame($oldHash, $owner->fresh()->password);
            $this->assertSame(2, $owner->fresh()->session_version);
            foreach (['account.update', 'account.login'] as $action) {
                $this->assertDatabaseHas('audit_logs', ['action' => $action, 'subject_account_id' => $target->id]);
            }
        }
    }

    public function test_failed_login_update_rolls_back_profile_and_requires_reason(): void
    {
        $root = $this->account(AccountType::System);
        $main = $this->account(AccountType::MainAgent, $root);
        $main->update($this->business($main));
        $owner = $this->userFor($main);
        $actor = $this->userFor($root);
        $originalName = $main->name;
        $this->asPortalUser($actor);
        $this->patchJson('/api/v1/accounts/'.$main->id, ['version' => 1, 'name' => 'Must not persist', 'login' => $actor->email, 'reason' => 'Duplicate'])
            ->assertUnprocessable()->assertJsonValidationErrors('login');
        $this->patchJson('/api/v1/accounts/'.$main->id, ['version' => 1, 'name' => 'Must not persist', 'login' => 'newidentifier'])
            ->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->assertSame($originalName, $main->fresh()->name);
        $this->assertSame(1, $main->fresh()->version);
        $this->assertSame($owner->email, $owner->fresh()->email);
        $this->assertSame(1, $owner->fresh()->session_version);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'account.update', 'subject_account_id' => $main->id]);
    }

    public function test_agent_cannot_bypass_login_authority_through_profile_update(): void
    {
        $root = $this->account(AccountType::System);
        $main = $this->account(AccountType::MainAgent, $root);
        $pos = $this->account(AccountType::Pos, $main);
        $pos->update($this->business($pos));
        $owner = $this->userFor($pos);
        $this->asPortalUser($this->userFor($main));
        $this->patchJson('/api/v1/accounts/'.$pos->id, ['version' => 1, 'name' => 'Forbidden', 'login' => 'forgedlogin', 'reason' => 'Bypass'])
            ->assertForbidden();
        $this->assertSame(1, $pos->fresh()->version);
        $this->assertNotSame('Forbidden', $pos->fresh()->name);
        $this->assertSame($owner->email, $owner->fresh()->email);
    }

    public static function forbiddenFields(): array
    {
        return ['type' => ['type'], 'parent' => ['parent_id'], 'status' => ['status'], 'permissions' => ['permissions'], 'password' => ['password'], 'role' => ['role_id']];
    }

    #[DataProvider('forbiddenFields')]
    public function test_general_patch_rejects_privilege_and_identity_keys(string $field): void
    {
        $root = $this->account(AccountType::System);
        $main = $this->account(AccountType::MainAgent, $root);
        $this->asPortalUser($this->userFor($root));
        $this->patchJson('/api/v1/accounts/'.$main->id, ['version' => 1, $field => 'forged'])->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertSame(1, $main->fresh()->version);
    }

    public function test_agent_cannot_mutate_self_ancestor_or_other_network(): void
    {
        $root = $this->account(AccountType::System);
        $main = $this->account(AccountType::MainAgent, $root);
        $foreign = $this->account(AccountType::MainAgent, $root);
        $this->asPortalUser($this->userFor($main));
        $payload = ['version' => 1, 'status' => 'disabled', 'reason' => 'Test status'];
        $this->patchJson('/api/v1/accounts/'.$main->id.'/status', $payload)->assertForbidden();
        $this->patchJson('/api/v1/accounts/'.$root->id.'/status', $payload)->assertNotFound();
        $this->patchJson('/api/v1/accounts/'.$foreign->id.'/status', $payload)->assertNotFound();
        $this->patchJson('/api/v1/accounts/'.$foreign->id, ['version' => 1, 'name' => 'Foreign'])->assertNotFound();
        $this->assertSame('active', $main->fresh()->status);
        $this->assertSame('active', $foreign->fresh()->status);
    }

    public function test_account_filters_parent_and_counts_only_expose_own_network(): void
    {
        $root = $this->account(AccountType::System);
        $main = $this->account(AccountType::MainAgent, $root);
        $sub = $this->account(AccountType::SubAgent, $main);
        $pos = $this->account(AccountType::Pos, $sub);
        $foreign = $this->account(AccountType::MainAgent, $root);
        $this->account(AccountType::Pos, $foreign);
        $this->asPortalUser($this->userFor($main));
        $this->getJson('/api/v1/accounts?kind=agents&parent_id='.$main->id)->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $sub->id)->assertJsonPath('data.0.network_pos_count', 1)
            ->assertJsonPath('data.0.parent.id', $main->id)->assertJsonPath('meta.summary.pos', 1);
        $this->getJson('/api/v1/accounts/'.$main->id)->assertOk()->assertJsonPath('data.parent', null)->assertJsonPath('data.children_count', 1);
        $this->getJson('/api/v1/accounts?parent_id='.$foreign->id)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/accounts?kind=pos&q='.$pos->name)->assertOk()->assertJsonCount(1, 'data')->assertJsonMissingPath('data.0.owner_user');
    }

    public function test_search_matches_original_city_owner_and_scoped_parent_without_leaking_other_networks(): void
    {
        $root = $this->account(AccountType::System);
        $root->update(['name' => 'Private Ancestor Name']);
        $main = $this->account(AccountType::MainAgent, $root);
        $main->update(['name' => 'Scoped Parent Agent', 'city' => 'كربلاء']);
        $pos = $this->account(AccountType::Pos, $main);
        $pos->update(['city' => 'بغداد', 'owner_name' => 'Distinct Office Owner']);
        $foreign = $this->account(AccountType::MainAgent, $root);
        $foreign->update(['name' => 'Foreign Agent Name']);
        $foreignPoint = $this->account(AccountType::Pos, $foreign);
        $foreignPoint->update(['city' => 'بغداد', 'owner_name' => 'Distinct Office Owner']);
        $this->asPortalUser($this->userFor($main));
        $this->getJson('/api/v1/accounts?kind=agents&q='.urlencode('كربلاء'))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $main->id);
        foreach (['بغداد', 'Distinct Office Owner', 'Scoped Parent Agent'] as $term) {
            $this->getJson('/api/v1/accounts?kind=pos&q='.urlencode($term))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $pos->id);
        }
        foreach (['Private Ancestor Name', 'Foreign Agent Name'] as $term) {
            $this->getJson('/api/v1/accounts?kind=pos&q='.urlencode($term))->assertOk()->assertJsonCount(0, 'data');
        }
    }

    public function test_pos_device_location_permissions_and_serial_uniqueness_are_enforced(): void
    {
        $root = $this->account(AccountType::System);
        $main = $this->account(AccountType::MainAgent, $root);
        $pos = $this->account(AccountType::Pos, $main);
        $pos->update($this->business($pos));
        $other = $this->account(AccountType::Pos, $main);
        $other->update($this->business($other));
        $actor = $this->userFor($main);
        DB::table('membership_permissions')->insert(['membership_id' => $actor->membership->id, 'permission_id' => DB::table('permissions')->where('name', 'pos.device')->value('id'), 'allowed' => false]);
        $this->asPortalUser($actor);
        $this->patchJson('/api/v1/accounts/'.$pos->id, ['version' => 1, 'serial' => 'New Device'])->assertForbidden();
        $this->patchJson('/api/v1/accounts/'.$pos->id, ['version' => 1, 'device_lock_enabled' => false])->assertForbidden();
        $this->patchJson('/api/v1/accounts/'.$pos->id, ['version' => 1, 'notes' => str_repeat('x', 2001)])->assertUnprocessable()->assertJsonValidationErrors('notes');
        DB::table('membership_permissions')->delete();
        $this->patchJson('/api/v1/accounts/'.$pos->id, ['version' => 1, 'serial' => mb_strtolower($other->serial)])->assertUnprocessable()->assertJsonValidationErrors('serial');
        $this->assertSame(1, $pos->fresh()->version);
        $this->patchJson('/api/v1/accounts/'.$pos->id, ['version' => 1, 'device_lock_enabled' => false])->assertOk()->assertJsonPath('data.device_lock_enabled', false);
        $this->assertSame('TEST-'.$pos->id, $pos->fresh()->serial);
        $this->patchJson('/api/v1/accounts/'.$pos->id, ['version' => 2, 'device_lock_enabled' => true])->assertOk()->assertJsonPath('data.device_lock_enabled', true);
        $this->patchJson('/api/v1/accounts/'.$pos->id, ['version' => 3, 'device_lock_enabled' => false, 'serial' => ''])->assertOk()->assertJsonPath('data.serial', null);
        $this->patchJson('/api/v1/accounts/'.$pos->id, ['version' => 4, 'device_lock_enabled' => true])->assertUnprocessable()->assertJsonValidationErrors('serial');
        $this->assertFalse($pos->fresh()->device_lock_enabled);
    }

    public function test_disabling_parent_revokes_descendant_sessions_and_reactivation_preserves_independent_stop(): void
    {
        $root = $this->account(AccountType::System);
        $main = $this->account(AccountType::MainAgent, $root);
        $pos = $this->account(AccountType::Pos, $main);
        $posUser = $this->userFor($pos);
        $pos->update(['status' => 'disabled']);
        $admin = $this->userFor($root);
        $this->asPortalUser($admin);
        $this->patchJson('/api/v1/accounts/'.$main->id.'/status', ['version' => 1, 'status' => 'disabled', 'reason' => 'Pause network'])->assertOk();
        $this->patchJson('/api/v1/accounts/'.$main->id.'/status', ['version' => 2, 'status' => 'active', 'reason' => 'Resume parent'])->assertOk();
        $this->assertSame('disabled', $pos->fresh()->status);
        $this->assertSame(2, $posUser->fresh()->session_version);
        $this->asPortalUser($posUser);
        $this->withSession(['masal.session_version' => 1])->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_lower_authority_cannot_remove_higher_deny_but_higher_can_replace_target_lower_rule(): void
    {
        $root = $this->account(AccountType::System);
        $main = $this->account(AccountType::MainAgent, $root);
        $pos = $this->account(AccountType::Pos, $main);
        $admin = $this->userFor($root);
        $agent = $this->userFor($main);
        $posUser = $this->userFor($pos);
        $grants = ['account.view', 'staff.view'];
        $this->asPortalUser($admin);
        $this->patchJson('/api/v1/accounts/'.$pos->id.'/permissions', ['version' => 1, 'permissions' => [], 'reason' => 'System deny'])->assertOk();
        $this->asPortalUser($agent);
        $this->patchJson('/api/v1/accounts/'.$pos->id.'/permissions', ['version' => 2, 'permissions' => $grants, 'reason' => 'Lower restore'])->assertUnprocessable()->assertJsonValidationErrors('permissions');
        $this->assertNotContains('account.view', $posUser->fresh()->membership->permissions());
        $this->asPortalUser($admin);
        $this->patchJson('/api/v1/accounts/'.$pos->id.'/permissions', ['version' => 2, 'permissions' => $grants, 'reason' => 'System restore'])->assertOk();
        $this->asPortalUser($agent);
        $this->patchJson('/api/v1/accounts/'.$pos->id.'/permissions', ['version' => 3, 'permissions' => [], 'reason' => 'Agent deny'])->assertOk();
        $this->assertNotContains('account.view', $posUser->fresh()->membership->permissions());
        $this->asPortalUser($admin);
        $this->patchJson('/api/v1/accounts/'.$pos->id.'/permissions', ['version' => 4, 'permissions' => $grants, 'reason' => 'Explicit replace lower'])->assertOk();
        $this->assertContains('account.view', $posUser->fresh()->membership->permissions());
    }

    public function test_restoring_parent_permission_does_not_remove_independent_child_deny(): void
    {
        $root = $this->account(AccountType::System);
        $main = $this->account(AccountType::MainAgent, $root);
        $pos = $this->account(AccountType::Pos, $main);
        $admin = $this->userFor($root);
        $agent = $this->userFor($main);
        $posUser = $this->userFor($pos);
        $agentGrants = $agent->membership->permissions();
        $this->asPortalUser($agent);
        $this->patchJson('/api/v1/accounts/'.$pos->id.'/permissions', ['version' => 1, 'permissions' => [], 'reason' => 'Independent child deny'])->assertOk();
        $this->asPortalUser($admin);
        $this->patchJson('/api/v1/accounts/'.$main->id.'/permissions', ['version' => 1, 'permissions' => array_values(array_diff($agentGrants, ['pos.device'])), 'reason' => 'Restrict parent'])->assertOk();
        $this->patchJson('/api/v1/accounts/'.$main->id.'/permissions', ['version' => 2, 'permissions' => $agentGrants, 'reason' => 'Restore parent'])->assertOk();
        $this->assertNotContains('account.view', $posUser->fresh()->membership->permissions());
    }

    public function test_editing_another_permission_does_not_take_ownership_of_unchanged_lower_deny(): void
    {
        $root = $this->account(AccountType::System);
        $main = $this->account(AccountType::MainAgent, $root);
        $pos = $this->account(AccountType::Pos, $main);
        $admin = $this->userFor($root);
        $agent = $this->userFor($main);
        $posUser = $this->userFor($pos);
        $initial = $posUser->membership->permissions();
        $this->asPortalUser($agent);
        $this->patchJson('/api/v1/accounts/'.$pos->id.'/permissions', ['version' => 1, 'permissions' => array_values(array_diff($initial, ['account.attachments.view'])), 'reason' => 'Agent attachment deny'])->assertOk();
        $this->asPortalUser($admin);
        $otherChange = array_values(array_diff($initial, ['account.attachments.view', 'staff.toggle']));
        $this->patchJson('/api/v1/accounts/'.$pos->id.'/permissions', ['version' => 2, 'permissions' => $otherChange, 'reason' => 'System changes only staff toggle'])->assertOk();
        $attachmentId = DB::table('permissions')->where('name', 'account.attachments.view')->value('id');
        $this->assertDatabaseMissing('account_permission_rules', ['target_account_id' => $pos->id, 'authority_account_id' => $root->id, 'permission_id' => $attachmentId]);
        $this->asPortalUser($agent);
        $this->patchJson('/api/v1/accounts/'.$pos->id.'/permissions', ['version' => 3, 'permissions' => [...$otherChange, 'account.attachments.view'], 'reason' => 'Restore own decision'])->assertOk();
        $this->assertContains('account.attachments.view', $posUser->fresh()->membership->permissions());
        $this->assertNotContains('staff.toggle', $posUser->fresh()->membership->permissions());
    }

    public function test_login_change_is_admin_owner_only_scoped_and_revokes_previous_session(): void
    {
        $root = $this->account(AccountType::System);
        $main = $this->account(AccountType::MainAgent, $root);
        $sub = $this->account(AccountType::SubAgent, $main);
        $owner = $this->userFor($sub);
        $oldHash = $owner->password;
        $agent = $this->userFor($main);
        $this->asPortalUser($agent);
        $this->patchJson('/api/v1/accounts/'.$sub->id.'/login', ['version' => 1, 'login' => 'new@example.test', 'reason' => 'Change login'])->assertForbidden();
        $this->asPortalUser($this->userFor($root));
        $this->patchJson('/api/v1/accounts/'.$sub->id.'/login', ['version' => 1, 'login' => 'NEW@example.test', 'reason' => 'Change login'])->assertOk()->assertJsonPath('data.owner_user.email', 'new@example.test');
        $this->assertNull($owner->fresh()->login);
        $this->assertSame($oldHash, $owner->fresh()->password);
        $this->assertSame(2, $owner->fresh()->session_version);
    }

    public function test_switching_email_identifier_to_username_revokes_alias_and_accepts_only_new_identifier(): void
    {
        $root = $this->account(AccountType::System);
        $main = $this->account(AccountType::MainAgent, $root);
        $owner = $this->userFor($main);
        $oldEmail = $owner->email;
        $this->asPortalUser($this->userFor($root));
        $this->patchJson('/api/v1/accounts/'.$main->id.'/login', ['version' => 1, 'login' => 'single.username', 'reason' => 'Switch identifier'])
            ->assertOk()->assertJsonPath('data.owner_user.login', 'single.username')->assertJsonPath('data.owner_user.email', null);
        $this->postJson('/api/v1/auth/logout')->assertNoContent();
        Auth::forgetGuards();
        $this->withHeader('X-Masal-Portal', 'agents')->postJson('/api/v1/auth/login', ['login' => $oldEmail, 'password' => 'password'])->assertUnprocessable()->assertJsonValidationErrors('login');
        $this->postJson('/api/v1/auth/login', ['login' => 'single.username', 'password' => 'password'])->assertOk()->assertJsonPath('data.user.id', $owner->id);
        $this->assertSame(2, $owner->fresh()->session_version);
    }

    public function test_permission_limits_and_ancestor_picker_respect_higher_target_denial_and_scope(): void
    {
        $root = $this->account(AccountType::System);
        $main = $this->account(AccountType::MainAgent, $root);
        $pos = $this->account(AccountType::Pos, $main);
        $foreign = $this->account(AccountType::MainAgent, $root);
        $this->account(AccountType::Pos, $foreign);
        $admin = $this->userFor($root);
        $agent = $this->userFor($main);
        $pointOwner = $this->userFor($pos);
        $allowed = array_values(array_diff($pointOwner->membership->permissions(), ['account.attachments.view']));
        $this->asPortalUser($admin);
        $this->patchJson('/api/v1/accounts/'.$pos->id.'/permissions', ['version' => 1, 'permissions' => $allowed, 'reason' => 'Higher target limit'])->assertOk();
        $this->asPortalUser($agent);
        $response = $this->getJson('/api/v1/accounts/'.$pos->id)->assertOk();
        $this->assertNotContains('account.attachments.view', $response->json('data.grantable_permissions'));
        $this->assertNotContains('account.create', $response->json('data.grantable_permissions'));
        $this->assertContains('account.view', $response->json('data.profile_grantable_permissions'));
        $this->getJson('/api/v1/accounts?ancestor_id='.$main->id)->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/accounts?ancestor_id='.$foreign->id)->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_owner_can_change_own_name_only_with_membership_version(): void
    {
        $user = $this->userFor($this->account(AccountType::System));
        $hash = $user->password;
        $this->asPortalUser($user);
        $this->patchJson('/api/v1/auth/profile', ['version' => 1, 'name' => 'Updated Manager'])->assertOk()->assertJsonPath('data.user.name', 'Updated Manager')->assertJsonPath('data.membership.version', 2);
        $this->patchJson('/api/v1/auth/profile', ['version' => 1, 'name' => 'Stale'])->assertConflict();
        $this->patchJson('/api/v1/auth/profile', ['version' => 2, 'name' => 'Forged', 'email' => 'changed@example.test'])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertSame($hash, $user->fresh()->password);
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.profile', 'user_id' => $user->id]);
    }

    public function test_audit_failure_rolls_back_edit_and_session_revocation(): void
    {
        $root = $this->account(AccountType::System);
        $main = $this->account(AccountType::MainAgent, $root);
        $owner = $this->userFor($main);
        $this->asPortalUser($this->userFor($root));
        $this->mock(AuditLogger::class)->shouldReceive('record')->once()->andThrow(new \RuntimeException('Simulated audit failure'));
        $this->patchJson('/api/v1/accounts/'.$main->id.'/status', ['version' => 1, 'status' => 'disabled', 'reason' => 'Pause'])->assertStatus(500);
        $this->assertSame('active', $main->fresh()->status);
        $this->assertSame(1, $owner->fresh()->session_version);
        $this->assertSame(1, $main->fresh()->version);
    }

    public static function protectedRoutes(): array
    {
        return ['update' => ['PATCH', '/accounts/999'], 'status' => ['PATCH', '/accounts/999/status'], 'permissions' => ['PATCH', '/accounts/999/permissions'],
            'login' => ['PATCH', '/accounts/999/login'], 'staff read' => ['GET', '/accounts/999/staff'], 'staff create' => ['POST', '/accounts/999/staff'],
            'staff edit' => ['PATCH', '/accounts/999/staff/999'], 'staff status' => ['PATCH', '/accounts/999/staff/999/status'],
            'profile read' => ['GET', '/accounts/999/permission-profiles'], 'profile create' => ['POST', '/accounts/999/permission-profiles'],
            'profile edit' => ['PATCH', '/accounts/999/permission-profiles/999'], 'profile status' => ['PATCH', '/accounts/999/permission-profiles/999/status'],
            'profile delete' => ['DELETE', '/accounts/999/permission-profiles/999'], 'self name' => ['PATCH', '/auth/profile']];
    }

    #[DataProvider('protectedRoutes')]
    public function test_all_new_management_routes_require_authentication(string $method, string $path): void
    {
        $this->withHeader('X-Masal-Portal', 'admin')->json($method, '/api/v1'.$path)->assertUnauthorized();
    }
}

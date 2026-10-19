<?php

namespace Tests\Feature;

use App\Models\AccountMembership;
use App\Models\Company\CompanyProfile;
use App\Models\Operations\AccountTimePolicy;
use App\Models\PermissionProfile;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Backups\BackupAccess;
use App\Services\ManagementAuthority;
use App\Services\Operations\AccountTimeGuard;
use App\Services\PermissionCatalog;
use App\Services\ReferenceAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\CreatesSupport;
use Tests\TestCase;

class DelegatedSystemPermissionsTest extends TestCase
{
    use CreatesSupport, RefreshDatabase;

    private function createStaff(array $f, int $profileId): User
    {
        $response = $this->postJson('/api/v1/accounts/'.$f['system']->id.'/staff', ['name' => 'Delegated employee', 'email' => fake()->unique()->safeEmail(), 'password' => 'FixturePassword123', 'password_confirmation' => 'FixturePassword123', 'permission_profile_id' => $profileId, 'scope_roots' => [$f['system']->id], 'include_descendants' => true])->assertCreated();

        return User::findOrFail($response->json('data.user_id'));
    }

    public function test_all_catalog_permissions_can_be_delegated_to_system_staff_while_owner_and_self_stay_protected(): void
    {
        Http::preventStrayRequests();
        $f = $this->supportFixture();
        $this->asPortalUser($f['admin']);
        $keys = array_keys(PermissionCatalog::LABELS);
        $profile = $this->postJson('/api/v1/accounts/'.$f['system']->id.'/permission-profiles', ['name' => 'Full delegated administration', 'permissions' => $keys])->assertCreated()->assertJsonCount(174, 'data.permissions');
        $employee = $this->createStaff($f, $profile->json('data.id'));
        $this->assertEqualsCanonicalizing($keys, $employee->membership->permissions());
        $this->asPortalUser($employee);
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.membership.kind', 'employee')->assertJsonPath('data.capabilities.system_scope', true);
        $this->getJson('/api/v1/company/app-config?manage=1')->assertOk()->assertJsonStructure(['data' => ['config' => ['name', 'printers']]]);
        app(BackupAccess::class)->require($employee, 'backup.restore');
        app(ReferenceAccess::class)->require($employee, 'governorates.toggle');
        $this->patchJson('/api/v1/accounts/'.$f['system']->id.'/staff/'.$f['admin']->membership->id.'/status', ['version' => 1, 'status' => 'disabled', 'reason' => 'Protected owner'])->assertForbidden();
        $this->patchJson('/api/v1/accounts/'.$f['system']->id.'/permission-profiles/'.$profile->json('data.id'), ['version' => 1, 'name' => 'Self promotion', 'reason' => 'Protected own role'])->assertForbidden();
        $this->assertDatabaseCount('finance_transactions', 0);
        $this->assertDatabaseCount('finance_entries', 0);
        Http::assertNothingSent();
    }

    public function test_printer_only_profile_does_not_imply_account_access_and_returns_only_manageable_fields(): void
    {
        $f = $this->supportFixture();
        $this->asPortalUser($f['admin']);
        $profile = $this->postJson('/api/v1/accounts/'.$f['system']->id.'/permission-profiles', ['name' => 'Printers only', 'permissions' => ['printers.manage']])->assertCreated();
        $employee = $this->createStaff($f, $profile->json('data.id'));
        $this->asPortalUser($employee);
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.permissions', ['printers.manage'])->assertJsonPath('data.capabilities.printers_manage', true)->assertJsonPath('data.capabilities.app_settings', false);
        $this->getJson('/api/v1/accounts')->assertForbidden();
        $this->getJson('/api/v1/accounts/'.$f['system']->id)->assertForbidden();
        $data = $this->getJson('/api/v1/company/app-config?manage=1')->assertOk()->assertJsonMissingPath('data.config.name')->assertJsonMissingPath('data.config.terms')->json('data');
        $data['config']['printers'][0]['name'] = 'Authorized printer';
        $saved = $this->putJson('/api/v1/company/app-config', $data)->assertOk()->assertJsonMissingPath('data.config.name')->json('data');
        $this->putJson('/api/v1/company/app-config', ['version' => $saved['version'], 'config' => ['name' => 'دنانير']])->assertForbidden();
        $this->assertSame('Authorized printer', CompanyProfile::findOrFail(1)->content['app_config']['printers'][0]['name']);
        $this->getJson('/api/v1/company/app-config')->assertOk()->assertJsonPath('data.config.name', 'دنانير');
    }

    public function test_settings_only_partial_save_preserves_printers_and_rejects_even_unchanged_ungranted_fields(): void
    {
        $f = $this->supportFixture();
        $employee = $this->supportEmployee($f['system'], $f['system'], ['app.settings']);
        $this->asPortalUser($employee);
        $printers = $this->getJson('/api/v1/company/app-config')->assertOk()->json('data.config.printers');
        $data = $this->getJson('/api/v1/company/app-config?manage=1')->assertOk()->assertJsonMissingPath('data.config.printers')->json('data');
        $this->putJson('/api/v1/company/app-config', ['version' => $data['version'], 'config' => ['about' => 'Authorized description']])->assertOk()->assertJsonPath('data.config.about', 'Authorized description')->assertJsonMissingPath('data.config.printers');
        $this->putJson('/api/v1/company/app-config', ['version' => 2, 'config' => ['printers' => $printers]])->assertForbidden();
        $this->assertSame($printers, CompanyProfile::findOrFail(1)->content['app_config']['printers']);
        $this->getJson('/api/v1/accounts')->assertForbidden();
    }

    public function test_global_configuration_requires_system_scope_and_does_not_name_missing_permissions(): void
    {
        $f = $this->supportFixture();
        foreach ([$this->supportEmployee($f['system'], $f['main'], ['printers.manage']), $f['agent']] as $actor) {
            if ($actor->id === $f['agent']->id) {
                $this->supportGrant($actor, 'printers.manage');
            }
            $this->asPortalUser($actor);
            $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.capabilities.printers_manage', false);
            $response = $this->getJson('/api/v1/company/app-config?manage=1')->assertForbidden();
            $this->assertStringNotContainsString('printers.manage', $response->getContent());
            $this->assertStringNotContainsString('app.settings', $response->getContent());
        }
    }

    public function test_catalog_and_stronger_profile_metadata_do_not_disclose_unowned_keys_or_allow_mutation(): void
    {
        $f = $this->supportFixture();
        $actorKeys = ['permission_profile.view', 'permission_profile.update', 'permission_profile.toggle', 'permission_profile.delete'];
        $actor = $this->supportEmployee($f['system'], $f['system'], $actorKeys);
        $stronger = PermissionProfile::factory()->create(['account_id' => $f['system']->id]);
        app(ManagementAuthority::class)->replaceProfilePermissions($stronger->id, ['app.settings', 'printers.manage']);
        $this->asPortalUser($actor);
        $catalog = $this->getJson('/api/v1/permission-catalog?account_id='.$f['system']->id)->assertOk();
        $this->assertEqualsCanonicalizing($actorKeys, array_column($catalog->json('data'), 'key'));
        $this->assertStringNotContainsString('printers.manage', $catalog->getContent());
        $rows = collect($this->getJson('/api/v1/accounts/'.$f['system']->id.'/permission-profiles')->assertOk()->json('data'))->keyBy('id');
        $this->assertSame([], $rows[$stronger->id]['permissions']);
        $this->assertSame(['update' => false, 'toggle' => false, 'delete' => false, 'assign' => false], $rows[$stronger->id]['abilities']);
        $this->patchJson('/api/v1/accounts/'.$f['system']->id.'/permission-profiles/'.$stronger->id, ['version' => 1, 'name' => 'Forbidden edit', 'reason' => 'Above actor'])->assertForbidden();
        $this->assertSame(1, $stronger->fresh()->version);
        $this->getJson('/api/v1/accounts')->assertForbidden();
    }

    public function test_staff_only_assignment_and_scope_picker_require_only_their_own_explicit_permissions(): void
    {
        $f = $this->supportFixture();
        $actor = $this->supportEmployee($f['system'], $f['system'], ['staff.view', 'staff.create', 'staff.role', 'staff.scope']);
        $weak = PermissionProfile::factory()->create(['account_id' => $f['system']->id]);
        $strong = PermissionProfile::factory()->create(['account_id' => $f['system']->id]);
        app(ManagementAuthority::class)->replaceProfilePermissions($weak->id, ['staff.view']);
        app(ManagementAuthority::class)->replaceProfilePermissions($strong->id, ['backup.restore', 'backup.view']);
        $this->asPortalUser($actor);
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.capabilities.management_account.id', $f['system']->id)->assertJsonPath('data.capabilities.management_account.type', 'system');
        $rows = $this->getJson('/api/v1/accounts/'.$f['system']->id.'/permission-profiles?assignable=1')->assertOk()->json('data');
        $this->assertContains($weak->id, array_column($rows, 'id'));
        $this->assertNotContains($strong->id, array_column($rows, 'id'));
        $this->getJson('/api/v1/accounts/'.$f['system']->id.'/permission-profiles')->assertForbidden();
        $this->getJson('/api/v1/accounts/'.$f['system']->id.'/staff')->assertOk();
        $this->getJson('/api/v1/accounts')->assertForbidden();
        $this->getJson('/api/v1/account-lookup?context=staff_scope&ancestor_id='.$f['main']->id)->assertOk()->assertJsonMissingPath('data.0.phone')->assertJsonMissingPath('data.0.city');
        $this->getJson('/api/v1/account-lookup')->assertForbidden();
    }

    public function test_withdrawing_owner_permission_takes_effect_next_request_and_employee_cannot_regrant_it(): void
    {
        $f = $this->supportFixture();
        $employee = $this->supportEmployee($f['system'], $f['system'], ['printers.manage', 'permission_profile.view', 'permission_profile.create']);
        $this->asPortalUser($employee);
        $this->getJson('/api/v1/company/app-config?manage=1')->assertOk();
        $this->supportGrant($f['admin'], 'printers.manage', false);
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.capabilities.printers_manage', false);
        $this->getJson('/api/v1/company/app-config?manage=1')->assertForbidden();
        $this->postJson('/api/v1/accounts/'.$f['system']->id.'/permission-profiles', ['name' => 'Regrant unavailable permission', 'permissions' => ['printers.manage']])->assertUnprocessable();
        $this->assertNotContains('printers.manage', $this->getJson('/api/v1/permission-catalog')->assertOk()->json('meta.grantable_permissions'));
    }

    public function test_employee_created_owner_never_exceeds_creator_and_does_not_post_money(): void
    {
        $f = $this->supportFixture();
        $actor = $this->supportEmployee($f['system'], $f['system'], ['account.create']);
        $this->asPortalUser($actor);
        $created = $this->postJson('/api/v1/accounts', ['name' => 'Bounded new agent', 'type' => 'main_agent', 'parent_id' => $f['system']->id, 'city' => 'بغداد', 'phone' => '07712345678', 'color' => '#112233', 'user' => ['email' => 'bounded-agent@example.test', 'password' => 'FixturePassword123', 'password_confirmation' => 'FixturePassword123']])->assertCreated();
        $owner = AccountMembership::where('account_id', $created->json('data.account.id'))->where('kind', 'owner')->firstOrFail();
        $this->assertSame([], array_values(array_diff($owner->permissions(), $actor->membership->permissions())));
        $this->assertSame('owner', $owner->kind);
        $this->assertDatabaseCount('finance_transactions', 0);
        $this->assertDatabaseCount('finance_entries', 0);
        $this->getJson('/api/v1/accounts')->assertForbidden();
    }

    public function test_employee_cannot_take_over_stronger_owner_staff_or_system_owner(): void
    {
        $f = $this->supportFixture();
        $actor = $this->supportEmployee($f['system'], $f['system'], ['account.login', 'account.password_reset', 'staff.view', 'staff.update', 'staff.password_reset']);
        $strong = $this->supportEmployee($f['system'], $f['system'], ['app.settings']);
        $this->asPortalUser($actor);
        $before = $f['agent']->email;
        $staff = collect($this->getJson('/api/v1/accounts/'.$f['system']->id.'/staff')->assertOk()->json('data'))->keyBy('user_id');
        $this->assertArrayNotHasKey($f['admin']->id, $staff->all());
        foreach ([$actor->id, $strong->id] as $id) {
            $this->assertSame(['update' => false, 'toggle' => false, 'password_reset' => false], $staff[$id]['abilities']);
        }
        $this->patchJson('/api/v1/accounts/'.$f['main']->id.'/login', ['version' => 1, 'login' => 'takeover@example.test', 'reason' => 'Above actor'])->assertForbidden();
        $this->postJson('/api/v1/accounts/'.$f['main']->id.'/password-reset', ['version' => 1, 'reason' => 'Above actor'])->assertForbidden();
        $this->patchJson('/api/v1/accounts/'.$f['system']->id.'/staff/'.$strong->membership->id, ['version' => 1, 'email' => 'takeover-staff@example.test', 'reason' => 'Above actor'])->assertForbidden();
        $this->assertSame($before, $f['agent']->fresh()->email);
        $this->assertNull($f['agent']->fresh()->password_change_required_at);
        $full = $this->supportEmployee($f['system'], $f['system'], array_keys(PermissionCatalog::LABELS));
        $this->asPortalUser($full);
        $this->patchJson('/api/v1/accounts/'.$f['system']->id.'/staff/'.$f['admin']->membership->id, ['version' => 1, 'email' => 'protected-staff@example.test', 'reason' => 'Protected owner'])->assertForbidden();
        $this->postJson('/api/v1/accounts/'.$f['system']->id.'/staff/'.$f['admin']->membership->id.'/password-reset', ['version' => 1, 'reason' => 'Protected owner'])->assertForbidden();
        $this->patchJson('/api/v1/accounts/'.$f['system']->id.'/login', ['version' => 1, 'login' => 'protected@example.test', 'reason' => 'Protected owner'])->assertForbidden();
        $this->postJson('/api/v1/accounts/'.$f['system']->id.'/password-reset', ['version' => 1, 'reason' => 'Protected owner'])->assertForbidden();
    }

    public function test_finance_view_only_does_not_grant_account_views(): void
    {
        $f = $this->supportFixture();
        $employee = $this->supportEmployee($f['main'], $f['main'], ['wallets.view']);
        $this->asPortalUser($employee);
        $this->getJson('/api/v1/finance/wallets')->assertOk();
        $this->getJson('/api/v1/accounts')->assertForbidden();
        $this->getJson('/api/v1/accounts/'.$f['main']->id)->assertForbidden();
        $this->assertDatabaseCount('finance_transactions', 0);
    }

    public function test_full_system_employee_can_manage_weaker_owner_login_and_reset_but_not_the_system_owner(): void
    {
        $f = $this->supportFixture();
        $employee = $this->supportEmployee($f['system'], $f['system'], array_keys(PermissionCatalog::LABELS));
        $this->asPortalUser($employee);
        $this->patchJson('/api/v1/accounts/'.$f['main']->id.'/login', ['version' => 1, 'login' => 'delegated-change@example.test', 'reason' => 'Authorized login change'])->assertOk()->assertJsonPath('data.owner_user.email', 'delegated-change@example.test');
        $this->postJson('/api/v1/accounts/'.$f['main']->id.'/password-reset', ['version' => 2, 'reason' => 'Authorized password reset'])->assertOk();
        $this->assertNotNull($f['agent']->fresh()->password_change_required_at);
        $this->assertDatabaseCount('finance_transactions', 0);
    }

    public function test_created_account_and_its_ceiling_denials_roll_back_if_creation_audit_fails(): void
    {
        $f = $this->supportFixture();
        $actor = $this->supportEmployee($f['system'], $f['system'], ['account.create']);
        $counts = collect(['accounts', 'users', 'account_permission_rules', 'account_memberships'])->mapWithKeys(fn ($table) => [$table => DB::table($table)->count()]);
        $this->asPortalUser($actor);
        $this->mock(AuditLogger::class)->shouldReceive('record')->once()->andThrow(new \RuntimeException('Simulated creation audit failure'));
        $this->postJson('/api/v1/accounts', ['name' => 'Rolled back agent', 'type' => 'main_agent', 'parent_id' => $f['system']->id, 'city' => 'بغداد', 'phone' => '07712345678', 'color' => '#112233', 'user' => ['email' => 'rollback-agent@example.test', 'password' => 'FixturePassword123', 'password_confirmation' => 'FixturePassword123']])->assertStatus(500);
        foreach ($counts as $table => $count) {
            $this->assertDatabaseCount($table, $count);
        }
        $this->assertDatabaseCount('finance_transactions', 0);
    }

    public function test_delegated_clock_list_and_edits_hide_self_stronger_staff_and_broader_existing_scopes(): void
    {
        $f = $this->supportFixture();
        $actor = $this->supportEmployee($f['system'], $f['main'], ['security.view', 'security.time', 'staff.view']);
        $inside = $this->supportEmployee($f['main'], $f['main'], ['staff.view']);
        $broader = $this->supportEmployee($f['main'], $f['main'], ['staff.view']);
        $stronger = $this->supportEmployee($f['main'], $f['main'], ['wallets.view']);
        $outside = $this->supportEmployee($f['foreign'], $f['foreign'], ['staff.view']);
        AccountMembership::whereIn('user_id', [$actor->id, $inside->id, $stronger->id])->update(['include_descendants' => false]);
        $clock = AccountTimePolicy::factory()->create(['user_id' => $broader->id, 'policy' => AccountTimeGuard::emptyPolicy()]);
        $this->asPortalUser($actor->fresh());
        $this->getJson('/api/v1/operations/account-times')->assertOk()->assertJsonCount(1, 'data.users')->assertJsonPath('data.users.0.id', $inside->id);
        foreach ([$actor->id, $broader->id, $stronger->id, $outside->id, $f['admin']->id] as $id) {
            $this->getJson('/api/v1/operations/account-times/'.$id)->assertNotFound();
            $this->putJson('/api/v1/operations/account-times/'.$id, ['version' => 1, 'policy' => AccountTimeGuard::emptyPolicy(), 'idempotency_key' => 'forbidden-clock-'.$id])->assertNotFound();
        }
        $this->assertSame($clock->policy, $clock->fresh()->policy);
        $this->getJson('/api/v1/accounts')->assertForbidden();
    }

    public function test_pos_history_cursor_works_with_sales_view_without_account_view(): void
    {
        $f = $this->supportFixture();
        $employee = $this->supportEmployee($f['pos'], $f['pos'], ['sales.view']);
        $this->asPortalUser($employee);
        $this->getJson('/api/v1/digital/history?pagination=cursor&per_page=10')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.has_more', false);
        $this->getJson('/api/v1/accounts')->assertForbidden();
    }

    public function test_whole_database_backup_requires_full_scope_including_archived_accounts(): void
    {
        $f = $this->supportFixture();
        $actor = $this->supportEmployee($f['system'], $f['system'], ['backup.view']);
        AccountMembership::where('user_id', $actor->id)->update(['include_descendants' => false]);
        $f['foreign']->update(['archived_at' => now()]);
        foreach (['main', 'sub', 'branch', 'pos'] as $key) {
            DB::table('membership_scope_roots')->insert(['membership_id' => $actor->membership->id, 'account_id' => $f[$key]->id]);
        }
        $this->asPortalUser($actor->fresh());
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.capabilities.system_scope', true)->assertJsonPath('data.capabilities.backup_access', false);
        $response = $this->getJson('/api/v1/backups')->assertForbidden();
        $this->assertStringNotContainsString('backup.view', $response->getContent());
        AccountMembership::where('user_id', $actor->id)->update(['include_descendants' => true]);
        app(BackupAccess::class)->require($actor->fresh(), 'backup.view');
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.capabilities.backup_access', true);
        $this->assertDatabaseCount('finance_transactions', 0);
    }

    public function test_report_and_dashboard_only_lookup_is_scoped_and_returns_no_account_details(): void
    {
        $f = $this->supportFixture();
        foreach (['reports.view', 'dashboard.view'] as $permission) {
            $actor = $this->supportEmployee($f['system'], $f['main'], [$permission]);
            $this->asPortalUser($actor);
            $rows = $this->getJson('/api/v1/account-lookup?context=reports')->assertOk()->json('data');
            $this->assertContains($f['main']->id, array_column($rows, 'id'));
            $this->assertNotContains($f['foreign']->id, array_column($rows, 'id'));
            foreach ($rows as $row) {
                $this->assertEqualsCanonicalizing(['id', 'name', 'type', 'parent_id', 'parent_name'], array_keys($row));
            }
            $this->postJson('/api/v1/account-lookup', ['context' => 'reports', 'lookup_ids' => [$f['foreign']->id]])->assertOk()->assertJsonCount(0, 'data');
            $this->getJson('/api/v1/accounts')->assertForbidden();
            $this->getJson('/api/v1/account-lookup')->assertForbidden();
        }
        $this->asPortalUser($this->supportEmployee($f['system'], $f['main'], ['printers.manage']));
        $this->getJson('/api/v1/account-lookup?context=reports')->assertForbidden();
    }
}

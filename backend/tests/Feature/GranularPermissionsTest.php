<?php

namespace Tests\Feature;

use App\Models\Company\CompanyProfile;
use App\Models\Operations\DirectStop;
use App\Services\ManagementAuthority;
use App\Services\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Support\CreatesSupport;
use Tests\TestCase;

class GranularPermissionsTest extends TestCase
{
    use CreatesSupport, RefreshDatabase;

    public function test_admin_receives_every_catalog_permission_and_metadata_is_authoritative(): void
    {
        $f = $this->supportFixture();
        $this->assertEqualsCanonicalizing(array_keys(PermissionCatalog::LABELS), $f['admin']->membership->permissions());
        $this->asPortalUser($f['admin']);
        $rows = collect($this->getJson('/api/v1/permission-catalog')->assertOk()->json('data'))->keyBy('key');
        $this->assertCount(174, $rows);
        $this->assertSame(['digital.view', 'digital.receipt', 'data.pin'], $rows['digital.print']['dependencies']);
        $this->assertSame(['backup.view'], $rows['backup.restore']['dependencies']);
        $this->assertSame(['security.view'], $rows['security.policies']['dependencies']);
        $this->assertTrue($rows['backup.restore']['employee_allowed']);
        $this->assertTrue($rows['integrations.credentials']['employee_allowed']);
    }

    #[TestWith(['app'])]
    #[TestWith(['login'])]
    #[TestWith(['sales'])]
    #[TestWith(['printing'])]
    #[TestWith(['import'])]
    public function test_each_stop_requires_its_own_permission_instead_of_print_policies(string $action): void
    {
        $f = $this->supportFixture();
        $employee = $this->supportEmployee($f['system'], $f['main'], ['account.view', 'security.view', 'security.policies']);
        $this->asPortalUser($employee);
        $input = ['scope' => 'custom', 'account_ids' => [$f['pos']->id], 'actions' => [$action], 'reason' => 'Authorized operational review', 'idempotency_key' => 'granular-stop-'.$action];
        $this->postJson('/api/v1/operations/security/stops', $input)->assertForbidden();
        app(ManagementAuthority::class)->replaceProfilePermissions($employee->membership->permission_profile_id, ['account.view', 'security.view', 'security.'.$action]);
        $employee->unsetRelation('membership');
        $this->asPortalUser($employee);
        $this->postJson('/api/v1/operations/security/stops', $input)->assertOk();
        $this->assertDatabaseCount('operation_stops', 1);
    }

    public function test_partial_direct_change_preserves_other_stops_and_rejects_unauthorized_fields(): void
    {
        $f = $this->supportFixture();
        DirectStop::factory()->create(['account_id' => $f['sub']->id, 'stops' => ['sales' => true, 'printing' => true], 'version' => 1]);
        $employee = $this->supportEmployee($f['system'], $f['main'], ['account.view', 'security.view', 'security.printing']);
        $this->asPortalUser($employee);
        $path = '/api/v1/operations/security/accounts/'.$f['sub']->id;
        $this->putJson($path, ['stops' => ['sales' => false], 'version' => 1, 'idempotency_key' => 'deny-sales-clear'])->assertForbidden();
        $this->putJson($path, ['stops' => ['printing' => false], 'version' => 1, 'idempotency_key' => 'allow-print-clear'])->assertOk();
        $this->assertSame(['sales' => true, 'printing' => false], DirectStop::where('account_id', $f['sub']->id)->firstOrFail()->stops);
    }

    public function test_multi_action_resume_requires_all_actions(): void
    {
        $f = $this->supportFixture();
        $creator = $this->supportEmployee($f['system'], $f['main'], ['account.view', 'security.view', 'security.sales', 'security.printing']);
        $this->asPortalUser($creator);
        $id = $this->postJson('/api/v1/operations/security/stops', ['scope' => 'custom', 'account_ids' => [$f['pos']->id], 'actions' => ['sales', 'printing'], 'reason' => 'Review combined actions', 'idempotency_key' => 'combined-stop'])->assertOk()->json('data.id');
        $employee = $this->supportEmployee($f['system'], $f['main'], ['account.view', 'security.view', 'security.sales']);
        $this->asPortalUser($employee);
        $this->postJson('/api/v1/operations/security/stops/'.$id.'/resume', ['version' => 1, 'idempotency_key' => 'deny-combined-resume'])->assertForbidden();
        app(ManagementAuthority::class)->replaceProfilePermissions($employee->membership->permission_profile_id, ['account.view', 'security.view', 'security.sales', 'security.printing']);
        $employee->unsetRelation('membership');
        $this->asPortalUser($employee);
        $this->postJson('/api/v1/operations/security/stops/'.$id.'/resume', ['version' => 1, 'idempotency_key' => 'allow-combined-resume'])->assertOk();
    }

    public function test_company_content_does_not_grant_application_or_printer_configuration(): void
    {
        $f = $this->supportFixture();
        $employee = $this->supportEmployee($f['system'], $f['system'], ['account.view', 'company.view', 'company.edit']);
        $this->asPortalUser($employee);
        $data = $this->getJson('/api/v1/company/app-config')->assertOk()->json('data');
        $data['config']['name'] = 'Unauthorized application change';
        $before = CompanyProfile::findOrFail(1)->content;
        $this->putJson('/api/v1/company/app-config', $data)->assertForbidden();
        $this->assertSame($before, CompanyProfile::findOrFail(1)->content);
    }

    #[TestWith(['app.settings'])]
    #[TestWith(['printers.manage'])]
    public function test_configuration_can_be_delegated_without_editing_company_content(string $permission): void
    {
        $f = $this->supportFixture();
        $employee = $this->supportEmployee($f['system'], $f['system'], ['account.view', $permission]);
        $this->asPortalUser($employee);
        $data = $this->getJson('/api/v1/company/app-config?manage=1')->assertOk()->json('data');
        if ($permission === 'app.settings') {
            $data['config']['name'] = 'Authorized application name';
        } else {
            $data['config']['printers'][0]['name'] = 'Approved receipt printer';
        }
        $data = $this->putJson('/api/v1/company/app-config', $data)->assertOk()->json('data');
        if ($permission === 'app.settings') {
            $data['config']['printers'][0]['name'] = 'Unauthorized printer change';
        } else {
            $data['config']['terms'] = 'Unauthorized terms change';
        }
        $this->putJson('/api/v1/company/app-config', $data)->assertForbidden();
    }

    public function test_configuration_requires_the_system_scope_not_an_unrelated_staff_root(): void
    {
        $f = $this->supportFixture();
        $this->asPortalUser($f['admin']);
        $data = $this->getJson('/api/v1/company/app-config')->assertOk()->json('data');
        $employee = $this->supportEmployee($f['system'], $f['main'], ['account.view', 'app.settings']);
        $this->asPortalUser($employee);
        $data['config']['name'] = 'Outside assigned scope';
        $this->putJson('/api/v1/company/app-config', $data)->assertForbidden();
    }

    public function test_employee_can_edit_own_identity_but_cannot_edit_agent_color(): void
    {
        $f = $this->supportFixture();
        $employee = $this->supportEmployee($f['main'], $f['pos'], ['account.view']);
        $this->asPortalUser($employee);
        $this->patchJson('/api/v1/auth/profile', ['version' => 1, 'name' => 'Updated employee'])->assertOk()->assertJsonPath('data.user.name', 'Updated employee');
        $this->patchJson('/api/v1/auth/profile', ['version' => 2, 'name' => 'Updated employee', 'color' => '#112233'])->assertForbidden();
        $this->assertSame('Updated employee', $employee->fresh()->name);
    }

    public function test_staff_password_reset_is_scoped_and_keeps_force_change_flow(): void
    {
        $f = $this->supportFixture();
        $employee = $this->supportEmployee($f['system'], $f['main'], ['account.view', 'reports.view']);
        $this->asPortalUser($f['admin']);
        $path = '/api/v1/accounts/'.$f['system']->id.'/staff/'.$employee->membership->id.'/password-reset';
        $this->getJson($path)->assertOk()->assertJsonPath('data.version', 1);
        $this->postJson($path, ['version' => 1, 'reason' => 'Employee requested a password reset'])->assertOk();
        $this->assertNotNull($employee->fresh()->password_change_required_at);
        $this->asPortalUser($employee->fresh());
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.password_reset.required', true);
        $this->getJson('/api/v1/accounts')->assertForbidden()->assertJsonPath('code', 'PASSWORD_CHANGE_REQUIRED');
        $this->postJson('/api/v1/auth/password-reset/complete', ['version' => 1, 'password' => 'New-employee-981!', 'password_confirmation' => 'New-employee-981!'])->assertOk()->assertJsonPath('data.password_reset.required', false);
        $this->assertTrue(Hash::check('New-employee-981!', $employee->fresh()->password));
        $this->assertNull($employee->fresh()->password_change_required_at);
    }

    public function test_delegated_staff_reset_cannot_target_owner_self_or_stronger_employee(): void
    {
        $f = $this->supportFixture();
        $actor = $this->supportEmployee($f['system'], $f['system'], ['account.view', 'staff.view', 'staff.password_reset']);
        $stronger = $this->supportEmployee($f['system'], $f['system'], ['account.view', 'staff.view', 'integrations.view']);
        $this->asPortalUser($actor);
        foreach ([$f['admin']->membership->id, $actor->membership->id, $stronger->membership->id] as $id) {
            $this->postJson('/api/v1/accounts/'.$f['system']->id.'/staff/'.$id.'/password-reset', ['version' => 1, 'reason' => 'Invalid authority target'])->assertForbidden();
        }
        $this->assertNull($f['admin']->fresh()->password_change_required_at);
        $this->assertNull($stronger->fresh()->password_change_required_at);
    }

    public function test_permission_migration_preserves_business_data_and_inherited_denials(): void
    {
        $f = $this->supportFixture();
        $employee = $this->supportEmployee($f['system'], $f['system'], ['account.view', 'company.view', 'company.edit']);
        DB::table('account_permission_rules')->insert(['target_account_id' => $f['system']->id, 'authority_account_id' => $f['system']->id, 'permission_id' => DB::table('permissions')->where('name', 'digital.assign')->value('id'), 'allowed' => false]);
        $excluded = ['permissions', 'role_permissions', 'account_permission_rules', 'membership_permissions', 'permission_profile_permissions'];
        $tables = collect(Schema::getTables())->pluck('name')->reject(fn ($table) => in_array($table, $excluded, true));
        $before = $tables->mapWithKeys(fn ($table) => [$table => json_encode(DB::table($table)->get(), JSON_THROW_ON_ERROR)])->all();
        $migration = require database_path('migrations/2026_10_17_134658_add_granular_operational_permissions.php');
        $migration->up();
        $migration->up();
        foreach ($before as $table => $data) {
            $this->assertSame($data, json_encode(DB::table($table)->get(), JSON_THROW_ON_ERROR), $table.' changed during permission migration');
        }
        $employee->unsetRelation('membership');
        $this->assertContains('app.settings', $employee->membership->permissions());
        $this->assertContains('printers.manage', $employee->membership->permissions());
        $f['agent']->unsetRelation('membership');
        $this->assertNotContains('topup.assign', $f['agent']->membership->permissions());
        $this->assertCount(174, DB::table('permissions')->get());
    }

    public function test_logged_out_employee_can_complete_only_the_request_approved_for_their_browser(): void
    {
        $f = $this->supportFixture();
        $employee = $this->supportEmployee($f['system'], $f['main'], ['account.view']);
        $this->withCredentials()->withHeader('X-Masal-Portal', 'admin');
        Auth::forgetGuards();
        Auth::guard('web')->logout();
        $request = $this->postJson('/api/v1/auth/password-reset/request', ['login' => $employee->email])->assertStatus(202);
        $cookie = $request->getCookie('masal_admin_password_recovery');
        $this->assertNotNull($cookie);
        $this->withCookie('masal_admin_password_recovery', $cookie->getValue());
        $this->getJson('/api/v1/auth/password-reset/request-status')->assertOk()->assertJsonPath('data.status', 'pending');
        $password = ['version' => 1, 'password' => 'Employee-reset-459!', 'password_confirmation' => 'Employee-reset-459!'];
        $this->postJson('/api/v1/auth/password-reset/complete-request', $password)->assertUnprocessable();
        $this->asPortalUser($f['admin']);
        $path = '/api/v1/accounts/'.$f['system']->id.'/staff/'.$employee->membership->id.'/password-reset';
        $this->getJson($path)->assertOk()->assertJsonPath('data.password_reset_requests.0.id', $request->json('data.request_id'));
        $this->postJson($path, ['version' => 1, 'reason' => 'Verified employee recovery request', 'request_id' => $request->json('data.request_id')])->assertOk();
        Auth::forgetGuards();
        Auth::guard('web')->logout();
        $this->getJson('/api/v1/auth/password-reset/request-status')->assertOk()->assertJsonPath('data.status', 'approved');
        $this->postJson('/api/v1/auth/password-reset/complete-request', $password)->assertOk();
        $this->assertTrue(Hash::check('Employee-reset-459!', $employee->fresh()->password));
        $this->postJson('/api/v1/auth/password-reset/complete-request', $password)->assertUnprocessable();
    }

    public function test_admin_can_delegate_employee_password_reset_to_an_agent_without_promoting_the_agent(): void
    {
        $f = $this->supportFixture();
        $this->assertNotContains('staff.password_reset', $f['agent']->membership->permissions());
        $employee = $this->supportEmployee($f['main'], $f['pos'], ['account.view']);
        $this->asPortalUser($f['admin']);
        $permissions = [...$f['agent']->membership->permissions(), 'staff.password_reset'];
        $this->patchJson('/api/v1/accounts/'.$f['main']->id.'/permissions', ['version' => 1, 'permissions' => $permissions, 'reason' => 'Delegate staff password recovery'])->assertOk();
        $actor = $f['agent']->fresh();
        $this->assertContains('staff.password_reset', $actor->membership->permissions());
        $this->assertNotContains('integrations.credentials', $actor->membership->permissions());
        $this->asPortalUser($actor);
        $this->postJson('/api/v1/accounts/'.$f['main']->id.'/staff/'.$employee->membership->id.'/password-reset', ['version' => 1, 'reason' => 'Verified employee requested reset'])->assertOk();
        $this->assertNotNull($employee->fresh()->password_change_required_at);
    }
}

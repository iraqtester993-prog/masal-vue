<?php

namespace Tests\Feature;

use App\Models\Operations\AccountTimePolicy;
use App\Models\User;
use App\Services\ManagementAuthority;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesSupport;
use Tests\TestCase;

class PermissionVisibilityTest extends TestCase
{
    use CreatesSupport, RefreshDatabase;

    private function events(array $fixture, User $employee, array $actions): void
    {
        foreach ($actions as $index => $action) {
            DB::table('audit_logs')->insert(['user_id' => $employee->id, 'account_id' => $fixture['system']->id,
                'subject_account_id' => $fixture['system']->id, 'action' => $action, 'portal' => 'admin',
                'created_at' => '2026-10-18 10:00:'.str_pad((string) $index, 2, '0', STR_PAD_LEFT),
                'details' => json_encode(['reason' => 'Authorized fixture event', 'credential' => 'PRIVATE_FIXTURE_SECRET'])]);
        }
    }

    public function test_dashboard_and_audit_hide_revoked_features_even_in_the_callers_old_events(): void
    {
        $fixture = $this->supportFixture();
        $employee = $this->supportEmployee($fixture['system'], $fixture['system'], ['dashboard.view', 'reports.view', 'reports.audit', 'printers.manage']);
        $actions = ['company.app-config', 'company.update', 'permission_profile.create', 'account.permissions', 'wallets.transfer', 'backup.create', 'auth.login'];
        $this->events($fixture, $employee, $actions);
        $this->asPortalUser($employee);
        foreach (['dashboard/activity', 'reports/sections/audit/rows'] as $path) {
            $response = $this->getJson('/api/v1/'.$path)->assertOk()->assertJsonCount(2, 'data');
            $this->assertEqualsCanonicalizing(['company.app-config', 'auth.login'], array_column($response->json('data'), 'action'));
            foreach (['PRIVATE_FIXTURE_SECRET', 'permission_profile.create', 'wallets.transfer', 'backup.create'] as $hidden) {
                $this->assertStringNotContainsString($hidden, $response->getContent());
            }
        }
        app(ManagementAuthority::class)->replaceProfilePermissions($employee->membership->permission_profile_id, ['dashboard.view', 'reports.view', 'reports.audit']);
        $this->getJson('/api/v1/dashboard/activity')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.action', 'auth.login');
        $this->assertDatabaseCount('audit_logs', count($actions));
        $this->assertDatabaseCount('finance_transactions', 0);
    }

    public function test_report_options_omit_ungranted_sections_groups_and_permission_hints(): void
    {
        $fixture = $this->supportFixture();
        $employee = $this->supportEmployee($fixture['system'], $fixture['system'], ['reports.view', 'reports.audit', 'reports.sales', 'reports.inventory', 'reports.wallets', 'reports.users', 'reports.operations', 'reports.network', 'reports.support']);
        $this->asPortalUser($employee);
        $response = $this->getJson('/api/v1/reports/options')->assertOk()->assertJsonCount(2, 'data.sections')->assertJsonCount(1, 'data.groups');
        $this->assertEqualsCanonicalizing(['audit', 'audit-changes'], array_column($response->json('data.sections'), 'id'));
        $response->assertJsonPath('data.groups.0.prefixes', ['audit'])->assertJsonPath('data.groups.0.note', '');
        $response->assertJsonPath('data.filters.products', false)->assertJsonPath('data.filters.providers', false)->assertJsonPath('data.filters.status', false)->assertJsonPath('data.products', [])->assertJsonPath('data.providers', []);
        foreach (['required_permissions', 'protected', 'sales-services', 'operations-integrations', 'wallets-ledger', 'users-permissions', 'support-replies'] as $hidden) {
            $this->assertStringNotContainsString($hidden, $response->getContent());
        }
        $this->getJson('/api/v1/reports/sections/sales/rows')->assertNotFound();
    }

    public function test_permission_report_keys_and_counts_stay_inside_the_viewers_grants(): void
    {
        $fixture = $this->supportFixture();
        $employee = $this->supportEmployee($fixture['system'], $fixture['system'], ['reports.view', 'reports.users', 'staff.view']);
        $this->asPortalUser($employee);
        $visible = $employee->membership->permissions();
        $rows = $this->getJson('/api/v1/reports/sections/users-permissions/rows?per_page=100')->assertOk()->json('data');
        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertContains($row['permission'], $visible);
        }
        $this->assertNotContains('backup.restore', array_column($rows, 'permission'));
        $users = $this->getJson('/api/v1/reports/sections/users/rows?per_page=100')->assertOk()->json('data');
        $admin = collect($users)->firstWhere('id', $fixture['admin']->id);
        $this->assertNull($admin);
        $own = collect($users)->firstWhere('id', $employee->id);
        $this->assertNotNull($own);
        $this->assertSame(count(array_intersect($employee->membership->permissions(), $visible)), $own['granted']);
    }

    public function test_read_only_module_events_stay_visible_without_granting_their_write_actions(): void
    {
        $fixture = $this->supportFixture();
        $employee = $this->supportEmployee($fixture['system'], $fixture['system'], ['dashboard.view', 'reports.view', 'reports.audit', 'wallets.view']);
        $this->events($fixture, $employee, ['wallets.transfer', 'wallets.reverse', 'company.app-config', 'account.permissions']);
        $this->asPortalUser($employee);
        $response = $this->getJson('/api/v1/dashboard/activity')->assertOk()->assertJsonCount(2, 'data');
        $this->assertEqualsCanonicalizing(['wallets.transfer', 'wallets.reverse'], array_column($response->json('data'), 'action'));
        $this->assertNotContains('wallets.transfer', $employee->membership->permissions());
        $this->assertDatabaseCount('finance_entries', 0);
    }

    public function test_report_only_scope_does_not_grant_the_accounts_page_and_denials_are_neutral(): void
    {
        $fixture = $this->supportFixture();
        $employee = $this->supportEmployee($fixture['system'], $fixture['main'], ['reports.view', 'reports.sales', 'sales.view']);
        $this->asPortalUser($employee);
        $this->getJson('/api/v1/reports/options')->assertOk();
        $response = $this->getJson('/api/v1/accounts')->assertForbidden()->assertExactJson(['message' => 'هذا الإجراء غير متاح حاليًا.']);
        $this->assertStringNotContainsString('account.view', $response->getContent());
        $this->assertNotContains('account.view', $employee->membership->permissions());
    }

    public function test_permission_reports_match_explicit_delegation_overrides_and_inactive_owners(): void
    {
        $fixture = $this->supportFixture();
        foreach (['map.view', 'staff.password_reset'] as $name) {
            DB::table('account_permission_rules')->insert(['target_account_id' => $fixture['main']->id, 'authority_account_id' => $fixture['system']->id, 'permission_id' => DB::table('permissions')->where('name', $name)->value('id'), 'allowed' => true]);
        }
        $employee = $this->supportEmployee($fixture['main'], $fixture['pos'], ['map.view', 'staff.password_reset']);
        $this->asPortalUser($fixture['admin']);
        $check = function (string $key, string $expected) use ($employee, $fixture): void {
            $rows = $this->getJson('/api/v1/reports/sections/users-permissions/rows?q='.urlencode($key).'&per_page=100')->assertOk()->json('data');
            foreach ([$fixture['agent']->name, $employee->name] as $name) {
                $row = collect($rows)->first(fn (array $row): bool => $row['user'] === $name && $row['permission'] === $key);
                $this->assertNotNull($row);
                $this->assertSame($expected, $row['effective']);
            }
        };
        $check('map.view', 'مسموح');
        $check('staff.password_reset', 'مسموح');
        DB::table('membership_permissions')->insert(['membership_id' => $fixture['agent']->membership->id, 'permission_id' => DB::table('permissions')->where('name', 'map.view')->value('id'), 'allowed' => false]);
        $check('map.view', 'ممنوع');
        $fixture['agent']->membership->update(['status' => 'inactive']);
        $rows = $this->getJson('/api/v1/reports/sections/users-permissions/rows?q=staff.password_reset&per_page=100')->assertOk()->json('data');
        $this->assertSame('ممنوع', collect($rows)->firstWhere('user', $employee->name)['effective']);
    }

    public function test_time_reports_use_complete_membership_coverage_and_hide_self_and_broader_staff(): void
    {
        $fixture = $this->supportFixture();
        $employee = $this->supportEmployee($fixture['system'], $fixture['system'], ['reports.view', 'reports.users', 'security.view', 'security.time', 'dashboard.view']);
        $employee->membership->update(['include_descendants' => false]);
        $visible = $this->supportEmployee($fixture['system'], $fixture['system'], ['dashboard.view']);
        $visible->membership->update(['include_descendants' => false]);
        $broader = $this->supportEmployee($fixture['system'], $fixture['system'], ['dashboard.view']);
        foreach ([$employee, $visible, $broader] as $user) {
            AccountTimePolicy::factory()->create(['user_id' => $user->id]);
        }
        $this->asPortalUser($employee);
        $this->getJson('/api/v1/reports/sections/users-times/rows')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $visible->id);
        $this->assertDatabaseCount('account_time_policies', 3);
        $this->assertDatabaseCount('finance_transactions', 0);
    }

    public function test_empty_report_grants_do_not_expose_filter_catalogs_or_scope_names(): void
    {
        $fixture = $this->supportFixture();
        $employee = $this->supportEmployee($fixture['system'], $fixture['system'], ['reports.view']);
        $this->asPortalUser($employee);
        $response = $this->getJson('/api/v1/reports/options')->assertOk();
        foreach (['sections', 'groups', 'accounts', 'cities', 'account_kinds', 'products', 'providers'] as $key) {
            $response->assertJsonPath('data.'.$key, []);
        }
        $this->assertStringNotContainsString($fixture['foreign']->name, $response->getContent());
    }
}

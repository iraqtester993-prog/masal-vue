<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Operations\DirectStop;
use App\Models\Operations\OperationStop;
use App\Services\Operations\MutationGuard;
use App\Services\Operations\OperationGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\CreatesSupport;
use Tests\TestCase;

class OperationsWorkflowTest extends TestCase
{
    use CreatesSupport, RefreshDatabase;

    public function test_compact_security_preserves_inherited_restrictions_without_shipping_account_options(): void
    {
        $f = $this->supportFixture();
        $this->asPortalUser($f['admin']);
        $this->getJson('/api/v1/operations/security?compact_accounts=1')->assertOk()->assertJsonCount(0, 'data.accounts')->assertJsonCount(0, 'data.restricted');
        DirectStop::create(['account_id' => $f['main']->id, 'stops' => ['sales' => true], 'version' => 1]);
        $full = $this->getJson('/api/v1/operations/security')->assertOk()->json('data');
        $compact = $this->getJson('/api/v1/operations/security?compact_accounts=1')->assertOk()->json('data');
        $this->assertSame($full['restricted'], $compact['restricted']);
        $this->assertSame($full['restricted_total'], $compact['restricted_total']);
        $this->assertSame([], $compact['accounts']);
        $this->assertGreaterThan(0, array_sum($compact['account_totals']));
    }

    private function stop(array $extra = []): array
    {
        return array_replace(['scope' => 'main', 'actions' => ['sales'], 'reason' => 'مراجعة تشغيلية', 'idempotency_key' => 'stop-001'], $extra);
    }

    public function test_guests_and_non_system_owners_cannot_manage_or_read_security_even_with_grants(): void
    {
        $f = $this->supportFixture();
        $this->withHeader('X-Masal-Portal', 'admin')->getJson('/api/v1/operations/security')->assertUnauthorized();
        foreach (['security.view', 'security.policies'] as $permission) {
            $this->supportGrant($f['agent'], $permission);
        }
        $this->asPortalUser($f['agent']);
        $this->getJson('/api/v1/operations/security')->assertForbidden();
        $this->postJson('/api/v1/operations/security/stops', $this->stop())->assertForbidden();
        $this->assertDatabaseCount('operation_stops', 0);
    }

    public function test_scope_levels_do_not_inherit_but_direct_stops_do_and_bulk_table_agrees(): void
    {
        $f = $this->supportFixture();
        $this->asPortalUser($f['admin']);
        $this->postJson('/api/v1/operations/security/stops', $this->stop())->assertOk()->assertJsonPath('data.count', 2);
        $guard = app(OperationGuard::class);
        $this->assertNotNull($guard->reason($f['main']->id, 'sales'));
        foreach (['sub', 'branch', 'pos'] as $key) {
            $this->assertNull($guard->reason($f[$key]->id, 'sales'));
        }
        $this->putJson('/api/v1/operations/security/accounts/'.$f['sub']->id, ['stops' => ['printing' => true], 'version' => 0, 'idempotency_key' => 'direct-1'])->assertOk();
        $this->assertNotNull($guard->reason($f['pos']->id, 'printing'));
        $this->assertNull($guard->reason($f['main']->id, 'printing'));
        $data = $this->getJson('/api/v1/operations/security')->assertOk()->json('data.restricted');
        $byId = collect($data)->keyBy('id');
        $this->assertSame(['sales'], $byId[$f['main']->id]['stops']);
        $this->assertSame(['printing'], $byId[$f['pos']->id]['stops']);
        $this->assertSame(1, $byId[$f['sub']->id]['direct_version']);
        $this->assertSame([], $byId[$f['pos']->id]['direct_stops']);
    }

    public function test_rule_resume_uses_version_idempotency_and_keeps_overlapping_decisions(): void
    {
        $f = $this->supportFixture();
        $this->asPortalUser($f['admin']);
        $input = $this->stop(['scope' => 'custom', 'account_ids' => [$f['pos']->id], 'actions' => ['app']]);
        $id = $this->postJson('/api/v1/operations/security/stops', $input)->assertOk()->json('data.id');
        $this->postJson('/api/v1/operations/security/stops', $input)->assertOk()->assertJsonPath('data.id', $id);
        $this->postJson('/api/v1/operations/security/stops', array_replace($input, ['reason' => 'تغيير']))->assertConflict();
        $other = $this->postJson('/api/v1/operations/security/stops', $this->stop(['scope' => 'pos', 'idempotency_key' => 'second']))->assertOk()->json('data.id');
        foreach (['login', 'import', 'printing', 'sales'] as $key) {
            $this->assertNotNull(app(OperationGuard::class)->reason($f['pos']->id, $key));
        }
        $this->postJson('/api/v1/operations/security/stops/'.$id.'/resume', ['version' => 0, 'idempotency_key' => 'bad-resume'])->assertConflict();
        $resume = ['version' => 1, 'idempotency_key' => 'resume'];
        $this->postJson('/api/v1/operations/security/stops/'.$id.'/resume', $resume)->assertOk()->assertJsonPath('data.version', 2);
        $this->postJson('/api/v1/operations/security/stops/'.$id.'/resume', $resume)->assertOk();
        $this->assertNull(app(OperationGuard::class)->reason($f['pos']->id, 'printing'));
        $this->assertNotNull(app(OperationGuard::class)->reason($f['pos']->id, 'sales'));
        $this->assertTrue(OperationStop::findOrFail($other)->active);
        $this->assertDatabaseCount('operation_stops', 2);
    }

    public function test_system_employee_all_decision_remains_inside_original_authorized_forest_and_hidden_from_other_staff(): void
    {
        $f = $this->supportFixture();
        $employee = $this->supportEmployee($f['system'], $f['main'], ['account.view', 'security.view', 'security.policies']);
        $foreign = $this->supportEmployee($f['system'], $f['foreign'], ['account.view', 'security.view', 'security.policies']);
        $this->asPortalUser($employee);
        $this->postJson('/api/v1/operations/security/stops', $this->stop(['scope' => 'custom', 'account_ids' => [$f['foreign']->id]]))->assertNotFound();
        $id = $this->postJson('/api/v1/operations/security/stops', $this->stop(['scope' => 'all']))->assertOk()->assertJsonPath('data.count', 4)->json('data.id');
        $new = $this->account(AccountType::Pos, $f['main']);
        $this->assertNotNull(app(OperationGuard::class)->reason($new->id, 'sales'));
        $this->assertNull(app(OperationGuard::class)->reason($f['foreign']->id, 'sales'));
        $this->asPortalUser($foreign);
        $this->getJson('/api/v1/operations/security')->assertJsonCount(0, 'data.active_stops');
        $this->postJson('/api/v1/operations/security/stops/'.$id.'/resume', ['version' => 1, 'idempotency_key' => 'foreign'])->assertNotFound();
        $this->assertDatabaseHas('operation_stops', ['id' => $id, 'active' => true]);
    }

    public function test_unknown_fields_duplicates_invalid_actions_and_global_self_targets_are_rejected(): void
    {
        $f = $this->supportFixture();
        $this->asPortalUser($f['admin']);
        foreach ([$this->stop(['permission' => 'all']), $this->stop(['scope' => 'custom', 'account_ids' => [$f['pos']->id, $f['pos']->id]]), $this->stop(['actions' => ['fake']]), $this->stop(['reason' => '   '])] as $input) {
            $this->postJson('/api/v1/operations/security/stops', $input)->assertUnprocessable();
        }
        $this->postJson('/api/v1/operations/security/stops', $this->stop(['scope' => 'custom', 'account_ids' => [$f['system']->id]]))->assertNotFound();
        $this->assertDatabaseCount('operation_stops', 0);
        $this->assertDatabaseCount('operation_mutations', 0);
    }

    public function test_bulk_restrictions_are_constant_query_count_for_many_accounts_and_include_disabled_ancestors(): void
    {
        $f = $this->supportFixture();
        for ($i = 0; $i < 35; $i++) {
            $this->account(AccountType::Pos, $f['main']);
        }
        OperationStop::factory()->create(['creator_id' => $f['admin']->id, 'authority_account_id' => $f['system']->id]);
        DirectStop::factory()->create(['account_id' => $f['main']->id, 'stops' => ['import' => true]]);
        $accounts = Account::where('type', '<>', 'system')->get();
        DB::enableQueryLog();
        $map = app(OperationGuard::class)->restrictions($accounts);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertLessThanOrEqual(4, $queries);
        $this->assertContains('import', $map[$f['pos']->id]);
        $this->assertContains('sales', $map[$f['pos']->id]);
        $f['main']->update(['status' => 'disabled']);
        $map = app(OperationGuard::class)->restrictions($accounts);
        $this->assertEqualsCanonicalizing(OperationGuard::KEYS, $map[$f['pos']->id]);
    }

    public function test_global_legacy_restore_requires_each_permission_and_entire_authorized_forest(): void
    {
        $f = $this->supportFixture();
        DirectStop::factory()->create(['account_id' => $f['system']->id, 'stops' => ['app' => true, 'login' => true]]);
        $grants = ['account.view', 'security.view', 'security.policies', 'security.app', 'security.login', 'security.sales', 'security.printing'];
        $limited = $this->supportEmployee($f['system'], $f['main'], $grants);
        $whole = $this->supportEmployee($f['system'], $f['system'], $grants);
        $this->asPortalUser($limited);
        $this->getJson('/api/v1/operations/security')->assertUnauthorized();
        $this->asPortalUser($limited);
        $this->postJson('/api/v1/operations/security/restore-global', ['version' => 1, 'idempotency_key' => 'limited-global'])->assertUnauthorized();
        $this->asPortalUser($whole);
        $this->postJson('/api/v1/operations/security/restore-global', ['version' => 1, 'idempotency_key' => 'whole-global'])->assertOk();
        $this->assertNull(app(OperationGuard::class)->reason($f['pos']->id, 'sales'));
        $this->assertDatabaseHas('operation_direct_stops', ['account_id' => $f['system']->id, 'version' => 2]);
    }

    public function test_current_write_admission_preserves_scope_type_custom_targets_and_inherited_direct_stops(): void
    {
        $f = $this->supportFixture();
        $cases = [
            ['all', null, true, null, null, true],
            ['main', null, true, null, null, false],
            ['pos', null, true, null, null, true],
            ['custom', null, true, $f['pos']->id, null, true],
            ['custom', null, true, $f['foreign']->id, null, false],
            ['all', [$f['main']->id], true, null, null, true],
            ['all', [$f['main']->id], false, null, null, false],
            ['all', [$f['foreign']->id], true, null, null, false],
            [null, null, true, null, $f['main']->id, true],
        ];
        foreach ($cases as [$scope, $roots, $descendants, $target, $direct, $blocked]) {
            DB::beginTransaction();
            try {
                if ($scope !== null) {
                    $rule = OperationStop::factory()->create(['creator_id' => $f['admin']->id, 'authority_account_id' => $f['system']->id,
                        'scope' => $scope, 'scope_roots' => $roots, 'include_descendants' => $descendants, 'actions' => ['sales']]);
                    if ($target !== null) {
                        DB::table('operation_stop_targets')->insert(['stop_id' => $rule->id, 'account_id' => $target]);
                    }
                }
                if ($direct !== null) {
                    DirectStop::create(['account_id' => $direct, 'stops' => ['sales' => true], 'version' => 1]);
                }
                $this->assertSame($blocked, app(OperationGuard::class)->reason($f['pos']->id, 'sales') !== null);
                $ancestors = DB::table('account_closure')->where('descendant_id', $f['pos']->id)->pluck('ancestor_id')->all();
                app(MutationGuard::class)->lockAccounts($ancestors, $f['pos']->id);
                try {
                    app(OperationGuard::class)->assertAllowedCurrent($f['pos']->id, 'sales');
                    $this->assertFalse($blocked);
                } catch (HttpException $failure) {
                    $this->assertTrue($blocked);
                    $this->assertSame(423, $failure->getStatusCode());
                }
            } finally {
                DB::rollBack();
            }
        }
    }

    public function test_bulk_operation_stops_preserve_system_account_admission(): void
    {
        $f = $this->supportFixture();
        OperationStop::factory()->create(['creator_id' => $f['admin']->id, 'authority_account_id' => $f['system']->id, 'scope' => 'all', 'scope_roots' => null, 'actions' => ['app']]);
        $this->assertNull(app(OperationGuard::class)->reason($f['system']->id, 'app'));
        DB::transaction(function () use ($f): void {
            app(MutationGuard::class)->lock($f['admin']);
        });
        $this->assertNotNull(app(OperationGuard::class)->reason($f['pos']->id, 'app'));
    }
}

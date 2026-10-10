<?php

namespace Tests\Feature;

use App\Models\Operations\AccountTimePolicy;
use App\Services\Operations\AccountTimeGuard;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\CreatesSupport;
use Tests\TestCase;

class AccountTimeGuardTest extends TestCase
{
    use CreatesSupport, RefreshDatabase;

    public function test_only_system_owner_can_manage_actual_employees_and_saving_revokes_sessions_without_changing_password(): void
    {
        $f = $this->supportFixture();
        $staff = $this->supportEmployee($f['system'], $f['main'], ['account.view', 'security.view', 'security.policies']);
        $target = $this->supportEmployee($f['main'], $f['pos'], ['account.view']);
        $password = $target->password;
        $version = $target->fresh()->session_version;
        $policy = array_replace(AccountTimeGuard::emptyPolicy(), ['enabled' => true, 'idleEnabled' => true, 'idleMinutes' => 15]);
        $input = ['policy' => $policy, 'version' => 0, 'idempotency_key' => 'time-1'];
        $this->asPortalUser($staff);
        $this->getJson('/api/v1/operations/account-times')->assertForbidden();
        $this->putJson('/api/v1/operations/account-times/'.$target->id, $input)->assertForbidden();
        $this->asPortalUser($f['admin']);
        $this->getJson('/api/v1/operations/account-times/'.$f['agent']->id)->assertNotFound();
        $this->putJson('/api/v1/operations/account-times/'.$target->id, $input)->assertOk()->assertJsonPath('data.policy.idleMinutes', 15)->assertJsonPath('data.version', 1);
        $this->putJson('/api/v1/operations/account-times/'.$target->id, $input)->assertOk();
        $this->assertSame($password, $target->fresh()->password);
        $this->assertSame($version + 1, $target->fresh()->session_version);
        $this->assertDatabaseCount('account_time_policies', 1);
        $this->assertDatabaseCount('operation_mutations', 1);
        $this->putJson('/api/v1/operations/account-times/'.$target->id, array_replace($input, ['idempotency_key' => 'stale']))->assertConflict();
    }

    public function test_disabled_policy_is_saved_and_invalid_windows_or_unspecified_constraints_are_rejected_atomically(): void
    {
        $f = $this->supportFixture();
        $target = $this->supportEmployee($f['main'], $f['pos'], ['account.view']);
        $this->asPortalUser($f['admin']);
        $empty = AccountTimeGuard::emptyPolicy();
        foreach ([['enabled' => true], ['enabled' => true, 'hoursEnabled' => true, 'startTime' => '08:00', 'endTime' => '08:00'], ['enabled' => true, 'dateEnabled' => true, 'startAt' => '2026-02-30T01:00', 'endAt' => '2026-03-01T01:00'], ['idleMinutes' => 0], ['owner' => true]] as $extra) {
            $this->putJson('/api/v1/operations/account-times/'.$target->id, ['policy' => array_replace($empty, $extra), 'version' => 0, 'idempotency_key' => 'invalid'])->assertUnprocessable();
        }
        $this->assertDatabaseCount('account_time_policies', 0);
        $this->putJson('/api/v1/operations/account-times/'.$target->id, ['policy' => $empty, 'version' => 0, 'idempotency_key' => 'disabled'])->assertOk()->assertJsonPath('data.policy.enabled', false);
    }

    public function test_baghdad_date_window_and_overnight_hours_have_exact_inclusive_start_exclusive_end(): void
    {
        $f = $this->supportFixture();
        $staff = $this->supportEmployee($f['main'], $f['pos'], ['account.view']);
        $record = AccountTimePolicy::factory()->create(['user_id' => $staff->id, 'policy' => array_replace(AccountTimeGuard::emptyPolicy(), ['enabled' => true, 'dateEnabled' => true, 'startAt' => '2026-10-06T22:00', 'endAt' => '2026-10-07T06:00', 'hoursEnabled' => true, 'startTime' => '22:00', 'endTime' => '06:00'])]);
        $guard = app(AccountTimeGuard::class);
        foreach (['2026-10-06 19:00:00 UTC', '2026-10-07 02:59:59 UTC'] as $date) {
            $this->travelTo(CarbonImmutable::parse($date));
            $this->assertNull($guard->reason($staff));
        }
        foreach (['2026-10-06 18:59:59 UTC', '2026-10-07 03:00:00 UTC'] as $date) {
            $this->travelTo(CarbonImmutable::parse($date));
            $this->assertNotNull($guard->reason($staff));
        }
        $record->update(['policy' => array_replace($record->policy, ['dateEnabled' => false])]);
        $this->travelTo(CarbonImmutable::parse('2026-10-08 20:00:00 UTC'));
        $this->assertNull($guard->reason($staff));
        $this->travelTo(CarbonImmutable::parse('2026-10-08 09:00:00 UTC'));
        $this->assertNotNull($guard->reason($staff));
        $this->assertNull($guard->reason($f['agent']));
        $this->travelBack();
    }

    public function test_only_activity_updates_idle_clock_and_never_extends_absolute_session_duration(): void
    {
        $f = $this->supportFixture();
        $staff = $this->supportEmployee($f['main'], $f['pos'], ['account.view']);
        AccountTimePolicy::factory()->create(['user_id' => $staff->id, 'policy' => array_replace(AccountTimeGuard::emptyPolicy(), ['enabled' => true, 'idleEnabled' => true, 'idleMinutes' => 15, 'sessionEnabled' => true, 'sessionMinutes' => 30])]);
        $this->travelTo(CarbonImmutable::parse('2026-10-06 12:00:00 UTC'));
        $request = Request::create('/api/v1/operations/activity', 'POST');
        $request->setLaravelSession(app('session')->driver());
        $request->attributes->set('portal', 'agents');
        $guard = app(AccountTimeGuard::class);
        $guard->start($staff, $request);
        $initial = $request->session()->get('masal.time_clock');
        $this->travel(14)->minutes();
        $guard->enforce($staff, $request);
        $this->assertSame($initial, $request->session()->get('masal.time_clock'));
        $guard->enforce($staff, $request, true);
        $updated = $request->session()->get('masal.time_clock');
        $this->assertSame($initial['started'], $updated['started']);
        $this->assertGreaterThan($initial['lastActivity'], $updated['lastActivity']);
        $this->travel(14)->minutes();
        $guard->enforce($staff, $request, true);
        $this->travel(2)->minutes();
        try {
            $guard->enforce($staff, $request, true);
            $this->fail('Absolute session must expire despite activity.');
        } catch (HttpException $e) {
            $this->assertSame(401, $e->getStatusCode());
            $this->assertStringContainsString('مدة الجلسة', $e->getMessage());
        }
        $this->assertNull($request->session()->get('masal.time_clock'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.time_expired', 'user_id' => $staff->id]);
        $this->travelBack();
    }

    public function test_enabled_idle_without_server_clock_requires_new_login_and_client_timestamps_are_rejected(): void
    {
        $f = $this->supportFixture();
        $staff = $this->supportEmployee($f['main'], $f['pos'], ['account.view']);
        AccountTimePolicy::factory()->create(['user_id' => $staff->id, 'policy' => array_replace(AccountTimeGuard::emptyPolicy(), ['enabled' => true, 'idleEnabled' => true])]);
        $request = Request::create('/');
        $request->setLaravelSession(app('session')->driver());
        $request->attributes->set('portal', 'agents');
        try {
            app(AccountTimeGuard::class)->enforce($staff, $request, true);
            $this->fail('Missing server clock cannot bootstrap restricted session.');
        } catch (HttpException $e) {
            $this->assertSame(401, $e->getStatusCode());
        }
        $this->asPortalUser($f['admin']);
        $this->postJson('/api/v1/operations/activity', ['started' => 9999999999])->assertUnprocessable();
        $this->postJson('/api/v1/operations/activity', [])->assertOk();
    }
}

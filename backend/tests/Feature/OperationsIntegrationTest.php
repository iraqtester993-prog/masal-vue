<?php

namespace Tests\Feature;

use App\Models\Operations\AccountTimePolicy;
use App\Models\Operations\DirectStop;
use App\Services\Operations\AccountTimeGuard;
use App\Services\Operations\MutationGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\CreatesSupport;
use Tests\TestCase;

class OperationsIntegrationTest extends TestCase
{
    use CreatesSupport, RefreshDatabase;

    public function test_login_restriction_is_applied_before_a_session_is_created(): void
    {
        $f = $this->supportFixture();
        $f['agent']->update(['password' => Hash::make('valid-fixture-password')]);
        DirectStop::create(['account_id' => $f['main']->id, 'stops' => ['login' => true], 'version' => 1]);
        $this->withHeader('X-Masal-Portal', 'agents')->postJson('/api/v1/auth/login', ['login' => $f['agent']->email, 'password' => 'valid-fixture-password'])->assertStatus(423);
        $this->assertGuest('web');
        $this->assertDatabaseMissing('audit_logs', ['action' => 'auth.login', 'user_id' => $f['agent']->id]);
    }

    public function test_system_owner_can_recover_security_after_app_stop_but_cannot_perform_business_work(): void
    {
        $f = $this->supportFixture();
        DirectStop::create(['account_id' => $f['system']->id, 'stops' => ['app' => true], 'version' => 1]);
        $this->asPortalUser($f['admin']);
        $this->getJson('/api/v1/auth/me')->assertOk();
        $this->getJson('/api/v1/operations/security')->assertOk();
        $this->getJson('/api/v1/accounts')->assertStatus(423);
        $this->assertAuthenticatedAs($f['admin'], 'web');
        $this->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->assertGuest('web');
    }

    public function test_location_consent_is_required_for_mutation_and_old_updates_cannot_restore_revoked_consent(): void
    {
        config(['maps.require_location' => true]);
        $f = $this->supportFixture();
        $this->asPortalUser($f['posUser']);
        $this->withCredentials()->withCookie('masal_pos_session', session()->getId());
        $input = ['recipient_id' => $f['branch']->id, 'title' => 'دعم', 'description' => 'طلب مراجعة', 'idempotency_key' => 'gps-required'];
        $this->postJson('/api/v1/support/tickets', $input)->assertStatus(428);
        $this->assertDatabaseCount('support_tickets', 0);
        $point = ['latitude' => 33.3, 'longitude' => 44.4, 'accuracy' => 10, 'recorded_at' => now()->toISOString(), 'consent_version' => 1];
        $this->postJson('/api/v1/maps/location', $point)->assertOk();
        $this->postJson('/api/v1/support/tickets', $input)->assertOk();
        $this->postJson('/api/v1/maps/disconnect')->assertNoContent();
        $this->postJson('/api/v1/maps/heartbeat', [])->assertNoContent();
        $this->getJson('/api/v1/maps/own')->assertOk()->assertJsonPath('data.consent_version', 2)->assertJsonPath('data.location_ready', false);
        $this->postJson('/api/v1/maps/location', $point)->assertConflict();
        $this->postJson('/api/v1/support/tickets', array_replace($input, ['idempotency_key' => 'revoked']))->assertStatus(428);
        $this->postJson('/api/v1/maps/location', array_replace($point, ['consent_version' => 2]))->assertOk();
        $this->postJson('/api/v1/maps/location', array_replace($point, ['consent_version' => 2, 'recorded_at' => now()->subMinute()->toISOString()]))->assertConflict();
        $this->assertDatabaseCount('support_tickets', 1);
    }

    public function test_background_requests_do_not_extend_employee_idle_time(): void
    {
        $f = $this->supportFixture();
        $employee = $this->supportEmployee($f['main'], $f['pos'], ['account.view']);
        AccountTimePolicy::factory()->create(['user_id' => $employee->id, 'policy' => array_replace(AccountTimeGuard::emptyPolicy(), ['enabled' => true, 'idleEnabled' => true, 'idleMinutes' => 1])]);
        $this->asPortalUser($employee);
        $this->withSession(['masal.time_clock' => ['user' => $employee->id, 'version' => $employee->fresh()->session_version, 'started' => now()->timestamp, 'lastActivity' => now()->timestamp]]);
        $this->travel(30)->seconds();
        $this->getJson('/api/v1/auth/me')->assertOk();
        $this->travel(30)->seconds();
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->assertGuest('web');
        $this->travelBack();
    }

    public function test_mutation_rechecks_session_version_before_idempotency_or_financial_effect(): void
    {
        $f = $this->supportFixture();
        $actor = $f['agent']->fresh();
        DB::table('users')->where('id', $actor->id)->increment('session_version');
        try {
            DB::transaction(fn () => app(MutationGuard::class)->lock($actor, [], 'wallets.transfer'));
            $this->fail('Stale actor must be rejected before mutation.');
        } catch (HttpException $failure) {
            $this->assertSame(401, $failure->getStatusCode());
        }
        $this->assertDatabaseCount('finance_transactions', 0);
        $this->assertDatabaseCount('finance_operations', 0);
    }

    public function test_restore_write_gate_blocks_background_presence_and_domain_mutations_without_touching_data(): void
    {
        $f = $this->supportFixture();
        $this->asPortalUser($f['admin']);
        DB::table('runtime_write_gate')->where('id', 1)->update(['blocked' => true]);
        $this->postJson('/api/v1/maps/heartbeat', [])->assertStatus(423);
        $this->postJson('/api/v1/maps/disconnect')->assertStatus(423);
        $this->postJson('/api/v1/support/tickets', ['recipient_id' => $f['main']->id, 'title' => 'طلب', 'description' => 'محتوى', 'idempotency_key' => 'during-restore'])->assertStatus(423);
        $this->assertDatabaseCount('user_presences', 0);
        $this->assertDatabaseCount('support_tickets', 0);
        $this->assertDatabaseCount('finance_transactions', 0);
        DB::table('runtime_write_gate')->where('id', 1)->update(['blocked' => false]);
        $this->postJson('/api/v1/maps/heartbeat', [])->assertNoContent();
        $this->assertDatabaseCount('user_presences', 1);
    }
}

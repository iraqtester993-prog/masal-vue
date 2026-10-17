<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Digital\DigitalConnection;
use App\Models\Digital\DigitalOffer;
use App\Models\Digital\DigitalOrder;
use App\Models\Digital\ProviderAttempt;
use App\Models\Operations\AccountTimePolicy;
use App\Models\PermissionProfile;
use App\Models\User;
use App\Services\Operations\AccountTimeGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesAccounts;
use Tests\TestCase;

class PosLoginDeviceTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    private function fixture(): array
    {
        $this->posLoginDeviceFixtureEnabled = false;
        $this->withCredentials()->withHeader('X-Masal-Portal', 'pos');
        $system = $this->account(AccountType::System);
        $main = $this->account(AccountType::MainAgent, $system);
        $pos = $this->account(AccountType::Pos, $main);
        $pos->update(['device_lock_enabled' => false]);

        return ['system' => $system, 'main' => $main, 'pos' => $pos, 'point' => $this->userFor($pos),
            'agent' => $this->userFor($main), 'admin' => $this->userFor($system)];
    }

    private function guest(): void
    {
        session()->invalidate();
        session()->save();
        Auth::forgetGuards();
        $this->withCookie('masal_pos_session', session()->getId())->withHeader('X-Masal-Portal', 'pos');
    }

    private function login(User $user, array $identity, bool $remember = false): void
    {
        $this->postJson('/api/v1/auth/login', $this->signedPosLogin($user, $identity, $remember))
            ->assertOk()->assertJsonPath('data.account.id', $user->membership->account_id);
        $this->withCookie('masal_pos_session', session()->getId());
    }

    private function binding(Account $account): object
    {
        return DB::table('pos_login_bindings')->where('account_id', $account->id)->first();
    }

    private function signedHeartbeat(array $identity): array
    {
        $payload = array_diff_key($identity, ['private' => true]);
        $challenge = $this->postJson('/api/v1/device/challenge', array_intersect_key($payload, array_flip(['installation_id', 'proof_public_key', 'proof_key_kind'])))
            ->assertOk()->json('data');
        $this->withCookie('masal_pos_session', session()->getId());
        openssl_sign($challenge['challenge'], $signature, $identity['private'], OPENSSL_ALGO_SHA256);

        return $payload + ['proof_challenge_id' => $challenge['challenge_id'], 'proof_signature' => base64_encode($signature)];
    }

    private function heartbeat(array $identity): void
    {
        $this->postJson('/api/v1/device/heartbeat', $this->signedHeartbeat($identity))->assertOk();
    }

    public function test_first_signed_device_is_retained_across_logout_and_refresh_and_a_second_is_denied_even_when_sales_lock_is_disabled(): void
    {
        $f = $this->fixture();
        $first = $this->posLoginIdentity();
        $this->login($f['point'], $first);
        $bound = $this->binding($f['pos']);
        $this->assertNotNull($bound->registered_device_id);
        $this->getJson('/api/v1/auth/me')->assertOk();
        $this->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->guest();

        $second = $this->posLoginIdentity();
        $this->postJson('/api/v1/auth/login', $this->signedPosLogin($f['point'], $second))
            ->assertStatus(423)->assertJsonPath('code', 'POS_LOGIN_DEVICE_MISMATCH');
        $this->assertGuest('web');
        $this->assertSame((int) $bound->registered_device_id, (int) $this->binding($f['pos'])->registered_device_id);
        $this->assertDatabaseHas('pos_registered_devices', ['account_id' => $f['pos']->id, 'identifier_hash' => hash('sha256', $second['installation_id']), 'status' => 'pending']);
        $this->guest();
        $this->login($f['point'], $first);
        $this->getJson('/api/v1/auth/me')->assertOk();
    }

    public function test_refresh_can_restore_device_session_after_location_disconnect_without_unlocking_business_mutations(): void
    {
        config(['maps.require_location' => true]);
        $f = $this->fixture();
        $identity = $this->posLoginIdentity();
        $this->login($f['point'], $identity);
        $this->postJson('/api/v1/maps/location', ['latitude' => 33.3, 'longitude' => 44.4, 'accuracy' => 10, 'recorded_at' => now()->toISOString(), 'consent_version' => 1])
            ->assertOk()->assertJsonPath('data.location_ready', true);
        $this->postJson('/api/v1/maps/disconnect')->assertNoContent();

        $this->heartbeat($identity);
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.account.id', $f['pos']->id);
        $this->assertAuthenticatedAs($f['point'], 'web');
        $this->getJson('/api/v1/maps/own')->assertOk()->assertJsonPath('data.location_ready', false);
        $this->postJson('/api/v1/sales', ['product_id' => 1, 'quantity' => 1, 'idempotency_key' => 'sale-before-location-restored'])->assertStatus(428);
        $this->postJson('/api/v1/finance/transfers', [])->assertStatus(428);
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('finance_transactions', 0);
    }

    public function test_device_bootstrap_without_location_still_rejects_incomplete_and_tampered_proof(): void
    {
        config(['maps.require_location' => true]);
        $f = $this->fixture();
        $identity = $this->posLoginIdentity();
        $this->login($f['point'], $identity);
        session()->forget(['masal.pos_login_device_id', 'masal.pos_login_key_hash', 'masal.pos_login_binding_version']);
        session()->save();
        $this->postJson('/api/v1/device/heartbeat', array_diff_key($identity, ['private' => true]))->assertUnprocessable();
        $this->getJson('/api/v1/auth/me')->assertStatus(428)->assertJsonPath('code', 'POS_LOGIN_PROOF_REQUIRED');

        $signed = $this->signedHeartbeat($identity);
        $signature = base64_decode($signed['proof_signature'], true);
        $signature[strlen($signature) - 1] = chr(ord($signature[strlen($signature) - 1]) ^ 1);
        $this->postJson('/api/v1/device/heartbeat', array_replace($signed, ['proof_signature' => base64_encode($signature)]))->assertUnprocessable();
        $this->postJson('/api/v1/device/heartbeat', $signed)->assertUnprocessable();
        $this->getJson('/api/v1/auth/me')->assertStatus(428)->assertJsonPath('code', 'POS_LOGIN_PROOF_REQUIRED');
        $this->heartbeat($identity);
        $this->getJson('/api/v1/auth/me')->assertOk();
    }

    public function test_device_bootstrap_without_location_keeps_disabled_account_and_revoked_session_checks(): void
    {
        config(['maps.require_location' => true]);
        $f = $this->fixture();
        $identity = $this->posLoginIdentity();
        $this->login($f['point'], $identity);
        $payload = array_diff_key($identity, ['private' => true]);
        $f['pos']->update(['status' => 'disabled']);
        foreach (['challenge', 'heartbeat'] as $action) {
            $this->postJson('/api/v1/device/'.$action, $payload)->assertForbidden();
        }
        $f['pos']->update(['status' => 'active']);
        $f['point']->increment('session_version');
        foreach (['challenge', 'heartbeat'] as $action) {
            $this->postJson('/api/v1/device/'.$action, $payload)->assertUnauthorized();
        }
    }

    public function test_device_bootstrap_without_location_keeps_employee_session_time_limits(): void
    {
        config(['maps.require_location' => true]);
        $f = $this->fixture();
        $employee = $this->userFor($f['pos']);
        $profile = PermissionProfile::factory()->create(['account_id' => $f['pos']->id]);
        $employee->membership->update(['kind' => 'employee', 'permission_profile_id' => $profile->id]);
        $identity = $this->posLoginIdentity();
        $this->login($employee, $identity);
        AccountTimePolicy::factory()->create(['user_id' => $employee->id, 'policy' => array_replace(AccountTimeGuard::emptyPolicy(), ['enabled' => true, 'sessionEnabled' => true, 'sessionMinutes' => 1])]);
        $this->travel(60)->seconds();

        foreach (['challenge', 'heartbeat'] as $action) {
            $this->postJson('/api/v1/device/'.$action, array_diff_key($identity, ['private' => true]))->assertUnauthorized();
            $this->assertGuest('web');
            Auth::forgetGuards();
            $this->withCookie('masal_pos_session', session()->getId());
        }
        $this->travelBack();
    }

    public function test_binding_belongs_to_the_point_account_and_another_staff_user_cannot_use_a_second_device(): void
    {
        $f = $this->fixture();
        $staff = $this->userFor($f['pos']);
        $profile = PermissionProfile::factory()->create(['account_id' => $f['pos']->id]);
        $staff->membership->update(['kind' => 'employee', 'permission_profile_id' => $profile->id]);
        $first = $this->posLoginIdentity();
        $this->login($f['point'], $first);
        $this->guest();
        $this->postJson('/api/v1/auth/login', $this->signedPosLogin($staff, $this->posLoginIdentity()))->assertStatus(423);
        $this->guest();
        $this->login($staff, $first);
        $this->assertDatabaseCount('pos_login_bindings', 1);
    }

    public function test_uuid_clone_cannot_replace_the_key_and_replayed_or_expired_login_challenges_fail(): void
    {
        $f = $this->fixture();
        $first = $this->posLoginIdentity();
        $signed = $this->signedPosLogin($f['point'], $first);
        $this->postJson('/api/v1/auth/login', $signed)->assertOk();
        $this->withCookie('masal_pos_session', session()->getId());
        $this->postJson('/api/v1/auth/login', $signed)->assertUnprocessable();
        $this->guest();
        $cloned = array_replace($this->posLoginIdentity(), ['installation_id' => $first['installation_id']]);
        $this->postJson('/api/v1/auth/login', $this->signedPosLogin($f['point'], $cloned))->assertForbidden();
        $this->assertDatabaseCount('pos_registered_devices', 1);
        $this->assertDatabaseHas('pos_registered_devices', ['proof_public_key' => $first['proof_public_key']]);
        $this->guest();
        $expired = $this->signedPosLogin($f['point'], $first);
        $this->travel(121)->seconds();
        $this->postJson('/api/v1/auth/login', $expired)->assertUnprocessable();
    }

    public function test_remember_cookie_alone_never_restores_business_access_without_current_device_proof(): void
    {
        config(['maps.require_location' => true]);
        $f = $this->fixture();
        $first = $this->posLoginIdentity();
        $cookieName = Auth::guard('web')->getRecallerName();
        $this->login($f['point'], $first, true);
        $user = $f['point']->fresh();
        $recaller = $user->id.'|'.$user->remember_token.'|'.$user->password;
        $this->guest();
        $this->withCookie($cookieName, $recaller)->getJson('/api/v1/auth/me')
            ->assertStatus(428)->assertJsonPath('code', 'POS_LOGIN_PROOF_REQUIRED');
        $this->withCookie('masal_pos_session', session()->getId());
        $this->heartbeat($first);
        $this->getJson('/api/v1/auth/me')->assertOk();
    }

    public function test_only_scoped_management_can_transfer_the_device_and_it_invalidates_every_point_user_session(): void
    {
        $f = $this->fixture();
        $staff = $this->userFor($f['pos']);
        $first = $this->posLoginIdentity();
        $second = $this->posLoginIdentity();
        $this->login($f['point'], $first, true);
        $oldBinding = $this->binding($f['pos']);
        $this->guest();
        $this->postJson('/api/v1/auth/login', $this->signedPosLogin($f['point'], $second))->assertStatus(423);
        $replacement = DB::table('pos_registered_devices')->where('identifier_hash', hash('sha256', $second['installation_id']))->first();
        $path = '/api/v1/accounts/'.$f['pos']->id.'/devices/'.$replacement->id.'/login-access';
        $input = ['version' => $replacement->version, 'binding_version' => $oldBinding->version];
        $this->guest();
        $this->login($f['point'], $first);
        $this->postJson($path, $input)->assertForbidden();
        $other = $this->account(AccountType::MainAgent, $f['system']);
        $this->asPortalUser($this->userFor($other));
        $this->postJson($path, $input)->assertNotFound();
        $versions = User::whereIn('id', [$f['point']->id, $staff->id])->pluck('session_version', 'id')->all();
        $this->asPortalUser($f['agent']);
        $this->getJson('/api/v1/accounts/'.$f['pos']->id.'/devices')->assertOk()
            ->assertJsonPath('meta.login_binding.device_id', $oldBinding->registered_device_id);
        $this->postJson($path, $input)->assertOk()->assertJsonPath('data.device_id', $replacement->id)->assertJsonPath('data.version', (int) $oldBinding->version + 1);
        $this->postJson($path, $input)->assertConflict();
        foreach ([$f['point']->id, $staff->id] as $userId) {
            $this->assertSame((int) $versions[$userId] + 1, User::findOrFail($userId)->session_version);
            $this->assertNull(User::findOrFail($userId)->remember_token);
        }
        $this->guest();
        $this->postJson('/api/v1/auth/login', $this->signedPosLogin($f['point']->fresh(), $first))->assertStatus(423);
        $this->guest();
        $this->login($f['point']->fresh(), $second);
        $this->getJson('/api/v1/auth/me')->assertOk();
    }

    public function test_existing_authenticated_session_is_rejected_after_manager_changes_the_binding(): void
    {
        $f = $this->fixture();
        $first = $this->posLoginIdentity();
        $this->login($f['point'], $first);
        $this->getJson('/api/v1/auth/me')->assertOk();
        DB::table('pos_login_bindings')->where('account_id', $f['pos']->id)->increment('version');
        $this->getJson('/api/v1/auth/me')->assertStatus(423)->assertJsonPath('code', 'POS_LOGIN_DEVICE_MISMATCH');
        $this->postJson('/api/v1/sales', ['product_id' => 1, 'quantity' => 1, 'idempotency_key' => 'sale-after-device-transfer'])
            ->assertStatus(423);
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('finance_transactions', 0);
    }

    public function test_credentials_without_device_proof_cannot_open_a_point_but_agent_login_remains_unchanged(): void
    {
        $f = $this->fixture();
        $this->postJson('/api/v1/auth/login', ['login' => $f['point']->login, 'password' => 'password'])->assertStatus(428);
        $this->assertGuest('web');
        $this->withHeader('X-Masal-Portal', 'agents')->postJson('/api/v1/auth/login', ['login' => $f['agent']->login, 'password' => 'password'])->assertOk();
    }

    public function test_device_transfer_waits_for_an_active_provider_attempt_without_revoking_or_changing_money(): void
    {
        $f = $this->fixture();
        $this->login($f['point'], $this->posLoginIdentity());
        $binding = $this->binding($f['pos']);
        $this->guest();
        $candidate = $this->posLoginIdentity();
        $this->postJson('/api/v1/auth/login', $this->signedPosLogin($f['point'], $candidate))->assertStatus(423);
        $device = DB::table('pos_registered_devices')->where('identifier_hash', hash('sha256', $candidate['installation_id']))->first();
        $connection = DigitalConnection::factory()->create(['account_id' => $f['main']->id]);
        $offer = DigitalOffer::factory()->create(['connection_id' => $connection->id]);
        $order = DigitalOrder::factory()->create(['account_id' => $f['pos']->id, 'creator_id' => $f['point']->id, 'main_account_id' => $f['main']->id, 'offer_id' => $offer->id, 'connection_id' => $connection->id]);
        $attempt = ProviderAttempt::factory()->create(['order_id' => $order->id, 'actor_id' => $f['point']->id, 'active_order_id' => $order->id]);
        $version = $f['point']->fresh()->session_version;
        $path = '/api/v1/accounts/'.$f['pos']->id.'/devices/'.$device->id.'/login-access';
        $data = ['version' => $device->version, 'binding_version' => $binding->version];
        $this->asPortalUser($f['agent']);
        $this->postJson($path, $data)->assertConflict();
        $this->assertSame((int) $binding->registered_device_id, (int) $this->binding($f['pos'])->registered_device_id);
        $this->assertSame($version, $f['point']->fresh()->session_version);
        $this->assertDatabaseCount('finance_transactions', 0);
        $attempt->update(['active_order_id' => null, 'status' => 'received', 'finished_at' => now()]);
        $this->postJson($path, $data)->assertOk()->assertJsonPath('data.device_id', $device->id);
        $this->assertDatabaseCount('finance_transactions', 0);
    }
}

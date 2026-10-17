<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\Digital\DigitalOrder;
use App\Models\Digital\ProviderAttempt;
use App\Models\Sales\Sale;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Operations\MutationGuard;
use App\Services\SessionRevoker;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Support\CreatesAccounts;
use Tests\TestCase;

class ManagedPasswordResetTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withCredentials();
        $this->freezeTime();
    }

    private function fixture(): array
    {
        $root = $this->account(AccountType::System);
        $main = $this->account(AccountType::MainAgent, $root);
        $sub = $this->account(AccountType::SubAgent, $main);
        $pos = $this->account(AccountType::Pos, $sub);
        $foreign = $this->account(AccountType::MainAgent, $root);

        return ['root' => $root, 'main' => $main, 'sub' => $sub, 'pos' => $pos, 'foreign' => $foreign,
            'admin' => $this->userFor($root), 'agent' => $this->userFor($main), 'seller' => $this->userFor($sub),
            'point' => $this->userFor($pos), 'other' => $this->userFor($foreign)];
    }

    private function input(int $version = 1): array
    {
        return ['version' => $version, 'password' => 'New-password-459!', 'password_confirmation' => 'New-password-459!'];
    }

    private function issue(User $actor, Account $target, int $version = 1, ?string $requestId = null): TestResponse
    {
        $this->asPortalUser($actor);

        return $this->postJson('/api/v1/accounts/'.$target->id.'/password-reset', ['version' => $version, 'reason' => 'Owner requested password reset']
            + ($requestId ? ['request_id' => $requestId] : []));
    }

    private function knownCookie(User $user): string
    {
        $this->asPortalUser($user);
        $name = 'masal_'.$user->membership->account->type->portal().'_password_recovery';
        $response = $this->getJson('/api/v1/auth/me')->assertOk();
        $cookie = $response->getCookie($name);
        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isSecure());
        $this->assertTrue($cookie->isHttpOnly());
        $value = $cookie->getValue();
        $this->withCookie($name, $value);

        return $value;
    }

    private function anonymous(string $portal = 'agents'): void
    {
        Auth::forgetGuards();
        Auth::guard('web')->logout();
        $this->withHeader('X-Masal-Portal', $portal);
    }

    public function test_owner_scopes_permissions_and_account_capability_are_enforced(): void
    {
        $f = $this->fixture();
        $this->asPortalUser($f['agent']);
        $this->getJson('/api/v1/accounts/'.$f['sub']->id)->assertOk()->assertJsonPath('data.can_reset_password', true);
        $this->getJson('/api/v1/accounts/'.$f['main']->id)->assertOk()->assertJsonPath('data.can_reset_password', false);
        $this->issue($f['agent'], $f['foreign'])->assertNotFound();
        $this->issue($f['agent'], $f['root'])->assertNotFound();
        $this->issue($f['agent'], $f['main'])->assertForbidden();
        $this->issue($f['point'], $f['pos'])->assertForbidden();
        $this->issue($f['agent'], $f['sub'])->assertOk();
        $this->issue($f['agent'], $f['pos'])->assertOk();
        $employee = User::factory()->create();
        AccountMembership::create(['user_id' => $employee->id, 'account_id' => $f['main']->id, 'role_id' => $f['agent']->membership->role_id, 'kind' => 'employee', 'status' => 'active']);
        $this->issue($employee, $f['sub'], 2)->assertForbidden();
        $this->issue($f['admin'], $f['main'])->assertOk()->assertJsonPath('data.account.version', 2);
    }

    public function test_active_force_flow_blocks_other_routes_and_keeps_current_session_after_completion(): void
    {
        $f = $this->fixture();
        $hash = $f['seller']->password;
        $this->issue($f['agent'], $f['sub'])->assertOk();
        $this->assertSame($hash, $f['seller']->fresh()->password);
        $this->assertSame(1, $f['seller']->fresh()->session_version);
        $this->asPortalUser($f['seller']);
        $this->getJson('/api/v1/auth/password-reset/status')->assertOk()->assertJsonPath('data.required', true)->assertJsonPath('data.version', 1);
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.password_reset.required', true);
        $this->getJson('/api/v1/accounts')->assertForbidden()->assertJsonPath('code', 'PASSWORD_CHANGE_REQUIRED');
        $this->patchJson('/api/v1/auth/profile', ['version' => 1, 'name' => 'Bypass'])->assertForbidden()->assertJsonPath('code', 'PASSWORD_CHANGE_REQUIRED');
        $this->postJson('/api/v1/auth/password-reset/complete', $this->input())->assertOk()->assertJsonPath('data.password_reset.required', false)->assertJsonPath('data.password_reset.version', 2);
        $this->assertTrue(Hash::check('New-password-459!', $f['seller']->fresh()->password));
        $this->assertSame(2, $f['seller']->fresh()->session_version);
        $this->getJson('/api/v1/auth/me')->assertOk();
        $this->getJson('/api/v1/accounts')->assertOk();
        $this->withSession(['masal.session_version' => 1])->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_stale_reset_versions_password_rules_and_audit_rollback_are_atomic(): void
    {
        $f = $this->fixture();
        $this->issue($f['agent'], $f['sub'])->assertOk();
        $this->issue($f['agent'], $f['sub'])->assertConflict();
        $this->issue($f['agent'], $f['sub'], 2)->assertOk();
        $this->asPortalUser($f['seller']);
        $this->postJson('/api/v1/auth/password-reset/complete', $this->input())->assertConflict();
        $this->postJson('/api/v1/auth/password-reset/complete', $this->input(2) + ['forged' => true])->assertOk();
        $audit = (string) DB::table('audit_logs')->where('action', 'auth.password_reset')->value('details');
        $this->assertStringNotContainsString('New-password-459!', $audit);
    }

    public function test_known_logged_out_device_is_automatically_approved_without_request_or_link(): void
    {
        $f = $this->fixture();
        $cookie = $this->knownCookie($f['seller']);
        $this->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->anonymous();
        $this->getJson('/api/v1/auth/password-reset/request-status')->assertOk()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.trusted', true);
        $this->issue($f['agent'], $f['sub'])->assertOk()->assertJsonMissingPath('data.recovery_url');
        $this->anonymous();
        $this->getJson('/api/v1/auth/password-reset/request-status')->assertOk()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.version', 1);
        $this->postJson('/api/v1/auth/password-reset/complete-request', $this->input())->assertOk()->assertJsonPath('data.completed', true);
        $this->postJson('/api/v1/auth/password-reset/complete-request', $this->input())->assertUnprocessable();
        $this->assertTrue(Hash::check('New-password-459!', $f['seller']->fresh()->password));
        $this->assertNotSame($cookie, DB::table('managed_password_reset_requests')->value('nonce_hash'));
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_unknown_identifier_and_untrusted_request_cannot_reset_without_exact_approval(): void
    {
        $f = $this->fixture();
        $this->anonymous();
        $unknown = $this->postJson('/api/v1/auth/password-reset/request', ['login' => 'not-real-user'])->assertStatus(202);
        $known = $this->postJson('/api/v1/auth/password-reset/request', ['login' => $f['seller']->login])->assertStatus(202);
        $this->assertSame(array_keys($unknown->json('data')), array_keys($known->json('data')));
        $cookie = $known->getCookie('masal_agents_password_recovery')->getValue();
        $this->withCookie('masal_agents_password_recovery', $cookie);
        $this->issue($f['agent'], $f['sub'])->assertOk();
        $this->anonymous();
        $this->getJson('/api/v1/auth/password-reset/request-status')->assertOk()->assertJsonPath('data.status', 'pending');
        $this->postJson('/api/v1/auth/password-reset/complete-request', $this->input())->assertUnprocessable();
        $this->issue($f['agent'], $f['sub'], 2, $known->json('data.request_id'))->assertOk();
        $this->anonymous();
        $this->getJson('/api/v1/auth/password-reset/request-status')->assertOk()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.version', 2);
        $this->postJson('/api/v1/auth/password-reset/complete-request', $this->input(2))->assertOk();
    }

    public function test_manager_cannot_approve_another_accounts_request_even_with_a_valid_request_id(): void
    {
        $f = $this->fixture();
        $this->anonymous();
        $request = $this->postJson('/api/v1/auth/password-reset/request', ['login' => $f['other']->login])->assertStatus(202);
        $this->issue($f['agent'], $f['sub'], 1, $request->json('data.request_id'))->assertConflict();
        $this->issue($f['agent'], $f['foreign'], 1, $request->json('data.request_id'))->assertNotFound();
        $this->assertNull($f['seller']->fresh()->password_change_required_at);
        $this->assertNull($f['other']->fresh()->password_change_required_at);
    }

    public function test_recovery_cookie_binding_portal_expiry_and_revocation_cannot_be_bypassed(): void
    {
        $f = $this->fixture();
        $cookie = $this->knownCookie($f['seller']);
        $this->issue($f['agent'], $f['sub'])->assertOk();
        $this->anonymous('admin');
        $this->getJson('/api/v1/auth/password-reset/request-status')->assertOk()->assertJsonPath('data.status', 'none');
        $this->anonymous();
        $this->withCookie('masal_agents_password_recovery', substr($cookie, 0, -1).($cookie[-1] === 'a' ? 'b' : 'a'));
        $this->getJson('/api/v1/auth/password-reset/request-status')->assertOk()->assertJsonPath('data.status', 'none');
        $this->withCookie('masal_agents_password_recovery', $cookie);
        $this->travel(16)->minutes();
        $this->getJson('/api/v1/auth/password-reset/request-status')->assertOk()->assertJsonPath('data.status', 'expired');
        $this->postJson('/api/v1/auth/password-reset/complete-request', $this->input())->assertUnprocessable();
        $this->travelBack();
        app(SessionRevoker::class)->revoke([$f['seller']->id]);
        $this->getJson('/api/v1/auth/password-reset/request-status')->assertOk()->assertJsonPath('data.status', 'none');
        $this->postJson('/api/v1/auth/password-reset/complete-request', $this->input())->assertUnprocessable();
    }

    public function test_multiple_trusted_devices_require_exact_selection_and_rotation_invalidates_previous_approval(): void
    {
        $f = $this->fixture();
        $first = $this->knownCookie($f['seller']);
        $this->withCookie('masal_agents_password_recovery', '');
        $second = $this->knownCookie($f['seller']);
        $this->issue($f['agent'], $f['sub'])->assertConflict();
        $this->getJson('/api/v1/accounts/'.$f['sub']->id)->assertOk()->assertJsonCount(2, 'data.password_reset_requests');
        $this->issue($f['agent'], $f['sub'], 1, explode('.', $first)[0])->assertOk();
        $this->anonymous();
        $this->withCookie('masal_agents_password_recovery', $first);
        $this->getJson('/api/v1/auth/password-reset/request-status')->assertOk()->assertJsonPath('data.status', 'approved');
        $this->issue($f['agent'], $f['sub'], 2, explode('.', $second)[0])->assertOk();
        $this->anonymous();
        $this->postJson('/api/v1/auth/password-reset/complete-request', $this->input())->assertUnprocessable();
        $this->withCookie('masal_agents_password_recovery', $second);
        $this->postJson('/api/v1/auth/password-reset/complete-request', $this->input(2))->assertOk();
    }

    public function test_pending_sales_printing_and_digital_work_are_not_interrupted(): void
    {
        $f = $this->fixture();
        $sale = Sale::factory()->create(['main_account_id' => $f['main']->id, 'account_id' => $f['sub']->id, 'creator_id' => $f['seller']->id]);
        $this->issue($f['agent'], $f['sub'])->assertConflict();
        $sale->update(['status' => 'Print Requested', 'print_pending' => true]);
        $this->issue($f['agent'], $f['sub'])->assertConflict();
        $sale->update(['status' => 'Cancelled', 'print_pending' => false]);
        $this->issue($f['agent'], $f['sub'])->assertOk();
        DigitalOrder::factory()->create(['main_account_id' => $f['main']->id, 'account_id' => $f['pos']->id, 'creator_id' => $f['point']->id]);
        $this->issue($f['agent'], $f['pos'])->assertConflict();
        $this->assertNull($f['point']->fresh()->password_change_required_at);
    }

    public function test_password_validation_and_csrf_required_for_public_completion(): void
    {
        $f = $this->fixture();
        $this->issue($f['agent'], $f['sub'])->assertOk();
        $f['seller']->update(['password' => '123456789']);
        $this->asPortalUser($f['seller']);
        foreach ([['password' => '12345678', 'password_confirmation' => '12345678'], ['password' => str_repeat('ا', 37), 'password_confirmation' => str_repeat('ا', 37)], ['password' => 'different-password', 'password_confirmation' => 'mismatch']] as $password) {
            $this->postJson('/api/v1/auth/password-reset/complete', array_replace($this->input(), $password))->assertUnprocessable()->assertJsonValidationErrors('password');
        }
        $this->postJson('/api/v1/auth/password-reset/complete', array_replace($this->input(), ['password' => '123456789', 'password_confirmation' => '123456789']))->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->assertTrue(Hash::check('123456789', $f['seller']->fresh()->password));
        $this->withMiddleware(PreventRequestForgery::class);
        $this->app->instance('env', 'production');
        $this->withHeader('X-Masal-Portal', 'agents');
        $this->postJson('https://agents.localhost/api/v1/auth/password-reset/complete-request', $this->input())->assertStatus(419);
    }

    public function test_audit_failure_rolls_back_flag_and_member_password(): void
    {
        $f = $this->fixture();
        $this->mock(AuditLogger::class)->shouldReceive('record')->once()->andThrow(new \RuntimeException('Audit unavailable'));
        $this->issue($f['agent'], $f['sub'])->assertStatus(500);
        $this->assertNull($f['seller']->fresh()->password_change_required_at);
        $this->assertSame(1, $f['sub']->fresh()->version);
    }

    public function test_mutation_gate_rechecks_flag_after_authorization_to_close_reset_race(): void
    {
        $f = $this->fixture();
        $stale = $f['seller']->fresh();
        $this->issue($f['agent'], $f['sub'])->assertOk();
        $this->expectException(HttpResponseException::class);
        DB::transaction(fn () => app(MutationGuard::class)->lock($stale, [], 'account.update'));
    }

    public function test_reissuing_same_trusted_request_keeps_latest_approval_usable_and_old_version_invalid(): void
    {
        $f = $this->fixture();
        $cookie = $this->knownCookie($f['seller']);
        $requestId = explode('.', $cookie)[0];
        $this->issue($f['agent'], $f['sub'], 1, $requestId)->assertOk();
        $this->issue($f['agent'], $f['sub'], 2, $requestId)->assertOk();
        $this->anonymous();
        $this->getJson('/api/v1/auth/password-reset/request-status')->assertOk()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.version', 2);
        $this->postJson('/api/v1/auth/password-reset/complete-request', $this->input())->assertUnprocessable();
        $this->postJson('/api/v1/auth/password-reset/complete-request', $this->input(2))->assertOk();
    }

    public function test_login_seeds_recovery_cookie_and_forced_change_does_not_require_location(): void
    {
        $f = $this->fixture();
        $this->anonymous();
        $login = $this->postJson('/api/v1/auth/login', ['login' => $f['seller']->login, 'password' => 'password'])->assertOk();
        $cookie = $login->getCookie('masal_agents_password_recovery');
        $this->assertNotNull($cookie);
        $this->withCookie('masal_agents_password_recovery', $cookie->getValue());
        $this->issue($f['agent'], $f['sub'])->assertOk();
        $this->asPortalUser($f['seller']);
        config(['maps.require_location' => true]);
        $this->postJson('/api/v1/auth/password-reset/complete', $this->input())->assertOk();
    }

    private function signedRecoveryDevice(array $identity): array
    {
        $fields = array_intersect_key($identity, array_flip(['installation_id', 'proof_public_key', 'proof_key_kind']));
        $challenge = $this->postJson('/api/v1/auth/password-reset/device-challenge', $fields)->assertOk()->json('data');
        $this->withCookie('masal_pos_session', session()->getId());
        openssl_sign($challenge['challenge'], $signature, $identity['private'], OPENSSL_ALGO_SHA256);

        return $fields + ['proof_challenge_id' => $challenge['challenge_id'], 'proof_signature' => base64_encode($signature)];
    }

    private function trustedPosIdentity(array $f): array
    {
        $identity = $this->posLoginIdentity();
        $this->asPortalUser($f['point']);
        $binding = DB::table('pos_login_bindings')->where('account_id', $f['pos']->id)->first();
        DB::table('pos_registered_devices')->where('id', $binding->registered_device_id)->update([
            'identifier_hash' => hash('sha256', strtolower($identity['installation_id'])), 'proof_public_key' => $identity['proof_public_key'], 'proof_key_kind' => $identity['proof_key_kind'], 'status' => 'approved']);

        return $identity;
    }

    public function test_legacy_logged_out_pos_one_manager_press_then_real_bound_device_proof_opens_form(): void
    {
        $f = $this->fixture();
        $identity = $this->trustedPosIdentity($f);
        $binding = DB::table('pos_login_bindings')->where('account_id', $f['pos']->id)->first();
        $this->issue($f['agent'], $f['pos'])->assertOk();
        $this->anonymous('pos');
        $this->getJson('/api/v1/auth/password-reset/request-status')->assertOk()->assertJsonPath('data.status', 'none');
        $signed = $this->signedRecoveryDevice($identity);
        $response = $this->postJson('/api/v1/auth/password-reset/device-bind', $signed)->assertOk()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.version', 1);
        $cookie = $response->getCookie('masal_pos_password_recovery');
        $this->withCookie('masal_pos_password_recovery', $cookie->getValue());
        $this->postJson('/api/v1/auth/password-reset/device-bind', $signed)->assertUnprocessable();
        $this->getJson('/api/v1/auth/password-reset/request-status')->assertOk()->assertJsonPath('data.status', 'approved');
        $this->postJson('/api/v1/auth/password-reset/complete-request', $this->input())->assertOk();
        $this->assertSame((int) $binding->registered_device_id, (int) DB::table('pos_login_bindings')->where('account_id', $f['pos']->id)->value('registered_device_id'));
        $this->assertGuest('web');
    }

    public function test_legacy_pos_proof_cannot_use_other_key_changed_binding_expired_or_revoked_intent(): void
    {
        $f = $this->fixture();
        $identity = $this->trustedPosIdentity($f);
        $this->issue($f['agent'], $f['pos'])->assertOk();
        $this->anonymous('pos');
        $other = $this->posLoginIdentity();
        $this->postJson('/api/v1/auth/password-reset/device-bind', $this->signedRecoveryDevice($other))->assertUnprocessable();
        DB::table('pos_login_bindings')->where('account_id', $f['pos']->id)->increment('version');
        $response = $this->postJson('/api/v1/auth/password-reset/device-bind', $this->signedRecoveryDevice($identity))->assertOk()->assertJsonPath('data.status', 'pending');
        $this->withCookie('masal_pos_password_recovery', $response->getCookie('masal_pos_password_recovery')->getValue());
        $this->postJson('/api/v1/auth/password-reset/complete-request', $this->input())->assertUnprocessable();
        $this->withCookie('masal_pos_password_recovery', '');
        $this->issue($f['agent'], $f['pos'], 2)->assertOk();
        $this->anonymous('pos');
        $this->travel(16)->minutes();
        $response = $this->postJson('/api/v1/auth/password-reset/device-bind', $this->signedRecoveryDevice($identity))->assertOk()->assertJsonPath('data.status', 'pending');
        $this->withCookie('masal_pos_password_recovery', '');
        $this->travelBack();
        $this->issue($f['agent'], $f['pos'], 3, $response->json('data.request_id'))->assertOk();
        $this->anonymous('pos');
        app(SessionRevoker::class)->revoke([$f['point']->id]);
        $this->postJson('/api/v1/auth/password-reset/device-bind', $this->signedRecoveryDevice($identity))->assertOk()->assertJsonPath('data.status', 'pending');
        DB::table('pos_registered_devices')->where('account_id', $f['pos']->id)->update(['status' => 'rejected']);
        $this->postJson('/api/v1/auth/password-reset/device-bind', $this->signedRecoveryDevice($identity))->assertUnprocessable();
    }

    #[TestWith(['expiry'])]
    #[TestWith(['session_version'])]
    #[TestWith(['reset_version'])]
    #[TestWith(['binding_version'])]
    #[TestWith(['device_rejected'])]
    public function test_captured_legacy_pos_intent_cannot_cross_security_or_expiry_changes(string $change): void
    {
        $f = $this->fixture();
        $identity = $this->trustedPosIdentity($f);
        $this->issue($f['agent'], $f['pos'])->assertOk();
        $this->anonymous('pos');
        if ($change === 'expiry') {
            $this->travel(16)->minutes();
        } elseif ($change === 'session_version') {
            app(SessionRevoker::class)->revoke([$f['point']->id]);
        } elseif ($change === 'reset_version') {
            $f['point']->increment('password_reset_version');
        } elseif ($change === 'binding_version') {
            DB::table('pos_login_bindings')->where('account_id', $f['pos']->id)->increment('version');
        } else {
            DB::table('pos_registered_devices')->where('account_id', $f['pos']->id)->update(['status' => 'rejected']);
        }
        $response = $this->postJson('/api/v1/auth/password-reset/device-bind', $this->signedRecoveryDevice($identity));
        if ($change === 'device_rejected') {
            $response->assertUnprocessable();
        } else {
            $response->assertOk()->assertJsonPath('data.status', 'pending');
            $this->withCookie('masal_pos_password_recovery', $response->getCookie('masal_pos_password_recovery')->getValue());
            $this->postJson('/api/v1/auth/password-reset/complete-request', $this->input())->assertUnprocessable();
        }
        $this->assertTrue(Hash::check('password', $f['point']->fresh()->password));
    }

    public function test_authenticated_pos_change_keeps_login_binding_and_rotates_current_cookie(): void
    {
        $f = $this->fixture();
        $oldCookie = $this->knownCookie($f['point']);
        $binding = DB::table('pos_login_bindings')->where('account_id', $f['pos']->id)->first();
        $this->issue($f['agent'], $f['pos'])->assertOk();
        $this->asPortalUser($f['point']);
        config(['maps.require_location' => true]);
        $response = $this->postJson('/api/v1/auth/password-reset/complete', $this->input())->assertOk()->assertJsonPath('data.password_reset.required', false)->assertJsonPath('data.password_reset.version', 2);
        $newCookie = $response->getCookie('masal_pos_password_recovery')->getValue();
        $this->assertNotSame($oldCookie, $newCookie);
        $this->assertSame((int) $binding->registered_device_id, (int) DB::table('pos_login_bindings')->where('account_id', $f['pos']->id)->value('registered_device_id'));
        $this->assertSame((int) $binding->version, (int) DB::table('pos_login_bindings')->where('account_id', $f['pos']->id)->value('version'));
        $this->withCookie('masal_pos_session', $response->getCookie('masal_pos_session')->getValue());
        $this->withCookie('masal_pos_password_recovery', $newCookie);
        $this->getJson('/api/v1/auth/me')->assertOk();
    }

    public function test_claim_pruning_is_bounded_and_keeps_valid_approved_requests_and_audit_has_no_secrets(): void
    {
        $f = $this->fixture();
        $cookie = $this->knownCookie($f['seller']);
        $id = explode('.', $cookie)[0];
        $this->issue($f['agent'], $f['sub'])->assertOk();
        $template = (array) DB::table('managed_password_reset_requests')->where('id', $id)->first();
        for ($index = 0; $index < 105; $index++) {
            DB::table('managed_password_reset_requests')->insert(array_replace($template, ['id' => (string) Str::uuid(), 'user_id' => null, 'account_id' => null,
                'expires_at' => now()->subMinutes(2), 'approval_expires_at' => null, 'approved_at' => null, 'trusted' => false]));
        }
        $this->anonymous();
        $this->withCookie('masal_agents_password_recovery', '');
        $this->postJson('/api/v1/auth/password-reset/request', ['login' => 'not-real-user'])->assertStatus(202);
        $this->assertSame(5, DB::table('managed_password_reset_requests')->where('expires_at', '<', now())->count());
        $this->assertDatabaseHas('managed_password_reset_requests', ['id' => $id, 'consumed_at' => null]);
        $this->withCookie('masal_agents_password_recovery', $cookie);
        $this->postJson('/api/v1/auth/password-reset/complete-request', $this->input())->assertOk();
        $audit = json_decode((string) DB::table('audit_logs')->where('action', 'auth.password_reset')->value('details'), true);
        $this->assertSame($id, $audit['request_id']);
        $this->assertSame($f['agent']->id, $audit['approved_by']);
        $serialized = json_encode($audit);
        $this->assertStringNotContainsString('New-password-459!', $serialized);
        $this->assertStringNotContainsString(explode('.', $cookie)[1], $serialized);
        $this->assertStringNotContainsString($template['nonce_hash'], $serialized);
    }

    public function test_active_or_unresolved_provider_refund_blocks_reset_until_latest_result_is_settled(): void
    {
        $f = $this->fixture();
        $order = DigitalOrder::factory()->create(['main_account_id' => $f['main']->id, 'account_id' => $f['pos']->id, 'creator_id' => $f['point']->id,
            'status' => 'succeeded', 'reservation_active' => false, 'acknowledged_at' => now(), 'cost_basis' => 'catalog_snapshot', 'actual_cost_minor' => 430000,
            'actual_retail_minor' => 500000, 'company_transaction_id' => 'test-provider-txn', 'receipt_ref' => 'test-provider-receipt', 'resolved_at' => now()]);
        $attempt = ProviderAttempt::factory()->create(['order_id' => $order->id, 'actor_id' => $f['point']->id, 'active_order_id' => $order->id, 'operation' => 'refund-status']);
        $this->issue($f['agent'], $f['pos'])->assertConflict();
        $attempt->update(['active_order_id' => null, 'status' => 'unknown', 'finished_at' => now()]);
        $this->issue($f['agent'], $f['pos'])->assertConflict();
        ProviderAttempt::factory()->create(['order_id' => $order->id, 'actor_id' => $f['point']->id, 'operation' => 'refund-status', 'status' => 'received', 'finished_at' => now()]);
        $this->issue($f['agent'], $f['pos'])->assertOk();
    }
}

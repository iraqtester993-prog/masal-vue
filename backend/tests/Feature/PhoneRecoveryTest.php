<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Tests\Support\CreatesAccounts;
use Tests\TestCase;

class PhoneRecoveryTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['auth.phone_recovery_test_mode' => true]);
        $this->withHeader('X-Masal-Portal', 'agents');
        $this->withCredentials();
    }

    private function owner(): User
    {
        $system = $this->account(AccountType::System);
        $account = $this->account(AccountType::MainAgent, $system);
        $account->forceFill(['phone' => '07701234567'])->save();

        return $this->userFor($account)->fresh();
    }

    private function issue(string $phone = '+964 770 123 4567'): string
    {
        $response = $this->postJson('/api/v1/auth/recovery/phone', ['phone' => $phone])->assertOk()->assertJsonPath('data.test_mode', true);
        $this->withCookie('masal_agents_session', session()->getId());

        return $response->json('data.challenge');
    }

    private function payload(string $challenge, string $code = '123456'): array
    {
        return ['challenge' => $challenge, 'code' => $code, 'password' => 'New-password-459!', 'password_confirmation' => 'New-password-459!'];
    }

    public function test_phone_recovery_changes_password_revokes_old_sessions_and_consumes_challenge(): void
    {
        $user = $this->owner();
        $version = $user->session_version;
        $challenge = $this->issue('٠٧٧٠١٢٣٤٥٦٧');
        $state = Cache::get('phone-recovery:'.hash_hmac('sha256', $challenge, config('app.key')));
        $this->assertSame($user->id, $state['user_id']);
        $this->assertSame(hash_hmac('sha256', session()->getId(), config('app.key')), $state['session']);
        $this->postJson('/api/v1/auth/recovery/reset', $this->payload($challenge))->assertOk();
        $this->assertTrue(Hash::check('New-password-459!', $user->fresh()->password));
        $this->assertSame($version + 1, $user->fresh()->session_version);
        $this->postJson('/api/v1/auth/recovery/reset', $this->payload($challenge))->assertUnprocessable();
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.password_reset', 'user_id' => $user->id]);
    }

    public function test_wrong_codes_exhaust_challenge_without_changing_password(): void
    {
        $user = $this->owner();
        $hash = $user->password;
        $challenge = $this->issue();
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/recovery/reset', $this->payload($challenge, '000000'))->assertUnprocessable();
        }
        $this->postJson('/api/v1/auth/recovery/reset', $this->payload($challenge))->assertUnprocessable();
        $this->assertSame($hash, $user->fresh()->password);
    }

    public function test_password_policy_and_browser_session_are_required(): void
    {
        $user = $this->owner();
        $challenge = $this->issue();
        $weak = $this->payload($challenge);
        $weak['password'] = $weak['password_confirmation'] = '12345678';
        $this->postJson('/api/v1/auth/recovery/reset', $weak)->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->withCookie('masal_agents_session', str_repeat('x', 40));
        $this->postJson('/api/v1/auth/recovery/reset', $this->payload($challenge))->assertUnprocessable();
        $this->assertSame($user->password, $user->fresh()->password);
    }

    public function test_recovery_accepts_a_nine_digit_password_without_requiring_letters(): void
    {
        $user = $this->owner();
        $payload = $this->payload($this->issue());
        $payload['password'] = $payload['password_confirmation'] = '123456789';
        $this->postJson('/api/v1/auth/recovery/reset', $payload)->assertOk();
        $this->assertTrue(Hash::check('123456789', $user->fresh()->password));
    }

    public function test_expired_challenge_is_rejected(): void
    {
        $this->owner();
        $challenge = $this->issue();
        $this->travel(6)->minutes();
        $this->postJson('/api/v1/auth/recovery/reset', $this->payload($challenge))->assertUnprocessable();
    }

    public function test_changed_phone_or_session_version_invalidates_challenge(): void
    {
        $user = $this->owner();
        $challenge = $this->issue();
        $user->membership->account->forceFill(['phone' => '07701234568'])->save();
        $this->postJson('/api/v1/auth/recovery/reset', $this->payload($challenge))->assertUnprocessable();
        $challenge = $this->issue('07701234568');
        $user->increment('session_version');
        $this->postJson('/api/v1/auth/recovery/reset', $this->payload($challenge))->assertUnprocessable();
    }

    public function test_unknown_phone_has_same_issue_response_but_cannot_reset(): void
    {
        $challenge = $this->issue();
        $this->postJson('/api/v1/auth/recovery/reset', $this->payload($challenge))->assertUnprocessable();
    }

    public function test_challenge_is_bound_to_portal(): void
    {
        $this->owner();
        $challenge = $this->issue();
        $this->withHeader('X-Masal-Portal', 'pos')->postJson('/api/v1/auth/recovery/reset', $this->payload($challenge))->assertUnprocessable();
    }

    public function test_fixed_code_is_disabled_on_production_even_when_configured(): void
    {
        $this->withoutMiddleware(PreventRequestForgery::class);
        $this->app->instance('env', 'production');
        $this->postJson('http://agents.localhost/api/v1/auth/recovery/phone', ['phone' => '07701234567'])->assertStatus(503);
        $this->postJson('http://agents.localhost/api/v1/auth/recovery/reset', $this->payload(str_repeat('x', 64)))->assertStatus(503);
    }

    public function test_disabled_mode_and_invalid_phone_do_not_issue_challenges(): void
    {
        $this->postJson('/api/v1/auth/recovery/phone', ['phone' => '123'])->assertUnprocessable();
        config(['auth.phone_recovery_test_mode' => false]);
        $this->postJson('/api/v1/auth/recovery/phone', ['phone' => '07701234567'])->assertStatus(503);
    }

    public function test_ambiguous_number_does_not_reset_either_account(): void
    {
        $first = $this->owner();
        $system = $first->membership->account->parent;
        $other = $this->account(AccountType::MainAgent, $system);
        $other->forceFill(['phone' => '+9647701234567'])->save();
        $second = $this->userFor($other);
        $challenge = $this->issue();
        $this->postJson('/api/v1/auth/recovery/reset', $this->payload($challenge))->assertUnprocessable();
        $this->assertSame($first->password, $first->fresh()->password);
        $this->assertSame($second->password, $second->fresh()->password);
    }
}

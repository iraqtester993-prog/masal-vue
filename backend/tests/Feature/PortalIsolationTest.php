<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Models\Account;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CreatesAccounts;
use Tests\TestCase;

class PortalIsolationTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    public static function portalCases(): array
    {
        return [
            'system' => [AccountType::System, 'admin', 'agents'],
            'main agent' => [AccountType::MainAgent, 'agents', 'admin'],
            'sub agent' => [AccountType::SubAgent, 'agents', 'pos'],
            'sub branch' => [AccountType::SubBranch, 'agents', 'admin'],
            'point of sale' => [AccountType::Pos, 'pos', 'agents'],
        ];
    }

    private function accountForType(AccountType $type): Account
    {
        $root = $this->account(AccountType::System);
        if ($type === AccountType::System) {
            return $root;
        }
        $main = $this->account(AccountType::MainAgent, $root);
        if ($type === AccountType::MainAgent) {
            return $main;
        }
        if ($type === AccountType::Pos) {
            return $this->account(AccountType::Pos, $main);
        }
        $sub = $this->account(AccountType::SubAgent, $main);

        return $type === AccountType::SubAgent ? $sub : $this->account(AccountType::SubBranch, $sub);
    }

    #[DataProvider('portalCases')]
    public function test_login_only_accepts_the_accounts_own_portal(AccountType $type, string $portal, string $wrongPortal): void
    {
        $user = $this->userFor($this->accountForType($type));
        $this->withHeader('X-Masal-Portal', $wrongPortal)->postJson('/api/v1/auth/login', [
            'login' => $user->login, 'password' => 'password',
        ])->assertUnprocessable()->assertJsonValidationErrors('login');
        $this->assertGuest('web');
        $guestSessionId = session()->getId();
        $response = $this->withHeader('X-Masal-Portal', $portal)->postJson('/api/v1/auth/login', [
            'login' => $user->login, 'password' => 'password', 'role' => 'admin', 'account_id' => 999,
        ])->assertOk()->assertJsonPath('data.portal', $portal)
            ->assertJsonPath('data.account.type', $type->value)
            ->assertJsonMissingPath('data.user.password')
            ->assertSessionHas('masal.portal', $portal);
        $this->assertContains('account.view', $response->json('data.permissions'));
        $this->assertSame($type !== AccountType::Pos, in_array('account.create', $response->json('data.permissions'), true));
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login', 'user_id' => $user->id, 'portal' => $portal]);
        $this->assertNotSame($guestSessionId, session()->getId());
    }

    #[DataProvider('portalCases')]
    public function test_authenticated_session_cannot_switch_portals(AccountType $type, string $portal, string $wrongPortal): void
    {
        $user = $this->userFor($this->accountForType($type));
        $this->asPortalUser($user);
        $this->getJson('/api/v1/auth/me')->assertOk();
        $this->withHeader('X-Masal-Portal', $wrongPortal)->getJson('/api/v1/auth/me')->assertForbidden();
    }

    public function test_session_is_bound_to_the_original_portal(): void
    {
        $user = $this->userFor($this->accountForType(AccountType::System));
        $this->asPortalUser($user);
        $this->withSession(['masal.portal' => 'agents'])->getJson('/api/v1/auth/me')->assertForbidden();
    }

    public function test_missing_authentication_is_unauthorized(): void
    {
        $this->withHeader('X-Masal-Portal', 'agents')->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->getJson('/api/v1/accounts')->assertUnauthorized();
    }

    public function test_invalid_credentials_are_generic_and_rate_limited(): void
    {
        $this->freezeTime();
        $user = $this->userFor($this->accountForType(AccountType::System));
        $this->withHeader('X-Masal-Portal', 'admin');
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/v1/auth/login', ['login' => $user->login, 'password' => 'wrong'])
                ->assertUnprocessable()->assertJsonValidationErrors('login');
        }
        $this->postJson('/api/v1/auth/login', ['login' => $user->login, 'password' => 'password'])
            ->assertTooManyRequests()->assertHeader('Retry-After');
        $this->assertGuest('web');
        $this->assertDatabaseCount('audit_logs', 5);
        $this->assertStringNotContainsString('wrong', json_encode(DB::table('audit_logs')->get()));
    }

    public function test_unknown_user_gets_same_failure_as_wrong_password(): void
    {
        $user = $this->userFor($this->accountForType(AccountType::System));
        $known = $this->withHeader('X-Masal-Portal', 'admin')->postJson('/api/v1/auth/login', ['login' => $user->login, 'password' => 'wrong']);
        $unknown = $this->postJson('/api/v1/auth/login', ['login' => 'does-not-exist', 'password' => 'wrong']);
        $known->assertUnprocessable();
        $unknown->assertUnprocessable();
        $this->assertSame($known->json(), $unknown->json());
    }

    public function test_login_requires_both_fields(): void
    {
        $this->withHeader('X-Masal-Portal', 'admin')->postJson('/api/v1/auth/login', [])
            ->assertUnprocessable()->assertJsonValidationErrors(['login', 'password']);
    }

    public function test_login_rejects_non_string_credentials_without_a_server_error(): void
    {
        $this->withHeader('X-Masal-Portal', 'admin')->postJson('/api/v1/auth/login', ['login' => ['unexpected'], 'password' => ['unexpected']])
            ->assertUnprocessable()->assertJsonValidationErrors(['login', 'password']);
    }

    public function test_email_login_selects_email_column_even_when_another_login_matches(): void
    {
        $root = $this->accountForType(AccountType::System);
        $user = $this->userFor($root);
        $other = $this->userFor($root);
        $other->update(['login' => $user->email]);
        $this->withHeader('X-Masal-Portal', 'admin')->postJson('/api/v1/auth/login', ['login' => strtoupper($user->email), 'password' => 'password'])
            ->assertOk()->assertJsonPath('data.user.id', $user->id);
    }

    public function test_bearer_tokens_cannot_replace_session_login(): void
    {
        $this->withHeader('X-Masal-Portal', 'admin')->withHeader('Authorization', 'Bearer unsupported-token')
            ->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_revoked_user_can_still_log_out_their_own_portal_session(): void
    {
        $user = $this->userFor($this->accountForType(AccountType::System));
        $user->update(['status' => 'disabled']);
        $this->asPortalUser($user);
        $this->postJson('/api/v1/auth/logout')->assertNoContent()->assertSessionMissing('masal.portal');
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.logout', 'user_id' => $user->id]);
    }

    public function test_logout_refuses_a_session_bound_to_another_portal(): void
    {
        $user = $this->userFor($this->accountForType(AccountType::System));
        $this->asPortalUser($user);
        $this->withHeader('X-Masal-Portal', 'agents')->postJson('/api/v1/auth/logout')->assertForbidden();
        $this->assertDatabaseMissing('audit_logs', ['action' => 'auth.logout']);
    }

    public function test_logout_invalidates_session_and_audits_the_action(): void
    {
        $user = $this->userFor($this->accountForType(AccountType::System));
        $this->withHeader('X-Masal-Portal', 'admin')->postJson('/api/v1/auth/login', ['login' => $user->login, 'password' => 'password'])->assertOk();
        $sessionId = session()->getId();
        $this->postJson('/api/v1/auth/logout')->assertNoContent()->assertSessionMissing('masal.portal');
        $this->assertNotSame($sessionId, session()->getId());
        Auth::forgetGuards();
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.logout', 'user_id' => $user->id]);
    }

    public function test_csrf_cookies_are_host_only_and_sessions_have_distinct_portal_names(): void
    {
        config(['session.domain' => '.dananir-iq.com']);
        foreach (['admin', 'agents', 'pos'] as $portal) {
            $response = $this->withHeader('X-Masal-Portal', $portal)->get('/sanctum/csrf-cookie')->assertNoContent();
            $sessionCookie = $response->getCookie('masal_'.$portal.'_session');
            $this->assertNotNull($sessionCookie);
            $this->assertNull($sessionCookie->getDomain());
            $this->assertTrue($sessionCookie->isHttpOnly());
            $this->assertNull($response->getCookie('XSRF-TOKEN')->getDomain());
        }
    }

    public function test_unknown_host_is_refused_and_production_ignores_portal_header(): void
    {
        $this->app->instance('env', 'production');
        config(['portals.hosts' => ['admin' => 'admin.example.test', 'agents' => 'agents.example.test', 'pos' => 'pos.example.test']]);
        $this->withHeader('X-Masal-Portal', 'admin')->getJson('https://unknown.example.test/api/v1/auth/me')->assertForbidden();
        $response = $this->get('https://agents.example.test/sanctum/csrf-cookie')->assertNoContent();
        $response->assertCookie('masal_agents_session')->assertCookieMissing('masal_admin_session');
    }

    public function test_post_login_requires_csrf_when_not_running_under_test_environment(): void
    {
        $this->app->instance('env', 'local');
        $this->withHeader('X-Masal-Portal', 'admin')->postJson('/api/v1/auth/login', ['login' => 'unknown', 'password' => 'wrong'])->assertStatus(419);
    }
}

<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RecoveryThrottleIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Http::preventStrayRequests();
        $this->freezeTime();
        $this->withHeader('X-Masal-Portal', 'agents');
        $this->withCredentials();
    }

    public function test_five_status_polls_do_not_block_the_first_recovery_request(): void
    {
        for ($index = 0; $index < 5; $index++) {
            $this->getJson('/api/v1/auth/password-reset/request-status')->assertOk()->assertJsonPath('data.status', 'none');
        }

        $this->postJson('/api/v1/auth/password-reset/request', ['login' => 'unknown-recovery-fixture'])->assertAccepted()->assertJsonPath('data.status', 'pending');

        $this->assertDatabaseCount('managed_password_reset_requests', 1);
        $this->assertDatabaseHas('managed_password_reset_requests', ['user_id' => null, 'portal' => 'agents']);
    }

    public function test_sixth_recovery_request_returns_429_without_creating_another_request(): void
    {
        for ($index = 0; $index < 5; $index++) {
            $this->postJson('/api/v1/auth/password-reset/request', ['login' => 'unknown-recovery-fixture'])->assertAccepted();
        }

        $this->postJson('/api/v1/auth/password-reset/request', ['login' => 'unknown-recovery-fixture'])->assertTooManyRequests()->assertHeader('Retry-After');

        $this->assertDatabaseCount('managed_password_reset_requests', 5);
    }

    public function test_sixty_first_status_poll_returns_429(): void
    {
        for ($index = 0; $index < 60; $index++) {
            $this->getJson('/api/v1/auth/password-reset/request-status')->assertOk();
        }

        $this->getJson('/api/v1/auth/password-reset/request-status')->assertTooManyRequests()->assertHeader('Retry-After');

        $this->assertDatabaseCount('managed_password_reset_requests', 0);
    }

    public function test_status_polling_does_not_spend_the_completion_attempts(): void
    {
        for ($index = 0; $index < 10; $index++) {
            $this->getJson('/api/v1/auth/password-reset/request-status')->assertOk();
        }

        for ($index = 0; $index < 10; $index++) {
            $this->postJson('/api/v1/auth/password-reset/complete-request', [])->assertUnprocessable()->assertJsonValidationErrors(['version', 'password']);
        }

        $this->postJson('/api/v1/auth/password-reset/complete-request', [])->assertTooManyRequests();

        $this->assertDatabaseCount('managed_password_reset_requests', 0);
        $this->assertDatabaseCount('users', 0);
    }
}

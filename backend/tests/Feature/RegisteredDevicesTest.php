<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Models\Sales\DeviceSession;
use App\Services\Sales\SalesAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\CreatesAccounts;
use Tests\TestCase;

class RegisteredDevicesTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    private function actors(): array
    {
        $this->posLoginDeviceFixtureEnabled = false;
        $system = $this->account(AccountType::System);
        $main = $this->account(AccountType::MainAgent, $system);
        $pos = $this->account(AccountType::Pos, $main);

        return ['system' => $system, 'main' => $main, 'pos' => $pos, 'admin' => $this->userFor($system), 'agent' => $this->userFor($main), 'point' => $this->userFor($pos)];
    }

    private mixed $proofPrivate = null;

    private function heartbeat(array $payload): TestResponse
    {
        $this->withCredentials();
        $this->proofPrivate ??= openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $public = preg_replace('/-----[^-]+-----|\s/', '', openssl_pkey_get_details($this->proofPrivate)['key']);
        $payload += ['proof_public_key' => $public, 'proof_key_kind' => 'webcrypto'];
        $challenge = $this->postJson('/api/v1/device/challenge', array_intersect_key($payload, array_flip(['installation_id', 'proof_public_key', 'proof_key_kind'])))->assertOk()->json('data');
        $this->withCookie('masal_pos_session', app('session')->driver()->getId());
        openssl_sign($challenge['challenge'], $signature, $this->proofPrivate, OPENSSL_ALGO_SHA256);

        return $this->postJson('/api/v1/device/heartbeat', $payload + ['proof_challenge_id' => $challenge['challenge_id'], 'proof_signature' => base64_encode($signature)]);
    }

    private function payload(): array
    {
        return ['installation_id' => (string) Str::uuid(), 'kind' => 'pos', 'model' => 'SUNMI V2', 'manufacturer' => 'SUNMI', 'os_version' => '7.1', 'app_version' => '1.1.0'];
    }

    private function assertDeviceAccess(array $tree, int $registeredId, bool $allowed): void
    {
        $tree['point'] = $tree['point']->fresh();
        $session = app('session.store');
        $request = Request::create('/');
        $request->setLaravelSession($session);
        DeviceSession::updateOrCreate(['session_hash' => hash('sha256', $session->getId())], ['account_id' => $tree['pos']->id, 'user_id' => $tree['point']->id, 'session_version' => $tree['point']->session_version, 'registered_device_id' => $registeredId ?: null, 'proof_key_hash' => $registeredId ? hash('sha256', DB::table('pos_registered_devices')->where('id', $registeredId)->value('proof_public_key') ?? '') : null, 'proof_verified_at' => now(), 'app_version' => '1.1.0', 'expires_at' => now()->addMinute(), 'last_seen_at' => now()]);
        try {
            app(SalesAccess::class)->device($tree['point'], $tree['pos']->fresh(), $request, ['min_app_version' => '1.0.0']);
            $this->assertTrue($allowed, 'An unapproved device must not pass the sales/printing device gate.');
        } catch (HttpException $exception) {
            $this->assertFalse($allowed, $exception->getMessage());
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    public function test_device_registration_is_idempotent_and_approval_and_revocation_control_sales_access(): void
    {
        $tree = $this->actors();
        $payload = $this->payload();
        $this->asPortalUser($tree['point']);
        $first = $this->heartbeat($payload)->assertOk()->assertJsonPath('data.allowed', false)->assertJsonPath('data.device.status', 'pending')->json('data.device');
        $this->heartbeat($payload)->assertOk()->assertJsonPath('data.device.id', $first['id']);
        $this->assertSame(1, DB::table('pos_registered_devices')->count());
        $this->assertArrayNotHasKey('identifier_hash', $first);
        $this->assertArrayNotHasKey('installation_id', $first);
        $this->assertDeviceAccess($tree, $first['id'], false);
        $path = '/api/v1/accounts/'.$tree['pos']->id.'/devices/'.$first['id'];
        $this->patchJson($path, ['version' => $first['version'], 'status' => 'approved'])->assertForbidden();
        $this->asPortalUser($tree['agent']);
        $this->getJson('/api/v1/accounts/'.$tree['pos']->id.'/devices')->assertOk()->assertJsonPath('data.0.model', 'SUNMI V2');
        $this->patchJson($path, ['version' => $first['version'], 'status' => 'approved'])->assertOk()->assertJsonPath('data.status', 'approved');
        $this->patchJson($path, ['version' => $first['version'], 'status' => 'revoked'])->assertConflict();
        $this->assertDeviceAccess($tree, $first['id'], true);
        $this->assertDeviceAccess($tree, 0, false);
        $this->asPortalUser($tree['admin']);
        $this->patchJson($path, ['version' => $first['version'] + 1, 'status' => 'revoked'])->assertOk();
        $this->assertDeviceAccess($tree, $first['id'], false);
        $this->asPortalUser($tree['point']);
        $this->heartbeat($payload)->assertOk()->assertJsonPath('data.allowed', false)->assertJsonPath('data.device.status', 'revoked');
    }

    public function test_unlocked_points_can_sell_and_a_different_agent_cannot_approve_their_devices(): void
    {
        $tree = $this->actors();
        $tree['pos']->update(['device_lock_enabled' => false]);
        $this->asPortalUser($tree['point']);
        $row = $this->heartbeat($this->payload())->assertOk()->assertJsonPath('data.allowed', true)->json('data.device');
        $this->assertDeviceAccess($tree, $row['id'], true);
        $other = $this->account(AccountType::MainAgent, $tree['system']);
        $this->asPortalUser($this->userFor($other));
        $this->getJson('/api/v1/accounts/'.$tree['pos']->id.'/devices')->assertNotFound();
        $this->patchJson('/api/v1/accounts/'.$tree['pos']->id.'/devices/'.$row['id'], ['version' => $row['version'], 'status' => 'approved'])->assertNotFound();
        $this->asPortalUser($tree['point']);
        $this->postJson('/api/v1/device/heartbeat', ['app_version' => '1.1.0'])->assertUnprocessable();
    }
}

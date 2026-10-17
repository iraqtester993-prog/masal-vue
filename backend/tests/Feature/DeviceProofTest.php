<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\CreatesAccounts;
use Tests\TestCase;

class DeviceProofTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    private function fixture(): array
    {
        $this->posLoginDeviceFixtureEnabled = false;
        $this->withCredentials();
        $system = $this->account(AccountType::System);
        $main = $this->account(AccountType::MainAgent, $system);
        $pos = $this->account(AccountType::Pos, $main);
        $point = $this->userFor($pos);
        $agent = $this->userFor($main);
        $this->asPortalUser($point);
        $private = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $pem = openssl_pkey_get_details($private)['key'];
        $public = preg_replace('/-----[^-]+-----|\s/', '', $pem);
        $payload = ['installation_id' => (string) Str::uuid(), 'app_version' => '1.4.0', 'proof_public_key' => $public, 'proof_key_kind' => 'webcrypto'];

        return compact('system', 'main', 'pos', 'point', 'agent', 'private', 'payload');
    }

    private function signed(array $payload, mixed $private): array
    {
        $challenge = $this->postJson('/api/v1/device/challenge', array_intersect_key($payload, array_flip(['installation_id', 'proof_public_key', 'proof_key_kind'])))->assertOk()->json('data');
        $this->withCookie('masal_pos_session', app('session')->driver()->getId());
        openssl_sign($challenge['challenge'], $signature, $private, OPENSSL_ALGO_SHA256);

        return $payload + ['proof_challenge_id' => $challenge['challenge_id'], 'proof_signature' => base64_encode($signature)];
    }

    public function test_proof_is_session_bound_single_use_and_uuid_clone_cannot_inherit_approval(): void
    {
        $tree = $this->fixture();
        $signed = $this->signed($tree['payload'], $tree['private']);
        $row = $this->postJson('/api/v1/device/heartbeat', $signed)->assertOk()->assertJsonPath('data.allowed', false)->json('data.device');
        $this->postJson('/api/v1/device/heartbeat', $signed)->assertUnprocessable();
        $this->asPortalUser($tree['agent']);
        $this->patchJson('/api/v1/accounts/'.$tree['pos']->id.'/devices/'.$row['id'], ['version' => $row['version'], 'status' => 'approved'])->assertOk();
        $this->asPortalUser($tree['point']);
        $this->postJson('/api/v1/device/heartbeat', $this->signed($tree['payload'], $tree['private']))->assertOk()->assertJsonPath('data.allowed', true);
        $legacy = array_diff_key($tree['payload'], array_flip(['proof_public_key', 'proof_key_kind']));
        $this->postJson('/api/v1/device/heartbeat', $legacy + ['model' => 'cloned'])->assertOk()->assertJsonPath('data.allowed', false);
        $this->assertDatabaseMissing('pos_registered_devices', ['id' => $row['id'], 'model' => 'cloned']);
        $other = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $changed = array_replace($tree['payload'], ['proof_public_key' => preg_replace('/-----[^-]+-----|\s/', '', openssl_pkey_get_details($other)['key'])]);
        $this->postJson('/api/v1/device/heartbeat', $this->signed($changed, $other))->assertForbidden();
    }

    public function test_old_approved_uuid_requires_reapproval_when_a_new_key_is_enrolled(): void
    {
        $tree = $this->fixture();
        $legacy = array_diff_key($tree['payload'], array_flip(['proof_public_key', 'proof_key_kind']));
        $row = $this->postJson('/api/v1/device/heartbeat', $legacy)->assertOk()->json('data.device');
        DB::table('pos_registered_devices')->where('id', $row['id'])->update(['status' => 'approved']);
        $this->postJson('/api/v1/device/heartbeat', $this->signed($tree['payload'], $tree['private']))->assertOk()->assertJsonPath('data.allowed', false)->assertJsonPath('data.device.status', 'pending');
    }

    public function test_a_valid_signature_from_another_session_cannot_enroll_the_device(): void
    {
        $tree = $this->fixture();
        $signed = $this->signed($tree['payload'], $tree['private']);
        $session = app('session')->driver();
        $original = $session->getId();
        $session->migrate(true);
        $session->save();
        $this->assertNotSame($original, $session->getId());
        $this->withCookie('masal_pos_session', $session->getId());

        $this->postJson('/api/v1/device/heartbeat', $signed)->assertUnprocessable();
        $this->assertDatabaseCount('pos_registered_devices', 0);
        $this->postJson('/api/v1/device/heartbeat', $this->signed($tree['payload'], $tree['private']))->assertOk();
    }

    public function test_expired_challenge_cannot_enroll_but_a_fresh_challenge_can(): void
    {
        $tree = $this->fixture();
        $signed = $this->signed($tree['payload'], $tree['private']);
        $this->travel(121)->seconds();

        $this->postJson('/api/v1/device/heartbeat', $signed)->assertUnprocessable();
        $this->assertDatabaseCount('pos_registered_devices', 0);
        $this->postJson('/api/v1/device/heartbeat', $this->signed($tree['payload'], $tree['private']))->assertOk();
    }

    public function test_tampered_signature_is_rejected_and_burns_the_challenge(): void
    {
        $tree = $this->fixture();
        $signed = $this->signed($tree['payload'], $tree['private']);
        $signature = base64_decode($signed['proof_signature'], true);
        $signature[strlen($signature) - 1] = chr(ord($signature[strlen($signature) - 1]) ^ 1);
        $tampered = array_replace($signed, ['proof_signature' => base64_encode($signature)]);

        $this->postJson('/api/v1/device/heartbeat', $tampered)->assertUnprocessable();
        $this->assertDatabaseCount('pos_registered_devices', 0);
        $this->postJson('/api/v1/device/heartbeat', $signed)->assertUnprocessable();
        $this->postJson('/api/v1/device/heartbeat', $this->signed($tree['payload'], $tree['private']))->assertOk();
    }

    public function test_revoked_devices_do_not_consume_registration_quota_and_archive_requires_management(): void
    {
        $tree = $this->fixture();
        for ($index = 0; $index < 30; $index++) {
            DB::table('pos_registered_devices')->insert(['account_id' => $tree['pos']->id, 'identifier_hash' => hash('sha256', 'old-'.$index), 'kind' => 'browser', 'app_version' => '1.3.0', 'status' => 'revoked', 'version' => 1, 'last_seen_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }
        $row = $this->postJson('/api/v1/device/heartbeat', $this->signed($tree['payload'], $tree['private']))->assertOk()->json('data.device');
        $path = '/api/v1/accounts/'.$tree['pos']->id.'/devices/'.$row['id'];
        $this->patchJson($path, ['version' => $row['version'], 'status' => 'archived'])->assertForbidden();
        $this->asPortalUser($tree['agent']);
        $this->patchJson($path, ['version' => $row['version'], 'status' => 'archived'])->assertUnprocessable();
        $old = DB::table('pos_registered_devices')->where('identifier_hash', hash('sha256', 'old-0'))->first();
        $this->patchJson('/api/v1/accounts/'.$tree['pos']->id.'/devices/'.$old->id, ['version' => $old->version, 'status' => 'archived'])->assertOk();
        $this->getJson('/api/v1/accounts/'.$tree['pos']->id.'/devices')->assertOk()->assertJsonPath('meta.total', 30);
    }
}

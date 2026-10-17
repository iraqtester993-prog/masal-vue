<?php

namespace Tests\Support;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\User;
use App\Services\AccountHierarchy;
use Database\Seeders\FoundationPermissionsSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

trait CreatesAccounts
{
    protected bool $posLoginDeviceFixtureEnabled = true;

    protected function account(AccountType $type, ?Account $parent = null): Account
    {
        return app(AccountHierarchy::class)->create('Account '.fake()->unique()->numerify('######'), $type, $parent);
    }

    protected function userFor(Account $account): User
    {
        $this->seed(FoundationPermissionsSeeder::class);
        $user = User::factory()->create();
        $role = $account->type->portal() === 'agents' ? 'agent' : $account->type->portal();
        AccountMembership::create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'role_id' => DB::table('roles')->where('name', $role)->value('id'),
            'status' => 'active',
        ]);

        return $user;
    }

    protected function asPortalUser(User $user): void
    {
        Auth::forgetGuards();
        $user = $user->fresh();
        $portal = $user->membership->account->type->portal();
        $this->actingAs($user, 'web')->withSession(['masal.portal' => $portal, 'masal.session_version' => $user->session_version])
            ->withHeader('X-Masal-Portal', $portal);
        if ($portal === 'pos' && $this->posLoginDeviceFixtureEnabled) {
            $this->trustPosLoginDeviceFixture($user);
        }
    }

    protected function trustPosLoginDeviceFixture(User $user): void
    {
        $accountId = $user->membership->account_id;
        $binding = DB::table('pos_login_bindings')->where('account_id', $accountId)->first();
        if (! $binding || ! $binding->registered_device_id) {
            $publicKey = $this->posLoginIdentity()['proof_public_key'];
            $deviceId = DB::table('pos_registered_devices')->insertGetId([
                'account_id' => $accountId, 'identifier_hash' => hash('sha256', 'trusted-pos-test-fixture-'.$accountId),
                'kind' => 'browser', 'model' => 'Trusted test fixture', 'app_version' => '1.4.0', 'status' => 'approved', 'version' => 1,
                'proof_public_key' => $publicKey, 'proof_key_kind' => 'webcrypto', 'proof_enrolled_at' => now(),
                'last_seen_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('pos_login_bindings')->updateOrInsert(['account_id' => $accountId], [
                'registered_device_id' => $deviceId, 'version' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $binding = DB::table('pos_login_bindings')->where('account_id', $accountId)->first();
        }
        $device = DB::table('pos_registered_devices')->where('id', $binding->registered_device_id)->first();
        $this->withSession([
            'masal.pos_login_device_id' => $device->id,
            'masal.pos_login_key_hash' => hash('sha256', $device->proof_public_key),
            'masal.pos_login_binding_version' => (int) $binding->version,
        ]);
        app('session')->driver()->save();
        $this->withCookie('masal_pos_session', app('session')->driver()->getId());
    }

    protected function posLoginIdentity(): array
    {
        $private = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $this->assertNotFalse($private, 'The test environment must configure OPENSSL_CONF for EC device keys.');

        return ['private' => $private, 'installation_id' => (string) Str::uuid(), 'app_version' => '1.4.0',
            'proof_public_key' => preg_replace('/-----[^-]+-----|\s/', '', openssl_pkey_get_details($private)['key']), 'proof_key_kind' => 'webcrypto'];
    }

    protected function signedPosLogin(User $user, array $identity, bool $remember = false): array
    {
        $this->withCredentials()->withHeader('X-Masal-Portal', 'pos');
        $credentials = ['login' => $user->login, 'password' => 'password', 'remember' => $remember];
        $private = $identity['private'];
        $identity = array_diff_key($identity, ['private' => true]);
        $challenge = $this->postJson('/api/v1/auth/device-challenge', $credentials + $identity)->assertOk()->json('data');
        $this->withCookie('masal_pos_session', app('session')->driver()->getId());

        openssl_sign($challenge['challenge'], $signature, $private, OPENSSL_ALGO_SHA256);

        return $credentials + $identity + ['proof_challenge_id' => $challenge['challenge_id'], 'proof_signature' => base64_encode($signature)];
    }
}

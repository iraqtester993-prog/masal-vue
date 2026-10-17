<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\Support\CreatesAccounts;
use Tests\TestCase;

class SessionRefreshNativeTest extends TestCase
{
    use CreatesAccounts;

    private function fingerprint(): string
    {
        $hash = hash_init('sha256');
        foreach (['finance_wallets', 'finance_transactions', 'finance_entries', 'finance_operations', 'finance_invoices', 'topup_grants', 'topup_wallet_bindings', 'topup_finance_events', 'digital_connections', 'digital_orders', 'digital_provider_attempts', 'sales', 'stock_cards', 'users', 'audit_logs', 'user_presences', 'pos_registered_devices', 'pos_login_bindings', 'sales_device_sessions'] as $table) {
            hash_update($hash, $table);
            $key = match ($table) {
                'topup_wallet_bindings' => 'wallet_id',
                'pos_login_bindings' => 'account_id',
                default => 'id',
            };
            foreach (DB::table($table)->lazyById(500, $key) as $row) {
                hash_update($hash, json_encode($row, JSON_THROW_ON_ERROR));
            }
        }

        return hash_final($hash);
    }

    private function financialFingerprint(): string
    {
        $hash = hash_init('sha256');
        foreach (['finance_transactions', 'finance_entries', 'finance_operations', 'digital_orders', 'sales', 'stock_cards'] as $table) {
            hash_update($hash, $table);
            foreach (DB::table($table)->lazyById(500) as $row) {
                hash_update($hash, json_encode($row, JSON_THROW_ON_ERROR));
            }
        }

        return hash_final($hash);
    }

    public function test_three_signed_refreshes_retain_the_same_user_after_location_disconnect_on_the_verified_native_source(): void
    {
        if (getenv('MASAL_SESSION_REFRESH_NATIVE') !== '1') {
            $this->markTestSkipped('Requires the isolated MariaDB synthetic point fixture and exclusive QA execution.');
        }
        $this->assertTrue(app()->environment('testing'));
        $this->assertSame('mysql', DB::getDriverName());
        $this->assertSame('dananiriq_masalverify', DB::connection()->getDatabaseName());
        $this->assertSame('/home/dananiriq/masal-verification/finance-stock-20261006-01', realpath(base_path()));
        $this->assertSame(base_path('storage'), realpath(storage_path()));
        $this->assertSame('array', config('session.driver'));
        $this->assertSame('array', config('cache.default'));
        $expected = getenv('MASAL_SESSION_REFRESH_SOURCE_SHA256');
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/D', is_string($expected) ? $expected : '');
        $this->assertSame($expected, hash_file('sha256', app_path('Http/Middleware/EnsureOperationalRequest.php')));
        $this->assertSame(0, DB::table('users')->where('email', 'not like', '%@example.invalid')->count());
        $point = User::where('login', 'scale-point-1')->firstOrFail();
        $this->assertSame('scale-point-1@example.invalid', $point->email);
        $this->assertSame('owner', $point->membership->kind);
        $this->assertSame(AccountType::Pos, $point->membership->account->type);
        $this->assertTrue($point->isOperational());
        $this->assertTrue(Hash::check('password', $point->password));
        $this->assertFalse(DB::table('digital_provider_attempts')->whereNotNull('active_order_id')->exists());
        Http::preventStrayRequests();
        config(['maps.require_location' => true]);
        $this->posLoginDeviceFixtureEnabled = false;
        $before = $this->fingerprint();
        $financialBefore = $this->financialFingerprint();
        DB::beginTransaction();
        try {
            DB::table('pos_login_bindings')->where('account_id', $point->membership->account_id)->delete();
            $identity = $this->posLoginIdentity();
            $this->postJson('/api/v1/auth/login', $this->signedPosLogin($point, $identity))->assertOk();
            $this->withCookie('masal_pos_session', session()->getId());
            for ($reload = 1; $reload <= 3; $reload++) {
                $this->postJson('/api/v1/maps/location', ['latitude' => 33.3, 'longitude' => 44.4, 'accuracy' => 10, 'recorded_at' => now()->toISOString(), 'consent_version' => $reload])
                    ->assertOk()->assertJsonPath('data.location_ready', true);
                $this->postJson('/api/v1/maps/disconnect')->assertNoContent();
                Auth::forgetGuards();
                $payload = array_diff_key($identity, ['private' => true]);
                $challenge = $this->postJson('/api/v1/device/challenge', array_intersect_key($payload, array_flip(['installation_id', 'proof_public_key', 'proof_key_kind'])))
                    ->assertOk()->json('data');
                openssl_sign($challenge['challenge'], $signature, $identity['private'], OPENSSL_ALGO_SHA256);
                $this->postJson('/api/v1/device/heartbeat', $payload + ['proof_challenge_id' => $challenge['challenge_id'], 'proof_signature' => base64_encode($signature)])
                    ->assertOk()->assertJsonPath('data.login_allowed', true);
                $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.user.id', $point->id)->assertJsonPath('data.account.id', $point->membership->account_id);
                $this->assertAuthenticatedAs($point, 'web');
                $this->getJson('/api/v1/maps/own')->assertOk()->assertJsonPath('data.location_ready', false)->assertJsonPath('data.consent_version', $reload + 1);
                $this->postJson('/api/v1/sales', ['product_id' => 1, 'quantity' => 1, 'idempotency_key' => 'native-refresh-before-location-'.$reload])->assertStatus(428);
                $this->postJson('/api/v1/finance/transfers', [])->assertStatus(428);
                $this->assertSame($financialBefore, $this->financialFingerprint());
            }
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            Auth::forgetGuards();
        }
        $this->assertSame($before, $this->fingerprint());
        fwrite(STDOUT, 'NATIVE_SESSION_REFRESH '.json_encode(['middleware_sha256' => $expected, 'reloads' => 3, 'same_user_retained' => true, 'location_required_for_business' => true, 'financial_inventory_unchanged' => true, 'synthetic_changes_rolled_back' => true], JSON_THROW_ON_ERROR).PHP_EOL);
    }
}

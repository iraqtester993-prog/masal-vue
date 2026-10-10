<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Models\AccountMembership;
use App\Models\Maps\UserPresence;
use App\Models\PermissionProfile;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\ManagementAuthority;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesAccounts;
use Tests\TestCase;

class MapsPresenceTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    private function tree(): array
    {
        $system = $this->account(AccountType::System);
        $main = $this->account(AccountType::MainAgent, $system);
        $sub = $this->account(AccountType::SubAgent, $main);
        $pos = $this->account(AccountType::Pos, $sub);
        $foreign = $this->account(AccountType::MainAgent, $system);
        $admin = $this->userFor($system);
        $agent = $this->userFor($main);
        $branch = $this->userFor($sub);
        $seller = $this->userFor($pos);
        $outsider = $this->userFor($foreign);

        return compact('system', 'main', 'sub', 'pos', 'foreign', 'admin', 'agent', 'branch', 'seller', 'outsider');
    }

    private function grant(User $user, string $permission = 'map.view', bool $allowed = true): void
    {
        DB::table('membership_permissions')->updateOrInsert(['membership_id' => $user->membership->id, 'permission_id' => DB::table('permissions')->where('name', $permission)->value('id')], ['allowed' => $allowed]);
    }

    private function presence(User $user, array $changes = []): UserPresence
    {
        return UserPresence::create(array_replace(['user_id' => $user->id, 'session_hash' => hash('sha256', fake()->uuid()), 'session_version' => $user->fresh()->session_version, 'connected' => true, 'last_seen_at' => now(), 'latitude' => 33.30, 'longitude' => 44.40, 'accuracy' => 10, 'location_at' => now()], $changes));
    }

    public function test_guests_denied_and_sensitive_map_is_default_off_but_explicitly_delegable_inside_scope(): void
    {
        $this->withHeader('X-Masal-Portal', 'admin')->getJson('/api/v1/maps/users')->assertUnauthorized();
        $t = $this->tree();
        $this->asPortalUser($t['agent']);
        $this->getJson('/api/v1/maps/users')->assertForbidden();
        $this->assertContains('map.view', app(ManagementAuthority::class)->permissionLimits($t['admin'], $t['agent']->membership));
        $this->grant($t['agent']);
        $this->asPortalUser($t['agent']);
        $reply = $this->getJson('/api/v1/maps/users')->assertOk()->assertJsonCount(3, 'data');
        $this->assertNotContains($t['outsider']->id, array_column($reply->json('data'), 'id'));
        $this->assertSame('', $reply->json('data.0.parentName'));
        $this->getJson('/api/v1/maps/users?branch_id='.$t['foreign']->id)->assertNotFound();
        $this->getJson('/api/v1/maps/users?branch_id='.$t['sub']->id)->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_online_uses_real_recent_valid_sessions_and_preserves_last_location_without_fake_positions(): void
    {
        $t = $this->tree();
        $this->presence($t['agent'], ['last_seen_at' => now()->subSeconds(120)]);
        $this->presence($t['branch'], ['connected' => false]);
        $this->presence($t['seller'], ['session_version' => $t['seller']->fresh()->session_version + 1]);
        $this->asPortalUser($t['admin']);
        $response = $this->getJson('/api/v1/maps/users')->assertOk()->assertJsonCount(4, 'data');
        $this->assertNotContains($t['admin']->id, array_column($response->json('data'), 'id'));
        foreach ($response->json('data') as $row) {
            $this->assertFalse($row['online']);
            $this->assertArrayNotHasKey('session_hash', $row);
            $this->assertArrayNotHasKey('password', $row);
        }
        $this->getJson('/api/v1/maps/users?status=without_location')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $t['outsider']->id)->assertJsonPath('data.0.location', null);
        $this->presence($t['seller']);
        $this->getJson('/api/v1/maps/users?status=online')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $t['seller']->id)->assertJsonPath('data.0.location.lat', 33.3);
        $t['main']->update(['status' => 'disabled']);
        $this->getJson('/api/v1/maps/users?status=online')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_any_active_account_can_heartbeat_and_share_only_own_valid_coordinates_without_map_grant(): void
    {
        $t = $this->tree();
        $this->asPortalUser($t['seller']);
        $this->getJson('/api/v1/maps/own')->assertOk()->assertJsonPath('data.location_required', true)->assertJsonPath('data.location_ready', false);
        $this->withCredentials()->withCookie('masal_pos_session', session()->getId());
        $this->postJson('/api/v1/maps/heartbeat', ['device_model' => 'Recorded Device', 'app_version' => '1.0'])->assertNoContent();
        $this->postJson('/api/v1/maps/location', ['latitude' => 33.3, 'longitude' => 44.4, 'accuracy' => 12, 'recorded_at' => now()->toISOString(), 'consent_version' => 1])->assertOk()->assertJsonPath('data.location_ready', true);
        $this->getJson('/api/v1/maps/own')->assertOk()->assertJsonPath('data.location_ready', true);
        $this->assertDatabaseHas('user_presences', ['user_id' => $t['seller']->id, 'connected' => true, 'latitude' => 33.3]);
        $this->postJson('/api/v1/maps/location', ['latitude' => 33.3, 'longitude' => 44.4, 'accuracy' => 12, 'recorded_at' => now()->toISOString(), 'consent_version' => 1, 'user_id' => $t['outsider']->id])->assertUnprocessable();
        $this->postJson('/api/v1/maps/location', ['latitude' => 86, 'longitude' => 44.4, 'accuracy' => 12, 'recorded_at' => now()->subMinutes(6)->toISOString(), 'consent_version' => 1])->assertUnprocessable();
        $this->postJson('/api/v1/maps/disconnect')->assertNoContent();
        $this->getJson('/api/v1/maps/own')->assertOk()->assertJsonPath('data.location_ready', false);
    }

    public function test_fixed_location_is_scoped_versioned_private_and_audit_failure_rolls_back(): void
    {
        $t = $this->tree();
        $this->asPortalUser($t['agent']);
        $this->putJson('/api/v1/maps/accounts/'.$t['foreign']->id.'/location', ['latitude' => 33.3, 'longitude' => 44.4, 'version' => 0])->assertNotFound();
        $this->putJson('/api/v1/maps/accounts/'.$t['pos']->id.'/location', ['latitude' => 33.3, 'longitude' => 44.4, 'version' => 0])->assertOk()->assertJsonPath('data.version', 1);
        $this->putJson('/api/v1/maps/accounts/'.$t['pos']->id.'/location', ['latitude' => 35.3, 'longitude' => 44.4, 'version' => 0])->assertConflict();
        $this->mock(AuditLogger::class)->shouldReceive('record')->once()->andThrow(new \RuntimeException('Simulated audit failure'));
        $this->putJson('/api/v1/maps/accounts/'.$t['pos']->id.'/location', ['latitude' => 35.3, 'longitude' => 44.4, 'version' => 1])->assertStatus(500);
        $this->assertDatabaseHas('account_locations', ['account_id' => $t['pos']->id, 'latitude' => 33.3, 'version' => 1]);
        $this->grant($t['agent']);
        $this->asPortalUser($t['agent']);
        $this->getJson('/api/v1/maps/users?type=pos')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.source', 'موقع الحساب المسجل');
    }

    public function test_employee_coordinates_are_visible_only_when_their_complete_scope_is_allowed(): void
    {
        $t = $this->tree();
        $profile = PermissionProfile::create(['account_id' => $t['main']->id, 'name' => 'Scoped', 'normalized_name' => 'scoped', 'status' => 'active']);
        $employee = User::factory()->create();
        $membership = AccountMembership::create(['user_id' => $employee->id, 'account_id' => $t['main']->id, 'role_id' => DB::table('roles')->where('name', 'employee')->value('id'), 'status' => 'active', 'kind' => 'employee', 'permission_profile_id' => $profile->id, 'include_descendants' => true]);
        DB::table('membership_scope_roots')->insert(['membership_id' => $membership->id, 'account_id' => $t['sub']->id]);
        $this->presence($employee);
        $this->grant($t['agent']);
        $this->asPortalUser($t['agent']);
        $this->getJson('/api/v1/maps/users?type=employee')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $employee->id);
        $this->grant($t['agent'], 'staff.view', false);
        $this->asPortalUser($t['agent']);
        $this->getJson('/api/v1/maps/users?type=employee')->assertOk()->assertJsonCount(0, 'data');
        $this->grant($t['agent'], 'staff.view');
        DB::table('membership_scope_roots')->insert(['membership_id' => $membership->id, 'account_id' => $t['foreign']->id]);
        $this->asPortalUser($t['agent']);
        $this->getJson('/api/v1/maps/users?type=employee')->assertOk()->assertJsonCount(0, 'data');
    }
}

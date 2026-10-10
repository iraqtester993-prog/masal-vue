<?php

namespace Tests\Feature;

use App\Services\ManagementAuthority;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesSupport;
use Tests\TestCase;

class AccountDelegationIntegrationTest extends TestCase
{
    use CreatesSupport, RefreshDatabase;

    public function test_map_permission_can_be_granted_through_real_account_api_and_remains_scoped(): void
    {
        $f = $this->supportFixture();
        $this->asPortalUser($f['agent']);
        $this->getJson('/api/v1/maps/users')->assertForbidden();
        $before = $f['agent']->fresh()->membership->permissions();
        $this->asPortalUser($f['admin']);
        $this->patchJson('/api/v1/accounts/'.$f['main']->id.'/permissions', ['version' => $f['main']->version, 'permissions' => array_merge($before, ['map.view']), 'reason' => 'تفويض الخريطة'])->assertOk();
        $this->asPortalUser($f['agent']);
        $reply = $this->getJson('/api/v1/maps/users')->assertOk();
        $this->assertNotContains($f['foreignUser']->id, array_column($reply->json('data'), 'id'));
        $this->getJson('/api/v1/maps/users?branch_id='.$f['foreign']->id)->assertNotFound();
    }

    public function test_agent_can_restore_own_pos_digital_execution_grants_without_acquiring_them_personally(): void
    {
        $f = $this->supportFixture();
        foreach (['digital.create', 'digital.receipt'] as $permission) {
            DB::table('account_permission_rules')->insert(['target_account_id' => $f['pos']->id, 'authority_account_id' => $f['main']->id, 'permission_id' => DB::table('permissions')->where('name', $permission)->value('id'), 'allowed' => false]);
        }
        $actor = $f['agent']->fresh();
        $seller = $f['posUser']->fresh();
        $this->assertNotContains('digital.create', $actor->membership->permissions());
        $limits = app(ManagementAuthority::class)->permissionLimits($actor, $seller->membership);
        $this->assertContains('digital.create', $limits);
        $before = $seller->membership->permissions();
        $this->asPortalUser($actor);
        $this->patchJson('/api/v1/accounts/'.$f['pos']->id.'/permissions', ['version' => $f['pos']->version, 'permissions' => array_merge($before, ['digital.create', 'digital.receipt']), 'reason' => 'تفعيل الخدمة المخصصة'])->assertOk();
        $this->assertContains('digital.create', $seller->fresh()->membership->permissions());
        $this->assertNotContains('digital.create', $actor->fresh()->membership->permissions());
    }

    public function test_higher_authority_denial_and_missing_assignment_grant_prevent_digital_redelegation(): void
    {
        $f = $this->supportFixture();
        DB::table('account_permission_rules')->insert(['target_account_id' => $f['pos']->id, 'authority_account_id' => $f['system']->id, 'permission_id' => DB::table('permissions')->where('name', 'digital.create')->value('id'), 'allowed' => false]);
        $actor = $f['agent']->fresh();
        $owner = $f['posUser']->fresh()->membership;
        $this->assertNotContains('digital.create', app(ManagementAuthority::class)->permissionLimits($actor, $owner));
        $this->asPortalUser($actor);
        $this->patchJson('/api/v1/accounts/'.$f['pos']->id.'/permissions', ['version' => $f['pos']->version, 'permissions' => array_merge($owner->permissions(), ['digital.create']), 'reason' => 'تجاوز المنع'])->assertUnprocessable();
        DB::table('account_permission_rules')->delete();
        $this->supportGrant($actor, 'digital.assign', false);
        $this->assertNotContains('digital.create', app(ManagementAuthority::class)->permissionLimits($actor->fresh(), $owner));
        $this->assertSame(1, $f['pos']->fresh()->version);
    }
}

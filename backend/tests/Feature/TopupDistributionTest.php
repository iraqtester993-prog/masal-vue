<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Models\Digital\CatalogSnapshot;
use App\Models\Digital\DigitalConnection;
use App\Models\Digital\TopupCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\CreatesSupport;
use Tests\TestCase;

class TopupDistributionTest extends TestCase
{
    use CreatesSupport, RefreshDatabase;

    private function category(array $f, string $name = 'تعبئة 5000'): TopupCategory
    {
        $connection = DigitalConnection::factory()->create(['account_id' => $f['main']->id, 'provider' => 'topup', 'provider_id' => null, 'credential' => 'private-main-token']);
        $snapshot = CatalogSnapshot::factory()->create(['connection_id' => $connection->id]);

        return TopupCategory::create(['connection_id' => $connection->id, 'catalog_snapshot_id' => $snapshot->id, 'remote_id' => 'ASIA-5000', 'name' => $name, 'type' => 'topup', 'cost_minor' => 500000, 'retail_minor' => 500000, 'active' => true, 'version' => 1]);
    }

    private function grant(User $user, int $target, array $ids, int $version = 0, bool $active = true): array
    {
        $this->asPortalUser($user);
        $payload = ['category_ids' => $ids, 'active' => $active, 'version' => $version, 'idempotency_key' => 'topup-grant-'.$user->id.'-'.$target.'-'.$version];
        $this->putJson('/api/v1/topup/distribution/'.$target, $payload)->assertOk()->assertJsonPath('data.version', $version + 1);

        return $payload;
    }

    public function test_main_token_and_categories_flow_through_each_level_and_pos_at_any_level(): void
    {
        Http::preventStrayRequests();
        $f = $this->supportFixture();
        $category = $this->category($f);
        $ids = [$category->id];
        $this->grant($f['admin'], $f['main']->id, $ids);
        $this->grant($f['agent'], $f['sub']->id, $ids);
        $this->grant($f['subUser'], $f['branch']->id, $ids);
        $this->grant($f['branchUser'], $f['pos']->id, $ids);
        foreach ([$f['main'], $f['sub'], $f['branch']] as $parent) {
            $pos = $this->account(AccountType::Pos, $parent);
            $owner = $parent->id === $f['main']->id ? $f['agent'] : ($parent->id === $f['sub']->id ? $f['subUser'] : $f['branchUser']);
            $this->grant($owner, $pos->id, $ids);
            $this->asPortalUser($this->userFor($pos));
            $response = $this->getJson('/api/v1/topup/available')->assertOk()
                ->assertJsonPath('data.categories.0.price', '5000.00')
                ->assertJsonPath('data.categories.0.id', $category->id)
                ->assertJsonPath('data.connection.main_account_id', $f['main']->id)
                ->assertJsonPath('data.connection.token_present', true)
                ->assertJsonPath('data.execution_ready', false);
            $this->assertStringNotContainsString('private-main-token', $response->getContent());
            $response->assertJsonMissingPath('data.connection.company_balance');
        }
        $this->assertDatabaseCount('digital_connections', 1);
        $this->assertDatabaseCount('digital_orders', 0);
        $this->assertDatabaseCount('digital_offers', 0);
        Http::assertNothingSent();
    }

    public function test_upstream_revocation_pause_and_category_disable_remove_pos_availability(): void
    {
        $f = $this->supportFixture();
        $category = $this->category($f);
        $ids = [$category->id];
        $this->grant($f['admin'], $f['main']->id, $ids);
        $this->grant($f['agent'], $f['sub']->id, $ids);
        $this->grant($f['subUser'], $f['branch']->id, $ids);
        $this->grant($f['branchUser'], $f['pos']->id, $ids);
        $this->grant($f['admin'], $f['main']->id, [], 1);
        $this->asPortalUser($f['posUser']);
        $this->getJson('/api/v1/topup/available')->assertOk()->assertJsonCount(0, 'data.categories');
        $this->asPortalUser($f['agent']);
        $this->getJson('/api/v1/topup/distribution')->assertOk()->assertJsonCount(0, 'data.categories');
        $this->putJson('/api/v1/topup/distribution/'.$f['sub']->id, ['category_ids' => $ids, 'active' => true, 'version' => 1, 'idempotency_key' => 'revoked-cannot-regrant'])->assertUnprocessable();
        $this->grant($f['admin'], $f['main']->id, $ids, 2);
        $this->grant($f['agent'], $f['sub']->id, $ids, 1, false);
        $this->asPortalUser($f['posUser']);
        $this->getJson('/api/v1/topup/available')->assertOk()->assertJsonCount(0, 'data.categories');
        $this->grant($f['agent'], $f['sub']->id, $ids, 2, true);
        $category->update(['active' => false]);
        $this->asPortalUser($f['posUser']);
        $this->getJson('/api/v1/topup/available')->assertOk()->assertJsonCount(0, 'data.categories');
    }

    public function test_target_scope_permissions_and_main_only_token_boundary(): void
    {
        $f = $this->supportFixture();
        $ids = [$this->category($f)->id];
        $payload = ['category_ids' => $ids, 'active' => true, 'version' => 0, 'idempotency_key' => 'outside-scope-001'];
        $this->asPortalUser($f['agent']);
        foreach ([$f['foreign']->id, $f['branch']->id, $f['pos']->id, $f['main']->id] as $target) {
            $this->getJson('/api/v1/topup/distribution?target_account_id='.$target)->assertNotFound();
            $this->putJson('/api/v1/topup/distribution/'.$target, $payload)->assertNotFound();
        }
        $this->asPortalUser($f['admin']);
        $this->putJson('/api/v1/topup/distribution/'.$f['sub']->id, $payload)->assertNotFound();
        $this->postJson('/api/v1/digital/connections', ['provider' => 'topup', 'account_id' => $f['sub']->id, 'version' => 0, 'credential' => 'no-child-token', 'offers' => [], 'active' => true, 'idempotency_key' => 'no-child-token-001'])->assertNotFound();
        foreach ([$f['posUser'], $this->supportEmployee($f['system'], $f['system'], ['account.view', 'integrations.view', 'integrations.edit'])] as $u) {
            $this->asPortalUser($u);
            $this->getJson('/api/v1/topup/distribution')->assertForbidden();
            $this->putJson('/api/v1/topup/distribution/'.$f['main']->id, $payload)->assertForbidden();
        }
        $this->asPortalUser($this->supportEmployee($f['main'], $f['pos'], ['account.view', 'digital.view', 'digital.assign']));
        $this->getJson('/api/v1/topup/distribution')->assertNotFound();
        $this->assertDatabaseCount('topup_grants', 0);
        $this->assertDatabaseCount('digital_connections', 1);
    }

    public function test_idempotency_versions_and_invalid_assignments_do_not_create_extra_grants(): void
    {
        $f = $this->supportFixture();
        $category = $this->category($f);
        $p = $this->grant($f['admin'], $f['main']->id, [$category->id]);
        $this->putJson('/api/v1/topup/distribution/'.$f['main']->id, $p)->assertOk()->assertJsonPath('data.version', 1);
        $this->assertDatabaseCount('topup_grants', 1);
        $this->putJson('/api/v1/topup/distribution/'.$f['main']->id, array_replace($p, ['idempotency_key' => 'stale-topup-001']))->assertConflict();
        $this->putJson('/api/v1/topup/distribution/'.$f['main']->id, array_replace($p, ['active' => false]))->assertConflict();
        foreach ([[999999], [$category->id, $category->id]] as $ids) {
            $this->putJson('/api/v1/topup/distribution/'.$f['main']->id, array_replace($p, ['category_ids' => $ids, 'version' => 1, 'idempotency_key' => 'invalid-topup-001']))->assertUnprocessable();
        }
        $this->getJson('/api/v1/topup/distribution?target_account_id='.$f['main']->id)->assertOk()->assertJsonPath('data.connection.token_present', true)->assertJsonPath('data.grant.category_ids.0', $category->id);
        $this->putJson('/api/v1/topup/distribution/'.$f['main']->id, $p + ['credential' => 'must-not-be-accepted'])->assertUnprocessable();
        $this->assertDatabaseHas('topup_grants', ['main_account_id' => $f['main']->id, 'target_account_id' => $f['main']->id, 'version' => 1]);
    }
}

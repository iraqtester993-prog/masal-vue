<?php

namespace Tests\Feature;

use App\Models\Digital\CatalogSnapshot;
use App\Models\Digital\DigitalConnection;
use App\Models\Digital\DigitalOffer;
use App\Models\Digital\DigitalOrder;
use App\Models\Digital\TopupCategory;
use App\Models\Digital\TopupGrant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\CreatesSupport;
use Tests\TestCase;

class TopupCategoryTest extends TestCase
{
    use CreatesSupport, RefreshDatabase;

    public function test_manual_creation_and_edit_routes_are_removed_and_previous_categories_are_hidden(): void
    {
        $f = $this->supportFixture();
        $this->asPortalUser($f['admin']);
        Http::preventStrayRequests();
        $manual = TopupCategory::create(['name' => 'يدوية', 'type' => 'topup', 'use_name' => true, 'cost_minor' => 500000, 'retail_minor' => 500000, 'active' => true]);
        $this->postJson('/api/v1/topup/categories', ['name' => '5000', 'price' => '5000'])->assertMethodNotAllowed();
        $this->putJson('/api/v1/topup/categories/'.$manual->id, ['active' => true])->assertNotFound();
        $this->getJson('/api/v1/topup/categories')->assertOk()->assertJsonCount(0, 'data');
        $this->putJson('/api/v1/topup/distribution/'.$f['main']->id, ['category_ids' => [$manual->id], 'active' => true, 'version' => 0, 'idempotency_key' => 'manual-category-blocked'])->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_read_only_company_categories_follow_connection_scope_and_token_rotation(): void
    {
        $f = $this->supportFixture();
        $this->asPortalUser($f['admin']);
        Http::preventStrayRequests();
        $connection = DigitalConnection::factory()->create(['account_id' => $f['main']->id, 'provider' => 'topup']);
        $snapshot = CatalogSnapshot::factory()->create(['connection_id' => $connection->id]);
        TopupCategory::create(['name' => 'آسيا 5000', 'type' => 'topup', 'connection_id' => $connection->id, 'catalog_snapshot_id' => $snapshot->id, 'remote_id' => 'ASIA-5000', 'cost_minor' => 500000, 'retail_minor' => 500000, 'active' => true]);
        $this->getJson('/api/v1/topup/categories?connection_id='.$connection->id)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.remote_id', 'ASIA-5000')->assertJsonPath('data.0.main_account_name', $f['main']->name)->assertJsonMissingPath('data.0.use_name');
        $this->getJson('/api/v1/topup/categories?connection_id=999999')->assertNotFound();
        $connection->update(['credential' => 'rotated-key']);
        $this->getJson('/api/v1/topup/categories')->assertOk()->assertJsonCount(0, 'data');
        Http::assertNothingSent();
    }

    public function test_retirement_migration_removes_manual_grants_and_preserves_order_history(): void
    {
        $manual = TopupCategory::create(['name' => 'يدوية', 'type' => 'topup', 'use_name' => true, 'cost_minor' => 500000, 'retail_minor' => 500000, 'active' => true, 'version' => 1]);
        $offer = DigitalOffer::factory()->create(['topup_category_id' => $manual->id, 'manual_category_version' => 1]);
        $order = DigitalOrder::factory()->create(['offer_id' => $offer->id, 'provider' => 'topup', 'manual_category_name' => true]);
        $grant = TopupGrant::factory()->create(['main_account_id' => $order->main_account_id, 'target_account_id' => $order->account_id, 'from_account_id' => $order->account->parent_id, 'category_ids' => [$manual->id]]);
        $history = $order->fresh()->getAttributes();
        $migration = require database_path('migrations/2026_10_08_112520_retire_manual_topup_categories.php');
        $migration->up();
        $this->assertFalse($manual->fresh()->active);
        $this->assertFalse($offer->fresh()->active);
        $this->assertTrue($offer->fresh()->listed);
        $this->assertSame([], $grant->fresh()->category_ids);
        $this->assertSame($history, $order->fresh()->getAttributes());
        $this->assertDatabaseCount('digital_orders', 1);
        $this->assertDatabaseCount('topup_categories', 1);
    }

    public function test_non_owner_cannot_read_the_company_catalog(): void
    {
        $f = $this->supportFixture();
        foreach ([$f['agent'], $f['posUser'], $this->supportEmployee($f['system'], $f['system'], ['account.view', 'integrations.view', 'integrations.edit'])] as $user) {
            $this->asPortalUser($user);
            $this->getJson('/api/v1/topup/categories')->assertForbidden();
        }
    }
}

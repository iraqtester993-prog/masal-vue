<?php

namespace Tests\Feature;

use App\Models\Digital\CatalogSnapshot;
use App\Models\Digital\DigitalConnection;
use App\Models\Digital\DigitalOffer;
use App\Models\Digital\DigitalOrder;
use App\Models\Digital\TopupCategory;
use App\Models\Digital\TopupGrant;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Support\CreatesSupport;
use Tests\TestCase;

class TopupCatalogTest extends TestCase
{
    use CreatesSupport, RefreshDatabase;

    private array $products = [];

    private ?array $purchaseResponse = null;

    private string $inventory = '100000';

    private bool $invalidCatalog = false;

    private ?int $revokeTarget = null;

    private bool $rejectEligibility = false;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['digital.topup.driver' => 'masal_v2_1', 'digital.purchases_enabled' => true]);
        Http::fake(function ($request) {
            if (str_ends_with($request->url(), '/inventory')) {
                return Http::response($this->inventory, 200, ['Content-Type' => 'application/json']);
            }
            if (str_ends_with($request->url(), '/products')) {
                return Http::response($this->invalidCatalog ? ['status' => 'error'] : ['status' => 'ok', 'products' => $this->products]);
            }
            if (str_ends_with($request->url(), '/checkEligibility')) {
                if ($this->rejectEligibility) {
                    return Http::response(['message' => 'Unknown category'], 400);
                }
                if ($this->revokeTarget !== null) {
                    TopupGrant::where('target_account_id', $this->revokeTarget)->update(['active' => false]);
                }

                return Http::response(['status' => true]);
            }

            return Http::response($this->purchaseResponse ?? ['id' => 'confirmed-topup-001', 'status' => 'Succeed']);
        });
    }

    private function connection(array $f, bool $foreign = false): int
    {
        $this->asPortalUser($f['admin']);

        return $this->postJson('/api/v1/digital/connections', ['provider' => 'topup', 'account_id' => $f[$foreign ? 'foreign' : 'main']->id, 'active' => true, 'version' => 0, 'credential' => $foreign ? 'test-private-other-topup-key' : 'test-private-topup-key', 'offers' => [], 'idempotency_key' => $foreign ? 'topup-other-connect' : 'topup-main-connect'])->assertCreated()->json('data.id');
    }

    public function test_topup_token_cannot_be_assigned_to_another_main_even_when_paused(): void
    {
        $f = $this->supportFixture();
        $id = $this->connection($f);
        $duplicate = ['provider' => 'topup', 'account_id' => $f['foreign']->id, 'active' => true, 'version' => 0, 'credential' => '  test-private-topup-key  ', 'offers' => [], 'idempotency_key' => 'duplicate-topup-token'];
        $this->postJson('/api/v1/digital/connections', $duplicate)->assertUnprocessable()->assertJsonValidationErrors('credential');
        $this->assertDatabaseCount('digital_connections', 1);
        $same = ['provider' => 'topup', 'account_id' => $f['main']->id, 'active' => false, 'version' => 1, 'credential' => 'test-private-topup-key', 'offers' => [], 'idempotency_key' => 'pause-same-topup-token'];
        $this->putJson('/api/v1/digital/connections/'.$id, $same)->assertOk()->assertJsonMissingPath('data.topup_credential_hash')->assertJsonMissingPath('data.credential');
        $this->postJson('/api/v1/digital/connections', $duplicate)->assertUnprocessable()->assertJsonValidationErrors('credential');
        $this->assertDatabaseCount('digital_connections', 1);
        $other = $this->connection($f, true);
        $this->putJson('/api/v1/digital/connections/'.$other, array_replace($duplicate, ['version' => 1, 'idempotency_key' => 'replace-with-duplicate-topup']))->assertUnprocessable()->assertJsonValidationErrors('credential');
        $this->assertSame('test-private-other-topup-key', DigitalConnection::findOrFail($other)->credential);
        Http::assertNothingSent();
    }

    public function test_database_rejects_duplicate_topup_token_fingerprints(): void
    {
        $f = $this->supportFixture();
        $this->connection($f);
        $this->expectException(UniqueConstraintViolationException::class);
        DigitalConnection::factory()->create(['account_id' => $f['foreign']->id, 'provider' => 'topup', 'credential' => 'test-private-topup-key']);
    }

    private function synchronize(array $f, int $id): array
    {
        $this->asPortalUser($f['admin']);

        return $this->postJson('/api/v1/digital/connections/'.$id.'/sync', ['version' => DigitalConnection::findOrFail($id)->version])->assertOk()->assertJsonMissingPath('data.credential')->json('data');
    }

    private function grant(array $f, TopupCategory $category): void
    {
        foreach ([['agent', 'sub'], ['subUser', 'branch'], ['branchUser', 'pos']] as [$actor, $target]) {
            $this->asPortalUser($f[$actor]);
            $this->putJson('/api/v1/topup/distribution/'.$f[$target]->id, ['category_ids' => [$category->id], 'active' => true, 'version' => 0, 'idempotency_key' => 'synced-topup-'.$target])->assertOk();
        }
    }

    private function populate(): void
    {
        $this->products = [['product_id' => 'ASIA-5000', 'title' => 'آسياسيل 5000', 'type' => 'topup', 'price' => '5000.00', 'show2site' => 1]];
    }

    public function test_actual_category_ids_accept_shared_and_null_product_references_without_importing_groups(): void
    {
        $f = $this->supportFixture();
        $this->products = [
            ['id' => 3, 'product_id' => 53, 'title' => 'Free Social Packages', 'type' => 'bundle', 'price' => 0, 'show2site' => 1],
            ['id' => 4, 'parent_id' => 3, 'product_id' => 53, 'title' => '50GB for 4 Weeks', 'type' => 'bundle', 'price' => 35000, 'show2site' => 1],
            ['id' => 6, 'parent_id' => 3, 'product_id' => 53, 'title' => '30GB for 4 Weeks', 'type' => 'bundle', 'price' => 30000, 'show2site' => 1],
            ['id' => 49, 'product_id' => null, 'title' => '6000', 'type' => 'topup', 'price' => 6000, 'show2site' => 1],
            ['id' => 50, 'product_id' => null, 'title' => '10000', 'type' => 'topup', 'price' => 10000, 'show2site' => 1],
            ['id' => 51, 'product_id' => null, 'title' => '15000', 'type' => 'topup', 'price' => 15000, 'show2site' => 0],
            ['id' => 52, 'product_id' => 63, 'title' => 'Unknown price', 'type' => 'bundle', 'price' => null, 'show2site' => 1],
        ];
        $id = $this->connection($f);
        $result = $this->synchronize($f, $id);
        $this->assertSame('ok', $result['sync']['catalog']);
        $this->assertSame(4, $result['sync']['category_count']);
        $this->assertSame(2, $result['sync']['excluded_count']);
        $this->assertSame(['zero_price', 'missing_price'], array_column($result['excluded_catalog'], 'reason_code'));
        $this->assertSame(['3', '52'], array_column($result['excluded_catalog'], 'remote_id'));
        $this->getJson('/api/v1/digital/connections?provider=topup')->assertOk()->assertJsonPath('data.0.excluded_count', 2)->assertJsonPath('data.0.excluded_catalog.0.remote_name', 'Free Social Packages')->assertJsonPath('data.0.excluded_catalog.1.price', null);
        $this->getJson('/api/v1/topup/distribution?target_account_id='.$f['main']->id)->assertOk()->assertJsonPath('data.connection.excluded_catalog.0.reason_code', 'zero_price');
        $this->assertEqualsCanonicalizing(['4', '6', '49', '50'], TopupCategory::where('connection_id', $id)->pluck('remote_id')->all());
        $this->assertDatabaseHas('topup_categories', ['connection_id' => $id, 'remote_id' => '49', 'name' => '6000', 'cost_minor' => 600000]);
        $this->assertCount(4, $result['offers']);
        $snapshot = CatalogSnapshot::where('connection_id', $id)->latest('id')->firstOrFail();
        DB::table('digital_catalog_exclusions')->where('snapshot_id', $snapshot->id)->delete();
        $this->artisan('topup:refresh-excluded-catalog')->assertSuccessful();
        $this->assertCount(2, $snapshot->fresh()->excluded_catalog);
        DB::table('digital_catalog_exclusions')->where('snapshot_id', $snapshot->id)->delete();
        $this->products[1]['price'] = 36000;
        $this->artisan('topup:refresh-excluded-catalog')->assertSuccessful();
        $this->assertNull($snapshot->fresh()->excluded_catalog);
        $this->assertDatabaseHas('topup_categories', ['connection_id' => $id, 'remote_id' => '4', 'cost_minor' => 3500000]);
        Http::assertNotSent(fn ($request): bool => $request->method() !== 'GET');
    }

    public function test_duplicate_actual_category_id_keeps_previous_catalog_and_distribution(): void
    {
        $f = $this->supportFixture();
        $this->products = [['id' => 50, 'product_id' => null, 'title' => '10000', 'type' => 'topup', 'price' => 10000]];
        $id = $this->connection($f);
        $this->synchronize($f, $id);
        $category = TopupCategory::where('connection_id', $id)->firstOrFail();
        $this->grant($f, $category);
        $this->products[] = ['id' => 50, 'product_id' => 63, 'title' => 'Conflicting bundle', 'type' => 'bundle', 'price' => 20000];
        $result = $this->synchronize($f, $id);
        $this->assertSame('failed', $result['sync']['catalog']);
        $this->assertDatabaseCount('topup_categories', 1);
        $this->assertTrue($category->fresh()->active);
        $this->asPortalUser($f['posUser']);
        $this->getJson('/api/v1/digital/offers')->assertOk()->assertJsonCount(1, 'data');
        Http::assertNotSent(fn ($request): bool => $request->method() !== 'GET');
    }

    public function test_company_catalog_can_be_refreshed_without_querying_balance_or_purchasing(): void
    {
        $f = $this->supportFixture();
        $this->populate();
        $id = $this->connection($f);
        $this->postJson('/api/v1/digital/connections/'.$id.'/sync', ['version' => 1, 'catalog_only' => true])->assertOk()->assertJsonPath('data.sync.balance', 'skipped')->assertJsonPath('data.sync.catalog', 'ok');
        Http::assertNotSent(fn ($request): bool => str_ends_with($request->url(), '/inventory') || $request->method() !== 'GET');
        $this->assertDatabaseCount('digital_offers', 1);
    }

    public function test_previous_manual_name_offers_cannot_be_distributed_or_sold_even_before_retirement_migration(): void
    {
        $f = $this->supportFixture();
        $this->populate();
        $id = $this->connection($f);
        $this->synchronize($f, $id);
        $category = TopupCategory::where('connection_id', $id)->firstOrFail();
        $this->grant($f, $category);
        $this->asPortalUser($f['posUser']);
        $offer = $this->getJson('/api/v1/digital/offers')->assertOk()->json('data.0');
        $this->postJson('/api/v1/digital/device-session', ['app_version' => '1.0.0'])->assertOk();
        $this->withCredentials()->withCookie(config('session.cookie'), app('session')->getId());
        DigitalOffer::whereKey($offer['id'])->update(['manual_category_version' => 1, 'remote_id' => '5,000']);
        $category->update(['connection_id' => null, 'catalog_snapshot_id' => null, 'use_name' => true]);
        $this->getJson('/api/v1/digital/offers')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/topup/available')->assertOk()->assertJsonCount(0, 'data.categories')->assertJsonPath('data.execution_ready', false);
        Http::recorded()->each(fn ($pair) => $this->assertSame('GET', $pair[0]->method()));
        $this->postJson('/api/v1/digital/orders', ['request_id' => 'retired-manual-denied', 'connection_id' => $id, 'offer_id' => $offer['id'], 'offer_version' => $offer['version'], 'expected_retail' => $offer['retail'], 'mobile' => '07701234567', 'confirm_mobile' => '07701234567'])->assertNotFound();
        $this->asPortalUser($f['admin']);
        $this->getJson('/api/v1/topup/categories')->assertOk()->assertJsonCount(0, 'data');
        $this->putJson('/api/v1/topup/distribution/'.$f['main']->id, ['category_ids' => [$category->id], 'active' => true, 'version' => 1, 'idempotency_key' => 'retired-manual-assignment'])->assertUnprocessable();
        Http::assertNotSent(fn ($request): bool => $request->method() !== 'GET');
        $this->assertDatabaseCount('digital_orders', 0);
    }

    public function test_rejected_provider_eligibility_does_not_submit_a_transaction(): void
    {
        $f = $this->supportFixture();
        $this->populate();
        $id = $this->connection($f);
        $this->synchronize($f, $id);
        $this->grant($f, TopupCategory::where('connection_id', $id)->firstOrFail());
        $this->asPortalUser($f['posUser']);
        $offer = $this->getJson('/api/v1/digital/offers')->assertOk()->json('data.0');
        $this->postJson('/api/v1/digital/device-session', ['app_version' => '1.0.0'])->assertOk();
        $this->withCredentials()->withCookie(config('session.cookie'), app('session')->getId());
        $this->rejectEligibility = true;
        $this->postJson('/api/v1/digital/orders', ['request_id' => 'provider-eligibility-denied', 'connection_id' => $id, 'offer_id' => $offer['id'], 'offer_version' => $offer['version'], 'expected_retail' => $offer['retail'], 'mobile' => '07701234567', 'confirm_mobile' => '07701234567'])->assertCreated()->assertJsonPath('data.status', 'failed');
        Http::assertNotSent(fn ($request): bool => str_ends_with($request->url(), '/transactions'));
    }

    public function test_provider_identifier_and_cost_are_preserved_when_owner_changes_the_sale_price(): void
    {
        $f = $this->supportFixture();
        $this->populate();
        $id = $this->connection($f);
        $this->synchronize($f, $id);
        $offer = DigitalOffer::where('connection_id', $id)->firstOrFail();
        $this->putJson('/api/v1/digital/connections/'.$id, ['provider' => 'topup', 'account_id' => $f['main']->id, 'active' => true, 'version' => 2, 'offers' => [['id' => $offer->id, 'product_id' => $offer->product_id, 'remote_id' => 'ASIA-5000', 'retail' => '6000.00', 'active' => false]], 'idempotency_key' => 'provider-retail-price'])->assertOk();
        $this->products[0]['price'] = '5500.00';
        $this->synchronize($f, $id);
        $this->assertSame(550000, $offer->fresh()->cost_minor);
        $this->assertSame(600000, $offer->fresh()->retail_minor);
        $this->assertSame('ASIA-5000', $offer->fresh()->remote_id);
        $this->assertFalse($offer->fresh()->active);
    }

    public function test_empty_live_shape_returns_real_balance_without_fabricating_categories_or_deleting_manual_drafts(): void
    {
        $f = $this->supportFixture();
        TopupCategory::create(['name' => 'مسودة يدوية', 'type' => 'topup', 'active' => false]);
        $id = $this->connection($f);
        $result = $this->synchronize($f, $id);
        $this->assertSame('100000.00', $result['company_balance']);
        $this->assertSame('ok', $result['sync']['balance']);
        $this->assertSame('empty', $result['sync']['catalog']);
        $this->assertSame(0, $result['sync']['category_count']);
        $this->assertSame(0, $result['catalog_count']);
        $this->assertDatabaseCount('topup_categories', 1);
        $this->assertDatabaseCount('digital_offers', 0);
        $this->assertDatabaseCount('catalog_products', 0);
        $this->assertStringNotContainsString('test-private-topup-key', json_encode($result));
        Http::assertNotSent(fn ($request): bool => $request->method() !== 'GET');
    }

    #[TestWith([false])]
    #[TestWith([true])]
    #[TestWith([true, true])]
    public function test_imported_categories_flow_to_pos_and_execute_once_using_the_main_token_without_internal_wallets(bool $actualId, bool $nested = false): void
    {
        $f = $this->supportFixture();
        $this->populate();
        if ($actualId) {
            $this->products = [['id' => 50, 'product_id' => null, 'title' => '10000', 'type' => 'topup', 'price' => 10000, 'show2site' => 1]];
        }
        if ($nested) {
            $this->purchaseResponse = ['status' => 'success', 'data' => ['status' => 'Success', 'id' => '6252706171791504972', 'logicalResource' => ['value' => '9647701234567']]];
        }
        $id = $this->connection($f);
        $result = $this->synchronize($f, $id);
        $this->assertSame('ok', $result['sync']['catalog']);
        $category = TopupCategory::where('connection_id', $id)->firstOrFail();
        $this->grant($f, $category);
        $salePrice = $category->retail_minor + 50000;
        DigitalOffer::where('topup_category_id', $category->id)->update(['retail_minor' => $salePrice]);
        $category->update(['retail_minor' => $salePrice]);
        $f['pos']->update(['serial' => 'Approved-Topup-Device']);
        $this->asPortalUser($f['posUser']);
        $offer = $this->getJson('/api/v1/topup/available')->assertOk()->assertJsonPath('data.execution_ready', true)->assertJsonMissingPath('data.connection.company_balance')->json('data.categories.0.offer');
        $this->getJson('/api/v1/topup/available')->assertOk()->assertJsonPath('data.categories.0.price', $offer['retail']);
        $this->getJson('/api/v1/digital/offers')->assertOk()->assertJsonPath('data.0.id', $offer['id']);
        $this->postJson('/api/v1/digital/device-session', ['serial' => 'Other-Topup-Device', 'app_version' => '1.0.0'])->assertOk()->assertJsonPath('data.device_lock_enabled', true);
        $this->withCredentials()->withCookie(config('session.cookie'), app('session')->getId());
        $input = ['request_id' => 'synced-topup-purchase', 'connection_id' => $id, 'offer_id' => $offer['id'], 'offer_version' => $offer['version'], 'expected_retail' => $offer['retail'], 'mobile' => '07701234567', 'confirm_mobile' => '07701234567'];
        $this->postJson('/api/v1/digital/orders', $input)->assertForbidden();
        Http::assertNotSent(fn ($request): bool => str_ends_with($request->url(), '/transactions'));
        $f['pos']->update(['device_lock_enabled' => false]);
        $this->asPortalUser($f['posUser']);
        $this->postJson('/api/v1/digital/orders', $input)->assertCreated()->assertJsonPath('data.status', 'succeeded');
        $this->postJson('/api/v1/digital/orders', $input)->assertCreated()->assertJsonPath('data.status', 'succeeded');
        $this->assertDatabaseCount('digital_orders', 1);
        $this->assertDatabaseCount('finance_entries', 0);
        $transactions = Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/transactions'));
        $this->assertCount(1, $transactions);
        $this->assertTrue($transactions->first()[0]->hasHeader('x-api-key', 'test-private-topup-key'));
        $this->assertSame($actualId ? '50' : 'ASIA-5000', $transactions->first()[0]['category']);
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/checkEligibility') && $request['category'] === ($actualId ? '50' : 'ASIA-5000'));
        $this->assertSame('9647701234567', $transactions->first()[0]['mobile']);
        $this->assertArrayNotHasKey('retail', $transactions->first()[0]->data());
        $this->assertArrayNotHasKey('amount', $transactions->first()[0]->data());
    }

    #[TestWith(['Success', '9647701234567', '6252706171791504972', true])]
    #[TestWith(['Failed', '9647701234567', '6252706171791504972', false])]
    #[TestWith(['Success', '9647701234568', '6252706171791504972', false])]
    #[TestWith(['Success', '9647701234567', null, false])]
    public function test_saved_nested_response_is_reconciled_without_another_purchase(string $status, string $mobile, ?string $reference, bool $confirmed): void
    {
        $f = $this->supportFixture();
        $this->populate();
        $id = $this->connection($f);
        $this->synchronize($f, $id);
        $category = TopupCategory::where('connection_id', $id)->firstOrFail();
        $this->grant($f, $category);
        $offer = DigitalOffer::where('topup_category_id', $category->id)->firstOrFail();
        $order = DigitalOrder::factory()->create(['offer_id' => $offer->id, 'connection_id' => $id, 'main_account_id' => $f['main']->id, 'account_id' => $f['pos']->id, 'creator_id' => $f['posUser']->id, 'provider' => 'topup', 'type' => 'topup', 'mobile' => '9647701234567', 'quoted_cost_minor' => 500000, 'quoted_retail_minor' => 500000, 'status' => 'review', 'provider_purchase_started_at' => now(), 'provider_purchase_response' => ['status' => 'success', 'data' => ['status' => $status, 'id' => $reference, 'logicalResource' => ['value' => $mobile]]]]);
        $this->inventory = '94000';
        $this->asPortalUser($f['posUser']);
        $input = ['version' => 1, 'idempotency_key' => 'saved-topup-result'];
        $this->postJson('/api/v1/digital/orders/'.$order->id.'/verify', $input)->assertOk()->assertJsonPath('data.status', $confirmed ? 'succeeded' : 'review')->assertJsonPath('data.reservation_active', ! $confirmed);
        $this->postJson('/api/v1/digital/orders/'.$order->id.'/verify', $input)->assertOk();
        $this->assertSame($confirmed ? $reference : null, $order->fresh()->company_transaction_id);
        $this->assertSame($confirmed ? 9400000 : 10000000, DigitalConnection::findOrFail($id)->company_balance_minor);
        $this->assertDatabaseCount('digital_orders', 1);
        $this->assertSame(1, $order->attempts()->count());
        Http::assertNotSent(fn ($request): bool => str_ends_with($request->url(), '/transactions'));
    }

    public function test_sync_keeps_ids_updates_prices_and_empty_catalog_revokes_execution(): void
    {
        $f = $this->supportFixture();
        $this->populate();
        $id = $this->connection($f);
        $this->synchronize($f, $id);
        $category = TopupCategory::where('connection_id', $id)->firstOrFail();
        $offer = DigitalOffer::where('topup_category_id', $category->id)->firstOrFail();
        $this->grant($f, $category);
        $this->products[0]['price'] = '5500.00';
        $this->synchronize($f, $id);
        $this->assertDatabaseCount('digital_offers', 1);
        $this->assertSame(550000, $offer->fresh()->cost_minor);
        $this->assertSame(550000, $offer->fresh()->retail_minor);
        $this->products = [];
        $this->synchronize($f, $id);
        $this->asPortalUser($f['posUser']);
        $this->getJson('/api/v1/digital/offers')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/topup/available')->assertOk()->assertJsonCount(0, 'data.categories')->assertJsonPath('data.execution_ready', false);
        $this->assertDatabaseHas('topup_categories', ['id' => $category->id, 'active' => false]);
    }

    public function test_each_main_has_its_own_categories_and_cannot_be_granted_another_mains_category(): void
    {
        $f = $this->supportFixture();
        $this->populate();
        $id = $this->connection($f);
        $this->synchronize($f, $id);
        $other = $this->connection($f, true);
        $this->synchronize($f, $other);
        $category = TopupCategory::where('connection_id', $other)->firstOrFail();
        $this->getJson('/api/v1/topup/distribution?target_account_id='.$f['main']->id)->assertOk()->assertJsonCount(1, 'data.categories');
        $this->putJson('/api/v1/topup/distribution/'.$f['main']->id, ['category_ids' => [$category->id], 'active' => true, 'version' => 1, 'idempotency_key' => 'foreign-category-denied'])->assertUnprocessable();
        $this->asPortalUser($f['agent']);
        $this->postJson('/api/v1/digital/connections/'.$id.'/sync', ['version' => 2])->assertForbidden();
        $this->assertDatabaseCount('topup_categories', 2);
    }

    public function test_rotating_token_hides_old_categories_and_partial_failure_reports_balance_separately(): void
    {
        $f = $this->supportFixture();
        $this->populate();
        $id = $this->connection($f);
        $this->synchronize($f, $id);
        $category = TopupCategory::where('connection_id', $id)->firstOrFail();
        $this->grant($f, $category);
        $this->asPortalUser($f['admin']);
        $this->putJson('/api/v1/digital/connections/'.$id, ['provider' => 'topup', 'account_id' => $f['main']->id, 'active' => true, 'version' => 2, 'credential' => 'replacement-topup-key', 'offers' => [], 'idempotency_key' => 'rotate-synced-topup'])->assertOk()->assertJsonPath('data.company_balance', null);
        $this->invalidCatalog = true;
        $result = $this->synchronize($f, $id);
        $this->assertSame('ok', $result['sync']['balance']);
        $this->assertSame('failed', $result['sync']['catalog']);
        $this->asPortalUser($f['posUser']);
        $this->getJson('/api/v1/topup/available')->assertOk()->assertJsonCount(0, 'data.categories');
        $this->getJson('/api/v1/digital/offers')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_revoking_the_upstream_category_removes_the_sale_offer_and_manual_edit_is_denied(): void
    {
        $f = $this->supportFixture();
        $this->populate();
        $id = $this->connection($f);
        $this->synchronize($f, $id);
        $category = TopupCategory::where('connection_id', $id)->firstOrFail();
        $this->grant($f, $category);
        TopupGrant::where('target_account_id', $f['sub']->id)->update(['active' => false]);
        $this->asPortalUser($f['posUser']);
        $this->getJson('/api/v1/digital/offers')->assertOk()->assertJsonCount(0, 'data');
        $this->asPortalUser($f['admin']);
        $this->putJson('/api/v1/topup/categories/'.$category->id, ['name' => 'Forged category', 'type' => 'topup', 'price' => '1.00', 'active' => true, 'version' => 1, 'idempotency_key' => 'cannot-edit-remote-topup'])->assertNotFound();

    }

    public function test_category_revocation_during_eligibility_blocks_the_financial_post(): void
    {
        $f = $this->supportFixture();
        $this->populate();
        $id = $this->connection($f);
        $this->synchronize($f, $id);
        $category = TopupCategory::where('connection_id', $id)->firstOrFail();
        $this->grant($f, $category);
        $this->asPortalUser($f['posUser']);
        $offer = $this->getJson('/api/v1/digital/offers')->assertOk()->json('data.0');
        $this->postJson('/api/v1/digital/device-session', ['app_version' => '1.0.0'])->assertOk();
        $this->withCredentials()->withCookie(config('session.cookie'), app('session')->getId());
        $this->revokeTarget = $f['sub']->id;
        $this->postJson('/api/v1/digital/orders', ['request_id' => 'revoked-before-transaction', 'connection_id' => $id, 'offer_id' => $offer['id'], 'offer_version' => $offer['version'], 'expected_retail' => $offer['retail'], 'mobile' => '07701234567', 'confirm_mobile' => '07701234567'])->assertCreated()->assertJsonPath('data.status', 'failed');
        Http::assertNotSent(fn ($request): bool => str_ends_with($request->url(), '/transactions'));
    }
}

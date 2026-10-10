<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Models\CatalogProduct;
use App\Models\CatalogProvider;
use App\Models\Digital\DigitalConnection;
use App\Models\Digital\DigitalGrant;
use App\Models\Digital\DigitalOffer;
use App\Models\Digital\DigitalOrder;
use App\Models\Operations\DirectStop;
use App\Models\Sales\PrintPolicy;
use App\Models\Sales\Sale;
use App\Services\Digital\DigitalAccess;
use App\Services\Digital\DigitalOperations;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\CreatesSupport;
use Tests\TestCase;

class DigitalServicesTest extends TestCase
{
    use CreatesSupport, RefreshDatabase;

    private bool $premium = false;

    private int $beinProvince = 2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        Http::preventStrayRequests();
        config(['digital.gateway_url' => 'https://1.1.1.1/masal', 'digital.allowed_hosts' => ['1.1.1.1'], 'digital.contract_verified' => true, 'digital.purchases_enabled' => true, 'digital.topup.driver' => 'normalized', 'digital.rabiaa.driver' => 'normalized']);
        Http::fake(function ($request) {
            if (str_ends_with($request->url(), '/catalog')) {
                return Http::response($request['provider'] === 'topup' ? ['products' => [['product_id' => 'PRODUCT-51', 'title' => 'Provider topup', 'type' => 'topup', 'price' => '4300.75']]] : ['data' => [['catalogId' => 71, 'title' => 'Provider voucher', 'provinceId' => 2, 'province' => 'بغداد', 'vendorPrice' => '4300.75', 'publicPrice' => '5000.25', 'packageType' => $this->premium ? 'premium' : 'voucher']], 'beinProvinces' => [['id' => $this->beinProvince, 'name' => 'بغداد']]]);
            }
            if (str_ends_with($request->url(), '/inventory')) {
                return Http::response(['remaining_balance' => '120000.10']);
            }
            if (str_ends_with($request->url(), '/receipt')) {
                return Http::response(['code' => 'actual-test-pin', 'serial' => '000000701']);
            }

            return Http::response(['status' => 'succeeded', 'transactionId' => 'provider-'.$request['requestId'], 'receiptRef' => 'receipt-'.$request['requestId'], 'cost' => '4300.75', 'retail' => '5000.25', 'remaining_balance' => '120000.10', ...($this->premium ? ['beinStatus' => 'success'] : [])]);
        });
    }

    private function fixture(string $provider = 'rabiaa', bool $companyMetadata = true, bool $crossCompanyProduct = false): array
    {
        $system = $this->account(AccountType::System);
        $main = $this->account(AccountType::MainAgent, $system);
        $sub = $this->account(AccountType::SubAgent, $main);
        $branch = $this->account(AccountType::SubBranch, $sub);
        $pos = $this->account(AccountType::Pos, $branch);
        $admin = $this->userFor($system);
        $owner = $this->userFor($main);
        $subOwner = $this->userFor($sub);
        $branchOwner = $this->userFor($branch);
        $seller = $this->userFor($pos);
        $company = CatalogProvider::factory()->create(['connection' => $companyMetadata ? 'API' : 'ملفات']);
        $productCompany = $crossCompanyProduct ? CatalogProvider::factory()->create(['connection' => 'ملفات']) : $company;
        $product = CatalogProduct::factory()->create(['provider_id' => $productCompany->id, 'minimum_price' => '4000.00']);
        $rule = DB::table('catalog_account_rules')->insertGetId(['target_account_id' => $main->id, 'authority_account_id' => $system->id]);
        DB::table('catalog_rule_products')->insert(['rule_id' => $rule, 'product_id' => $product->id]);
        $this->asPortalUser($admin);
        $configuration = ['version' => 0, 'provider' => $provider, 'account_id' => $main->id, ...($companyMetadata ? ['provider_id' => $company->id] : []), 'active' => true, 'credential' => 'fixture-gateway-secret-123', 'offers' => [], 'idempotency_key' => 'fixture-config-001'];
        $connection = $this->postJson('/api/v1/digital/connections', $configuration)->assertCreated()->assertJsonMissingPath('data.credential')->json('data');
        $catalog = $this->postJson('/api/v1/digital/connections/'.$connection['id'].'/catalog', ['version' => 1])->assertOk()->json('data');
        $offer = ['product_id' => $product->id, 'remote_id' => $provider === 'topup' ? 'PRODUCT-51' : '71', 'province_id' => $provider === 'topup' ? '' : '2', 'retail' => '5000.25', 'active' => true];
        $configuration = array_replace($configuration, ['version' => 1, 'catalog_snapshot_id' => $catalog['id'], 'offers' => [$offer], 'idempotency_key' => 'fixture-map-001']);
        unset($configuration['credential']);
        $connection = $this->putJson('/api/v1/digital/connections/'.$connection['id'], $configuration)->assertOk()->json('data');
        $offer = $connection['offers'][0];
        foreach ([[$owner, $sub], [$subOwner, $branch], [$branchOwner, $pos]] as [$actor, $target]) {
            $this->asPortalUser($actor);
            $this->putJson('/api/v1/digital/connections/'.$connection['id'].'/grants/'.$target->id, ['version' => 0, 'offer_ids' => [$offer['id']], 'active' => true, 'idempotency_key' => 'fixture-grant-'.$target->id])->assertOk();
        }
        $this->asPortalUser($seller);
        $this->postJson('/api/v1/digital/device-session', ['app_version' => '1.0.0'])->assertOk();
        $this->withCredentials()->withCookie(config('session.cookie'), app('session')->getId());

        return compact('system', 'main', 'sub', 'branch', 'pos', 'admin', 'owner', 'subOwner', 'branchOwner', 'seller', 'company', 'product', 'connection', 'offer', 'configuration');
    }

    private function input(array $fixture, string $id = 'digital-order-001'): array
    {
        return ['request_id' => $id, 'connection_id' => $fixture['connection']['id'], 'offer_id' => $fixture['offer']['id'], 'offer_version' => $fixture['offer']['version'], 'expected_retail' => '5000.25', ...($fixture['connection']['provider'] === 'topup' ? ['mobile' => '07701234567', 'confirm_mobile' => '٠٧٧٠١٢٣٤٥٦٧'] : [])];
    }

    private function providerResponse(array $responses): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake($responses);
    }

    public function test_unauthenticated_digital_api_is_denied(): void
    {
        $this->withHeader('X-Masal-Portal', 'admin')->getJson('/api/v1/digital/options')->assertUnauthorized();
        Http::assertNothingSent();
    }

    #[TestWith(['rabiaa'])]
    #[TestWith(['topup'])]
    public function test_incoming_token_uses_the_selected_main_and_existing_file_product_without_creating_an_api_company(string $provider): void
    {
        $f = $this->fixture($provider, false);
        $name = $provider === 'rabiaa' ? 'الرابعة' : 'Topup';
        $this->assertSame(0, CatalogProvider::where('connection', 'API')->count());
        $this->getJson('/api/v1/digital/offers')->assertOk()->assertJsonPath('data.0.provider_id', null)->assertJsonPath('data.0.company_name', $name)->assertJsonPath('data.0.product_id', $f['product']->id)->assertJsonPath('data.0.product_provider_id', $f['company']->id)->assertJsonPath('data.0.product_provider_name', $f['company']->name)->assertJsonMissingPath('data.0.cost')->assertJsonMissingPath('data.0.credential');
        $this->getJson('/api/v1/sales/products')->assertOk()->assertJsonCount(0, 'data');
        $this->providerResponse(['*/submit' => Http::response(['status' => 'succeeded', 'transactionId' => 'incoming-provider-confirmed', 'receiptRef' => 'incoming-provider-receipt', 'cost' => '4300.75', 'retail' => '5000.25']), '*/receipt' => Http::response(['code' => 'actual-incoming-token-card', 'serial' => '000000001'])]);

        $order = $this->postJson('/api/v1/digital/orders', $this->input($f))->assertCreated()->assertJsonPath('data.status', 'succeeded')->json('data');

        $this->postJson('/api/v1/digital/orders', $this->input($f))->assertCreated()->assertJsonPath('data.id', $order['id']);
        $this->getJson('/api/v1/digital/orders/'.$order['id'].'/receipt')->assertOk()->assertJsonPath('data.code', 'actual-incoming-token-card');
        $this->asPortalUser($f['admin']);
        $this->getJson('/api/v1/digital/connections')->assertOk()->assertJsonPath('data.0.provider_name', $name)->assertJsonPath('data.0.provider_id', null)->assertJsonMissingPath('data.0.credential');
        $this->assertDatabaseHas('digital_connections', ['id' => $f['connection']['id'], 'account_id' => $f['main']->id, 'provider_id' => null, 'company_balance_minor' => null]);
        $this->assertDatabaseHas('digital_orders', ['id' => $order['id'], 'main_account_id' => $f['main']->id, 'product_id' => $f['product']->id, 'actual_retail_minor' => 500025]);
        $this->assertDatabaseCount('catalog_providers', 1);
        $this->assertDatabaseCount('catalog_products', 1);
        $this->assertDatabaseCount('digital_provider_attempts', 1);
        $this->assertDatabaseCount('finance_entries', 0);
        $this->assertDatabaseCount('finance_wallets', 0);
        Http::assertSentCount(2);
    }

    public function test_explicit_legacy_company_is_preserved_when_omitted_and_does_not_restrict_mapping_to_its_products(): void
    {
        $f = $this->fixture('rabiaa', true, true);
        $this->assertNotSame($f['company']->id, $f['product']->provider_id);
        $this->assertSame($f['company']->id, $f['connection']['provider_id']);
        $this->getJson('/api/v1/digital/offers')->assertOk()->assertJsonPath('data.0.product_id', $f['product']->id);
        $credential = DB::table('digital_connections')->where('id', $f['connection']['id'])->value('credential');
        $this->asPortalUser($f['admin']);
        $payload = array_replace($f['configuration'], ['version' => 2, 'idempotency_key' => 'keep-legacy-company-metadata']);
        unset($payload['provider_id']);
        $url = '/api/v1/digital/connections/'.$f['connection']['id'];

        $response = $this->putJson($url, $payload)->assertOk();
        $this->assertDatabaseHas('digital_connections', ['id' => $f['connection']['id'], 'provider_id' => $f['company']->id]);
        $response->assertJsonPath('data.provider_id', $f['company']->id)->assertJsonPath('data.offers.0.product_id', $f['product']->id)->assertJsonPath('data.version', 3);
        $this->assertDatabaseHas('digital_offers', ['id' => $f['offer']['id'], 'listed' => true, 'active' => true, 'remote_key' => hash('sha256', '71:2'), 'version' => 2]);

        $this->assertSame($credential, DB::table('digital_connections')->where('id', $f['connection']['id'])->value('credential'));
        $this->putJson($url, array_replace($payload, ['version' => 3, 'provider_id' => null, 'idempotency_key' => 'reject-clearing-company-identity']))->assertConflict();
        $this->assertDatabaseHas('digital_connections', ['id' => $f['connection']['id'], 'provider_id' => $f['company']->id, 'version' => 3]);
        $this->assertDatabaseCount('catalog_providers', 2);
        $this->assertDatabaseCount('catalog_products', 1);
        $this->asPortalUser($f['seller']);
        $this->getJson('/api/v1/digital/offers')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $f['offer']['id'])->assertJsonPath('data.0.version', 2)->assertJsonPath('data.0.retail', '5000.25');
        $this->assertDatabaseCount('finance_entries', 0);
        $this->assertDatabaseCount('finance_wallets', 0);
    }

    #[TestWith(['outside_account'])]
    #[TestWith(['inactive_product'])]
    #[TestWith(['foreign_currency'])]
    #[TestWith(['inactive_supplier'])]
    #[TestWith(['unverified_remote'])]
    public function test_mapping_without_company_metadata_still_rejects_unauthorized_or_unverified_categories(string $reason): void
    {
        $f = $this->fixture('rabiaa', false);
        $this->asPortalUser($f['admin']);
        $payload = array_replace($f['configuration'], ['version' => 2, 'idempotency_key' => 'deny-incoming-mapping-'.$reason]);
        if ($reason === 'unverified_remote') {
            $payload['offers'][0]['remote_id'] = '999';
        } else {
            $product = CatalogProduct::factory()->create(['provider_id' => $f['company']->id, 'currency' => $reason === 'foreign_currency' ? 'USD' : 'IQD', 'status' => $reason === 'inactive_product' ? 'inactive' : 'active']);
            if ($reason !== 'outside_account') {
                DB::table('catalog_rule_products')->insert(['rule_id' => DB::table('catalog_account_rules')->where('target_account_id', $f['main']->id)->value('id'), 'product_id' => $product->id]);
            }
            if ($reason === 'inactive_supplier') {
                $f['company']->update(['status' => 'inactive']);
            }
            $payload['offers'][0]['product_id'] = $product->id;
        }
        $calls = count(Http::recorded());

        $this->putJson('/api/v1/digital/connections/'.$f['connection']['id'], $payload)->assertUnprocessable();

        $this->assertDatabaseHas('digital_connections', ['id' => $f['connection']['id'], 'provider_id' => null, 'version' => 2]);
        $this->assertDatabaseHas('digital_offers', ['id' => $f['offer']['id'], 'product_id' => $f['product']->id, 'remote_id' => '71', 'retail_minor' => 500025, 'version' => 1]);
        $this->assertDatabaseCount('digital_orders', 0);
        $this->assertDatabaseCount('finance_entries', 0);
        $this->assertSame($calls, count(Http::recorded()));
    }

    public function test_pausing_the_local_product_supplier_blocks_an_incoming_token_offer_without_metadata_before_provider_contact(): void
    {
        $f = $this->fixture('rabiaa', false);
        $f['company']->update(['status' => 'inactive']);
        $calls = count(Http::recorded());

        $this->getJson('/api/v1/digital/offers')->assertOk()->assertJsonCount(0, 'data');

        $this->postJson('/api/v1/digital/orders', $this->input($f))->assertNotFound();
        $this->assertDatabaseCount('digital_orders', 0);
        $this->assertDatabaseCount('digital_provider_attempts', 0);
        $this->assertDatabaseCount('finance_entries', 0);
        $this->assertSame($calls, count(Http::recorded()));
    }

    public function test_pos_scoped_main_employee_cannot_read_its_employers_provider_connection_or_company_balance(): void
    {
        $f = $this->fixture('topup', false);
        $this->postJson('/api/v1/digital/orders', $this->input($f))->assertCreated()->assertJsonPath('data.status', 'succeeded');
        $employee = $this->supportEmployee($f['main'], $f['pos'], ['account.view', 'digital.view', 'data.cost']);
        $this->asPortalUser($employee);
        $calls = count(Http::recorded());

        $response = $this->getJson('/api/v1/digital/connections')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 0)->assertJsonPath('balance.known', 0)->assertJsonPath('balance.amount', null);

        $this->assertStringNotContainsString($f['main']->name, $response->getContent());
        $this->assertStringNotContainsString('fixture-gateway-secret-123', $response->getContent());
        $this->getJson('/api/v1/digital/connections?main_account_id='.$f['main']->id)->assertNotFound();
        $this->getJson('/api/v1/digital/offers')->assertNotFound();
        $this->getJson('/api/v1/digital/orders')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.account_id', $f['pos']->id);
        $this->assertSame($calls, count(Http::recorded()));
    }

    public function test_real_provider_result_is_idempotent_private_and_does_not_create_internal_money(): void
    {
        $f = $this->fixture('topup');
        $data = $this->input($f);
        $response = $this->postJson('/api/v1/digital/orders', $data);
        $this->assertSame(201, $response->status(), $response->getContent());
        $order = $response->assertCreated()->assertJsonPath('data.status', 'succeeded')->assertJsonPath('data.retail', '5000.25')->assertJsonPath('data.mobile', '9647701234567')->assertJsonMissingPath('data.cost')->assertJsonMissingPath('data.receipt_ref')->assertJsonMissingPath('data.receipt')->json('data');
        $this->postJson('/api/v1/digital/orders', $data)->assertCreated()->assertJsonPath('data.id', $order['id']);
        $this->postJson('/api/v1/digital/orders', array_replace($data, ['mobile' => '07701234568', 'confirm_mobile' => '07701234568']))->assertConflict();
        $this->assertCount(1, Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/submit')));
        $this->assertDatabaseCount('digital_orders', 1);
        $this->assertDatabaseCount('digital_provider_attempts', 1);
        $this->assertDatabaseCount('finance_entries', 0);
        $this->assertDatabaseCount('finance_wallets', 0);
        $this->assertDatabaseHas('digital_connections', ['id' => $f['connection']['id'], 'company_balance_minor' => 12000010]);
        $this->assertStringNotContainsString('fixture-gateway-secret-123', DB::table('digital_connections')->value('credential'));
        $this->assertSame(0, DB::table('audit_logs')->where('details', 'like', '%fixture-gateway-secret-123%')->count());
        $this->getJson('/api/v1/digital/orders/'.$order['id'].'/receipt')->assertOk()->assertJsonPath('data.code', 'actual-test-pin');
        $this->assertStringNotContainsString('actual-test-pin', DB::table('digital_orders')->where('id', $order['id'])->value('receipt'));
    }

    public function test_missing_contract_or_disabled_purchases_is_blocked_before_creating_order_or_contacting_provider(): void
    {
        $f = $this->fixture();
        $count = count(Http::recorded());
        config(['digital.contract_verified' => false]);
        $this->postJson('/api/v1/digital/orders', $this->input($f))->assertStatus(503);
        config(['digital.contract_verified' => true, 'digital.purchases_enabled' => false]);
        $this->postJson('/api/v1/digital/orders', $this->input($f))->assertConflict();
        $this->assertDatabaseCount('digital_orders', 0);
        $this->assertSame($count, count(Http::recorded()));
    }

    public function test_timeout_is_held_for_verification_and_never_reissues_the_same_purchase(): void
    {
        $f = $this->fixture();
        $this->providerResponse(['*/submit' => Http::failedConnection(), '*/verify' => Http::response(['status' => 'succeeded', 'transactionId' => 'verified-provider-001', 'receiptRef' => 'verified-receipt-001', 'cost' => '4300.75', 'retail' => '5000.25'])]);
        $input = $this->input($f);
        $order = $this->postJson('/api/v1/digital/orders', $input)->assertCreated()->assertJsonPath('data.status', 'review')->assertJsonPath('data.reservation_active', true)->json('data');
        $this->postJson('/api/v1/digital/orders', $input)->assertCreated()->assertJsonPath('data.id', $order['id']);
        $this->postJson('/api/v1/digital/orders', $this->input($f, 'new-during-review'))->assertConflict();
        $this->postJson('/api/v1/digital/orders/'.$order['id'].'/verify', ['version' => $order['version'], 'idempotency_key' => 'verify-original-request'])->assertOk()->assertJsonPath('data.status', 'succeeded')->assertJsonPath('data.reservation_active', false);
        $this->assertDatabaseCount('digital_orders', 1);
        $this->assertDatabaseCount('finance_entries', 0);
    }

    public function test_only_current_own_pos_can_execute_verify_or_read_provider_codes(): void
    {
        $f = $this->fixture();
        $id = $this->postJson('/api/v1/digital/orders', $this->input($f))->assertCreated()->json('data.id');
        foreach ([$f['admin'], $f['owner'], $f['subOwner']] as $actor) {
            $this->asPortalUser($actor);
            $this->getJson('/api/v1/digital/orders/'.$id)->assertOk()->assertJsonMissingPath('data.receipt');
            $this->getJson('/api/v1/digital/orders/'.$id.'/receipt')->assertForbidden();
            $this->postJson('/api/v1/digital/orders', $this->input($f, 'impersonate-'.$actor->id))->assertForbidden();
        }
        $otherMain = $this->account(AccountType::MainAgent, $f['system']);
        $otherPos = $this->account(AccountType::Pos, $otherMain);
        $this->asPortalUser($this->userFor($otherPos));
        $this->getJson('/api/v1/digital/orders/'.$id)->assertNotFound();
        $this->getJson('/api/v1/digital/orders/'.$id.'/receipt')->assertNotFound();
    }

    public function test_provider_response_with_missing_reference_or_changed_retail_stays_unconfirmed_without_free_purchase(): void
    {
        $f = $this->fixture();
        $this->providerResponse(['*/submit' => Http::response(['status' => 'succeeded', 'cost' => '4300.75', 'retail' => '5000.25'])]);
        $order = $this->postJson('/api/v1/digital/orders', $this->input($f))->assertCreated()->assertJsonPath('data.status', 'review')->assertJsonPath('data.reservation_active', true)->json('data');
        $this->providerResponse(['*/verify' => Http::response(['status' => 'succeeded', 'transactionId' => 'retail-mismatch', 'receiptRef' => 'mismatch-receipt', 'cost' => '4300.75', 'retail' => '5000.26'])]);
        $this->postJson('/api/v1/digital/orders/'.$order['id'].'/verify', ['version' => $order['version'], 'idempotency_key' => 'verify-price-mismatch'])->assertOk()->assertJsonPath('data.status', 'review');
        $this->assertDatabaseHas('digital_orders', ['id' => $order['id'], 'actual_cost_minor' => null, 'company_transaction_id' => null]);
    }

    public function test_grants_require_direct_child_and_paused_ancestor_cannot_be_overridden(): void
    {
        $f = $this->fixture();
        $this->asPortalUser($f['owner']);
        $url = '/api/v1/digital/connections/'.$f['connection']['id'].'/grants/';
        $this->putJson($url.$f['pos']->id, ['version' => 0, 'offer_ids' => [$f['offer']['id']], 'active' => true, 'idempotency_key' => 'skip-two-parents'])->assertNotFound();
        $grant = DB::table('digital_grants')->where('target_account_id', $f['sub']->id)->first();
        $this->putJson($url.$f['sub']->id, ['version' => $grant->version, 'offer_ids' => [$f['offer']['id']], 'active' => false, 'idempotency_key' => 'pause-direct-child'])->assertOk();
        $this->asPortalUser($f['branchOwner']);
        $this->putJson($url.$f['pos']->id, ['version' => 1, 'offer_ids' => [$f['offer']['id']], 'active' => true, 'idempotency_key' => 'cannot-override-paused'])->assertOk();
        $this->asPortalUser($f['seller']);
        $this->getJson('/api/v1/digital/offers')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson('/api/v1/digital/orders', $this->input($f))->assertForbidden();
        $this->assertDatabaseCount('digital_orders', 0);
    }

    public function test_empty_grant_revokes_access_and_hidden_connection_count_is_not_leaked(): void
    {
        $f = $this->fixture();
        $this->asPortalUser($f['branchOwner']);
        $this->putJson('/api/v1/digital/connections/'.$f['connection']['id'].'/grants/'.$f['pos']->id, ['version' => 1, 'offer_ids' => [], 'active' => true, 'idempotency_key' => 'revoke-all-allowed'])->assertOk()->assertJsonPath('data.offer_ids', []);
        $this->asPortalUser($f['seller']);
        $this->getJson('/api/v1/digital/connections')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/digital/offers')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_configuration_is_owner_only_cost_cannot_be_supplied_and_stale_quote_cannot_purchase(): void
    {
        $f = $this->fixture();
        $this->postJson('/api/v1/digital/orders', array_replace($this->input($f), ['expected_retail' => '5000.26']))->assertConflict();
        $this->asPortalUser($f['owner']);
        $this->putJson('/api/v1/digital/connections/'.$f['connection']['id'], $f['configuration'])->assertForbidden();
        $this->asPortalUser($f['admin']);
        $payload = array_replace($f['configuration'], ['version' => 2, 'idempotency_key' => 'forged-cost-attempt']);
        $payload['offers'][0]['cost'] = '0.00';
        $this->putJson('/api/v1/digital/connections/'.$f['connection']['id'], $payload)->assertUnprocessable();
        $this->assertDatabaseCount('digital_orders', 0);
        $this->assertDatabaseHas('digital_offers', ['id' => $f['offer']['id'], 'cost_minor' => 430075, 'retail_minor' => 500025]);
    }

    public function test_rotating_credential_requires_a_new_actual_catalogue_before_sales(): void
    {
        $f = $this->fixture();
        $this->asPortalUser($f['admin']);
        $payload = array_replace($f['configuration'], ['version' => 2, 'credential' => 'rotated-secret-unverified', 'idempotency_key' => 'rotate-provider-secret']);
        unset($payload['catalog_snapshot_id']);
        $payload['offers'][0]['remote_id'] = '';
        $payload['offers'][0]['province_id'] = '';
        $this->putJson('/api/v1/digital/connections/'.$f['connection']['id'], $payload)->assertOk()->assertJsonPath('data.offers.0.cost', null);
        $this->asPortalUser($f['seller']);
        $this->getJson('/api/v1/digital/offers')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson('/api/v1/digital/orders', $this->input($f))->assertNotFound();
        $this->assertDatabaseCount('finance_entries', 0);
    }

    public function test_verification_retry_reuses_its_result_and_rejects_changed_payload(): void
    {
        $f = $this->fixture();
        $this->providerResponse(['*/submit' => Http::failedConnection(), '*/verify' => Http::response(['status' => 'review', 'message' => 'credential fixture-gateway-secret-123 actual-test-pin'])]);
        $order = $this->postJson('/api/v1/digital/orders', $this->input($f))->assertCreated()->json('data');
        $payload = ['version' => $order['version'], 'idempotency_key' => 'verify-same-body-only'];
        $url = '/api/v1/digital/orders/'.$order['id'].'/verify';
        $response = $this->postJson($url, $payload)->assertOk()->assertJsonPath('data.status', 'review');
        $this->assertStringNotContainsString('fixture-gateway-secret-123', $response->getContent());
        $this->postJson($url, $payload)->assertOk()->assertJsonPath('data.version', $response->json('data.version'));
        $this->postJson($url, array_replace($payload, ['version' => $response->json('data.version')]))->assertConflict();
        $this->assertCount(1, Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/verify')));
        $this->assertDatabaseCount('digital_provider_attempts', 2);
    }

    public function test_confirmed_failure_releases_logical_request_but_duplicate_provider_reference_does_not_confirm_new_sale(): void
    {
        $f = $this->fixture();
        $this->providerResponse(['*/submit' => Http::sequence()->push(['status' => 'failed'])->push(['status' => 'succeeded', 'transactionId' => 'single-company-ref', 'receiptRef' => 'single-company-receipt', 'cost' => '4300.75', 'retail' => '5000.25'])->push(['status' => 'succeeded', 'transactionId' => 'single-company-ref', 'receiptRef' => 'second-company-receipt', 'cost' => '4300.75', 'retail' => '5000.25'])]);
        $this->postJson('/api/v1/digital/orders', $this->input($f, 'company-failed-001'))->assertCreated()->assertJsonPath('data.status', 'failed')->assertJsonPath('data.reservation_active', false);
        $this->postJson('/api/v1/digital/orders/'.DigitalOrder::latest('id')->firstOrFail()->id.'/acknowledge', [])->assertOk();
        $this->postJson('/api/v1/digital/orders', $this->input($f, 'company-success-002'))->assertCreated()->assertJsonPath('data.status', 'succeeded');
        $this->postJson('/api/v1/digital/orders/'.DigitalOrder::latest('id')->firstOrFail()->id.'/acknowledge', [])->assertOk();
        $this->postJson('/api/v1/digital/orders', $this->input($f, 'company-ref-reused-003'))->assertCreated()->assertJsonPath('data.status', 'review')->assertJsonPath('data.reservation_active', true);
        $this->assertSame(1, DigitalOrder::where('status', 'succeeded')->count());
        $this->assertDatabaseCount('finance_entries', 0);
    }

    public function test_refund_only_records_company_confirmation_and_never_sends_a_new_purchase_or_internal_credit(): void
    {
        $f = $this->fixture('topup');
        $order = $this->postJson('/api/v1/digital/orders', $this->input($f))->assertCreated()->json('data');
        $this->providerResponse(['*/verify' => Http::response(['status' => 'refunded', 'transactionId' => $order['company_transaction_id'], 'remaining_balance' => '125000.35'])]);
        $url = '/api/v1/digital/orders/'.$order['id'].'/refund-status';
        $payload = ['version' => $order['version'], 'idempotency_key' => 'company-confirmed-refund'];
        $this->postJson($url, $payload)->assertForbidden();
        $this->asPortalUser($f['admin']);
        $this->postJson($url, $payload)->assertOk()->assertJsonPath('data.status', 'refunded')->assertJsonPath('data.reservation_active', false);
        $this->postJson($url, $payload)->assertOk()->assertJsonPath('data.status', 'refunded');
        $this->assertCount(1, Http::recorded());
        $this->assertTrue(str_ends_with(Http::recorded()[0][0]->url(), '/verify'));
        $this->assertDatabaseCount('finance_entries', 0);
        $this->assertDatabaseCount('finance_wallets', 0);
        $this->assertDatabaseHas('digital_connections', ['id' => $f['connection']['id'], 'company_balance_minor' => 12500035]);
    }

    public function test_database_rejects_rewriting_confirmed_financial_identity_and_terminal_attempts(): void
    {
        $f = $this->fixture();
        $order = $this->postJson('/api/v1/digital/orders', $this->input($f))->assertCreated()->json('data');
        foreach ([['quoted_retail_minor' => 1], ['actual_retail_minor' => 0], ['cost_basis' => 'catalog_snapshot'], ['status' => 'review', 'reservation_active' => true], ['receipt_ref' => null], ['account_id' => $f['main']->id]] as $change) {
            try {
                DB::table('digital_orders')->where('id', $order['id'])->update($change);
                $this->fail('Confirmed monetary identity must remain immutable.');
            } catch (QueryException) {
                $this->assertTrue(true);
            }
        }
        try {
            DB::table('digital_provider_attempts')->update(['finished_at' => null, 'active_order_id' => $order['id']]);
            $this->fail('Finished attempt cannot become active again.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }
        try {
            DB::table('digital_orders')->where('id', $order['id'])->delete();
            $this->fail('Provider financial history cannot be deleted.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }
    }

    public function test_expired_device_session_denies_purchase_before_outgoing_request(): void
    {
        $f = $this->fixture();
        $this->travel(91)->seconds();
        $count = count(Http::recorded());
        $this->postJson('/api/v1/digital/orders', $this->input($f))->assertConflict();
        $this->assertSame($count, count(Http::recorded()));
        $this->assertDatabaseCount('digital_orders', 0);
    }

    public function test_log_summary_filters_and_export_do_not_expose_pos_costs_or_codes(): void
    {
        $f = $this->fixture('topup');
        $this->postJson('/api/v1/digital/orders', $this->input($f))->assertCreated();
        $this->getJson('/api/v1/digital/orders?to='.now()->setTimezone('Asia/Baghdad')->format('Y-m-d').'&q=770123')->assertOk()->assertJsonCount(1, 'data')->assertJsonMissingPath('data.0.cost');
        $this->getJson('/api/v1/digital/orders/summary')->assertOk()->assertJsonPath('data.quantity', 1)->assertJsonPath('data.retail', '5000.25')->assertJsonMissingPath('data.cost');
        $response = $this->get('/api/v1/digital/orders/export')->assertOk();
        $csv = $response->streamedContent();
        $this->assertStringContainsString('5000.25', $csv);
        $this->assertStringNotContainsString('4300.75', $csv);
        $this->assertStringNotContainsString('actual-test-pin', $csv);
        $this->asPortalUser($f['owner']);
        $this->getJson('/api/v1/digital/orders/summary?main_account_id='.$f['main']->id)->assertOk()->assertJsonPath('data.cost', '4300.75');
    }

    private function directTopupResponses(mixed $transaction, bool $eligible = true, string $price = '4300.75'): void
    {
        config(['digital.topup.driver' => 'masal_v2_1', 'digital.topup.url' => 'https://1.1.1.1', 'digital.topup.allowed_hosts' => ['1.1.1.1']]);
        $this->providerResponse([
            '*/api/v1/products' => Http::response(['status' => 'ok', 'products' => [['type' => 'topup', 'product_id' => 'PRODUCT-51', 'title' => 'Documented category', 'price' => $price, 'show2site' => 1]]]),
            '*/api/v1/checkEligibility' => Http::response(['status' => $eligible]),
            '*/api/v1/transactions' => $transaction,
            '*/api/v1/inventory' => Http::response(['remaining_balance' => 1000000]),
        ]);
    }

    public function test_documented_topup_adapter_uses_actual_methods_header_eligibility_and_one_financial_post(): void
    {
        $f = $this->fixture('topup');
        $this->directTopupResponses(Http::response(['id' => 'provider-v21-000001', 'href' => 'Req_ETopUp_Amount', 'status' => 'Succeed']));
        $data = $this->input($f);
        $order = $this->postJson('/api/v1/digital/orders', $data)->assertCreated()->assertJsonPath('data.status', 'succeeded')->assertJsonPath('data.company_transaction_id', 'provider-v21-000001')->assertJsonPath('data.verification_supported', false)->assertJsonMissingPath('data.cost')->json('data');
        $this->postJson('/api/v1/digital/orders', $data)->assertCreated()->assertJsonPath('data.id', $order['id']);
        $this->assertCount(4, Http::recorded());
        $this->assertCount(1, Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/transactions')));
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/products') && $request->method() === 'GET' && $request->hasHeader('x-api-key', 'fixture-gateway-secret-123') && ! isset($request['credential']));
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/checkEligibility') && $request->method() === 'POST' && $request['category'] === 'PRODUCT-51' && $request['mobile'] === '9647701234567' && $request['type'] === 'topup');
        $this->assertDatabaseHas('digital_orders', ['id' => $order['id'], 'cost_basis' => 'catalog_snapshot', 'actual_cost_minor' => 430075]);
        $this->assertDatabaseHas('digital_connections', ['id' => $f['connection']['id'], 'account_id' => $f['main']->id, 'company_balance_minor' => 100000000]);
        $this->getJson('/api/v1/digital/orders/'.$order['id'].'/receipt')->assertOk()->assertJsonPath('data.code', '')->assertJsonPath('data.order.company_transaction_id', 'provider-v21-000001');
        $this->assertCount(4, Http::recorded());
        $this->assertDatabaseCount('finance_entries', 0);
    }

    public function test_direct_topup_rejects_changed_catalog_price_or_ineligible_number_before_transaction(): void
    {
        $f = $this->fixture('topup');
        $this->directTopupResponses(Http::response(['id' => 'must-not-be-bought', 'status' => 'Succeed']), true, '4300.76');
        $this->postJson('/api/v1/digital/orders', $this->input($f, 'category-price-changed'))->assertCreated()->assertJsonPath('data.status', 'failed')->assertJsonPath('data.reservation_active', false);
        Http::assertNotSent(fn ($request): bool => str_ends_with($request->url(), '/transactions'));
        $this->postJson('/api/v1/digital/orders/'.DigitalOrder::latest('id')->firstOrFail()->id.'/acknowledge', [])->assertOk();
        $this->directTopupResponses(Http::response(['id' => 'must-not-be-bought', 'status' => 'Succeed']), false);
        $this->postJson('/api/v1/digital/orders', $this->input($f, 'number-is-ineligible'))->assertCreated()->assertJsonPath('data.status', 'failed');
        Http::assertNotSent(fn ($request): bool => str_ends_with($request->url(), '/transactions'));
        $this->assertDatabaseCount('finance_entries', 0);
    }

    public function test_direct_topup_timeout_never_uses_an_invented_verify_endpoint_or_repeats_purchase(): void
    {
        $f = $this->fixture('topup');
        $this->directTopupResponses(Http::failedConnection());
        $input = $this->input($f);
        $order = $this->postJson('/api/v1/digital/orders', $input)->assertCreated()->assertJsonPath('data.status', 'review')->assertJsonPath('data.reservation_active', true)->json('data');
        $this->postJson('/api/v1/digital/orders', $input)->assertCreated()->assertJsonPath('data.id', $order['id']);
        $count = count(Http::recorded());
        $this->postJson('/api/v1/digital/orders/'.$order['id'].'/verify', ['version' => $order['version'], 'idempotency_key' => 'v21-has-no-status-path'])->assertConflict();
        $this->assertSame($count, count(Http::recorded()));
        $this->assertDatabaseCount('digital_provider_attempts', 1);
        $this->assertDatabaseCount('finance_entries', 0);
    }

    public function test_unknown_or_floating_company_prices_cannot_create_a_zero_cost_sale_and_private_gateway_is_blocked(): void
    {
        $f = $this->fixture();
        $this->asPortalUser($f['admin']);
        $this->providerResponse(['*/catalog' => Http::response(['data' => [['catalogId' => 71, 'title' => 'Invalid cost', 'vendorPrice' => 4300.75, 'publicPrice' => '5000.25']]])]);
        $this->postJson('/api/v1/digital/connections/'.$f['connection']['id'].'/catalog', ['version' => 2])->assertUnprocessable();
        $count = count(Http::recorded());
        config(['digital.gateway_url' => 'https://127.0.0.1/provider', 'digital.allowed_hosts' => ['127.0.0.1']]);
        $this->postJson('/api/v1/digital/connections/'.$f['connection']['id'].'/catalog', ['version' => 2])->assertStatus(503);
        $this->assertSame($count, count(Http::recorded()));
        $this->assertDatabaseCount('digital_orders', 0);
    }

    public function test_digital_assignment_stays_digital_when_connection_or_grant_is_paused(): void
    {
        $f = $this->fixture('topup');
        $access = app(DigitalAccess::class);
        $this->assertTrue($access->isAssignedProduct($f['pos'], $f['product']->id));
        DB::table('digital_connections')->where('id', $f['connection']['id'])->update(['active' => false]);
        DB::table('digital_grants')->update(['active' => false]);
        $this->assertTrue($access->isAssignedProduct($f['pos'], $f['product']->id));
        $otherMain = $this->account(AccountType::MainAgent, $f['system']);
        $otherPos = $this->account(AccountType::Pos, $otherMain);
        $this->assertFalse($access->isAssignedProduct($otherPos, $f['product']->id));
    }

    public function test_stock_catalog_excludes_linked_and_paused_digital_categories_but_restores_unlinked_categories(): void
    {
        $f = $this->fixture('topup');
        $f['company']->update(['connection' => 'ملفات']);
        $url = '/api/v1/sales/products';
        $this->getJson($url)->assertOk()->assertJsonCount(0, 'data');
        DB::table('digital_connections')->where('id', $f['connection']['id'])->update(['active' => false]);
        DB::table('digital_grants')->update(['active' => false]);
        $this->getJson($url)->assertOk()->assertJsonCount(0, 'data');
        DB::table('digital_offers')->where('id', $f['offer']['id'])->update(['listed' => false, 'active' => false]);
        $this->getJson($url)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $f['product']->id);
        $this->assertFalse(app(DigitalAccess::class)->isAssignedProduct($f['pos'], $f['product']->id));
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('finance_entries', 0);
    }

    public function test_premium_subscriber_is_validated_encrypted_and_never_in_metadata_or_audit(): void
    {
        $this->premium = true;
        $f = $this->fixture();
        $input = $this->input($f) + ['mobile' => '07701234567', 'confirm_mobile' => '07701234567', 'first_name' => 'Premium Private Name', 'last_name' => 'Private Family', 'bein_province_id' => '2'];
        $this->postJson('/api/v1/digital/orders', array_replace($input, ['bein_province_id' => '99']))->assertUnprocessable();
        $this->assertDatabaseCount('digital_orders', 0);
        $order = $this->postJson('/api/v1/digital/orders', $input)->assertCreated()->assertJsonPath('data.status', 'succeeded')->assertJsonMissingPath('data.subscriber')->json('data');
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/submit') && $request['subscriber']['beinProvinceId'] === '2' && $request['subscriber']['phone'] === '+9647701234567' && $request['subscriber']['firstName'] === 'Premium Private Name');
        $this->assertStringNotContainsString('Premium Private Name', DB::table('digital_orders')->where('id', $order['id'])->value('subscriber'));
        $this->assertStringNotContainsString('Premium Private Name', DB::table('audit_logs')->pluck('details')->implode(' '));
        $this->assertDatabaseCount('finance_entries', 0);
    }

    public function test_pos_history_paginates_both_real_sources_and_never_returns_another_pos_or_private_codes(): void
    {
        $f = $this->fixture('topup');
        $digital = $this->postJson('/api/v1/digital/orders', $this->input($f))->assertCreated()->json('data');
        $this->travel(1)->minutes();
        $stock = Sale::factory()->create(['main_account_id' => $f['main']->id, 'account_id' => $f['pos']->id, 'creator_id' => $f['seller']->id]);
        $otherPos = $this->account(AccountType::Pos, $f['main']);
        Sale::factory()->create(['main_account_id' => $f['main']->id, 'account_id' => $otherPos->id, 'creator_id' => $this->userFor($otherPos)->id]);
        $this->getJson('/api/v1/digital/history?per_page=1')->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('meta.last_page', 2)->assertJsonPath('data.0.kind', 'stock')->assertJsonPath('data.0.record.id', $stock->id)->assertJsonMissingPath('data.0.record.cards');
        $this->getJson('/api/v1/digital/history?per_page=1&page=2')->assertOk()->assertJsonPath('data.0.kind', 'digital')->assertJsonPath('data.0.record.id', $digital['id'])->assertJsonMissingPath('data.0.record.cost')->assertJsonMissingPath('data.0.record.receipt');
        $this->getJson('/api/v1/digital/history?kind=topup')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.kind', 'digital');
        $this->getJson('/api/v1/digital/history?account_id='.$otherPos->id)->assertUnprocessable();
        $this->getJson('/api/v1/digital/history?q=digital-order')->assertOk()->assertJsonPath('meta.total', 1);
        $this->asPortalUser($f['owner']);
        $this->getJson('/api/v1/digital/history')->assertForbidden();
    }

    public function test_print_authorization_checks_current_policy_and_real_device_without_claiming_physical_success(): void
    {
        $f = $this->fixture('topup');
        $order = $this->postJson('/api/v1/digital/orders', $this->input($f))->assertCreated()->json('data');
        $count = count(Http::recorded());
        $this->getJson('/api/v1/digital/orders/'.$order['id'].'/print-authorization')->assertOk()->assertJsonPath('data.authorized', true);
        $policy = PrintPolicy::findOrFail(1);
        $settings = $policy->settings;
        $settings['printing_enabled'] = false;
        $policy->update(['settings' => $settings]);
        $this->getJson('/api/v1/digital/orders/'.$order['id'].'/print-authorization')->assertConflict();
        $settings['printing_enabled'] = true;
        $policy->update(['settings' => $settings]);
        $this->travel(91)->seconds();
        $this->getJson('/api/v1/digital/orders/'.$order['id'].'/print-authorization')->assertConflict();
        $this->assertSame($count, count(Http::recorded()));
        $this->assertDatabaseHas('digital_orders', ['id' => $order['id'], 'status' => 'succeeded', 'version' => $order['version']]);
        $this->assertDatabaseCount('digital_provider_attempts', 1);
        $this->assertDatabaseCount('finance_entries', 0);
    }

    public function test_operation_sales_stop_denies_new_purchase_but_preserves_verification_and_printing_as_separate_actions(): void
    {
        $f = $this->fixture('topup');
        $this->providerResponse(['*/submit' => Http::failedConnection(), '*/verify' => Http::response(['status' => 'succeeded', 'transactionId' => 'operation-stop-verified', 'receiptRef' => 'operation-stop-receipt', 'cost' => '4300.75', 'retail' => '5000.25'])]);
        $order = $this->postJson('/api/v1/digital/orders', $this->input($f))->assertCreated()->assertJsonPath('data.status', 'review')->json('data');
        $stop = DirectStop::factory()->create(['account_id' => $f['branch']->id]);
        $count = count(Http::recorded());
        $this->postJson('/api/v1/digital/orders', $this->input($f, 'blocked-by-sales-stop'))->assertStatus(423);
        $this->assertSame($count, count(Http::recorded()));
        $this->postJson('/api/v1/digital/orders/'.$order['id'].'/verify', ['version' => $order['version'], 'idempotency_key' => 'verify-despite-sales-stop'])->assertOk()->assertJsonPath('data.status', 'succeeded');
        $this->getJson('/api/v1/digital/orders/'.$order['id'].'/print-authorization')->assertOk()->assertJsonPath('data.authorized', true);
        $stop->update(['stops' => ['printing' => true, 'sales' => false]]);
        $this->getJson('/api/v1/digital/orders/'.$order['id'].'/print-authorization')->assertStatus(423);
        $this->assertDatabaseCount('digital_orders', 1);
        $this->assertDatabaseCount('finance_entries', 0);
    }

    public function test_sales_revocation_between_intent_and_dispatch_blocks_outgoing_purchase_before_durable_attempt(): void
    {
        $f = $this->fixture('topup');
        $request = Request::create('/digital/orders', 'POST', $this->input($f));
        $request->attributes->set('portal', 'pos');
        $request->setLaravelSession(app('session')->driver());
        $request->setUserResolver(fn () => $f['seller']);
        $operations = app(DigitalOperations::class);
        $order = $operations->begin($f['seller'], $this->input($f), $request);
        DirectStop::factory()->create(['account_id' => $f['pos']->id]);
        $count = count(Http::recorded());
        try {
            $operations->dispatch($f['seller'], $order->id, 'submit', $request);
            $this->fail('Sales stop must be checked again before a provider attempt.');
        } catch (HttpException $exception) {
            $this->assertSame(423, $exception->getStatusCode());
        }
        $this->assertSame($count, count(Http::recorded()));
        $this->assertDatabaseCount('digital_provider_attempts', 0);
        $this->assertDatabaseHas('digital_orders', ['id' => $order->id, 'status' => 'pending', 'reservation_active' => true]);
        $this->assertDatabaseCount('finance_entries', 0);
    }

    private function rabiaaPurchase(string $beinStatus = 'success', string $cost = '4300.80'): string
    {
        $activation = json_encode($this->premium ? $beinStatus : null, JSON_THROW_ON_ERROR);

        return <<<JSON
            {"data":{"cardId":1024,"serialNumber":"SN-001024","code":"actual-nojoom-private-pin","transactionId":512,"publicPrice":5000.25,"vendorPrice":{$cost},"beinStatus":{$activation}}}
            JSON;
    }

    private function rabiaaHold(): string
    {
        $heldAt = json_encode(now()->toISOString(), JSON_THROW_ON_ERROR);

        return <<<JSON
            {"data":{"holdToken":"a1b2c3d4-e5f6-7890-abcd-ef1234567890","cardId":1024,"heldAt":{$heldAt},"expiresInMinutes":23,"vendorPrice":4300.75}}
            JSON;
    }

    private function directRabiaaResponses(mixed $purchase, mixed $hold = null, ?string $history = null): void
    {
        config(['digital.rabiaa.driver' => 'nojoom_v2_1', 'digital.rabiaa.url' => 'https://1.1.1.1/api/v2', 'digital.rabiaa.allowed_hosts' => ['1.1.1.1']]);
        $package = $this->premium ? 'premium' : 'standard';
        $catalog = <<<JSON
            {"data":[{"catalogId":71,"title":"Official provider card","province":"بغداد","packageType":"{$package}","vendorPrice":4300.75,"publicPrice":5000.25,"inStock":true}]}
            JSON;
        $this->providerResponse([
            '*/vendor/provinces' => Http::response(['data' => [['id' => 2, 'title' => 'بغداد', 'prefix' => 'BGD']]]),
            '*/vendor/catalog*' => Http::response($catalog),
            '*/vendor/bein/provinces' => Http::response(['data' => [['id' => 12, 'name' => 'بغداد BeIN']]]),
            '*/vendor/hold' => $hold ?? Http::response($this->rabiaaHold()),
            '*/vendor/purchase' => $purchase,
            '*/vendor/transactions*' => Http::response($history ?? ['data' => [], 'meta' => ['pagination' => ['page' => 1, 'pageCount' => 1, 'pageSize' => 100, 'total' => 0]]]),
        ]);
    }

    private function rabiaaHistory(string $beinStatus = 'success', bool $refunded = false, int $refundOf = 512): string
    {
        $activation = json_encode($this->premium ? $beinStatus : null, JSON_THROW_ON_ERROR);
        $createdAt = json_encode(now()->toISOString(), JSON_THROW_ON_ERROR);
        $refundIds = $refunded ? '[513]' : '[]';
        $refund = $refunded ? ',{"id":513,"type":"refund","cardId":1024,"catalogId":71,"vendorPrice":-4300.80,"publicPrice":5000.25,"refundOfId":'.$refundOf.'}' : '';
        $total = $refunded ? 2 : 1;

        return <<<JSON
            {"data":[{"id":512,"type":"purchase","cardId":1024,"catalogId":71,"vendorPrice":4300.80,"publicPrice":5000.25,"beinStatus":{$activation},"createdAt":{$createdAt},"refundIds":{$refundIds}}{$refund}],"meta":{"pagination":{"page":1,"pageCount":1,"pageSize":100,"total":{$total}}}}
            JSON;
    }

    public function test_documented_fourth_purchase_holds_once_records_actual_changed_charge_and_preserves_private_pin_immediately(): void
    {
        $f = $this->fixture();
        $this->directRabiaaResponses(Http::response($this->rabiaaPurchase()));
        $input = $this->input($f);
        $order = $this->postJson('/api/v1/digital/orders', $input)->assertCreated()->assertJsonPath('data.status', 'succeeded')->assertJsonPath('data.company_transaction_id', '512')->assertJsonPath('data.company_purchase_confirmed', true)->assertJsonPath('data.receipt_available', true)->assertJsonMissingPath('data.cost')->assertJsonMissingPath('data.provider_hold')->assertJsonMissingPath('data.receipt')->json('data');
        $this->postJson('/api/v1/digital/orders', $input)->assertCreated()->assertJsonPath('data.id', $order['id']);
        $this->assertCount(3, Http::recorded());
        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/vendor/catalog') && $request->method() === 'GET' && $request['provinceId'] === 2 && $request->hasHeader('X-API-Key', 'fixture-gateway-secret-123'));
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/vendor/hold') && $request->method() === 'POST' && $request->data() === ['catalogId' => 71]);
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/vendor/purchase') && $request->method() === 'POST' && $request->data() === ['holdToken' => 'a1b2c3d4-e5f6-7890-abcd-ef1234567890']);
        $this->assertDatabaseHas('digital_orders', ['id' => $order['id'], 'quoted_cost_minor' => 430075, 'actual_cost_minor' => 430080, 'actual_retail_minor' => 500025, 'cost_basis' => 'provider_response', 'main_account_id' => $f['main']->id]);
        $saved = DigitalOrder::findOrFail($order['id']);
        $this->assertSame(23, $saved->provider_hold['expiresInMinutes']);
        $this->assertStringNotContainsString('actual-nojoom-private-pin', DB::table('digital_orders')->where('id', $order['id'])->value('receipt'));
        $this->assertStringNotContainsString('a1b2c3d4-e5f6-7890-abcd-ef1234567890', DB::table('digital_orders')->where('id', $order['id'])->value('provider_hold'));
        $this->assertStringNotContainsString('actual-nojoom-private-pin', DB::table('audit_logs')->pluck('details')->implode(' '));
        foreach (['provider_hold', 'provider_evidence', 'provider_purchase_response', 'provider_purchase_started_at'] as $column) {
            try {
                DB::table('digital_orders')->where('id', $order['id'])->update([$column => null]);
                $this->fail('Actual provider evidence cannot be erased or rewritten.');
            } catch (QueryException) {
                $this->assertTrue(true);
            }
        }
        $this->getJson('/api/v1/digital/orders/'.$order['id'].'/receipt')->assertOk()->assertJsonPath('data.code', 'actual-nojoom-private-pin')->assertJsonPath('data.serial', 'SN-001024');
        $this->assertCount(3, Http::recorded());
        $this->assertDatabaseCount('finance_entries', 0);
    }

    #[TestWith(['4300.80', 'succeeded', 430080])]
    #[TestWith(['4300.805', 'review', null])]
    public function test_fourth_raw_wire_money_remains_exact_under_high_host_serialization_precision(string $cost, string $status, ?int $actualCost): void
    {
        $precision = (string) ini_get('precision');
        $serializationPrecision = (string) ini_get('serialize_precision');
        try {
            ini_set('precision', '14');
            ini_set('serialize_precision', '100');
            $f = $this->fixture();
            $this->directRabiaaResponses(Http::response($this->rabiaaPurchase('success', $cost)));
            $input = $this->input($f);

            $order = $this->postJson('/api/v1/digital/orders', $input)->assertCreated()->assertJsonPath('data.status', $status)->assertJsonMissingPath('data.cost')->assertJsonMissingPath('data.receipt')->json('data');

            $saved = DigitalOrder::findOrFail($order['id']);
            $this->assertSame($cost, $saved->provider_purchase_response['vendorPrice']);
            $this->assertSame($actualCost, $saved->actual_cost_minor);
            $this->assertSame('actual-nojoom-private-pin', $saved->receipt['code']);
            $this->assertStringNotContainsString('actual-nojoom-private-pin', DB::table('digital_orders')->where('id', $saved->id)->value('receipt'));
            $this->postJson('/api/v1/digital/orders', $input)->assertCreated()->assertJsonPath('data.id', $saved->id);
            $this->assertCount(1, Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/vendor/purchase')));
            $this->assertDatabaseCount('finance_wallets', 0);
            $this->assertDatabaseCount('finance_entries', 0);
        } finally {
            ini_set('precision', $precision);
            ini_set('serialize_precision', $serializationPrecision);
        }
    }

    public function test_fourth_catalog_reads_served_province_and_separate_bein_list_without_inventing_remaining_balance(): void
    {
        $this->premium = true;
        $f = $this->fixture();
        $this->directRabiaaResponses(Http::response($this->rabiaaPurchase()));
        $this->asPortalUser($f['admin']);
        $this->postJson('/api/v1/digital/connections/'.$f['connection']['id'].'/catalog', ['version' => 2])->assertOk()->assertJsonPath('data.catalog.0.province_id', '2')->assertJsonPath('data.catalog.0.cost', '4300.75')->assertJsonPath('data.bein_provinces.0.id', '12');
        $count = count(Http::recorded());
        $this->postJson('/api/v1/digital/connections/'.$f['connection']['id'].'/balance')->assertConflict();
        $this->assertSame($count, count(Http::recorded()));
        $this->getJson('/api/v1/digital/connections')->assertOk()->assertJsonPath('data.0.company_balance', null)->assertJsonPath('data.0.gateway.balance_supported', false);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/vendor/account'));
    }

    public function test_fourth_hold_timeout_never_reserves_again_or_sends_purchase(): void
    {
        $f = $this->fixture();
        $this->directRabiaaResponses(Http::response($this->rabiaaPurchase()), Http::failedConnection());
        $input = $this->input($f);
        $order = $this->postJson('/api/v1/digital/orders', $input)->assertCreated()->assertJsonPath('data.status', 'review')->json('data');
        $this->postJson('/api/v1/digital/orders', $input)->assertCreated()->assertJsonPath('data.id', $order['id']);
        $this->assertCount(1, Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/vendor/hold')));
        Http::assertNotSent(fn ($request): bool => str_ends_with($request->url(), '/vendor/purchase'));
        $this->assertDatabaseHas('digital_orders', ['id' => $order['id'], 'provider_hold' => null, 'provider_purchase_started_at' => null]);
        $this->assertDatabaseCount('finance_entries', 0);
    }

    public function test_fourth_purchase_timeout_uses_readonly_history_and_never_claims_a_pin_or_resends_purchase(): void
    {
        $f = $this->fixture();
        $this->directRabiaaResponses(Http::failedConnection(), null, $this->rabiaaHistory());
        $input = $this->input($f);
        $order = $this->postJson('/api/v1/digital/orders', $input)->assertCreated()->assertJsonPath('data.status', 'review')->json('data');
        $this->postJson('/api/v1/digital/orders', $input)->assertCreated();
        $result = $this->postJson('/api/v1/digital/orders/'.$order['id'].'/verify', ['version' => $order['version'], 'idempotency_key' => 'nojoom-purchase-timeout'])->assertOk()->assertJsonPath('data.status', 'review')->assertJsonPath('data.company_purchase_confirmed', true)->assertJsonPath('data.receipt_missing', true)->assertJsonPath('data.receipt_available', false)->assertJsonPath('data.company_transaction_id', '512')->json('data');
        $this->getJson('/api/v1/digital/orders/'.$order['id'].'/receipt')->assertConflict();
        $this->assertCount(1, Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/vendor/purchase')));
        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/vendor/transactions') && $request->method() === 'GET');
        $this->assertDatabaseHas('digital_orders', ['id' => $order['id'], 'actual_cost_minor' => 430080, 'receipt' => null, 'reservation_active' => true]);
        $this->assertDatabaseCount('finance_entries', 0);
    }

    public function test_fourth_premium_uses_bein_province_e164_and_waits_for_real_activation_without_losing_pin(): void
    {
        $this->premium = true;
        $this->beinProvince = 12;
        $f = $this->fixture();
        $this->directRabiaaResponses(Http::response($this->rabiaaPurchase('pending')), null, $this->rabiaaHistory());
        $input = $this->input($f) + ['mobile' => '07701234567', 'confirm_mobile' => '07701234567', 'first_name' => 'Actual First', 'last_name' => 'Actual Last', 'bein_province_id' => '12'];
        $order = $this->postJson('/api/v1/digital/orders', $input)->assertCreated()->assertJsonPath('data.status', 'review')->assertJsonPath('data.company_purchase_confirmed', true)->assertJsonPath('data.receipt_available', false)->json('data');
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/vendor/hold') && $request['beinProvinceId'] === 12 && $request['phone'] === '+9647701234567' && $request['firstName'] === 'Actual First');
        $this->getJson('/api/v1/digital/orders/'.$order['id'].'/receipt')->assertConflict();
        $this->postJson('/api/v1/digital/orders/'.$order['id'].'/verify', ['version' => $order['version'], 'idempotency_key' => 'nojoom-premium-activation'])->assertOk()->assertJsonPath('data.status', 'succeeded');
        $this->getJson('/api/v1/digital/orders/'.$order['id'].'/receipt')->assertOk()->assertJsonPath('data.code', 'actual-nojoom-private-pin');
        $this->assertCount(1, Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/vendor/purchase')));
        $this->assertDatabaseCount('finance_entries', 0);
    }

    public function test_fourth_refund_requires_actual_linked_negative_transaction_and_never_creates_local_credit(): void
    {
        $f = $this->fixture();
        $this->directRabiaaResponses(Http::response($this->rabiaaPurchase()), null, $this->rabiaaHistory('success', true, 999));
        $order = $this->postJson('/api/v1/digital/orders', $this->input($f))->assertCreated()->json('data');
        $this->asPortalUser($f['admin']);
        $url = '/api/v1/digital/orders/'.$order['id'].'/refund-status';
        $order = $this->postJson($url, ['version' => $order['version'], 'idempotency_key' => 'foreign-refund-not-valid'])->assertOk()->assertJsonPath('data.status', 'succeeded')->json('data');
        $this->directRabiaaResponses(Http::response($this->rabiaaPurchase()), null, $this->rabiaaHistory('success', true));
        $this->postJson($url, ['version' => $order['version'], 'idempotency_key' => 'nojoom-linked-refund-ok'])->assertOk()->assertJsonPath('data.status', 'refunded')->assertJsonPath('data.reservation_active', false);
        $this->assertCount(1, Http::recorded());
        Http::assertNotSent(fn ($request): bool => str_ends_with($request->url(), '/vendor/purchase'));
        $this->assertDatabaseCount('finance_entries', 0);
        $this->assertDatabaseCount('finance_wallets', 0);
    }

    public function test_fourth_permission_revocation_after_hold_prevents_purchase_and_bad_price_never_loses_returned_pin(): void
    {
        $f = $this->fixture();
        $this->directRabiaaResponses(Http::response($this->rabiaaPurchase()), function () use ($f) {
            DirectStop::factory()->create(['account_id' => $f['pos']->id]);

            return Http::response($this->rabiaaHold());
        });
        $this->postJson('/api/v1/digital/orders', $this->input($f))->assertCreated()->assertJsonPath('data.status', 'failed');
        Http::assertNotSent(fn ($request): bool => str_ends_with($request->url(), '/vendor/purchase'));
        DirectStop::whereKey($f['pos']->id)->delete();
        $this->postJson('/api/v1/digital/orders/'.DigitalOrder::latest('id')->firstOrFail()->id.'/acknowledge', [])->assertOk();
        $this->directRabiaaResponses(Http::response($this->rabiaaPurchase('success', '4300.805')));
        $order = $this->postJson('/api/v1/digital/orders', $this->input($f, 'nojoom-bad-money-response'))->assertCreated()->assertJsonPath('data.status', 'review')->assertJsonMissingPath('data.receipt')->json('data');
        $saved = DigitalOrder::findOrFail($order['id']);
        $this->assertSame('actual-nojoom-private-pin', $saved->receipt['code']);
        $this->assertSame('4300.805', $saved->provider_purchase_response['vendorPrice']);
        $this->assertNull($saved->actual_cost_minor);
        $this->getJson('/api/v1/digital/orders/'.$order['id'].'/receipt')->assertConflict();
        $this->assertDatabaseCount('finance_entries', 0);
    }

    public function test_fourth_known_out_of_stock_hold_failure_releases_request_without_purchase(): void
    {
        $f = $this->fixture();
        $this->directRabiaaResponses(Http::response($this->rabiaaPurchase()), Http::response(['error' => 'No available cards for this type and province.'], 404));
        $this->postJson('/api/v1/digital/orders', $this->input($f))->assertCreated()->assertJsonPath('data.status', 'failed')->assertJsonPath('data.reservation_active', false);
        Http::assertNotSent(fn ($request): bool => str_ends_with($request->url(), '/vendor/purchase'));
        $this->assertDatabaseCount('finance_entries', 0);
    }

    public function test_fourth_confirmed_refund_of_a_charged_purchase_with_missing_pin_releases_review_without_fabricating_receipt(): void
    {
        $f = $this->fixture();
        $this->directRabiaaResponses(Http::failedConnection(), null, $this->rabiaaHistory());
        $order = $this->postJson('/api/v1/digital/orders', $this->input($f))->assertCreated()->json('data');
        $order = $this->postJson('/api/v1/digital/orders/'.$order['id'].'/verify', ['version' => $order['version'], 'idempotency_key' => 'verify-charged-missing-pin'])->assertOk()->assertJsonPath('data.status', 'review')->json('data');
        $this->asPortalUser($f['admin']);
        $this->directRabiaaResponses(Http::response($this->rabiaaPurchase()), null, $this->rabiaaHistory('success', true));
        $this->postJson('/api/v1/digital/orders/'.$order['id'].'/refund-status', ['version' => $order['version'], 'idempotency_key' => 'refund-charged-missing-pin'])->assertOk()->assertJsonPath('data.status', 'refunded')->assertJsonPath('data.reservation_active', false)->assertJsonPath('data.receipt_available', false);
        $this->assertDatabaseHas('digital_orders', ['id' => $order['id'], 'actual_cost_minor' => 430080, 'receipt' => null]);
        Http::assertNotSent(fn ($request): bool => str_ends_with($request->url(), '/vendor/purchase'));
        $this->assertDatabaseCount('finance_entries', 0);
        $this->assertDatabaseCount('finance_wallets', 0);
    }

    public function test_company_balance_is_authoritative_and_is_not_subject_to_the_per_purchase_price_cap(): void
    {
        $f = $this->fixture('topup');
        $this->asPortalUser($f['admin']);
        $this->providerResponse(['*/inventory' => Http::response(['remaining_balance' => '200000000.25'])]);
        $this->postJson('/api/v1/digital/connections/'.$f['connection']['id'].'/balance')->assertOk()->assertJsonPath('data.company_balance', '200000000.25');
        $this->assertDatabaseHas('digital_connections', ['id' => $f['connection']['id'], 'account_id' => $f['main']->id, 'company_balance_minor' => 20000000025]);
        $this->assertDatabaseCount('finance_wallets', 0);
        $this->assertDatabaseCount('finance_entries', 0);
    }

    public function test_safety_pos_cannot_send_even_with_conflicting_notification_permission(): void
    {
        $f = $this->fixture('topup');
        $this->supportGrant($f['seller'], 'notifications.send');
        $before = DB::table('notices')->count();
        $this->postJson('/api/v1/notifications', ['title' => 'Audit', 'body' => 'Audit', 'mode' => 'all', 'idempotency_key' => 'audit-pos-notice'])->assertUnprocessable();
        $this->assertDatabaseCount('notices', $before);
    }

    public function test_safety_inventory_failure_keeps_confirmed_topup_success(): void
    {
        $f = $this->fixture('topup');
        $this->directTopupResponses(Http::response(['id' => 'audit-inventory', 'status' => 'Succeed']));
        $this->providerResponse([
            '*/api/v1/products' => Http::response(['status' => 'ok', 'products' => [['product_id' => 'PRODUCT-51', 'title' => 'Audit', 'type' => 'topup', 'price' => '4300.75']]]),
            '*/api/v1/checkEligibility' => Http::response(['status' => true]),
            '*/api/v1/transactions' => Http::response(['id' => 'audit-inventory', 'status' => 'Succeed']),
            '*/api/v1/inventory' => Http::failedConnection(),
        ]);
        $this->postJson('/api/v1/digital/orders', $this->input($f))->assertCreated()->assertJsonPath('data.status', 'succeeded');
        $this->assertNull(DigitalConnection::findOrFail($f['connection']['id'])->company_balance_minor);
    }

    public function test_safety_pin_is_preserved_when_non_secret_response_field_has_invalid_shape(): void
    {
        $f = $this->fixture();
        $purchase = json_decode($this->rabiaaPurchase(), true);
        $purchase['data']['soldAt'] = ['unexpected' => 'shape'];
        $this->directRabiaaResponses(Http::response($purchase));
        $row = $this->postJson('/api/v1/digital/orders', $this->input($f))->assertCreated()->assertJsonPath('data.status', 'review')->json('data');
        $this->assertNotNull(DigitalOrder::findOrFail($row['id'])->receipt);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/vendor/purchase'));
    }

    public function test_safety_topup_purchase_is_blocked_after_connection_disabled_during_eligibility(): void
    {
        $f = $this->fixture('topup');
        $this->directTopupResponses(Http::response(['id' => 'audit-confirmed', 'status' => 'Succeed']));
        $this->providerResponse([
            '*/api/v1/products' => Http::response(['status' => 'ok', 'products' => [['product_id' => 'PRODUCT-51', 'title' => 'Audit', 'type' => 'topup', 'price' => '4300.75']]]),
            '*/api/v1/checkEligibility' => function () use ($f) {
                DigitalConnection::findOrFail($f['connection']['id'])->update(['active' => false]);

                return Http::response(['status' => true]);
            },
            '*/api/v1/transactions' => Http::response(['id' => 'audit-confirmed', 'status' => 'Succeed']),
            '*/api/v1/inventory' => Http::response(['remaining_balance' => 90000]),
        ]);
        $this->postJson('/api/v1/digital/orders', $this->input($f))->assertCreated()->assertJsonPath('data.status', 'failed')->assertJsonPath('data.company_purchase_confirmed', false);
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/transactions'));
    }

    public function test_safety_rotated_key_invalidates_previous_company_balance(): void
    {
        $f = $this->fixture('topup');
        $this->asPortalUser($f['admin']);
        $this->postJson('/api/v1/digital/connections/'.$f['connection']['id'].'/balance')->assertOk();
        $payload = $f['configuration'];
        unset($payload['catalog_snapshot_id']);
        $payload['version'] = 2;
        $payload['offers'] = [];
        $payload['credential'] = 'audit-new-fake-key-only';
        $payload['idempotency_key'] = 'audit-key-rotation';
        $this->putJson('/api/v1/digital/connections/'.$f['connection']['id'], $payload)->assertOk()->assertJsonPath('data.company_balance', null)->assertJsonPath('data.balance_updated_at', null);
    }

    public function test_safety_catalog_cost_is_estimated_in_log_and_dashboard(): void
    {
        $f = $this->fixture('topup');
        $this->directTopupResponses(Http::response(['id' => 'audit-cost', 'status' => 'success']));
        $this->postJson('/api/v1/digital/orders', $this->input($f))->assertCreated();
        $this->asPortalUser($f['admin']);
        $this->getJson('/api/v1/digital/orders/summary?provider=topup')->assertOk()->assertJsonPath('data.cost', null)->assertJsonPath('data.estimated_cost', '4300.75')->assertJsonPath('data.catalog_cost_count', 1);
        $cards = collect($this->getJson('/api/v1/dashboard/summary')->assertOk()->json('data.cards'));
        $this->assertNull($cards->firstWhere('key', 'digital:topup')['value']);
    }

    public function test_safety_recovery_survives_lost_response_and_requires_explicit_new_sale(): void
    {
        $f = $this->fixture('topup');
        $this->directTopupResponses(Http::response(['id' => 'recovered-provider-ref', 'status' => 'Succeed', 'totalAmount' => ['value' => '9999.99', 'unit' => 'IQD']]));
        $input = $this->input($f);
        $order = $this->postJson('/api/v1/digital/orders', $input)->assertCreated()->assertJsonPath('data.company_purchase_confirmed', true)->assertJsonPath('data.receipt_missing', false)->json('data');
        $saved = DigitalOrder::findOrFail($order['id']);
        $this->assertSame('catalog_snapshot', $saved->cost_basis);
        $this->assertSame(430075, $saved->actual_cost_minor);
        $this->assertSame('9999.99', $saved->provider_purchase_response['totalAmount']['value']);
        $this->assertStringNotContainsString('recovered-provider-ref', $saved->getRawOriginal('provider_purchase_response'));
        $this->getJson('/api/v1/digital/orders/recovery')->assertOk()->assertJsonPath('data.order.id', $order['id'])->assertJsonMissingPath('data.order.provider_purchase_response');
        $this->postJson('/api/v1/digital/orders', $this->input($f, 'new-browser-request'))->assertConflict();
        $this->postJson('/api/v1/digital/orders', $input)->assertCreated()->assertJsonPath('data.id', $order['id']);
        $this->assertCount(1, Http::recorded(fn ($r): bool => str_ends_with($r->url(), '/transactions')));
        $this->postJson('/api/v1/digital/orders/'.$order['id'].'/acknowledge', [])->assertOk();
        $this->postJson('/api/v1/digital/orders/'.$order['id'].'/acknowledge', [])->assertOk();
        $this->getJson('/api/v1/digital/orders/recovery')->assertOk()->assertJsonPath('data.order', null);
        $this->directTopupResponses(Http::response(['id' => 'second-intent-ref', 'status' => 'Succeed']));
        $this->postJson('/api/v1/digital/orders', $this->input($f, 'new-browser-request'))->assertCreated()->assertJsonPath('data.status', 'succeeded');
        $this->assertDatabaseCount('digital_orders', 2);
    }

    public function test_safety_uncertain_sale_cannot_be_acknowledged_or_reissued_and_other_pos_cannot_read_it(): void
    {
        $f = $this->fixture('topup');
        $this->directTopupResponses(Http::failedConnection());
        $order = $this->postJson('/api/v1/digital/orders', $this->input($f))->assertCreated()->assertJsonPath('data.status', 'review')->json('data');
        $this->getJson('/api/v1/digital/orders/recovery')->assertOk()->assertJsonPath('data.order.id', $order['id']);
        $this->postJson('/api/v1/digital/orders/'.$order['id'].'/acknowledge', [])->assertConflict();
        $this->postJson('/api/v1/digital/orders', $this->input($f, 'new-uncertain-intent'))->assertConflict();
        $other = $this->userFor($this->account(AccountType::Pos, $f['branch']));
        $this->asPortalUser($other);
        $this->getJson('/api/v1/digital/orders/recovery')->assertOk()->assertJsonPath('data.order', null);
        $this->postJson('/api/v1/digital/orders/'.$order['id'].'/acknowledge', [])->assertNotFound();
        $this->assertCount(1, Http::recorded(fn ($r): bool => str_ends_with($r->url(), '/transactions')));
    }

    #[TestWith(['grant'])]
    #[TestWith(['offer'])]
    #[TestWith(['stop'])]
    public function test_safety_topup_rechecks_current_grant_price_and_stop_after_eligibility(string $change): void
    {
        $f = $this->fixture('topup');
        $this->directTopupResponses(Http::response(['id' => 'never-sent', 'status' => 'Succeed']));
        $this->providerResponse([
            '*/api/v1/products' => Http::response(['status' => 'ok', 'products' => [['product_id' => 'PRODUCT-51', 'title' => 'Category', 'type' => 'topup', 'price' => '4300.75']]]),
            '*/api/v1/checkEligibility' => function () use ($f, $change) {
                if ($change === 'grant') {
                    DigitalGrant::where('target_account_id', $f['pos']->id)->update(['active' => false]);
                } elseif ($change === 'offer') {
                    DigitalOffer::whereKey($f['offer']['id'])->increment('version');
                } else {
                    DirectStop::factory()->create(['account_id' => $f['pos']->id]);
                }

                return Http::response(['status' => true]);
            },
        ]);
        $this->postJson('/api/v1/digital/orders', $this->input($f))->assertCreated()->assertJsonPath('data.status', 'failed');
        Http::assertNotSent(fn ($r): bool => str_ends_with($r->url(), '/transactions'));
    }

    public function test_safety_fourth_keeps_encrypted_raw_response_even_if_primary_metadata_is_invalid(): void
    {
        $f = $this->fixture();
        $purchase = json_decode($this->rabiaaPurchase(), true);
        $purchase['data']['transactionId'] = null;
        $this->directRabiaaResponses(Http::response($purchase));
        $row = $this->postJson('/api/v1/digital/orders', $this->input($f))->assertCreated()->assertJsonPath('data.status', 'review')->json('data');
        $order = DigitalOrder::findOrFail($row['id']);
        $this->assertSame('actual-nojoom-private-pin', $order->provider_purchase_response['code']);
        $this->assertStringNotContainsString('actual-nojoom-private-pin', $order->getRawOriginal('provider_purchase_response'));
        $this->assertNull($order->receipt);
        $this->getJson('/api/v1/digital/orders/'.$order->id.'/receipt')->assertConflict();
    }

    #[TestWith(['250000', '250000.00'])]
    #[TestWith(['0', '0.00'])]
    #[TestWith(['12345.67', '12345.67'])]
    #[TestWith(['"12345.67"', '12345.67'])]
    #[TestWith(['{"remaining_balance":12345.67}', '12345.67'])]
    public function test_live_topup_inventory_accepts_exact_scalar_and_documented_object(string $body, string $balance): void
    {
        $f = $this->fixture('topup');
        $this->directTopupResponses(Http::response(['status' => 'Succeed', 'id' => 'not-used']));
        $this->providerResponse(['*/api/v1/inventory' => Http::response($body, 200, ['Content-Type' => 'text/html; charset=UTF-8'])]);
        $this->asPortalUser($f['admin']);
        $this->postJson('/api/v1/digital/connections/'.$f['connection']['id'].'/balance')->assertOk()->assertJsonPath('data.company_balance', $balance);
        $this->assertDatabaseCount('digital_orders', 0);
        Http::assertSentCount(1);
        Http::assertSent(fn ($r): bool => $r->method() === 'GET' && str_ends_with($r->url(), '/inventory'));
    }

    #[TestWith(['false'])]
    #[TestWith(['[]'])]
    #[TestWith(['{"error":"Could not fetch inventory."}'])]
    #[TestWith(['-10'])]
    #[TestWith(['1.123'])]
    #[TestWith(['<html>Access denied</html>'])]
    public function test_live_topup_inventory_rejects_invalid_balance_without_overwriting_previous_value(string $body): void
    {
        $f = $this->fixture('topup');
        $this->directTopupResponses(Http::response(['status' => 'Succeed', 'id' => 'not-used']));
        DigitalConnection::findOrFail($f['connection']['id'])->update(['company_balance_minor' => 10000]);
        $this->providerResponse(['*/api/v1/inventory' => Http::response($body)]);
        $this->asPortalUser($f['admin']);
        $this->postJson('/api/v1/digital/connections/'.$f['connection']['id'].'/balance')->assertUnprocessable();
        $this->assertDatabaseHas('digital_connections', ['id' => $f['connection']['id'], 'company_balance_minor' => 10000]);
        $this->assertDatabaseCount('digital_orders', 0);
    }

    public function test_scalar_topup_transaction_is_not_treated_as_balance_or_success(): void
    {
        $f = $this->fixture('topup');
        $this->directTopupResponses(Http::response('250000'));
        $this->postJson('/api/v1/digital/orders', $this->input($f))->assertCreated()->assertJsonPath('data.status', 'review');
        $this->assertNull(DigitalConnection::findOrFail($f['connection']['id'])->company_balance_minor);
    }
}

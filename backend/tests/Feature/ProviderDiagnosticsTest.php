<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Models\Digital\CatalogSnapshot;
use App\Models\Digital\DigitalConnection;
use App\Models\Digital\DigitalOffer;
use App\Services\Digital\DigitalConfiguration;
use App\Services\Digital\DigitalReadiness;
use App\Services\Digital\ProviderGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Support\CreatesAccounts;
use Tests\TestCase;

class ProviderDiagnosticsTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['digital.gateway_url' => 'https://1.1.1.1/masal', 'digital.allowed_hosts' => ['1.1.1.1'], 'digital.contract_verified' => true, 'digital.purchases_enabled' => true, 'digital.topup.driver' => 'normalized', 'digital.rabiaa.driver' => 'normalized']);
        Http::preventStrayRequests();
    }

    private function fixture(string $provider = 'rabiaa'): array
    {
        $system = $this->account(AccountType::System);
        $main = $this->account(AccountType::MainAgent, $system);
        $actor = $this->userFor($system);
        $connection = DigitalConnection::factory()->create(['account_id' => $provider === 'topup' ? $system->id : $main->id, 'provider' => $provider, 'credential' => 'isolated-diagnostic-fixture-key']);
        $this->asPortalUser($actor);

        return compact('system', 'main', 'actor', 'connection');
    }

    public function test_missing_token_and_server_configuration_are_named_without_exposing_secrets(): void
    {
        $f = $this->fixture();
        $f['connection']->update(['credential' => null]);
        config(['digital.gateway_url' => 'http://unapproved.test', 'digital.contract_verified' => false]);
        $response = $this->getJson('/api/v1/digital/connections')->assertOk();
        $issues = collect($response->json('data.0.readiness.issues'));
        $this->assertContains('credential_missing', $issues->pluck('code'));
        $this->assertContains('gateway_configuration', $issues->pluck('code'));
        $response->assertJsonPath('data.0.readiness.ready', false)->assertJsonMissingPath('data.0.credential');
        Http::assertNothingSent();
    }

    public function test_connection_page_batches_latest_catalog_diagnostics_without_a_query_per_row(): void
    {
        $f = $this->fixture();
        foreach (range(1, 24) as $index) {
            $connection = DigitalConnection::factory()->create(['account_id' => $f['main']->id, 'provider' => 'rabiaa']);
            CatalogSnapshot::factory()->create(['connection_id' => $connection->id, 'catalog' => []]);
        }
        DB::enableQueryLog();
        $this->getJson('/api/v1/digital/connections?per_page=25')->assertOk()->assertJsonCount(25, 'data');
        $catalogQueries = array_filter(DB::getQueryLog(), fn (array $query): bool => str_contains($query['query'], 'from "digital_catalog_snapshots"'));
        DB::disableQueryLog();
        $this->assertCount(1, $catalogQueries);
        Http::assertNothingSent();
    }

    public function test_current_token_needs_its_own_catalog_and_empty_catalog_is_distinguished_from_no_fetch(): void
    {
        $f = $this->fixture();
        $this->getJson('/api/v1/digital/connections')->assertOk()->assertJsonPath('data.0.readiness.issues.0.code', 'catalog_not_fetched');
        CatalogSnapshot::factory()->create(['connection_id' => $f['connection']->id, 'credential_hash' => DigitalConfiguration::credentialHash('different-old-key'), 'catalog' => []]);
        $this->getJson('/api/v1/digital/connections')->assertOk()->assertJsonPath('data.0.readiness.issues.0.code', 'catalog_not_fetched');
        CatalogSnapshot::factory()->create(['connection_id' => $f['connection']->id, 'catalog' => []]);
        $this->getJson('/api/v1/digital/connections')->assertOk()->assertJsonPath('data.0.readiness.issues.0.code', 'catalog_empty');
        Http::assertNothingSent();
    }

    public function test_category_mapping_pricing_and_activation_have_separate_factual_reasons(): void
    {
        $f = $this->fixture();
        $snapshot = CatalogSnapshot::factory()->create(['connection_id' => $f['connection']->id]);
        $connection = $f['connection']->fresh();
        $issues = app(DigitalReadiness::class)->connection($connection, $f['actor']);
        $this->assertSame('categories_unlinked', $issues['issues'][0]['code']);
        $offer = DigitalOffer::factory()->create(['connection_id' => $connection->id, 'catalog_snapshot_id' => $snapshot->id, 'active' => false, 'retail_minor' => 0]);
        $issues = app(DigitalReadiness::class)->connection($connection->fresh(), $f['actor']);
        $this->assertEqualsCanonicalizing(['category_price_missing', 'categories_paused'], array_column($issues['issues'], 'code'));
        $offer->update(['active' => true, 'retail_minor' => 500000]);
        $this->assertTrue(app(DigitalReadiness::class)->connection($connection->fresh(), $f['actor'])['ready']);
    }

    #[TestWith([401, 'رفضت الشركة المصادقة'])]
    #[TestWith([403, 'صلاحية الوصول'])]
    #[TestWith([429, 'حد الاستعلامات'])]
    #[TestWith([503, 'غير متاح'])]
    public function test_upstream_read_rejections_are_safe_validation_errors_and_never_local_logout(int $status, string $message): void
    {
        $f = $this->fixture();
        Http::fake(['*' => Http::response(['message' => 'SECRET_PROVIDER_BODY isolated-diagnostic-fixture-key'], $status)]);
        $response = $this->postJson('/api/v1/digital/connections/'.$f['connection']->id.'/catalog', ['version' => 1])->assertUnprocessable()->assertJsonValidationErrors('gateway');
        $this->assertStringContainsString($message, $response->json('errors.gateway.0'));
        $this->assertStringNotContainsString('SECRET_PROVIDER_BODY', $response->getContent());
        $this->assertStringNotContainsString('isolated-diagnostic-fixture-key', $response->getContent());
        $this->assertDatabaseCount('digital_catalog_snapshots', 0);
        $this->assertDatabaseCount('finance_transactions', 0);
        $this->getJson('/api/v1/auth/me')->assertOk();
        if ($status === 403) {
            $this->assertStringNotContainsString('المفتاح غير صالح', $response->getContent());
        }
    }

    public function test_connection_timeout_has_a_safe_specific_message(): void
    {
        $f = $this->fixture();
        Http::fake(['*' => Http::failedConnection('PRIVATE_CONNECTION_DETAILS')]);
        $response = $this->postJson('/api/v1/digital/connections/'.$f['connection']->id.'/catalog', ['version' => 1])->assertUnprocessable();
        $this->assertStringContainsString('تعذر الاتصال', $response->json('errors.gateway.0'));
        $this->assertStringNotContainsString('PRIVATE_CONNECTION_DETAILS', $response->getContent());
    }

    public function test_fourth_unsupported_balance_is_not_reported_as_missing_configuration(): void
    {
        $f = $this->fixture();
        config(['digital.rabiaa.driver' => 'nojoom_v2_1', 'digital.rabiaa.url' => 'https://1.1.1.1', 'digital.rabiaa.allowed_hosts' => ['1.1.1.1']]);
        $gateway = app(ProviderGateway::class)->status($f['connection']);
        $this->assertTrue($gateway['configured']);
        $this->assertFalse($gateway['balance_supported']);
        $this->assertSame([], $gateway['missing']);
    }

    public function test_topup_sync_keeps_the_specific_reason_and_does_not_invent_categories(): void
    {
        $f = $this->fixture('topup');
        Http::fake(['*' => Http::response(['message' => 'PRIVATE_RESPONSE'], 401)]);
        $response = $this->postJson('/api/v1/digital/connections/'.$f['connection']->id.'/sync', ['version' => 1, 'catalog_only' => true])->assertOk();
        $response->assertJsonPath('data.sync.catalog', 'failed')->assertJsonPath('data.sync.balance', 'skipped');
        $this->assertStringContainsString('رفضت الشركة المصادقة', $response->json('data.sync.catalog_message'));
        $this->assertStringNotContainsString('PRIVATE_RESPONSE', $response->getContent());
        $this->assertDatabaseCount('topup_categories', 0);
        $this->assertDatabaseCount('finance_entries', 0);
    }

    public function test_successful_direct_topup_sync_imports_categories_without_duplicate_company(): void
    {
        $f = $this->fixture('topup');
        config(['digital.topup.driver' => 'masal_v2_1', 'digital.topup.url' => 'https://1.1.1.1', 'digital.topup.allowed_hosts' => ['1.1.1.1']]);
        Http::fake(['*/inventory' => Http::response('100000'), '*/products' => Http::response(['status' => 'ok', 'products' => [['id' => 49, 'title' => '6000', 'type' => 'topup', 'price' => 6000]]])]);
        $response = $this->postJson('/api/v1/digital/connections/'.$f['connection']->id.'/sync', ['version' => 1])->assertOk();
        $this->assertSame('ok', $response->json('data.sync.catalog'), $response->json('data.sync.catalog_message'));
        $this->assertDatabaseCount('topup_categories', 1);
    }
}

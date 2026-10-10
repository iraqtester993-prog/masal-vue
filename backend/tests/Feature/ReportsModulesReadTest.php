<?php

namespace Tests\Feature;

use App\Models\CatalogProduct;
use App\Models\Digital\DigitalConnection;
use App\Models\Digital\DigitalGrant;
use App\Models\Digital\DigitalOffer;
use App\Models\Digital\DigitalOrder;
use App\Models\Digital\ProviderAttempt;
use App\Models\Maps\UserPresence;
use App\Models\Operations\AccountArchive;
use App\Models\Operations\AccountTimePolicy;
use App\Models\Operations\DirectStop;
use App\Models\Operations\OperationStop;
use App\Models\User;
use App\Services\Operations\AccountTimeGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesSupport;
use Tests\TestCase;

class ReportsModulesReadTest extends TestCase
{
    use CreatesSupport, RefreshDatabase;

    private function digitalFixture(): array
    {
        $f = $this->supportFixture();
        $product = CatalogProduct::factory()->create();
        foreach ([$f['main'], $f['foreign']] as $account) {
            $rule = DB::table('catalog_account_rules')->insertGetId(['target_account_id' => $account->id, 'authority_account_id' => $f['system']->id]);
            DB::table('catalog_rule_products')->insert(['rule_id' => $rule, 'product_id' => $product->id]);
        }
        $connection = DigitalConnection::factory()->create(['account_id' => $f['main']->id, 'provider_id' => $product->provider_id, 'credential' => 'PRIVATE_PROVIDER_TOKEN']);
        $offer = DigitalOffer::factory()->create(['connection_id' => $connection->id, 'product_id' => $product->id]);
        foreach ([['sub', 'main'], ['branch', 'sub'], ['pos', 'branch']] as [$target, $parent]) {
            DigitalGrant::factory()->create(['connection_id' => $connection->id, 'target_account_id' => $f[$target]->id, 'from_account_id' => $f[$parent]->id, 'offer_ids' => [$offer->id]]);
        }

        return $f + compact('product', 'connection', 'offer');
    }

    private function order(array $f, array $changes = []): DigitalOrder
    {
        return DigitalOrder::factory()->create(array_replace([
            'connection_id' => $f['connection']->id, 'offer_id' => $f['offer']->id,
            'product_id' => $f['product']->id, 'main_account_id' => $f['main']->id,
            'account_id' => $f['pos']->id, 'creator_id' => $f['posUser']->id,
            'mobile' => '07712345678', 'quoted_cost_minor' => 430001, 'quoted_retail_minor' => 500002,
            'subscriber' => ['card' => 'PRIVATE_SUBSCRIBER_CARD'],
            'provider_hold' => ['secret' => 'PRIVATE_PROVIDER_HOLD'],
        ], $changes));
    }

    private function completed(array $f, array $changes = []): DigitalOrder
    {
        return $this->order($f, array_replace([
            'status' => 'succeeded', 'reservation_active' => false, 'actual_cost_minor' => 430001,
            'actual_retail_minor' => 500002, 'cost_basis' => 'provider_response',
            'company_transaction_id' => fake()->uuid(), 'receipt_ref' => fake()->uuid(),
            'receipt' => ['pin' => 'PRIVATE_RECEIPT_PIN'], 'provider_evidence' => ['secret' => 'PRIVATE_PROVIDER_EVIDENCE'],
            'provider_purchase_response' => ['secret' => 'PRIVATE_PURCHASE_RESPONSE'],
            'resolved_at' => now(),
        ], $changes));
    }

    private function presence(User $user, array $changes = []): UserPresence
    {
        return UserPresence::create(array_replace([
            'user_id' => $user->id, 'session_hash' => hash('sha256', fake()->uuid()),
            'session_version' => $user->fresh()->session_version, 'connected' => true,
            'sharing' => true, 'last_seen_at' => now(), 'latitude' => 33.301,
            'longitude' => 44.401, 'accuracy' => 12, 'location_at' => now(),
        ], $changes));
    }

    private function noSecrets(string $body): void
    {
        foreach (['PRIVATE_PROVIDER_TOKEN', 'PRIVATE_SUBSCRIBER_CARD', 'PRIVATE_PROVIDER_HOLD',
            'PRIVATE_RECEIPT_PIN', 'PRIVATE_PROVIDER_EVIDENCE', 'PRIVATE_PURCHASE_RESPONSE',
            'credential', 'subscriber', 'receipt_ref', 'session_hash', 'password', 'remember_token'] as $secret) {
            $this->assertStringNotContainsString($secret, $body);
        }
    }

    public function test_digital_orders_use_actual_scoped_records_and_never_expose_provider_or_pin_secrets(): void
    {
        $f = $this->digitalFixture();
        $own = $this->completed($f);
        ProviderAttempt::factory()->count(2)->create(['order_id' => $own->id, 'actor_id' => $f['posUser']->id]);
        $this->completed($f, ['account_id' => $f['foreign']->id, 'main_account_id' => $f['foreign']->id]);
        $this->asPortalUser($f['agent']);
        $response = $this->getJson('/api/v1/reports/sections/sales-services/rows')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $own->id)
            ->assertJsonPath('data.0.price', '5000.02')->assertJsonPath('data.0.cost', '4300.01')
            ->assertJsonPath('data.0.profit', '700.01')->assertJsonPath('data.0.attempts', 2);
        $this->noSecrets($response->getContent());
        $this->getJson('/api/v1/reports/sections/sales-services/rows?agent_id='.$f['foreign']->id)->assertNotFound();
        $this->getJson('/api/v1/reports/sections/sales-services/rows?currency=USD')->assertUnprocessable()->assertJsonValidationErrors('currency');
        $this->supportGrant($f['agent'], 'data.cost', false);
        $this->asPortalUser($f['agent']);
        $protected = $this->getJson('/api/v1/reports/sections/sales-services/rows')->assertOk()->assertJsonPath('data.0.profit', null);
        $this->assertArrayNotHasKey('cost', $protected->json('data.0'));
        $profit = collect($protected->json('columns'))->firstWhere('key', 'profit');
        $this->assertTrue($profit['protected']);
        $this->assertSame(['data.profit', 'data.cost'], $profit['required_permissions']);
        $this->supportGrant($f['agent'], 'digital.view', false);
        $this->asPortalUser($f['agent']);
        $this->getJson('/api/v1/reports/sections/sales-services/rows')->assertConflict();
    }

    public function test_catalog_snapshot_is_not_provider_settlement_and_pending_review_refunded_orders_have_no_completed_profit(): void
    {
        $f = $this->digitalFixture();
        $pending = $this->order($f);
        $snapshot = $this->completed($f, ['cost_basis' => 'catalog_snapshot']);
        $review = $this->order($f, ['status' => 'review', 'actual_cost_minor' => 430001, 'actual_retail_minor' => 500002, 'cost_basis' => 'provider_response', 'company_transaction_id' => fake()->uuid()]);
        $refund = $this->completed($f, ['status' => 'refunded', 'refunded_at' => now()]);
        $this->asPortalUser($f['admin']);
        $response = $this->getJson('/api/v1/reports/sections/sales-services/rows')->assertOk()->assertJsonCount(4, 'data');
        $rows = collect($response->json('data'))->keyBy('id');
        $this->assertNull($rows[$pending->id]['cost']);
        $this->assertNull($rows[$snapshot->id]['cost']);
        foreach ([$pending, $snapshot, $review, $refund] as $order) {
            $this->assertNull($rows[$order->id]['profit']);
        }
        $this->assertSame('4300.01', $rows[$review->id]['cost']);
        $columns = collect($response->json('columns'))->keyBy('key');
        $this->assertStringContainsString('كتالوج', $columns['cost']['source_note']);
        $this->assertDatabaseCount('finance_transactions', 0);
    }

    public function test_pos_cannot_reconstruct_provider_cost_from_profit_even_with_explicit_general_grants(): void
    {
        $f = $this->digitalFixture();
        $this->completed($f);
        $this->supportGrant($f['posUser'], 'data.cost');
        $this->supportGrant($f['posUser'], 'data.profit');
        $this->asPortalUser($f['posUser']);
        $response = $this->getJson('/api/v1/reports/sections/sales-services/rows')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.price', '5000.02')->assertJsonPath('data.0.profit', null);
        if (array_key_exists('cost', $response->json('data.0'))) {
            $response->assertJsonPath('data.0.cost', null);
        }
        $this->assertNull($response->json('data.0.agent'));
        $this->noSecrets($response->getContent());
        $export = $this->postJson('/api/v1/reports/export', ['section_ids' => ['sales-services']])->assertOk();
        $this->assertNull($export->json('data.sections.0.rows.0.profit'));
        $this->noSecrets($export->getContent());
    }

    public function test_digital_export_includes_every_filtered_sorted_row_and_honors_baghdad_dates(): void
    {
        $f = $this->digitalFixture();
        for ($i = 1; $i <= 24; $i++) {
            $this->completed($f, ['created_at' => '2026-10-05 21:00:00', 'quoted_retail_minor' => 500000 + $i, 'actual_retail_minor' => 500000 + $i]);
        }
        $this->completed($f, ['created_at' => '2026-10-05 20:59:59']);
        $this->asPortalUser($f['admin']);
        $this->getJson('/api/v1/reports/sections/sales-services/rows?from=2026-10-06&to=2026-10-06&per_page=20')->assertOk()->assertJsonCount(20, 'data')->assertJsonPath('meta.total', 24);
        $response = $this->postJson('/api/v1/reports/export', ['section_ids' => ['sales-services'], 'from' => '2026-10-06', 'to' => '2026-10-06', 'sort' => 'price', 'direction' => 'desc', 'q' => '07712345678'])->assertOk()
            ->assertJsonCount(24, 'data.sections.0.rows')->assertJsonPath('data.sections.0.rows.0.price', '5000.24')->assertJsonPath('data.sections.0.rows.23.price', '5000.01');
        $this->noSecrets($response->getContent());
    }

    public function test_integration_report_uses_effective_grants_and_keeps_unknown_expiry_null(): void
    {
        $f = $this->digitalFixture();
        DigitalConnection::factory()->create(['account_id' => $f['foreign']->id, 'provider_id' => $f['product']->provider_id]);
        $this->asPortalUser($f['admin']);
        $response = $this->getJson('/api/v1/reports/sections/operations-integrations/rows?agent_id='.$f['main']->id)->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.agent', $f['main']->name)->assertJsonPath('data.0.expiry', null);
        $columns = collect($response->json('columns'))->keyBy('key');
        $this->assertFalse($columns['expiry']['source_available']);
        $this->assertNotEmpty($columns['expiry']['reason']);
        $this->assertSame(parse_url(config('digital.rabiaa.url'), PHP_URL_HOST), $response->json('data.0.environment'));
        $this->noSecrets($response->getContent());
        $this->supportGrant($f['subUser'], 'reports.operations');
        $this->asPortalUser($f['subUser']);
        $this->getJson('/api/v1/reports/sections/operations-integrations/rows')->assertOk()->assertJsonCount(1, 'data');
        DigitalGrant::where('target_account_id', $f['sub']->id)->update(['offer_ids' => []]);
        $this->getJson('/api/v1/reports/sections/operations-integrations/rows')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_integration_report_keeps_connections_without_optional_company_visible_scoped_and_secret_free(): void
    {
        $f = $this->supportFixture();
        foreach (['rabiaa', 'topup'] as $provider) {
            DigitalConnection::factory()->create(['account_id' => $f['main']->id, 'provider_id' => null, 'provider' => $provider, 'credential' => 'PRIVATE_PROVIDER_TOKEN']);
        }
        DigitalConnection::factory()->create(['account_id' => $f['foreign']->id, 'provider_id' => null, 'provider' => 'rabiaa', 'credential' => 'PRIVATE_PROVIDER_TOKEN']);
        $this->assertDatabaseCount('catalog_providers', 0);
        $this->assertDatabaseCount('catalog_products', 0);
        $this->supportGrant($f['agent'], 'reports.operations');
        $this->asPortalUser($f['agent']);
        $response = $this->getJson('/api/v1/reports/sections/operations-integrations/rows?sort=provider&direction=asc')->assertOk()->assertJsonCount(2, 'data');
        $this->assertEqualsCanonicalizing(['الرابعة', 'التعبئة المباشرة'], array_column($response->json('data'), 'provider'));
        foreach ($response->json('data') as $row) {
            $this->assertSame($f['main']->name, $row['agent']);
            $this->assertNull($row['expiry']);
        }
        $this->noSecrets($response->getContent());
        $this->getJson('/api/v1/reports/sections/operations-integrations/rows?q='.urlencode('التعبئة المباشرة'))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.provider', 'التعبئة المباشرة');
        $export = $this->postJson('/api/v1/reports/export', ['section_ids' => ['operations-integrations']])->assertOk()->assertJsonCount(2, 'data.sections.0.rows');
        $this->noSecrets($export->getContent());
        $this->getJson('/api/v1/reports/sections/operations-integrations/rows?agent_id='.$f['foreign']->id)->assertNotFound();
        $employee = $this->supportEmployee($f['system'], $f['pos'], ['account.view', 'reports.view', 'reports.operations', 'digital.view']);
        $this->asPortalUser($employee);
        $this->getJson('/api/v1/reports/sections/operations-integrations/rows')->assertOk()->assertJsonCount(0, 'data');
        $mainEmployee = $this->supportEmployee($f['main'], $f['pos'], ['account.view', 'reports.view', 'reports.operations', 'reports.export', 'digital.view', 'integrations.view', 'data.cost']);
        $this->asPortalUser($mainEmployee);
        $this->getJson('/api/v1/digital/offers')->assertNotFound();
        $this->getJson('/api/v1/digital/connections?main_account_id='.$f['main']->id)->assertNotFound();
        $staffRows = $this->getJson('/api/v1/reports/sections/operations-integrations/rows')->assertOk()->assertJsonCount(0, 'data');
        $staffExport = $this->postJson('/api/v1/reports/export', ['section_ids' => ['operations-integrations']])->assertOk()->assertJsonCount(0, 'data.sections.0.rows');
        foreach ([$staffRows, $staffExport] as $response) {
            $this->assertStringNotContainsString($f['main']->name, $response->getContent());
            $this->noSecrets($response->getContent());
        }
        $this->assertDatabaseCount('catalog_providers', 0);
        $this->assertDatabaseCount('catalog_products', 0);
    }

    public function test_archive_report_includes_archived_scoped_accounts_and_never_decrypts_private_snapshots_into_export(): void
    {
        $f = $this->supportFixture();
        $f['pos']->forceFill(['archived_at' => now(), 'status' => 'disabled'])->save();
        $this->assertNotNull($f['pos']->fresh()->archived_at);
        $own = AccountArchive::factory()->create(['account_id' => $f['pos']->id, 'actor_id' => $f['admin']->id, 'before' => ['name' => $f['pos']->name, 'type' => 'pos', 'phone' => 'PRIVATE_ARCHIVE_PHONE'], 'users' => [['id' => $f['posUser']->id, 'email' => 'PRIVATE_ARCHIVE_EMAIL']], 'reason' => 'طلب صاحب الحساب', 'created_at' => '2026-10-06 09:00:00']);
        AccountArchive::factory()->create(['account_id' => $f['foreign']->id, 'actor_id' => $f['admin']->id]);
        $this->supportGrant($f['agent'], 'agents.archiveView');
        $this->asPortalUser($f['agent']);
        $response = $this->getJson('/api/v1/reports/sections/network-archive/rows?agent_id='.$f['main']->id.'&from=2026-10-06&to=2026-10-06')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $own->id)->assertJsonPath('data.0.entity', $f['pos']->id)->assertJsonPath('data.0.name', $f['pos']->name)->assertJsonPath('data.0.reason', 'طلب صاحب الحساب');
        foreach (['PRIVATE_ARCHIVE_PHONE', 'PRIVATE_ARCHIVE_EMAIL', 'before', 'users'] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }
        $this->getJson('/api/v1/reports/sections/network-archive/rows?q=PRIVATE_ARCHIVE_EMAIL')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson('/api/v1/reports/export', ['section_ids' => ['network-archive']])->assertOk()->assertJsonCount(1, 'data.sections.0.rows');
        $this->supportGrant($f['agent'], 'agents.archiveView', false);
        $this->asPortalUser($f['agent']);
        $this->getJson('/api/v1/reports/sections/network-archive/rows')->assertConflict();
    }

    public function test_presence_uses_latest_real_location_current_sessions_and_active_ancestry_without_leaking_session_ids(): void
    {
        $f = $this->supportFixture();
        $this->travelTo(now()->startOfSecond());
        $this->presence($f['agent'], ['last_seen_at' => now()->subSeconds(10)]);
        $this->presence($f['agent'], ['last_seen_at' => now()->subSeconds(5), 'session_version' => $f['agent']->session_version + 1, 'latitude' => 34.101]);
        $this->presence($f['subUser'], ['last_seen_at' => now()->subSeconds(120)]);
        $this->presence($f['branchUser'], ['last_seen_at' => now()->addSecond()]);
        $this->presence($f['posUser'], ['connected' => false, 'sharing' => false]);
        $this->presence($f['foreignUser'], ['latitude' => 38.999]);
        $this->supportGrant($f['agent'], 'map.view');
        $this->asPortalUser($f['agent']);
        $response = $this->getJson('/api/v1/reports/sections/network-presence/rows?from=2030-01-01')->assertOk()->assertJsonCount(4, 'data');
        $rows = collect($response->json('data'))->keyBy('id');
        $this->assertSame('متصل', $rows[$f['agent']->id]['status']);
        $this->assertEquals(34.101, $rows[$f['agent']->id]['lat']);
        foreach (['subUser', 'branchUser', 'posUser'] as $key) {
            $this->assertSame('غير متصل', $rows[$f[$key]->id]['status']);
        }
        $this->assertEquals(33.301, $rows[$f['posUser']->id]['lat']);
        $this->noSecrets($response->getContent());
        $this->assertNotContains($f['foreignUser']->id, array_column($response->json('data'), 'id'));
        $f['main']->update(['status' => 'disabled']);
        $this->asPortalUser($f['admin']);
        $response = $this->getJson('/api/v1/reports/sections/network-presence/rows')->assertOk();
        $rows = collect($response->json('data'))->keyBy('id');
        $this->assertSame('غير متصل', $rows[$f['agent']->id]['status']);
    }

    public function test_presence_export_is_scoped_to_complete_employee_forests_and_is_not_limited_to_the_current_page(): void
    {
        $f = $this->supportFixture();
        for ($i = 1; $i <= 24; $i++) {
            $employee = $this->supportEmployee($f['main'], $f['sub'], ['account.view']);
            $employee->update(['name' => 'Visible Employee '.str_pad($i, 2, '0', STR_PAD_LEFT)]);
            $this->presence($employee);
        }
        $hidden = $this->supportEmployee($f['main'], $f['sub'], ['account.view']);
        DB::table('membership_scope_roots')->insert(['membership_id' => $hidden->membership->id, 'account_id' => $f['foreign']->id]);
        $hidden->update(['name' => 'Hidden Employee']);
        $this->presence($hidden, ['latitude' => 38.999]);
        $this->supportGrant($f['agent'], 'map.view');
        $this->asPortalUser($f['agent']);
        $this->getJson('/api/v1/reports/sections/network-presence/rows?q=Visible%20Employee&per_page=20')->assertOk()->assertJsonCount(20, 'data')->assertJsonPath('meta.total', 24);
        $response = $this->postJson('/api/v1/reports/export', ['section_ids' => ['network-presence'], 'q' => 'Visible Employee', 'sort' => 'name', 'direction' => 'desc'])->assertOk()
            ->assertJsonCount(24, 'data.sections.0.rows')->assertJsonPath('data.sections.0.rows.0.name', 'Visible Employee 24');
        $this->noSecrets($response->getContent());
        $this->getJson('/api/v1/reports/sections/network-presence/rows?q=Hidden%20Employee')->assertOk()->assertJsonCount(0, 'data');
        $this->supportGrant($f['agent'], 'staff.view', false);
        $this->asPortalUser($f['agent']);
        $this->getJson('/api/v1/reports/sections/network-presence/rows?q=Visible%20Employee')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_time_policy_rows_are_real_employee_policies_and_owner_only_even_when_employee_is_granted_security_permissions(): void
    {
        $f = $this->supportFixture();
        $employee = $this->supportEmployee($f['system'], $f['main'], ['account.view', 'reports.view', 'reports.users', 'security.view', 'security.policies']);
        AccountTimePolicy::factory()->create(['user_id' => $employee->id, 'policy' => array_replace(AccountTimeGuard::emptyPolicy(), ['enabled' => true, 'dateEnabled' => true, 'startAt' => '2026-10-01T00:00', 'endAt' => '2026-12-31T23:59', 'idleEnabled' => true, 'idleMinutes' => 7])]);
        AccountTimePolicy::factory()->create(['user_id' => $f['agent']->id]);
        $this->asPortalUser($f['admin']);
        $this->getJson('/api/v1/reports/sections/users-times/rows?from=2030-01-01')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $employee->id)->assertJsonPath('data.0.status', 'مفعّل')
            ->assertJsonPath('data.0.startAt', '2026-10-01T00:00')->assertJsonPath('data.0.endAt', '2026-12-31T23:59')
            ->assertJsonPath('data.0.startTime', 'غير مفعّل')->assertJsonPath('data.0.idleMinutes', '7')->assertJsonPath('data.0.sessionMinutes', 'غير مفعّل');
        $this->asPortalUser($employee);
        $this->withSession(['masal.time_clock' => ['user' => $employee->id, 'started' => now()->timestamp, 'lastActivity' => now()->timestamp]]);
        $this->getJson('/api/v1/reports/sections/users-times/rows')->assertConflict();
        $this->supportGrant($f['agent'], 'reports.users');
        $this->supportGrant($f['agent'], 'security.view');
        $this->supportGrant($f['agent'], 'security.policies');
        $this->asPortalUser($f['agent']);
        $this->getJson('/api/v1/reports/sections/users-times/rows')->assertConflict();
    }

    public function test_security_events_include_actual_stop_and_resume_records_and_hide_foreign_global_decisions_from_scoped_staff(): void
    {
        $f = $this->supportFixture();
        $own = OperationStop::factory()->create(['creator_id' => $f['admin']->id, 'authority_account_id' => $f['system']->id, 'scope_roots' => [$f['main']->id], 'scope' => 'all', 'active' => false, 'reason' => 'مراجعة مرئية', 'resumed_by' => $f['admin']->id, 'resumed_at' => now()]);
        $foreign = OperationStop::factory()->create(['creator_id' => $f['admin']->id, 'authority_account_id' => $f['system']->id, 'scope_roots' => [$f['foreign']->id], 'reason' => 'FOREIGN_SECURITY_REASON']);
        OperationStop::factory()->create(['creator_id' => $f['admin']->id, 'authority_account_id' => $f['system']->id, 'reason' => 'GLOBAL_SECURITY_REASON']);
        foreach ([[$own, $f['main']], [$foreign, $f['foreign']]] as [$stop, $root]) {
            DB::table('operation_stop_scope_roots')->insert(['stop_id' => $stop->id, 'account_id' => $root->id]);
        }
        DirectStop::factory()->create(['account_id' => $f['pos']->id]);
        DB::table('audit_logs')->insert(['user_id' => $f['admin']->id, 'account_id' => $f['system']->id, 'subject_account_id' => $f['main']->id, 'action' => 'security.stop', 'portal' => 'admin', 'details' => json_encode(['stop_id' => $own->id]), 'created_at' => now()]);
        $employee = $this->supportEmployee($f['system'], $f['main'], ['account.view', 'reports.view', 'reports.operations', 'reports.audit', 'security.view']);
        $this->asPortalUser($employee);
        $response = $this->getJson('/api/v1/reports/sections/operations-security/rows')->assertOk()->assertJsonCount(3, 'data');
        $this->assertContains('stop:'.$own->id.':create', array_column($response->json('data'), 'row_key'));
        $this->assertContains('stop:'.$own->id.':resume', array_column($response->json('data'), 'row_key'));
        $this->assertStringNotContainsString('FOREIGN_SECURITY_REASON', $response->getContent());
        $this->assertStringNotContainsString('GLOBAL_SECURITY_REASON', $response->getContent());
        $this->getJson('/api/v1/reports/sections/operations-security/rows?agent_id='.$f['main']->id)->assertOk()->assertJsonCount(3, 'data');
        $this->getJson('/api/v1/reports/sections/operations-security/rows?agent_id='.$f['foreign']->id)->assertNotFound();
        $this->asPortalUser($f['admin']);
        $this->getJson('/api/v1/reports/sections/operations-security/rows')->assertOk()->assertJsonCount(5, 'data');
    }
}

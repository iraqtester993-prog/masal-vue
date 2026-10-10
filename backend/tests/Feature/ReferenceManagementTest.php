<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\CatalogProvider;
use App\Models\NetworkRepresentative;
use App\Models\OperatingGovernorate;
use App\Models\OrderSource;
use App\Models\PosType;
use App\Services\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CreatesAccounts;
use Tests\TestCase;

class ReferenceManagementTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    public function test_pos_list_batches_selected_representatives_and_respects_agent_scope(): void
    {
        $root = $this->account(AccountType::System);
        $main = $this->account(AccountType::MainAgent, $root);
        $other = $this->account(AccountType::MainAgent, $root);
        $visible = NetworkRepresentative::factory()->create(['agent_account_id' => $main->id, 'name' => 'Visible representative']);
        $hidden = NetworkRepresentative::factory()->create(['agent_account_id' => $other->id, 'name' => 'Private representative']);
        $first = $this->account(AccountType::Pos, $main);
        $second = $this->account(AccountType::Pos, $main);
        $outside = $this->account(AccountType::Pos, $other);
        foreach ([$first, $second, $outside] as $pos) {
            DB::table('pos_representatives')->insert(['account_id' => $pos->id, 'representative_id' => $visible->id]);
            DB::table('pos_representatives')->insert(['account_id' => $pos->id, 'representative_id' => $hidden->id]);
        }
        $this->asPortalUser($this->userFor($main));
        DB::enableQueryLog();
        $response = $this->getJson('/api/v1/accounts?kind=pos')->assertOk()->assertJsonCount(2, 'data');
        $queries = collect(DB::getQueryLog())->filter(fn ($query): bool => str_contains($query['query'], 'pos_representatives'));
        DB::disableQueryLog();
        $this->assertCount(1, $queries);
        foreach ($response->json('data') as $row) {
            $this->assertSame(['Visible representative'], $row['representative_names']);
        }
        $this->assertStringNotContainsString('Private representative', $response->getContent());
    }

    public function test_governorate_counts_include_both_subagent_levels_without_demo_entities(): void
    {
        $system = $this->account(AccountType::System);
        $main = $this->account(AccountType::MainAgent, $system);
        $sub = $this->account(AccountType::SubAgent, $main);
        $branch = $this->account(AccountType::SubBranch, $sub);
        foreach ([$main, $sub, $branch, $this->account(AccountType::Pos, $branch)] as $account) {
            $account->update(['city' => 'بغداد']);
        }
        $this->asPortalUser($this->userFor($system));

        $this->getJson('/api/v1/reference/governorates?query=بغداد')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'بغداد')->assertJsonPath('data.0.main_agents_count', 1)->assertJsonPath('data.0.sub_agents_count', 2)->assertJsonPath('data.0.pos_count', 1);

        $this->assertDatabaseCount('network_representatives', 0);
        $this->assertDatabaseCount('order_sources', 0);
        $this->assertSame(['متجر', 'سوبر ماركت', 'مطعم'], PosType::orderBy('id')->pluck('name')->all());
    }

    public function test_governorate_disable_changes_account_creation_choices_and_records_audit(): void
    {
        $this->asPortalUser($this->userFor($this->account(AccountType::System)));
        $city = OperatingGovernorate::where('name', 'بغداد')->firstOrFail();

        $this->patchJson('/api/v1/reference/governorates/'.$city->id.'/status', ['version' => 1, 'status' => 'disabled'])->assertOk()->assertJsonPath('data.active', false)->assertJsonPath('data.version', 2);

        $this->assertDatabaseHas('account_cities', ['id' => $city->id, 'active' => false, 'version' => 2]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'governorates.toggle']);
        $this->getJson('/api/v1/account-options')->assertJsonMissing(['بغداد']);
    }

    public function test_main_agent_creates_source_for_own_network_and_active_provider(): void
    {
        $system = $this->account(AccountType::System);
        $main = $this->account(AccountType::MainAgent, $system);
        $provider = CatalogProvider::factory()->create();
        $this->asPortalUser($this->userFor($main));

        $this->postJson('/api/v1/reference/sources', ['name' => ' مصدر بغداد ', 'provider_id' => $provider->id])->assertCreated()->assertJsonPath('data.name', 'مصدر بغداد')->assertJsonPath('data.provider_name', $provider->name)->assertJsonPath('data.network_account_id', $main->id);

        $this->assertDatabaseHas('order_sources', ['name' => 'مصدر بغداد', 'network_account_id' => $main->id, 'provider_id' => $provider->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'sources.create', 'subject_account_id' => $main->id]);
    }

    public function test_source_duplicate_name_is_case_insensitive_per_provider_and_network(): void
    {
        $source = OrderSource::factory()->create(['name' => 'Recorded Source', 'normalized_name' => 'recorded source']);
        $this->asPortalUser($this->userFor($this->accountRoot()));

        $this->postJson('/api/v1/reference/sources', ['name' => 'RECORDED SOURCE', 'provider_id' => $source->provider_id, 'network_account_id' => $source->network_account_id])->assertUnprocessable()->assertJsonValidationErrors('name');

        $this->assertDatabaseCount('order_sources', 1);
    }

    private function accountRoot(): Account
    {
        return Account::where('type', AccountType::System)->firstOrFail();
    }

    public function test_system_must_select_network_and_provider_must_be_active(): void
    {
        $system = $this->account(AccountType::System);
        $main = $this->account(AccountType::MainAgent, $system);
        $provider = CatalogProvider::factory()->create(['status' => 'disabled']);
        $this->asPortalUser($this->userFor($system));

        $this->postJson('/api/v1/reference/sources', ['name' => 'Missing Network', 'provider_id' => $provider->id])->assertUnprocessable()->assertJsonValidationErrors('network_account_id');
        $this->postJson('/api/v1/reference/sources', ['name' => 'Disabled Company', 'provider_id' => $provider->id, 'network_account_id' => $main->id])->assertUnprocessable()->assertJsonValidationErrors('provider_id');

        $this->assertDatabaseCount('order_sources', 0);
    }

    public function test_representative_original_optional_fields_default_empty_and_private_paths_absent(): void
    {
        $system = $this->account(AccountType::System);
        $main = $this->account(AccountType::MainAgent, $system);
        $this->asPortalUser($this->userFor($system));

        $this->postJson('/api/v1/reference/representatives', ['agent_account_id' => $main->id])->assertCreated()->assertJsonPath('data.name', '')->assertJsonPath('data.phone', '')->assertJsonPath('data.address', '')->assertJsonPath('data.photos', [])->assertJsonMissingPath('data.storage_path')->assertJsonMissingPath('data.normalized_name');

        $this->assertDatabaseHas('network_representatives', ['agent_account_id' => $main->id, 'name' => '', 'phone' => '', 'address' => '']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'representatives.create']);
    }

    public static function invalidInputs(): array
    {
        return [['representatives', ['agent_account_id' => 0], 'agent_account_id'], ['representatives', ['agent_account_id' => 1, 'phone' => str_repeat('1', 41)], 'phone'], ['pos-types', ['name' => ''], 'name'], ['pos-types', ['name' => 'New', 'role' => 'system'], 'payload'], ['pos-types', ['name' => 'New', 'status' => 'forged'], 'status'], ['sources', ['name' => 'Source', 'provider_id' => 1, 'normalized_name' => 'forged'], 'payload']];
    }

    #[DataProvider('invalidInputs')]
    public function test_invalid_or_unknown_reference_input_returns_422_without_writes(string $kind, array $payload, string $error): void
    {
        $this->asPortalUser($this->userFor($this->account(AccountType::System)));

        $this->postJson('/api/v1/reference/'.$kind, $payload)->assertUnprocessable()->assertJsonValidationErrors($error);

        $this->assertDatabaseCount('network_representatives', 0);
        $this->assertDatabaseCount('order_sources', 0);
        $this->assertDatabaseCount('pos_types', 3);
    }

    public function test_pos_type_create_edit_and_stale_toggle_preserve_versioned_record(): void
    {
        $this->asPortalUser($this->userFor($this->account(AccountType::System)));
        $type = $this->postJson('/api/v1/reference/pos-types', ['name' => ' محل هواتف '])->assertCreated()->json('data');
        $this->patchJson('/api/v1/reference/pos-types/'.$type['id'], ['version' => 1, 'name' => 'مركز هواتف'])->assertOk()->assertJsonPath('data.version', 2);

        $this->patchJson('/api/v1/reference/pos-types/'.$type['id'].'/status', ['version' => 1, 'status' => 'disabled'])->assertConflict();

        $this->assertDatabaseHas('pos_types', ['id' => $type['id'], 'name' => 'مركز هواتف', 'status' => 'active', 'version' => 2]);
    }

    public function test_representative_phone_and_address_search_and_literal_wildcard_pagination(): void
    {
        $this->asPortalUser($this->userFor($this->account(AccountType::System)));
        $selected = NetworkRepresentative::factory()->create(['name' => 'Literal %_ representative', 'phone' => '07701234567', 'address' => 'بغداد شارع المتنبي']);
        NetworkRepresentative::factory()->create(['name' => 'Ordinary', 'phone' => '1234', 'address' => 'البصرة']);

        $this->getJson('/api/v1/reference/representatives?query=%25_&per_page=1')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $selected->id);
        $this->getJson('/api/v1/reference/representatives?query=شارع')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/reference/representatives?sort=id;DROP')->assertUnprocessable()->assertJsonValidationErrors('payload');

        $this->assertDatabaseCount('network_representatives', 2);
    }

    public function test_source_search_matches_company_name_and_filter_status(): void
    {
        $this->asPortalUser($this->userFor($this->account(AccountType::System)));
        $provider = CatalogProvider::factory()->create(['name' => 'شركة بغداد']);
        $source = OrderSource::factory()->create(['provider_id' => $provider->id]);
        OrderSource::factory()->create(['status' => 'disabled']);

        $this->getJson('/api/v1/reference/sources?query=بغداد&status=active')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $source->id);
    }

    public function test_reference_export_neutralizes_spreadsheet_formulas_and_logs_count(): void
    {
        $this->asPortalUser($this->userFor($this->account(AccountType::System)));
        NetworkRepresentative::factory()->create(['name' => '=SUM(1,2)', 'phone' => '+9647700000000', 'address' => '@danger']);

        $response = $this->getJson('/api/v1/reference/representatives/export')->assertOk()->assertJsonPath('data.count', 1)->assertHeaderContains('Cache-Control', 'no-store');

        $csv = $response->json('data.csv');
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString("'=SUM(1,2)", $csv);
        $this->assertStringContainsString("'+9647700000000", $csv);
        $this->assertStringContainsString("'@danger", $csv);
        $this->assertDatabaseHas('audit_logs', ['action' => 'representatives.export']);
    }

    public function test_pos_type_export_contains_canonical_labels_without_private_fields(): void
    {
        $this->asPortalUser($this->userFor($this->account(AccountType::System)));

        $response = $this->getJson('/api/v1/reference/pos-types/export')->assertOk()->assertJsonPath('data.count', 3);

        $this->assertStringContainsString('سوبر ماركت', $response->json('data.csv'));
        $this->assertStringNotContainsString('normalized_name', $response->json('data.csv'));
    }

    public function test_audit_failure_rolls_back_reference_mutation(): void
    {
        $this->asPortalUser($this->userFor($this->account(AccountType::System)));
        $this->mock(AuditLogger::class)->shouldReceive('record')->once()->andThrow(new \RuntimeException('Intentional failure'));

        $this->postJson('/api/v1/reference/pos-types', ['name' => 'Rolled Back'])->assertInternalServerError();

        $this->assertDatabaseCount('pos_types', 3);
    }

    public function test_export_over_10000_records_fails_explicitly_without_truncated_csv(): void
    {
        $system = $this->account(AccountType::System);
        $main = $this->account(AccountType::MainAgent, $system);
        $this->asPortalUser($this->userFor($system));
        $rows = array_fill(0, 10001, ['agent_account_id' => $main->id, 'name' => 'Many rows', 'phone' => '', 'address' => '', 'status' => 'active', 'version' => 1]);
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('network_representatives')->insert($chunk);
        }

        $this->getJson('/api/v1/reference/representatives/export')->assertUnprocessable()->assertJsonMissingPath('data.csv');

        $this->assertDatabaseMissing('audit_logs', ['action' => 'representatives.export']);
    }

    public function test_representative_list_queries_do_not_grow_per_row(): void
    {
        $this->asPortalUser($this->userFor($this->account(AccountType::System)));
        NetworkRepresentative::factory()->create();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->getJson('/api/v1/reference/representatives?per_page=100')->assertOk();
        $one = count(DB::getQueryLog());
        NetworkRepresentative::factory()->count(24)->create();
        DB::flushQueryLog();

        $this->getJson('/api/v1/reference/representatives?per_page=100')->assertOk()->assertJsonCount(25, 'data');

        $many = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertLessThanOrEqual($one + 3, $many, 'Agents and photos must be eager loaded.');
    }
}

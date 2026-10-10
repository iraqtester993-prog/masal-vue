<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\NetworkRepresentative;
use App\Models\PosType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesAccounts;
use Tests\TestCase;

class PosReferenceCreationTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    private function setupNetwork(): array
    {
        $root = $this->account(AccountType::System);
        $main = $this->account(AccountType::MainAgent, $root);
        $sub = $this->account(AccountType::SubAgent, $main);
        $this->asPortalUser($this->userFor($main));

        return [$root, $main, $sub];
    }

    private function payload(Account $parent): array
    {
        return ['name' => 'Office', 'type' => 'pos', 'parent_id' => $parent->id, 'city' => 'بغداد', 'phone' => '07700000000', 'owner_name' => 'Owner', 'address' => 'Recorded address', 'serial' => fake()->unique()->bothify('FIX-####-????'), 'device_model' => 'Recorded device', 'app_version' => '1.0', 'user' => ['email' => 'fixture@example.test', 'password' => 'FixturePassword123', 'password_confirmation' => 'FixturePassword123']];
    }

    public function test_creation_atomically_saves_type_and_multiple_descendant_representatives(): void
    {
        [, $main, $sub] = $this->setupNetwork();
        $first = NetworkRepresentative::factory()->create(['agent_account_id' => $main->id]);
        $second = NetworkRepresentative::factory()->create(['agent_account_id' => $sub->id]);
        $type = PosType::firstOrFail();
        $response = $this->postJson('/api/v1/accounts', $this->payload($main) + ['pos_type_id' => $type->id, 'representative_ids' => [$first->id, $second->id]])->assertCreated();
        $id = $response->json('data.account.id');
        $this->assertDatabaseHas('pos_reference_profiles', ['account_id' => $id, 'pos_type_id' => $type->id]);
        foreach ([$first, $second] as $rep) {
            $this->assertDatabaseHas('pos_representatives', ['account_id' => $id, 'representative_id' => $rep->id]);
        }
        $this->getJson('/api/v1/accounts/'.$id.'/reference-profile')->assertOk()->assertJsonCount(2, 'data.selected_representatives');
    }

    public function test_foreign_representative_rolls_back_account_user_closure_and_audit(): void
    {
        [$root,$main] = $this->setupNetwork();
        $foreign = $this->account(AccountType::MainAgent, $root);
        $rep = NetworkRepresentative::factory()->create(['agent_account_id' => $foreign->id]);
        $counts = [Account::count(), User::count(), DB::table('account_closure')->count(), DB::table('audit_logs')->count()];
        $this->postJson('/api/v1/accounts', $this->payload($main) + ['representative_ids' => [$rep->id]])->assertUnprocessable()->assertJsonValidationErrors('representative_ids');
        $this->assertSame($counts, [Account::count(), User::count(), DB::table('account_closure')->count(), DB::table('audit_logs')->count()]);
        $this->assertDatabaseCount('pos_representatives', 0);
    }

    public function test_disabled_type_and_representative_are_rejected_without_partial_account(): void
    {
        [, $main] = $this->setupNetwork();
        $type = PosType::factory()->create(['status' => 'disabled']);
        $rep = NetworkRepresentative::factory()->create(['agent_account_id' => $main->id, 'status' => 'disabled']);
        $before = Account::count();
        $this->postJson('/api/v1/accounts', $this->payload($main) + ['pos_type_id' => $type->id])->assertUnprocessable()->assertJsonValidationErrors('pos_type_id');
        $this->postJson('/api/v1/accounts', $this->payload($main) + ['representative_ids' => [$rep->id]])->assertUnprocessable()->assertJsonValidationErrors('representative_ids');
        $this->assertDatabaseCount('accounts', $before);
    }

    public function test_update_rejects_invalid_assignment_and_rolls_back_text_and_version(): void
    {
        [$root,$main] = $this->setupNetwork();
        $pos = $this->account(AccountType::Pos, $main);
        $pos->update(['city' => 'بغداد', 'phone' => '07700000000', 'owner_name' => 'Owner', 'address' => 'Recorded address', 'serial' => 'RECORDED', 'device_model' => 'M', 'app_version' => '1']);
        $foreign = $this->account(AccountType::MainAgent, $root);
        $rep = NetworkRepresentative::factory()->create(['agent_account_id' => $foreign->id]);
        $this->patchJson('/api/v1/accounts/'.$pos->id, ['version' => 1, 'name' => 'Edited text', 'representative_ids' => [$rep->id]])->assertUnprocessable()->assertJsonValidationErrors('representative_ids');
        $this->assertDatabaseHas('accounts', ['id' => $pos->id, 'name' => $pos->name, 'version' => 1]);
    }

    public function test_type_form_status_matches_original_create_and_edit_fields(): void
    {
        [$root] = $this->setupNetwork();
        $this->asPortalUser($this->userFor($root));
        $created = $this->postJson('/api/v1/reference/pos-types', ['name' => 'Recorded type', 'status' => 'disabled'])->assertCreated()->assertJsonPath('data.status', 'disabled');
        $this->patchJson('/api/v1/reference/pos-types/'.$created->json('data.id'), ['version' => 1, 'name' => 'Edited type', 'status' => 'active'])->assertOk()->assertJsonPath('data.status', 'active');
    }

    public function test_reference_choices_include_only_active_representatives_below_selected_parent(): void
    {
        [, $main,$sub] = $this->setupNetwork();
        $included = NetworkRepresentative::factory()->create(['agent_account_id' => $sub->id]);
        NetworkRepresentative::factory()->create(['agent_account_id' => $main->id]);
        NetworkRepresentative::factory()->create(['agent_account_id' => $sub->id, 'status' => 'disabled']);
        $this->getJson('/api/v1/reference/options?agent_account_id='.$sub->id)->assertOk()->assertJsonCount(1, 'data.available_representatives')->assertJsonPath('data.available_representatives.0.id', $included->id);
    }
}

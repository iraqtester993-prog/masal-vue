<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Models\Account;
use App\Services\AccountScope;
use App\Services\ManagementAuthority;
use App\Support\AccountPhone;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\Support\CreatesAccounts;
use Tests\TestCase;

class AccountPhoneUniquenessTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    private function details(Account $account, string $phone): void
    {
        $account->fill(['phone' => $phone, 'city' => 'بغداد', 'color' => '#123456'])->save();
    }

    private function payload(Account $parent, string $phone): array
    {
        return ['name' => 'Office', 'type' => 'pos', 'parent_id' => $parent->id, 'city' => 'بغداد', 'phone' => $phone, 'owner_name' => 'Owner', 'address' => 'Address', 'device_model' => 'Model', 'app_version' => '1.0', 'serial' => 'NEW-SERIAL', 'user' => ['email' => 'new@example.test', 'password' => 'FixturePassword123', 'password_confirmation' => 'FixturePassword123']];
    }

    private function phoneMigration(): Migration
    {
        return require database_path('migrations/2026_10_19_075658_enforce_unique_account_phones.php');
    }

    private function removePhoneConstraint(): void
    {
        Schema::table('accounts', fn (Blueprint $table) => $table->dropUnique(AccountPhone::UNIQUE_INDEX));
    }

    public function test_equivalent_iraqi_formats_have_one_key(): void
    {
        foreach (['07701234567', '+964 770 123 4567', '00964(770)123-4567', '٠٧٧٠١٢٣٤٥٦٧', '۰۷۷۰۱۲۳۴۵۶۷'] as $number) {
            $this->assertSame('9647701234567', AccountPhone::key($number));
        }
        $this->assertNull(AccountPhone::key(null));
        $this->assertNull(AccountPhone::key(' '));
    }

    public function test_creation_rejects_phone_of_another_agent_even_when_creating_a_pos(): void
    {
        $root = $this->account(AccountType::System);
        $main = $this->account(AccountType::MainAgent, $root);
        $this->details($main, '+964 770 123 4567');
        $this->asPortalUser($this->userFor($root));
        $this->postJson('/api/v1/accounts', $this->payload($main, '07701234567'))
            ->assertUnprocessable()->assertJsonValidationErrors('phone');
        $this->assertDatabaseCount('accounts', 2);
        $this->assertDatabaseMissing('users', ['email' => 'new@example.test']);
    }

    public function test_update_rejects_equivalent_phone_and_preserves_record(): void
    {
        $root = $this->account(AccountType::System);
        $first = $this->account(AccountType::MainAgent, $root);
        $second = $this->account(AccountType::MainAgent, $root);
        $this->details($first, '00964 770 123 4567');
        $this->details($second, '07801234567');
        $this->asPortalUser($this->userFor($root));
        $this->patchJson('/api/v1/accounts/'.$second->id, ['version' => $second->version, 'phone' => '٠٧٧٠١٢٣٤٥٦٧'])->assertUnprocessable()->assertJsonValidationErrors('phone');
        $this->assertSame('07801234567', $second->fresh()->phone);
    }

    public function test_only_eleven_local_077_or_078_digits_are_accepted_for_a_new_phone(): void
    {
        $root = $this->account(AccountType::System);
        $main = $this->account(AccountType::MainAgent, $root);
        $this->asPortalUser($this->userFor($root));
        foreach (['0770123456', '077012345678', '07901234567', '07501234567', '+9647701234567', '009647701234567', '0770 123 4567', '0770-1234567', '+++', '()'] as $invalid) {
            $this->postJson('/api/v1/accounts', $this->payload($main, $invalid))->assertUnprocessable()->assertJsonValidationErrors('phone');
        }
        $this->assertDatabaseCount('accounts', 2);
        $response = $this->postJson('/api/v1/accounts', $this->payload($main, '۰۷۸۰۱۲۳۴۵۶۷'))->assertCreated();
        $this->assertDatabaseHas('accounts', ['id' => $response->json('data.account.id'), 'phone' => '07801234567', 'phone_key' => '9647801234567']);
    }

    public function test_global_uniqueness_includes_system_disabled_and_archived_accounts(): void
    {
        $root = $this->account(AccountType::System);
        $main = $this->account(AccountType::MainAgent, $root);
        $archived = $this->account(AccountType::MainAgent, $root);
        $this->details($root, '+9647701234567');
        $this->details($archived, '07801234567');
        $archived->forceFill(['status' => 'disabled', 'archived_at' => now()])->save();
        $this->assertNotNull($archived->fresh()->archived_at);
        $this->asPortalUser($this->userFor($root));
        foreach (['07701234567', '07801234567'] as $phone) {
            $this->postJson('/api/v1/accounts', $this->payload($main, $phone))->assertUnprocessable()->assertJsonValidationErrors('phone');
        }
        $this->assertDatabaseCount('accounts', 3);
    }

    public function test_unique_self_number_and_unchanged_legacy_international_number_allow_other_edits(): void
    {
        $root = $this->account(AccountType::System);
        $main = $this->account(AccountType::MainAgent, $root);
        $this->details($main, '+964 770 123 4567');
        $this->asPortalUser($this->userFor($root));
        $this->patchJson('/api/v1/accounts/'.$main->id, ['version' => $main->version, 'name' => 'Updated', 'phone' => '+964 770 123 4567'])->assertOk();
        $this->assertSame('+964 770 123 4567', $main->fresh()->phone);
        $this->patchJson('/api/v1/accounts/'.$main->id, ['version' => $main->fresh()->version, 'phone' => '07701234567'])->assertOk();
        $this->assertSame('07701234567', $main->fresh()->phone);
        $this->patchJson('/api/v1/accounts/'.$main->id, ['version' => $main->fresh()->version, 'phone' => '+9647701234567'])->assertUnprocessable()->assertJsonValidationErrors('phone');
    }

    public function test_legacy_duplicate_is_rejected_even_if_its_normalized_phone_is_unchanged(): void
    {
        $this->removePhoneConstraint();
        $root = $this->account(AccountType::System);
        $first = $this->account(AccountType::MainAgent, $root);
        $second = $this->account(AccountType::MainAgent, $root);
        $this->details($first, '07701234567');
        $this->details($second, '+9647701234567');
        $this->asPortalUser($this->userFor($root));
        $this->patchJson('/api/v1/accounts/'.$second->id, ['version' => $second->version, 'name' => 'Updated', 'phone' => '٠٧٧٠١٢٣٤٥٦٧'])->assertUnprocessable()->assertJsonValidationErrors('phone');
        $this->assertNotSame('Updated', $second->fresh()->name);
    }

    public function test_database_constraint_prevents_duplicate_canonical_keys_outside_request_validation(): void
    {
        $root = $this->account(AccountType::System);
        $main = $this->account(AccountType::MainAgent, $root);
        $this->details($root, '07701234567');
        try {
            $this->details($main, '+964 770 123 4567');
            $this->fail('The database must reject another account with the same canonical phone.');
        } catch (UniqueConstraintViolationException $failure) {
            $this->assertTrue(AccountPhone::duplicateViolation($failure));
        }
        $this->assertNull($main->fresh()->phone);
    }

    public function test_late_database_phone_conflicts_return_phone_field_errors_for_create_and_update(): void
    {
        $root = $this->account(AccountType::System);
        $first = $this->account(AccountType::MainAgent, $root);
        $second = $this->account(AccountType::MainAgent, $root);
        $this->details($first, '07701234567');
        $this->details($second, '07801234567');
        $this->asPortalUser($this->userFor($root));
        $authority = Mockery::mock(ManagementAuthority::class, [app(AccountScope::class)])->makePartial();
        $authority->shouldReceive('data')->twice()->andReturn(['name' => 'Conflicting', 'city' => 'بغداد', 'phone' => '07701234567', 'color' => '#123456']);
        $this->app->instance(ManagementAuthority::class, $authority);

        $this->postJson('/api/v1/accounts', $this->payload($first, '07701234567'))->assertUnprocessable()->assertJsonValidationErrors('phone');
        $this->patchJson('/api/v1/accounts/'.$second->id, ['version' => $second->version, 'phone' => '07701234567'])->assertUnprocessable()->assertJsonValidationErrors('phone');
        $this->assertSame('07801234567', $second->fresh()->phone);
        $this->assertDatabaseCount('accounts', 3);
        $this->assertDatabaseMissing('users', ['email' => 'new@example.test']);
    }

    public function test_migration_reports_legacy_duplicate_ids_before_changing_any_keys(): void
    {
        $this->removePhoneConstraint();
        $root = $this->account(AccountType::System);
        $main = $this->account(AccountType::MainAgent, $root);
        $this->details($root, '07701234567');
        $this->details($main, '+9647701234567');
        DB::table('accounts')->whereIn('id', [$root->id, $main->id])->update(['phone_key' => null]);

        try {
            $this->phoneMigration()->up();
            $this->fail('Legacy duplicates must stop this migration.');
        } catch (\RuntimeException $failure) {
            $this->assertStringContainsString('Duplicate account IDs: ['.$root->id.', '.$main->id.']', $failure->getMessage());
        }
        $this->assertNull($root->fresh()->phone_key);
        $this->assertNull($main->fresh()->phone_key);
        $this->assertSame('07701234567', $root->fresh()->phone);
        $this->assertSame('+9647701234567', $main->fresh()->phone);
    }

    public function test_migration_backfills_system_and_legacy_keys_before_adding_unique_constraint(): void
    {
        $this->removePhoneConstraint();
        $root = $this->account(AccountType::System);
        $main = $this->account(AccountType::MainAgent, $root);
        $this->details($root, '+9647701234567');
        $this->details($main, '٠٧٨٠١٢٣٤٥٦٧');
        DB::table('accounts')->whereIn('id', [$root->id, $main->id])->update(['phone_key' => null]);
        $this->phoneMigration()->up();

        $this->assertSame('9647701234567', $root->fresh()->phone_key);
        $this->assertSame('9647801234567', $main->fresh()->phone_key);
        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('accounts')->where('id', $main->id)->update(['phone_key' => '9647701234567']);
    }
}

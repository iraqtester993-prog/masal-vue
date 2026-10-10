<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Models\Account;
use App\Support\AccountPhone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesAccounts;
use Tests\TestCase;

class AccountPhoneUniquenessTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    private function details(Account $account, string $phone): void
    {
        $account->fill(['phone' => $phone, 'city' => 'بغداد', 'color' => '#123456'])->save();
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
        $this->details($main, '07701234567');
        $this->asPortalUser($this->userFor($root));
        $this->postJson('/api/v1/accounts', ['name' => 'Office', 'type' => 'pos', 'parent_id' => $main->id, 'city' => 'بغداد', 'phone' => '+964 770 123 4567', 'owner_name' => 'Owner', 'address' => 'Address', 'device_model' => 'Model', 'app_version' => '1.0', 'serial' => 'NEW-SERIAL', 'user' => ['email' => 'new@example.test', 'password' => 'FixturePassword123', 'password_confirmation' => 'FixturePassword123']])
            ->assertUnprocessable()->assertJsonValidationErrors('phone');
        $this->assertDatabaseCount('accounts', 2);
        $this->assertDatabaseMissing('users', ['email' => 'new@example.test']);
    }

    public function test_update_rejects_equivalent_phone_and_preserves_record(): void
    {
        $root = $this->account(AccountType::System);
        $first = $this->account(AccountType::MainAgent, $root);
        $second = $this->account(AccountType::MainAgent, $root);
        $this->details($first, '07701234567');
        $this->details($second, '07801234567');
        $this->asPortalUser($this->userFor($root));
        $this->patchJson('/api/v1/accounts/'.$second->id, ['version' => $second->version, 'phone' => '00964 770 123 4567'])->assertUnprocessable()->assertJsonValidationErrors('phone');
        $this->assertSame('07801234567', $second->fresh()->phone);
    }

    public function test_legacy_duplicate_can_keep_its_phone_but_cannot_be_assigned_to_a_new_account(): void
    {
        $root = $this->account(AccountType::System);
        $first = $this->account(AccountType::MainAgent, $root);
        $second = $this->account(AccountType::MainAgent, $root);
        $this->details($first, '07701234567');
        $this->details($second, '+9647701234567');
        $this->asPortalUser($this->userFor($root));
        $this->patchJson('/api/v1/accounts/'.$second->id, ['version' => $second->version, 'name' => 'Updated', 'phone' => '٠٧٧٠١٢٣٤٥٦٧'])->assertOk();
        $this->assertSame('Updated', $second->fresh()->name);
    }
}

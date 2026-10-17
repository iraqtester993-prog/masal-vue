<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Models\Notifications\Notice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CreatesAccounts;
use Tests\TestCase;

class OperationalHealthTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    public function test_missing_backup_alerts_only_system_owner_and_deduplicates_the_warning(): void
    {
        Storage::fake('local');
        $system = $this->account(AccountType::System);
        $owner = $this->userFor($system);
        $agent = $this->userFor($this->account(AccountType::MainAgent, $system));
        $this->artisan('system:health-check', ['--notify' => true])->assertFailed();
        $this->artisan('system:health-check', ['--notify' => true])->assertFailed();
        $this->assertDatabaseCount('notices', 1);
        $this->assertDatabaseHas('notice_recipients', ['user_id' => $owner->id]);
        $this->assertDatabaseMissing('notice_recipients', ['user_id' => $agent->id]);
        $this->assertNull(Notice::firstOrFail()->sender_id);
        $this->artisan('backups:create-scheduled')->assertSuccessful();
        $this->artisan('system:health-check')->assertSuccessful();
        $this->assertDatabaseCount('notices', 1);
    }
}

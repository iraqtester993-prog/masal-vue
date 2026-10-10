<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Models\AccountMembership;
use App\Models\PermissionProfile;
use App\Models\Preferences\UserPreference;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Backups\SnapshotArchive;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CreatesAccounts;
use Tests\TestCase;

class UserPreferencesTest extends TestCase
{
    use CreatesAccounts, LazilyRefreshDatabase;

    private function actors(): array
    {
        $system = $this->account(AccountType::System);
        $main = $this->account(AccountType::MainAgent, $system);
        $pos = $this->account(AccountType::Pos, $main);
        $owner = $this->userFor($system);
        $agent = $this->userFor($main);
        $seller = $this->userFor($pos);
        $profile = PermissionProfile::factory()->create(['account_id' => $system->id]);
        $employee = User::factory()->create();
        AccountMembership::create(['user_id' => $employee->id, 'account_id' => $system->id, 'kind' => 'employee', 'role_id' => DB::table('roles')->where('name', 'employee')->value('id'), 'permission_profile_id' => $profile->id, 'include_descendants' => false, 'status' => 'active']);

        return compact('owner', 'agent', 'seller', 'employee', 'system', 'main');
    }

    public function test_guest_and_wrong_portal_cannot_read_or_change_user_preferences(): void
    {
        $this->withHeader('X-Masal-Portal', 'admin')->getJson('/api/v1/preferences')->assertUnauthorized();
        $this->putJson('/api/v1/preferences', ['language' => 'en', 'theme' => 'dark', 'version' => 0])->assertUnauthorized();
        $actors = $this->actors();
        $this->asPortalUser($actors['agent']);
        $this->withHeader('X-Masal-Portal', 'admin')->getJson('/api/v1/preferences')->assertForbidden();

        $this->assertSame(0, UserPreference::count());
    }

    public function test_default_read_is_readonly_exact_dto_for_owner_agent_employee_and_pos_without_new_permissions(): void
    {
        $actors = $this->actors();
        $before = DB::table('audit_logs')->count();

        foreach (['owner', 'agent', 'seller', 'employee'] as $kind) {
            $this->asPortalUser($actors[$kind]);
            $this->getJson('/api/v1/preferences')->assertExactJson(['data' => ['language' => 'ar', 'theme' => 'light', 'version' => 0]]);
        }

        $this->assertSame(0, UserPreference::count());
        $this->assertSame($before, DB::table('audit_logs')->count());
    }

    public function test_saved_language_and_theme_belong_only_to_current_user_and_do_not_invalidate_sessions(): void
    {
        $actors = $this->actors();
        $sessionVersion = $actors['owner']->fresh()->session_version;
        $this->asPortalUser($actors['owner']);

        $this->putJson('/api/v1/preferences', ['language' => 'ckb', 'theme' => 'dark', 'version' => 0])->assertExactJson(['data' => ['language' => 'ckb', 'theme' => 'dark', 'version' => 1]]);

        $this->assertDatabaseHas('user_preferences', ['user_id' => $actors['owner']->id, 'language' => 'ckb', 'theme' => 'dark', 'version' => 1]);
        $this->assertSame($sessionVersion, $actors['owner']->fresh()->session_version);
        $this->assertDatabaseHas('audit_logs', ['user_id' => $actors['owner']->id, 'action' => 'preferences.updated']);
        $this->asPortalUser($actors['employee']);
        $this->getJson('/api/v1/preferences')->assertJsonPath('data.language', 'ar')->assertJsonPath('data.version', 0);
        $this->putJson('/api/v1/preferences', ['language' => 'en', 'theme' => 'light', 'version' => 0])->assertJsonPath('data.version', 1);
        $this->asPortalUser($actors['owner']);
        $this->getJson('/api/v1/preferences')->assertExactJson(['data' => ['language' => 'ckb', 'theme' => 'dark', 'version' => 1]]);
        $this->assertSame(2, UserPreference::count());
    }

    public function test_invalid_enums_missing_fields_and_foreign_user_payload_are_422_without_any_preferences_write(): void
    {
        $actors = $this->actors();
        $this->asPortalUser($actors['owner']);
        $valid = ['language' => 'ar', 'theme' => 'light', 'version' => 0];

        foreach ([['language' => 'ku'], ['theme' => 'auto'], ['version' => -1], ['version' => 4294967295], ['user_id' => $actors['employee']->id], ['language' => '<script>'], ['theme' => null]] as $changes) {
            $this->putJson('/api/v1/preferences', array_replace($valid, $changes))->assertUnprocessable();
        }
        $this->putJson('/api/v1/preferences', ['language' => 'en'])->assertUnprocessable();
        $this->getJson('/api/v1/preferences?user_id='.$actors['employee']->id)->assertUnprocessable();

        $this->assertSame(0, UserPreference::count());
        $this->assertDatabaseMissing('audit_logs', ['action' => 'preferences.updated']);
    }

    public function test_stale_initial_or_existing_version_is_409_and_keeps_saved_language_theme_and_audit_count(): void
    {
        $actors = $this->actors();
        UserPreference::factory()->create(['user_id' => $actors['owner']->id, 'language' => 'en', 'theme' => 'dark', 'version' => 4]);
        $this->asPortalUser($actors['owner']);
        $before = DB::table('audit_logs')->count();

        foreach ([0, 3] as $stale) {
            $this->putJson('/api/v1/preferences', ['language' => 'ar', 'theme' => 'light', 'version' => $stale])->assertConflict();
        }

        $this->assertDatabaseHas('user_preferences', ['user_id' => $actors['owner']->id, 'language' => 'en', 'theme' => 'dark', 'version' => 4]);
        $this->assertSame($before, DB::table('audit_logs')->count());
        $this->putJson('/api/v1/preferences', ['language' => 'ar', 'theme' => 'light', 'version' => 4])->assertJsonPath('data.version', 5);
    }

    public function test_restore_write_gate_is_423_and_inactive_parent_is_403_without_changing_preferences(): void
    {
        $actors = $this->actors();
        $this->asPortalUser($actors['agent']);
        DB::table('runtime_write_gate')->where('id', 1)->update(['blocked' => true]);

        $this->putJson('/api/v1/preferences', ['language' => 'en', 'theme' => 'dark', 'version' => 0])->assertStatus(423);
        DB::table('runtime_write_gate')->where('id', 1)->update(['blocked' => false]);
        $actors['main']->update(['status' => 'disabled']);
        $this->getJson('/api/v1/preferences')->assertForbidden();
        $this->putJson('/api/v1/preferences', ['language' => 'en', 'theme' => 'dark', 'version' => 0])->assertForbidden();

        $this->assertSame(0, UserPreference::count());
    }

    public function test_failed_audit_rolls_back_preferences_and_signed_backups_include_preferences_without_runtime_sessions(): void
    {
        $actors = $this->actors();
        $this->asPortalUser($actors['owner']);
        $this->mock(AuditLogger::class)->shouldReceive('record')->once()->andThrow(new \RuntimeException('Audit storage unavailable'));

        $this->putJson('/api/v1/preferences', ['language' => 'en', 'theme' => 'dark', 'version' => 0])->assertServerError();

        $this->assertSame(0, UserPreference::count());
        $this->app->forgetInstance(AuditLogger::class);
        UserPreference::factory()->create(['user_id' => $actors['owner']->id, 'language' => 'en', 'theme' => 'dark']);
        Storage::fake('local');
        $archive = app(SnapshotArchive::class);
        $archive->create(DB::connection(), 'backups/preferences-test.json');
        $preferences = [];
        $verified = $archive->read('backups/preferences-test.json', function (array $record) use (&$preferences): void {
            if ($record['kind'] === 'row' && $record['table'] === 'user_preferences') {
                $preferences[] = $record['row'];
            }
        });
        $this->assertCount(1, $preferences);
        $this->assertSame($actors['owner']->id, $preferences[0]['user_id']);
        $this->assertSame('en', $preferences[0]['language']);
        $this->assertArrayNotHasKey('sessions', $verified['manifest']['counts']);
    }
}

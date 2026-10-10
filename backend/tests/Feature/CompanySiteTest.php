<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Models\AccountMembership;
use App\Models\Company\CompanyAsset;
use App\Models\Company\CompanyInquiry;
use App\Models\Company\CompanyProfile;
use App\Models\PermissionProfile;
use App\Models\User;
use App\Services\Company\CompanyConversations;
use App\Services\ManagementAuthority;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\CreatesAccounts;
use Tests\TestCase;

class CompanySiteTest extends TestCase
{
    use CreatesAccounts,RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeader('X-Masal-Portal', 'admin')->withServerVariables(['REMOTE_ADDR' => '198.51.100.'.(crc32($this->name()) % 200 + 1)]);
    }

    private function actors(): array
    {
        $system = $this->account(AccountType::System);
        $main = $this->account(AccountType::MainAgent, $system);

        return ['system' => $system, 'main' => $main, 'admin' => $this->userFor($system), 'agent' => $this->userFor($main)];
    }

    private function profile(): array
    {
        return CompanyProfile::findOrFail(1)->content;
    }

    private function published(): void
    {
        $profile = $this->profile();
        CompanyProfile::findOrFail(1)->update(['published' => $profile, 'published_at' => now()]);
    }

    private function inquiry(array $changes = []): array
    {
        return array_replace(['name' => 'زائر فعلي', 'contact' => '07700000000', 'message' => 'أحتاج تفاصيل الخدمة', 'website_honeypot' => '', 'idempotency_key' => (string) Str::uuid()], $changes);
    }

    public function test_site_messages_are_visible_in_support_and_replies_reach_private_tracking_page(): void
    {
        $tree = $this->actors();
        $this->published();
        $payload = $this->inquiry();
        $created = $this->postJson('/api/v1/company/inquiries', $payload)->assertCreated();
        $id = $created->json('data.id');
        $token = $created->json('data.tracking_token');
        $this->postJson('/api/v1/company/inquiries', $payload)->assertJsonPath('data.tracking_token', $token);
        $this->postJson('/api/v1/company/inquiries/track', ['token' => $token])->assertOk()->assertJsonPath('data.message', $payload['message'])->assertJsonCount(0, 'data.messages')->assertJsonMissingPath('data.contact')->assertJsonMissingPath('data.tracking_token');
        $this->postJson('/api/v1/company/inquiries/track', ['token' => $id.'.'.str_repeat('a', 64)])->assertNotFound();
        $this->postJson('/api/v1/company/inquiries/track', ['token' => (string) $id])->assertUnprocessable();
        $this->getJson('/api/v1/support/site-inquiries')->assertUnauthorized();
        $this->asPortalUser($tree['admin']);
        $this->getJson('/api/v1/support/site-inquiries')->assertOk()->assertJsonPath('meta.new_count', 1)->assertJsonPath('data.0.id', $id);
        $this->getJson('/api/v1/notifications/summary')->assertOk()->assertJsonPath('data.sidebar_counts.support', 1);
        $this->getJson('/api/v1/support/site-inquiries/'.$id)->assertOk()->assertJsonPath('data.tracking_token', $token);
        $reply = ['version' => 1, 'body' => 'رد المدير', 'idempotency_key' => (string) Str::uuid()];
        $this->postJson('/api/v1/support/site-inquiries/'.$id.'/replies', $reply)->assertOk()->assertJsonPath('data.version', 2)->assertJsonPath('data.status', 'followed')->assertJsonPath('data.messages.0.sender', 'staff');
        $this->postJson('/api/v1/support/site-inquiries/'.$id.'/replies', $reply)->assertOk()->assertJsonCount(1, 'data.messages');
        $this->postJson('/api/v1/support/site-inquiries/'.$id.'/replies', array_replace($reply, ['body' => 'Different']))->assertConflict();
        $this->postJson('/api/v1/support/site-inquiries/'.$id.'/replies', array_replace($reply, ['idempotency_key' => (string) Str::uuid()]))->assertConflict();
        $this->postJson('/api/v1/company/inquiries/track', ['token' => $token])->assertOk()->assertJsonPath('data.messages.0.body', 'رد المدير')->assertJsonMissingPath('data.messages.0.user_id');
        $followup = ['token' => $token, 'body' => 'متابعة الزبون', 'idempotency_key' => (string) Str::uuid(), 'website_honeypot' => ''];
        $this->postJson('/api/v1/company/inquiries/followup', $followup)->assertOk()->assertJsonPath('data.status', 'new')->assertJsonPath('data.version', 3)->assertJsonPath('data.messages.1.sender', 'visitor');
        $this->postJson('/api/v1/company/inquiries/followup', $followup)->assertOk()->assertJsonCount(2, 'data.messages');
        $this->assertDatabaseCount('company_inquiry_messages', 2);
        $this->assertDatabaseHas('audit_logs', ['action' => 'support.site-inquiry.reply', 'user_id' => $tree['admin']->id]);
    }

    public function test_site_inbox_preserves_system_only_access_and_reply_permission(): void
    {
        $tree = $this->actors();
        $record = CompanyInquiry::factory()->create();
        $this->asPortalUser($tree['agent']);
        $this->getJson('/api/v1/support/site-inquiries')->assertForbidden();
        $this->getJson('/api/v1/support/site-inquiries/'.$record->id)->assertForbidden();
        $this->postJson('/api/v1/support/site-inquiries/'.$record->id.'/replies', ['version' => 1, 'body' => 'Forbidden', 'idempotency_key' => (string) Str::uuid()])->assertForbidden();
        $this->asPortalUser($tree['admin']);
        DB::table('membership_permissions')->insert(['membership_id' => $tree['admin']->membership->id, 'permission_id' => DB::table('permissions')->where('name', 'support.reply')->value('id'), 'allowed' => false]);
        $this->getJson('/api/v1/support/site-inquiries')->assertOk();
        $this->postJson('/api/v1/support/site-inquiries/'.$record->id.'/replies', ['version' => 1, 'body' => 'Forbidden', 'idempotency_key' => (string) Str::uuid()])->assertForbidden();
        $this->assertDatabaseCount('company_inquiry_messages', 0);
    }

    public function test_public_tracking_host_is_allowed_without_exposing_admin_endpoints_and_restore_blocks_replies(): void
    {
        $tree = $this->actors();
        $this->published();
        $record = CompanyInquiry::factory()->create();
        $token = app(CompanyConversations::class)->token($record);
        $this->withHeader('X-Masal-Portal', 'public');
        $this->postJson('/api/v1/company/inquiries/track', ['token' => $token])->assertOk();
        $this->getJson('/api/v1/support/site-inquiries')->assertNotFound();
        $this->getJson('/api/v1/accounts')->assertNotFound();
        DB::table('runtime_write_gate')->where('id', 1)->update(['blocked' => true, 'restore_job_id' => (string) Str::uuid()]);
        $this->postJson('/api/v1/company/inquiries/followup', ['token' => $token, 'body' => 'Blocked', 'idempotency_key' => (string) Str::uuid(), 'website_honeypot' => ''])->assertStatus(423);
        $this->asPortalUser($tree['admin']);
        $this->postJson('/api/v1/support/site-inquiries/'.$record->id.'/replies', ['version' => 1, 'body' => 'Blocked', 'idempotency_key' => (string) Str::uuid()])->assertStatus(423);
        $this->assertDatabaseCount('company_inquiry_messages', 0);
    }

    private function image(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('company.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aF3sAAAAASUVORK5CYII='));
    }

    public function test_company_and_public_inquiry_writes_are_blocked_during_restore_drain_without_orphan_files(): void
    {
        Storage::fake('local');
        $tree = $this->actors();
        $this->published();
        $this->asPortalUser($tree['admin']);
        DB::table('runtime_write_gate')->where('id', 1)->update(['blocked' => true, 'restore_job_id' => (string) Str::uuid()]);
        $this->putJson('/api/v1/company/profile', ['version' => 1, 'profile' => $this->profile()])->assertStatus(423);
        $this->post('/api/v1/company/assets', ['file' => $this->image()], ['Accept' => 'application/json'])->assertStatus(423);
        $this->postJson('/api/v1/company/inquiries', $this->inquiry())->assertStatus(423);
        $this->assertSame(0, CompanyInquiry::count());
        $this->assertSame(0, CompanyAsset::count());
        $this->assertSame(1, CompanyProfile::findOrFail(1)->version);
        $this->assertSame([], Storage::disk('local')->allFiles('company/assets'));
    }

    public function test_public_profile_is_unavailable_until_real_authorized_save_and_hidden_content_is_removed(): void
    {
        $this->getJson('/api/v1/company/public')->assertNotFound();
        $tree = $this->actors();
        $this->asPortalUser($tree['admin']);
        $p = $this->profile();
        $p['name'] = 'شركة منشورة';
        $p['about'] = 'PRIVATE_ABOUT';
        $p['email'] = 'PRIVATE_CONTACT@example.com';
        $p['website'] = 'https://example.com/PRIVATE_WEBSITE';
        $p['visibility']['about'] = false;
        $p['visibility']['care'] = false;
        $p['slides'] = [['title' => 'الشريحة الثانية', 'description' => 'تفاصيل عامة', 'image' => null, 'link' => 'https://example.com', 'visible' => true], ['title' => 'PRIVATE_SLIDE', 'description' => 'PRIVATE_DESCRIPTION', 'image' => null, 'link' => 'https://example.com/PRIVATE_URL', 'visible' => false], ['title' => 'الشريحة الأولى', 'description' => '', 'image' => null, 'link' => '', 'visible' => true]];
        $this->putJson('/api/v1/company/profile', ['version' => 1, 'profile' => $p])->assertOk()->assertJsonPath('data.version', 2)->assertJsonPath('data.profile.about', 'PRIVATE_ABOUT');
        $public = $this->getJson('/api/v1/company/public')->assertOk()->assertJsonPath('data.profile.name', 'شركة منشورة')->assertJsonCount(2, 'data.profile.slides')->assertJsonPath('data.profile.slides.0.title', 'الشريحة الثانية')->assertJsonPath('data.profile.slides.1.title', 'الشريحة الأولى');
        $this->assertStringNotContainsString('PRIVATE_', $public->getContent());
        $this->assertArrayNotHasKey('editor_id', $public->json('data'));
        $this->assertArrayNotHasKey('version', $public->json('data'));
        $this->putJson('/api/v1/company/profile', ['version' => 1, 'profile' => $p])->assertConflict();
        $this->postJson('/api/v1/company/inquiries', $this->inquiry())->assertConflict();
    }

    public function test_management_requires_operational_system_and_explicit_permission_even_for_other_owner_with_grant(): void
    {
        $this->getJson('/api/v1/company/profile')->assertUnauthorized();
        $tree = $this->actors();
        DB::table('membership_permissions')->insert(['membership_id' => $tree['agent']->membership->id, 'permission_id' => DB::table('permissions')->where('name', 'company.edit')->value('id'), 'allowed' => true]);
        $this->asPortalUser($tree['agent']);
        $this->getJson('/api/v1/company/profile')->assertForbidden();
        $this->getJson('/api/v1/company/inquiries')->assertForbidden();
        $profile = PermissionProfile::factory()->create(['account_id' => $tree['system']->id]);
        app(ManagementAuthority::class)->replaceProfilePermissions($profile->id, ['account.view', 'company.edit']);
        $staff = User::factory()->create();
        $member = AccountMembership::create(['user_id' => $staff->id, 'account_id' => $tree['system']->id, 'kind' => 'employee', 'role_id' => DB::table('roles')->where('name', 'employee')->value('id'), 'permission_profile_id' => $profile->id, 'include_descendants' => false, 'status' => 'active']);
        DB::table('membership_scope_roots')->insert(['membership_id' => $member->id, 'account_id' => $tree['main']->id]);
        $this->asPortalUser($staff);
        $this->getJson('/api/v1/company/profile')->assertOk();
        app(ManagementAuthority::class)->replaceProfilePermissions($profile->id, ['account.view']);
        $this->getJson('/api/v1/company/profile')->assertForbidden();
    }

    public function test_profile_rejects_unapproved_protocols_credentials_unknown_fields_and_bad_gallery_shapes(): void
    {
        $tree = $this->actors();
        $this->asPortalUser($tree['admin']);
        foreach (['javascript:alert(1)', 'http://example.com', 'https://user:password@example.com'] as $url) {
            $p = $this->profile();
            $p['website'] = $url;
            $this->putJson('/api/v1/company/profile', ['version' => 1, 'profile' => $p])->assertUnprocessable();
        }
        $p = $this->profile();
        $p['server_path'] = '/private/path';
        $this->putJson('/api/v1/company/profile', ['version' => 1, 'profile' => $p])->assertUnprocessable();
        $this->putJson('/api/v1/company/profile', ['version' => 1, 'profile' => 'invalid'])->assertUnprocessable();
        $p = $this->profile();
        $p['slides'] = ['invalid'];
        $this->putJson('/api/v1/company/profile', ['version' => 1, 'profile' => $p])->assertUnprocessable();
        $item = ['title' => 'عرض', 'description' => '', 'image' => null, 'link' => '', 'visible' => true];
        $p = $this->profile();
        $p['slides'] = array_fill(0, 31, $item);
        $this->putJson('/api/v1/company/profile', ['version' => 1, 'profile' => $p])->assertUnprocessable();
        $p['slides'] = [$item];
        $p['social'] = [['name' => 'المنصة', 'url' => 'https://example.com']];
        $this->putJson('/api/v1/company/profile', ['version' => 1, 'profile' => $p])->assertOk();
    }

    public function test_images_remain_private_until_used_by_published_visible_content_and_disappear_when_hidden(): void
    {
        Storage::fake('local');
        $tree = $this->actors();
        $this->asPortalUser($tree['admin']);
        $upload = $this->post('/api/v1/company/assets', ['file' => $this->image()], ['Accept' => 'application/json'])->assertCreated();
        $id = $upload->json('data.id');
        $this->assertArrayNotHasKey('path', $upload->json('data'));
        $this->get('/api/v1/company/assets/'.$id)->assertOk();
        $this->get('/api/v1/company/public/assets/'.$id)->assertNotFound();
        $p = $this->profile();
        $p['activities'] = [['title' => 'نشاط', 'description' => 'نص', 'image' => $id, 'link' => '', 'visible' => false]];
        $this->putJson('/api/v1/company/profile', ['version' => 1, 'profile' => $p])->assertOk();
        $this->get('/api/v1/company/public/assets/'.$id)->assertNotFound();
        $p['activities'][0]['visible'] = true;
        $this->putJson('/api/v1/company/profile', ['version' => 2, 'profile' => $p])->assertOk();
        $this->getJson('/api/v1/company/public')->assertOk()->assertJsonPath('data.profile.activities.0.image', '/api/v1/company/public/assets/'.$id);
        $this->get('/api/v1/company/public/assets/'.$id)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $p['visibility']['activities'] = false;
        $this->putJson('/api/v1/company/profile', ['version' => 3, 'profile' => $p])->assertOk();
        $this->get('/api/v1/company/public/assets/'.$id)->assertNotFound();
        $this->post('/api/v1/company/assets', ['file' => UploadedFile::fake()->createWithContent('bad.png', '<svg onload="alert(1)">')], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->assertSame(1, CompanyAsset::count());
    }

    public function test_public_inquiry_persists_once_without_email_claim_and_queue_can_follow_and_reopen_with_versions(): void
    {
        $this->published();
        $payload = $this->inquiry();
        $first = $this->postJson('/api/v1/company/inquiries', $payload)->assertCreated()->assertJsonPath('data.registered', true);
        $id = $first->json('data.id');
        $this->postJson('/api/v1/company/inquiries', $payload)->assertCreated()->assertJsonPath('data.id', $id);
        $this->assertSame(1, CompanyInquiry::count());
        $this->postJson('/api/v1/company/inquiries', array_replace($payload, ['message' => 'Different message']))->assertConflict();
        $this->assertArrayNotHasKey('message', $first->json('data'));
        $this->getJson('/api/v1/company/inquiries')->assertUnauthorized();
        $tree = $this->actors();
        $this->asPortalUser($tree['admin']);
        $this->getJson('/api/v1/company/inquiries')->assertOk()->assertJsonPath('meta.new_count', 1)->assertJsonPath('data.0.message', $payload['message']);
        $this->patchJson('/api/v1/company/inquiries/'.$id, ['version' => 1, 'status' => 'followed'])->assertOk()->assertJsonPath('data.version', 2)->assertJsonPath('data.status', 'followed');
        $this->patchJson('/api/v1/company/inquiries/'.$id, ['version' => 1, 'status' => 'new'])->assertConflict();
        $this->getJson('/api/v1/company/inquiries?status=new')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.new_count', 0);
        $this->patchJson('/api/v1/company/inquiries/'.$id, ['version' => 2, 'status' => 'new'])->assertOk()->assertJsonPath('data.version', 3);
    }

    public function test_guest_contact_limit_is_separate_from_public_page_views_and_honeypot_blocks_bot_payload(): void
    {
        $this->published();
        for ($i = 0; $i < 6; $i++) {
            $this->getJson('/api/v1/company/public')->assertOk();
        }
        $this->postJson('/api/v1/company/inquiries', $this->inquiry(['website_honeypot' => 'https://spam.example']))->assertUnprocessable();
        $this->assertSame(0, CompanyInquiry::count());
        for ($i = 0; $i < 4; $i++) {
            $this->postJson('/api/v1/company/inquiries', $this->inquiry())->assertCreated();
        }
        $this->postJson('/api/v1/company/inquiries', $this->inquiry())->assertTooManyRequests();
        $this->assertSame(4, CompanyInquiry::count());
    }

    public function test_public_contact_enforces_csrf_outside_phpunit_bypass(): void
    {
        $this->published();
        $this->app->instance('env', 'local');
        try {
            $this->postJson('/api/v1/company/inquiries', $this->inquiry())->assertStatus(419);
            $this->assertSame(0, CompanyInquiry::count());
            $this->withSession(['_token' => 'company-csrf-token'])->withHeader('X-CSRF-TOKEN', 'company-csrf-token')->postJson('/api/v1/company/inquiries', $this->inquiry())->assertCreated();
            $this->assertSame(1, CompanyInquiry::count());
        } finally {
            $this->app->instance('env', 'testing');
        }
    }

    public function test_queue_pages_filters_literal_search_and_secret_columns_are_not_exported(): void
    {
        $tree = $this->actors();
        CompanyInquiry::factory()->count(24)->create();
        CompanyInquiry::factory()->create(['name' => '100% زائر', 'status' => 'followed']);
        $this->asPortalUser($tree['admin']);
        $first = $this->getJson('/api/v1/company/inquiries')->assertOk()->assertJsonCount(20, 'data')->assertJsonPath('meta.total', 25)->assertJsonPath('meta.last_page', 2);
        foreach (['payload_hash', 'idempotency_key', 'reviewer_id'] as $key) {
            $this->assertArrayNotHasKey($key, $first->json('data.0'));
        }
        $this->getJson('/api/v1/company/inquiries?page=2')->assertOk()->assertJsonCount(5, 'data');
        $this->getJson('/api/v1/company/inquiries?q=100%25&status=followed')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', '100% زائر');
        $this->getJson('/api/v1/company/inquiries?per_page=500')->assertUnprocessable();
    }
}

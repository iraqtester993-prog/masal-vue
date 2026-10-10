<?php

namespace Tests\Feature;

use App\Models\Company\CompanyProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PublicHostIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_main_and_www_hosts_serve_only_published_public_company_content(): void
    {
        $profile = CompanyProfile::findOrFail(1);
        $profile->update(['published' => $profile->content, 'published_at' => now()]);
        foreach (['dananir-iq.com', 'www.dananir-iq.com'] as $host) {
            $this->getJson('https://'.$host.'/api/v1/company/public')->assertOk()->assertJsonPath('data.profile.name', 'ماسال');
            foreach (['auth/me', 'accounts', 'company/profile', 'backups', 'digital/connections'] as $path) {
                $this->getJson('https://'.$host.'/api/v1/'.$path)->assertNotFound();
            }
            $this->postJson('https://'.$host.'/api/v1/auth/login', ['login' => 'forbidden', 'password' => 'forbidden'])->assertNotFound();
        }
    }

    public function test_public_host_allows_inquiries_and_csrf_cookie_without_allowing_private_writes(): void
    {
        $profile = CompanyProfile::findOrFail(1);
        $profile->update(['published' => $profile->content, 'published_at' => now()]);
        $this->getJson('https://dananir-iq.com/sanctum/csrf-cookie')->assertNoContent();
        $this->postJson('https://dananir-iq.com/api/v1/company/inquiries', ['name' => 'زائر', 'contact' => 'visitor@example.test', 'message' => 'طلب معلومات', 'website_honeypot' => '', 'idempotency_key' => (string) Str::uuid()])->assertCreated();
        $this->putJson('https://dananir-iq.com/api/v1/company/profile', [])->assertNotFound();
        $this->assertDatabaseCount('company_inquiries', 1);
    }

    public function test_unknown_hosts_and_production_portal_header_spoofing_are_rejected(): void
    {
        app()->detectEnvironment(fn () => 'production');
        $this->withHeader('X-Masal-Portal', 'admin')->getJson('https://untrusted.example/api/v1/company/public')->assertForbidden();
        $this->getJson('https://dananir-iq.com/api/v1/accounts')->assertNotFound();
        $this->getJson('https://admin.dananir-iq.com/api/v1/accounts')->assertForbidden();
    }
}

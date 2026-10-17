<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Models\CatalogProduct;
use App\Models\Sales\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesAccounts;
use Tests\TestCase;

class ReportExportBoundsTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    private function fixture(): array
    {
        $this->withCredentials();
        $system = $this->account(AccountType::System);
        $main = $this->account(AccountType::MainAgent, $system);
        $pos = $this->account(AccountType::Pos, $main);
        $actor = $this->userFor($system);
        $seller = $this->userFor($pos);
        $product = CatalogProduct::factory()->create();
        for ($index = 0; $index < 3; $index++) {
            Sale::factory()->create(['account_id' => $pos->id, 'main_account_id' => $main->id, 'creator_id' => $seller->id,
                'product_id' => $product->id, 'provider_id' => $product->provider_id, 'status' => 'Printed', 'issued_at' => now()]);
        }
        $this->asPortalUser($actor);

        return compact('system', 'actor', 'main');
    }

    public function test_large_export_returns_private_bounded_download_without_building_all_rows(): void
    {
        $tree = $this->fixture();
        config(['reports.inline_rows' => 1]);
        $result = $this->postJson('/api/v1/reports/export', ['section_ids' => ['sales']])->assertOk()->assertJsonPath('data.download.rows', 3);
        $path = $result->json('data.download.url');
        $this->withCookie('masal_admin_session', app('session')->driver()->getId());
        $this->assertSame([], $result->json('data.sections'));
        $download = $this->getJson($path)->assertOk()->assertHeader('Content-Type', 'text/csv; charset=utf-8');
        $body = $download->streamedContent();
        $this->assertStringContainsString('اكتمل التصدير', $body);
        $this->asPortalUser($this->userFor($tree['main']));
        $this->get($path)->assertNotFound();
    }

    public function test_export_and_print_reject_over_budget_without_partial_rows(): void
    {
        $this->fixture();
        config(['reports.download_rows' => 2, 'reports.print_rows' => 2]);
        $this->postJson('/api/v1/reports/export', ['section_ids' => ['sales']])->assertUnprocessable();
        $this->postJson('/api/v1/reports/export', ['section_ids' => ['sales'], 'purpose' => 'print'])->assertUnprocessable();
    }

    public function test_download_rechecks_permissions_and_revoked_sessions(): void
    {
        $tree = $this->fixture();
        config(['reports.inline_rows' => 1]);
        $path = $this->postJson('/api/v1/reports/export', ['section_ids' => ['sales']])->assertOk()->json('data.download.url');
        $tree['actor']->increment('session_version');
        $this->getJson($path)->assertUnauthorized();
    }

    public function test_export_ticket_does_not_keep_permission_after_it_is_revoked(): void
    {
        $tree = $this->fixture();
        config(['reports.inline_rows' => 1]);
        $path = $this->postJson('/api/v1/reports/export', ['section_ids' => ['sales']])->assertOk()->json('data.download.url');
        $this->withCookie('masal_admin_session', app('session')->driver()->getId());
        $permission = DB::table('permissions')->where('name', 'reports.export')->value('id');
        DB::table('membership_permissions')->insert(['membership_id' => $tree['actor']->membership->id,
            'permission_id' => $permission, 'allowed' => false]);

        $this->getJson($path)->assertForbidden();
        DB::table('membership_permissions')->where('membership_id', $tree['actor']->membership->id)->where('permission_id', $permission)->delete();
        $download = $this->getJson($path)->assertOk();
        $this->assertStringContainsString('اكتمل التصدير', $download->streamedContent());
    }
}

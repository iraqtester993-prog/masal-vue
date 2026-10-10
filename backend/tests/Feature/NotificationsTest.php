<?php

namespace Tests\Feature;

use App\Models\Finance\FundingRequest;
use App\Models\Notifications\Notice;
use App\Models\Stock\StockOrder;
use App\Services\AuditLogger;
use App\Services\Notifications\ActivityNotifications;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesSupport;
use Tests\TestCase;

class NotificationsTest extends TestCase
{
    use CreatesSupport,RefreshDatabase;

    public function test_manual_notice_is_visible_only_to_sender_or_exact_target_and_read_state_is_per_user(): void
    {
        $f = $this->supportFixture();
        $this->asPortalUser($f['admin']);
        $payload = ['title' => 'تنبيه', 'body' => 'المحتوى', 'mode' => 'custom', 'user_ids' => [$f['agent']->id, $f['foreignUser']->id], 'idempotency_key' => 'notice-one'];
        $id = $this->postJson('/api/v1/notifications', $payload)->assertCreated()->assertJsonPath('data.count', 2)->json('data.id');
        $this->postJson('/api/v1/notifications', $payload)->assertCreated();
        $this->assertDatabaseCount('notices', 1);
        $this->assertDatabaseCount('notice_recipients', 2);
        $this->getJson('/api/v1/notifications')->assertOk()->assertJsonPath('data.0.mine', true)->assertJsonPath('data.0.read', true);
        $this->asPortalUser($f['agent']);
        $this->getJson('/api/v1/notifications?filter=unread')->assertOk()->assertJsonPath('meta.total', 1);
        $this->postJson('/api/v1/notifications/'.$id.'/read')->assertOk();
        $this->getJson('/api/v1/notifications/summary')->assertOk()->assertJsonPath('data.unread', 0);
        $this->asPortalUser($f['foreignUser']);
        $this->getJson('/api/v1/notifications?filter=unread')->assertOk()->assertJsonPath('meta.total', 1);
        $this->asPortalUser($f['subUser']);
        $this->getJson('/api/v1/notifications')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson('/api/v1/notifications/'.$id.'/read')->assertNotFound();
    }

    public function test_agent_audience_only_contains_lower_accounts_and_cannot_send_to_parent_own_staff_or_foreign_network(): void
    {
        $f = $this->supportFixture();
        $staff = $this->supportEmployee($f['main'], $f['main'], ['account.view', 'notifications.view']);
        $this->asPortalUser($f['agent']);
        $ids = array_column($this->getJson('/api/v1/notifications/options')->assertOk()->json('data.users'), 'id');
        $this->assertEqualsCanonicalizing([$f['subUser']->id, $f['branchUser']->id, $f['posUser']->id], $ids);
        foreach ([$f['admin']->id, $f['foreignUser']->id, $staff->id] as $id) {
            $this->postJson('/api/v1/notifications', ['title' => 'عنوان', 'body' => 'نص', 'mode' => 'custom', 'user_ids' => [$id], 'idempotency_key' => 'foreign-'.$id])->assertNotFound();
        }
        $this->postJson('/api/v1/notifications', ['title' => 'عنوان', 'body' => 'نص', 'mode' => 'agents', 'idempotency_key' => 'main-mode'])->assertForbidden();
        $this->asPortalUser($f['posUser']);
        $this->getJson('/api/v1/notifications/options')->assertForbidden();
        $this->postJson('/api/v1/notifications', ['title' => 'عنوان', 'body' => 'نص', 'mode' => 'all', 'idempotency_key' => 'pos-send'])->assertForbidden();
    }

    public function test_translations_need_permission_complete_pairs_and_fall_back_to_arabic(): void
    {
        $f = $this->supportFixture();
        $this->asPortalUser($f['admin']);
        $data = ['title' => 'العنوان العربي', 'body' => 'النص العربي', 'mode' => 'custom', 'user_ids' => [$f['agent']->id], 'idempotency_key' => 'translate-one', 'translations' => ['en' => ['title' => 'English title', 'body' => 'English body'], 'ckb' => ['title' => '', 'body' => '']]];
        $this->postJson('/api/v1/notifications', $data)->assertCreated();
        $this->asPortalUser($f['agent']);
        $this->getJson('/api/v1/notifications?locale=en')->assertOk()->assertJsonPath('data.0.title', 'English title');
        $this->getJson('/api/v1/notifications?locale=ckb')->assertOk()->assertJsonPath('data.0.title', 'العنوان العربي');
        $this->asPortalUser($f['admin']);
        $this->postJson('/api/v1/notifications', array_replace($data, ['idempotency_key' => 'partial-translation', 'translations' => ['en' => ['title' => 'partial', 'body' => '']]]))->assertUnprocessable();
        $this->postJson('/api/v1/notifications', array_replace($data, ['idempotency_key' => 'unknown-locale', 'translations' => ['fr' => ['title' => 'titre', 'body' => 'texte']]]))->assertUnprocessable();
        $this->supportGrant($f['admin'], 'notifications.translations', false);
        $this->asPortalUser($f['admin']);
        $this->postJson('/api/v1/notifications', array_replace($data, ['idempotency_key' => 'denied-translation']))->assertForbidden();
    }

    public function test_read_page_only_marks_current_users_incoming_page_notices_and_export_escapes_formulas(): void
    {
        $f = $this->supportFixture();
        $first = Notice::factory()->toUser($f['agent'])->toUser($f['foreignUser'])->create(['sender_id' => $f['admin']->id, 'title' => '=HYPERLINK("bad")', 'body' => '@bad', 'page' => 'wallets']);
        Notice::factory()->toUser($f['agent'])->create(['sender_id' => $f['admin']->id, 'page' => 'prices']);
        $this->asPortalUser($f['agent']);
        $this->postJson('/api/v1/notifications/read-page', ['page' => 'wallets'])->assertOk()->assertJsonPath('data.count', 1);
        $this->getJson('/api/v1/notifications/summary')->assertOk()->assertJsonPath('data.unread', 1);
        $csv = $this->getJson('/api/v1/notifications/export')->assertOk()->assertJsonPath('data.count', 2)->json('data.csv');
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringContainsString("'@bad", $csv);
        $this->asPortalUser($f['foreignUser']);
        $this->getJson('/api/v1/notifications?filter=unread')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/notifications?query=%25')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/notifications?per_page=101')->assertUnprocessable();
        $this->postJson('/api/v1/notifications/'.$first->id.'/read', ['user_id' => $f['agent']->id])->assertUnprocessable();
    }

    public function test_employee_notice_audience_is_limited_to_assigned_roots_and_inactive_ancestors_are_excluded(): void
    {
        $f = $this->supportFixture();
        $employee = $this->supportEmployee($f['system'], $f['main'], ['account.view', 'notifications.view', 'notifications.send']);
        $this->asPortalUser($employee);
        $ids = array_column($this->getJson('/api/v1/notifications/options')->assertOk()->json('data.users'), 'id');
        $this->assertContains($f['agent']->id, $ids);
        $this->assertNotContains($f['foreignUser']->id, $ids);
        $this->assertNotContains($f['admin']->id, $ids);
        $f['sub']->update(['status' => 'disabled']);
        $this->asPortalUser($employee);
        $ids = array_column($this->getJson('/api/v1/notifications/options')->assertOk()->json('data.users'), 'id');
        $this->assertNotContains($f['posUser']->id, $ids);
        $this->postJson('/api/v1/notifications', ['title' => 'تنبيه', 'body' => 'نص', 'mode' => 'custom', 'user_ids' => [$f['foreignUser']->id], 'idempotency_key' => 'outside-root'])->assertNotFound();
    }

    public function test_workflow_notices_are_generated_once_from_real_audit_and_filter_employee_entity_scope(): void
    {
        $f = $this->supportFixture();
        $assigned = $this->supportEmployee($f['system'], $f['main'], ['account.view', 'notifications.view', 'import.view', 'import.approve']);
        $other = $this->supportEmployee($f['system'], $f['foreign'], ['account.view', 'notifications.view', 'import.view', 'import.approve']);
        $order = StockOrder::factory()->create(['account_id' => $f['main']->id, 'creator_id' => $f['agent']->id]);
        $request = Request::create('/api/v1/stock/orders', 'POST');
        $request->attributes->set('portal', 'agents');
        DB::transaction(function () use ($f, $order, $request): void {
            app(AuditLogger::class)->record('import.submit', $request, $f['agent'], $f['main']->id, ['order_id' => $order->id]);
            app(ActivityNotifications::class)->publish((int) DB::table('audit_logs')->max('id'));
        });
        $auditId = (int) DB::table('audit_logs')->max('id');
        app(ActivityNotifications::class)->publish($auditId);
        $this->assertDatabaseCount('notices', 1);
        $id = Notice::sole()->id;
        $this->assertDatabaseHas('notice_recipients', ['notice_id' => $id, 'user_id' => $assigned->id]);
        $this->assertDatabaseMissing('notice_recipients', ['notice_id' => $id, 'user_id' => $other->id]);
        $this->asPortalUser($assigned);
        $this->getJson('/api/v1/notifications')->assertOk()->assertJsonPath('data.0.can_open', true)->assertJsonPath('data.0.entity_id', $order->id);
        $this->getJson('/api/v1/notifications/summary')->assertOk()->assertJsonPath('data.sidebar_counts.import', 1);
        $this->asPortalUser($other);
        $this->getJson('/api/v1/notifications')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_activity_notice_rolls_back_with_failed_operation_and_unknown_audit_does_not_create_fake_notice(): void
    {
        $f = $this->supportFixture();
        $request = Request::create('/api/v1/support', 'POST');
        $request->attributes->set('portal', 'agents');
        try {
            DB::transaction(function () use ($f, $request): void {
                $order = StockOrder::factory()->create(['account_id' => $f['main']->id, 'creator_id' => $f['agent']->id]);
                app(AuditLogger::class)->record('import.submit', $request, $f['agent'], $f['main']->id, ['order_id' => $order->id]);
                app(ActivityNotifications::class)->publish((int) DB::table('audit_logs')->max('id'));
                $this->assertDatabaseCount('notices', 1);
                throw new \RuntimeException('Rollback');
            });
        } catch (\RuntimeException $error) {
            $this->assertSame('Rollback', $error->getMessage());
        }
        $this->assertDatabaseCount('notices', 0);
        $this->assertDatabaseCount('stock_orders', 0);
        app(AuditLogger::class)->record('support.reply', $request, $f['agent'], $f['main']->id, ['ticket_id' => 9999]);
        app(ActivityNotifications::class)->publish((int) DB::table('audit_logs')->max('id'));
        $this->assertDatabaseCount('notices', 0);
    }

    public function test_live_pending_sidebar_counts_obey_scope_permission_and_deduplicate_notification_entities(): void
    {
        $f = $this->supportFixture();
        $request = FundingRequest::factory()->create(['from_account_id' => $f['main']->id, 'to_account_id' => $f['sub']->id, 'creator_id' => $f['subUser']->id]);
        Notice::factory()->toUser($f['agent'])->count(2)->create(['sender_id' => $f['admin']->id, 'page' => 'wallets', 'entity_id' => $request->id, 'entity_type' => 'funding_request']);
        $this->asPortalUser($f['agent']);
        $this->getJson('/api/v1/notifications/summary')->assertOk()->assertJsonPath('data.sidebar_counts.wallets', 1)->assertJsonPath('data.unread', 2);
        $this->supportGrant($f['agent'], 'wallets.view', false);
        $this->asPortalUser($f['agent']);
        $this->getJson('/api/v1/notifications/summary')->assertOk()->assertJsonMissingPath('data.sidebar_counts.wallets');
        $this->asPortalUser($f['foreignUser']);
        $this->getJson('/api/v1/notifications/summary')->assertOk()->assertJsonMissingPath('data.sidebar_counts.wallets');
    }
}

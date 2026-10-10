<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Models\Support\SupportAttachment;
use App\Models\Support\SupportTicket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CreatesSupport;
use Tests\TestCase;

class SupportWorkflowTest extends TestCase
{
    use CreatesSupport,RefreshDatabase;

    public function test_guests_and_foreign_accounts_cannot_access_threads_or_messages(): void
    {
        $this->withHeader('X-Masal-Portal', 'admin')->getJson('/api/v1/support/tickets')->assertUnauthorized();
        $f = $this->supportFixture();
        $id = $this->openSupport($f['posUser'], $f['branch']);
        $this->asPortalUser($f['foreignUser']);
        $this->getJson('/api/v1/support/tickets/'.$id)->assertNotFound();
        $this->postJson('/api/v1/support/tickets/'.$id.'/replies', ['body' => 'غير مخول', 'version' => 1, 'idempotency_key' => 'foreign-reply'])->assertNotFound();
        $this->getJson('/api/v1/support/tickets')->assertOk()->assertJsonCount(0, 'data');
        $this->asPortalUser($f['admin']);
        $this->getJson('/api/v1/support/tickets/'.$id)->assertNotFound();
    }

    public function test_contacts_are_direct_parent_and_children_and_pos_may_belong_to_any_agent_level(): void
    {
        $f = $this->supportFixture();
        $directPos = $this->account(AccountType::Pos, $f['main']);
        $this->asPortalUser($f['agent']);
        $r = $this->getJson('/api/v1/support/options')->assertOk()->json('data.recipients');
        $this->assertEqualsCanonicalizing([$f['system']->id, $f['sub']->id, $directPos->id], array_column($r, 'id'));
        $this->postJson('/api/v1/support/tickets', ['recipient_id' => $f['pos']->id, 'title' => 'غير مباشر', 'description' => 'غير مباشر', 'idempotency_key' => 'unreachable'])->assertNotFound();
        $this->openSupport($f['agent'], $directPos, 'downward-ticket');
        $this->asPortalUser($this->userFor($directPos));
        $this->getJson('/api/v1/support/tickets')->assertOk()->assertJsonPath('data.0.can_reply', true);
        $f['sub']->update(['status' => 'disabled']);
        $this->asPortalUser($f['agent']);
        $this->getJson('/api/v1/support/options')->assertOk()->assertJsonCount(2, 'data.recipients');
    }

    public function test_escalation_uses_active_pair_and_private_replies_return_one_level_without_leaking_to_origin(): void
    {
        $f = $this->supportFixture();
        $id = $this->openSupport($f['posUser'], $f['branch']);
        $this->asPortalUser($f['branchUser']);
        $this->postJson('/api/v1/support/tickets/'.$id.'/status', ['action' => 'escalate', 'version' => 1, 'idempotency_key' => 'escalate-one'])->assertOk()->assertJsonPath('data.recipient_id', $f['sub']->id);
        $this->asPortalUser($f['posUser']);
        $this->postJson('/api/v1/support/tickets/'.$id.'/replies', ['body' => 'لا يسمح', 'version' => 2, 'idempotency_key' => 'inactive-pair'])->assertForbidden();
        $this->asPortalUser($f['subUser']);
        $this->postJson('/api/v1/support/tickets/'.$id.'/status', ['action' => 'close', 'version' => 2, 'idempotency_key' => 'close-early'])->assertForbidden();
        $this->postJson('/api/v1/support/tickets/'.$id.'/replies', ['body' => 'رد خاص من المسؤول', 'version' => 2, 'idempotency_key' => 'private-reply'])->assertOk()->assertJsonPath('data.status', 'replied')->assertJsonPath('data.recipient_id', $f['branch']->id);
        $this->asPortalUser($f['posUser']);
        $this->getJson('/api/v1/support/tickets/'.$id)->assertOk()->assertJsonCount(1, 'data.messages')->assertJsonMissing(['body' => 'رد خاص من المسؤول']);
        $this->asPortalUser($f['branchUser']);
        $this->getJson('/api/v1/support/tickets/'.$id)->assertOk()->assertJsonCount(2, 'data.messages');
        $this->postJson('/api/v1/support/tickets/'.$id.'/replies', ['body' => 'النتيجة لنقطة البيع', 'version' => 3, 'idempotency_key' => 'public-answer'])->assertOk();
        $this->postJson('/api/v1/support/tickets/'.$id.'/status', ['action' => 'close', 'version' => 4, 'idempotency_key' => 'close-final'])->assertOk()->assertJsonPath('data.status', 'closed');
        $this->asPortalUser($f['posUser']);
        $this->getJson('/api/v1/support/tickets/'.$id)->assertOk()->assertJsonCount(2, 'data.messages')->assertJsonPath('data.can_reply', false);
        $this->assertDatabaseCount('support_history', 3);
    }

    public function test_compare_and_swap_and_idempotency_do_not_repeat_reply_or_notification(): void
    {
        $f = $this->supportFixture();
        $id = $this->openSupport($f['agent'], $f['system']);
        $this->asPortalUser($f['admin']);
        $payload = ['body' => 'تمت المراجعة', 'version' => 1, 'idempotency_key' => 'same-reply'];
        $this->postJson('/api/v1/support/tickets/'.$id.'/replies', $payload)->assertOk()->assertJsonPath('data.version', 2);
        $notices = DB::table('notices')->count();
        $this->postJson('/api/v1/support/tickets/'.$id.'/replies', $payload)->assertOk();
        $this->assertDatabaseCount('support_messages', 2);
        $this->assertDatabaseCount('notices', $notices);
        $this->postJson('/api/v1/support/tickets/'.$id.'/replies', array_replace($payload, ['body' => 'تغيير']))->assertConflict();
        $this->postJson('/api/v1/support/tickets/'.$id.'/replies', array_replace($payload, ['idempotency_key' => 'stale-key']))->assertConflict();
        $this->postJson('/api/v1/support/tickets/'.$id.'/read', ['through_message_id' => 99999])->assertUnprocessable();
    }

    public function test_unread_is_per_user_and_hidden_messages_do_not_change_origin_last_visible_time(): void
    {
        $f = $this->supportFixture();
        $id = $this->openSupport($f['agent'], $f['system']);
        $employee = $this->supportEmployee($f['system'], $f['main'], ['account.view', 'support.view', 'support.reply', 'notifications.view']);
        $this->asPortalUser($f['admin']);
        $this->getJson('/api/v1/support/tickets?filter=unread')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('meta.unread_total', 1);
        $this->postJson('/api/v1/support/tickets/'.$id.'/read')->assertOk();
        $this->getJson('/api/v1/support/tickets?filter=unread')->assertOk()->assertJsonPath('meta.total', 0);
        $this->asPortalUser($employee);
        $this->getJson('/api/v1/support/tickets?filter=unread')->assertOk()->assertJsonPath('meta.total', 1);
        $this->postJson('/api/v1/support/tickets/'.$id.'/read')->assertOk();
        $this->asPortalUser($f['agent']);
        $this->getJson('/api/v1/support/tickets?filter=unread')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/support/tickets?query=%25')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/support/tickets?per_page=101')->assertUnprocessable();
    }

    public function test_system_employee_scope_does_not_expose_other_network_threads_or_contacts(): void
    {
        $f = $this->supportFixture();
        $mine = $this->openSupport($f['agent'], $f['system'], 'mine');
        $foreign = $this->openSupport($f['foreignUser'], $f['system'], 'other');
        $employee = $this->supportEmployee($f['system'], $f['main'], ['account.view', 'support.view', 'support.create', 'support.reply']);
        $this->asPortalUser($employee);
        $this->getJson('/api/v1/support/tickets')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $mine);
        $this->getJson('/api/v1/support/tickets/'.$foreign)->assertNotFound();
        $this->getJson('/api/v1/support/options')->assertOk()->assertJsonCount(1, 'data.recipients')->assertJsonPath('data.recipients.0.id', $f['main']->id);
        $this->postJson('/api/v1/support/tickets', ['recipient_id' => $f['foreign']->id, 'title' => 'عنوان', 'description' => 'نص', 'idempotency_key' => 'employee-foreign'])->assertNotFound();
    }

    public function test_support_notices_never_leak_foreign_thread_titles_to_employee_of_shared_recipient_account(): void
    {
        $f = $this->supportFixture();
        $employee = $this->supportEmployee($f['system'], $f['main'], ['account.view', 'support.view', 'notifications.view']);
        $this->openSupport($f['foreignUser'], $f['system'], 'private-other-network');
        $this->asPortalUser($employee);
        $this->getJson('/api/v1/notifications')->assertOk()->assertJsonCount(0, 'data');
        $id = $this->openSupport($f['agent'], $f['system'], 'assigned-network-thread');
        $this->asPortalUser($employee);
        $this->getJson('/api/v1/notifications')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.entity_id', $id);
    }

    public function test_action_permissions_and_parent_denials_are_enforced_even_for_existing_thread_participants(): void
    {
        $f = $this->supportFixture();
        $id = $this->openSupport($f['posUser'], $f['branch'], 'restricted-thread');
        $permission = DB::table('permissions')->where('name', 'support.reply')->value('id');
        DB::table('account_permission_rules')->insert(['target_account_id' => $f['branch']->id, 'authority_account_id' => $f['main']->id, 'permission_id' => $permission, 'allowed' => false]);
        $this->asPortalUser($f['branchUser']);
        $this->getJson('/api/v1/support/tickets/'.$id)->assertOk()->assertJsonPath('data.can_reply', false);
        $this->postJson('/api/v1/support/tickets/'.$id.'/replies', ['version' => 1, 'body' => 'لا يسمح', 'idempotency_key' => 'denied-parent-reply'])->assertForbidden();
        $this->supportGrant($f['posUser'], 'support.view', false);
        $this->asPortalUser($f['posUser']);
        $this->getJson('/api/v1/support/tickets/'.$id)->assertForbidden();
        $this->postJson('/api/v1/support/tickets/'.$id.'/read')->assertForbidden();
    }

    public function test_broadcasts_are_private_per_user_and_cannot_escalate(): void
    {
        $f = $this->supportFixture();
        $this->supportGrant($f['agent'], 'support.broadcast');
        $second = $this->userFor($f['sub']);
        $this->asPortalUser($f['agent']);
        $payload = ['title' => 'عام', 'description' => 'للمستلم فقط', 'mode' => 'custom', 'user_ids' => [$f['subUser']->id], 'idempotency_key' => 'broadcast-key'];
        $this->postJson('/api/v1/support/broadcasts', $payload)->assertCreated()->assertJsonPath('data.count', 1);
        $id = SupportTicket::sole()->id;
        $this->postJson('/api/v1/support/broadcasts', $payload)->assertCreated();
        $this->assertDatabaseCount('support_tickets', 1);
        $this->asPortalUser($second);
        $this->getJson('/api/v1/support/tickets/'.$id)->assertNotFound();
        $this->asPortalUser($f['subUser']);
        $this->getJson('/api/v1/support/tickets/'.$id)->assertOk();
        $this->postJson('/api/v1/support/tickets/'.$id.'/status', ['action' => 'escalate', 'version' => 1, 'idempotency_key' => 'no-broadcast-escalation'])->assertForbidden();
        $this->asPortalUser($f['admin']);
        $this->getJson('/api/v1/support/tickets/'.$id)->assertOk();
        $this->asPortalUser($f['agent']);
        $this->postJson('/api/v1/support/broadcasts', array_replace($payload, ['user_ids' => [$f['foreignUser']->id], 'idempotency_key' => 'foreign-broadcast']))->assertNotFound();
    }

    public function test_only_owner_edits_support_phones_with_version_and_strict_iraqi_number_validation(): void
    {
        $f = $this->supportFixture();
        $this->asPortalUser($f['agent']);
        $url = '/api/v1/support/phones/'.$f['main']->id;
        $payload = ['phones' => [['label' => 'الدعم', 'number' => '07712345678']], 'version' => 0, 'idempotency_key' => 'phones-save'];
        $this->putJson($url, $payload)->assertOk()->assertJsonPath('data.version', 1);
        $this->putJson($url, $payload)->assertOk();
        $this->putJson($url, array_replace($payload, ['idempotency_key' => 'phones-stale']))->assertConflict();
        $this->putJson($url, ['phones' => [['label' => 'الدعم', 'number' => '07912345678']], 'version' => 1, 'idempotency_key' => 'bad-number'])->assertUnprocessable();
        $this->asPortalUser($f['subUser']);
        $this->getJson($url)->assertOk()->assertJsonPath('data.phones.0.number', '07712345678');
        $this->putJson($url, $payload)->assertForbidden();
        $this->asPortalUser($f['foreignUser']);
        $this->getJson($url)->assertNotFound();
        $this->asPortalUser($f['posUser']);
        $this->putJson('/api/v1/support/phones/'.$f['pos']->id, $payload)->assertForbidden();
    }

    private function png(int $bytes = 0): UploadedFile
    {
        $data = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl6JgAAAABJRU5ErkJggg==');
        if ($bytes > strlen($data)) {
            $data .= str_repeat('x', $bytes - strlen($data));
        }
        $path = tempnam(sys_get_temp_dir(), 'support-image');
        file_put_contents($path, $data);

        return new UploadedFile($path, 'image.png', 'image/png', null, true);
    }

    public function test_expired_unused_images_are_pruned_but_current_or_linked_private_images_remain(): void
    {
        Storage::fake('local');
        $f = $this->supportFixture();
        $expired = SupportAttachment::factory()->create(['user_id' => $f['agent']->id, 'expires_at' => now()->subMinute()]);
        $current = SupportAttachment::factory()->create(['user_id' => $f['agent']->id]);
        $linked = SupportAttachment::factory()->create(['user_id' => $f['agent']->id, 'expires_at' => now()->subMinute()]);
        foreach ([$expired, $current, $linked] as $image) {
            Storage::disk('local')->put($image->path, 'private attachment bytes');
        }
        SupportTicket::factory()->create(['sender_id' => $f['agent']->id, 'origin_id' => $f['main']->id, 'recipient_id' => $f['system']->id, 'attachment_id' => $linked->id]);
        $this->artisan('support:prune-attachments')->assertSuccessful();
        Storage::disk('local')->assertMissing($expired->path);
        Storage::disk('local')->assertExists($current->path);
        Storage::disk('local')->assertExists($linked->path);
        $this->assertDatabaseMissing('support_attachments', ['id' => $expired->id]);
        $this->assertDatabaseHas('support_attachments', ['id' => $linked->id]);
    }

    public function test_attachments_are_private_exact_byte_bounded_and_cannot_be_assigned_twice_or_by_foreign_user(): void
    {
        Storage::fake('local');
        $f = $this->supportFixture();
        $this->asPortalUser($f['agent']);
        $this->post('/api/v1/support/attachments', ['kind' => 'support', 'file' => $this->png(700001)], ['Accept' => 'application/json'])->assertUnprocessable();
        $id = $this->post('/api/v1/support/attachments', ['kind' => 'support', 'file' => $this->png(700000)], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.bytes', 700000)->json('data.id');
        $this->get('/api/v1/support/attachments/'.$id)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->asPortalUser($f['foreignUser']);
        $this->get('/api/v1/support/attachments/'.$id)->assertNotFound();
        $this->postJson('/api/v1/support/tickets', ['recipient_id' => $f['system']->id, 'title' => 'صورة', 'description' => 'صورة', 'attachment_id' => $id, 'idempotency_key' => 'foreign-attachment'])->assertNotFound();
        $this->asPortalUser($f['agent']);
        $payload = ['recipient_id' => $f['system']->id, 'title' => 'صورة', 'description' => 'صورة', 'attachment_id' => $id, 'idempotency_key' => 'attach-thread'];
        $this->postJson('/api/v1/support/tickets', $payload)->assertOk();
        $this->postJson('/api/v1/support/tickets', array_replace($payload, ['idempotency_key' => 'reuse-image']))->assertConflict();
        $this->asPortalUser($f['admin']);
        $this->get('/api/v1/support/attachments/'.$id)->assertOk();
        $this->supportGrant($f['agent'], 'support.attach', false);
        $this->asPortalUser($f['agent']);
        $this->post('/api/v1/support/attachments', ['kind' => 'support', 'file' => $this->png()], ['Accept' => 'application/json'])->assertForbidden();
    }
}

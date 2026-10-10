<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\AccountAttachment;
use App\Services\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CreatesAccounts;
use Tests\TestCase;

class AccountAttachmentTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    private function image(int $bytes = 0): UploadedFile
    {
        $contents = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a3H8AAAAASUVORK5CYII=');

        return UploadedFile::fake()->createWithContent('photo.png', $bytes > strlen($contents) ? str_pad($contents, $bytes, 'x') : $contents);
    }

    private function existingPhoto(Account $account, string $kind = 'document'): AccountAttachment
    {
        $path = 'accounts/'.$account->id.'/attachments/existing.png';
        Storage::disk('local')->put($path, 'private-image-content');

        return AccountAttachment::factory()->for($account)->create([
            'kind' => $kind, 'document_type' => $kind === 'document' ? 'national_card' : null, 'storage_path' => $path,
        ]);
    }

    public function test_upload_saves_private_image_and_audit_without_exposing_storage_path(): void
    {
        Storage::fake('local');
        $root = $this->account(AccountType::System);
        $child = $this->account(AccountType::MainAgent, $root);
        $admin = $this->userFor($root);
        $this->asPortalUser($admin);

        $response = $this->post('/api/v1/accounts/'.$child->id.'/attachments', [
            'version' => 1, 'kind' => 'agent_image', 'file' => $this->image(),
        ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('account_version', 2)
            ->assertJsonPath('data.label', 'صورة الوكيل')->assertJsonMissingPath('data.storage_path')->assertJsonMissingPath('data.uploaded_by');

        $attachment = AccountAttachment::findOrFail($response->json('data.id'));
        Storage::disk('local')->assertExists($attachment->storage_path);
        $this->assertDatabaseHas('accounts', ['id' => $child->id, 'version' => 2]);
        $this->assertDatabaseHas('audit_logs', ['user_id' => $admin->id, 'subject_account_id' => $child->id, 'action' => 'account.attachment.upload']);
        $this->assertSame('/api/v1/accounts/'.$child->id.'/attachments/'.$attachment->id.'/content', $response->json('data.content_url'));
    }

    public function test_pos_can_view_own_photo_but_cannot_manage_its_own_attachments(): void
    {
        Storage::fake('local');
        $root = $this->account(AccountType::System);
        $main = $this->account(AccountType::MainAgent, $root);
        $pos = $this->account(AccountType::Pos, $main);
        $photo = $this->existingPhoto($pos);
        $this->asPortalUser($this->userFor($pos));

        $this->getJson('/api/v1/accounts/'.$pos->id.'/attachments')->assertOk()->assertJsonPath('data.0.id', $photo->id);
        $this->get('/api/v1/accounts/'.$pos->id.'/attachments/'.$photo->id.'/content')->assertOk()
            ->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeaderContains('Cache-Control', 'no-store');
        $this->post('/api/v1/accounts/'.$pos->id.'/attachments', ['version' => 1, 'kind' => 'personal_image', 'file' => $this->image()], ['Accept' => 'application/json'])->assertForbidden();

        $this->assertDatabaseCount('account_attachments', 1);
    }

    public function test_foreign_network_and_mismatched_attachment_ids_return_404(): void
    {
        Storage::fake('local');
        $root = $this->account(AccountType::System);
        $main = $this->account(AccountType::MainAgent, $root);
        $own = $this->account(AccountType::Pos, $main);
        $other = $this->account(AccountType::MainAgent, $root);
        $foreign = $this->account(AccountType::Pos, $other);
        $photo = $this->existingPhoto($foreign);
        $this->asPortalUser($this->userFor($main));

        $this->getJson('/api/v1/accounts/'.$foreign->id.'/attachments')->assertNotFound();
        $this->getJson('/api/v1/accounts/'.$foreign->id.'/attachments/'.$photo->id.'/content')->assertNotFound();
        $this->getJson('/api/v1/accounts/'.$own->id.'/attachments/'.$photo->id.'/content')->assertNotFound();
        $this->post('/api/v1/accounts/'.$foreign->id.'/attachments', ['version' => 1, 'kind' => 'document', 'document_type' => 'national_card', 'file' => $this->image()], ['Accept' => 'application/json'])->assertNotFound();
        $this->deleteJson('/api/v1/accounts/'.$own->id.'/attachments/'.$photo->id, ['version' => 1, 'reason' => 'فحص عزل الملفات'])->assertNotFound();

        $this->assertModelExists($photo);
        Storage::disk('local')->assertExists($photo->storage_path);
    }

    public function test_explicit_denial_applies_to_list_and_existing_content_url(): void
    {
        Storage::fake('local');
        $root = $this->account(AccountType::System);
        $main = $this->account(AccountType::MainAgent, $root);
        $pos = $this->account(AccountType::Pos, $main);
        $photo = $this->existingPhoto($pos);
        $user = $this->userFor($main);
        DB::table('membership_permissions')->insert([
            'membership_id' => $user->membership->id,
            'permission_id' => DB::table('permissions')->where('name', 'account.attachments.view')->value('id'),
            'allowed' => false,
        ]);
        $this->asPortalUser($user);

        $this->getJson('/api/v1/accounts/'.$pos->id.'/attachments')->assertForbidden();
        $this->getJson('/api/v1/accounts/'.$pos->id.'/attachments/'.$photo->id.'/content')->assertForbidden();
    }

    public function test_stale_version_cannot_upload_or_delete_a_file(): void
    {
        Storage::fake('local');
        $root = $this->account(AccountType::System);
        $main = $this->account(AccountType::MainAgent, $root);
        $pos = $this->account(AccountType::Pos, $main);
        $photo = $this->existingPhoto($pos);
        $pos->update(['version' => 3]);
        $this->asPortalUser($this->userFor($root));

        $this->post('/api/v1/accounts/'.$pos->id.'/attachments', ['version' => 1, 'kind' => 'document', 'document_type' => 'national_card', 'file' => $this->image()], ['Accept' => 'application/json'])->assertConflict();
        $this->deleteJson('/api/v1/accounts/'.$pos->id.'/attachments/'.$photo->id, ['version' => 1, 'reason' => 'صورة قديمة'])->assertConflict();

        $this->assertModelExists($photo);
        $this->assertDatabaseHas('accounts', ['id' => $pos->id, 'version' => 3]);
        $this->assertDatabaseCount('audit_logs', 0);
        Storage::disk('local')->assertExists($photo->storage_path);
        $this->assertCount(1, Storage::disk('local')->allFiles());
    }

    public function test_single_personal_photo_replacement_preserves_only_the_new_file(): void
    {
        Storage::fake('local');
        $root = $this->account(AccountType::System);
        $main = $this->account(AccountType::MainAgent, $root);
        $pos = $this->account(AccountType::Pos, $main);
        $old = $this->existingPhoto($pos, 'personal_image');
        $this->asPortalUser($this->userFor($root));

        $response = $this->post('/api/v1/accounts/'.$pos->id.'/attachments', ['version' => 1, 'kind' => 'personal_image', 'file' => $this->image()], ['Accept' => 'application/json'])->assertCreated();

        $this->assertModelMissing($old);
        $this->assertDatabaseCount('account_attachments', 1);
        Storage::disk('local')->assertMissing($old->storage_path);
        Storage::disk('local')->assertExists(AccountAttachment::findOrFail($response->json('data.id'))->storage_path);
    }

    public function test_audit_failure_rolls_back_replacement_and_removes_new_file_only(): void
    {
        Storage::fake('local');
        $root = $this->account(AccountType::System);
        $main = $this->account(AccountType::MainAgent, $root);
        $pos = $this->account(AccountType::Pos, $main);
        $old = $this->existingPhoto($pos, 'personal_image');
        $this->asPortalUser($this->userFor($root));
        $this->mock(AuditLogger::class)->shouldReceive('record')->once()->andThrow(new \RuntimeException('Simulated audit failure'));

        $this->post('/api/v1/accounts/'.$pos->id.'/attachments', ['version' => 1, 'kind' => 'personal_image', 'file' => $this->image()], ['Accept' => 'application/json'])->assertInternalServerError();

        $this->assertModelExists($old);
        $this->assertDatabaseHas('accounts', ['id' => $pos->id, 'version' => 1]);
        $this->assertDatabaseCount('account_attachments', 1);
        $this->assertDatabaseCount('audit_logs', 0);
        Storage::disk('local')->assertExists($old->storage_path);
        $this->assertCount(1, Storage::disk('local')->allFiles());
    }

    public function test_delete_removes_file_and_records_reason_with_new_version(): void
    {
        Storage::fake('local');
        $root = $this->account(AccountType::System);
        $main = $this->account(AccountType::MainAgent, $root);
        $pos = $this->account(AccountType::Pos, $main);
        $photo = $this->existingPhoto($pos);
        $admin = $this->userFor($root);
        $this->asPortalUser($admin);

        $this->deleteJson('/api/v1/accounts/'.$pos->id.'/attachments/'.$photo->id, ['version' => 1, 'reason' => 'استبدال نسخة الوثيقة'])->assertOk()->assertJsonPath('account_version', 2);

        $this->assertModelMissing($photo);
        Storage::disk('local')->assertMissing($photo->storage_path);
        $this->assertDatabaseHas('audit_logs', ['user_id' => $admin->id, 'subject_account_id' => $pos->id, 'action' => 'account.attachment.delete']);
    }

    public static function invalidFileCases(): array
    {
        return ['oversized_image' => ['oversized'], 'disguised_executable' => ['php'], 'forged_path' => ['path'], 'missing_document_type' => ['type'], 'wrong_kind_for_pos' => ['kind']];
    }

    #[DataProvider('invalidFileCases')]
    public function test_invalid_file_or_metadata_returns_422_without_writing(string $case): void
    {
        Storage::fake('local');
        $root = $this->account(AccountType::System);
        $main = $this->account(AccountType::MainAgent, $root);
        $pos = $this->account(AccountType::Pos, $main);
        $this->asPortalUser($this->userFor($root));
        $payload = ['version' => 1, 'kind' => 'document', 'document_type' => 'national_card', 'file' => $this->image()];
        $errorField = match ($case) {
            'path' => 'storage_path', 'type' => 'document_type', 'kind' => 'kind', default => 'file',
        };
        if ($case === 'oversized') {
            $payload['file'] = $this->image(700001);
        } elseif ($case === 'php') {
            $payload['file'] = UploadedFile::fake()->createWithContent('picture.png', '<?php echo "code";');
        } elseif ($case === 'path') {
            $payload['storage_path'] = '../../.env';
        } elseif ($case === 'type') {
            unset($payload['document_type']);
        } else {
            $payload['kind'] = 'agent_image';
            unset($payload['document_type']);
        }

        $this->post('/api/v1/accounts/'.$pos->id.'/attachments', $payload, ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors($errorField);

        $this->assertDatabaseCount('account_attachments', 0);
        $this->assertDatabaseHas('accounts', ['id' => $pos->id, 'version' => 1]);
        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_account_attachment_limit_returns_422_and_preserves_existing_images(): void
    {
        Storage::fake('local');
        $root = $this->account(AccountType::System);
        $main = $this->account(AccountType::MainAgent, $root);
        $pos = $this->account(AccountType::Pos, $main);
        AccountAttachment::factory()->count(50)->for($pos)->create();
        $this->asPortalUser($this->userFor($root));

        $this->post('/api/v1/accounts/'.$pos->id.'/attachments', ['version' => 1, 'kind' => 'document', 'document_type' => 'civil_id', 'file' => $this->image()], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('file');

        $this->assertDatabaseCount('account_attachments', 50);
        $this->assertDatabaseHas('accounts', ['id' => $pos->id, 'version' => 1]);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_missing_authentication_returns_401_for_metadata_content_and_upload(): void
    {
        $this->withHeader('X-Masal-Portal', 'admin');

        $this->getJson('/api/v1/accounts/1/attachments')->assertUnauthorized();
        $this->getJson('/api/v1/accounts/1/attachments/1/content')->assertUnauthorized();
        $this->postJson('/api/v1/accounts/1/attachments', [])->assertUnauthorized();
        $this->deleteJson('/api/v1/accounts/1/attachments/1', [])->assertUnauthorized();

        $this->assertDatabaseCount('account_attachments', 0);
    }
}

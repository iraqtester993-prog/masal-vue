<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Models\NetworkRepresentative;
use App\Models\RepresentativePhoto;
use App\Services\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CreatesAccounts;
use Tests\TestCase;

class RepresentativePhotoTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    private function image(int $size = 100): UploadedFile
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAYAAABytg0kAAAAEUlEQVR4nGPgmLH1PwgzwBgATd4JUcKJ7HcAAAAASUVORK5CYII=');

        return UploadedFile::fake()->createWithContent('photo.png', $png.str_repeat(' ', max(0, $size - strlen($png))));
    }

    public function test_image_upload_accepts_exact_700000_bytes_stores_private_and_increments_version(): void
    {
        Storage::fake('local');
        $this->asPortalUser($this->userFor($this->account(AccountType::System)));
        $rep = NetworkRepresentative::factory()->create();

        $response = $this->post('/api/v1/reference/representatives/'.$rep->id.'/photos', ['version' => 1, 'file' => $this->image(700000)], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.version', 2)->assertJsonPath('data.photos.0.bytes', 700000)->assertJsonMissingPath('data.photos.0.storage_path');

        $photo = RepresentativePhoto::firstOrFail();
        Storage::disk('local')->assertExists($photo->storage_path);
        $this->assertDatabaseHas('audit_logs', ['action' => 'representatives.photo.upload']);
        $this->get($response->json('data.photos.0.url'))->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeaderContains('Cache-Control', 'no-store');
    }

    public function test_image_over_exact_limit_returns_422_without_private_file_or_version_change(): void
    {
        Storage::fake('local');
        $this->asPortalUser($this->userFor($this->account(AccountType::System)));
        $rep = NetworkRepresentative::factory()->create();

        $this->post('/api/v1/reference/representatives/'.$rep->id.'/photos', ['version' => 1, 'file' => $this->image(700001)], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('file')->assertJsonPath('errors.file.0', 'حجم الصورة يتجاوز 700000 بايت.');

        $this->assertDatabaseCount('representative_photos', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame(1, $rep->fresh()->version);
    }

    public function test_disguised_svg_returns_422_without_saving_photo(): void
    {
        Storage::fake('local');
        $this->asPortalUser($this->userFor($this->account(AccountType::System)));
        $rep = NetworkRepresentative::factory()->create();

        $this->post('/api/v1/reference/representatives/'.$rep->id.'/photos', ['version' => 1, 'file' => UploadedFile::fake()->createWithContent('fake.png', '<svg><script>alert(1)</script></svg>')], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('file');

        $this->assertDatabaseCount('representative_photos', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_audit_failure_rolls_back_photo_and_removes_new_private_file(): void
    {
        Storage::fake('local');
        $this->asPortalUser($this->userFor($this->account(AccountType::System)));
        $rep = NetworkRepresentative::factory()->create();
        $this->mock(AuditLogger::class)->shouldReceive('record')->once()->andThrow(new \RuntimeException('Intentional failure'));

        $this->post('/api/v1/reference/representatives/'.$rep->id.'/photos', ['version' => 1, 'file' => $this->image()], ['Accept' => 'application/json'])->assertInternalServerError();

        $this->assertDatabaseCount('representative_photos', 0);
        $this->assertSame(1, $rep->fresh()->version);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_photo_delete_is_versioned_and_removes_storage_after_commit(): void
    {
        Storage::fake('local');
        $actor = $this->userFor($this->account(AccountType::System));
        $this->asPortalUser($actor);
        $rep = NetworkRepresentative::factory()->create();
        $photo = RepresentativePhoto::factory()->create(['representative_id' => $rep->id, 'uploaded_by' => $actor->id]);
        Storage::disk('local')->put($photo->storage_path, 'private fixture');

        $this->deleteJson('/api/v1/reference/representatives/'.$rep->id.'/photos/'.$photo->id, ['version' => 1])->assertOk()->assertJsonPath('data.version', 2)->assertJsonPath('data.photos', []);

        $this->assertDatabaseCount('representative_photos', 0);
        Storage::disk('local')->assertMissing($photo->storage_path);
        $this->assertDatabaseHas('audit_logs', ['action' => 'representatives.photo.delete']);
    }

    public function test_cross_network_photo_read_and_write_return_404(): void
    {
        Storage::fake('local');
        $system = $this->account(AccountType::System);
        $main = $this->account(AccountType::MainAgent, $system);
        $foreign = $this->account(AccountType::MainAgent, $system);
        $actor = $this->userFor($main);
        $rep = NetworkRepresentative::factory()->create(['agent_account_id' => $foreign->id]);
        $photo = RepresentativePhoto::factory()->create(['representative_id' => $rep->id, 'uploaded_by' => $actor->id]);
        Storage::disk('local')->put($photo->storage_path, 'private fixture');
        $this->asPortalUser($actor);

        $this->get('/api/v1/reference/representatives/'.$rep->id.'/photos/'.$photo->id.'/content')->assertNotFound();
        $this->deleteJson('/api/v1/reference/representatives/'.$rep->id.'/photos/'.$photo->id, ['version' => 1])->assertNotFound();
        $this->post('/api/v1/reference/representatives/'.$rep->id.'/photos', ['version' => 1, 'file' => $this->image()], ['Accept' => 'application/json'])->assertNotFound();

        $this->assertModelExists($photo);
        Storage::disk('local')->assertExists($photo->storage_path);
    }

    public function test_photo_upload_permission_and_stale_version_cannot_mutate_private_files(): void
    {
        Storage::fake('local');
        $actor = $this->userFor($this->account(AccountType::System));
        $rep = NetworkRepresentative::factory()->create();
        $this->asPortalUser($actor);
        $this->post('/api/v1/reference/representatives/'.$rep->id.'/photos', ['version' => 99, 'file' => $this->image()], ['Accept' => 'application/json'])->assertConflict();
        DB::table('membership_permissions')->insert(['membership_id' => $actor->membership->id, 'permission_id' => DB::table('permissions')->where('name', 'representatives.images')->value('id'), 'allowed' => false]);
        $this->asPortalUser($actor);

        $this->post('/api/v1/reference/representatives/'.$rep->id.'/photos', ['version' => 1, 'file' => $this->image()], ['Accept' => 'application/json'])->assertForbidden();

        $this->assertDatabaseCount('representative_photos', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_photo_limit_and_unknown_input_are_rejected_without_storage_changes(): void
    {
        Storage::fake('local');
        $actor = $this->userFor($this->account(AccountType::System));
        $this->asPortalUser($actor);
        $rep = NetworkRepresentative::factory()->create();
        RepresentativePhoto::factory()->count(50)->create(['representative_id' => $rep->id, 'uploaded_by' => $actor->id]);

        $this->post('/api/v1/reference/representatives/'.$rep->id.'/photos', ['version' => 1, 'file' => $this->image()], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->deleteJson('/api/v1/reference/representatives/'.$rep->id.'/photos/1', ['version' => 1, 'storage_path' => 'forged'])->assertUnprocessable()->assertJsonValidationErrors('payload');

        $this->assertDatabaseCount('representative_photos', 50);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }
}

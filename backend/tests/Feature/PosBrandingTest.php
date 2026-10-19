<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Models\AccountAttachment;
use App\Models\PosType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CreatesSupport;
use Tests\TestCase;

class PosBrandingTest extends TestCase
{
    use CreatesSupport, RefreshDatabase;

    public function test_identity_keeps_owner_name_separate_from_the_logged_in_employee(): void
    {
        $system = $this->account(AccountType::System);
        $main = $this->account(AccountType::MainAgent, $system);
        $pos = $this->account(AccountType::Pos, $main);
        $owner = $this->userFor($pos);
        $employee = $this->supportEmployee($pos, $pos, ['account.view']);
        $this->asPortalUser($employee);
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.user.name', $employee->name)->assertJsonPath('data.account.owner_name', $owner->name);
        $pos->update(['owner_name' => 'اسم صاحب النقطة المسجل']);
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.account.owner_name', 'اسم صاحب النقطة المسجل')->assertJsonPath('data.user.name', $employee->name);
    }

    public function test_self_photo_and_parent_name_are_available_without_general_attachment_access(): void
    {
        Storage::fake('local');
        $system = $this->account(AccountType::System);
        $main = $this->account(AccountType::MainAgent, $system);
        $main->update(['name' => 'الوكيل المباشر']);
        $pos = $this->account(AccountType::Pos, $main);
        $foreign = $this->account(AccountType::Pos, $main);
        $user = $this->userFor($pos);
        $photo = AccountAttachment::factory()->create(['account_id' => $pos->id, 'kind' => 'personal_image', 'storage_path' => 'private/self.png']);
        AccountAttachment::factory()->create(['account_id' => $foreign->id, 'kind' => 'personal_image', 'storage_path' => 'private/foreign.png']);
        AccountAttachment::factory()->create(['account_id' => $pos->id, 'kind' => 'document', 'storage_path' => 'private/document.png']);
        Storage::disk('local')->put('private/self.png', 'self-photo');
        Storage::disk('local')->put('private/foreign.png', 'foreign-photo');
        Storage::disk('local')->put('private/document.png', 'private-document');
        $this->asPortalUser($user);
        $response = $this->getJson('/api/v1/auth/me?account_id='.$foreign->id)->assertOk()
            ->assertJsonPath('data.account.parent_name', 'الوكيل المباشر')
            ->assertJsonPath('data.account.personal_image_url', '/api/v1/auth/personal-image?v='.$photo->id)
            ->assertJsonPath('data.user.name', $user->name);
        $this->assertStringNotContainsString('storage_path', $response->getContent());
        $image = $this->get('/api/v1/auth/personal-image?account_id='.$foreign->id.'&kind=document')->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertSame(Storage::disk('local')->path('private/self.png'), $image->baseResponse->getFile()->getPathname());
        $photo->delete();
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.account.personal_image_url', null);
        $this->get('/api/v1/auth/personal-image')->assertNotFound();
    }

    public function test_self_photo_requires_authentication_and_never_falls_back_to_documents(): void
    {
        Storage::fake('local');
        $this->withHeader('X-Masal-Portal', 'pos')->get('/api/v1/auth/personal-image')->assertUnauthorized();
        $system = $this->account(AccountType::System);
        $main = $this->account(AccountType::MainAgent, $system);
        $pos = $this->account(AccountType::Pos, $main);
        AccountAttachment::factory()->create(['account_id' => $pos->id, 'kind' => 'document', 'storage_path' => 'private/id.png']);
        Storage::disk('local')->put('private/id.png', 'id-document');
        $this->asPortalUser($this->userFor($pos));
        $this->get('/api/v1/auth/personal-image')->assertNotFound();
    }

    public function test_pos_inherits_its_main_agent_brand_without_exposing_private_attachment_fields(): void
    {
        Storage::fake('local');
        $system = $this->account(AccountType::System);
        $main = $this->account(AccountType::MainAgent, $system);
        $main->update(['name' => 'Agent Brand', 'color' => '#AD3512']);
        $sub = $this->account(AccountType::SubAgent, $main);
        $pos = $this->account(AccountType::Pos, $sub);
        $user = $this->userFor($pos);
        $image = AccountAttachment::factory()->create(['account_id' => $main->id, 'kind' => 'agent_image', 'storage_path' => 'brand/own.png']);
        Storage::disk('local')->put('brand/own.png', 'own-logo');
        $other = $this->account(AccountType::MainAgent, $system);
        $foreign = AccountAttachment::factory()->create(['account_id' => $other->id, 'kind' => 'agent_image', 'storage_path' => 'brand/foreign.png']);
        Storage::disk('local')->put('brand/foreign.png', 'foreign-logo');
        $this->asPortalUser($user);
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.brand.name', 'Agent Brand')
            ->assertJsonPath('data.brand.color', '#AD3512')->assertJsonPath('data.brand.logo_url', '/api/v1/auth/brand-image?v='.$image->id)
            ->assertJsonMissingPath('data.brand.storage_path');
        $this->get('/api/v1/auth/brand-image?account_id='.$other->id.'&v='.$foreign->id)->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame(Storage::disk('local')->path('brand/own.png'), $this->get('/api/v1/auth/brand-image')->baseResponse->getFile()->getPathname());
        $main->update(['color' => 'invalid']);
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.brand.color', '#0D5C63');
    }

    public function test_brand_image_requires_an_active_pos_session_and_an_agent_logo(): void
    {
        $this->withHeader('X-Masal-Portal', 'pos')->get('/api/v1/auth/brand-image')->assertUnauthorized();
        $system = $this->account(AccountType::System);
        $main = $this->account(AccountType::MainAgent, $system);
        $pos = $this->account(AccountType::Pos, $main);
        $this->asPortalUser($this->userFor($main));
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.brand', null);
        $this->get('/api/v1/auth/brand-image')->assertNotFound();
        $this->asPortalUser($this->userFor($pos));
        $this->get('/api/v1/auth/brand-image')->assertNotFound();
        $main->update(['status' => 'disabled']);
        $this->get('/api/v1/auth/brand-image')->assertForbidden();
    }

    public function test_identity_only_returns_the_current_points_assigned_type_name(): void
    {
        $system = $this->account(AccountType::System);
        $main = $this->account(AccountType::MainAgent, $system);
        $pos = $this->account(AccountType::Pos, $main);
        $otherPos = $this->account(AccountType::Pos, $main);
        $ownType = PosType::factory()->create(['name' => 'متجر النقطة الحالية']);
        $otherType = PosType::factory()->create(['name' => 'نوع نقطة أخرى']);
        DB::table('pos_reference_profiles')->insert([
            ['account_id' => $pos->id, 'pos_type_id' => $ownType->id],
            ['account_id' => $otherPos->id, 'pos_type_id' => $otherType->id],
        ]);
        $this->asPortalUser($this->userFor($pos));

        $this->getJson('/api/v1/auth/me?account_id='.$otherPos->id.'&pos_type_id='.$otherType->id)
            ->assertOk()->assertJsonPath('data.account.pos_type_name', $ownType->name)
            ->assertJsonMissing(['pos_type_name' => $otherType->name])
            ->assertJsonMissingPath('data.account.pos_type_id');
        $this->assertDatabaseHas('pos_reference_profiles', ['account_id' => $pos->id, 'pos_type_id' => $ownType->id]);
    }

    public function test_identity_returns_null_for_an_unassigned_pos_type(): void
    {
        $system = $this->account(AccountType::System);
        $main = $this->account(AccountType::MainAgent, $system);
        $pos = $this->account(AccountType::Pos, $main);
        $this->asPortalUser($this->userFor($pos));

        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.account.pos_type_name', null);
        DB::table('pos_reference_profiles')->insert(['account_id' => $pos->id, 'pos_type_id' => null]);
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.account.pos_type_name', null);
    }

    public function test_non_pos_identity_has_no_pos_type_name(): void
    {
        $system = $this->account(AccountType::System);
        $main = $this->account(AccountType::MainAgent, $system);
        $type = PosType::factory()->create();
        DB::table('pos_reference_profiles')->insert(['account_id' => $main->id, 'pos_type_id' => $type->id]);
        $this->asPortalUser($this->userFor($main));

        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.account.pos_type_name', null);
    }
}

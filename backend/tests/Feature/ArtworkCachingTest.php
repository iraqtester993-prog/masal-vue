<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Models\CatalogProduct;
use App\Models\Digital\DigitalConnection;
use App\Models\Digital\DigitalOffer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CreatesAccounts;
use Tests\TestCase;

class ArtworkCachingTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    private function fixture(): array
    {
        Storage::fake('local');
        $system = $this->account(AccountType::System);
        $main = $this->account(AccountType::MainAgent, $system);
        $user = $this->userFor($main);
        $product = CatalogProduct::factory()->create(['image_path' => 'catalog/category.png', 'image_mime' => 'image/png']);
        $product->provider->update(['image_path' => 'catalog/provider.png', 'image_mime' => 'image/png']);
        Storage::disk('local')->put($product->image_path, 'category-image-v1');
        Storage::disk('local')->put($product->provider->image_path, 'provider-image-v1');
        $rule = DB::table('catalog_account_rules')->insertGetId(['target_account_id' => $main->id, 'authority_account_id' => $system->id]);
        DB::table('catalog_rule_products')->insert(['rule_id' => $rule, 'product_id' => $product->id]);
        $this->asPortalUser($user);

        return compact('system', 'main', 'user', 'product', 'rule');
    }

    public static function artworkRoutes(): array
    {
        return [
            'catalog category' => ['/api/v1/catalog/products/%d/image', 'product'],
            'catalog provider' => ['/api/v1/catalog/providers/%d/image', 'provider'],
            'sale category' => ['/api/v1/sales/products/%d/images/product', 'product'],
            'sale provider' => ['/api/v1/sales/products/%d/images/provider', 'product'],
        ];
    }

    #[DataProvider('artworkRoutes')]
    public function test_authorized_artwork_reuses_cached_bytes_after_revalidation(string $template, string $record): void
    {
        $f = $this->fixture();
        $url = sprintf($template, $record === 'provider' ? $f['product']->provider_id : $f['product']->id);
        $first = $this->get($url)->assertOk()->assertHeaderContains('Cache-Control', 'private')
            ->assertHeaderContains('Cache-Control', 'no-cache')->assertHeaderContains('Cache-Control', 'must-revalidate')
            ->assertHeaderMissing('Last-Modified');
        $this->assertNotContains('Cookie', $first->baseResponse->getVary());
        $this->assertContains('Authorization', $first->baseResponse->getVary());
        $this->assertStringNotContainsString('no-store', $first->headers->get('Cache-Control'));
        $etag = $first->headers->get('ETag');
        $this->assertNotEmpty($etag);
        $cached = $this->withHeader('If-None-Match', $etag)->get($url)->assertStatus(304)->assertHeader('ETag', $etag);
        ob_start();
        $cached->baseResponse->sendContent();
        $this->assertSame('', ob_get_clean());
    }

    public function test_changed_bytes_and_date_only_conditions_do_not_reuse_old_artwork(): void
    {
        $f = $this->fixture();
        $url = '/api/v1/catalog/products/'.$f['product']->id.'/image?v=1';
        $etag = $this->get($url)->assertOk()->headers->get('ETag');
        Storage::disk('local')->put($f['product']->image_path, 'category-image-v2');
        $f['product']->update(['version' => 2]);
        $updated = $this->withHeader('If-None-Match', $etag)->get('/api/v1/catalog/products/'.$f['product']->id.'/image?v=2')->assertOk();
        $this->assertNotSame($etag, $updated->headers->get('ETag'));
        $this->getJson('/api/v1/catalog/products')->assertOk()->assertJsonPath('data.0.image_url', '/api/v1/catalog/products/'.$f['product']->id.'/image?v=2');
        $this->withoutHeader('If-None-Match')->withHeader('If-Modified-Since', 'Wed, 01 Jan 2031 00:00:00 GMT')->get($url)->assertOk();
    }

    public function test_rotating_session_cookie_does_not_partition_the_private_artwork_cache(): void
    {
        $f = $this->fixture();
        $url = '/api/v1/catalog/products/'.$f['product']->id.'/image';
        $first = $this->get($url)->assertOk();
        $second = $this->withHeader('If-None-Match', $first->headers->get('ETag'))->get($url)->assertStatus(304);
        $this->assertNotSame($first->getCookie('masal_agents_session', false)->getValue(), $second->getCookie('masal_agents_session', false)->getValue());
        $this->assertNotContains('Cookie', $second->baseResponse->getVary());
        $second->assertHeaderContains('Cache-Control', 'private')->assertHeaderContains('Cache-Control', 'no-cache')->assertHeaderContains('Cache-Control', 'must-revalidate');
    }

    public function test_cached_artwork_does_not_skip_revoked_category_access_or_logout(): void
    {
        $f = $this->fixture();
        $url = '/api/v1/catalog/products/'.$f['product']->id.'/image';
        $etag = $this->get($url)->assertOk()->headers->get('ETag');
        DB::table('catalog_rule_products')->where('rule_id', $f['rule'])->delete();
        $this->withHeader('If-None-Match', $etag)->get($url)->assertNotFound()->assertHeaderContains('Cache-Control', 'no-store')->assertHeaderMissing('ETag');
        Auth::guard('web')->logout();
        Auth::forgetGuards();
        $this->get($url)->assertUnauthorized()->assertHeaderContains('Cache-Control', 'no-store')->assertHeaderMissing('ETag');
    }

    public function test_cached_artwork_is_scoped_to_the_current_account_and_active_session(): void
    {
        $f = $this->fixture();
        $url = '/api/v1/catalog/products/'.$f['product']->id.'/image';
        $etag = $this->get($url)->assertOk()->headers->get('ETag');
        $other = $this->account(AccountType::MainAgent, $f['system']);
        $rule = DB::table('catalog_account_rules')->insertGetId(['target_account_id' => $other->id, 'authority_account_id' => $f['system']->id]);
        DB::table('catalog_rule_products')->insert(['rule_id' => $rule, 'product_id' => $f['product']->id]);
        $otherUser = $this->userFor($other);
        $this->asPortalUser($otherUser);
        $response = $this->withHeader('If-None-Match', $etag)->get($url)->assertOk();
        $this->assertNotSame($etag, $response->headers->get('ETag'));
        $otherUser->increment('session_version');
        $this->withHeader('If-None-Match', $response->headers->get('ETag'))->get($url)->assertUnauthorized()->assertHeaderContains('Cache-Control', 'no-store')->assertHeaderMissing('ETag');
    }

    public function test_topup_artwork_revalidates_without_caching_its_catalog_or_wallet_json(): void
    {
        $f = $this->fixture();
        $connection = DigitalConnection::factory()->create(['account_id' => $f['system']->id, 'provider' => 'topup']);
        $offer = DigitalOffer::factory()->create(['connection_id' => $connection->id, 'product_id' => $f['product']->id]);
        $this->asPortalUser($this->userFor($f['system']));
        $url = '/api/v1/topup/offers/'.$offer->id.'/image?v=1';
        $etag = $this->get($url)->assertOk()->headers->get('ETag');
        $this->assertNotEmpty($etag);
        $cached = $this->withHeader('If-None-Match', $etag)->get($url)->assertStatus(304);
        ob_start();
        $cached->baseResponse->sendContent();
        $this->assertSame('', ob_get_clean());
        $this->getJson('/api/v1/catalog/products')->assertOk()->assertHeaderContains('Cache-Control', 'no-store')->assertHeaderMissing('ETag');
        $this->getJson('/api/v1/auth/me')->assertOk()->assertHeaderContains('Cache-Control', 'no-store')->assertHeaderMissing('ETag');
        $this->getJson('/api/v1/finance/wallets?currency=IQD')->assertOk()->assertHeaderContains('Cache-Control', 'no-store')->assertHeaderMissing('ETag');
    }
}

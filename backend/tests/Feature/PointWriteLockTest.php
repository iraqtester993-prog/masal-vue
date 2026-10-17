<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Models\Digital\DigitalConnection;
use App\Models\Digital\DigitalGrant;
use App\Models\Digital\DigitalOffer;
use App\Models\Operations\DirectStop;
use App\Services\Digital\DigitalAccess;
use App\Services\Operations\MutationGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\CreatesSupport;
use Tests\TestCase;

class PointWriteLockTest extends TestCase
{
    use CreatesSupport, RefreshDatabase;

    public function test_point_write_still_rechecks_session_version_before_any_mutation(): void
    {
        $f = $this->supportFixture();
        $actor = $f['posUser']->fresh();
        DB::table('users')->where('id', $actor->id)->increment('session_version');
        try {
            DB::transaction(fn () => app(MutationGuard::class)->lock($actor, [], 'digital.create', shareAncestors: true));
            $this->fail('An outdated point session cannot pass the shared ancestor guard.');
        } catch (HttpException $failure) {
            $this->assertSame(401, $failure->getStatusCode());
        }
        $this->assertDatabaseCount('digital_orders', 0);
        $this->assertDatabaseCount('finance_entries', 0);
    }

    public function test_point_write_rejects_a_stopped_ancestor_and_a_removed_permission(): void
    {
        $f = $this->supportFixture();
        $this->app->instance('request', Request::create('/api/v1/digital/orders', 'POST'));
        $f['main']->update(['status' => 'disabled']);
        try {
            DB::transaction(fn () => app(MutationGuard::class)->lock($f['posUser'], [], 'digital.create', shareAncestors: true));
            $this->fail('A point cannot bypass its stopped ancestor.');
        } catch (HttpException $failure) {
            $this->assertSame(403, $failure->getStatusCode());
        }
        $f['main']->update(['status' => 'active']);
        $this->supportGrant($f['posUser'], 'digital.create', false);
        try {
            DB::transaction(fn () => app(MutationGuard::class)->lock($f['posUser'], [], 'digital.create', shareAncestors: true));
            $this->fail('A point cannot bypass a removed sale permission.');
        } catch (HttpException $failure) {
            $this->assertSame(403, $failure->getStatusCode());
        }
        $this->assertDatabaseCount('digital_orders', 0);
        $this->assertDatabaseCount('finance_entries', 0);
    }

    public function test_point_write_keeps_current_direct_stop_enforcement(): void
    {
        $f = $this->supportFixture();
        DirectStop::create(['account_id' => $f['main']->id, 'stops' => ['sales' => true], 'version' => 1]);
        try {
            DB::transaction(fn () => app(MutationGuard::class)->lock($f['posUser'], [], 'digital.create', shareAncestors: true));
            $this->fail('The shared ancestor protocol must retain inherited operation stops.');
        } catch (HttpException $failure) {
            $this->assertSame(423, $failure->getStatusCode());
        }
        $this->assertDatabaseCount('digital_orders', 0);
    }

    public function test_sale_offer_loads_only_the_selected_offer_and_point_path_without_changing_access(): void
    {
        $f = $this->supportFixture();
        $connection = DigitalConnection::factory()->create(['provider' => 'rabiaa', 'account_id' => $f['main']->id]);
        $selected = DigitalOffer::factory()->create(['connection_id' => $connection->id]);
        $otherOffer = DigitalOffer::factory()->create(['connection_id' => $connection->id, 'remote_id' => '72', 'remote_key' => hash('sha256', '72:')]);
        $rule = DB::table('catalog_account_rules')->insertGetId(['target_account_id' => $f['main']->id, 'authority_account_id' => $f['system']->id]);
        DB::table('catalog_rule_products')->insert(['rule_id' => $rule, 'product_id' => $selected->product_id]);
        foreach ([['main', 'sub'], ['sub', 'branch'], ['branch', 'pos']] as [$from, $to]) {
            DigitalGrant::factory()->create(['connection_id' => $connection->id, 'from_account_id' => $f[$from]->id, 'target_account_id' => $f[$to]->id, 'offer_ids' => [$selected->id]]);
        }
        for ($i = 0; $i < 20; $i++) {
            $other = $this->account(AccountType::Pos, $f['main']);
            DigitalGrant::factory()->create(['connection_id' => $connection->id, 'from_account_id' => $f['main']->id, 'target_account_id' => $other->id, 'offer_ids' => [$otherOffer->id]]);
        }
        $access = app(DigitalAccess::class);
        $expected = $access->offers($access->connection($f['posUser'], $connection->id), $f['pos'])->pluck('id')->all();
        $this->assertSame([$selected->id], $expected);
        [$loaded, $offer] = $access->saleOffer($f['posUser'], $f['pos'], $connection->id, $selected->id);
        $this->assertSame($selected->id, $offer?->id);
        $this->assertSame($selected->cost_minor, $offer->cost_minor);
        $this->assertCount(1, $loaded->offers);
        $this->assertEqualsCanonicalizing([$f['sub']->id, $f['branch']->id, $f['pos']->id], $loaded->grants->pluck('target_account_id')->all());
        $this->assertTrue($loaded->grants->every(fn ($grant): bool => ! $grant->relationLoaded('target')));
        [, $ungranted] = $access->saleOffer($f['posUser'], $f['pos'], $connection->id, $otherOffer->id);
        $this->assertNull($ungranted);
        DigitalGrant::where('connection_id', $connection->id)->where('target_account_id', $f['pos']->id)->update(['active' => false]);
        [, $revoked] = $access->saleOffer($f['posUser'], $f['pos'], $connection->id, $selected->id);
        $this->assertNull($revoked);

        $this->assertDatabaseCount('digital_orders', 0);
        $this->assertDatabaseCount('finance_entries', 0);
    }
}

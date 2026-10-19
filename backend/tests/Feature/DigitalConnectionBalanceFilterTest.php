<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Models\Digital\DigitalConnection;
use App\Models\Digital\TopupGrant;
use App\Services\Digital\TopupWallets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\CreatesSupport;
use Tests\TestCase;

class DigitalConnectionBalanceFilterTest extends TestCase
{
    use CreatesSupport, RefreshDatabase;

    public function test_shared_provider_connection_returns_each_filtered_agents_available_wallet_without_changing_money(): void
    {
        $f = $this->fixture();
        $this->asPortalUser($f['admin']);
        $wallets = DB::table('finance_wallets')->orderBy('id')->get()->toJson();
        $entries = DB::table('finance_entries')->orderBy('id')->get()->toJson();
        $transactions = DB::table('finance_transactions')->orderBy('id')->get()->toJson();

        foreach ([[$f['main']->id, '12500.67'], [$f['foreign']->id, '25000.01'], [$f['main']->id, '12500.67']] as [$id, $amount]) {
            $this->getJson('/api/v1/digital/connections?provider=topup&main_account_id='.$id.'&per_page=1')->assertOk()
                ->assertJsonPath('balance.scope', 'account_available')->assertJsonPath('balance.account_id', $id)
                ->assertJsonPath('balance.amount', $amount)->assertJsonPath('balance.known', 1)
                ->assertJsonPath('balance.updated_at', null)->assertJsonPath('data.0.company_balance', '94000.00');
        }
        $this->getJson('/api/v1/digital/connections?provider=topup')->assertOk()->assertJsonPath('balance.amount', '94000.00');
        $this->assertSame($wallets, DB::table('finance_wallets')->orderBy('id')->get()->toJson());
        $this->assertSame($entries, DB::table('finance_entries')->orderBy('id')->get()->toJson());
        $this->assertSame($transactions, DB::table('finance_transactions')->orderBy('id')->get()->toJson());
        Http::assertNothingSent();
    }

    public function test_filtered_balance_deducts_holds_and_preserves_exact_zero_and_unknown_wallets(): void
    {
        $f = $this->fixture();
        DB::transaction(fn () => app(TopupWallets::class)->hold($f['wallets']['main'], 10025));
        $this->asPortalUser($f['admin']);
        $this->getJson('/api/v1/digital/connections?provider=topup&main_account_id='.$f['main']->id)->assertOk()->assertJsonPath('balance.amount', '12400.42');

        $zero = $this->account(AccountType::MainAgent, $f['system']);
        $zeroGrant = $this->grant($f, $zero->id, 0);
        $this->getJson('/api/v1/digital/connections?provider=topup&main_account_id='.$zero->id)->assertOk()
            ->assertJsonPath('balance.amount', null)->assertJsonPath('balance.known', 0);
        DB::transaction(fn () => app(TopupWallets::class)->bootstrap($zeroGrant, $f['admin']));
        $this->getJson('/api/v1/digital/connections?provider=topup&main_account_id='.$zero->id)->assertOk()
            ->assertJsonPath('balance.amount', '0.00')->assertJsonPath('balance.known', 1);
        Http::assertNothingSent();
    }

    public function test_agent_filter_respects_visibility_provider_and_legacy_connection_balances(): void
    {
        $f = $this->fixture();
        $this->asPortalUser($f['agent']);
        $this->getJson('/api/v1/digital/connections?provider=topup&main_account_id='.$f['foreign']->id)->assertNotFound();
        $this->asPortalUser($f['admin']);
        $this->getJson('/api/v1/digital/connections?provider=rabiaa&main_account_id='.$f['main']->id)->assertOk()
            ->assertJsonPath('balance.total', 0)->assertJsonPath('balance.amount', null);
        $this->getJson('/api/v1/digital/connections?main_account_id='.$f['pos']->id)->assertNotFound();

        $legacy = $this->account(AccountType::MainAgent, $f['system']);
        DigitalConnection::factory()->create(['account_id' => $legacy->id, 'provider' => 'topup', 'company_balance_minor' => 1234501]);
        $this->getJson('/api/v1/digital/connections?provider=topup&main_account_id='.$legacy->id)->assertOk()
            ->assertJsonPath('balance.amount', '12345.01')->assertJsonMissingPath('balance.scope');
        Http::assertNothingSent();
    }

    private function fixture(): array
    {
        Http::preventStrayRequests();
        $f = $this->supportFixture();
        $f['connection'] = DigitalConnection::factory()->create(['account_id' => $f['system']->id, 'provider' => 'topup', 'company_balance_minor' => 9400000, 'balance_updated_at' => now()]);
        $wallets = [];
        foreach (['main' => 1250067, 'foreign' => 2500001] as $key => $amount) {
            $grant = $this->grant($f, $f[$key]->id, $amount);
            $wallets[$key] = DB::transaction(fn () => app(TopupWallets::class)->bootstrap($grant, $f['admin']));
        }

        return $f + compact('wallets');
    }

    private function grant(array $f, int $accountId, int $amount): TopupGrant
    {
        return TopupGrant::factory()->create(['main_account_id' => $accountId, 'target_account_id' => $accountId, 'from_account_id' => $f['system']->id, 'connection_id' => $f['connection']->id, 'balance_minor' => $amount]);
    }
}

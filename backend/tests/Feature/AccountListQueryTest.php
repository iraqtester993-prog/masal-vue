<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesAccounts;
use Tests\TestCase;

class AccountListQueryTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    public function test_larger_account_page_uses_bounded_queries_and_keeps_counts_in_own_network(): void
    {
        $root = $this->account(AccountType::System);
        $main = $this->account(AccountType::MainAgent, $root);
        for ($index = 0; $index < 25; $index++) {
            $sub = $this->account(AccountType::SubAgent, $main);
            $this->account(AccountType::Pos, $sub);
        }
        $foreign = $this->account(AccountType::MainAgent, $root);
        $this->account(AccountType::Pos, $foreign);
        $actor = $this->userFor($main);

        $smallPageQueries = $this->pageQueries($actor, 1);
        $largePageQueries = $this->pageQueries($actor, 25);

        $this->assertLessThanOrEqual($smallPageQueries + 8, $largePageQueries);
        $this->asPortalUser($actor);
        $this->getJson('/api/v1/accounts?kind=agents&per_page=25')->assertOk()
            ->assertJsonCount(25, 'data')->assertJsonPath('data.0.id', $main->id)
            ->assertJsonPath('data.0.parent', null)->assertJsonPath('data.0.children_count', 25)
            ->assertJsonPath('data.0.network_pos_count', 25)->assertJsonPath('data.1.parent.id', $main->id)
            ->assertJsonPath('data.1.children_count', 1)->assertJsonPath('data.1.network_pos_count', 1)
            ->assertJsonPath('meta.summary.main_agent', 1)->assertJsonPath('meta.summary.pos', 25);
    }

    private function pageQueries(User $actor, int $perPage): int
    {
        $this->asPortalUser($actor);
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $this->getJson('/api/v1/accounts?kind=agents&per_page='.$perPage)->assertOk()->assertJsonCount($perPage, 'data');

            return count(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    }
}

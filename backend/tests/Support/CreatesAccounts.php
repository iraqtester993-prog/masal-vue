<?php

namespace Tests\Support;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\User;
use App\Services\AccountHierarchy;
use Database\Seeders\FoundationPermissionsSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

trait CreatesAccounts
{
    protected function account(AccountType $type, ?Account $parent = null): Account
    {
        return app(AccountHierarchy::class)->create('Account '.fake()->unique()->numerify('######'), $type, $parent);
    }

    protected function userFor(Account $account): User
    {
        $this->seed(FoundationPermissionsSeeder::class);
        $user = User::factory()->create();
        $role = $account->type->portal() === 'agents' ? 'agent' : $account->type->portal();
        AccountMembership::create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'role_id' => DB::table('roles')->where('name', $role)->value('id'),
            'status' => 'active',
        ]);

        return $user;
    }

    protected function asPortalUser(User $user): void
    {
        Auth::forgetGuards();
        $user = $user->fresh();
        $portal = $user->membership->account->type->portal();
        $this->actingAs($user, 'web')->withSession(['masal.portal' => $portal, 'masal.session_version' => $user->session_version])
            ->withHeader('X-Masal-Portal', $portal);
    }
}

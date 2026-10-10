<?php

namespace App\Services;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\CatalogProduct;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class CatalogAccess
{
    public function __construct(private AccountScope $scope, private ManagementAuthority $authority) {}

    public function require(User $actor, string $permission, bool $global = false): void
    {
        $this->authority->require($actor, $permission);
        $this->authority->require($actor, 'account.view');
        $module = explode('.', $permission)[0];
        if (in_array($module, ['products', 'providers'], true) && $permission !== $module.'.view') {
            $this->authority->require($actor, $module.'.view');
        }
        if ($global) {
            abort_unless($actor->membership->account->type === AccountType::System && $this->scope->query($actor)->whereKey($actor->membership->account_id)->exists(), 403);
        }
    }

    public function products(User $actor): Builder
    {
        $membership = $actor->membership;
        if ($membership->kind !== 'employee') {
            return $this->forAccount($membership->account_id);
        }
        $accounts = $this->scope->query($actor)->select('accounts.id');

        return CatalogProduct::query()->whereExists(function ($visible) use ($accounts): void {
            $visible->selectRaw('1')->from('accounts as catalog_visible_accounts')->whereIn('catalog_visible_accounts.id', $accounts);
            $visible->where(function ($initialized): void {
                $initialized->where('catalog_visible_accounts.type', 'system')->orWhereExists(function ($main): void {
                    $main->selectRaw('1')->from('account_closure as catalog_main_path')->join('accounts as catalog_main_accounts', 'catalog_main_accounts.id', '=', 'catalog_main_path.ancestor_id')
                        ->whereColumn('catalog_main_path.descendant_id', 'catalog_visible_accounts.id')->where('catalog_main_accounts.type', 'main_agent')
                        ->whereExists(function ($rule): void {
                            $rule->selectRaw('1')->from('catalog_account_rules as catalog_main_rules')->whereColumn('catalog_main_rules.target_account_id', 'catalog_main_accounts.id');
                        });
                });
            });
            $visible->whereNotExists(function ($rules): void {
                $rules->selectRaw('1')->from('catalog_account_rules as catalog_blocking_rules')->join('account_closure as catalog_rule_path', 'catalog_rule_path.ancestor_id', '=', 'catalog_blocking_rules.target_account_id')
                    ->whereColumn('catalog_rule_path.descendant_id', 'catalog_visible_accounts.id')
                    ->whereNotExists(function ($assigned): void {
                        $assigned->selectRaw('1')->from('catalog_rule_products as catalog_allowed_products')->whereColumn('catalog_allowed_products.rule_id', 'catalog_blocking_rules.id')->whereColumn('catalog_allowed_products.product_id', 'catalog_products.id');
                    });
            });
        });
    }

    public function forAccount(int $accountId, array $excludedRules = [], bool $ignoreMainDefault = false): Builder
    {
        $ancestors = DB::table('account_closure')->where('descendant_id', $accountId)->pluck('ancestor_id');
        $mainId = Account::whereIn('id', $ancestors)->where('type', AccountType::MainAgent)->value('id');
        $query = CatalogProduct::query();
        if ($mainId && ! $ignoreMainDefault && ! DB::table('catalog_account_rules')->where('target_account_id', $mainId)->exists()) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereNotExists(function ($rules) use ($ancestors, $excludedRules): void {
            $rules->selectRaw('1')->from('catalog_account_rules')->whereIn('target_account_id', $ancestors)->whereNotIn('catalog_account_rules.id', $excludedRules)
                ->whereNotExists(function ($assigned): void {
                    $assigned->selectRaw('1')->from('catalog_rule_products')->whereColumn('rule_id', 'catalog_account_rules.id')->whereColumn('product_id', 'catalog_products.id');
                });
        });
    }

    public function replaceableRules(User $actor, int $targetId): array
    {
        $lower = DB::table('account_closure')->where('ancestor_id', $actor->membership->account_id)->pluck('descendant_id');

        return DB::table('catalog_account_rules')->where('target_account_id', $targetId)->whereIn('authority_account_id', $lower)->pluck('id')->all();
    }

    public function options(User $actor, Account $target): Builder
    {
        $isSystem = $actor->membership->account->type === AccountType::System;
        $candidate = $this->forAccount($target->id, $this->replaceableRules($actor, $target->id), $isSystem && $target->type === AccountType::MainAgent);

        return $isSystem ? $candidate : $candidate->whereIn('catalog_products.id', $this->forAccount($actor->membership->account_id)->select('catalog_products.id'));
    }
}

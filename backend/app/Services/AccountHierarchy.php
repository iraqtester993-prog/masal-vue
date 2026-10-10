<?php

namespace App\Services;

use App\Enums\AccountType;
use App\Models\Account;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AccountHierarchy
{
    public function create(string $name, AccountType $type, ?Account $parent = null): Account
    {
        return DB::transaction(function () use ($name, $type, $parent): Account {
            if ($type === AccountType::System) {
                if ($parent || Account::where('type', $type->value)->exists()) {
                    throw ValidationException::withMessages(['parent_id' => 'Only one root system account is permitted.']);
                }
            } else {
                $parent = $parent ? Account::query()->lockForUpdate()->find($parent->id) : null;
                if (! $parent || ! $parent->isOperational() || ! $parent->type->allowsChild($type)) {
                    throw ValidationException::withMessages(['parent_id' => 'Invalid or disabled parent account.']);
                }
            }

            // Creation only: parent must exist before child, so cycles cannot be created.
            // Reparenting remains unavailable until historic ownership rules are approved.
            $account = Account::create(['name' => $name, 'type' => $type, 'parent_id' => $parent?->id, 'status' => 'active']);
            $rows = [['ancestor_id' => $account->id, 'descendant_id' => $account->id, 'depth' => 0]];
            if ($parent) {
                foreach (DB::table('account_closure')->where('descendant_id', $parent->id)->get() as $ancestor) {
                    $rows[] = ['ancestor_id' => $ancestor->ancestor_id, 'descendant_id' => $account->id, 'depth' => $ancestor->depth + 1];
                }
            }
            DB::table('account_closure')->insert($rows);

            return $account;
        });
    }
}

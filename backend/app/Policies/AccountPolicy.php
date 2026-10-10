<?php

namespace App\Policies;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\User;
use App\Services\AccountScope;

class AccountPolicy
{
    public function view(User $user, Account $account): bool
    {
        return $this->viewAny($user) && app(AccountScope::class)->query($user)->whereKey($account->id)->exists();
    }

    public function update(User $user, Account $account): bool
    {
        return $this->view($user, $account) && $account->type !== AccountType::System
            && $account->id !== $user->membership->account_id && in_array('account.update', $user->membership->permissions(), true);
    }

    public function viewAttachments(User $user, Account $account): bool
    {
        return $this->view($user, $account) && in_array('account.attachments.view', $user->membership->permissions(), true);
    }

    public function manageAttachments(User $user, Account $account): bool
    {
        return $this->update($user, $account) && in_array('account.attachments.manage', $user->membership->permissions(), true);
    }

    public function viewAny(User $user): bool
    {
        return $user->isOperational() && in_array('account.view', $user->membership->permissions(), true);
    }

    public function create(User $user): bool
    {
        return $user->isOperational()
            && $user->membership->kind === 'owner'
            && $user->membership->account->type !== AccountType::Pos
            && in_array('account.create', $user->membership->permissions(), true);
    }
}

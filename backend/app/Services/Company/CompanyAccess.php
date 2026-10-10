<?php

namespace App\Services\Company;

use App\Enums\AccountType;
use App\Models\User;
use App\Services\ManagementAuthority;

class CompanyAccess
{
    public function __construct(private ManagementAuthority $authority) {}

    public function requireInbox(User $actor, bool $reply = false): void
    {
        $this->authority->require($actor, 'account.view');
        $this->authority->require($actor, 'support.view');
        abort_unless($actor->membership->account->type === AccountType::System, 403);
        if ($reply) {
            $this->authority->require($actor, 'support.reply');
        }
    }

    public function require(User $actor): void
    {
        $this->authority->require($actor, 'company.edit');
        abort_unless($actor->membership->account->type === AccountType::System, 403);
    }
}

<?php

namespace App\Services\Company;

use App\Models\User;
use App\Services\ManagementAuthority;

class CompanyAccess
{
    public function __construct(private ManagementAuthority $authority) {}

    public function requireInbox(User $actor, bool $reply = false): void
    {
        $this->authority->systemAccess($actor, 'support.view');
        if ($reply) {
            $this->authority->require($actor, 'support.reply');
        }
    }

    public function require(User $actor): void
    {
        $this->authority->systemAccess($actor, 'company.edit');
    }

    public function configuration(User $actor, string $permission): void
    {
        $this->authority->systemAccess($actor, $permission);
    }
}

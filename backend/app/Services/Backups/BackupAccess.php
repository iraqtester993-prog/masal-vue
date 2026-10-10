<?php

namespace App\Services\Backups;

use App\Enums\AccountType;
use App\Models\User;
use App\Services\ManagementAuthority;

class BackupAccess
{
    public function __construct(private ManagementAuthority $authority) {}

    public function require(User $actor, string $permission): void
    {
        $this->authority->require($actor, $permission);
        abort_unless($actor->membership->kind === 'owner' && $actor->membership->account->type === AccountType::System, 403);
    }
}

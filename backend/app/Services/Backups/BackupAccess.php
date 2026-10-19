<?php

namespace App\Services\Backups;

use App\Models\User;
use App\Services\ManagementAuthority;

class BackupAccess
{
    public function __construct(private ManagementAuthority $authority) {}

    public function require(User $actor, string $permission): void
    {
        $this->authority->systemAccess($actor, $permission);
        abort_unless($this->authority->hasFullSystemScope($actor), 403);
        if ($permission !== 'backup.view') {
            $this->authority->require($actor, 'backup.view');
        }
    }
}

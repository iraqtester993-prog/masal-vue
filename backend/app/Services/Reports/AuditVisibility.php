<?php

namespace App\Services\Reports;

use App\Models\User;
use App\Services\AccountPrivacy;
use App\Services\PermissionCatalog;
use Illuminate\Database\Query\Builder;

class AuditVisibility
{
    private const PREFIXES = [
        'wallets' => ['wallets.view'], 'invoices' => ['invoices.view'], 'prices' => ['prices.view'],
        'sell' => ['sales.view', 'sell.receipt'], 'sales' => ['sales.view'], 'digital' => ['digital.view'],
        'topup' => ['topup.view'], 'account' => ['account.view'], 'staff' => ['staff.view'],
        'permission_profile' => ['permission_profile.view'], 'inventory' => ['inventory.view'],
        'import' => ['import.view'], 'claims' => ['claims.view'], 'exports' => ['exports.view'],
        'support' => ['support.view'], 'notifications' => ['notifications.view'], 'security' => ['security.view'],
        'integrations' => ['integrations.view'], 'company' => ['company.view', 'company.edit'],
        'products' => ['products.view'], 'providers' => ['providers.view'], 'branding' => ['branding.view'],
        'governorates' => ['governorates.view'], 'sources' => ['sources.view'], 'posTypes' => ['posTypes.view'],
        'representatives' => ['representatives.view'], 'backup' => ['backup.view'], 'auth' => ['staff.view'],
        'exceptions' => ['exceptions.view'], 'pos.device' => ['pos.device'],
    ];

    private const ACTIONS = [
        'account.permissions' => ['account.permissions'], 'account.login' => ['account.login'],
        'account.password_reset' => ['account.password_reset'], 'staff.password_reset' => ['staff.password_reset'],
        'account.archive' => ['agents.archiveView'], 'account.location' => ['map.view', 'pos.location'],
        'account.attachment.upload' => ['account.attachments.view'], 'account.attachment.delete' => ['account.attachments.view'],
        'company.app-config' => ['app.settings', 'printers.manage'], 'security.account_time' => ['security.time'],
        'security.fundingRequests' => ['security.fundingRequests'], 'security.fundingRecovery' => ['security.fundingRecovery'],
        'digital.assign' => ['digital.assign'], 'digital.balance' => ['integrations.balance'],
        'support.site-inquiry.reply' => ['company.edit'],
        'pos.reference-profile' => ['pos.type', 'pos.representatives'],
        'pos.device.login_transferred' => ['pos.device'], 'pos.device.active' => ['pos.device'], 'pos.device.disabled' => ['pos.device'],
        'agents.categories' => ['agents.categories'],
    ];

    public function apply(Builder $query, User $actor): Builder
    {
        app(AccountPrivacy::class)->excludeOwner($query, 'r.user_id');
        $permissions = $actor->membership->permissions();
        if (array_diff(array_keys(PermissionCatalog::LABELS), $permissions) === []) {
            return $query;
        }

        return $query->where(function (Builder $visible) use ($actor, $permissions): void {
            $visible->whereRaw('1=0');
            foreach (self::ACTIONS as $action => $required) {
                if (array_intersect($required, $permissions)) {
                    $visible->orWhere('r.action', $action);
                }
            }
            foreach (self::PREFIXES as $prefix => $required) {
                if (array_intersect($required, $permissions)) {
                    $pattern = str_replace(['!', '_', '%'], ['!!', '!_', '!%'], $prefix).'.%';
                    $visible->orWhere(function (Builder $events) use ($pattern): void {
                        $events->whereRaw("r.action LIKE ? ESCAPE '!'", [$pattern])->whereNotIn('r.action', array_keys(self::ACTIONS));
                    });
                }
            }
            $visible->orWhere(function (Builder $own) use ($actor): void {
                $own->where('r.user_id', $actor->id)->whereIn('r.action', ['auth.login', 'auth.logout', 'auth.profile', 'auth.password_reset', 'auth.time_expired', 'preferences.updated']);
            });
        });
    }
}

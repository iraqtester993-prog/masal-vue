<?php

use App\Services\PermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const PERMISSIONS = ['app.settings', 'printers.manage', 'staff.password_reset', 'digital.print', 'integrations.credentials', 'integrations.sync', 'integrations.balance', 'topup.view', 'topup.assign', 'topup.prices', 'topup.allocate', 'topup.recover', 'topup.categories', 'security.time'];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (app()->bound('masal.fresh_restore_database') && app('masal.fresh_restore_database') === DB::connection()) {
            return;
        }

        DB::transaction(function (): void {
            foreach (self::PERMISSIONS as $key) {
                DB::table('permissions')->insertOrIgnore(['name' => $key]);
                $permissionId = DB::table('permissions')->where('name', $key)->value('id');
                foreach (DB::table('roles')->get() as $role) {
                    if (PermissionCatalog::defaultRoleAllows($role->name, $key)) {
                        DB::table('role_permissions')->insertOrIgnore(['role_id' => $role->id, 'permission_id' => $permissionId]);
                    }
                }
            }
            $mappings = [
                ['company.edit', ['app.settings', 'printers.manage'], ['system'], true],
                ['digital.receipt', ['digital.print'], ['pos'], true],
                ['digital.view', ['integrations.balance'], ['system', 'main_agent', 'sub_agent', 'sub_branch'], true],
                ['digital.assign', ['topup.view', 'topup.assign', 'topup.prices'], ['main_agent', 'sub_agent', 'sub_branch'], true],
                ['integrations.edit', ['integrations.credentials', 'integrations.sync', 'topup.view', 'topup.assign', 'topup.prices', 'topup.allocate', 'topup.recover', 'topup.categories'], ['system'], false],
                ['security.policies', ['security.time'], ['system'], false],
            ];
            foreach ($mappings as [$old, $newKeys, $types, $copyProfiles]) {
                $oldId = DB::table('permissions')->where('name', $old)->value('id');
                if (! $oldId) {
                    continue;
                }
                foreach (DB::table('permissions')->whereIn('name', $newKeys)->pluck('id') as $newId) {
                    foreach (DB::table('account_permission_rules')->join('accounts', 'accounts.id', '=', 'target_account_id')->where('permission_id', $oldId)->where(fn ($q) => $q->whereIn('accounts.type', $types)->orWhere('allowed', false))->get(['target_account_id', 'authority_account_id', 'allowed']) as $rule) {
                        DB::table('account_permission_rules')->insertOrIgnore(['target_account_id' => $rule->target_account_id, 'authority_account_id' => $rule->authority_account_id, 'permission_id' => $newId, 'allowed' => $rule->allowed]);
                    }
                    foreach (DB::table('membership_permissions')->join('account_memberships', 'account_memberships.id', '=', 'membership_id')->join('accounts', 'accounts.id', '=', 'account_id')->where('permission_id', $oldId)->where(fn ($q) => $q->whereIn('accounts.type', $types)->orWhere('allowed', false))->get(['membership_id', 'allowed']) as $override) {
                        DB::table('membership_permissions')->insertOrIgnore(['membership_id' => $override->membership_id, 'permission_id' => $newId, 'allowed' => $override->allowed]);
                    }
                    if ($copyProfiles) {
                        foreach (DB::table('permission_profile_permissions')->join('permission_profiles', 'permission_profiles.id', '=', 'permission_profile_id')->join('accounts', 'accounts.id', '=', 'account_id')->where('permission_id', $oldId)->whereIn('accounts.type', $types)->pluck('permission_profile_id') as $profileId) {
                            DB::table('permission_profile_permissions')->insertOrIgnore(['permission_profile_id' => $profileId, 'permission_id' => $newId]);
                        }
                    }
                }
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('name', self::PERMISSIONS)->pluck('id');
        foreach (['role_permissions', 'account_permission_rules', 'membership_permissions', 'permission_profile_permissions'] as $table) {
            DB::table($table)->whereIn('permission_id', $ids)->delete();
        }
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};

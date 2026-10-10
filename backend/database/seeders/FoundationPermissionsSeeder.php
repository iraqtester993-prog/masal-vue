<?php

namespace Database\Seeders;

use App\Services\PermissionCatalog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class FoundationPermissionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach (array_keys(PermissionCatalog::LABELS) as $permission) {
            DB::table('permissions')->updateOrInsert(['name' => $permission]);
        }
        foreach (['admin', 'agent', 'pos', 'employee'] as $name) {
            DB::table('roles')->updateOrInsert(['name' => $name]);
            $roleId = DB::table('roles')->where('name', $name)->value('id');
            foreach (array_keys(PermissionCatalog::LABELS) as $permission) {
                if (! PermissionCatalog::defaultRoleAllows($name, $permission)) {
                    continue;
                }
                DB::table('role_permissions')->updateOrInsert(['role_id' => $roleId, 'permission_id' => DB::table('permissions')->where('name', $permission)->value('id')]);
            }
        }
        foreach (['بغداد', 'البصرة', 'نينوى', 'أربيل', 'السليمانية', 'دهوك', 'حلبجة', 'كركوك', 'ديالى', 'الأنبار', 'صلاح الدين', 'بابل', 'كربلاء', 'النجف', 'واسط', 'ميسان', 'ذي قار', 'المثنى', 'القادسية'] as $city) {
            DB::table('account_cities')->insertOrIgnore(['name' => $city, 'active' => true]);
        }
    }
}

<?php

namespace App\Http\Resources;

use App\Models\AccountMembership;
use App\Services\AccountScope;
use App\Services\ManagementAuthority;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

class AccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $context = $request->attributes->get('account_list_context');
        if ($context !== null) {
            $parent = $context['parents']->get($this->parent_id);
            $childrenCount = (int) $context['children']->get($this->id, 0);
            $networkPosCount = (int) $context['networkPos']->get($this->id, 0);
        } else {
            $scope = app(AccountScope::class);
            $parent = $this->parent_id ? $scope->query($request->user())->find($this->parent_id) : null;
            $childrenCount = $scope->query($request->user())->where('parent_id', $this->id)->count();
            $networkPosCount = $scope->query($request->user())->where('type', 'pos')->whereIn('id', DB::table('account_closure')->select('descendant_id')->where('ancestor_id', $this->id)->where('depth', '>', 0))->count();
        }
        $data = [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type->value,
            'parent_id' => $this->parent_id,
            'status' => $this->status,
            'version' => $this->version,
            'device_lock_enabled' => $this->device_lock_enabled,
            'parent' => $parent ? ['id' => $parent->id, 'name' => $parent->name, 'type' => $parent->type->value] : null,
            'children_count' => $childrenCount,
            'network_pos_count' => $networkPosCount,
            'created_at' => $this->created_at?->toISOString(),
        ];
        foreach (['city', 'phone', 'support', 'color', 'owner_name', 'address', 'serial', 'device_model', 'app_version', 'notes'] as $field) {
            $data[$field] = $this->{$field};
        }
        if ($context !== null && $this->type->value === 'pos') {
            $data['representative_names'] = $context['representatives']->get($this->id, []);
        }
        $method = $request->route()?->getActionMethod();
        if ($method !== 'index') {
            $owner = AccountMembership::where('account_id', $this->id)->where('kind', 'owner')->first();
            if ($owner) {
                $data['owner_user'] = ['id' => $owner->user->id, 'name' => $owner->user->name, 'login' => $owner->user->login, 'email' => $owner->user->email];
                $actorPermissions = $request->user()->membership->permissions();
                if (in_array('account.permissions', $actorPermissions, true)) {
                    $data['permissions'] = $owner->permissions();
                    $data['grantable_permissions'] = app(ManagementAuthority::class)->permissionLimits($request->user(), $owner);
                }
                if (in_array('permission_profile.view', $actorPermissions, true) || in_array('staff.create', $actorPermissions, true)) {
                    $data['profile_grantable_permissions'] = array_values(array_intersect($actorPermissions, $owner->permissions()));
                }
            }
        }

        return $data;
    }
}

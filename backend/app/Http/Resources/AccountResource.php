<?php

namespace App\Http\Resources;

use App\Models\AccountMembership;
use App\Services\AccountPrivacy;
use App\Services\AccountScope;
use App\Services\ManagementAuthority;
use App\Services\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

class AccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $context = $request->attributes->get('account_list_context');
        $privacy = app(AccountPrivacy::class);
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
            'name' => $privacy->accountName($this->resource),
            'type' => $this->type->value,
            'parent_id' => $this->parent_id,
            'status' => $this->status,
            'version' => $this->version,
            'device_lock_enabled' => $this->device_lock_enabled,
            'device_binding_enabled' => $this->device_binding_enabled,
            'parent' => $parent ? ['id' => $parent->id, 'name' => $privacy->accountName($parent), 'type' => $parent->type->value] : null,
            'children_count' => $childrenCount,
            'network_pos_count' => $networkPosCount,
            'created_at' => $this->created_at?->toISOString(),
            'can_reset_password' => app(PasswordReset::class)->canReset($request->user(), $this->resource, true),
        ];
        foreach (['city', 'phone', 'support', 'color', 'owner_name', 'address', 'serial', 'device_model', 'app_version', 'notes'] as $field) {
            $data[$field] = $this->type->value === 'system' ? null : $this->{$field};
        }
        if ($context !== null && $this->type->value === 'pos') {
            $data['representative_names'] = $context['representatives']->get($this->id, []);
        }
        $method = $request->route()?->getActionMethod();
        if ($method !== 'index') {
            $data['password_reset_requests'] = app(PasswordReset::class)->pendingRequests($request->user(), $this->resource);
            $owner = AccountMembership::where('account_id', $this->id)->where('kind', 'owner')->first();
            if ($owner) {
                if (! $privacy->isOwner($owner->user_id)) {
                    $data['owner_user'] = ['id' => $owner->user->id, 'name' => $owner->user->name, 'login' => $owner->user->login, 'email' => $owner->user->email];
                }
                $actorPermissions = $request->user()->membership->permissions();
                if (in_array('account.permissions', $actorPermissions, true)) {
                    $data['grantable_permissions'] = app(ManagementAuthority::class)->permissionLimits($request->user(), $owner);
                    $data['permissions'] = array_values(array_intersect($owner->permissions(), array_unique(array_merge($actorPermissions, $data['grantable_permissions']))));
                }
                if (array_intersect(['permission_profile.view', 'permission_profile.create', 'permission_profile.update', 'staff.create', 'staff.role'], $actorPermissions)) {
                    $data['profile_grantable_permissions'] = app(ManagementAuthority::class)->profilePermissions($request->user(), $owner);
                }
            }
        }

        return $data;
    }
}

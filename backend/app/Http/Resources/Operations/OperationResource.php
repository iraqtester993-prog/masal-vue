<?php

namespace App\Http\Resources\Operations;

use App\Models\Operations\AccountArchive;
use App\Services\AccountPrivacy;
use App\Services\AccountScope;
use App\Services\Operations\OperationAccess;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

class OperationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $privacy = app(AccountPrivacy::class);
        if ($this->resource instanceof AccountArchive) {
            $snapshot = $this->before;
            $detail = $request->route('operation_action') !== 'archive-index';
            $members = $detail ? app(AccountScope::class)->members($request->user(), $this->account_id, true)->pluck('user_id')->all() : [];

            return ['id' => $this->id, 'account_id' => $this->account_id, 'name' => $snapshot['name'], 'type' => $snapshot['type'], 'reason' => $this->reason, 'time' => $this->created_at->toISOString(), 'actor_id' => $privacy->userId($this->actor_id), 'actor_name' => $privacy->users()->where('users.id', $this->actor_id)->value('name') ?? 'الخدمة',
                'before' => $detail ? $snapshot : null, 'users' => $detail ? array_values(array_filter($this->users, fn ($u) => in_array($u['id'], $members, true) && ! $privacy->isOwner((int) $u['id']))) : [],
                'image_url' => $detail && ($snapshot['image_id'] ?? null) ? '/api/v1/operations/archive/'.$this->id.'/image' : null,
                'sales_count' => $detail ? DB::table('sales')->where(fn ($q) => $q->where('account_id', $this->account_id)->orWhere('main_account_id', $this->account_id))->count() : null,
                'ledger_count' => $detail ? DB::table('finance_entries')->whereIn('wallet_id', DB::table('finance_wallets')->where('account_id', $this->account_id)->where('kind', 'account')->select('id'))->count() : null];
        }

        return ['id' => $this->id, 'scope' => $this->scope, 'actions' => $this->actions, 'reason' => $this->reason, 'active' => $this->active, 'version' => $this->version, 'creator_id' => $privacy->userId($this->creator_id), 'creator_name' => $privacy->users()->where('users.id', $this->creator_id)->value('name') ?? 'الخدمة', 'time' => $this->created_at->toISOString(), 'resumed_at' => $this->resumed_at?->toISOString(), 'account_ids' => DB::table('operation_stop_targets')->where('stop_id', $this->id)->pluck('account_id')->all(), 'count' => app(OperationAccess::class)->matchedAccounts($this->resource)->count()];
    }
}

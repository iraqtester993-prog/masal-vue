<?php

namespace App\Services;

use App\Models\Account;
use App\Models\PosType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReferencePosProfile
{
    public function __construct(private ReferenceAccess $access, private ManagementAuthority $authority) {}

    /** Called only inside the account creation transaction, after its closure rows exist. */
    public function create(User $actor, Account $account, ?int $typeId, array $representativeIds): void
    {
        $this->write($actor, $account, $typeId, $representativeIds);
    }

    public function write(User $actor, Account $account, ?int $typeId, array $representativeIds): void
    {
        $oldType = DB::table('pos_reference_profiles')->where('account_id', $account->id)->value('pos_type_id');
        $oldIds = DB::table('pos_representatives')->where('account_id', $account->id)->pluck('representative_id')->map(fn ($id): int => (int) $id)->all();
        if ($typeId !== ($oldType ? (int) $oldType : null)) {
            $this->authority->require($actor, 'pos.type');
            if ($typeId !== null && ! PosType::whereKey($typeId)->where('status', 'active')->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['pos_type_id' => 'اختر نوع نقطة بيع مفعلًا.']);
            }
        }
        if (array_diff($representativeIds, $oldIds) || array_diff($oldIds, $representativeIds)) {
            $this->authority->require($actor, 'pos.representatives');
            $eligible = $this->access->representatives($actor)->whereIn('id', $representativeIds)
                ->whereIn('agent_account_id', DB::table('account_closure')->where('ancestor_id', $account->parent_id)->select('descendant_id'))
                ->where(fn ($query) => $query->where('status', 'active')->orWhereIn('id', $oldIds))->lockForUpdate()->pluck('id')->all();
            if (array_diff($representativeIds, $eligible)) {
                throw ValidationException::withMessages(['representative_ids' => 'اختر مندوبي نقطة البيع ضمن نطاق الوكيل وصلاحياتك.']);
            }
        }
        DB::table('pos_reference_profiles')->updateOrInsert(['account_id' => $account->id], ['pos_type_id' => $typeId]);
        DB::table('pos_representatives')->where('account_id', $account->id)->delete();
        foreach ($representativeIds as $id) {
            DB::table('pos_representatives')->insert(['account_id' => $account->id, 'representative_id' => $id]);
        }
    }
}

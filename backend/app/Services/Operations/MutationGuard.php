<?php

namespace App\Services\Operations;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\User;
use App\Services\AccountScope;
use App\Services\PermissionCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MutationGuard
{
    public function lockRuntime(bool $exclusive = false): void
    {
        abort_unless(DB::transactionLevel() > 0, 500, 'Write gate requires a transaction.');
        if (Schema::hasTable('runtime_write_gate')) {
            $query = DB::table('runtime_write_gate')->where('id', 1);
            $gate = ($exclusive ? $query->lockForUpdate() : $query->sharedLock())->first();
            abort_unless($gate && ! $gate->blocked, 423, 'النظام ينفذ استرجاعًا محفوظًا؛ انتظر اكتماله قبل تنفيذ عمليات جديدة.');
        }
    }

    public function key(string $action): string
    {
        if ($action === 'digital.print') {
            return 'printing';
        }
        if (str_starts_with($action, 'import.') || str_starts_with($action, 'stock.import') || str_starts_with($action, 'stock.order')) {
            return 'import';
        }
        if (str_starts_with($action, 'sell.') || in_array($action, ['sales.create', 'sales.reserve', 'sales.issue', 'sales.cancel-reservation'], true) || preg_match('/^sales\.(?:print-|reprint-|deliver)/', $action)) {
            return preg_match('/print|retry|result|deliver/', $action) ? 'printing' : 'sales';
        }
        if ($action === 'digital.create') {
            return 'sales';
        }

        return 'app';
    }

    public function lock(User $actor, array $accountIds = [], string $action = 'app'): void
    {
        abort_unless(DB::transactionLevel() > 0, 500, 'Mutation guard requires a transaction.');
        $this->lockRuntime(in_array($action, ['account.create', 'account.update'], true));
        $version = (int) ($actor->session_version ?? 1);
        $fresh = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
        abort_unless((int) $fresh->session_version === $version, 401, 'انتهت صلاحية الجلسة؛ سجل الدخول مجددًا.');
        $actor->refresh();
        $actor->unsetRelation('membership');
        abort_unless($actor->isOperational(), 403);
        $candidate = array_values(array_unique(array_filter(array_map('intval', $accountIds), fn ($id) => $id > 0)));
        $candidate = app(AccountScope::class)->query($actor)->whereIn('id', $candidate)->pluck('id')->all();
        $candidate[] = $actor->membership->account_id;
        $ancestors = DB::table('account_closure')->whereIn('descendant_id', $candidate)->pluck('ancestor_id')->unique();
        Account::whereIn('id', $ancestors)->where(fn ($q) => $q->where('type', '<>', AccountType::System->value)->orWhere('id', $actor->membership->account_id))->orderBy('id')->lockForUpdate()->get();
        abort_unless($actor->isOperational(), 403);
        app(OperationGuard::class)->assertAllowed($actor->membership->account_id, $this->key($action));
        if (array_key_exists($action, PermissionCatalog::LABELS)) {
            abort_unless(in_array($action, $actor->membership->permissions(), true), 403);
        }
    }

    public function targets(array $data): array
    {
        $ids = [];
        foreach (['account_id', 'from_account_id', 'to_account_id', 'recipient_id', 'parent_id'] as $key) {
            if (isset($data[$key]) && is_numeric($data[$key])) {
                $ids[] = (int) $data[$key];
            }
        }
        foreach ($data['rows'] ?? [] as $row) {
            if (is_array($row)) {
                $ids = array_merge($ids, $this->targets($row));
            }
        }

        return array_values(array_unique($ids));
    }
}

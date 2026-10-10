<?php

namespace App\Services\Notifications;

use App\Enums\AccountType;
use App\Models\Notifications\Notice;
use App\Models\Support\SupportAttachment;
use App\Models\User;
use App\Services\AccountScope;
use App\Services\AuditLogger;
use App\Services\Operations\MutationGuard;
use App\Services\Support\SupportAccess;
use App\Services\Support\SupportOperations;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class NotificationService
{
    public function __construct(private SupportAccess $access, private SupportOperations $operations, private AuditLogger $audit, private AccountScope $scope) {}

    public function visible(User $actor): Builder
    {
        return Notice::query()->where(function ($q) use ($actor): void {
            $q->where('sender_id', $actor->id)->orWhereExists(function ($r) use ($actor): void {
                $r->selectRaw('1')->from('notice_recipients')->whereColumn('notice_id', 'notices.id')->where('user_id', $actor->id);
            });
        });
    }

    public function create(?User $actor, string $title, string $body, array $userIds, array $attributes = []): ?Notice
    {
        $ids = User::query()->whereIn('id', array_unique($userIds))->where('status', 'active')->where('id', '<>', $actor?->id ?? 0)->pluck('id')->all();
        if (! $ids) {
            return null;
        }
        if (! empty($attributes['event_key']) && ($old = Notice::where('event_key', $attributes['event_key'])->first())) {
            return $old;
        }
        $notice = Notice::create(['sender_id' => $actor?->id, 'title' => $title, 'body' => $body] + $attributes);
        foreach (array_chunk($ids, 500) as $chunk) {
            DB::table('notice_recipients')->insert(array_map(fn ($id) => ['notice_id' => $notice->id, 'user_id' => $id, 'read_at' => null], $chunk));
        }

        return $notice;
    }

    public function claimAttachment(User $actor, ?int $id, string $kind): ?int
    {
        if (! $id) {
            return null;
        }
        $this->access->require($actor, $kind === 'support' ? 'support.attach' : 'notifications.attach');
        $image = SupportAttachment::where('user_id', $actor->id)->where('kind', $kind)->lockForUpdate()->findOrFail($id);
        abort_unless(! $image->assigned && $image->expires_at->isFuture(), 409, 'ارفع الصورة مجددًا؛ انتهت صلاحيتها أو استُخدمت.');
        $image->update(['assigned' => true]);

        return $image->id;
    }

    public function send(User $actor, array $data, Request $request): array
    {
        $this->access->require($actor, 'notifications.send');
        $translations = array_filter((array) ($data['translations'] ?? []), fn ($text) => trim($text['title'] ?? '') !== '' || trim($text['body'] ?? '') !== '');
        if ($translations) {
            $this->access->require($actor, 'notifications.translations');
        }
        if ($data['mode'] === 'agents') {
            abort_unless($actor->membership->account->type === AccountType::System, 403);
        }

        return $this->operations->execute($actor, 'notifications.send', $data, function () use ($actor, $data, $translations, $request): array {
            $this->access->require($actor, 'notifications.send');
            $users = $this->access->selectAudience($actor, false, $data['mode'], $data['user_ids'] ?? []);
            $image = $this->claimAttachment($actor, $data['attachment_id'] ?? null, 'notification');
            $notice = $this->create($actor, trim($data['title']), trim($data['body']), array_column($users, 'id'), ['attachment_id' => $image, 'translations' => $translations ?: null]);
            $this->audit->record('notifications.send', $request, $actor, $actor->membership->account_id, ['notice_id' => $notice->id, 'count' => count($users), 'mode' => $data['mode']]);

            return ['id' => $notice->id, 'count' => count($users)];
        });
    }

    public function read(User $actor, int $id): void
    {
        $this->access->require($actor, 'notifications.view');
        DB::transaction(function () use ($actor, $id): void {
            app(MutationGuard::class)->lock($actor, [], 'notifications.view');
            $this->visible($actor)->findOrFail($id);
            DB::table('notice_recipients')->where('notice_id', $id)->where('user_id', $actor->id)->whereNull('read_at')->update(['read_at' => now()]);
        });
    }

    public function readPage(User $actor, string $page): int
    {
        $this->access->require($actor, 'notifications.view');

        return DB::transaction(function () use ($actor, $page): int {
            app(MutationGuard::class)->lock($actor, [], 'notifications.view');

            return DB::table('notice_recipients')->where('user_id', $actor->id)->whereNull('read_at')->whereIn('notice_id', Notice::where('page', $page)->select('id'))->update(['read_at' => now()]);
        });
    }

    public function unread(User $actor): Builder
    {
        return Notice::query()->whereExists(function ($r) use ($actor): void {
            $r->selectRaw('1')->from('notice_recipients')->whereColumn('notice_id', 'notices.id')->where('user_id', $actor->id)->whereNull('read_at');
        });
    }

    public function canOpen(User $actor, Notice $notice): bool
    {
        if (! $notice->page || ! $notice->entity_id) {
            return false;
        }
        $permission = match ($notice->page) {
            'accounts' => 'account.view',default => $notice->page.'.view'
        };
        if (! $this->access->allows($actor, $permission)) {
            return false;
        }
        if ($notice->page === 'support') {
            return $this->access->tickets($actor)->whereKey($notice->entity_id)->exists();
        }
        if ($notice->page === 'wallets' && $notice->entity_type === 'transaction') {
            return DB::table('finance_entries')->join('finance_wallets', 'finance_wallets.id', '=', 'wallet_id')->where('transaction_id', $notice->entity_id)->where('finance_wallets.kind', 'account')->whereIn('finance_wallets.account_id', $this->scope->query($actor)->select('id'))->exists();
        }
        if ($notice->page === 'exceptions' && $notice->entity_type === 'sale') {
            return DB::table('sales')->where('id', $notice->entity_id)->whereIn('account_id', $this->scope->query($actor)->select('id'))->exists();
        }
        $table = match ($notice->page) {
            'import' => 'stock_orders','inventory' => 'stock_batches','claims' => 'stock_claims','exports' => 'stock_withdrawals','wallets' => 'finance_funding_requests','invoices' => 'finance_invoices','prices' => 'finance_price_requests','sales' => 'sales','exceptions' => 'sales_reprint_requests','accounts' => 'accounts',default => null
        };
        if (! $table) {
            return false;
        }
        $column = match ($notice->page) {
            'accounts' => 'id','wallets' => 'to_account_id',default => 'account_id'
        };

        return DB::table($table)->where('id', $notice->entity_id)->whereIn($column, $this->scope->query($actor)->select('id'))->exists();
    }
}

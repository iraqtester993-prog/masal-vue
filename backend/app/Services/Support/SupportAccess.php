<?php

namespace App\Services\Support;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Support\SupportMessage;
use App\Models\Support\SupportTicket;
use App\Models\User;
use App\Services\AccountScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class SupportAccess
{
    private ?\WeakMap $permissions = null;

    public function __construct(private AccountScope $scope) {}

    public function allows(User $actor, string $permission): bool
    {
        $this->permissions ??= new \WeakMap;
        $permissions = $this->permissions[$actor] ??= ($actor->isOperational() ? $actor->membership->permissions() : []);

        return in_array($permission, $permissions, true);
    }

    public function require(User $actor, string $permission): void
    {
        $module = str_starts_with($permission, 'notifications.') ? 'notifications' : 'support';
        abort_unless($this->allows($actor, 'account.view') && $this->allows($actor, $module.'.view') && $this->allows($actor, $permission), 403);
    }

    public function isSystemOwner(User $actor): bool
    {
        return $actor->membership->kind === 'owner' && $actor->membership->account->type === AccountType::System;
    }

    public function contacts(User $actor): Builder
    {
        $own = $actor->membership->account;

        return Account::query()->where('status', 'active')->where(function ($q) use ($actor, $own): void {
            $q->where('id', $own->parent_id ?? -1)->orWhere(function ($children) use ($actor, $own): void {
                $children->where('parent_id', $own->id)->whereIn('id', $this->scope->query($actor)->select('id'));
            });
        });
    }

    public function tickets(User $actor): Builder
    {
        $own = $actor->membership->account_id;

        return SupportTicket::query()->where(function ($q) use ($actor, $own): void {
            $q->where(function ($direct) use ($actor, $own): void {
                $direct->whereNull('broadcast_recipient_id')->where(function ($visible) use ($actor, $own): void {
                    $visible->where('sender_id', $actor->id)->orWhereExists(function ($participants) use ($own): void {
                        $participants->selectRaw('1')->from('support_ticket_participants')->whereColumn('ticket_id', 'support_tickets.id')->where('account_id', $own);
                    });
                });
                if ($actor->membership->kind === 'employee') {
                    $direct->where(function ($bounded) use ($actor, $own): void {
                        $bounded->where('sender_id', $actor->id)->orWhereExists(function ($participant) use ($actor, $own): void {
                            $participant->selectRaw('1')->from('support_ticket_participants')->whereColumn('ticket_id', 'support_tickets.id')->where('account_id', '<>', $own)->whereIn('account_id', $this->scope->query($actor)->select('id'));
                        });
                    });
                }
            })->orWhere(function ($broadcast) use ($actor): void {
                $broadcast->whereNotNull('broadcast_recipient_id');
                if (! $this->isSystemOwner($actor)) {
                    $broadcast->where(function ($users) use ($actor): void {
                        $users->where('sender_id', $actor->id)->orWhere('broadcast_recipient_id', $actor->id);
                    });
                }
            });
        });
    }

    public function ticket(User $actor, int $id, bool $lock = false): SupportTicket
    {
        $this->require($actor, 'support.view');
        $q = $this->tickets($actor)->with(['origin', 'recipient', 'sender']);

        return ($lock ? $q->lockForUpdate() : $q)->findOrFail($id);
    }

    public function messages(User $actor, ?int $ticketId = null): Builder
    {
        return SupportMessage::query()->when($ticketId, fn ($q) => $q->where('ticket_id', $ticketId))->where(function ($visible) use ($actor): void {
            $visible->where('is_opening', true)->orWhereExists(function ($audience) use ($actor): void {
                $audience->selectRaw('1')->from('support_message_audience')->whereColumn('message_id', 'support_messages.id')->where('account_id', $actor->membership->account_id);
            });
        });
    }

    public function activePair(SupportTicket $ticket): array
    {
        return array_values(array_slice($ticket->route_stack, -2));
    }

    public function canReply(User $actor, SupportTicket $ticket): bool
    {
        return $ticket->status !== 'closed' && in_array($actor->membership->account_id, $this->activePair($ticket), true) && $this->allows($actor, 'support.reply');
    }

    public function canClose(User $actor, SupportTicket $ticket): bool
    {
        return $ticket->status !== 'closed' && $ticket->recipient_id === $actor->membership->account_id && count($ticket->route_stack) <= 2 && $this->allows($actor, 'support.close');
    }

    public function canEscalate(User $actor, SupportTicket $ticket): bool
    {
        return $ticket->status !== 'closed' && ! $ticket->broadcast_recipient_id && $ticket->recipient_id === $actor->membership->account_id && $actor->membership->account->parent_id !== null && $this->allows($actor, 'support.escalate');
    }

    public function audience(User $actor, bool $support): array
    {
        $this->require($actor, $support ? 'support.broadcast' : 'notifications.send');
        if (! $support && $actor->membership->account->type === AccountType::Pos) {
            return [];
        }
        $own = $actor->membership->account;
        $scope = $this->scope->query($actor)->select('id');
        $members = DB::table('account_memberships')->join('users', 'users.id', '=', 'user_id')->join('accounts', 'accounts.id', '=', 'account_id')->where('users.status', 'active')->where('account_memberships.status', 'active')->where('accounts.status', 'active')->where('users.id', '<>', $actor->id)
            ->where(function ($q) use ($actor, $own, $scope, $support): void {
                if ($this->isSystemOwner($actor)) {
                    return;
                }
                $q->whereIn('accounts.id', $scope);
                if (! $support) {
                    $q->where('accounts.id', '<>', $own->id)->where('accounts.type', '<>', AccountType::System->value);
                }
            })->select(['users.id', 'users.name', 'users.email', 'accounts.id as account_id', 'accounts.name as account_name', 'accounts.type', 'account_memberships.kind'])->orderBy('users.id')->limit(10001)->get();
        abort_if($members->count() > 10000, 422, 'عدد المستلمين يتجاوز 10000؛ ضيّق نطاق الموظف.');
        $operational = $this->operationalAccounts($members->pluck('account_id')->map(fn ($id) => (int) $id)->unique()->all());
        $users = User::with('membership.account', 'membership.profile')->whereIn('id', $members->pluck('id'))->get()->keyBy('id');
        $employeeIds = [];
        $valid = [];
        foreach ($members as $member) {
            if (! $support && $own->type !== AccountType::System && $member->type === AccountType::MainAgent->value && $member->kind === 'owner') {
                continue;
            }
            $user = $users->get($member->id);
            if (! $user || ! isset($operational[(int) $member->account_id])) {
                continue;
            }
            if ($member->kind === 'employee' && (! $user->membership->profile || $user->membership->profile->status !== 'active' || $user->membership->profile->account_id !== (int) $member->account_id)) {
                continue;
            }
            if (! $this->isSystemOwner($actor) && $member->kind === 'employee') {
                $employeeIds[$member->account_id] ??= $this->scope->members($actor, (int) $member->account_id)->pluck('user_id')->all();
                if (! in_array((int) $member->id, $employeeIds[$member->account_id], true)) {
                    continue;
                }
            }
            $valid[] = (array) $member;
        }

        return $valid;
    }

    /** @return array<int,true> */
    public function operationalAccounts(array $ids): array
    {
        $rows = DB::table('account_closure')->join('accounts', 'accounts.id', '=', 'ancestor_id')->whereIn('descendant_id', $ids)->get(['descendant_id', 'accounts.id', 'accounts.parent_id', 'accounts.status', 'depth'])->groupBy('descendant_id');
        $valid = [];
        foreach ($ids as $id) {
            $ancestors = ($rows->get($id) ?? collect())->keyBy('id');
            $next = $id;
            $depth = 0;
            $seen = [];
            $ok = true;
            while ($next !== null) {
                $ancestor = $ancestors->get($next);
                if (! $ancestor || isset($seen[$next]) || $ancestor->status !== 'active' || (int) $ancestor->depth !== $depth) {
                    $ok = false;
                    break;
                }
                $seen[$next] = true;
                $next = $ancestor->parent_id;
                $depth++;
            }
            if ($ok && count($seen) === $ancestors->count()) {
                $valid[$id] = true;
            }
        }

        return $valid;
    }

    public function selectAudience(User $actor, bool $support, string $mode, array $ids): array
    {
        $users = $this->audience($actor, $support);
        $selected = array_values(array_filter($users, function (array $u) use ($mode, $ids): bool {
            return match ($mode) {
                'all' => true,'agents' => $u['type'] === AccountType::MainAgent->value && $u['kind'] === 'owner','branches' => in_array($u['type'], [AccountType::SubAgent->value, AccountType::SubBranch->value], true) && $u['kind'] === 'owner','points' => $u['type'] === AccountType::Pos->value && $u['kind'] === 'owner','custom' => in_array((int) $u['id'], $ids, true),default => false
            };
        }));
        if ($mode === 'custom') {
            abort_if(array_diff($ids, array_map(fn ($u) => (int) $u['id'], $users)), 404);
        }
        abort_if($selected === [], 422, 'اختر مستلمًا واحدًا على الأقل.');

        return $selected;
    }
}

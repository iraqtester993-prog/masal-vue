<?php

namespace App\Services\Support;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Support\SupportMessage;
use App\Models\Support\SupportPhoneProfile;
use App\Models\Support\SupportTicket;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\ManagementAuthority;
use App\Services\Notifications\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SupportWorkflow
{
    public function __construct(private SupportAccess $access, private SupportOperations $operations, private NotificationService $notices, private ManagementAuthority $authority, private AuditLogger $audit) {}

    public function create(User $actor, array $data, Request $request, bool $broadcast = false): array
    {
        $this->access->require($actor, 'support.create');
        if ($broadcast) {
            $this->access->require($actor, 'support.broadcast');
        }

        return $this->operations->execute($actor, $broadcast ? 'support.broadcast' : 'support.create', $data, function () use ($actor, $data, $request, $broadcast): array {
            $this->access->require($actor, 'support.create');
            $image = $this->notices->claimAttachment($actor, $data['attachment_id'] ?? null, 'support');
            $own = $actor->membership->account_id;
            if ($broadcast) {
                $targets = $this->access->selectAudience($actor, true, $data['mode'], $data['user_ids'] ?? []);
                $group = (string) Str::uuid();
            } else {
                $recipient = $this->access->contacts($actor)->findOrFail($data['recipient_id']);
                abort_unless($recipient->isOperational(), 404);
                $targets = [['id' => null, 'account_id' => $recipient->id]];
                $group = null;
            }
            $ticketIds = [];
            foreach ($targets as $target) {
                $route = array_values(array_unique([$own, (int) $target['account_id']]));
                $ticket = SupportTicket::create(['sender_id' => $actor->id, 'origin_id' => $own, 'recipient_id' => $target['account_id'], 'broadcast_recipient_id' => $target['id'], 'broadcast_group' => $group, 'attachment_id' => $image, 'title' => trim($data['title']), 'description' => trim($data['description']), 'route_stack' => $route, 'last_message_at' => now()]);
                DB::table('support_ticket_participants')->insert(array_map(fn ($accountId) => ['ticket_id' => $ticket->id, 'account_id' => $accountId], $route));
                SupportMessage::create(['ticket_id' => $ticket->id, 'user_id' => $actor->id, 'account_id' => $own, 'body' => $ticket->description, 'is_opening' => true]);
                $this->notify($actor, $ticket, 'رسالة دعم جديدة', [$target['account_id']]);
                $ticketIds[] = $ticket->id;
            }
            $this->audit->record($broadcast ? 'support.broadcast' : 'support.create', $request, $actor, $own, ['ticket_ids' => $ticketIds, 'count' => count($ticketIds), 'group_id' => $group]);

            return $broadcast ? ['count' => count($ticketIds), 'group_id' => $group] : ['id' => $ticketIds[0]];
        });
    }

    public function reply(User $actor, int $id, array $data, Request $request): array
    {
        $this->access->require($actor, 'support.reply');

        return $this->operations->execute($actor, 'support.reply:'.$id, $data, function () use ($actor, $id, $data, $request): array {
            $ticket = $this->access->ticket($actor, $id, true);
            $this->authority->version($ticket, (int) $data['version']);
            abort_unless($this->access->canReply($actor, $ticket), 403, 'المحادثة لدى المستوى الأعلى أو مغلقة.');
            $audience = $this->access->activePair($ticket);
            $message = SupportMessage::create(['ticket_id' => $id, 'user_id' => $actor->id, 'account_id' => $actor->membership->account_id, 'body' => trim($data['body'])]);
            DB::table('support_message_audience')->insert(array_map(fn ($accountId) => ['message_id' => $message->id, 'account_id' => $accountId], $audience));
            $stack = $ticket->route_stack;
            if ($ticket->recipient_id === $actor->membership->account_id && count($stack) > 2) {
                $from = array_pop($stack);
                $ticket->recipient_id = $stack[array_key_last($stack)];
                $ticket->route_stack = $stack;
                $ticket->status = 'replied';
                $this->history($actor, $ticket, 'return', $from, $ticket->recipient_id);
            }
            $ticket->last_message_at = now();
            $ticket->version++;
            $ticket->save();
            $this->notify($actor, $ticket, 'رد جديد على رسالة الدعم', $audience);
            $this->audit->record('support.reply', $request, $actor, $ticket->origin_id, ['ticket_id' => $id, 'message_id' => $message->id, 'version' => $ticket->version]);

            return ['id' => $id];
        });
    }

    public function status(User $actor, int $id, array $data, Request $request): array
    {
        $this->access->require($actor, 'support.'.$data['action']);

        return $this->operations->execute($actor, 'support.status:'.$id, $data, function () use ($actor, $id, $data, $request): array {
            $ticket = $this->access->ticket($actor, $id, true);
            $this->authority->version($ticket, (int) $data['version']);
            $old = $ticket->recipient_id;
            if ($data['action'] === 'close') {
                abort_unless($this->access->canClose($actor, $ticket), 403, 'أرسل الرد للمسؤول المباشر قبل إغلاق المحادثة.');
                $ticket->status = 'closed';
                $targets = $ticket->route_stack;
            } else {
                abort_unless($this->access->canEscalate($actor, $ticket), 403, 'التصعيد للمستلم الحالي فقط.');
                $parent = $actor->membership->account->parent;
                abort_unless($parent?->isOperational(), 404);
                $stack = $ticket->route_stack;
                abort_if(in_array($parent->id, $stack, true), 409, 'لا يمكن تكرار الحساب في مسار المحادثة.');
                $stack[] = $parent->id;
                $ticket->route_stack = $stack;
                $ticket->recipient_id = $parent->id;
                $ticket->status = 'escalated';
                $targets = [$parent->id];
                DB::table('support_ticket_participants')->insertOrIgnore(['ticket_id' => $id, 'account_id' => $parent->id]);
            }
            $ticket->version++;
            $ticket->save();
            $this->history($actor, $ticket, $data['action'], $old, $ticket->recipient_id);
            $this->notify($actor, $ticket, $data['action'] === 'close' ? 'تم إغلاق رسالة الدعم' : 'رسالة دعم مصعّدة', $targets);
            $this->audit->record('support.'.$data['action'], $request, $actor, $ticket->origin_id, ['ticket_id' => $id, 'from' => $old, 'to' => $ticket->recipient_id, 'version' => $ticket->version]);

            return ['id' => $id];
        });
    }

    public function read(User $actor, int $id): void
    {
        DB::transaction(function () use ($actor, $id): void {
            $this->access->ticket($actor, $id, true);
            $latest = (int) $this->access->messages($actor, $id)->max('id');
            $old = DB::table('support_reads')->where('ticket_id', $id)->where('user_id', $actor->id)->value('last_message_id');
            DB::table('support_reads')->updateOrInsert(['ticket_id' => $id, 'user_id' => $actor->id], ['last_message_id' => max((int) $old, $latest), 'read_at' => now()]);
        });
    }

    public function phones(User $actor, int $id): array
    {
        $this->access->require($actor, 'support.view');
        $own = $actor->membership->account_id;
        $visible = $id === $own || $this->access->contacts($actor)->whereKey($id)->exists() || $this->access->tickets($actor)->whereIn('id', DB::table('support_ticket_participants')->where('account_id', $id)->select('ticket_id'))->exists();
        abort_unless($visible, 404);
        $account = Account::findOrFail($id);
        $profile = SupportPhoneProfile::where('account_id', $id)->first();
        $phones = $profile?->phones ?? [];
        if (! $profile && $account->type !== AccountType::Pos && preg_match_all('/(?<![0-9])07[78][0-9]{8}(?![0-9])/', $account->support ?? '', $matches)) {
            $phones = array_map(fn ($number) => ['label' => 'الدعم الفني', 'number' => $number], array_slice(array_unique($matches[0]), 0, 5));
        }

        return ['account_id' => $id, 'phones' => $account->type === AccountType::Pos ? [] : $phones, 'version' => $profile?->version ?? 0, 'can_edit' => $id === $own && $actor->membership->kind === 'owner' && $account->type !== AccountType::Pos && $this->access->allows($actor, 'support.create')];
    }

    public function savePhones(User $actor, int $id, array $data, Request $request): array
    {
        $this->access->require($actor, 'support.create');
        abort_unless($id === $actor->membership->account_id && $actor->membership->kind === 'owner' && $actor->membership->account->type !== AccountType::Pos, 403);

        return $this->operations->execute($actor, 'support.phones', $data, function () use ($actor, $id, $data, $request): array {
            Account::whereKey($id)->lockForUpdate()->firstOrFail();
            $record = SupportPhoneProfile::where('account_id', $id)->lockForUpdate()->first();
            abort_unless(($record?->version ?? 0) === (int) $data['version'], 409, 'تغيرت أرقام الدعم؛ أعد فتحها.');
            $phones = array_map(fn ($p) => ['label' => trim($p['label']), 'number' => $p['number']], $data['phones']);
            if ($record) {
                $record->update(['phones' => $phones, 'version' => $record->version + 1]);
            } else {
                SupportPhoneProfile::create(['account_id' => $id, 'phones' => $phones]);
            }
            $this->audit->record('support.phones', $request, $actor, $id, ['count' => count($phones)]);

            return ['id' => $id];
        });
    }

    private function history(User $actor, SupportTicket $ticket, string $action, int $from, int $to): void
    {
        DB::table('support_history')->insert(['ticket_id' => $ticket->id, 'user_id' => $actor->id, 'action' => $action, 'from_id' => $from, 'to_id' => $to, 'created_at' => now()]);
    }

    private function notify(User $actor, SupportTicket $ticket, string $title, array $accounts): void
    {
        $targets = $ticket->broadcast_recipient_id ? [$ticket->sender_id, $ticket->broadcast_recipient_id] : DB::table('account_memberships')->whereIn('account_id', array_unique($accounts))->where('status', 'active')->pluck('user_id')->all();
        $targets = User::with('membership.account', 'membership.profile')->whereIn('id', $targets)->where('status', 'active')->get()
            ->filter(fn (User $user): bool => $this->access->allows($user, 'account.view') && $this->access->allows($user, 'support.view') && $this->access->tickets($user)->whereKey($ticket->id)->exists())
            ->pluck('id')->all();
        $this->notices->create($actor, $title, $ticket->title, $targets, ['page' => 'support', 'entity_id' => $ticket->id]);
    }
}

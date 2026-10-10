<?php

namespace App\Http\Resources\Support;

use App\Services\Support\SupportAccess;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

class SupportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $actor = $request->user();
        $access = $request->attributes->get('support_resource_access') ?? app(SupportAccess::class);
        $request->attributes->set('support_resource_access', $access);
        $ticket = $this->resource;
        $read = (int) DB::table('support_reads')->where('ticket_id', $ticket->id)->where('user_id', $actor->id)->value('last_message_id');
        $detail = $request->route('support_action') !== 'index';
        $messageQuery = $access->messages($actor, $ticket->id);
        $unread = (clone $messageQuery)->where('user_id', '<>', $actor->id)->where('id', '>', $read)->count();
        $messages = $detail ? $messageQuery->with('user')->orderBy('id')->limit(10001)->get() : collect();
        $last = $detail ? $messages->last() : (clone $messageQuery)->with('user')->orderByDesc('id')->first();
        abort_if($messages->count() > 10000, 422, 'المحادثة تجاوزت حد العرض؛ تواصل مع مدير النظام.');
        $participants = DB::table('support_ticket_participants')->join('accounts', 'accounts.id', '=', 'account_id')->where('ticket_id', $ticket->id)->orderBy('accounts.id')->get(['accounts.id', 'accounts.name', 'accounts.type'])->map(fn ($p) => (array) $p)->all();
        $history = $detail ? DB::table('support_history')->join('users', 'users.id', '=', 'user_id')->where('ticket_id', $ticket->id)->orderBy('support_history.id')->get(['support_history.action', 'users.name as user_name', 'support_history.from_id', 'support_history.to_id', 'support_history.created_at as time'])->map(fn ($h) => (array) $h)->all() : [];

        return ['id' => $ticket->id, 'title' => $ticket->title, 'description' => $ticket->description, 'attachment_id' => $ticket->attachment_id, 'status' => $ticket->status, 'origin_id' => $ticket->origin_id, 'origin_name' => $ticket->origin->name, 'recipient_id' => $ticket->recipient_id, 'recipient_name' => $ticket->recipient->name, 'sender_id' => $ticket->sender_id, 'sender_name' => $ticket->sender->name, 'broadcast_recipient_id' => $ticket->broadcast_recipient_id, 'broadcast_group' => $ticket->broadcast_group, 'route_stack' => $ticket->route_stack, 'participants' => $participants, 'version' => $ticket->version, 'unread_count' => $unread, 'last_message_at' => $detail ? $messages->last()?->created_at?->toISOString() : $ticket->visible_latest_at, 'last_message' => $last ? ['body' => $last->body, 'name' => $last->user->name, 'mine' => $last->user_id === $actor->id, 'time' => $last->created_at->toISOString()] : null, 'messages' => $messages->map(fn ($m) => ['id' => $m->id, 'body' => $m->body, 'user_id' => $m->user_id, 'name' => $m->user->name, 'mine' => $m->user_id === $actor->id, 'time' => $m->created_at->toISOString(), 'attachment_id' => $m->is_opening ? $ticket->attachment_id : null])->all(), 'history' => $history, 'can_reply' => $access->canReply($actor, $ticket), 'can_close' => $access->canClose($actor, $ticket), 'can_escalate' => $access->canEscalate($actor, $ticket)];
    }
}

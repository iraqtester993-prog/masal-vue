<?php

namespace App\Http\Resources\Notifications;

use App\Services\Notifications\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

class NoticeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $actor = $request->user();
        $notice = $this->resource;
        $locale = $request->input('locale', 'ar');
        $text = $notice->translations[$locale] ?? null;
        $recipient = DB::table('notice_recipients')->where('notice_id', $notice->id)->where('user_id', $actor->id)->first();

        return ['id' => $notice->id, 'title' => $text['title'] ?? $notice->title, 'body' => $text['body'] ?? $notice->body, 'sender_id' => $notice->sender_id, 'sender_name' => $notice->sender?->name ?? 'النظام', 'mine' => $notice->sender_id === $actor->id, 'read' => $notice->sender_id === $actor->id || $recipient?->read_at !== null, 'time' => $notice->created_at->toISOString(), 'attachment_id' => $notice->attachment_id, 'page' => $notice->page, 'entity_id' => $notice->entity_id, 'can_open' => app(NotificationService::class)->canOpen($actor, $notice)];
    }
}

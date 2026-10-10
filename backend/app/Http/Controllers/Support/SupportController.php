<?php

namespace App\Http\Controllers\Support;

use App\Http\Controllers\Controller;
use App\Http\Requests\Support\AttachmentRequest;
use App\Http\Requests\Support\SupportRequest;
use App\Http\Resources\Support\SupportResource;
use App\Models\Support\SupportAttachment;
use App\Services\Notifications\NotificationService;
use App\Services\Operations\MutationGuard;
use App\Services\Support\SupportAccess;
use App\Services\Support\SupportWorkflow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class SupportController extends Controller
{
    public function __construct(private SupportAccess $access, private SupportWorkflow $workflow) {}

    public function options(Request $request): JsonResponse
    {
        $actor = $request->user();
        $this->access->require($actor, 'support.view');
        $contacts = $this->access->contacts($actor)->orderBy('id')->limit(10001)->get();
        abort_if($contacts->count() > 10000, 422, 'ضيّق نطاق الحسابات.');
        $operational = $this->access->operationalAccounts($contacts->pluck('id')->all());
        $recipients = $contacts->filter(fn ($a) => isset($operational[$a->id]))->map(fn ($a) => ['id' => $a->id, 'name' => $a->name, 'type' => $a->type->value, 'phones' => $this->workflow->phones($actor, $a->id)['phones']])->values()->all();
        $own = $actor->membership->account;

        return response()->json(['data' => ['identity' => ['id' => $own->id, 'name' => $own->name, 'type' => $own->type->value], 'recipients' => $recipients, 'broadcast_users' => $this->access->allows($actor, 'support.broadcast') ? $this->access->audience($actor, true) : [], 'site_inquiries_count' => $own->type->value === 'system' ? DB::table('company_inquiries')->where('status', 'new')->count() : 0, 'can_edit_phones' => $this->workflow->phones($actor, $own->id)['can_edit']]]);
    }

    public function index(SupportRequest $request): JsonResponse
    {
        $actor = $request->user();
        $this->access->require($actor, 'support.view');
        $q = $this->access->tickets($actor)->with(['origin', 'recipient', 'sender']);
        $visible = $this->access->messages($actor)->whereColumn('ticket_id', 'support_tickets.id');
        $unread = (clone $visible)->where('user_id', '<>', $actor->id)->whereRaw('support_messages.id > COALESCE((SELECT last_message_id FROM support_reads WHERE ticket_id = support_tickets.id AND user_id = ?), 0)', [$actor->id]);
        $unreadTotal = $this->access->tickets($actor)->whereExists((clone $unread)->selectRaw('1')->toBase())->count();
        if ($request->input('filter') === 'unread') {
            $q->whereExists($unread->selectRaw('1')->toBase());
        }
        if ($term = trim($request->input('query', ''))) {
            $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term).'%';
            $q->where(function ($search) use ($term): void {
                $search->whereRaw("title LIKE ? ESCAPE '!'", [$term])->orWhereHas('origin', fn ($a) => $a->whereRaw("name LIKE ? ESCAPE '!'", [$term]))->orWhereHas('recipient', fn ($a) => $a->whereRaw("name LIKE ? ESCAPE '!'", [$term]));
            });
        }
        $q->select('support_tickets.*')->selectSub((clone $visible)->selectRaw('MAX(created_at)'), 'visible_latest_at')->orderByDesc('visible_latest_at')->orderByDesc('id');
        $page = $q->paginate($request->integer('per_page', 20));

        return response()->json(['data' => SupportResource::collection($page->getCollection())->resolve($request), 'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'unread_total' => $unreadTotal]]);
    }

    public function show(SupportRequest $request, int $id): SupportResource
    {
        return new SupportResource($this->access->ticket($request->user(), $id));
    }

    public function create(SupportRequest $request): SupportResource
    {
        $result = $this->workflow->create($request->user(), $request->validated(), $request);

        return new SupportResource($this->access->ticket($request->user(), $result['id']));
    }

    public function broadcast(SupportRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->workflow->create($request->user(), $request->validated(), $request, true)], 201);
    }

    public function reply(SupportRequest $request, int $id): SupportResource
    {
        $this->workflow->reply($request->user(), $id, $request->validated(), $request);

        return new SupportResource($this->access->ticket($request->user(), $id));
    }

    public function status(SupportRequest $request, int $id): SupportResource
    {
        $this->workflow->status($request->user(), $id, $request->validated(), $request);

        return new SupportResource($this->access->ticket($request->user(), $id));
    }

    public function read(SupportRequest $request, int $id): JsonResponse
    {
        $this->workflow->read($request->user(), $id);

        return response()->json(['data' => ['read' => true]]);
    }

    public function phones(SupportRequest $request, int $id): JsonResponse
    {
        return response()->json(['data' => $this->workflow->phones($request->user(), $id)]);
    }

    public function savePhones(SupportRequest $request, int $id): JsonResponse
    {
        $this->workflow->savePhones($request->user(), $id, $request->validated(), $request);

        return $this->phones($request, $id);
    }

    public function upload(AttachmentRequest $request): JsonResponse
    {
        $kind = $request->input('kind');
        $actor = $request->user();
        $module = $kind === 'support' ? 'support' : 'notifications';
        $this->access->require($actor, $module.'.attach');
        $this->access->require($actor, $module.($kind === 'support' ? '.create' : '.send'));
        abort_if(SupportAttachment::where('user_id', $actor->id)->where('assigned', false)->where('expires_at', '>', now())->count() >= 20, 422, 'استخدم الصور المرفوعة قبل رفع المزيد.');
        $path = null;
        try {
            $image = DB::transaction(function () use ($request, $actor, $kind, &$path): SupportAttachment {
                app(MutationGuard::class)->lock($request->user());
                $file = $request->file('file');
                $path = $file->store('support/attachments/'.$actor->id, 'local');
                abort_unless(is_string($path) && $path !== '', 503, 'تعذر حفظ الصورة.');

                return SupportAttachment::create(['user_id' => $actor->id, 'kind' => $kind, 'path' => $path, 'mime' => $file->getMimeType(), 'bytes' => $file->getSize(), 'expires_at' => now()->addHour()]);
            });
        } catch (Throwable $error) {
            if (is_string($path)) {
                Storage::disk('local')->delete($path);
            } throw $error;
        }

        return response()->json(['data' => ['id' => $image->id, 'bytes' => $image->bytes, 'mime' => $image->mime]], 201);
    }

    public function attachment(Request $request, int $id, NotificationService $notices): StreamedResponse
    {
        $image = SupportAttachment::findOrFail($id);
        $actor = $request->user();
        $this->access->require($actor, $image->kind === 'support' ? 'support.view' : 'notifications.view');
        $canRead = (! $image->assigned && $image->user_id === $actor->id && $image->expires_at->isFuture()) || ($image->kind === 'support' ? $this->access->tickets($actor)->where('attachment_id', $id)->exists() : $notices->visible($actor)->where('attachment_id', $id)->exists());
        abort_unless($canRead && Storage::disk('local')->exists($image->path), 404);

        return Storage::disk('local')->response($image->path, 'attachment-'.$id.($image->mime === 'image/png' ? '.png' : '.jpg'), ['Content-Type' => $image->mime, 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}

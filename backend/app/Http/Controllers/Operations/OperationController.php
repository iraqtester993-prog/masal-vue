<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Http\Requests\Operations\OperationRequest;
use App\Http\Resources\Operations\OperationResource;
use App\Models\AccountAttachment;
use App\Models\AccountMembership;
use App\Models\Operations\AccountTimePolicy;
use App\Models\Operations\DirectStop;
use App\Models\User;
use App\Services\Operations\AccountTimeGuard;
use App\Services\Operations\ArchiveBlockers;
use App\Services\Operations\OperationAccess;
use App\Services\Operations\OperationGuard;
use App\Services\Operations\OperationWorkflow;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OperationController extends Controller
{
    public function __construct(private OperationAccess $access, private OperationWorkflow $workflow, private OperationGuard $guard) {}

    public function security(OperationRequest $request): JsonResponse
    {
        $actor = $request->user();
        $this->access->requireSecurity($actor);
        $accounts = $this->access->accounts($actor)->orderBy('id')->limit(10001)->get();
        abort_if($accounts->count() > 10000, 422, 'ضيّق نطاق الحسابات إلى 10000 أو أقل.');
        $rules = $this->access->stops($actor)->where('active', true)->orderByDesc('id')->limit(1001)->get();
        abort_if($rules->count() > 1000, 422, 'تجاوز عدد قرارات التوقيف حد العرض.');
        $direct = DirectStop::whereIn('account_id', $accounts->pluck('id'))->get()->keyBy('account_id');
        $restrictions = $this->guard->restrictions($accounts);
        $rows = $accounts->map(function ($account) use ($direct, $restrictions): array {
            $stops = $restrictions[$account->id];

            return ['id' => $account->id, 'name' => $account->name, 'type' => $account->type->value, 'status' => $account->status, 'stops' => $stops, 'direct_stops' => $direct->get($account->id)?->stops ?? [], 'direct_version' => $direct->get($account->id)?->version ?? 0];
        });
        $restricted = $rows->filter(fn ($row) => count($row['stops']) > 0);
        if ($query = trim($request->input('query', ''))) {
            $restricted = $restricted->filter(fn ($row) => mb_stripos($row['name'], $query) !== false);
        }
        $page = $request->integer('page', 1);
        $perPage = $request->integer('per_page', 10);
        $total = $restricted->count();
        $global = $this->access->canRestoreGlobal($actor) ? DirectStop::find($actor->membership->account_id) : null;

        return response()->json(['data' => ['accounts' => $rows->map(fn ($a) => array_intersect_key($a, array_flip(['id', 'name', 'type'])))->values()->all(), 'active_stops' => OperationResource::collection($rules)->resolve($request), 'restricted' => $restricted->slice(($page - 1) * $perPage, $perPage)->values()->all(), 'restricted_total' => $rows->filter(fn ($row) => count($row['stops']) > 0)->count(), 'legacy_global' => $global ? ['stops' => $global->stops, 'version' => $global->version] : null, 'can_manage' => in_array('security.policies', $actor->membership->permissions(), true)], 'meta' => ['current_page' => $page, 'last_page' => max(1, (int) ceil($total / $perPage)), 'total' => $total, 'per_page' => $perPage]]);
    }

    public function createStop(OperationRequest $request): OperationResource
    {
        $result = $this->workflow->createStop($request->user(), $request->validated(), $request);

        return new OperationResource($this->access->stops($request->user())->findOrFail($result['id']));
    }

    public function resume(OperationRequest $request, int $id): OperationResource
    {
        $this->workflow->resume($request->user(), $id, $request->validated(), $request);

        return new OperationResource($this->access->stops($request->user())->findOrFail($id));
    }

    public function direct(OperationRequest $request, int $id): JsonResponse
    {
        $result = $this->workflow->direct($request->user(), $id, $request->validated(), $request);
        $record = DirectStop::findOrFail($result['account_id']);

        return response()->json(['data' => ['account_id' => $record->account_id, 'stops' => $record->stops, 'version' => $record->version]]);
    }

    public function restoreGlobal(OperationRequest $request): JsonResponse
    {
        $this->workflow->direct($request->user(), $request->user()->membership->account_id, $request->validated(), $request, true);

        return response()->json(['data' => ['restored' => true]]);
    }

    public function times(OperationRequest $request): JsonResponse
    {
        $actor = $request->user();
        $this->access->requireSecurity($actor, false, true);
        $users = User::whereIn('id', AccountMembership::where('kind', 'employee')->select('user_id'))->whereHas('membership.account', fn ($q) => $q->whereNull('archived_at'))->orderBy('id')->limit(10001)->get(['id', 'name', 'login', 'email', 'status']);
        abort_if($users->count() > 10000, 422, 'تجاوز عدد المستخدمين حد العرض.');
        $policies = AccountTimePolicy::whereIn('user_id', $users->pluck('id'))->get()->keyBy('user_id');

        return response()->json(['data' => ['users' => $users->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'login' => $u->login ?? $u->email, 'status' => $u->status, 'policy' => $policies->get($u->id)?->policy, 'version' => $policies->get($u->id)?->version ?? 0])->all()]]);
    }

    public function time(OperationRequest $request, int $id): JsonResponse
    {
        $user = $this->workflow->timeUser($request->user(), $id);
        $record = AccountTimePolicy::find($id);

        return response()->json(['data' => ['user_id' => $id, 'name' => $user->name, 'policy' => $record?->policy ?? AccountTimeGuard::emptyPolicy(), 'version' => $record?->version ?? 0]]);
    }

    public function saveTime(OperationRequest $request, int $id): JsonResponse
    {
        $this->workflow->saveTime($request->user(), $id, $request->validated(), $request);

        return $this->time($request, $id);
    }

    public function activity(OperationRequest $request, AccountTimeGuard $time): JsonResponse
    {
        $time->enforce($request->user(), $request, true);

        return response()->json(['data' => ['active' => true]]);
    }

    public function archives(OperationRequest $request): JsonResponse
    {
        $q = $this->access->archives($request->user());
        if ($term = trim($request->input('query', ''))) {
            $id = ctype_digit($term) ? $term : null;
            $members = $this->access->archiveMembers($request->user())->join('users', 'users.id', '=', 'user_id');
            $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term).'%';
            $q->where(function ($q) use ($term, $members, $id): void {
                $q->whereRaw("reason LIKE ? ESCAPE '!'", [$term])->orWhereIn('account_id', DB::table('accounts')->whereRaw("name LIKE ? ESCAPE '!'", [$term])->select('id'))->orWhereIn('account_id', $members->whereRaw("email LIKE ? ESCAPE '!'", [$term])->select('account_memberships.account_id'));
                if ($id !== null) {
                    $q->orWhere('account_id', $id);
                }
            });
        }
        $page = $q->orderByDesc('id')->paginate($request->integer('per_page', 20));

        return response()->json(['data' => OperationResource::collection($page->getCollection())->resolve($request), 'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()]]);
    }

    public function archive(OperationRequest $request, int $id): OperationResource
    {
        return new OperationResource($this->access->archives($request->user())->findOrFail($id));
    }

    public function eligibility(OperationRequest $request, int $id, ArchiveBlockers $blockers): JsonResponse
    {
        $account = $this->access->archiveTarget($request->user(), $id);
        $reasons = $account->archived_at ? ['الحساب مؤرشف بالفعل.'] : $blockers->reasons($account);

        return response()->json(['data' => ['account_id' => $id, 'name' => $account->name, 'type' => $account->type->value, 'version' => $account->version, 'eligible' => ! $reasons, 'blockers' => $reasons]]);
    }

    public function image(OperationRequest $request, int $id): StreamedResponse
    {
        $archive = $this->access->archives($request->user())->findOrFail($id);
        $image = AccountAttachment::where('account_id', $archive->account_id)->whereIn('kind', ['agent_image', 'personal_image'])->findOrFail($archive->before['image_id'] ?? 0);
        $extension = match ($image->mime_type) {
            'image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', default => null
        };
        abort_unless($extension && Storage::disk('local')->exists($image->storage_path), 404);

        return Storage::disk('local')->response($image->storage_path, 'archive-photo-'.$image->id.'.'.$extension, ['Content-Type' => $image->mime_type, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }

    public function createArchive(OperationRequest $request, int $id): JsonResponse
    {
        return response()->json(['data' => $this->workflow->archive($request->user(), $id, $request->validated(), $request)]);
    }
}

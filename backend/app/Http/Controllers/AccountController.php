<?php

namespace App\Http\Controllers;

use App\Http\Requests\AccountsQueryRequest;
use App\Http\Requests\StoreAccountRequest;
use App\Http\Resources\AccountResource;
use App\Models\Account;
use App\Services\AccountScope;
use App\Services\AuditLogger;
use App\Services\CreateAccount;
use App\Services\Operations\MutationGuard;
use App\Services\ReferenceAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class AccountController extends Controller
{
    public function index(AccountsQueryRequest $request, AccountScope $scope): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Account::class);
        $validated = $request->validated();

        $base = $scope->query($request->user());
        $summary = (clone $base)->selectRaw('type, count(*) as total')->groupBy('type')->pluck('total', 'type');
        $query = clone $base;
        if (isset($validated['ancestor_id'])) {
            $query->whereIn('id', DB::table('account_closure')->select('descendant_id')->where('ancestor_id', $validated['ancestor_id']));
        }
        foreach (['type', 'status', 'city', 'parent_id'] as $field) {
            if (isset($validated[$field])) {
                $query->where($field, $validated[$field]);
            }
        }
        if (isset($validated['kind'])) {
            $query->whereIn('type', $validated['kind'] === 'agents' ? ['main_agent', 'sub_agent', 'sub_branch'] : ['pos']);
        }
        if (! empty($validated['q'])) {
            $q = $validated['q'];
            $query->where(fn ($builder) => $builder->where('name', 'like', '%'.$q.'%')->orWhere('phone', 'like', '%'.$q.'%')->orWhere('serial', 'like', '%'.$q.'%')
                ->orWhere('city', 'like', '%'.$q.'%')->orWhere('owner_name', 'like', '%'.$q.'%')
                ->orWhereIn('parent_id', (clone $base)->where('name', 'like', '%'.$q.'%')->select('accounts.id')));
        }
        $page = $query->orderBy('id')->paginate($validated['per_page'] ?? 25)->withQueryString();
        $ids = $page->getCollection()->modelKeys();
        $parentIds = $page->getCollection()->pluck('parent_id')->filter()->unique()->values()->all();
        $parents = (clone $base)->whereIn('id', $parentIds)->get(['id', 'name', 'type'])->keyBy('id');
        $children = (clone $base)->whereIn('parent_id', $ids)->select('parent_id')
            ->selectRaw('count(*) as total')->groupBy('parent_id')->pluck('total', 'parent_id');
        $networkPos = DB::table('account_closure')->whereIn('ancestor_id', $ids)->where('depth', '>', 0)
            ->whereIn('descendant_id', (clone $base)->where('type', 'pos')->select('accounts.id'))
            ->select('ancestor_id')->selectRaw('count(*) as total')->groupBy('ancestor_id')->pluck('total', 'ancestor_id');
        $representatives = DB::table('pos_representatives as assigned')
            ->joinSub(app(ReferenceAccess::class)->representatives($request->user())->select('network_representatives.id', 'network_representatives.name'), 'visible', 'visible.id', '=', 'assigned.representative_id')
            ->whereIn('assigned.account_id', $ids)->orderBy('visible.id')->get(['assigned.account_id', 'visible.name'])
            ->groupBy('account_id')->map(fn ($rows): array => $rows->pluck('name')->all());
        app(Request::class)->attributes->set('account_list_context', compact('parents', 'children', 'networkPos', 'representatives'));

        return AccountResource::collection($page)
            ->additional(['meta' => ['summary' => collect(['main_agent', 'sub_agent', 'sub_branch', 'pos'])->mapWithKeys(fn (string $type): array => [$type => (int) ($summary[$type] ?? 0)])->all()]]);
    }

    public function show(Request $request, int $id, AccountScope $scope): AccountResource
    {
        Gate::authorize('viewAny', Account::class);

        return new AccountResource($scope->query($request->user())->findOrFail($id));
    }

    public function store(StoreAccountRequest $request, CreateAccount $create, AuditLogger $audit): JsonResponse
    {
        $created = DB::transaction(function () use ($request, $create, $audit): array {
            app(MutationGuard::class)->lock($request->user(), [], 'account.create');
            $created = $create->execute($request->user(), $request->validated());
            $audit->record('account.create', $request, $request->user(), $created['account']->id);

            return $created;
        });
        $user = $created['user'];

        return response()->json(['data' => [
            'account' => (new AccountResource($created['account']))->resolve($request),
            'user' => ['id' => $user->id, 'name' => $user->name, 'login' => $user->login, 'email' => $user->email],
        ]], 201);
    }
}

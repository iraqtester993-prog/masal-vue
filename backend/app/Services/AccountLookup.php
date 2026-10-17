<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AccountLookup
{
    public function __construct(private AccountScope $scope) {}

    public function query(Request $request): Builder
    {
        $query = $this->scope->query($request->user());
        foreach (['account_city' => 'city', 'account_type' => 'type', 'parent_id' => 'parent_id'] as $field => $column) {
            if ($request->filled($field)) {
                $query->where($column, $request->input($field));
            }
        }
        if ($request->filled('account_status')) {
            $query->where('status', $request->input('account_status') === 'active' ? '=' : '<>', 'active');
        }
        if ($request->filled('ancestor_id')) {
            $query->whereIn('id', DB::table('account_closure')->where('ancestor_id', $request->integer('ancestor_id'))->select('descendant_id'));
        }
        if ($request->filled('exclude_account_id')) {
            $query->where('id', '<>', $request->integer('exclude_account_id'));
        }
        if ($request->filled('account_query')) {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $request->input('account_query')).'%';
            $query->where(fn ($search) => $search->whereRaw("name LIKE ? ESCAPE '!'", [$pattern])->orWhereRaw("phone LIKE ? ESCAPE '!'", [$pattern]));
        }

        return $query;
    }

    public function page(Request $request): JsonResponse
    {
        $query = $this->query($request);
        if ($request->has('account_types')) {
            $query->whereIn('type', $request->input('account_types'));
        }
        if ($request->has('lookup_ids')) {
            $query->whereIn('id', $request->input('lookup_ids'));
        }
        if ($request->filled('account_id')) {
            $query->whereKey($request->integer('account_id'));
        }
        $page = $query->orderBy('id')->paginate($request->integer('per_page', 25), ['id', 'name', 'type', 'parent_id', 'phone', 'city', 'status']);
        $parents = $this->scope->query($request->user())->whereIn('id', $page->pluck('parent_id')->filter())->pluck('name', 'id');
        $rows = $page->getCollection()->map(fn ($row) => array_merge($row->toArray(), ['parent_name' => $parents[$row->parent_id] ?? null]));

        return response()->json(['data' => $rows, 'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(), 'per_page' => $page->perPage()]]);
    }
}

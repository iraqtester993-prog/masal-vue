<?php

namespace App\Services\Digital;

use App\Models\User;
use App\Services\AccountScope;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DigitalTotals
{
    public function __construct(private AccountScope $scope) {}

    private function query(User $actor, bool $productFiltered = false): Builder
    {
        return DB::table(($productFiltered ? 'digital_order_totals' : 'digital_account_totals').' as r')->whereIn('r.account_id', $this->scope->query($actor)->select('accounts.id'));
    }

    private function aggregate(Builder $query): object
    {
        return $query->selectRaw("COALESCE(SUM(r.order_count),0) AS total,COALESCE(SUM(CASE WHEN r.status='succeeded' THEN r.order_count ELSE 0 END),0) AS quantity,COALESCE(SUM(CASE WHEN r.status='succeeded' THEN r.retail_minor ELSE 0 END),0) AS retail,COALESCE(SUM(CASE WHEN r.status='succeeded' THEN r.cost_minor ELSE 0 END),0) AS cost,COALESCE(SUM(CASE WHEN r.status='succeeded' AND r.cost_basis='provider_response' THEN r.cost_minor ELSE 0 END),0) AS confirmed_cost,COALESCE(SUM(r.unknown_cost_count),0) AS unknown_cost,COALESCE(SUM(r.catalog_cost_count),0) AS catalog_cost_count,COALESCE(SUM(CASE WHEN r.status IN ('pending','review') THEN r.order_count ELSE 0 END),0) AS pending,COALESCE(SUM(CASE WHEN r.status='refunded' THEN r.order_count ELSE 0 END),0) AS refunds")->first();
    }

    public function dashboard(User $actor, string $provider): object
    {
        return $this->aggregate($this->query($actor)->where('r.provider', $provider));
    }

    public function summary(Request $request): object
    {
        $query = $this->query($request->user(), $request->filled('product_id'));
        foreach (['provider', 'product_id', 'status', 'account_id', 'main_account_id'] as $field) {
            if ($request->filled($field)) {
                $query->where('r.'.$field, $request->input($field));
            }
        }

        return $this->aggregate($query);
    }
}

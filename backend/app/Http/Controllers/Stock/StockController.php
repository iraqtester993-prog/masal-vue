<?php

namespace App\Http\Controllers\Stock;

use App\Http\Controllers\Controller;
use App\Http\Requests\Stock\StockRequest;
use App\Http\Resources\Stock\StockResource;
use App\Models\CatalogProduct;
use App\Models\CatalogProvider;
use App\Models\OperatingGovernorate;
use App\Models\OrderSource;
use App\Models\Stock\StockAdjustment;
use App\Models\Stock\StockBatch;
use App\Models\Stock\StockCard;
use App\Models\Stock\StockClaim;
use App\Models\Stock\StockOrder;
use App\Models\Stock\StockWithdrawal;
use App\Services\CatalogAccess;
use App\Services\Finance\Money;
use App\Services\Stock\StockAccess;
use App\Services\Stock\StockActions;
use App\Services\Stock\StockImport;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class StockController extends Controller
{
    public function options(StockRequest $request, StockAccess $access, CatalogAccess $catalog): JsonResponse
    {
        $access->requireAnyView($request->user());
        $accounts = $access->accounts($request->user())->where('status', 'active')->orderBy('id')->limit(10001)->get(['id', 'name', 'city', 'status']);
        $sources = OrderSource::whereIn('network_account_id', $access->accounts($request->user())->select('accounts.id'))->where('status', 'active')->orderBy('id')->limit(10001)->get(['id', 'name', 'provider_id', 'network_account_id']);
        $products = $catalog->products($request->user())->where('status', 'active')->orderBy('display_order')->orderBy('id')->limit(10001)->get(['id', 'name', 'provider_id', 'currency', 'import_codes', 'field_policy', 'extra_fields', 'allowed_cities']);
        abort_if(max($accounts->count(), $sources->count(), $products->count()) > 10000, 422, 'عدد خيارات الطلبية يتجاوز الحد؛ استخدم الفلاتر.');
        $permissions = $request->user()->membership->permissions();
        $prices = in_array('data.cost', $permissions, true) && count(array_intersect(['import.view', 'prices.view'], $permissions)) ? DB::table('finance_prices')->whereIn('account_id', $accounts->pluck('id'))->whereIn('product_id', $products->pluck('id'))->limit(10001)->get(['account_id', 'product_id', 'currency', 'price_minor', 'version']) : collect();
        $providers = CatalogProvider::where('status', 'active')->orderBy('id')->limit(10001)->get(['id', 'name']);
        abort_if(max($prices->count(), $providers->count()) > 10000, 422, 'عدد خيارات الطلبية يتجاوز الحد؛ استخدم الفلاتر.');
        $prices = $prices->map(fn ($row): array => ['account_id' => (int) $row->account_id, 'product_id' => (int) $row->product_id, 'currency' => $row->currency, 'price' => Money::decimal($row->price_minor), 'version' => (int) $row->version]);

        return response()->json(['data' => ['accounts' => $accounts, 'sources' => $sources, 'products' => $products, 'providers' => $providers, 'cities' => OperatingGovernorate::where('active', true)->orderBy('id')->pluck('name'), 'prices' => $prices]], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function preview(StockRequest $request, StockImport $import): JsonResponse
    {
        $result = $import->preview($request->user(), $request->validated());
        if (! in_array('data.cost', $request->user()->membership->permissions(), true)) {
            unset($result['amounts']);
            foreach ($result['lines'] as &$line) {
                unset($line['load_price'], $line['amount'], $line['cost_total']);
            }
            unset($line);
        }
        if (! in_array('data.pin', $request->user()->membership->permissions(), true)) {
            foreach ($result['lines'] as &$line) {
                foreach ($line['checked'] as &$row) {
                    unset($row['pin'], $row['cvc'], $row['reference'], $row['extra_fields']);
                }
                unset($row);
            }
            unset($line);
            foreach ($result['draft']['lines'] as &$line) {
                foreach ($line['rows'] as &$row) {
                    unset($row['pin'], $row['cvc'], $row['reference'], $row['extra_fields']);
                }
                unset($row);
            }
            unset($line);
        }

        return response()->json(['data' => $result], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function submit(StockRequest $request, StockImport $import): StockResource
    {
        return new StockResource($import->submit($request->user(), $request->validated(), $request)->load(['account', 'provider', 'source', 'batches']));
    }

    public function resubmit(StockRequest $request, int $id, StockImport $import): StockResource
    {
        return new StockResource($import->submit($request->user(), $request->validated(), $request, $id)->load(['account', 'provider', 'source', 'batches']));
    }

    public function review(StockRequest $request, int $id, StockImport $import): StockResource
    {
        return new StockResource($import->review($request->user(), $id, $request->validated(), $request)->load(['account', 'provider', 'source', 'batches']));
    }

    private function filtered(StockRequest $request, Builder $query): Builder
    {
        $withdrawalGroup = $query->getModel() instanceof StockWithdrawal && in_array($request->input('status'), ['active', 'history'], true);
        $activeBatches = $query->getModel() instanceof StockBatch && $request->input('status') === 'active';
        if ($activeBatches) {
            $query->whereIn('status', ['Loaded', 'Partially Used']);
        }
        if ($withdrawalGroup) {
            $query->whereIn('status', $request->input('status') === 'active' ? ['pending', 'approved'] : ['downloaded', 'rejected', 'cancelled']);
        }
        foreach (['account_id', 'product_id', 'status'] as $field) {
            if ($field === 'status' && ($withdrawalGroup || $activeBatches)) {
                continue;
            }
            if ($request->filled($field) && ($field !== 'product_id' || $query->getModel() instanceof StockBatch || $query->getModel() instanceof StockCard)) {
                $query->where($field, $request->input($field));
            }
        }
        if ($request->filled('provider_id')) {
            if ($query->getModel() instanceof StockOrder) {
                $query->where('provider_id', $request->integer('provider_id'));
            } elseif ($query->getModel() instanceof StockBatch || $query->getModel() instanceof StockCard) {
                $query->whereIn('product_id', CatalogProduct::where('provider_id', $request->integer('provider_id'))->select('id'));
            } else {
                $query->whereHas('batch.product', fn ($product) => $product->where('provider_id', $request->integer('provider_id')));
            }
        }
        if ($request->filled('product_id') && ! ($query->getModel() instanceof StockBatch || $query->getModel() instanceof StockCard)) {
            if ($query->getModel() instanceof StockOrder) {
                $query->whereJsonContains('summary->product_ids', $request->integer('product_id'));
            } else {
                $query->whereHas('batch', fn ($batch) => $batch->where('product_id', $request->integer('product_id')));
            }
        }
        if ($request->filled('query')) {
            $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $request->input('query')).'%';
            $query->where(function ($search) use ($term, $query): void {
                $search->whereRaw("CAST(id AS CHAR) LIKE ? ESCAPE '!'", [$term]);
                if ($query->getModel() instanceof StockBatch) {
                    $search->orWhereRaw("file_name LIKE ? ESCAPE '!'", [$term])->orWhereRaw("supplier LIKE ? ESCAPE '!'", [$term])->orWhereHas('product', fn ($product) => $product->whereRaw("name LIKE ? ESCAPE '!'", [$term]));
                } elseif ($query->getModel() instanceof StockCard) {
                    $search->orWhereRaw("serial LIKE ? ESCAPE '!'", [$term]);
                } elseif ($query->getModel() instanceof StockOrder) {
                    $search->orWhereHas('account', fn ($account) => $account->whereRaw("name LIKE ? ESCAPE '!'", [$term]))
                        ->orWhereHas('source', fn ($source) => $source->whereRaw("name LIKE ? ESCAPE '!'", [$term]))
                        ->orWhereRaw("CAST(summary AS CHAR) LIKE ? ESCAPE '!'", [$term]);
                } else {
                    $search->orWhereRaw("reason LIKE ? ESCAPE '!'", [$term])->orWhereHas('batch.product', fn ($product) => $product->whereRaw("name LIKE ? ESCAPE '!'", [$term]));
                }
            });
        }
        if ($request->filled('from')) {
            $query->where('created_at', '>=', CarbonImmutable::parse($request->input('from'), 'Asia/Baghdad')->startOfDay()->utc());
        }
        if ($request->filled('to')) {
            $query->where('created_at', '<', CarbonImmutable::parse($request->input('to'), 'Asia/Baghdad')->startOfDay()->addDay()->utc());
        }

        return $query;
    }

    private function page(StockRequest $request, Builder $query): JsonResponse
    {
        $rows = $this->filtered($request, $query)->orderByDesc('id')->paginate($request->integer('per_page', 20));

        return response()->json(['data' => StockResource::collection($rows)->resolve($request), 'meta' => ['page' => $rows->currentPage(), 'per_page' => $rows->perPage(), 'last_page' => $rows->lastPage(), 'total' => $rows->total()]], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function orders(StockRequest $request, StockAccess $access): JsonResponse
    {
        $access->require($request->user(), 'import.view');

        return $this->page($request, $access->orders($request->user())->select(['id', 'account_id', 'provider_id', 'source_id', 'creator_id', 'city', 'status', 'summary', 'reason', 'quantity', 'rejected', 'version', 'reviewed_at', 'created_at', 'updated_at'])->with(['account', 'provider', 'source', 'batches']));
    }

    public function showOrder(StockRequest $request, int $id, StockAccess $access): StockResource
    {
        $access->require($request->user(), 'import.view');

        return new StockResource($access->orders($request->user())->with(['account', 'provider', 'source', 'batches'])->findOrFail($id));
    }

    private function batchQuery(StockAccess $access, StockRequest $request): Builder
    {
        $query = $access->batches($request->user())->with(['account', 'product', 'source']);
        $today = now('Asia/Baghdad')->toDateString();
        foreach (['available_count' => ['Available'], 'sold_count' => ['Sold', 'Issued', 'Printed', 'Reprinted', 'Print Failed', 'Delivered'], 'damaged_count' => ['Quarantined', 'Compensated', 'Replaced', 'Written Off'], 'cancelled_count' => ['Cancelled by Reversal'], 'exported_count' => ['Exported'], 'reserved_count' => ['Reserved']] as $alias => $states) {
            $query->withCount(['cards as '.$alias => function ($cards) use ($states, $alias, $today): void {
                $cards->whereIn('status', $states);
                if ($alias === 'available_count') {
                    $cards->where('credit_held', false)->whereNull('sale_id')->where('expiry', '>', $today);
                }
            }]);
        }
        $query->withMin('cards', 'expiry')->withMax('cards', 'expiry');

        return $query;
    }

    public function summary(StockRequest $request, StockAccess $access): JsonResponse
    {
        $access->require($request->user(), 'inventory.view');
        $batches = $this->filtered($request, $access->batches($request->user()))->select('stock_batches.id');
        $costs = in_array('data.cost', $request->user()->membership->permissions(), true);
        $salable = "stock_cards.status = 'Available' AND stock_cards.credit_held = 0 AND stock_cards.sale_id IS NULL AND stock_cards.expiry > ?";
        $today = now('Asia/Baghdad')->toDateString();
        $query = StockCard::whereIn('batch_id', $batches)->join('stock_batches', 'stock_batches.id', '=', 'stock_cards.batch_id')
            ->selectRaw("stock_batches.currency, SUM(CASE WHEN {$salable} THEN 1 ELSE 0 END) AS available_count, SUM(CASE WHEN stock_cards.status = 'Quarantined' THEN 1 ELSE 0 END) AS quarantined_count, SUM(CASE WHEN stock_cards.status = 'Exported' THEN 1 ELSE 0 END) AS exported_count", [$today]);
        if ($costs) {
            $query->selectRaw("SUM(CASE WHEN {$salable} THEN stock_cards.cost_minor ELSE 0 END) AS available_cost_minor", [$today]);
        }
        $groups = $query->groupBy('stock_batches.currency')->get();
        $result = ['available_count' => (int) $groups->sum('available_count'), 'quarantined_count' => (int) $groups->sum('quarantined_count'), 'exported_count' => (int) $groups->sum('exported_count')];
        if ($costs) {
            $result['available_cost_by_currency'] = [];
            foreach ($groups as $group) {
                $amount = (string) $group->available_cost_minor;
                abort_if(! ctype_digit($amount) || strlen($amount) > strlen((string) Money::MAX_MINOR) || (int) $amount > Money::MAX_MINOR, 422, 'إجمالي تكلفة المخزون يتجاوز الحد المسموح؛ حدد وكيلًا أو فئة.');
                $result['available_cost_by_currency'][$group->currency] = Money::decimal((int) $amount);
            }
        }

        return response()->json(['data' => $result], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function batches(StockRequest $request, StockAccess $access): JsonResponse
    {
        $access->require($request->user(), 'inventory.view');

        return $this->page($request, $this->batchQuery($access, $request));
    }

    public function batch(StockRequest $request, int $id, StockAccess $access): StockResource
    {
        $access->require($request->user(), 'inventory.details');

        return new StockResource($this->batchQuery($access, $request)->findOrFail($id));
    }

    public function cards(StockRequest $request, int $id, StockAccess $access): JsonResponse
    {
        $access->require($request->user(), 'inventory.details');
        $access->batches($request->user())->findOrFail($id);

        return $this->page($request, StockCard::where('batch_id', $id));
    }

    public function selection(StockRequest $request, int $id, StockAccess $access): JsonResponse
    {
        $access->require($request->user(), 'inventory.details');
        $access->batches($request->user())->findOrFail($id);
        $query = $this->filtered($request, StockCard::where('batch_id', $id));
        if ($request->input('mode') === 'remaining') {
            $query->whereNull('sale_id')->whereIn('status', ['Available', 'Quarantined']);
        }
        $ids = $query->orderBy('id')->limit(50001)->pluck('id');
        abort_if($ids->count() > 50000, 422, 'حدد فلاتر إضافية؛ الحد الأقصى 50000 بطاقة.');

        return response()->json(['data' => ['ids' => $ids->map(fn ($id): int => (int) $id)->all(), 'total' => $ids->count()]], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function batchAction(StockRequest $request, int $id, StockActions $actions, StockAccess $access): StockResource
    {
        $actions->batch($request->user(), $id, $request->validated(), $request);

        return new StockResource($this->batchQuery($access, $request)->findOrFail($id));
    }

    public function previewAction(StockRequest $request, int $id, StockActions $actions): JsonResponse
    {
        return response()->json(['data' => $actions->preview($request->user(), $id, $request->validated())], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function claim(StockRequest $request, int $id, StockActions $actions): StockResource
    {
        return new StockResource($actions->claim($request->user(), $id, $request->validated(), $request)->load(['batch.product', 'batch.account']));
    }

    public function settleClaim(StockRequest $request, int $id, StockActions $actions): StockResource
    {
        return new StockResource($actions->settle($request->user(), $id, $request->validated(), $request)->load(['batch.product', 'batch.account']));
    }

    public function claims(StockRequest $request, StockAccess $access): JsonResponse
    {
        $access->require($request->user(), 'claims.view');

        return $this->page($request, StockClaim::whereIn('account_id', $access->accounts($request->user())->select('accounts.id'))->where('purpose', 'damage')->with(['batch.product', 'batch.account']));
    }

    public function adjustments(StockRequest $request, StockAccess $access): JsonResponse
    {
        $access->require($request->user(), 'inventory.view');

        return $this->page($request, StockAdjustment::whereIn('account_id', $access->accounts($request->user())->select('accounts.id'))->when($request->filled('batch_id'), fn ($query) => $query->where('batch_id', $request->integer('batch_id')))->with(['batch.product', 'batch.account']));
    }

    public function withdrawals(StockRequest $request, StockAccess $access): JsonResponse
    {
        $access->require($request->user(), 'exports.view');

        return $this->page($request, StockWithdrawal::whereIn('account_id', $access->accounts($request->user())->select('accounts.id'))->with(['batch.product', 'batch.account']));
    }

    public function requestWithdrawal(StockRequest $request, StockActions $actions): StockResource
    {
        return new StockResource($actions->withdrawal($request->user(), $request->validated(), $request)->load(['batch.product', 'batch.account']));
    }

    public function reviewWithdrawal(StockRequest $request, int $id, StockActions $actions): StockResource
    {
        return new StockResource($actions->reviewWithdrawal($request->user(), $id, $request->validated(), $request)->load(['batch.product', 'batch.account']));
    }

    public function downloadWithdrawal(StockRequest $request, int $id, StockActions $actions): JsonResponse
    {
        return response()->json(['data' => $actions->download($request->user(), $id, $request->validated(), $request)], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function copy(StockRequest $request, int $id, StockActions $actions): JsonResponse
    {
        return response()->json(['data' => $actions->copy($request->user(), $id, $request->validated(), $request)], 200, ['Cache-Control' => 'private, no-store']);
    }
}

<?php

namespace App\Http\Controllers\Digital;

use App\Enums\AccountType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Digital\DigitalHistoryRequest;
use App\Http\Requests\Digital\DigitalRequest;
use App\Http\Resources\Digital\DigitalResource;
use App\Http\Resources\Sales\SaleResource;
use App\Models\CatalogProvider;
use App\Models\Digital\DigitalConnection;
use App\Models\Digital\DigitalOffer;
use App\Models\Digital\DigitalOrder;
use App\Models\Sales\Sale;
use App\Services\AuditLogger;
use App\Services\Digital\DigitalAccess;
use App\Services\Digital\DigitalConfiguration;
use App\Services\Digital\DigitalOperations;
use App\Services\Digital\DigitalTotals;
use App\Services\Digital\ProviderGateway;
use App\Services\Digital\TopupAccounting;
use App\Services\Digital\TopupCatalog;
use App\Services\Digital\TopupDistribution;
use App\Services\Digital\TopupWallets;
use App\Services\Finance\Money;
use App\Services\Operations\MutationGuard;
use App\Support\RequestReadCache;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DigitalController extends Controller
{
    public function offerImage(Request $request, int $id): BinaryFileResponse
    {
        $actor = $request->user();
        $own = $this->access->own($actor, 'digital.view');
        $offer = DigitalOffer::with('product', 'connection')->findOrFail($id);
        if ($own->type === AccountType::System) {
            $this->access->connections($actor)->findOrFail($offer->connection_id);
        } else {
            abort_unless($this->access->offers($offer->connection, $own)->contains('id', $offer->id), 404);
        }
        abort_unless($offer->product->image_path && Storage::disk('local')->exists($offer->product->image_path), 404);

        return response()->file(Storage::disk('local')->path($offer->product->image_path), ['Content-Type' => $offer->product->image_mime, 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function __construct(private DigitalAccess $access, private DigitalConfiguration $configuration, private DigitalOperations $operations, private ProviderGateway $gateway, private AuditLogger $audit) {}

    private function response(array $data, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $data], $status, ['Cache-Control' => 'private, no-store']);
    }

    public function options(DigitalRequest $request): JsonResponse
    {
        $actor = $request->user();
        $this->access->require($actor, in_array('digital.view', $actor->membership->permissions(), true) ? 'digital.view' : 'integrations.view');
        $accounts = $this->access->accounts($actor)->when($request->boolean('compact'), fn ($query) => $query->whereKey($actor->membership->account_id))->get(['id', 'name', 'type', 'parent_id', 'status']);
        $productQuery = $this->access->catalog->products($actor)->where('status', 'active')->where('currency', 'IQD')->whereHas('provider', fn ($query) => $query->where('status', 'active'));
        if ($request->filled('main_account_id')) {
            $this->access->owner($actor);
            $main = $this->access->accounts($actor)->where('type', AccountType::MainAgent)->findOrFail($request->integer('main_account_id'));
            $productQuery->whereIn('catalog_products.id', $this->access->catalog->forAccount($main->id)->select('catalog_products.id'));
        }
        $products = $productQuery->orderBy('display_order')->get(['id', 'name', 'provider_id', 'currency', 'minimum_price']);

        return $this->response(['accounts' => $accounts->map(fn ($a): array => ['id' => $a->id, 'name' => $a->name, 'type' => $a->type->value, 'parent_id' => $a->parent_id, 'status' => $a->status])->all(), 'products' => $products->toArray(), 'providers' => CatalogProvider::where('status', 'active')->get(['id', 'name', 'connection'])->toArray(), 'own_account_id' => $actor->membership->account_id, 'gateway' => $this->gateway->status(), 'gateways' => ['rabiaa' => $this->gateway->status(new DigitalConnection(['provider' => 'rabiaa']), false), 'topup' => $this->gateway->status(new DigitalConnection(['provider' => 'topup']), false)], 'financial_policy' => 'provider_only', 'provider_names' => ['rabiaa' => 'الرابعة', 'topup' => 'Topup']]);
    }

    public function connections(DigitalRequest $request): JsonResponse
    {
        $actor = $request->user();
        $this->access->require($actor, in_array('digital.view', $actor->membership->permissions(), true) ? 'digital.view' : 'integrations.view');
        $query = $this->access->connections($actor)->with(['account', 'company', 'offers.product', 'grants.target']);
        if ($request->filled('provider')) {
            $query->where('provider', $request->input('provider'));
        }
        if ($request->filled('main_account_id')) {
            $this->access->accounts($actor)->where('type', AccountType::MainAgent)->findOrFail($request->integer('main_account_id'));
            $query->where(fn ($q) => $q->where('account_id', $request->integer('main_account_id'))->orWhereIn('id', DB::table('topup_grants')->where('target_account_id', $request->integer('main_account_id'))->select('connection_id')));
        }
        $perPage = $request->integer('per_page', 25);
        $balance = null;
        if ($actor->membership->account->type === AccountType::System) {
            $balances = (clone $query)->where('provider', 'topup')->toBase()->selectRaw('COUNT(*) AS total, COUNT(company_balance_minor) AS known, SUM(company_balance_minor) AS amount, MIN(balance_updated_at) AS oldest_balance')->first();
            $balance = ['updated_at' => $balances->oldest_balance ? CarbonImmutable::parse($balances->oldest_balance)->toISOString() : null, 'total' => (int) $balances->total, 'known' => (int) $balances->known, 'amount' => $balances->total && $balances->total === $balances->known ? $this->aggregate($balances->amount) : null];
            $rows = $query->orderBy('id')->paginate($perPage);
        } else {
            // Only the two provider connections at this account's main ancestor can exist.
            $visible = $query->orderBy('id')->get()->filter(fn (DigitalConnection $c): bool => $c->account_id === $actor->membership->account_id || $this->access->offers($c, $actor->membership->account, true)->isNotEmpty())->values();
            $rows = new LengthAwarePaginator($visible->forPage($request->integer('page', 1), $perPage)->values(), $visible->count(), $perPage, $request->integer('page', 1));
            if ($actor->membership->account->type === AccountType::MainAgent && $this->access->accounts($actor)->whereKey($actor->membership->account_id)->exists()) {
                $balances = $visible->where('provider', 'topup');
                $known = $balances->filter(fn ($row): bool => $row->company_balance_minor !== null);
                $balance = ['updated_at' => $known->min('balance_updated_at')?->toISOString(), 'total' => $balances->count(), 'known' => $known->count(), 'amount' => $balances->isNotEmpty() && $balances->count() === $known->count() ? $this->aggregate($known->sum('company_balance_minor')) : null];
                if ($grant = app(TopupAccounting::class)->grant($actor->membership->account)) {
                    $wallet = app(TopupWallets::class)->balance($actor->membership->account);
                    $balance = ['updated_at' => $balance['updated_at'], 'total' => 1, 'known' => 1, 'amount' => Money::decimal($wallet['available'])];
                }
            } elseif ($actor->membership->account->type !== AccountType::Pos) {
                $balance = ['updated_at' => null, 'total' => 0, 'known' => 0, 'amount' => null];
            }
        }
        $data = $rows->getCollection()->map(fn ($c): array => $this->configuration->dto($c, $actor))->values()->all();

        return response()->json(['data' => $data, 'meta' => ['current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'total' => $rows->total()], ...($balance ? ['balance' => $balance] : [])], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function offers(DigitalRequest $request): JsonResponse
    {
        $actor = $request->user();
        $own = $this->access->own($actor, 'digital.view');
        abort_unless($own->type === AccountType::Pos, 403);
        $productIds = $this->access->catalog->products($actor)->pluck('catalog_products.id')->all();
        $ancestors = DB::table('account_closure')->where('descendant_id', $own->id)->select('ancestor_id');
        $data = $this->access->connections($actor)->with(['account', 'company', 'offers.product.provider', 'grants' => fn ($query) => $query->whereIn('target_account_id', $ancestors)])->get()->flatMap(function ($connection) use ($actor, $own, $productIds): array {
            return $this->access->offers($connection, $own)->filter(fn ($offer): bool => $offer->topup_category_id !== null || in_array($offer->product_id, $productIds, true))->map(fn ($offer): array => $this->configuration->offerDto($offer, $actor) + ['connection_id' => $connection->id, 'provider' => $connection->provider, 'provider_id' => $connection->provider_id, 'company_name' => $connection->company?->name ?? ($connection->provider === 'rabiaa' ? 'الرابعة' : 'Topup'), 'main_account_name' => $connection->account->name, 'bein_provinces' => $connection->bein_provinces ?? [], 'gateway' => $this->gateway->status($connection)])->values()->all();
        })->values()->all();

        return $this->response($data);
    }

    private function query(DigitalRequest $request): Builder
    {
        $actor = $request->user();
        $this->access->require($actor, 'digital.view');
        $query = $this->access->orders($actor)->with(['account', 'mainAccount', 'creator', 'product']);
        foreach (['provider', 'product_id', 'status'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->input($field));
            }
        }
        foreach (['account_id', 'main_account_id'] as $field) {
            if ($request->filled($field)) {
                if ($field === 'main_account_id') {
                    $ownMain = app(TopupDistribution::class)->main($actor->membership->account);
                    abort_unless($ownMain?->id === $request->integer($field) || $this->access->accounts($actor)->where('type', AccountType::MainAgent)->whereKey($request->integer($field))->exists(), 404);
                } else {
                    $this->access->accounts($actor)->findOrFail($request->integer($field));
                }
                $query->where($field, $request->integer($field));
            }
        }
        if ($request->filled('from')) {
            $query->where('created_at', '>=', CarbonImmutable::parse($request->input('from'), 'Asia/Baghdad')->startOfDay()->utc());
        }
        if ($request->filled('to')) {
            $query->where('created_at', '<', CarbonImmutable::parse($request->input('to'), 'Asia/Baghdad')->startOfDay()->addDay()->utc());
        }
        if ($request->filled('q')) {
            $term = '%'.$request->input('q').'%';
            $query->where(function ($q) use ($term): void {
                $q->where('request_id', 'like', $term)->orWhere('company_transaction_id', 'like', $term)->orWhere('mobile', 'like', $term)->orWhereHas('creator', fn ($user) => $user->where('name', 'like', $term))->orWhereHas('product', fn ($product) => $product->where('name', 'like', $term))->orWhereHas('account', fn ($account) => $account->where('name', 'like', $term));
            });
        }

        return $query;
    }

    public function index(DigitalRequest $request): mixed
    {
        $query = $this->query($request);
        $total = ! $request->anyFilled(['q', 'from', 'to']) ? (int) app(DigitalTotals::class)->summary($request)->total : (clone $query)->count();
        $perPage = $request->integer('per_page', 25);
        $page = $request->integer('page', 1);
        $keys = (clone $query)->toBase()->select('digital_orders.id');
        if ($total > 0 && ! $request->anyFilled(['q', 'from', 'to', 'account_id', 'product_id', 'main_account_id'])) {
            $scope = $this->access->accounts($request->user());
            if ($request->filled('account_id')) {
                $scope->whereKey($request->integer('account_id'));
            } elseif ($request->filled('main_account_id')) {
                $scope->whereIn('accounts.id', DB::table('account_closure')->where('ancestor_id', $request->integer('main_account_id'))->select('descendant_id'));
            }
            $scopeIds = $scope->limit(501)->pluck('accounts.id')->all();
            if (count($scopeIds) <= 500) {
                $keys->whereIn('account_id', $scopeIds);
                if (DB::getDriverName() === 'mysql') {
                    $keys->forceIndex('digital_account_page_ids');
                }
            } elseif (! $request->anyFilled(['account_id', 'main_account_id']) && (! $request->filled('status') || $request->filled('provider'))) {
                $index = $request->filled('provider') ? ($request->filled('status') ? 'digital_provider_status_page_ids' : 'digital_provider_page_ids') : 'digital_page_ids';
                if (DB::getDriverName() === 'mysql') {
                    $keys->forceIndex($index);
                }
            }
        }
        $offset = ($page - 1) * $perPage;
        $ids = [];
        if ($total && $offset < $total) {
            if ($offset > intdiv($total, 2)) {
                $ids = $keys->orderBy('digital_orders.id')->offset(max(0, $total - $offset - $perPage))->limit(min($perPage, $total - $offset))->pluck('id')->all();
            } else {
                $ids = $keys->orderByDesc('digital_orders.id')->forPage($page, $perPage)->pluck('id')->all();
            }
        }
        $items = $ids ? $query->whereIn('id', $ids)->orderByDesc('id')->get() : collect();

        return DigitalResource::collection(new LengthAwarePaginator($items, $total, $perPage, $page, ['path' => LengthAwarePaginator::resolveCurrentPath()]));
    }

    public function show(DigitalRequest $request, int $id): DigitalResource
    {
        return new DigitalResource($this->access->order($request->user(), $id));
    }

    private function aggregate(mixed $value): string
    {
        $value = (string) $value;
        abort_unless(ctype_digit($value) && strlen($value) <= strlen((string) Money::MAX_MINOR) && (int) $value <= Money::MAX_MINOR, 422, 'الإجمالي يتجاوز الحد المسموح؛ حدد نطاقًا أصغر.');

        return Money::decimal((int) $value);
    }

    public function summary(DigitalRequest $request): JsonResponse
    {
        $query = $this->query($request);
        $row = ! $request->anyFilled(['q', 'from', 'to'])
            ? app(DigitalTotals::class)->summary($request)
            : $query->toBase()->selectRaw("SUM(CASE WHEN status = 'succeeded' THEN 1 ELSE 0 END) AS quantity, SUM(CASE WHEN status = 'succeeded' THEN actual_retail_minor ELSE 0 END) AS retail, SUM(CASE WHEN status = 'succeeded' THEN actual_cost_minor ELSE 0 END) AS cost, SUM(CASE WHEN status = 'succeeded' AND cost_basis = 'catalog_snapshot' THEN 1 ELSE 0 END) AS catalog_cost_count, SUM(CASE WHEN status = 'refunded' THEN 1 ELSE 0 END) AS refunds, SUM(CASE WHEN status IN ('pending','review') THEN 1 ELSE 0 END) AS pending")->first();
        $data = ['quantity' => (int) $row->quantity, 'retail' => $this->aggregate($row->retail ?? 0), 'refunds' => (int) $row->refunds, 'pending' => (int) $row->pending];
        if ($request->user()->membership->account->type !== AccountType::Pos && in_array('data.cost', $request->user()->membership->permissions(), true)) {
            $data['cost'] = (int) $row->catalog_cost_count > 0 ? null : $this->aggregate($row->cost ?? 0);
            $data['estimated_cost'] = $this->aggregate($row->cost ?? 0);
            $data['catalog_cost_count'] = (int) $row->catalog_cost_count;
        }

        return $this->response($data);
    }

    public function export(DigitalRequest $request): StreamedResponse
    {
        $this->access->require($request->user(), 'digital.export');
        $query = $this->query($request);
        $prices = $request->user()->membership->account->type !== AccountType::Pos;
        $costs = $prices && in_array('data.cost', $request->user()->membership->permissions(), true);
        $this->audit->record('digital.export', $request, $request->user(), $request->user()->membership->account_id, ['filters' => $request->validated()]);

        return response()->streamDownload(function () use ($query, $costs, $prices): void {
            $file = fopen('php://output', 'w');
            fwrite($file, "\xEF\xBB\xBF");
            $write = function (array $row) use ($file): void {
                $row = array_map(fn ($value): string => preg_match('/^[=+@\-]/u', (string) $value) ? "'".$value : (string) ($value ?? ''), $row);
                fputcsv($file, $row, ',', '"', '');
            };
            $write(['العملية', 'الخدمة', 'الوكيل', 'النقطة', 'الموظف', 'الفئة', 'رقم الزبون', 'التاريخ', 'الحالة', ...($costs ? ['الكلفة', 'مصدر الكلفة'] : []), ...($prices ? ['سعر المدير', 'زيادة الوكيل'] : []), 'مبلغ البيع', 'مرجع الشركة']);
            $query->orderBy('id')->chunkById(200, function ($rows) use ($write, $costs, $prices): void {
                foreach ($rows as $row) {
                    $write([$row->request_id, $row->provider === 'topup' ? 'Topup' : 'الرابعة', $row->mainAccount->name, $row->account->name, $row->creator->name, $row->product->name, $row->mobile, $row->created_at->toISOString(), $row->status, ...($costs ? [$row->actual_cost_minor === null ? '' : Money::decimal($row->actual_cost_minor), $row->cost_basis === 'catalog_snapshot' ? ($row->manual_category_name ? 'سعر الفئة اليدوي · تقديري' : 'قائمة الشركة وقت الطلب') : ($row->cost_basis === 'provider_response' ? 'رد الشركة' : '')] : []), ...($prices ? [$row->admin_price_minor === null ? '' : Money::decimal((int) $row->admin_price_minor), $row->admin_price_minor === null ? '' : Money::decimal($row->quoted_retail_minor - (int) $row->admin_price_minor)] : []), Money::decimal($row->actual_retail_minor ?? $row->quoted_retail_minor), $row->company_transaction_id]);
                }
            });
            fclose($file);
        }, 'digital-services.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }

    public function storeConnection(DigitalRequest $request): JsonResponse
    {
        return $this->response($this->configuration->save($request->user(), null, $request->validated(), $request), 201);
    }

    public function updateConnection(DigitalRequest $request, int $id): JsonResponse
    {
        return $this->response($this->configuration->save($request->user(), $id, $request->validated(), $request));
    }

    public function catalog(DigitalRequest $request, int $id): JsonResponse
    {
        return $this->response($this->configuration->catalog($request->user(), $id, $request->integer('version'), $request));
    }

    public function synchronize(DigitalRequest $request, int $id, TopupCatalog $catalog): JsonResponse
    {
        return $this->response($catalog->synchronize($request->user(), $id, $request->integer('version'), $request));
    }

    public function balance(DigitalRequest $request, int $id): JsonResponse
    {
        return $this->response($this->configuration->balance($request->user(), $id, $request));
    }

    public function grant(DigitalRequest $request, int $id, int $target): JsonResponse
    {
        return $this->response($this->configuration->grant($request->user(), $id, $target, $request->validated(), $request));
    }

    public function heartbeat(DigitalRequest $request): JsonResponse
    {
        return $this->response($this->operations->heartbeat($request->user(), $request->validated(), $request));
    }

    public function store(DigitalRequest $request): JsonResponse
    {
        $order = $this->operations->begin($request->user(), $request->validated(), $request);

        return $this->response($this->operations->dispatch($request->user(), $order->id, 'submit', $request), 201);
    }

    public function recovery(DigitalRequest $request): JsonResponse
    {
        $own = $this->access->own($request->user(), 'digital.create');
        abort_unless($own->type === AccountType::Pos, 403);
        $order = $this->access->orders($request->user())->where('account_id', $own->id)->whereNull('acknowledged_at')->latest('id')->first();

        return $this->response(['order' => $order ? $this->operations->dto($order, $request) : null]);
    }

    public function acknowledge(DigitalRequest $request, int $id): JsonResponse
    {
        return DB::transaction(function () use ($request, $id): JsonResponse {
            $actor = $request->user();
            app(MutationGuard::class)->lock($actor, [], 'digital.create', shareAncestors: true);

            return RequestReadCache::withinLockedAuthorization(function () use ($actor, $request, $id): JsonResponse {
                $this->access->own($actor, 'digital.create', true);
                $order = $this->access->order($actor, $id, 'digital.create', true, true);
                abort_if($order->reservation_active, 409, 'نتيجة العملية غير محسومة؛ لا يمكن بدء بيع جديد.');
                if ($order->acknowledged_at === null) {
                    $order->update(['acknowledged_at' => now()]);
                    $this->audit->record('digital.acknowledge', $request, $actor, $order->account_id, ['order_id' => $order->id]);
                }

                return $this->response($this->operations->dto($order, $request));
            });
        });
    }

    public function verify(DigitalRequest $request, int $id): JsonResponse
    {
        return $this->response($this->operations->dispatch($request->user(), $id, 'verify', $request, $request->validated()));
    }

    public function refundStatus(DigitalRequest $request, int $id): JsonResponse
    {
        return $this->response($this->operations->dispatch($request->user(), $id, 'refund-status', $request, $request->validated(), true));
    }

    public function recordReview(DigitalRequest $request, int $id): JsonResponse
    {
        return $this->response($this->operations->recordReview($request->user(), $id, $request->validated(), $request));
    }

    public function receipt(DigitalRequest $request, int $id): JsonResponse
    {
        return $this->response($this->operations->receipt($request->user(), $id, $request));
    }

    public function printAuthorization(DigitalRequest $request, int $id): JsonResponse
    {
        return $this->response($this->operations->printAuthorization($request->user(), $id, $request));
    }

    public function history(DigitalHistoryRequest $request): JsonResponse
    {
        $actor = $request->user();
        $this->access->authority->require($actor, 'account.view');
        $own = $this->access->accounts($actor)->findOrFail($actor->membership->account_id);
        abort_unless($own->type === AccountType::Pos, 403);
        $permissions = $actor->membership->permissions();
        $queries = [];
        foreach (['stock' => 'sales.view', 'digital' => 'digital.view'] as $kind => $permission) {
            if (! in_array($permission, $permissions, true) || $kind === 'stock' && $request->input('kind') === 'topup') {
                continue;
            }
            $table = $kind === 'stock' ? 'sales' : 'digital_orders';
            $time = $kind === 'stock' ? 'COALESCE(sales.issued_at,sales.created_at)' : 'digital_orders.created_at';
            $query = DB::table($table)->where($table.'.account_id', $own->id)->selectRaw($table.".id AS operation_id, {$time} AS occurred_at, '{$kind}' AS operation_type");
            if ($kind === 'digital' && in_array($request->input('kind'), ['topup', 'card'], true)) {
                $query->where('provider', $request->input('kind') === 'topup' ? 'topup' : 'rabiaa');
            }
            if ($request->filled('from')) {
                $query->whereRaw($time.' >= ?', [CarbonImmutable::parse($request->input('from'), 'Asia/Baghdad')->startOfDay()->utc()]);
            }
            if ($request->filled('to')) {
                $query->whereRaw($time.' < ?', [CarbonImmutable::parse($request->input('to'), 'Asia/Baghdad')->startOfDay()->addDay()->utc()]);
            }
            if ($request->filled('q')) {
                $query->join('catalog_products as product', 'product.id', '=', $table.'.product_id')->join('users as creator', 'creator.id', '=', $table.'.creator_id');
                $term = '%'.$request->input('q').'%';
                $query->where(function ($q) use ($term, $table, $kind): void {
                    $q->where($table.'.id', 'like', $term)->orWhere('product.name', 'like', $term)->orWhere('creator.name', 'like', $term);
                    if ($kind === 'digital') {
                        $q->orWhere('request_id', 'like', $term)->orWhere('mobile', 'like', $term)->orWhere('company_transaction_id', 'like', $term);
                    }
                });
            }
            $queries[] = $query;
        }
        abort_unless($queries, 403);
        $union = array_shift($queries);
        foreach ($queries as $query) {
            $union->unionAll($query);
        }
        $page = DB::query()->fromSub($union, 'history')->orderByDesc('occurred_at')->orderByDesc('operation_id')->orderBy('operation_type')->paginate($request->integer('per_page', 25));
        $rows = $page->getCollection();
        $stock = Sale::whereIn('id', $rows->where('operation_type', 'stock')->pluck('operation_id'))->with(['account', 'mainAccount', 'creator', 'product', 'provider'])->get()->keyBy('id');
        $digital = DigitalOrder::whereIn('id', $rows->where('operation_type', 'digital')->pluck('operation_id'))->with(['account', 'mainAccount', 'creator', 'product'])->get()->keyBy('id');
        $data = $rows->map(fn ($row): array => ['kind' => $row->operation_type, 'record' => $row->operation_type === 'stock' ? (new SaleResource($stock[$row->operation_id]))->resolve($request) : (new DigitalResource($digital[$row->operation_id]))->resolve($request)])->all();

        return response()->json(['data' => $data, 'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()]], 200, ['Cache-Control' => 'private, no-store']);
    }
}

<?php

namespace App\Http\Controllers\Sales;

use App\Enums\AccountType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\SalesRequest;
use App\Http\Resources\Sales\SaleResource;
use App\Models\Account;
use App\Models\AccountAttachment;
use App\Models\Finance\Wallet;
use App\Models\Sales\PrintRule;
use App\Models\Sales\ReceiptLayout;
use App\Models\Sales\ReprintRequest;
use App\Models\Sales\SaleLimit;
use App\Models\Stock\StockCard;
use App\Services\AuditLogger;
use App\Services\CatalogAccess;
use App\Services\Finance\FinanceOperations;
use App\Services\Finance\Money;
use App\Services\Sales\PrintOperations;
use App\Services\Sales\ReceiptDesign;
use App\Services\Sales\SalesAccess;
use App\Services\Sales\SalesOperations;
use App\Services\Sales\SalesPolicies;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SalesController extends Controller
{
    public function __construct(private SalesAccess $access, private SalesOperations $operations, private PrintOperations $printing, private SalesPolicies $policies, private CatalogAccess $catalog, private FinanceOperations $finance, private AuditLogger $audit, private ReceiptDesign $design) {}

    public function options(SalesRequest $request): JsonResponse
    {
        $salesView = in_array('sales.view', $request->user()->membership->permissions(), true);
        $this->access->require($request->user(), $salesView ? 'sales.view' : 'exceptions.view');
        $actor = $request->user();
        $own = $actor->membership->account;
        $canSell = $salesView && in_array($own->type, [AccountType::SubAgent, AccountType::SubBranch, AccountType::Pos], true) && $this->access->accounts($actor)->whereKey($own->id)->exists();
        $parent = $own->parent;

        return response()->json(['data' => ['accounts' => $this->access->accounts($actor)->orderBy('id')->get(['id', 'name', 'type', 'parent_id', 'city', 'phone', 'status']), 'own_account_id' => $own->id, 'seller_account_id' => $canSell ? $own->id : null, 'parent' => $parent ? ['id' => $parent->id, 'name' => $parent->name, 'type' => $parent->type->value] : null, 'products' => $canSell ? $this->productData($request) : [], 'policy' => $canSell ? $this->policies->context($own)['policy'] : null]]);
    }

    private function productData(SalesRequest $request): array
    {
        $own = $this->access->own($request->user(), 'sales.view');
        $main = $this->access->main($own);
        $query = $this->catalog->forAccount($own->id)->whereIn('id', $this->catalog->products($request->user())->select('catalog_products.id'))->where('status', 'active')->whereHas('provider', fn ($q) => $q->where('status', 'active')->where('connection', 'ملفات'))->with('provider')->orderBy('display_order')->orderBy('id');
        if (Schema::hasTable('digital_offers')) {
            $query->whereNotExists(fn ($q) => $q->selectRaw('1')->from('digital_offers')->join('digital_connections', 'digital_connections.id', '=', 'digital_offers.connection_id')->whereColumn('digital_offers.product_id', 'catalog_products.id')->where('digital_offers.listed', true)->where('digital_connections.account_id', $main->id));
        }
        $products = $query->get();
        $prices = DB::table('finance_prices')->where('account_id', $main->id)->whereIn('product_id', $products->pluck('id'))->get()->keyBy('product_id');
        $stocks = StockCard::join('stock_batches', 'stock_batches.id', '=', 'stock_cards.batch_id')->where('stock_cards.account_id', $main->id)->whereIn('stock_cards.product_id', $products->pluck('id'))->where('stock_cards.status', 'Available')->where('credit_held', false)->whereNull('sale_id')->where('expiry', '>', now('Asia/Baghdad')->toDateString())->whereIn('stock_batches.status', ['Loaded', 'Partially Used'])->groupBy('stock_cards.product_id', 'stock_batches.currency')->selectRaw('stock_cards.product_id, stock_batches.currency, COUNT(*) AS quantity')->get()->keyBy(fn ($s) => $s->product_id.':'.$s->currency);
        $wallets = Wallet::where('account_id', $own->id)->where('kind', 'account')->where('service', 'voucher')->get()->keyBy('currency');

        return $products->filter(fn ($p) => ! $p->allowed_cities || in_array($own->city, $p->allowed_cities, true))->map(function ($p) use ($prices, $stocks, $wallets, $own): array {
            $price = $prices->get($p->id);
            $configured = $price && $price->currency === $p->currency && (int) $price->price_minor > 0 && ($p->minimum_price === null || (int) $price->price_minor >= Money::minor($p->minimum_price));
            $context = $this->policies->context($own, $p->id);

            return ['id' => $p->id, 'name' => $p->name, 'kind' => $p->kind, 'provider_id' => $p->provider_id, 'provider_name' => $p->provider->name, 'currency' => $p->currency, 'face_value' => $p->face_value, 'price' => $configured ? Money::decimal((int) $price->price_minor) : null, 'price_version' => $configured ? (int) $price->version : null, 'stock_available' => (int) ($stocks->get($p->id.':'.$p->currency)?->quantity ?? 0), 'wallet_available' => isset($wallets[$p->currency]) ? Money::decimal($wallets[$p->currency]->balance_minor - $wallets[$p->currency]->held_minor) : '0.00', 'daily_quantity' => $p->daily_quantity, 'daily_amount' => $p->daily_amount, 'image_url' => $p->image_path ? '/api/v1/sales/products/'.$p->id.'/images/product' : null, 'provider_image_url' => $p->provider->image_path ? '/api/v1/sales/products/'.$p->id.'/images/provider' : null, 'print_policy' => $context['policy'], 'daily_print' => $this->policies->usage($own, $context), 'print_wait_seconds' => $this->policies->wait($own, $context)];
        })->values()->all();
    }

    public function configurationOptions(SalesRequest $request): JsonResponse
    {
        $actor = $request->user();
        $permissions = $actor->membership->permissions();
        $permission = collect(['branding.view', 'security.policies', 'sales.limits'])->first(fn ($key) => in_array($key, $permissions, true));
        abort_unless($permission, 403);
        $this->access->require($actor, $permission, $permission === 'security.policies');
        $accounts = $this->access->accounts($actor)->orderBy('id')->get(['id', 'name', 'type', 'parent_id', 'city', 'phone', 'status']);
        $products = $this->catalog->products($actor)->with('provider:id,name')->orderBy('display_order')->orderBy('id')->get(['id', 'provider_id', 'name', 'kind', 'currency', 'receipt_width', 'extra_fields']);

        return response()->json(['data' => ['own_account_id' => $actor->membership->account_id, 'accounts' => $accounts, 'products' => $products->map(fn ($product): array => ['id' => $product->id, 'name' => $product->name, 'provider_id' => $product->provider_id, 'provider_name' => $product->provider->name, 'currency' => $product->currency, 'kind' => $product->kind, 'receipt_width' => $product->receipt_width, 'extra_fields' => $product->extra_fields])->all()]]);
    }

    public function products(SalesRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->productData($request)]);
    }

    public function image(SalesRequest $request, int $id, string $kind): BinaryFileResponse
    {
        $own = $request->user()->membership->account;
        $isSeller = in_array($own->type, [AccountType::SubAgent, AccountType::SubBranch, AccountType::Pos], true);
        if ($isSeller) {
            $this->access->own($request->user(), 'sales.view');
        } else {
            $this->access->require($request->user(), 'branding.view');
            $this->access->accounts($request->user())->findOrFail($own->id);
        }
        $productQuery = $isSeller ? $this->catalog->forAccount($own->id)->whereIn('catalog_products.id', $this->catalog->products($request->user())->select('catalog_products.id')) : $this->catalog->products($request->user());
        $product = $productQuery->with('provider')->find($id);
        if (! $product) {
            $sale = $this->access->sales($request->user())->where('account_id', $own->id)->where('product_id', $id)->whereNotNull('issued_at')->with('product.provider')->firstOrFail();
            $this->access->require($request->user(), 'sell.receipt');
            $product = $sale->product;
        }
        if ($kind === 'agent') {
            $main = $own->type === AccountType::System ? $this->access->accounts($request->user())->where('type', AccountType::MainAgent)->findOrFail($request->integer('account_id')) : $this->access->main($own);
            abort_if($request->filled('account_id') && $request->integer('account_id') !== $main->id, 403);
            $personal = ReceiptLayout::where('scope_key', 'account:'.$main->id.':'.$product->id)->first();
            abort_if($personal?->layout['agent_image_removed'] ?? false, 404);
            if ($personal?->layout['agent_image_path'] ?? null) {
                $path = $personal->layout['agent_image_path'];
                abort_unless(Storage::disk('local')->exists($path), 404);

                return response()->file(Storage::disk('local')->path($path), ['Content-Type' => $personal->layout['agent_image_mime'], 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
            }
            $image = AccountAttachment::where('account_id', $main->id)->where('kind', 'agent_image')->latest('id')->firstOrFail();
            abort_unless(Storage::disk('local')->exists($image->storage_path), 404);

            return response()->file(Storage::disk('local')->path($image->storage_path), ['Content-Type' => $image->mime_type, 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
        }
        $record = $kind === 'provider' ? $product->provider : $product;
        abort_unless($record->image_path && Storage::disk('local')->exists($record->image_path), 404);

        return response()->file(Storage::disk('local')->path($record->image_path), ['Content-Type' => $record->image_mime, 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function filters(mixed $query, SalesRequest $request, string $date = 'created_at'): void
    {
        if ($request->filled('from')) {
            $query->where($date, '>=', CarbonImmutable::parse($request->input('from'), 'Asia/Baghdad')->startOfDay()->utc());
        }
        if ($request->filled('to')) {
            $query->where($date, '<', CarbonImmutable::parse($request->input('to'), 'Asia/Baghdad')->addDay()->startOfDay()->utc());
        }
        if ($request->filled('account_id')) {
            $this->access->accounts($request->user())->findOrFail($request->integer('account_id'));
            $query->where('account_id', $request->integer('account_id'));
        }
        if ($request->has('account_ids')) {
            $query->whereIn('account_id', $request->input('account_ids'));
        }
    }

    public function index(SalesRequest $request): mixed
    {
        $query = $this->listQuery($request);

        return SaleResource::collection($query->orderByDesc('id')->paginate($request->integer('per_page', 25)));
    }

    public function summary(SalesRequest $request): JsonResponse
    {
        $rows = $this->listQuery($request, false)->toBase()->selectRaw('status, COUNT(*) AS aggregate')->groupBy('status')->get();
        $counts = $rows->mapWithKeys(fn ($row): array => [$row->status => (int) $row->aggregate])->all();

        return response()->json(['data' => ['total' => array_sum($counts), 'status_counts' => $counts]]);
    }

    private function listQuery(SalesRequest $request, bool $applyStatus = true): mixed
    {
        $this->access->require($request->user(), 'sales.view');
        $query = $this->access->sales($request->user())->with(['account', 'creator', 'product', 'mainAccount', 'provider']);
        $this->filters($query, $request);
        foreach ($applyStatus ? ['status', 'product_id', 'provider_id', 'currency'] : ['product_id', 'provider_id', 'currency'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->input($field));
            }
        }
        if ($request->filled('q')) {
            $pattern = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $request->input('q')).'%';
            $query->where(fn ($q) => $q->where('id', 'like', $pattern)->orWhereHas('account', fn ($a) => $a->where('name', 'like', $pattern))->orWhereHas('product', fn ($p) => $p->where('name', 'like', $pattern)));
        }

        return $query;
    }

    public function export(SalesRequest $request): StreamedResponse
    {
        $this->access->require($request->user(), 'sales.export');
        $query = $this->listQuery($request);
        $costs = in_array('data.cost', $request->user()->membership->permissions(), true);
        $profits = in_array('data.profit', $request->user()->membership->permissions(), true);
        $this->audit->record('sales.export', $request, $request->user(), $request->user()->membership->account_id, ['filters' => $request->validated()]);

        return response()->streamDownload(function () use ($query, $costs, $profits): void {
            $stream = fopen('php://output', 'wb');
            fwrite($stream, "\xEF\xBB\xBF");
            $headers = ['رقم العملية', 'تاريخ الإصدار', 'الحساب', 'الفئة', 'العملة', 'الكمية', 'سعر البطاقة', 'الإجمالي', 'سعر الزبون', 'إجمالي الزبون', 'الحالة'];
            if ($costs) {
                $headers = array_merge($headers, ['التكلفة', 'تكلفة التحميل']);
                if ($profits) {
                    $headers[] = 'هامش الوكيل';
                }
            }
            fputcsv($stream, $headers, ',', '"', '');
            foreach ($query->lazyById(200) as $sale) {
                $values = [$sale->id, $sale->issued_at?->toISOString(), $sale->account?->name, $sale->product?->name, $sale->currency, $sale->quantity, Money::decimal($sale->price_minor), Money::decimal($sale->total_minor), Money::decimal($sale->retail_price_minor), Money::decimal($sale->retail_total_minor), $sale->status];
                if ($costs) {
                    $values = array_merge($values, [Money::decimal($sale->cost_minor), Money::decimal($sale->load_cost_minor)]);
                    if ($profits) {
                        $values[] = Money::decimal($sale->credit_minor - $sale->load_cost_minor);
                    }
                }
                $values = array_map(fn ($v): string => preg_match('/\A[=+@\-\t\r]/', (string) $v) ? "'".$v : (string) $v, $values);
                fputcsv($stream, $values, ',', '"', '');
            }
            fclose($stream);
        }, 'sales.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function show(SalesRequest $request, int $id): SaleResource
    {
        $sale = $this->access->sale($request->user(), $id, 'sales.view');
        $sale->load(['account', 'creator', 'product', 'attempts', 'mainAccount', 'provider']);

        return new SaleResource($sale);
    }

    public function create(SalesRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->operations->create($request->user(), $request->validated(), $request, true)], 201);
    }

    public function reserve(SalesRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->operations->create($request->user(), $request->validated(), $request, false)], 201);
    }

    public function issue(SalesRequest $request, int $id): JsonResponse
    {
        return response()->json(['data' => $this->operations->issue($request->user(), $id, $request->validated(), $request)]);
    }

    public function cancel(SalesRequest $request, int $id): JsonResponse
    {
        return response()->json(['data' => $this->operations->cancel($request->user(), $id, $request->validated(), $request)]);
    }

    public function heartbeat(SalesRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->operations->heartbeat($request->user(), $request->validated(), $request)]);
    }

    public function receipt(SalesRequest $request, int $id): JsonResponse
    {
        return response()->json(['data' => $this->operations->receipt($request->user(), $id, $request)])->header('Cache-Control', 'private, no-store, max-age=0')->header('Pragma', 'no-cache')->header('X-Content-Type-Options', 'nosniff');
    }

    public function start(SalesRequest $request, int $id): JsonResponse
    {
        return response()->json(['data' => $this->printing->start($request->user(), $id, $request->validated(), $request)]);
    }

    public function result(SalesRequest $request, int $id): JsonResponse
    {
        return response()->json(['data' => $this->printing->result($request->user(), $id, $request->validated(), $request)]);
    }

    public function retry(SalesRequest $request, int $id): JsonResponse
    {
        return response()->json(['data' => $this->printing->retry($request->user(), $id, $request->validated(), $request)]);
    }

    public function requestReprint(SalesRequest $request, int $id): JsonResponse
    {
        return response()->json(['data' => $this->printing->requestReprint($request->user(), $id, $request->validated(), $request)], 201);
    }

    public function review(SalesRequest $request, int $id): JsonResponse
    {
        return response()->json(['data' => $this->printing->review($request->user(), $id, $request->validated(), $request, false)]);
    }

    public function escalate(SalesRequest $request, int $id): JsonResponse
    {
        return response()->json(['data' => $this->printing->review($request->user(), $id, $request->validated(), $request, true)]);
    }

    public function deliver(SalesRequest $request, int $id): JsonResponse
    {
        return response()->json(['data' => $this->printing->deliver($request->user(), $id, $request->validated(), $request)]);
    }

    public function reprintRequests(SalesRequest $request): JsonResponse
    {
        $this->access->require($request->user(), 'exceptions.view');
        $query = ReprintRequest::with(['account', 'recipient', 'creator', 'reviewer'])->whereIn('sale_id', $this->access->sales($request->user())->select('sales.id'))->orderByDesc('id');
        $this->filters($query, $request);
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('direction')) {
            $query->where($request->input('direction') === 'incoming' ? 'recipient_id' : 'account_id', $request->user()->membership->account_id);
        }
        if ($request->filled('q')) {
            $pattern = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $request->input('q')).'%';
            $query->where(fn ($q) => $q->where('reason', 'like', $pattern)->orWhere('sale_id', 'like', $pattern)->orWhereHas('account', fn ($a) => $a->where('name', 'like', $pattern)));
        }
        $page = $query->paginate($request->integer('per_page', 25));

        return response()->json(['data' => $page->getCollection()->map($this->printing->requestDto(...))->all(), 'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()]]);
    }

    public function policy(SalesRequest $request): JsonResponse
    {
        $this->access->require($request->user(), 'security.policies', true);

        return response()->json(['data' => $this->policies->baseDto()]);
    }

    public function savePolicy(SalesRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->policies->save($request->user(), $request->validated(), $request)]);
    }

    public function rules(SalesRequest $request): JsonResponse
    {
        $this->access->require($request->user(), 'security.policies', true);
        $page = PrintRule::orderBy('id')->paginate($request->integer('per_page', 25));

        return response()->json(['data' => $page->items(), 'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()]]);
    }

    public function saveRule(SalesRequest $request, ?int $id = null): JsonResponse
    {
        return response()->json(['data' => $this->policies->saveRule($request->user(), $id, $request->validated(), $request)], $id ? 200 : 201);
    }

    public function limits(SalesRequest $request): JsonResponse
    {
        $this->access->require($request->user(), 'sales.limits');
        $query = SaleLimit::whereIn('account_id', $this->access->accounts($request->user())->select('accounts.id'));
        $this->filters($query, $request);
        if ($request->filled('product_id')) {
            $query->where('product_id', $request->integer('product_id'));
        }
        $page = $query->orderByDesc('id')->paginate($request->integer('per_page', 25));
        $data = $page->getCollection()->map(fn ($r): array => ['id' => $r->id, 'account_id' => (int) $r->account_id, 'product_id' => (int) $r->product_id, 'authority_account_id' => (int) $r->authority_account_id, 'max_cards' => $r->max_cards, 'daily_quantity' => $r->daily_quantity, 'daily_amount' => $r->daily_amount_minor === null ? null : Money::decimal($r->daily_amount_minor), 'version' => $r->version])->all();

        return response()->json(['data' => $data, 'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()]]);
    }

    public function saveLimit(SalesRequest $request): JsonResponse
    {
        $this->access->require($request->user(), 'sales.limits');
        $data = $request->validated();
        $actor = $request->user();
        $target = $this->access->accounts($actor)->findOrFail($data['account_id']);
        $this->catalog->products($actor)->findOrFail($data['product_id']);
        abort_unless($this->catalog->forAccount($target->id)->whereKey($data['product_id'])->exists(), 404);
        $result = $this->finance->execute($actor, 'sales.limit', $data, function () use ($actor, $target, $data, $request): array {
            Account::whereKey($target->id)->lockForUpdate()->firstOrFail();
            $record = SaleLimit::where('account_id', $target->id)->where('product_id', $data['product_id'])->where('authority_account_id', $actor->membership->account_id)->lockForUpdate()->first();
            abort_unless(($record?->version ?? 0) === (int) $data['version'], 409, 'تغيرت حدود البيع؛ أعد فتحها.');
            $values = ['account_id' => $target->id, 'product_id' => (int) $data['product_id'], 'authority_account_id' => $actor->membership->account_id, 'max_cards' => $data['max_cards'], 'daily_quantity' => $data['daily_quantity'], 'daily_amount_minor' => $data['daily_amount'] === null ? null : Money::minor($data['daily_amount']), 'version' => ($record?->version ?? 0) + 1];
            if ($record) {
                $record->update($values);
            } else {
                $record = SaleLimit::create($values);
            }
            $this->audit->record('sales.limits', $request, $actor, $target->id, ['product_id' => $record->product_id, 'version' => $record->version]);
            unset($values['daily_amount_minor']);

            return ['id' => $record->id, 'daily_amount' => $record->daily_amount_minor === null ? null : Money::decimal($record->daily_amount_minor)] + $values;
        });

        return response()->json(['data' => $result]);
    }

    public function layout(SalesRequest $request, int $id): JsonResponse
    {
        $permission = in_array('branding.view', $request->user()->membership->permissions(), true) ? 'branding.view' : 'sales.view';
        $this->access->require($request->user(), $permission);
        $product = $this->catalog->products($request->user())->with('provider')->findOrFail($id);
        $target = $request->filled('account_id') ? $this->access->accounts($request->user())->findOrFail($request->integer('account_id')) : $request->user()->membership->account;
        $main = $target->type === AccountType::System ? Account::where('type', AccountType::MainAgent)->whereIn('id', $this->access->accounts($request->user())->select('accounts.id'))->first() : $this->access->main($target);

        return response()->json(['data' => $this->design->resolve($product, $main)]);
    }

    public function saveLayout(SalesRequest $request, int $id): JsonResponse
    {
        return response()->json(['data' => $this->design->save($request->user(), $id, $request->validated(), $request)]);
    }
}

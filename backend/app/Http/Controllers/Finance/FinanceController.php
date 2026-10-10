<?php

namespace App\Http\Controllers\Finance;

use App\Enums\AccountType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\FinanceRequest;
use App\Http\Resources\Finance\FinanceResource;
use App\Models\CatalogProvider;
use App\Models\Finance\FundingRecovery;
use App\Models\Finance\FundingRequest;
use App\Models\Finance\Invoice;
use App\Models\Finance\LedgerTransaction;
use App\Models\Finance\Policy;
use App\Models\Finance\PriceRequest;
use App\Models\Finance\Wallet;
use App\Services\AuditLogger;
use App\Services\CatalogAccess;
use App\Services\Finance\FinanceAccess;
use App\Services\Finance\FinanceOperations;
use App\Services\Finance\Money;
use App\Services\ManagementAuthority;
use App\Services\Operations\MutationGuard;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class FinanceController extends Controller
{
    public function __construct(private FinanceAccess $access, private FinanceOperations $operations, private ManagementAuthority $authority, private AuditLogger $audit, private CatalogAccess $catalog) {}

    public function options(FinanceRequest $request): JsonResponse
    {
        $permissions = array_values(array_intersect(['wallets.view', 'prices.view', 'invoices.view', 'ledger.view'], $request->user()->membership->permissions()));
        abort_unless($permissions, 403);
        $this->access->require($request->user(), $permissions[0]);
        $services = [['id' => 'voucher', 'name' => 'الكارتات'], ['id' => 'topup', 'name' => 'التعبئة'], ['id' => 'cash', 'name' => 'النقد']];
        foreach (CatalogProvider::where('connection', 'API')->where('status', 'active')->get(['id', 'name']) as $provider) {
            $services[] = ['id' => 'api:'.$provider->id, 'name' => $provider->name];
        }
        $accounts = $this->access->accounts($request->user())->orderBy('id')->get(['id', 'name', 'type', 'parent_id', 'phone', 'city', 'status']);

        $own = (int) $request->user()->membership->account_id;
        $start = now('Asia/Baghdad')->startOfDay()->utc();
        $visible = $accounts->pluck('id');
        $counts = ['today_requests' => FundingRequest::whereIn('to_account_id', $visible)->where('to_account_id', $own)->where('created_at', '>=', $start)->where('created_at', '<', $start->copy()->addDay())->count(), 'pending_incoming' => FundingRequest::whereIn('to_account_id', $visible)->where('from_account_id', $own)->where('status', 'pending')->count(), 'transfer_count' => DB::table('finance_transactions')->whereIn('kind', ['funding', 'transfer'])->whereExists(function ($q) use ($visible, $own): void {
            $q->selectRaw('1')->from('finance_entries')->join('finance_wallets', 'finance_wallets.id', '=', 'wallet_id')->whereColumn('transaction_id', 'finance_transactions.id')->whereIn('account_id', $visible)->where('account_id', $own);
        })->count()];
        if (! in_array('wallets.view', $request->user()->membership->permissions(), true)) {
            $counts = ['today_requests' => 0, 'pending_incoming' => 0, 'transfer_count' => 0];
        }

        $parent = $request->user()->membership->account->parent;

        return response()->json(['data' => ['services' => $services, 'currencies' => ['IQD'], 'accounts' => $accounts, 'own_account_id' => $own, 'parent_account_id' => $parent?->id, 'parent_account_name' => $parent?->name, 'parent_account_type' => $parent?->type->value, 'counts' => $counts, 'funding_policy' => (new FinanceResource(Policy::findOrFail(1)))->resolve($request)]]);
    }

    private function filters(mixed $query, FinanceRequest $request, string $dateColumn, array $columns, array $relations = []): void
    {
        if ($request->filled('from')) {
            $query->where($dateColumn, '>=', CarbonImmutable::parse($request->input('from'), 'Asia/Baghdad')->startOfDay()->utc());
        }
        if ($request->filled('to')) {
            $query->where($dateColumn, '<', CarbonImmutable::parse($request->input('to'), 'Asia/Baghdad')->addDay()->startOfDay()->utc());
        }
        if ($request->filled('q') && $columns !== []) {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $request->input('q')).'%';
            $query->where(function ($search) use ($columns, $pattern, $relations): void {
                foreach ($columns as $column) {
                    $search->orWhereRaw($column." LIKE ? ESCAPE '!'", [$pattern]);
                }
                foreach ($relations as $relation) {
                    $search->orWhereHas($relation, fn ($names) => $names->whereRaw("name LIKE ? ESCAPE '!'", [$pattern]));
                }
            });
        }
    }

    private function ids(FinanceRequest $request, string $permission): mixed
    {
        $this->access->require($request->user(), $permission);
        $ids = $this->access->accounts($request->user());
        if ($request->has('account_id')) {
            $this->access->account($request->user(), (int) $request->account_id, $permission);
            $ids->whereKey((int) $request->account_id);
        }
        if ($request->has('account_ids')) {
            $ids->whereIn('id', $request->input('account_ids'));
        }

        return $ids->select('id');
    }

    public function wallets(FinanceRequest $request): mixed
    {
        $query = Wallet::with('account')->where('kind', 'account')->whereIn('account_id', $this->ids($request, 'wallets.view'))->orderBy('account_id')->orderBy('service')->orderBy('currency');
        $this->filters($query, $request, 'created_at', ['service'], ['account']);
        foreach (['service', 'currency'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->input($field));
            }
        }

        $stockMetrics = null;
        if (in_array($request->user()->membership->account->type, [AccountType::System, AccountType::MainAgent], true) && (! $request->filled('service') || $request->input('service') === 'voucher') && Schema::hasTable('stock_cards')) {
            $currency = $request->input('currency', 'IQD');
            $ancestors = DB::table('account_closure')->whereIn('descendant_id', $this->ids($request, 'wallets.view'))->select('ancestor_id');
            $mainIds = $this->access->accounts($request->user())->whereIn('id', $ancestors)->where('type', AccountType::MainAgent)->select('id');
            $stock = DB::table('stock_cards')->join('stock_batches', 'stock_batches.id', '=', 'stock_cards.batch_id')->whereIn('stock_cards.account_id', $mainIds)->where('stock_batches.currency', $currency)->where('stock_cards.status', 'Available')->where('credit_held', false)->whereNull('sale_id')->selectRaw('COUNT(*) AS quantity, COALESCE(SUM(stock_cards.cost_minor), 0) AS cost_minor, COALESCE(SUM(credit_minor), 0) AS value_minor')->first();
            $stockMetrics = ['count' => (int) $stock->quantity, 'currency' => $currency, 'cost' => in_array('data.cost', $request->user()->membership->permissions(), true) ? Money::decimal((int) $stock->cost_minor) : null, 'value' => Money::decimal((int) $stock->value_minor)];
        }

        return FinanceResource::collection($query->paginate((int) $request->input('per_page', 100)))->additional(['stock_metrics' => $stockMetrics]);
    }

    public function ledger(FinanceRequest $request): JsonResponse
    {
        $query = DB::table('finance_entries')->join('finance_wallets', 'finance_wallets.id', '=', 'finance_entries.wallet_id')->join('finance_transactions', 'finance_transactions.id', '=', 'finance_entries.transaction_id')
            ->where('finance_wallets.kind', 'account')->where('finance_transactions.posted', true)->whereIn('finance_wallets.account_id', $this->ids($request, 'ledger.view'))->orderByDesc('finance_entries.id');
        $this->filters($query, $request, 'finance_entries.created_at', ['finance_transactions.reference', 'finance_transactions.kind']);
        foreach (['service', 'currency'] as $field) {
            if ($request->filled($field)) {
                $query->where('finance_wallets.'.$field, $request->input($field));
            }
        }
        $page = $query->paginate((int) $request->input('per_page', 25), ['finance_entries.id', 'finance_entries.transaction_id', 'finance_wallets.account_id', 'finance_wallets.service', 'finance_wallets.currency', 'finance_entries.amount_minor', 'finance_entries.balance_after_minor', 'finance_transactions.kind', 'finance_transactions.reference', 'finance_entries.created_at']);
        $data = $page->getCollection()->map(fn ($entry): array => ['id' => (int) $entry->id, 'transaction_id' => (int) $entry->transaction_id, 'account_id' => (int) $entry->account_id, 'service' => $entry->service, 'currency' => $entry->currency, 'amount' => Money::decimal((int) $entry->amount_minor), 'balance_after' => Money::decimal((int) $entry->balance_after_minor), 'kind' => $entry->kind, 'reference' => $entry->reference, 'created_at' => $entry->created_at]);

        return response()->json(['data' => $data, 'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()]]);
    }

    public function deposits(FinanceRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->operations->deposit($request->user(), $request->validated(), $request)], 201);
    }

    public function transfers(FinanceRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->operations->transfer($request->user(), $request->validated(), $request)], 201);
    }

    public function bulkTransfers(FinanceRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->operations->bulkTransfer($request->user(), $request->validated(), $request)], 201);
    }

    public function recoverTransfer(FinanceRequest $request, int $id): JsonResponse
    {
        return response()->json(['data' => $this->operations->recover($request->user(), $id, $request->validated(), $request)], 201);
    }

    public function recoveries(FinanceRequest $request): mixed
    {
        $ids = $this->ids($request, 'wallets.view');
        $query = FundingRecovery::with(['fromAccount', 'toAccount', 'actor', 'transaction'])->where(fn ($q) => $q->whereIn('from_account_id', $ids)->orWhereIn('to_account_id', $ids))->orderByDesc('id');
        $this->filters($query, $request, 'created_at', ['reason', 'id'], ['fromAccount', 'toAccount', 'actor']);
        foreach (['service', 'currency'] as $field) {
            if ($request->filled($field)) {
                $query->whereIn('transaction_id', LedgerTransaction::where($field, $request->input($field))->select('id'));
            }
        }

        return FinanceResource::collection($query->paginate((int) $request->input('per_page', 25)));
    }

    public function transferHistory(FinanceRequest $request): JsonResponse
    {
        $ids = $this->ids($request, 'wallets.view');
        $query = DB::table('finance_transactions as t')->join('finance_entries as debit', fn ($q) => $q->on('debit.transaction_id', '=', 't.id')->where('debit.amount_minor', '<', 0))
            ->join('finance_entries as credit', fn ($q) => $q->on('credit.transaction_id', '=', 't.id')->where('credit.amount_minor', '>', 0))
            ->join('finance_wallets as sender', 'sender.id', '=', 'debit.wallet_id')->join('finance_wallets as recipient', 'recipient.id', '=', 'credit.wallet_id')
            ->where('t.posted', true)->whereIn('t.kind', ['transfer', 'funding'])->where('recipient.kind', 'account')
            ->where(fn ($q) => $q->whereIn('sender.account_id', $ids)->orWhereIn('recipient.account_id', $ids))->orderByDesc('t.id');
        $this->filters($query, $request, 't.created_at', ['t.reference']);
        if ($request->boolean('recoverable')) {
            $query->where('sender.account_id', $request->user()->membership->account_id)->where('sender.kind', 'account')->where('t.recovery_deadline', '>', now())
                ->whereRaw('credit.amount_minor > (SELECT COALESCE(SUM(amount_minor), 0) FROM finance_recoveries WHERE transfer_id = t.id)');
        }
        if ($request->filled('direction')) {
            $query->where($request->direction === 'incoming' ? 'recipient.account_id' : 'sender.account_id', $request->user()->membership->account_id);
        }
        foreach (['service', 'currency'] as $field) {
            if ($request->filled($field)) {
                $query->where('t.'.$field, $request->input($field));
            }
        }
        $page = $query->paginate((int) $request->input('per_page', 25), ['t.id', 'sender.account_id as from_account_id', 'recipient.account_id as to_account_id', 't.service', 't.currency', 't.reference', 't.created_at', 't.recovery_deadline', 'credit.amount_minor']);
        $recovered = FundingRecovery::whereIn('transfer_id', $page->getCollection()->pluck('id'))->groupBy('transfer_id')->selectRaw('transfer_id, SUM(amount_minor) as total')->pluck('total', 'transfer_id');
        $data = $page->getCollection()->map(fn ($tx): array => ['id' => (int) $tx->id, 'from_account_id' => (int) $tx->from_account_id, 'to_account_id' => (int) $tx->to_account_id, 'service' => $tx->service, 'currency' => $tx->currency, 'amount' => Money::decimal((int) $tx->amount_minor), 'recovered_amount' => Money::decimal((int) ($recovered[$tx->id] ?? 0)), 'remaining' => Money::decimal((int) $tx->amount_minor - (int) ($recovered[$tx->id] ?? 0)), 'reference' => $tx->reference, 'created_at' => $tx->created_at, 'recovery_deadline' => $tx->recovery_deadline]);

        return response()->json(['data' => $data, 'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()]]);
    }

    public function fundingRequests(FinanceRequest $request): mixed
    {
        $ids = $this->ids($request, 'wallets.view');
        $own = (int) $request->user()->membership->account_id;
        $query = FundingRequest::with(['fromAccount', 'toAccount', 'creator', 'reviewer'])->whereIn('to_account_id', $ids)->where(fn ($q) => $q->where('from_account_id', $own)->orWhere('to_account_id', $own))->orderByDesc('id');
        $this->filters($query, $request, 'created_at', ['purpose', 'reference', 'reason', 'id'], ['fromAccount', 'toAccount', 'creator']);
        if ($request->filled('direction')) {
            $query->where($request->direction === 'incoming' ? 'from_account_id' : 'to_account_id', $own);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        foreach (['service', 'currency'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->input($field));
            }
        }

        return FinanceResource::collection($query->paginate((int) $request->input('per_page', 25)));
    }

    public function storeFundingRequest(FinanceRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->operations->fundingRequest($request->user(), $request->validated(), $request)], 201);
    }

    public function reviewFundingRequest(FinanceRequest $request, int $id): JsonResponse
    {
        return response()->json(['data' => $this->operations->reviewFunding($request->user(), $id, $request->validated(), $request)]);
    }

    public function cancelFundingRequest(FinanceRequest $request, int $id): JsonResponse
    {
        return response()->json(['data' => $this->operations->cancelFunding($request->user(), $id, $request->validated(), $request)]);
    }

    public function policy(FinanceRequest $request): FinanceResource
    {
        $this->access->require($request->user(), 'security.fundingRequests', true);

        return new FinanceResource(Policy::findOrFail(1));
    }

    public function savePolicy(FinanceRequest $request): FinanceResource
    {
        $this->access->require($request->user(), 'security.fundingRequests', true);
        if ($request->has('recovery_hours')) {
            $this->access->require($request->user(), 'security.fundingRecovery', true);
        }

        return DB::transaction(function () use ($request): FinanceResource {
            app(MutationGuard::class)->lock($request->user());
            $policy = Policy::lockForUpdate()->findOrFail(1);
            $this->authority->version($policy, (int) $request->version);
            $amounts = array_map(fn (string $amount): int => Money::minor($amount), $request->amounts);
            if (count(array_unique($amounts)) !== count($amounts)) {
                throw ValidationException::withMessages(['amounts' => 'يوجد مبلغ مكرر.']);
            } sort($amounts, SORT_NUMERIC);
            $values = ['daily_limit' => (int) $request->daily_limit, 'amounts_minor' => $amounts, 'version' => $policy->version + 1];
            if ($request->has('recovery_hours')) {
                $values['recovery_hours'] = (int) $request->recovery_hours;
            }
            $policy->update($values);
            $this->audit->record('security.fundingRequests', $request, $request->user(), $request->user()->membership->account_id, $values);

            return new FinanceResource($policy);
        }, 5);
    }

    public function invoices(FinanceRequest $request): mixed
    {
        $query = Invoice::with(['account', 'creator'])->whereIn('account_id', $this->ids($request, 'invoices.view'))->orderByDesc('id');
        if ($request->filled('kind')) {
            $query->where('kind', $request->kind);
        }
        $this->filters($query, $request, 'created_at', ['reference', 'supplier', 'id'], ['account', 'creator']);
        foreach (['status', 'service', 'currency'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->input($field));
            }
        }

        return FinanceResource::collection($query->paginate((int) $request->input('per_page', 25)));
    }

    public function storeInvoice(FinanceRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->operations->invoice($request->user(), $request->validated(), $request)], 201);
    }

    public function settleInvoice(FinanceRequest $request, int $id): JsonResponse
    {
        return response()->json(['data' => $this->operations->settleInvoice($request->user(), $id, $request->validated(), $request)]);
    }

    public function priceTemplate(FinanceRequest $request): JsonResponse
    {
        $this->access->require($request->user(), 'prices.template');

        return $this->prices($request);
    }

    public function prices(FinanceRequest $request): JsonResponse
    {
        $this->access->require($request->user(), 'prices.view');
        $target = $this->access->account($request->user(), (int) $request->input('account_id', $request->user()->membership->account_id), 'prices.view');
        $priceAccountId = (int) (DB::table('account_closure')->join('accounts', 'accounts.id', '=', 'ancestor_id')->where('descendant_id', $target->id)->where('accounts.type', 'main_agent')->value('accounts.id') ?? $target->id);
        $query = $this->catalog->options($request->user(), $target)->with('provider')->orderBy('display_order')->orderBy('id');
        if ($request->has('account_ids') && ! in_array($target->id, array_map('intval', $request->input('account_ids')), true)) {
            $query->whereRaw('1 = 0');
        }
        foreach (['currency', 'provider_id'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->input($field));
            }
        }
        if ($request->filled('product_id')) {
            $query->whereKey((int) $request->product_id);
        }
        if ($request->filled('q')) {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $request->input('q')).'%';
            $query->where(fn ($names) => $names->whereRaw("catalog_products.name LIKE ? ESCAPE '!'", [$pattern])->orWhereRaw("catalog_products.id LIKE ? ESCAPE '!'", [$pattern])->orWhereHas('provider', fn ($providers) => $providers->whereRaw("name LIKE ? ESCAPE '!'", [$pattern])));
        }
        $configured = DB::table('finance_prices')->where('account_id', $priceAccountId);
        if (in_array($request->input('price_state'), ['active', 'inactive'], true)) {
            $query->where('status', $request->price_state === 'active' ? 'active' : 'disabled');
        }
        if ($request->input('price_state') === 'priced') {
            $query->whereIn('id', $configured->clone()->select('product_id'));
        }
        if ($request->input('price_state') === 'unpriced') {
            $query->whereNotIn('id', $configured->clone()->select('product_id'));
        }
        if ($request->filled('from') || $request->filled('to')) {
            $this->filters($configured, $request, 'updated_at', []);
            $query->whereIn('id', $configured->select('product_id'));
        }
        $products = $query->paginate((int) $request->input('per_page', 100));
        $prices = DB::table('finance_prices')->where('account_id', $priceAccountId)->whereIn('product_id', $products->getCollection()->pluck('id'))->get()->keyBy('product_id');
        $data = $products->getCollection()->map(fn ($p): array => ['product_id' => $p->id, 'name' => $p->name, 'face_value' => $p->face_value, 'price_updated_at' => isset($prices[$p->id]) ? CarbonImmutable::parse($prices[$p->id]->updated_at, 'UTC')->toIso8601String() : null, 'provider_id' => $p->provider_id, 'provider_name' => $p->provider->name, 'currency' => $p->currency, 'minimum_price' => $p->minimum_price, 'price' => isset($prices[$p->id]) ? Money::decimal((int) $prices[$p->id]->price_minor) : null, 'version' => isset($prices[$p->id]) ? (int) $prices[$p->id]->version : 0, 'status' => $p->status]);

        return response()->json(['data' => $data, 'meta' => ['current_page' => $products->currentPage(), 'per_page' => $products->perPage(), 'total' => $products->total(), 'last_page' => $products->lastPage()]]);
    }

    public function priceRequests(FinanceRequest $request): mixed
    {
        $query = PriceRequest::with(['account', 'creator', 'reviewer'])->whereIn('account_id', $this->ids($request, 'prices.view'))->orderByDesc('id');
        $this->filters($query, $request, 'created_at', ['reason', 'changes', 'id'], ['account', 'creator']);
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        return FinanceResource::collection($query->paginate((int) $request->input('per_page', 25)));
    }

    public function storePriceRequest(FinanceRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->operations->proposePrices($request->user(), $request->validated(), $request)], 201);
    }

    public function reviewPriceRequest(FinanceRequest $request, int $id): JsonResponse
    {
        return response()->json(['data' => $this->operations->reviewPrices($request->user(), $id, $request->validated(), $request)]);
    }

    public function reversePriceRequest(FinanceRequest $request, int $id): JsonResponse
    {
        return response()->json(['data' => $this->operations->reversePrices($request->user(), $id, $request->validated(), $request)]);
    }
}

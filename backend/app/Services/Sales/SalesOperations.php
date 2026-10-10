<?php

namespace App\Services\Sales;

use App\Enums\AccountType;
use App\Http\Resources\Sales\SaleResource;
use App\Models\Account;
use App\Models\Sales\DeviceSession;
use App\Models\Sales\Sale;
use App\Models\Stock\StockBatch;
use App\Models\Stock\StockCard;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Finance\FinanceOperations;
use App\Services\Finance\Ledger;
use App\Services\Finance\Money;
use App\Services\ManagementAuthority;
use App\Services\Stock\StockValuation;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SalesOperations
{
    public function __construct(private SalesAccess $access, private SalesPolicies $policies, private FinanceOperations $operations, private Ledger $ledger, private ManagementAuthority $authority, private AuditLogger $audit, private ReceiptDesign $design) {}

    public function dto(Sale $sale, Request $request): array
    {
        $sale->loadMissing(['mainAccount', 'provider', 'account', 'creator', 'product']);

        return (new SaleResource($sale))->resolve($request);
    }

    public function heartbeat(User $actor, array $data, Request $request): array
    {
        $own = $this->access->own($actor, 'sell.create');
        abort_unless($own->type === AccountType::Pos, 403);
        $device = DeviceSession::updateOrCreate(['session_hash' => hash('sha256', $request->session()->getId())], ['account_id' => $own->id, 'user_id' => $actor->id, 'session_version' => $actor->session_version, 'serial' => $data['serial'] ?? null, 'app_version' => $data['app_version'], 'os_version' => $data['os_version'] ?? null, 'last_seen_at' => now(), 'expires_at' => now()->addSeconds(90)]);

        return ['online' => true, 'device_lock_enabled' => $own->device_lock_enabled, 'expires_at' => $device->expires_at->toISOString(), 'app_version' => $device->app_version, 'os_version' => $device->os_version];
    }

    public function create(User $actor, array $data, Request $request, bool $issue): array
    {
        $this->access->own($actor, 'sell.create');
        if ($issue) {
            $this->access->require($actor, 'data.pin');
        }

        return $this->operations->execute($actor, $issue ? 'sales.create' : 'sales.reserve', $data, function () use ($actor, $data, $request, $issue): array {
            $own = $this->access->own($actor, 'sell.create', true);
            $main = $this->access->main($own);
            $product = $this->access->product($actor, $own, (int) $data['product_id']);
            $price = DB::table('finance_prices')->where('account_id', $main->id)->where('product_id', $product->id)->lockForUpdate()->first();
            abort_unless($price && $price->currency === $product->currency && (int) $price->price_minor > 0, 422, 'لا يوجد سعر معتمد صالح لهذه الفئة.');
            abort_unless((int) $price->version === (int) $data['price_version'] && (int) $price->price_minor === Money::minor($data['expected_price']), 409, 'تغير سعر الفئة؛ أعد تحميل سعر البيع.');
            abort_if($product->minimum_price !== null && (int) $price->price_minor < Money::minor($product->minimum_price), 422, 'سعر الفئة أقل من الحد المعتمد.');
            $quantity = (int) $data['quantity'];
            $total = Money::multiply((int) $price->price_minor, $quantity);
            $retail = isset($data['retail_price']) ? Money::minor($data['retail_price'], 'retail_price') : (int) $price->price_minor;
            $context = $this->policies->context($own, $product->id);
            $this->access->device($actor, $own, $request, $context['settings']);
            $this->policies->checkSale($own, $product, $quantity, $total, $context);
            if (class_exists(StockValuation::class)) {
                app(StockValuation::class)->revalue($actor, $main->id, $product->id, (int) $price->price_minor, 'SALE:'.$data['idempotency_key'], $request);
            }
            $batches = StockBatch::where('account_id', $main->id)->where('product_id', $product->id)->where('currency', $product->currency)->whereIn('status', ['Loaded', 'Partially Used'])->orderBy('id')->lockForUpdate()->get();
            $pick = StockCard::where('account_id', $main->id)->where('product_id', $product->id)->whereIn('batch_id', $batches->pluck('id'))->where('status', 'Available')->where('credit_held', false)->whereNull('sale_id')->where('expiry', '>', now('Asia/Baghdad')->toDateString())->orderBy('expiry')->orderBy('created_at')->orderBy('id')->limit($quantity)->pluck('id');
            abort_unless($pick->count() === $quantity, 422, 'المخزون المتاح غير كافٍ.');
            $cards = StockCard::whereIn('id', $pick)->orderBy('id')->lockForUpdate()->get();
            abort_unless($cards->every(fn (StockCard $card): bool => $card->status === 'Available' && ! $card->credit_held && $card->sale_id === null && $card->credit_minor === (int) $price->price_minor), 409, 'تغير المخزون أثناء الاختيار؛ أعد المحاولة.');
            $credit = $this->sum($cards, 'credit_minor');
            $cost = $this->sum($cards, 'cost_minor');
            $loadCost = 0;
            $batchMap = $batches->keyBy('id');
            foreach ($cards as $card) {
                $loadCost = $this->add($loadCost, $batchMap[$card->batch_id]->load_price_minor);
            }
            $wallet = $this->ledger->wallet($own->id, 'voucher', $product->currency);
            abort_unless($wallet->balance_minor - $wallet->held_minor >= $credit, 422, 'رصيد البطاقات التشغيلي غير كافٍ؛ موّل حسابك من محفظة البطاقات.');
            $sale = Sale::create(['account_id' => $own->id, 'main_account_id' => $main->id, 'creator_id' => $actor->id, 'product_id' => $product->id, 'provider_id' => $product->provider_id, 'currency' => $product->currency, 'quantity' => $quantity, 'price_minor' => (int) $price->price_minor, 'total_minor' => $total, 'retail_price_minor' => $retail, 'retail_total_minor' => Money::multiply($retail, $quantity), 'credit_minor' => $credit, 'cost_minor' => $cost, 'load_cost_minor' => $loadCost, 'price_version' => (int) $price->version, 'status' => 'Reserved', 'version' => 1]);
            foreach ($cards as $card) {
                $card->update(['sale_id' => $sale->id, 'status' => 'Reserved', 'version' => $card->version + 1]);
            }
            StockBatch::whereIn('id', $cards->pluck('batch_id')->unique())->update(['version' => DB::raw('version + 1'), 'updated_at' => now()]);
            $wallet->update(['held_minor' => $wallet->held_minor + $credit, 'version' => $wallet->version + 1]);
            if ($issue) {
                $this->issueLocked($actor, $sale, $own, $cards, $data['idempotency_key'], $request);
            }
            $this->audit->record($issue ? 'sell.create' : 'sales.reserve', $request, $actor, $own->id, ['sale_id' => $sale->id, 'product_id' => $product->id, 'quantity' => $quantity, 'credit' => Money::decimal($credit)]);

            return $this->dto($sale, $request);
        });
    }

    private function add(int $current, int $amount): int
    {
        abort_unless($amount >= 0 && $current <= Money::MAX_MINOR - $amount, 422, 'القيمة الإجمالية تتجاوز الحد المسموح.');

        return $current + $amount;
    }

    private function sum(Collection $cards, string $field): int
    {
        $total = 0;
        foreach ($cards as $card) {
            $total = $this->add($total, (int) $card->{$field});
        }

        return $total;
    }

    private function selected(Sale $sale): Collection
    {
        StockBatch::whereIn('id', StockCard::where('sale_id', $sale->id)->select('batch_id'))->orderBy('id')->lockForUpdate()->get();

        return StockCard::where('sale_id', $sale->id)->orderBy('id')->lockForUpdate()->get();
    }

    public function issue(User $actor, int $id, array $data, Request $request): array
    {
        $this->access->sale($actor, $id, 'sell.create', true);
        $this->access->require($actor, 'data.pin');

        return $this->operations->execute($actor, 'sales.issue', $data + ['sale_id' => $id], function () use ($actor, $id, $data, $request): array {
            $own = $this->access->own($actor, 'sell.create', true);
            $sale = $this->access->sale($actor, $id, 'sell.create', true, true);
            $this->authority->version($sale, (int) $data['version']);
            abort_unless($sale->status === 'Reserved', 409, 'الحجز صادر أو ملغى.');
            $product = $this->access->product($actor, $own, $sale->product_id);
            $price = DB::table('finance_prices')->where('account_id', $sale->main_account_id)->where('product_id', $sale->product_id)->lockForUpdate()->first();
            abort_unless($price && $price->currency === $sale->currency && (int) $price->price_minor === $sale->price_minor && (int) $price->version === $sale->price_version, 409, 'تغير السعر؛ ألغِ الحجز وأعده بالسعر الجديد.');
            $context = $this->policies->context($own, $sale->product_id);
            $this->access->device($actor, $own, $request, $context['settings']);
            $this->policies->checkSale($own, $product, $sale->quantity, $sale->total_minor, $context);
            $this->issueLocked($actor, $sale, $own, $this->selected($sale), $data['idempotency_key'], $request);

            return $this->dto($sale, $request);
        });
    }

    private function issueLocked(User $actor, Sale $sale, Account $own, Collection $cards, string $key, Request $request): void
    {
        abort_unless($cards->count() === $sale->quantity && $cards->every(fn (StockCard $c): bool => $c->status === 'Reserved' && ! $c->credit_held && $c->expiry->toDateString() > now('Asia/Baghdad')->toDateString()), 409, 'الحجز غير صالح أو بعض بطاقاته منتهية؛ ألغِه وأعد المحاولة.');
        $wallet = $this->ledger->wallet($own->id, 'voucher', $sale->currency);
        abort_unless($wallet->held_minor >= $sale->credit_minor, 409, 'رصيد الحجز غير متطابق.');
        $wallet->update(['held_minor' => $wallet->held_minor - $sale->credit_minor, 'version' => $wallet->version + 1]);
        $external = $this->ledger->wallet((int) Account::where('type', AccountType::System)->value('id'), 'voucher', $sale->currency, 'external');
        $tx = $this->ledger->pair($actor, 'sale', 'SALE:'.hash('sha256', $key), ['sale_id' => $sale->id, 'quantity' => $sale->quantity], $wallet, $external, $sale->credit_minor, 'إصدار بطاقات #'.$sale->id);
        $sale->update(['transaction_id' => $tx->id, 'status' => 'Print Requested', 'issued_at' => now(), 'version' => $sale->version + 1]);
        foreach ($cards as $card) {
            $card->update(['status' => 'Issued', 'version' => $card->version + 1]);
        }
        $this->refreshBatches($cards);
        $this->audit->record('sales.issue', $request, $actor, $own->id, ['sale_id' => $sale->id, 'transaction_id' => $tx->id]);
    }

    public function cancel(User $actor, int $id, array $data, Request $request): array
    {
        $this->access->sale($actor, $id, 'sell.create', true);

        return $this->operations->execute($actor, 'sales.cancel-reservation', $data + ['sale_id' => $id], function () use ($actor, $id, $data, $request): array {
            $own = $this->access->own($actor, 'sell.create', true);
            $sale = $this->access->sale($actor, $id, 'sell.create', true, true);
            $this->authority->version($sale, (int) $data['version']);
            abort_unless($sale->status === 'Reserved' && $sale->transaction_id === null && $sale->exposed_at === null, 409, 'لا يمكن فك حجز صادر أو مكشوف.');
            $cards = $this->selected($sale);
            abort_unless($cards->count() === $sale->quantity && $cards->every(fn (StockCard $c): bool => $c->status === 'Reserved'), 409);
            $wallet = $this->ledger->wallet($own->id, 'voucher', $sale->currency);
            abort_unless($wallet->held_minor >= $sale->credit_minor, 409);
            $wallet->update(['held_minor' => $wallet->held_minor - $sale->credit_minor, 'version' => $wallet->version + 1]);
            foreach ($cards as $card) {
                $card->update(['sale_id' => null, 'status' => 'Available', 'version' => $card->version + 1]);
            }
            StockBatch::whereIn('id', $cards->pluck('batch_id')->unique())->update(['version' => DB::raw('version + 1'), 'updated_at' => now()]);
            $sale->update(['status' => 'Cancelled', 'version' => $sale->version + 1]);
            $this->audit->record('sales.cancel-reservation', $request, $actor, $own->id, ['sale_id' => $sale->id]);

            return $this->dto($sale, $request);
        });
    }

    private function refreshBatches(Collection $cards): void
    {
        foreach ($cards->pluck('batch_id')->unique() as $batchId) {
            $batch = StockBatch::whereKey($batchId)->lockForUpdate()->firstOrFail();
            $remaining = StockCard::where('batch_id', $batchId)->whereIn('status', ['Available', 'Reserved', 'Quarantined'])->exists();
            $batch->update(['status' => $remaining ? 'Partially Used' : 'Sold Out', 'version' => $batch->version + 1]);
        }
    }

    public function receipt(User $actor, int $id, Request $request): array
    {
        $this->access->sale($actor, $id, 'sell.receipt', true);
        $this->access->require($actor, 'data.pin');

        return DB::transaction(function () use ($actor, $id, $request): array {
            $sale = $this->access->sale($actor, $id, 'sell.receipt', true, true);
            abort_unless($sale->issued_at && ! in_array($sale->status, ['Reserved', 'Cancelled'], true), 409, 'لا تكشف الرموز قبل إصدار الحجز.');
            if (! $sale->exposed_at) {
                $sale->update(['exposed_at' => now(), 'version' => $sale->version + 1]);
            }
            $sale->load(['product.provider', 'cards', 'account']);
            $cards = $sale->cards->map(fn (StockCard $card): array => ['id' => $card->id, 'serial' => $card->serial, 'expiry' => $card->expiry->toDateString(), 'fields' => $card->secret])->all();
            $this->audit->record('sell.receipt', $request, $actor, $sale->account_id, ['sale_id' => $sale->id, 'quantity' => $sale->quantity]);

            $context = $this->policies->context($sale->account, $sale->product_id);

            return ['sale' => $this->dto($sale, $request), 'cards' => $cards, 'design' => $this->design->resolve($sale->product, $this->access->main($sale->account)), 'extra_fields' => $sale->product->extra_fields, 'print_policy' => $context['policy'], 'daily_print' => $this->policies->usage($sale->account, $context), 'print_wait_seconds' => $this->policies->wait($sale->account, $context)];
        });
    }
}

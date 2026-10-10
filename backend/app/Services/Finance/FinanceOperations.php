<?php

namespace App\Services\Finance;

use App\Enums\AccountType;
use App\Http\Resources\Finance\FinanceResource;
use App\Models\CatalogProduct;
use App\Models\Finance\FundingRecovery;
use App\Models\Finance\FundingRequest;
use App\Models\Finance\Invoice;
use App\Models\Finance\LedgerTransaction;
use App\Models\Finance\Policy;
use App\Models\Finance\PriceRequest;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\CatalogAccess;
use App\Services\ManagementAuthority;
use App\Services\Operations\MutationGuard;
use App\Services\Stock\StockFunding;
use App\Services\Stock\StockValuation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FinanceOperations
{
    public function __construct(public Ledger $ledger, private FinanceAccess $access, private AuditLogger $audit, private ManagementAuthority $authority, private CatalogAccess $catalog) {}

    public function execute(User $actor, string $action, array $data, callable $work): array
    {
        return DB::transaction(function () use ($actor, $action, $data, $work): array {
            $guard = app(MutationGuard::class);
            $guard->lock($actor, $guard->targets($data), $action);
            $hash = Ledger::hash([$action, $data]);
            DB::table('finance_operations')->insertOrIgnore(['actor_id' => $actor->id, 'action' => $action, 'idempotency_key' => $data['idempotency_key'], 'payload_hash' => $hash, 'created_at' => now()]);
            $op = DB::table('finance_operations')->where('actor_id', $actor->id)->where('idempotency_key', $data['idempotency_key'])->lockForUpdate()->first();
            abort_unless($op && $op->payload_hash === $hash && $op->action === $action, 409, 'معرف العملية مستخدم لبيانات مختلفة.');
            if ($op->response !== null) {
                return json_decode($op->response, true, 512, JSON_THROW_ON_ERROR);
            }
            $response = $work();
            DB::table('finance_operations')->where('id', $op->id)->update(['response' => json_encode($response, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]);

            return $response;
        }, 5);
    }

    public function resource(mixed $record, Request $request): array
    {
        return (new FinanceResource($record))->resolve($request);
    }

    public function deposit(User $actor, array $data, Request $request): array
    {
        $this->access->require($actor, 'wallets.deposit', true);
        $this->access->account($actor, (int) $data['account_id'], 'wallets.deposit');
        $this->access->service($data['service'], $data['currency']);
        if ($data['service'] === 'voucher') {
            throw ValidationException::withMessages(['service' => 'تمويل محفظة الكارتات يتطلب طلبية مخزون معتمدة؛ لا يسمح بإيداع رصيد دون مخزون.']);
        }

        return $this->execute($actor, 'wallets.deposit', $data, function () use ($actor, $data, $request): array {
            $this->access->account($actor, (int) $data['account_id'], 'wallets.deposit', true);
            $amount = Money::minor($data['amount']);
            $clearing = $this->ledger->wallet($actor->membership->account_id, $data['service'], $data['currency'], 'external');
            $to = $this->ledger->wallet((int) $data['account_id'], $data['service'], $data['currency']);
            $tx = $this->ledger->pair($actor, 'deposit', $data['idempotency_key'], $data, $clearing, $to, $amount, $data['reference']);
            $this->audit->record('wallets.deposit', $request, $actor, (int) $data['account_id'], ['transaction_id' => $tx->id, 'amount' => Money::decimal($amount), 'reference' => $data['reference']]);

            return $this->resource($tx, $request);
        });
    }

    public function transfer(User $actor, array $data, Request $request): array
    {
        $this->access->account($actor, (int) $data['to_account_id'], 'wallets.transfer');
        $this->access->require($actor, 'wallets.transfer');
        $this->access->service($data['service'], $data['currency']);

        return $this->execute($actor, 'wallets.transfer', $data, function () use ($actor, $data, $request): array {
            [$from,$to] = $this->access->transfer($actor, (int) $data['from_account_id'], (int) $data['to_account_id']);
            $amount = Money::minor($data['amount']);
            $debit = $this->ledger->wallet($from->id, $data['service'], $data['currency']);
            $credit = $this->ledger->wallet($to->id, $data['service'], $data['currency']);
            $tx = $this->ledger->pair($actor, 'transfer', $data['idempotency_key'], $data, $debit, $credit, $amount, $data['reference']);
            $this->audit->record('wallets.transfer', $request, $actor, $to->id, ['transaction_id' => $tx->id, 'from' => $from->id, 'amount' => Money::decimal($amount)]);

            return $this->resource($tx, $request);
        });
    }

    public function bulkTransfer(User $actor, array $data, Request $request): array
    {
        $this->access->require($actor, 'wallets.bulk');
        $this->access->require($actor, 'wallets.transfer');
        $own = $this->access->bulkFunder($actor, (int) ($data['from_account_id'] ?? $actor->membership->account_id));
        $this->access->service($data['service'], $data['currency']);
        foreach ($data['rows'] as $row) {
            $this->access->account($actor, (int) $row['to_account_id'], 'wallets.transfer');
        }

        return $this->execute($actor, 'wallets.bulk', $data, function () use ($actor, $data, $request, $own): array {
            $result = [];
            $own = $this->access->bulkFunder($actor, $own->id, true);
            $seen = [];
            foreach ($data['rows'] as $index => $row) {
                $to = $this->access->descendant($actor, $own, (int) $row['to_account_id'], 'wallets.transfer');
                $service = $row['service'] ?? $data['service'];
                $currency = $row['currency'] ?? $data['currency'];
                $this->access->service($service, $currency);
                $recipientKey = $to->id.':'.$service.':'.$currency;
                if (isset($seen[$recipientKey])) {
                    throw ValidationException::withMessages(['rows' => 'المستفيد والمحفظة والعملة مكررة في المجموعة.']);
                }
                $seen[$recipientKey] = true;
                $reference = $row['reference'] ?? $data['reference'];
                if (! empty($row['reference']) && LedgerTransaction::where('kind', 'transfer')->where('reference', $reference)->whereExists(function ($q) use ($own): void {
                    $q->selectRaw('1')->from('finance_entries')->join('finance_wallets', 'finance_wallets.id', '=', 'wallet_id')->whereColumn('transaction_id', 'finance_transactions.id')->where('amount_minor', '<', 0)->where('finance_wallets.account_id', $own->id);
                })->exists()) {
                    throw ValidationException::withMessages(['rows' => 'مرجع التمويل مستخدم سابقًا لهذا الوكيل.']);
                }
                $debit = $this->ledger->wallet($own->id, $service, $currency);
                $credit = $this->ledger->wallet($to->id, $service, $currency);
                $key = 'BULK:'.hash('sha256', $data['idempotency_key'].':'.$index);
                $tx = $this->ledger->pair($actor, 'transfer', $key, ['group' => $data['idempotency_key'], 'row' => $row], $debit, $credit, Money::minor($row['amount']), $reference ?: $data['reference']);
                $result[] = ['from_account_id' => $own->id, 'to_account_id' => $to->id, 'service' => $service, 'currency' => $currency, 'amount' => $row['amount'], 'transaction_id' => $tx->id];
            }
            $this->audit->record('wallets.bulk', $request, $actor, $own->id, ['transfers' => $result]);

            return $result;
        });
    }

    public function recover(User $actor, int $id, array $data, Request $request): array
    {
        $own = $this->access->own($actor, 'wallets.reverse');
        $query = LedgerTransaction::whereKey($id)->where('posted', true)->whereIn('kind', ['transfer', 'funding'])->whereExists(function ($q) use ($own): void {
            $q->selectRaw('1')->from('finance_entries')->join('finance_wallets', 'finance_wallets.id', '=', 'finance_entries.wallet_id')
                ->whereColumn('transaction_id', 'finance_transactions.id')->where('amount_minor', '<', 0)->where('finance_wallets.kind', 'account')->where('account_id', $own->id);
        });
        $query->firstOrFail();

        return $this->execute($actor, 'wallets.reverse', $data + ['transfer_id' => $id], function () use ($actor, $id, $data, $request, $own, $query): array {
            $original = $query->lockForUpdate()->firstOrFail();
            abort_unless($original->recovery_deadline && now()->lt($original->recovery_deadline) && now()->gte($original->created_at), 409, 'انتهت مهلة استرجاع هذا التمويل.');
            $creditEntry = DB::table('finance_entries')->join('finance_wallets', 'finance_wallets.id', '=', 'finance_entries.wallet_id')->where('transaction_id', $id)->where('amount_minor', '>', 0)->where('kind', 'account')->first();
            abort_unless($creditEntry, 409);
            $target = $this->access->account($actor, (int) $creditEntry->account_id, 'wallets.reverse', true);
            $this->access->descendant($actor, $own, $target->id, 'wallets.reverse');
            abort_unless($own->type !== AccountType::System, 403, 'الاسترجاع من محفظة الممول الأصلي فقط.');
            $amount = Money::minor($data['amount']);
            $recovered = (int) FundingRecovery::where('transfer_id', $id)->sum('amount_minor');
            if ($amount > (int) $creditEntry->amount_minor - $recovered) {
                throw ValidationException::withMessages(['amount' => 'المبلغ يتجاوز المتبقي القابل للاسترجاع من التمويل الأصلي.']);
            }
            $debit = $this->ledger->wallet($target->id, $original->service, $original->currency);
            $credit = $this->ledger->wallet($own->id, $original->service, $original->currency);
            $tx = $this->ledger->pair($actor, 'recovery', $data['idempotency_key'], ['transfer_id' => $id] + $data, $debit, $credit, $amount, 'استرجاع تمويل #'.$id);
            $recovery = FundingRecovery::create(['transfer_id' => $id, 'transaction_id' => $tx->id, 'from_account_id' => $own->id, 'to_account_id' => $target->id, 'actor_id' => $actor->id, 'amount_minor' => $amount, 'reason' => $data['reason'], 'created_at' => now()]);
            $this->audit->record('wallets.reverse', $request, $actor, $target->id, ['recovery_id' => $recovery->id, 'transfer_id' => $id, 'amount' => Money::decimal($amount), 'reason' => $data['reason']]);

            return $this->resource($recovery, $request);
        });
    }

    public function fundingRequest(User $actor, array $data, Request $request): array
    {
        $account = $this->access->own($actor, 'wallets.request');
        abort_unless($account->parent_id !== null && $account->type !== AccountType::System, 403, 'هذا الحساب ليس له ممول أعلى.');
        $this->access->service($data['service'], $data['currency']);

        return $this->execute($actor, 'wallets.request', $data, function () use ($actor, $data, $request): array {
            $account = $this->access->own($actor, 'wallets.request', true);
            $amount = Money::minor($data['amount']);
            abort_unless($account->parent && $account->parent->isOperational(), 409, 'حساب الجهة الأعلى موقوف.');
            if ($account->type === AccountType::Pos) {
                $policy = Policy::findOrFail(1);
                $start = now('Asia/Baghdad')->startOfDay()->utc();
                $end = $start->copy()->addDay();
                if (! in_array($amount, $policy->amounts_minor, true)) {
                    throw ValidationException::withMessages(['amount' => 'اختر مبلغاً من مبالغ طلبات التمويل المعتمدة.']);
                }
                if (FundingRequest::where('to_account_id', $account->id)->where('created_at', '>=', $start)->where('created_at', '<', $end)->count() >= $policy->daily_limit) {
                    throw ValidationException::withMessages(['amount' => 'وصلت إلى الحد اليومي لطلبات التمويل؛ يمكنك تقديم طلب جديد غداً بتوقيت بغداد.']);
                }
            }
            $record = FundingRequest::create(['from_account_id' => $account->parent_id, 'to_account_id' => $account->id, 'creator_id' => $actor->id, 'service' => $data['service'], 'currency' => $data['currency'], 'amount_minor' => $amount, 'purpose' => $data['purpose'] ?? '']);
            $this->audit->record('wallets.request', $request, $actor, $account->id, ['request_id' => $record->id, 'amount' => Money::decimal($amount)]);

            return $this->resource($record, $request);
        });
    }

    public function reviewFunding(User $actor, int $id, array $data, Request $request): array
    {
        $own = $this->access->own($actor, 'wallets.approve');
        $record = FundingRequest::where('from_account_id', $own->id)->findOrFail($id);
        $this->access->account($actor, $record->to_account_id, 'wallets.approve');

        return $this->execute($actor, 'wallets.approve', $data + ['request_id' => $id], function () use ($actor, $id, $data, $request, $own): array {
            $record = FundingRequest::where('from_account_id', $own->id)->lockForUpdate()->findOrFail($id);
            $this->authority->version($record, $data['version']);
            abort_unless($record->status === 'pending', 409, 'تمت معالجة الطلب مسبقاً.');
            $target = $this->access->account($actor, $record->to_account_id, 'wallets.approve', true);
            abort_unless($target->parent_id === $own->id, 409, 'تغير تسلسل الحساب؛ أعد تقديم الطلب.');
            $this->access->service($record->service, $record->currency);
            if ($data['decision'] === 'approve') {
                if ($own->type === AccountType::System && $record->service === 'voucher') {
                    $this->access->require($actor, 'import.approve', true);
                    if (empty($data['stock_batch_id']) || ! class_exists(StockFunding::class)) {
                        throw ValidationException::withMessages(['stock_batch_id' => 'اختر طلبية مخزون معتمدة بعد الطلب وبنفس قيمته.']);
                    }
                    $record->transaction_id = app(StockFunding::class)->bind($actor, $record, (int) $data['stock_batch_id']);
                    $record->stock_batch_id = (int) $data['stock_batch_id'];
                } else {
                    if ($own->type === AccountType::System) {
                        $this->access->require($actor, 'wallets.deposit', true);
                        $debit = $this->ledger->wallet($own->id, $record->service, $record->currency, 'external');
                    } else {
                        $debit = $this->ledger->wallet($own->id, $record->service, $record->currency);
                    }
                    $credit = $this->ledger->wallet($target->id, $record->service, $record->currency);
                    $tx = $this->ledger->pair($actor, 'funding', $data['idempotency_key'], ['request_id' => $id] + $data, $debit, $credit, $record->amount_minor, $data['reference']);
                    $record->transaction_id = $tx->id;
                }
                $record->reference = $data['reference'];
                $record->status = 'approved';
            } else {
                $record->status = 'rejected';
                $record->reason = $data['reason'];
            }
            $record->reviewer_id = $actor->id;
            $record->reviewed_at = now();
            $record->version++;
            $record->save();
            $this->audit->record('wallets.approve', $request, $actor, $target->id, ['request_id' => $id, 'decision' => $data['decision'], 'transaction_id' => $record->transaction_id]);

            return $this->resource($record, $request);
        });
    }

    public function cancelFunding(User $actor, int $id, array $data, Request $request): array
    {
        $own = $this->access->own($actor, 'wallets.request');
        FundingRequest::where('to_account_id', $own->id)->findOrFail($id);

        return $this->execute($actor, 'wallets.cancel', $data + ['request_id' => $id], function () use ($actor, $id, $data, $request, $own): array {
            $record = FundingRequest::where('to_account_id', $own->id)->lockForUpdate()->findOrFail($id);
            $this->authority->version($record, $data['version']);
            abort_unless($record->status === 'pending', 409, 'يمكن إلغاء الطلب قبل الموافقة فقط.');
            $record->update(['status' => 'cancelled', 'version' => $record->version + 1, 'reviewed_at' => now(), 'reviewer_id' => $actor->id]);
            $this->audit->record('wallets.cancel', $request, $actor, $own->id, ['request_id' => $id]);

            return $this->resource($record, $request);
        });
    }

    public function invoice(User $actor, array $data, Request $request): array
    {
        $this->access->account($actor, (int) $data['account_id'], 'invoices.create');
        $this->access->service($data['service'], $data['currency']);

        return $this->execute($actor, 'invoices.create', $data, function () use ($actor, $data, $request): array {
            $account = $this->access->account($actor, (int) $data['account_id'], 'invoices.create', true);
            $record = Invoice::create(['account_id' => $account->id, 'creator_id' => $actor->id, 'kind' => $data['kind'], 'supplier' => $data['supplier'] ?? '', 'service' => $data['service'], 'currency' => $data['currency'], 'amount_minor' => Money::minor($data['amount']), 'reference' => $data['reference']]);
            $this->audit->record('invoices.create', $request, $actor, $account->id, ['invoice_id' => $record->id]);

            return $this->resource($record, $request);
        });
    }

    public function settleInvoice(User $actor, int $id, array $data, Request $request): array
    {
        $this->access->require($actor, 'invoices.settle');
        $record = Invoice::whereIn('account_id', $this->access->accounts($actor)->select('id'))->findOrFail($id);

        return $this->execute($actor, 'invoices.settle', $data + ['invoice_id' => $id], function () use ($actor, $id, $data, $request): array {
            $record = Invoice::whereIn('account_id', $this->access->accounts($actor)->select('id'))->lockForUpdate()->findOrFail($id);
            $this->authority->version($record, $data['version']);
            abort_unless($record->status !== 'cancelled', 409, 'الفاتورة ملغاة.');
            $topup = $record->source_type === 'digital_topup';
            if ($topup) {
                abort_unless($actor->membership->account_id === $record->creditor_account_id || $actor->membership->account->type === AccountType::System, 403, 'التحصيل للوكيل الدائن أو إدارة النظام فقط.');
            }
            $this->access->account($actor, $record->account_id, 'invoices.settle', true);
            $this->access->service($record->service, $record->currency);
            $amount = Money::minor($data['amount']);
            if ($amount > $record->amount_minor - $record->paid_minor) {
                throw ValidationException::withMessages(['amount' => 'المبلغ يتجاوز المتبقي من الفاتورة.']);
            }
            $settlementService = $record->service === 'voucher' || $topup ? 'cash' : $record->service;
            if ($record->kind === 'receivable') {
                $this->access->require($actor, $topup ? 'invoices.settle' : 'wallets.deposit', ! $topup);
                $collector = $topup ? $record->creditor_account_id : $actor->membership->account_id;
                $external = $this->ledger->wallet($collector, $settlementService, $record->currency, 'external');
                $debit = $external;
                $credit = $this->ledger->wallet($collector, $settlementService, $record->currency);
            } else {
                $debit = $this->ledger->wallet($record->account_id, $settlementService, $record->currency);
                $credit = $this->ledger->wallet($record->account_id, $settlementService, $record->currency, 'external');
            }
            $tx = $this->ledger->pair($actor, 'invoice-settlement', $data['idempotency_key'], ['invoice_id' => $id] + $data, $debit, $credit, $amount, $data['reference']);
            $record->paid_minor += $amount;
            $record->status = $record->paid_minor === $record->amount_minor ? 'paid' : 'partial';
            $record->version++;
            $record->save();
            $this->audit->record('invoices.settle', $request, $actor, $record->account_id, ['invoice_id' => $id, 'transaction_id' => $tx->id, 'amount' => Money::decimal($amount)]);

            return $this->resource($record, $request);
        });
    }

    public function proposePrices(User $actor, array $data, Request $request): array
    {
        $target = $this->access->account($actor, (int) $data['account_id'], 'prices.propose');
        abort_unless($target->type === AccountType::MainAgent, 422, 'الأسعار مخصصة للوكيل الرئيسي.');

        return $this->execute($actor, 'prices.propose', $data, function () use ($actor, $data, $request, $target): array {
            $target = $this->access->account($actor, $target->id, 'prices.propose', true);
            if (($data['source'] ?? 'manual') === 'import') {
                $this->access->require($actor, 'prices.import');
            }
            $changes = [];
            $products = $this->catalog->options($actor, $target)->with('provider')->whereIn('id', array_column($data['changes'], 'product_id'))->get()->keyBy('id');
            foreach ($data['changes'] as $change) {
                $product = $products->get($change['product_id']);
                abort_unless($product && $product->status === 'active' && $product->provider->status === 'active', 404);
                $price = Money::minor($change['price'], 'price');
                $old = DB::table('finance_prices')->where('account_id', $target->id)->where('product_id', $product->id)->lockForUpdate()->first();
                $expected = $change['expected_price'] === null ? null : Money::minor($change['expected_price'], 'expected_price');
                abort_unless(($old === null ? null : (int) $old->price_minor) === $expected, 409, 'تغير أحد الأسعار؛ أعد المعاينة.');
                if ($product->minimum_price !== null && $price < Money::minor($product->minimum_price, 'minimum_price')) {
                    throw ValidationException::withMessages(['price' => 'السعر أقل من الحد المسموح.']);
                }
                if ($old && (int) $old->price_minor === $price) {
                    continue;
                }
                $changes[] = ['product_id' => $product->id, 'name' => $product->name, 'currency' => $product->currency, 'old_minor' => $expected, 'price_minor' => $price];
            }
            if (! $changes) {
                throw ValidationException::withMessages(['changes' => 'لا يوجد تغيير في الأسعار.']);
            }
            $record = PriceRequest::create(['account_id' => $target->id, 'creator_id' => $actor->id, 'changes' => $changes]);
            $ownMain = $actor->membership->kind === 'owner' && $actor->membership->account->type === AccountType::MainAgent && $actor->membership->account_id === $target->id;
            if ($ownMain || ($actor->membership->account->type === AccountType::System && in_array('prices.approve', $actor->membership->permissions(), true))) {
                if (! $ownMain) {
                    $this->access->require($actor, 'prices.approve', true);
                }
                $this->applyPrices($record, $actor, $data['idempotency_key'], $request);
                $record->update(['status' => 'approved', 'reviewer_id' => $actor->id, 'reviewed_at' => now(), 'version' => 2]);
            }
            $this->audit->record('prices.propose', $request, $actor, $target->id, ['request_id' => $record->id, 'status' => $record->status, 'changes' => $changes]);

            return $this->resource($record, $request);
        });
    }

    private function applyPrices(PriceRequest $record, User $actor, string $key, Request $request): void
    {
        foreach ($record->changes as $change) {
            $current = DB::table('finance_prices')->where('account_id', $record->account_id)->where('product_id', $change['product_id'])->lockForUpdate()->first();
            abort_unless(($current === null ? null : (int) $current->price_minor) === $change['old_minor'], 409, 'تغير أحد الأسعار؛ أعد المعاينة.');
            $product = CatalogProduct::with('provider')->lockForUpdate()->findOrFail($change['product_id']);
            abort_unless($product->status === 'active' && $product->provider->status === 'active' && $product->currency === $change['currency'], 409, 'تغيرت حالة الفئة أو عملتها.');
            if ($product->minimum_price !== null && $change['price_minor'] < Money::minor($product->minimum_price, 'minimum_price')) {
                throw ValidationException::withMessages(['price' => 'السعر أقل من الحد المسموح.']);
            }
            $values = ['request_id' => $record->id, 'price_minor' => $change['price_minor'], 'currency' => $change['currency'], 'version' => ($current?->version ?? 0) + 1, 'updated_at' => now()];
            if ($current) {
                DB::table('finance_prices')->where('id', $current->id)->update($values);
            } else {
                DB::table('finance_prices')->insert($values + ['account_id' => $record->account_id, 'product_id' => $change['product_id'], 'created_at' => now()]);
            }
            if (class_exists(StockValuation::class)) {
                app(StockValuation::class)->revalue($actor, (int) $record->account_id, (int) $change['product_id'], (int) $change['price_minor'], 'PRICE:'.hash('sha256', $key.':'.$change['product_id']), $request);
            }
        }
    }

    public function reviewPrices(User $actor, int $id, array $data, Request $request): array
    {
        $this->access->require($actor, 'prices.approve', true);
        PriceRequest::whereIn('account_id', $this->access->accounts($actor)->select('id'))->findOrFail($id);

        return $this->execute($actor, 'prices.approve', $data + ['request_id' => $id], function () use ($actor, $id, $data, $request): array {
            $record = PriceRequest::whereIn('account_id', $this->access->accounts($actor)->select('id'))->lockForUpdate()->findOrFail($id);
            $this->authority->version($record, $data['version']);
            abort_unless($record->status === 'pending', 409, 'تمت معالجة الطلب مسبقاً.');
            $target = $this->access->account($actor, $record->account_id, 'prices.approve', true);
            if ($data['decision'] === 'approve') {
                $allowed = $this->catalog->options($actor, $target)->whereIn('id', array_column($record->changes, 'product_id'))->count();
                abort_unless($allowed === count($record->changes), 409, 'تغيرت الفئات المسموحة.');
                $this->applyPrices($record, $actor, $data['idempotency_key'], $request);
            }
            $record->update(['status' => $data['decision'] === 'approve' ? 'approved' : 'rejected', 'reason' => $data['reason'] ?? '', 'reviewer_id' => $actor->id, 'reviewed_at' => now(), 'version' => $record->version + 1]);
            $this->audit->record('prices.approve', $request, $actor, $record->account_id, ['request_id' => $id, 'decision' => $data['decision']]);

            return $this->resource($record, $request);
        });
    }

    public function reversePrices(User $actor, int $id, array $data, Request $request): array
    {
        $this->access->require($actor, 'prices.reverse', true);
        PriceRequest::whereIn('account_id', $this->access->accounts($actor)->select('id'))->findOrFail($id);

        return $this->execute($actor, 'prices.reverse', $data + ['request_id' => $id], function () use ($actor, $id, $data, $request): array {
            $record = PriceRequest::whereIn('account_id', $this->access->accounts($actor)->select('id'))->lockForUpdate()->findOrFail($id);
            $this->authority->version($record, $data['version']);
            abort_unless($record->status === 'approved', 409, 'لا يمكن عكس هذا الطلب.');
            $this->access->account($actor, $record->account_id, 'prices.reverse', true);
            foreach ($record->changes as $change) {
                $current = DB::table('finance_prices')->where('account_id', $record->account_id)->where('product_id', $change['product_id'])->lockForUpdate()->first();
                abort_unless($current && (int) $current->request_id === $id && (int) $current->price_minor === $change['price_minor'], 409, 'تغير السعر بعد هذا الطلب؛ لا يمكن عكسه.');
                if ($change['old_minor'] === null) {
                    DB::table('finance_prices')->where('id', $current->id)->delete();
                } else {
                    DB::table('finance_prices')->where('id', $current->id)->update(['price_minor' => $change['old_minor'], 'version' => $current->version + 1, 'updated_at' => now()]);
                }
                if (class_exists(StockValuation::class)) {
                    app(StockValuation::class)->revalue($actor, (int) $record->account_id, (int) $change['product_id'], $change['old_minor'], 'PRICE:'.hash('sha256', $data['idempotency_key'].':'.$change['product_id']), $request);
                }
            }
            $record->update(['status' => 'reversed', 'reason' => $data['reason'], 'version' => $record->version + 1, 'reviewer_id' => $actor->id, 'reviewed_at' => now()]);
            $this->audit->record('prices.reverse', $request, $actor, $record->account_id, ['request_id' => $id, 'reason' => $data['reason']]);

            return $this->resource($record, $request);
        });
    }
}

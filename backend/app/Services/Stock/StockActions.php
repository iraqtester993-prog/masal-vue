<?php

namespace App\Services\Stock;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Finance\Invoice;
use App\Models\OperatingGovernorate;
use App\Models\Stock\StockAdjustment;
use App\Models\Stock\StockBatch;
use App\Models\Stock\StockCard;
use App\Models\Stock\StockClaim;
use App\Models\Stock\StockWithdrawal;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Finance\FinanceOperations;
use App\Services\Finance\Ledger;
use App\Services\Finance\Money;
use App\Services\ManagementAuthority;
use App\Services\Operations\MutationGuard;
use App\Services\Operations\OperationGuard;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StockActions
{
    public function __construct(private StockAccess $access, private FinanceOperations $operations, private Ledger $ledger, private AuditLogger $audit, private ManagementAuthority $authority, private StockImport $import, private StockValuation $valuation) {}

    private function execute(User $actor, string $action, array $data, callable $work): array
    {
        try {
            return $this->operations->execute($actor, $action, $data, $work);
        } catch (UniqueConstraintViolationException) {
            abort(409, 'تغير المخزون أثناء الإجراء؛ أعد المعاينة.');
        }
    }

    private function money(User $actor, StockBatch $batch, int $delta, string $key, string $reference): ?int
    {
        if ($delta === 0) {
            return null;
        }
        $systemId = Account::where('type', AccountType::System)->value('id');
        $main = $this->ledger->wallet($batch->account_id, 'voucher', $batch->currency);
        $external = $this->ledger->wallet($systemId, 'voucher', $batch->currency, 'external');
        $tx = $this->ledger->pair($actor, 'stock-adjustment', 'STOCK:'.hash('sha256', $key), ['batch_id' => $batch->id, 'delta' => $delta, 'reference' => $reference], $delta > 0 ? $external : $main, $delta > 0 ? $main : $external, abs($delta), $reference);

        return $tx->id;
    }

    private function selected(StockBatch $batch, array $ids, bool $secrets = false): Collection
    {
        $fields = ['id', 'batch_id', 'account_id', 'product_id', 'serial', 'expiry', 'cost_minor', 'credit_minor', 'credit_held', 'status', 'version', 'sale_id', 'claim_id', 'adjustment_id'];
        if ($secrets) {
            $fields[] = 'secret';
        }
        $cards = StockCard::where('batch_id', $batch->id)->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get($fields);
        if ($cards->count() !== count($ids)) {
            throw ValidationException::withMessages(['card_ids' => 'إحدى البطاقات خارج الدفعة.']);
        }

        return $cards;
    }

    private function updateCards(Collection $cards, array $changes): void
    {
        foreach ($cards->pluck('id')->chunk(500) as $ids) {
            StockCard::whereIn('id', $ids)->update($changes + ['version' => DB::raw('version + 1'), 'updated_at' => now()]);
        }
    }

    private function lockedBatch(User $actor, int $id): StockBatch
    {
        $accountId = $this->access->batches($actor)->whereKey($id)->value('account_id');
        abort_if($accountId === null, 404);
        app(MutationGuard::class)->lock($actor, [$accountId]);
        $account = $this->access->accounts($actor)->lockForUpdate()->findOrFail($accountId);
        abort_unless($account->isOperational(), 409, 'الحساب أو أحد الحسابات الأعلى موقوف.');
        app(OperationGuard::class)->assertAllowed($accountId, 'app');

        return $this->access->batches($actor)->lockForUpdate()->findOrFail($id);
    }

    private function linked(StockBatch $batch, Collection $cards): void
    {
        $ids = $cards->pluck('id')->all();
        $overlap = StockClaim::where('batch_id', $batch->id)->where('status', 'pending')->get(['card_ids'])->contains(fn ($claim): bool => count(array_intersect($claim->card_ids, $ids)) > 0);
        abort_if($overlap || StockWithdrawal::where('batch_id', $batch->id)->whereIn('status', ['pending', 'approved'])->exists(), 409, 'عالج المطالبة أو طلب الإرجاع قبل تغيير البطاقات.');
    }

    public function summarize(StockBatch $batch): void
    {
        $states = StockCard::where('batch_id', $batch->id)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $total = $states->sum();
        $status = ($states['Cancelled by Reversal'] ?? 0) === $total ? 'Cancelled by Reversal'
            : (($states['Available'] ?? 0) > 0 ? (($states['Available'] ?? 0) === $total ? 'Loaded' : 'Partially Used')
                : (($states['Quarantined'] ?? 0) > 0 ? 'Quarantined' : (($states['Exported'] ?? 0) === $total ? 'Exported' : 'Completed')));
        $batch->status = $status;
        $batch->save();
    }

    public function preview(User $actor, int $id, array $data): array
    {
        $action = $data['action'];
        $permission = match ($action) {
            'edit' => 'inventory.edit', 'cancel' => 'inventory.cancel', 'restore' => 'inventory.restore', 'damage' => 'claims.create', 'return' => 'exports.request', 'copy' => 'inventory.export', default => 'inventory.quarantine'
        };
        $this->access->require($actor, $permission);
        if ($action === 'restore') {
            abort_unless($actor->membership->account->type === AccountType::System && $actor->membership->kind === 'owner', 403);
        }
        if ($action === 'copy' && ($data['secrets'] ?? false)) {
            $this->access->require($actor, 'exports.encrypt');
            $this->access->require($actor, 'data.pin');
        }

        return DB::transaction(function () use ($actor, $id, $data, $action): array {
            $batch = $this->lockedBatch($actor, $id);
            $this->authority->version($batch, $data['version']);
            $debit = 0;
            if ($action === 'edit') {
                if (empty($data['city']) || empty($data['supplier']) || ! OperatingGovernorate::where('name', $data['city'])->where('active', true)->exists()) {
                    throw ValidationException::withMessages(['city' => 'المحافظة المفعلة والمصدر مطلوبان.']);
                }
                $quantity = StockCard::where('batch_id', $id)->count();
            } elseif ($action === 'restore') {
                $adjustment = StockAdjustment::where('batch_id', $id)->where('kind', 'cancellation')->where('status', 'cancelled')->when(! empty($data['adjustment_id']), fn ($query) => $query->whereKey($data['adjustment_id']))->orderByDesc('id')->firstOrFail();
                $cards = $this->selected($batch, $adjustment->card_ids);
                abort_if($cards->contains(fn ($card): bool => $card->status !== 'Cancelled by Reversal' || $card->sale_id !== null || ! $card->credit_held || $card->adjustment_id !== $adjustment->id || $card->expiry->format('Y-m-d') <= now('Asia/Baghdad')->toDateString()), 409, 'بطاقات منتهية أو مستخدمة؛ تعذر الاسترجاع.');
                $this->linked($batch, $cards);
                $quantity = $cards->count();
            } else {
                $selected = in_array($action, ['copy', 'damage'], true) || ($action === 'return' && isset($data['card_ids']));
                if ($selected && empty($data['card_ids'])) {
                    throw ValidationException::withMessages(['card_ids' => 'حدد البطاقات أولًا.']);
                }
                $states = $action === 'resume' ? ['Quarantined'] : ($action === 'quarantine' ? ['Available'] : ['Available', 'Quarantined']);
                $cards = $selected ? $this->selected($batch, $data['card_ids']) : StockCard::where('batch_id', $id)->whereNull('sale_id')->whereIn('status', $states)->orderBy('id')->lockForUpdate()->get(['id', 'status', 'expiry', 'sale_id', 'credit_held', 'credit_minor', 'cost_minor']);
                abort_if($cards->isEmpty(), 409, 'لا توجد بطاقات تسمح بهذا الإجراء.');
                if ($action !== 'copy') {
                    abort_if($cards->contains(fn ($card): bool => $card->sale_id !== null || ! in_array($card->status, $states, true)), 409, 'حدد بطاقات متاحة أو موقوفة غير مرتبطة بعملية بيع.');
                    $this->linked($batch, $cards);
                }
                if ($action === 'resume') {
                    abort_if($cards->contains(fn ($card): bool => $card->expiry->format('Y-m-d') <= now('Asia/Baghdad')->toDateString()), 409, 'لا يمكن إعادة بطاقة منتهية للبيع.');
                    abort_unless(DB::table('finance_prices')->where('account_id', $batch->account_id)->where('product_id', $batch->product_id)->exists(), 422, 'حدد سعر الفئة قبل إعادة التفعيل.');
                }
                if (in_array($action, ['cancel', 'damage', 'return', 'quarantine'], true)) {
                    $debit = $cards->where('credit_held', false)->sum('credit_minor');
                }
                if ($action === 'cancel') {
                    $invoice = Invoice::findOrFail($batch->invoice_id);
                    $amount = $batch->replacement_claim_id ? 0 : Money::multiply($batch->load_price_minor, $cards->count());
                    if ($amount > $invoice->amount_minor || $invoice->paid_minor > $invoice->amount_minor - $amount) {
                        throw ValidationException::withMessages(['amount' => 'سجل استرداد المبلغ المدفوع قبل إلغاء هذه البطاقات.']);
                    }
                }
                $quantity = $cards->count();
            }
            if ($debit) {
                $wallet = DB::table('finance_wallets')->where('account_id', $batch->account_id)->where('service', 'voucher')->where('currency', $batch->currency)->where('kind', 'account')->first(['balance_minor', 'held_minor']);
                if (! $wallet || $debit > $wallet->balance_minor - $wallet->held_minor) {
                    throw ValidationException::withMessages(['amount' => 'استرجع الرصيد الموزع قبل تعليق أو إلغاء هذه البطاقات.']);
                }
            }

            return ['quantity' => $quantity, 'debit' => in_array('data.cost', $actor->membership->permissions(), true) ? Money::decimal($debit) : null, 'currency' => $batch->currency, 'version' => $batch->version];
        });
    }

    public function batch(User $actor, int $id, array $data, Request $request): StockBatch
    {
        $action = $data['action'];
        $permission = match ($action) {
            'edit' => 'inventory.edit', 'cancel' => 'inventory.cancel', 'restore' => 'inventory.restore', default => 'inventory.quarantine'
        };
        $this->access->require($actor, $permission);
        if ($action === 'restore') {
            abort_unless($actor->membership->account->type === AccountType::System && $actor->membership->kind === 'owner', 403);
        }
        $this->access->batches($actor)->findOrFail($id);
        $result = $this->execute($actor, 'inventory.'.$action, $data + ['batch_id' => $id], function () use ($actor, $id, $data, $request, $action): array {
            $batch = $this->lockedBatch($actor, $id);
            $this->authority->version($batch, $data['version']);
            if ($action === 'edit') {
                if (empty($data['city']) || empty($data['supplier']) || ! OperatingGovernorate::where('name', $data['city'])->where('active', true)->exists()) {
                    throw ValidationException::withMessages(['city' => 'المحافظة المفعلة والمصدر مطلوبان.']);
                }
                $batch->fill(['city' => $data['city'], 'supplier' => $data['supplier'], 'notes' => $data['notes'] ?? '']);
            } elseif ($action === 'restore') {
                $this->restoreCancellation($actor, $batch, $data);
            } else {
                $cards = StockCard::where('batch_id', $id)->whereNull('sale_id')->whereIn('status', $action === 'resume' ? ['Quarantined'] : ['Available', 'Quarantined'])->orderBy('id')->lockForUpdate()->get(['id', 'status', 'expiry', 'credit_held', 'credit_minor', 'cost_minor']);
                abort_if($cards->isEmpty(), 409, 'لا توجد بطاقات تسمح بهذا الإجراء.');
                $this->linked($batch, $cards);
                if ($action === 'quarantine') {
                    $cards = $cards->where('status', 'Available')->values();
                    abort_if($cards->isEmpty(), 409, 'لا توجد بطاقات متاحة للحجر.');
                    $credit = $cards->where('credit_held', false)->sum('credit_minor');
                    $this->money($actor, $batch, -$credit, $data['idempotency_key'], 'حجر رصيد البطاقات');
                    $this->updateCards($cards, ['status' => 'Quarantined', 'credit_held' => true]);
                } elseif ($action === 'resume') {
                    abort_if($cards->contains(fn ($card): bool => $card->expiry->format('Y-m-d') <= now('Asia/Baghdad')->toDateString()), 409, 'لا يمكن إعادة بطاقة منتهية للبيع.');
                    $this->money($actor, $batch, $cards->where('credit_held', true)->sum('credit_minor'), $data['idempotency_key'], 'إعادة تفعيل بطاقات');
                    $this->updateCards($cards, ['status' => 'Available', 'credit_held' => false]);
                    $price = DB::table('finance_prices')->where('account_id', $batch->account_id)->where('product_id', $batch->product_id)->value('price_minor');
                    $this->valuation->revalue($actor, $batch->account_id, $batch->product_id, $price === null ? null : (int) $price, 'RESUME:'.$data['idempotency_key'], $request);
                } else {
                    $this->cancel($actor, $batch, $cards, $data);
                }
            }
            $batch->version++;
            $this->summarize($batch);
            $this->audit->record('inventory.'.$action, $request, $actor, $batch->account_id, ['batch_id' => $id, 'version' => $batch->version, 'reason' => $data['reason']]);

            return ['batch_id' => $id];
        });

        return $this->access->batches($actor)->findOrFail($result['batch_id']);
    }

    private function cancel(User $actor, StockBatch $batch, Collection $cards, array $data): void
    {
        $credit = $cards->where('credit_held', false)->sum('credit_minor');
        $invoice = Invoice::whereKey($batch->invoice_id)->lockForUpdate()->firstOrFail();
        $amount = $batch->replacement_claim_id ? 0 : Money::multiply($batch->load_price_minor, $cards->count());
        if ($amount > $invoice->amount_minor || $invoice->paid_minor > $invoice->amount_minor - $amount) {
            throw ValidationException::withMessages(['amount' => 'سجل استرداد المبلغ المدفوع قبل إلغاء هذه البطاقات؛ لا يمكن أن تتجاوز المدفوعات قيمة الفاتورة المتبقية.']);
        }
        $tx = $this->money($actor, $batch, -$credit, $data['idempotency_key'], 'إلغاء المتبقي من الطلبية');
        $adjustment = StockAdjustment::create(['batch_id' => $batch->id, 'account_id' => $batch->account_id, 'creator_id' => $actor->id, 'kind' => 'cancellation', 'card_ids' => $cards->pluck('id')->all(), 'reason' => $data['reason'], 'quantity' => $cards->count(), 'credit_minor' => $cards->sum('credit_minor'), 'cost_minor' => $cards->sum('cost_minor'), 'invoice_amount_minor' => $amount, 'transaction_id' => $tx, 'status' => 'cancelled', 'version' => 1]);
        $this->updateCards($cards, ['status' => 'Cancelled by Reversal', 'credit_held' => true, 'adjustment_id' => $adjustment->id]);
        $after = $invoice->amount_minor - $amount;
        $invoice->update(['amount_minor' => $after, 'status' => $after === $invoice->paid_minor ? 'paid' : ($invoice->paid_minor ? 'partial' : 'unpaid'), 'version' => $invoice->version + 1]);
    }

    private function restoreCancellation(User $actor, StockBatch $batch, array $data): void
    {
        $adjustment = StockAdjustment::where('batch_id', $batch->id)->where('kind', 'cancellation')->where('status', 'cancelled')->when(! empty($data['adjustment_id']), fn ($query) => $query->whereKey($data['adjustment_id']))->orderByDesc('id')->lockForUpdate()->firstOrFail();
        $cards = $this->selected($batch, $adjustment->card_ids);
        abort_if($cards->contains(fn ($card): bool => $card->status !== 'Cancelled by Reversal' || $card->sale_id !== null || ! $card->credit_held || $card->adjustment_id !== $adjustment->id || $card->expiry->format('Y-m-d') <= now('Asia/Baghdad')->toDateString()), 409, 'بطاقات منتهية أو مستخدمة؛ تعذر الاسترجاع.');
        $this->linked($batch, $cards);
        $invoice = Invoice::whereKey($batch->invoice_id)->lockForUpdate()->firstOrFail();
        $amount = $invoice->amount_minor + $adjustment->invoice_amount_minor;
        abort_if($amount > Money::MAX_MINOR, 422, 'قيمة الفاتورة تتجاوز الحد المسموح.');
        $this->money($actor, $batch, $adjustment->credit_minor, $data['idempotency_key'], 'استرجاع بطاقات ملغاة');
        $this->updateCards($cards, ['status' => 'Available', 'credit_held' => false, 'adjustment_id' => null]);
        $invoice->update(['amount_minor' => $amount, 'status' => $invoice->paid_minor ? 'partial' : 'unpaid', 'version' => $invoice->version + 1]);
        $adjustment->update(['status' => 'restored', 'restored_at' => now(), 'version' => $adjustment->version + 1]);
    }

    public function claim(User $actor, int $id, array $data, Request $request): StockClaim
    {
        $this->access->require($actor, 'claims.create');
        $this->access->batches($actor)->findOrFail($id);
        $result = $this->execute($actor, 'claims.create', $data + ['batch_id' => $id], function () use ($actor, $id, $data, $request): array {
            $batch = $this->lockedBatch($actor, $id);
            $this->authority->version($batch, $data['version']);
            $cards = $this->selected($batch, $data['card_ids']);
            $this->linked($batch, $cards);
            $claim = $this->createClaim($actor, $batch, $cards, $data['reason'], $data['idempotency_key']);
            $batch->version++;
            $this->summarize($batch);
            $this->audit->record('claims.create', $request, $actor, $batch->account_id, ['claim_id' => $claim->id, 'quantity' => $claim->quantity]);

            return ['claim_id' => $claim->id];
        });

        return StockClaim::whereIn('account_id', $this->access->accounts($actor)->select('accounts.id'))->findOrFail($result['claim_id']);
    }

    private function createClaim(User $actor, StockBatch $batch, Collection $cards, string $reason, string $key, string $purpose = 'damage'): StockClaim
    {
        abort_if($cards->contains(fn ($card): bool => $card->sale_id !== null || ! in_array($card->status, ['Available', 'Quarantined'], true)), 409, 'حدد بطاقات متاحة أو موقوفة غير مرتبطة بعملية بيع.');
        $debit = $cards->where('credit_held', false)->sum('credit_minor');
        $credit = $cards->sum('credit_minor');
        $tx = $this->money($actor, $batch, -$debit, $key, 'تعليق رصيد بطاقات تالفة');
        $claim = StockClaim::create(['batch_id' => $batch->id, 'account_id' => $batch->account_id, 'creator_id' => $actor->id, 'card_ids' => $cards->pluck('id')->all(), 'reason' => $reason, 'purpose' => $purpose, 'quantity' => $cards->count(), 'credit_minor' => $credit, 'cost_minor' => $cards->sum('cost_minor'), 'transaction_id' => $tx, 'status' => 'pending', 'version' => 1]);
        $this->updateCards($cards, ['status' => 'Quarantined', 'credit_held' => true, 'claim_id' => $claim->id]);

        return $claim;
    }

    public function settle(User $actor, int $id, array $data, Request $request): StockClaim
    {
        $this->access->require($actor, 'claims.settle', true);
        if (in_array($data['decision'], ['reject', 'loss'], true)) {
            $this->access->require($actor, 'claims.loss', true);
        }
        $visible = $this->access->accounts($actor)->select('accounts.id');
        StockClaim::whereIn('account_id', $visible)->where('purpose', 'damage')->findOrFail($id);
        $result = $this->execute($actor, 'claims.settle', $data + ['claim_id' => $id], function () use ($actor, $id, $data, $request): array {
            $claim = StockClaim::whereIn('account_id', $this->access->accounts($actor)->select('accounts.id'))->where('purpose', 'damage')->lockForUpdate()->findOrFail($id);
            $this->authority->version($claim, $data['version']);
            abort_unless($claim->status === 'pending', 409, 'المطالبة غير متاحة للتسوية.');
            $batch = $this->lockedBatch($actor, $claim->batch_id);
            $cards = $this->selected($batch, $claim->card_ids);
            abort_if($cards->contains(fn ($card): bool => $card->sale_id !== null || ! in_array($card->status, ['Quarantined', 'Exported'], true) || $card->claim_id !== $id), 409, 'تغيرت حالة البطاقات أو سبق تعويضها.');
            $decision = $data['decision'];
            if ($decision === 'restore') {
                abort_if($cards->contains(fn ($card): bool => $card->status === 'Exported' || $card->expiry->format('Y-m-d') <= now('Asia/Baghdad')->toDateString()) || StockWithdrawal::where('claim_id', $id)->whereIn('status', ['approved', 'downloaded'])->exists(), 409, 'الرموز المصدرة أو المنتهية لا تعاد للبيع.');
                $this->money($actor, $batch, $claim->credit_minor, $data['idempotency_key'], 'استعادة رصيد مطالبة');
                $this->updateCards($cards, ['status' => 'Available', 'credit_held' => false, 'claim_id' => null]);
            } else {
                if ($decision === 'replace') {
                    $this->access->require($actor, 'import.approve', true);
                    $replacement = $this->replacement($actor, $claim, $batch, $data, $request);
                    $claim->replacement_batch_id = $replacement->id;
                } elseif ($decision === 'compensate') {
                    $amount = Money::multiply($batch->load_price_minor, $claim->quantity);
                    $this->money($actor, $batch, $amount, $data['idempotency_key'], 'تعويض بطاقات تالفة بسعر الشراء الأصلي');
                }
                $status = match ($decision) {
                    'replace' => 'Replaced', 'compensate' => 'Compensated', default => 'Written Off'
                };
                $this->updateCards($cards, ['status' => $status]);
            }
            StockWithdrawal::where('claim_id', $id)->whereIn('status', ['pending', 'approved'])->update(['status' => 'cancelled']);
            $claim->fill(['status' => $decision, 'reviewer_id' => $actor->id, 'reviewed_at' => now(), 'version' => $claim->version + 1])->save();
            $batch->version++;
            $this->summarize($batch);
            $this->audit->record('claims.settle', $request, $actor, $claim->account_id, ['claim_id' => $id, 'decision' => $decision, 'reason' => $data['reason']]);

            return ['claim_id' => $id];
        });

        return StockClaim::whereIn('account_id', $this->access->accounts($actor)->select('accounts.id'))->findOrFail($result['claim_id']);
    }

    private function replacement(User $actor, StockClaim $claim, StockBatch $original, array $data, Request $request): StockBatch
    {
        if (empty($data['replacement_rows']) || count($data['replacement_rows']) !== $claim->quantity) {
            throw ValidationException::withMessages(['replacement_rows' => 'عدد البدائل يجب أن يساوي عدد بطاقات المطالبة.']);
        }
        $product = $original->product()->with('provider')->firstOrFail();
        $rows = $this->import->rows($data['replacement_rows'], $product, 'REPLACE:'.$claim->id, 'replacement');
        $pins = [];
        $serials = [];
        $today = now('Asia/Baghdad')->toDateString();
        foreach ($rows as $row) {
            $pin = StockImport::fingerprint($row['pin']);
            $serial = StockImport::fingerprint($row['serial']);
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $row['expiry']);
            $missing = collect($product->field_policy)->contains(fn ($policy, $field): bool => $policy === 'required' && ($row[$field] ?? '') === '');
            $missingExtra = collect($product->extra_fields)->contains(fn ($definition): bool => $definition['required'] && $row['extra_fields'][$definition['key']] === '');
            if (($row['parse_error'] ?? '') || $missing || $missingExtra || ! $date || $date->format('Y-m-d') !== $row['expiry'] || $row['expiry'] <= $today || isset($pins[$pin]) || isset($serials[$serial]) || StockCard::where('pin_hash', $pin)->exists() || StockCard::where('product_id', $product->id)->where('serial_hash', $serial)->exists()) {
                throw ValidationException::withMessages(['replacement_rows' => 'صحح الرموز المكررة أو الناقصة أو المنتهية في الملف البديل.']);
            }
            $pins[$pin] = true;
            $serials[$serial] = true;
        }
        $replacement = StockBatch::create(['order_id' => null, 'line_key' => 'REPLACE:'.$claim->id, 'account_id' => $claim->account_id, 'product_id' => $original->product_id, 'source_id' => $original->source_id, 'currency' => $original->currency, 'file_name' => 'بدائل مطالبة '.$claim->id, 'city' => $original->city, 'supplier' => $original->supplier, 'quantity' => $claim->quantity, 'rejected' => 0, 'cost_minor' => $original->cost_minor, 'expenses_minor' => 0, 'cost_total_minor' => Money::multiply($original->cost_minor, $claim->quantity), 'load_price_minor' => $original->load_price_minor, 'amount_minor' => 0, 'status' => 'Loaded', 'replacement_claim_id' => $claim->id, 'version' => 1]);
        $perCard = intdiv($claim->credit_minor, $claim->quantity);
        $remainder = $claim->credit_minor % $claim->quantity;
        foreach ($rows as $index => $row) {
            StockCard::create(['batch_id' => $replacement->id, 'account_id' => $claim->account_id, 'product_id' => $original->product_id, 'serial' => $row['serial'], 'serial_hash' => StockImport::fingerprint($row['serial']), 'pin_hash' => StockImport::fingerprint($row['pin']), 'secret' => array_intersect_key($row, array_flip(['pin', 'cvc', 'reference', 'extra_fields'])), 'expiry' => $row['expiry'], 'cost_minor' => $original->cost_minor, 'credit_minor' => $perCard + ($index < $remainder ? 1 : 0), 'credit_held' => false, 'status' => 'Available', 'version' => 1]);
        }
        $tx = $this->money($actor, $replacement, $claim->credit_minor, $data['idempotency_key'], 'تحميل بدائل مطالبة دون تحصيل');
        $invoice = Invoice::create(['account_id' => $claim->account_id, 'creator_id' => $actor->id, 'kind' => 'receivable', 'supplier' => $original->supplier, 'service' => 'voucher', 'currency' => $original->currency, 'amount_minor' => 0, 'paid_minor' => 0, 'status' => 'paid', 'reference' => 'بديل مطالبة '.$claim->id, 'source_type' => 'stock_batch', 'source_id' => $replacement->id]);
        $replacement->update(['transaction_id' => $tx, 'invoice_id' => $invoice->id]);

        return $replacement;
    }

    public function withdrawal(User $actor, array $data, Request $request): StockWithdrawal
    {
        $this->access->require($actor, 'exports.request');
        $this->access->batches($actor)->findOrFail($data['batch_id']);
        $result = $this->execute($actor, 'exports.request', $data, function () use ($actor, $data, $request): array {
            $batch = $this->lockedBatch($actor, $data['batch_id']);
            $this->authority->version($batch, $data['version']);
            $cards = isset($data['card_ids']) ? $this->selected($batch, $data['card_ids']) : StockCard::where('batch_id', $batch->id)->whereNull('sale_id')->whereIn('status', ['Available', 'Quarantined'])->orderBy('id')->lockForUpdate()->get(['id', 'status', 'sale_id', 'expiry', 'credit_held', 'credit_minor', 'cost_minor', 'claim_id']);
            abort_if($cards->isEmpty(), 409, 'لا توجد بطاقات قابلة للإرجاع.');
            $this->linked($batch, $cards);
            $original = $cards->map(fn ($card): array => ['id' => $card->id, 'status' => $card->status, 'credit_held' => $card->credit_held, 'claim_id' => $card->claim_id])->all();
            $debit = $cards->where('credit_held', false)->sum('credit_minor');
            $claim = $this->createClaim($actor, $batch, $cards, $data['reason'], $data['idempotency_key'], 'supplier_return');
            $record = StockWithdrawal::create(['batch_id' => $batch->id, 'account_id' => $batch->account_id, 'claim_id' => $claim->id, 'creator_id' => $actor->id, 'card_ids' => $cards->pluck('id')->all(), 'original' => $original, 'debit_minor' => $debit, 'reason' => $data['reason'], 'status' => 'pending', 'version' => 1]);
            $batch->version++;
            $this->summarize($batch);
            $this->audit->record('exports.request', $request, $actor, $batch->account_id, ['withdrawal_id' => $record->id, 'claim_id' => $claim->id]);

            return ['withdrawal_id' => $record->id];
        });

        return StockWithdrawal::whereIn('account_id', $this->access->accounts($actor)->select('accounts.id'))->findOrFail($result['withdrawal_id']);
    }

    public function reviewWithdrawal(User $actor, int $id, array $data, Request $request): StockWithdrawal
    {
        $this->access->require($actor, 'exports.approve', true);
        StockWithdrawal::whereIn('account_id', $this->access->accounts($actor)->select('accounts.id'))->findOrFail($id);
        $result = $this->execute($actor, 'exports.approve', $data + ['withdrawal_id' => $id], function () use ($actor, $id, $data, $request): array {
            $record = StockWithdrawal::whereIn('account_id', $this->access->accounts($actor)->select('accounts.id'))->lockForUpdate()->findOrFail($id);
            $this->authority->version($record, $data['version']);
            abort_unless($record->status === 'pending', 409, 'طلب الإرجاع ليس بانتظار الاعتماد.');
            if ($record->creator_id === $actor->id) {
                abort_unless($actor->membership->kind === 'owner', 403, 'يعتمد الإرجاع مستخدم آخر.');
            }
            if ($data['decision'] === 'reject') {
                $batch = $this->lockedBatch($actor, $record->batch_id);
                $cards = $this->selected($batch, $record->card_ids)->keyBy('id');
                abort_if(count($record->original) !== $cards->count() || $cards->contains(fn ($card): bool => $card->status !== 'Quarantined' || $card->sale_id !== null || $card->claim_id !== $record->claim_id), 409, 'تغيرت البطاقات؛ تعذر رفض الطلب.');
                $groups = [];
                foreach ($record->original as $original) {
                    abort_if(! isset($cards[$original['id']]) || ! in_array($original['status'], ['Available', 'Quarantined'], true), 409, 'سجل حالات الإرجاع غير متسق.');
                    $group = $original['status'].':'.(int) $original['credit_held'].':'.($original['claim_id'] ?? 'none');
                    $groups[$group]['changes'] = ['status' => $original['status'], 'credit_held' => $original['credit_held'], 'claim_id' => $original['claim_id'] ?? null];
                    $groups[$group]['cards'][] = $cards[$original['id']];
                }
                foreach ($groups as $group) {
                    $this->updateCards(collect($group['cards']), $group['changes']);
                }
                $this->money($actor, $batch, $record->debit_minor, $data['idempotency_key'], 'إعادة رصيد طلب إرجاع مرفوض');
                StockClaim::whereKey($record->claim_id)->where('status', 'pending')->update(['status' => 'cancelled', 'reviewer_id' => $actor->id, 'reviewed_at' => now(), 'version' => DB::raw('version + 1')]);
                $batch->version++;
                $this->summarize($batch);
            }
            $record->update(['status' => $data['decision'] === 'approve' ? 'approved' : 'rejected', 'review_reason' => $data['reason'], 'reviewer_id' => $actor->id, 'valid_until' => $data['decision'] === 'approve' ? now()->addHours($data['hours']) : null, 'version' => $record->version + 1]);
            $this->audit->record('exports.approve', $request, $actor, $record->account_id, ['withdrawal_id' => $id, 'decision' => $data['decision'], 'reason' => $data['reason']]);

            return ['withdrawal_id' => $id];
        });

        return StockWithdrawal::whereIn('account_id', $this->access->accounts($actor)->select('accounts.id'))->findOrFail($result['withdrawal_id']);
    }

    public function download(User $actor, int $id, array $data, Request $request): array
    {
        $this->access->require($actor, 'exports.encrypt');
        $this->access->require($actor, 'data.pin');
        $visible = $this->access->accounts($actor)->select('accounts.id');
        $initial = StockWithdrawal::whereIn('account_id', $visible)->findOrFail($id);
        abort_unless($initial->status === 'downloaded' || ($initial->valid_until && $initial->valid_until->isFuture()), 409, 'انتهت صلاحية تنزيل الإرجاع.');
        $this->execute($actor, 'exports.download', $data + ['withdrawal_id' => $id], function () use ($actor, $id, $data, $request): array {
            $record = StockWithdrawal::whereIn('account_id', $this->access->accounts($actor)->select('accounts.id'))->lockForUpdate()->findOrFail($id);
            $this->authority->version($record, $data['version']);
            if ($record->status === 'downloaded') {
                return ['withdrawal_id' => $id];
            }
            abort_unless($record->status === 'approved' && $record->valid_until?->isFuture(), 409, 'الطلب غير معتمد أو انتهت صلاحيته.');
            $batch = $this->lockedBatch($actor, $record->batch_id);
            $cards = $this->selected($batch, $record->card_ids);
            abort_if($cards->contains(fn ($card): bool => $card->status !== 'Quarantined' || $card->sale_id !== null || $card->claim_id !== $record->claim_id), 409, 'تغيرت البطاقات أثناء التنزيل.');
            $this->updateCards($cards, ['status' => 'Exported']);
            $record->update(['status' => 'downloaded', 'downloaded_at' => now(), 'version' => $record->version + 1]);
            $batch->version++;
            $this->summarize($batch);
            $this->audit->record('exports.download', $request, $actor, $record->account_id, ['withdrawal_id' => $id, 'quantity' => count($record->card_ids)]);

            return ['withdrawal_id' => $id];
        });
        $record = StockWithdrawal::whereIn('account_id', $this->access->accounts($actor)->select('accounts.id'))->findOrFail($id);
        $cards = StockCard::where('batch_id', $record->batch_id)->whereIn('id', $record->card_ids)->orderBy('id')->get();

        return ['withdrawal_id' => $id, 'version' => $record->version, 'batch_id' => $record->batch_id, 'cards' => $cards->map(fn ($card): array => ['serial' => $card->serial, 'expiry' => $card->expiry->format('Y-m-d')] + $card->secret)->all()];
    }

    public function copy(User $actor, int $id, array $data, Request $request): array
    {
        $this->access->require($actor, 'inventory.export');
        if ($data['secrets']) {
            $this->access->require($actor, 'exports.encrypt');
            $this->access->require($actor, 'data.pin');
        }

        return DB::transaction(function () use ($actor, $id, $data, $request): array {
            $batch = $this->lockedBatch($actor, $id);
            $this->authority->version($batch, $data['version']);
            $cards = $this->selected($batch, $data['card_ids'], $data['secrets']);
            $payload = ['batch_id' => $id, 'account_id' => $batch->account_id, 'product_id' => $batch->product_id, 'purpose' => 'inventory-copy', 'cards' => $cards->map(fn ($card): array => ['serial' => $card->serial, 'expiry' => $card->expiry->format('Y-m-d'), 'status' => $card->status] + ($data['secrets'] ? $card->secret : []))->all()];
            StockAdjustment::create(['batch_id' => $id, 'account_id' => $batch->account_id, 'creator_id' => $actor->id, 'kind' => 'copy', 'card_ids' => $cards->pluck('id')->all(), 'reason' => $data['reason'], 'quantity' => $cards->count(), 'credit_minor' => 0, 'cost_minor' => 0, 'invoice_amount_minor' => 0, 'status' => 'copied', 'version' => 1]);
            $this->audit->record('inventory.export', $request, $actor, $batch->account_id, ['batch_id' => $id, 'quantity' => $cards->count(), 'secrets' => $data['secrets']]);

            return $payload;
        });
    }
}

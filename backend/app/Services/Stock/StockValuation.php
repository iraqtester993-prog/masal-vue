<?php

namespace App\Services\Stock;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\CatalogProduct;
use App\Models\Stock\StockCard;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Finance\Ledger;
use App\Services\Finance\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StockValuation
{
    public function __construct(private Ledger $ledger, private AuditLogger $audit) {}

    public function revalue(User $actor, int $accountId, int $productId, ?int $newPriceMinor, string $key, Request $request): void
    {
        abort_unless(DB::transactionLevel() > 0, 500);
        $cards = StockCard::where('account_id', $accountId)->where('product_id', $productId)->where('status', 'Available')->where('credit_held', false)->orderBy('id')->lockForUpdate()->get(['id', 'credit_minor']);
        if ($cards->isEmpty()) {
            return;
        }
        if ($newPriceMinor === null) {
            throw ValidationException::withMessages(['price' => 'لا يمكن إزالة سعر فئة لها مخزون متاح.']);
        }
        $product = CatalogProduct::findOrFail($productId);
        $delta = 0;
        foreach ($cards as $card) {
            $delta += $newPriceMinor - $card->credit_minor;
            if (abs($delta) > Money::MAX_MINOR) {
                throw ValidationException::withMessages(['price' => 'فرق تقييم المخزون يتجاوز الحد المسموح.']);
            }
        }
        if ($delta !== 0) {
            $systemId = Account::where('type', AccountType::System)->value('id');
            $main = $this->ledger->wallet($accountId, 'voucher', $product->currency);
            $external = $this->ledger->wallet($systemId, 'voucher', $product->currency, 'external');
            $ledgerKey = 'PRICE:'.hash('sha256', $key.':'.$productId.':'.$accountId);
            $this->ledger->pair($actor, 'stock-revaluation', $ledgerKey, ['account_id' => $accountId, 'product_id' => $productId, 'price_minor' => $newPriceMinor, 'delta' => $delta], $delta > 0 ? $external : $main, $delta > 0 ? $main : $external, abs($delta), 'تحديث قيمة المخزون');
        }
        foreach ($cards->pluck('id')->chunk(500) as $ids) {
            StockCard::whereIn('id', $ids)->update(['credit_minor' => $newPriceMinor, 'version' => DB::raw('version + 1'), 'updated_at' => now()]);
        }
        $this->audit->record('inventory.revalue', $request, $actor, $accountId, ['product_id' => $productId, 'quantity' => $cards->count(), 'delta' => Money::decimal($delta)]);
    }
}

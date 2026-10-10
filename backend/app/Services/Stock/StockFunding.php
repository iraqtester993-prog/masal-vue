<?php

namespace App\Services\Stock;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Finance\FundingRequest;
use App\Models\Finance\Invoice;
use App\Models\Stock\StockBatch;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StockFunding
{
    public function bind(User $actor, FundingRequest $request, int $batchId): int
    {
        abort_unless(DB::transactionLevel() > 0, 500);
        $batch = StockBatch::whereKey($batchId)->where('account_id', $request->to_account_id)->lockForUpdate()->firstOrFail();
        $invoice = Invoice::whereKey($batch->invoice_id)->where('source_type', 'stock_batch')->where('source_id', $batchId)->where('kind', 'receivable')->firstOrFail();
        $parentType = $request->fromAccount?->type ?? Account::findOrFail($request->from_account_id)->type;
        if ($parentType !== AccountType::System || $request->service !== 'voucher' || $request->currency !== $batch->currency || $request->amount_minor !== $batch->amount_minor || $invoice->amount_minor !== $batch->amount_minor || $batch->created_at->lt($request->created_at) || ! in_array($batch->status, ['Loaded', 'Partially Used'], true) || ! $batch->transaction_id || FundingRequest::where('stock_batch_id', $batchId)->where('id', '<>', $request->id)->exists()) {
            throw ValidationException::withMessages(['stock_batch_id' => 'اختر طلبية معتمدة مطابقة للحساب والعملة والمبلغ، منشأة بعد الطلب وغير مرتبطة بتمويل آخر.']);
        }
        $request->stock_batch_id = $batchId;

        return (int) $batch->transaction_id;
    }
}

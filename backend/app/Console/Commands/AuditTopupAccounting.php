<?php

namespace App\Console\Commands;

use App\Services\Digital\TopupWallets;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

#[Signature('topup:audit-accounting')]
#[Description('Read-only reconciliation of Topup holds, spending, invoices and financial ledger balances')]
class AuditTopupAccounting extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $report = DB::transaction(function (): array {
            $issues = [];
            $grants = DB::table('topup_grants')->get()->keyBy('id');
            $expected = [];
            $counts = ['orders' => 0, 'legacy_orders' => DB::table('digital_orders')->where('provider', 'topup')->whereNull('topup_grant_id')->count(), 'pending' => 0, 'invoices' => 0];
            $check = function (bool $valid, string $code, int $id) use (&$issues): void {
                if (! $valid) {
                    $issues[] = ['code' => $code, 'id' => $id];
                }
            };
            foreach (DB::table('digital_orders')->whereNotNull('topup_grant_id')->lazyById(500)->chunk(500) as $batch) {
                $batchInvoices = DB::table('finance_invoices')->where('source_type', 'digital_topup')->whereIn('source_id', $batch->pluck('id'))->get()->groupBy('source_id');
                foreach ($batch as $order) {
                    $counts['orders']++;
                    $grant = $grants->get($order->topup_grant_id);
                    $check($grant !== null && (int) $grant->main_account_id === (int) $order->main_account_id, 'order_grant_owner', $order->id);
                    $base = (int) $order->admin_price_minor;
                    $check($base > 0 && (int) $order->quoted_retail_minor >= $base && $order->provider === 'topup', 'order_price', $order->id);
                    $pending = in_array($order->status, ['pending', 'review'], true);
                    $check((bool) $order->reservation_active === $pending, 'order_reservation', $order->id);
                    $check($pending === ($order->accounted_at === null), 'order_accounting_status', $order->id);
                    $expected[$order->topup_grant_id] ??= ['held' => 0, 'spent' => 0];
                    if ($pending) {
                        $counts['pending']++;
                        $expected[$order->topup_grant_id]['held'] += $base;
                    }
                    if ($order->status === 'succeeded') {
                        $expected[$order->topup_grant_id]['spent'] += $base;
                    }
                    $invoices = $batchInvoices->get($order->id, collect());
                    $needsInvoice = in_array($order->status, ['succeeded', 'refunded'], true);
                    $check($invoices->count() === ($needsInvoice ? 1 : 0), 'order_invoice_count', $order->id);
                    foreach ($invoices as $invoice) {
                        $counts['invoices']++;
                        $check((int) $invoice->amount_minor === (int) $order->quoted_retail_minor && (int) $invoice->account_id === (int) $order->account_id && (int) $invoice->creditor_account_id === (int) $order->main_account_id && $invoice->currency === 'IQD' && $invoice->service === 'topup' && $invoice->kind === 'receivable', 'invoice_sale_snapshot', $invoice->id);
                        $check($invoice->paid_minor >= 0 && $invoice->paid_minor <= $invoice->amount_minor, 'invoice_paid_bounds', $invoice->id);
                        $status = $order->status === 'refunded' ? 'cancelled' : ((int) $invoice->paid_minor === 0 ? 'unpaid' : ((int) $invoice->paid_minor === (int) $invoice->amount_minor ? 'paid' : 'partial'));
                        $check($invoice->status === $status && ($order->status !== 'refunded' || (int) $invoice->paid_minor === 0), 'invoice_status', $invoice->id);
                    }
                }
            }
            foreach ($grants as $grant) {
                $totals = $expected[$grant->id] ?? ['held' => 0, 'spent' => 0];
                $check((int) $grant->held_minor === $totals['held'], 'grant_held', $grant->id);
                $check((int) $grant->spent_minor === $totals['spent'], 'grant_spent', $grant->id);
                $check($grant->balance_minor >= $grant->held_minor && $grant->held_minor >= 0 && $grant->spent_minor >= 0, 'grant_bounds', $grant->id);
            }
            foreach (DB::table('finance_invoices as i')->leftJoin('digital_orders as o', 'o.id', '=', 'i.source_id')->where('i.source_type', 'digital_topup')->whereNull('o.topup_grant_id')->pluck('i.id') as $id) {
                $check(false, 'orphan_topup_invoice', $id);
            }
            $ledger = DB::table('finance_entries')->selectRaw('wallet_id, SUM(amount_minor) AS total')->groupBy('wallet_id');
            foreach (DB::table('finance_wallets as w')->leftJoinSub($ledger, 'e', 'e.wallet_id', '=', 'w.id')->select(['w.id', 'w.balance_minor'])->selectRaw('COALESCE(e.total, 0) AS ledger_total')->get() as $wallet) {
                $check((int) $wallet->balance_minor === (int) $wallet->ledger_total, 'wallet_ledger_balance', $wallet->id);
            }
            $unbalanced = DB::table('finance_entries')->selectRaw('transaction_id, SUM(amount_minor) AS total')->groupBy('transaction_id')->havingRaw('SUM(amount_minor) <> 0')->pluck('transaction_id');
            foreach ($unbalanced as $id) {
                $check(false, 'unbalanced_transaction', $id);
            }
            foreach (app(TopupWallets::class)->auditInvariants()['issues'] as $code) {
                $check(false, $code, 0);
            }

            return ['checked_at' => now()->toIso8601String(), 'read_only' => true, 'currency' => 'IQD', 'counts' => $counts, 'grants' => $grants->count(), 'issues' => $issues, 'ok' => $issues === [], 'provider_statement_checked' => false];
        });
        $this->line(json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        if (! $report['ok']) {
            Log::error('Topup accounting reconciliation failed.', ['issues' => $report['issues']]);
        }

        return $report['ok'] ? self::SUCCESS : self::FAILURE;
    }
}

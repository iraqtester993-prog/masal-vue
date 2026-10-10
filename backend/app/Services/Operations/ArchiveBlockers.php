<?php

namespace App\Services\Operations;

use App\Models\Account;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ArchiveBlockers
{
    /** Financial, stock and unfinished workflows are checked without changing any record. */
    public function reasons(Account $account, bool $lock = false): array
    {
        $reasons = [];
        $id = $account->id;
        if (Account::whereIn('id', DB::table('account_closure')->where('ancestor_id', $id)->where('depth', '>', 0)->select('descendant_id'))->whereNull('archived_at')->exists()) {
            $reasons[] = 'أرشف الفروع ونقاط البيع التابعة أولًا.';
        }
        foreach (['finance_wallets', 'finance_entries', 'finance_funding_requests', 'finance_invoices', 'stock_cards', 'stock_orders', 'stock_claims', 'stock_withdrawals', 'sales', 'sales_print_attempts', 'sales_reprint_requests', 'support_tickets'] as $required) {
            abort_unless(Schema::hasTable($required), 503, 'تعذر التحقق من سلامة الأرشفة؛ مكوّن '.$required.' غير متاح.');
        }
        $wallets = DB::table('finance_wallets')->where('account_id', $id)->where('kind', 'account')->orderBy('id');
        if ($lock) {
            $wallets->lockForUpdate();
        }
        foreach ($wallets->get(['id', 'balance_minor', 'held_minor']) as $wallet) {
            $ledger = (string) DB::table('finance_entries')->where('wallet_id', $wallet->id)->sum('amount_minor');
            if ((string) $wallet->balance_minor !== '0' || $ledger !== '0' || (string) $wallet->held_minor !== '0') {
                $reasons[] = 'لا يمكن الأرشفة قبل تسوية الأرصدة والحجوزات المالية.';
                break;
            }
        }
        $checks = [
            ['finance_funding_requests', ['from_account_id', 'to_account_id'], ['approved', 'rejected', 'cancelled'], 'يوجد طلب تمويل غير مغلق.'],
            ['finance_invoices', ['account_id'], ['paid', 'cancelled'], 'يوجد رصيد فاتورة غير مسدد.'],
            ['finance_price_requests', ['account_id'], ['approved', 'rejected', 'reversed'], 'يوجد اقتراح أسعار غير مغلق.'],
            ['stock_orders', ['account_id'], ['approved', 'rejected', 'cancelled'], 'يوجد طلب استيراد غير مغلق.'],
            ['stock_claims', ['account_id'], ['restore', 'compensate', 'replace', 'reject', 'loss', 'cancelled'], 'يوجد طلب مطالبة غير مغلق.'],
            ['stock_withdrawals', ['account_id'], ['downloaded', 'rejected', 'cancelled'], 'يوجد طلب إرجاع غير مكتمل.'],
            ['sales', ['account_id', 'main_account_id'], ['Printed', 'Reprinted', 'Delivered', 'Cancelled'], 'أكمل البيع والحجوزات والطباعة المعلقة أولًا.'],
            ['sales_reprint_requests', ['account_id', 'recipient_id'], ['approved', 'rejected', 'cancelled', 'used'], 'يوجد طلب إعادة طباعة غير مغلق.'],
        ];
        foreach ($checks as [$table,$columns,$terminal,$message]) {
            abort_unless(Schema::hasTable($table), 503, 'تعذر التحقق من مكوّن '.$table.'.');
            $query = DB::table($table)->where(fn ($q) => $this->accountsWhere($q, $columns, $id))->whereNotIn('status', $terminal);
            if ($query->exists()) {
                $reasons[] = $message;
            }
        }
        if (DB::table('stock_cards')->where('account_id', $id)->whereIn('status', ['Available', 'Reserved', 'Quarantined', 'Held'])->exists()) {
            $reasons[] = 'يجب تسوية المخزون المتبقي أولًا.';
        }
        $sales = DB::table('sales')->where(fn ($q) => $q->where('account_id', $id)->orWhere('main_account_id', $id))->select('id');
        if (DB::table('sales')->whereIn('id', $sales)->where('print_pending', true)->exists() || DB::table('sales_print_attempts')->whereIn('sale_id', $sales)->where('status', 'pending')->exists()) {
            $reasons[] = 'أكمل عمليات الطباعة المعلقة أولًا.';
        }
        if (DB::table('support_tickets')->where('status', '<>', 'closed')->where(function ($q) use ($id): void {
            $q->where('origin_id', $id)->orWhere('recipient_id', $id)->orWhereIn('id', DB::table('support_ticket_participants')->where('account_id', $id)->select('ticket_id'));
        })->exists()) {
            $reasons[] = 'أغلق محادثات الدعم المرتبطة بالحساب أولًا.';
        }
        // Future service modules must expose recognized account/status columns or fail closed.
        foreach (Schema::getTables() as $table) {
            $name = $table['name'];
            if (! preg_match('/^(digital_|service_).*(orders|requests|reservations|holds)$/', $name)) {
                continue;
            }
            $columns = Schema::getColumnListing($name);
            $accountColumns = array_values(array_intersect($columns, ['account_id', 'main_account_id', 'agent_id', 'pos_id', 'from_account_id', 'to_account_id', 'agent', 'account', 'pos', 'from', 'to']));
            abort_unless($accountColumns && in_array('status', $columns, true), 503, 'تعذر التحقق من الطلبات المرتبطة بالمكوّن '.$name.'.');
            if (DB::table($name)->where(fn ($q) => $this->accountsWhere($q, $accountColumns, $id))->whereNotIn('status', ['success', 'succeeded', 'failed', 'refunded', 'cancelled', 'Success', 'Failed', 'Cancelled', 'ناجح', 'فاشل', 'ملغى'])->exists()) {
                $reasons[] = 'يوجد طلب خدمة غير مغلق.';
            }
            if (in_array('reservation_active', $columns, true) && DB::table($name)->where(fn ($q) => $this->accountsWhere($q, $accountColumns, $id))->where('reservation_active', true)->exists()) {
                $reasons[] = 'يوجد حجز خدمة لم يُحرر بعد.';
            }
        }
        if (Schema::hasTable('digital_orders')) {
            abort_unless(Schema::hasTable('digital_provider_attempts'), 503, 'تعذر التحقق من محاولات مزوّد الخدمات.');
            $orders = DB::table('digital_orders')->where(fn ($q) => $q->where('account_id', $id)->orWhere('main_account_id', $id))->select('id');
            if (DB::table('digital_provider_attempts')->whereIn('active_order_id', $orders)->exists()) {
                $reasons[] = 'توجد محاولة مزوّد غير محسومة؛ راجع الطلب قبل الأرشفة.';
            }
        }
        foreach (app()->tagged('account.archive.blockers') as $guard) {
            $reasons = array_merge($reasons, $guard->reasons($account, $lock));
        }

        return array_values(array_unique($reasons));
    }

    private function accountsWhere(Builder $query, array $columns, int $id): void
    {
        foreach ($columns as $column) {
            $query->orWhere($column, $id);
        }
    }
}

<?php

namespace App\Services\Reports;

use App\Enums\AccountType;
use App\Models\Digital\DigitalConnection;
use App\Models\User;
use App\Services\Digital\DigitalAccess;
use App\Services\Support\SupportAccess;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DashboardSummary
{
    public function __construct(private ReportAccess $access, private ReportQueries $queries, private SupportAccess $support, private DigitalAccess $digital) {}

    public function recentOperations(User $actor, string $currency = 'IQD', ?int $limit = 30): array
    {
        $rows = collect();
        if ($this->access->allows($actor, 'sales.view')) {
            $rows = DB::table('sales as r')->whereIn('r.account_id', $this->access->accounts($actor)->select('id'))
                ->whereIn('r.product_id', $this->access->products($actor)->select('id'))->where('r.currency', $currency)->whereNotNull('r.issued_at')
                ->join('accounts as a', 'a.id', '=', 'r.account_id')->join('catalog_products as p', 'p.id', '=', 'r.product_id')
                ->orderByDesc('r.issued_at')->orderByDesc('r.id')->when($limit !== null, fn ($query) => $query->limit($limit))
                ->get(['r.id', 'r.issued_at as occurred_at', 'r.status', 'a.name as account_name', 'p.name as product_name', 'r.retail_total_minor as amount'])
                ->map(fn ($row): array => ['key' => 'stock:'.$row->id, 'kind' => 'stock', 'occurred_at' => $row->occurred_at, 'status' => $row->status, 'account_name' => $row->account_name, 'product_name' => $row->product_name, 'amount' => ReportDataset::decimal($row->amount), 'currency' => $currency, 'destination' => 'sales', 'parameters' => []]);
        }
        if ($currency === 'IQD' && $this->access->allows($actor, 'digital.view') && Schema::hasTable('digital_orders')) {
            $digital = DB::table('digital_orders as r')->whereIn('r.id', $this->digital->orders($actor)->select('digital_orders.id'))
                ->join('accounts as a', 'a.id', '=', 'r.account_id')->join('catalog_products as p', 'p.id', '=', 'r.product_id')
                ->orderByDesc('r.created_at')->orderByDesc('r.id')->when($limit !== null, fn ($query) => $query->limit($limit))
                ->get(['r.id', 'r.created_at as occurred_at', 'r.status', 'r.provider', 'a.name as account_name', 'p.name as product_name', 'r.actual_retail_minor as amount'])
                ->map(fn ($row): array => ['key' => 'digital:'.$row->id, 'kind' => $row->provider, 'occurred_at' => $row->occurred_at, 'status' => $row->status, 'account_name' => $row->account_name, 'product_name' => $row->product_name, 'amount' => ReportDataset::decimal($row->amount), 'currency' => 'IQD', 'destination' => 'digital', 'parameters' => ['provider' => $row->provider, 'tab' => 'log']]);
            $rows = $rows->concat($digital);
        }

        $audit = $this->recentAuditOperations($actor, $limit);
        $records = $rows->keyBy('key');
        $audit = $audit->map(function (array $row) use ($records): array {
            $record = $records->get($row['sale_id'] ?? $row['order_id']);
            if ($record) {
                $row['product_name'] = $record['product_name'];
                $row['amount'] ??= $record['amount'];
                $row['destination'] = $record['destination'];
                $row['parameters'] = $record['parameters'];
            }

            return $row;
        });
        $saleIds = $audit->pluck('sale_id')->filter()->all();
        $orderIds = $audit->pluck('order_id')->filter()->all();
        $rows = $rows->reject(fn (array $row): bool => in_array($row['key'], array_merge($saleIds, $orderIds), true));

        return $rows->concat($audit->map(fn (array $row): array => array_diff_key($row, array_flip(['sale_id', 'order_id']))))
            ->sortByDesc(fn (array $row): string => $row['occurred_at'].'|'.str_pad(explode(':', $row['key'])[1], 20, '0', STR_PAD_LEFT).'|'.$row['key'])
            ->when($limit !== null, fn (Collection $items): Collection => $items->take($limit))->values()->all();
    }

    private function recentAuditOperations(User $actor, ?int $limit): Collection
    {
        $scope = $this->access->accounts($actor)->select('id');
        $query = DB::table('audit_logs as r')->where(function (Builder $query) use ($scope): void {
            $query->whereIn('r.subject_account_id', $scope)->orWhere(function (Builder $query) use ($scope): void {
                $query->whereNull('r.subject_account_id')->whereIn('r.account_id', $scope);
            });
        });
        if (! $this->access->allows($actor, 'audit.view')) {
            $permissions = ['wallets' => 'wallets.view', 'invoices' => 'invoices.view', 'prices' => 'prices.view', 'sell' => 'sales.view', 'sales' => 'sales.view', 'digital' => 'digital.view', 'topup' => 'digital.view', 'account' => 'account.view', 'staff' => 'staff.view', 'inventory' => 'inventory.view', 'import' => 'import.view', 'support' => 'support.view'];
            $query->where(function (Builder $query) use ($actor, $permissions): void {
                $query->where('r.user_id', $actor->id);
                foreach ($permissions as $prefix => $permission) {
                    if ($this->access->allows($actor, $permission)) {
                        $query->orWhere('r.action', 'like', $prefix.'.%');
                    }
                }
            });
        }

        return $query->leftJoin('accounts as a', 'a.id', '=', DB::raw('COALESCE(r.subject_account_id,r.account_id)'))
            ->leftJoin('users as u', 'u.id', '=', 'r.user_id')->orderByDesc('r.created_at')->orderByDesc('r.id')->when($limit !== null, fn ($query) => $query->limit($limit))
            ->get(['r.id', 'r.action', 'r.created_at', 'r.details', 'a.name as account_name', 'u.name as actor_name'])
            ->map(function ($row) use ($actor): array {
                $details = json_decode($row->details ?? '{}', true) ?? [];
                $saleId = in_array(explode('.', $row->action)[0], ['sell', 'sales'], true) ? ($details['sale_id'] ?? null) : null;
                $orderId = str_starts_with($row->action, 'digital.') ? ($details['order_id'] ?? null) : null;
                $brief = [];
                foreach (['quantity' => 'العدد', 'version' => 'الإصدار', 'inquiry_id' => 'رقم الرسالة', 'transfer_id' => 'رقم التحويل'] as $field => $label) {
                    if (isset($details[$field]) && is_numeric($details[$field])) {
                        $brief[] = ['label' => $label, 'value' => (string) $details[$field]];
                    }
                }
                if ($this->access->allows($actor, 'audit.view') && is_string($details['reason'] ?? null) && trim($details['reason']) !== '') {
                    $brief[] = ['label' => 'سبب الإجراء', 'value' => mb_substr(trim($details['reason']), 0, 160)];
                }

                return ['summary_fields' => $brief, 'key' => 'audit:'.$row->id, 'kind' => 'audit', 'action' => $row->action, 'occurred_at' => $row->created_at, 'status' => is_string($details['status'] ?? null) ? $details['status'] : 'recorded', 'account_name' => $row->account_name, 'actor_name' => $row->actor_name, 'product_name' => null, 'amount' => isset($details['amount']) && is_numeric($details['amount']) ? (string) $details['amount'] : null, 'currency' => 'IQD', 'destination' => null, 'parameters' => [], 'sale_id' => $saleId ? 'stock:'.$saleId : null, 'order_id' => $orderId ? 'digital:'.$orderId : null];
            });
    }

    private function digitalConnections(User $actor): \Illuminate\Database\Eloquent\Builder
    {
        $query = $this->digital->connections($actor);
        if ($actor->membership->account->type !== AccountType::System) {
            $visible = (clone $query)->with(['account', 'company', 'offers.product', 'grants.target'])->get()
                ->filter(fn (DigitalConnection $connection): bool => $connection->account_id === $actor->membership->account_id || $this->digital->offers($connection, $actor->membership->account, true)->isNotEmpty())->pluck('id')->all();
            $query->whereIn('digital_connections.id', $visible);
        }

        return $query;
    }

    private function digitalCards(User $actor, string $currency): array
    {
        if (! $this->access->allows($actor, 'digital.view') || ! Schema::hasTable('digital_orders')) {
            return [];
        }
        $pos = $actor->membership->account->type === AccountType::Pos;
        $costVisible = ! $pos && $this->access->allows($actor, 'data.cost');
        $orders = DB::table('digital_orders as r')->whereIn('r.id', $this->digital->orders($actor)->select('digital_orders.id'));
        if ($currency !== 'IQD') {
            $orders->whereRaw('1=0');
        }
        $cards = [];
        foreach (['rabiaa' => 'الرابعة', 'topup' => 'Topup'] as $provider => $title) {
            $query = (clone $orders)->where('r.provider', $provider);
            $stats = (clone $query)->selectRaw("SUM(CASE WHEN r.status='succeeded' THEN 1 ELSE 0 END) AS quantity, SUM(CASE WHEN r.status='succeeded' THEN r.actual_retail_minor ELSE 0 END) AS retail, SUM(CASE WHEN r.status IN ('pending','review') THEN 1 ELSE 0 END) AS pending, SUM(CASE WHEN r.status='refunded' THEN 1 ELSE 0 END) AS refunds");
            if ($costVisible) {
                $stats->selectRaw("SUM(CASE WHEN r.status='succeeded' AND r.cost_basis='provider_response' THEN r.actual_cost_minor ELSE 0 END) AS confirmed_cost, SUM(CASE WHEN r.status='succeeded' AND (r.actual_cost_minor IS NULL OR r.cost_basis IS NULL OR r.cost_basis<>'provider_response') THEN 1 ELSE 0 END) AS unknown_cost, SUM(CASE WHEN r.status='succeeded' AND r.cost_basis='catalog_snapshot' THEN 1 ELSE 0 END) AS catalog_cost_count");
            }
            $stats = $stats->first();
            $useCost = $provider === 'topup' && $costVisible;
            $value = $useCost ? ((int) $stats->unknown_cost > 0 ? null : ReportDataset::decimal($stats->confirmed_cost ?? 0)) : ReportDataset::decimal($stats->retail ?? 0);
            $expression = $useCost ? "CASE WHEN r.cost_basis='provider_response' THEN r.actual_cost_minor ELSE NULL END" : 'COALESCE(r.actual_retail_minor,r.quoted_retail_minor)';
            $rows = (clone $query)->join('accounts as a', 'a.id', '=', 'r.account_id')->leftJoin('users as u', 'u.id', '=', 'r.creator_id')->join('catalog_products as product', 'product.id', '=', 'r.product_id')
                ->orderByDesc('r.created_at')->orderByDesc('r.id')->limit(5)->get(['a.name as account_name', 'u.name as creator_name', 'product.name as product_name', 'r.status', DB::raw($expression.' AS value')])
                ->map(fn ($row): array => ['name' => $row->account_name.' · '.$row->creator_name.' · '.$row->product_name, 'value' => ReportDataset::decimal($row->value), 'unit' => 'د.ع', 'note' => match ($row->status) {
                    'pending' => 'قيد التنفيذ', 'review' => 'بانتظار التحقق', 'succeeded' => 'ناجحة', 'failed' => 'فاشلة', 'refunded' => 'مرتجعة', default => $row->status,
                }])->all();
            $cards[] = ['key' => 'digital:'.$provider, 'title' => $title, 'value' => $value, 'unit' => 'د.ع', 'note' => $provider === 'rabiaa' ? 'مبلغ المبيع' : ($useCost ? 'المبلغ المصروف' : 'المبيعات الناجحة'), 'icon' => 'sales', 'available' => true, 'rows' => $rows, 'rows_total' => (clone $query)->count(), 'destination' => 'digital', 'parameters' => ['provider' => $provider, 'tab' => 'log'], 'metrics' => ['quantity' => (int) $stats->quantity, 'pending' => (int) $stats->pending, 'refunds' => (int) $stats->refunds], 'explanation' => $useCost ? 'المصروف يحتسب للعمليات الناجحة بتكلفة موثقة برد الشركة فقط. قيمة قائمة الشركة وقت الطلب ليست تسوية فعلية؛ إذا وجدت تكلفة غير موثقة يبقى الإجمالي غير معلوم.' : 'مجموع مبالغ بيع العمليات الناجحة فقط ضمن نطاقك، عبر جميع التواريخ. الطلبات المعلقة والمرتجعة لا تدخل في هذا المجموع.'];
            $index = array_key_last($cards);
            if ($useCost) {
                $cards[$index]['cost_availability'] = ['complete' => (int) $stats->unknown_cost === 0, 'unknown_count' => (int) $stats->unknown_cost, 'catalog_count' => (int) $stats->catalog_cost_count];
            }
            if ($provider === 'topup' && ! $pos && $currency === 'IQD') {
                $connections = DB::table('digital_connections as c')->whereIn('c.id', $this->digitalConnections($actor)->select('digital_connections.id'))->where('c.provider', 'topup')->join('accounts as a', 'a.id', '=', 'c.account_id');
                $cards[$index]['balance_rows_total'] = (clone $connections)->count();
                $balances = (clone $connections)->selectRaw('COUNT(*) AS total, COUNT(c.company_balance_minor) AS known, SUM(c.company_balance_minor) AS amount')->first();
                $cards[$index]['remaining'] = (int) $balances->total > 0 && (int) $balances->known === (int) $balances->total ? ReportDataset::decimal($balances->amount) : null;
                if ($costVisible) {
                    $spent = (clone $query)->groupBy('r.main_account_id')->select('r.main_account_id')->selectRaw("SUM(CASE WHEN r.status='succeeded' AND r.cost_basis='provider_response' THEN r.actual_cost_minor ELSE 0 END) AS cost, SUM(CASE WHEN r.status='succeeded' AND (r.actual_cost_minor IS NULL OR r.cost_basis IS NULL OR r.cost_basis<>'provider_response') THEN 1 ELSE 0 END) AS unknown_cost");
                    $connections->leftJoinSub($spent, 'spent', 'spent.main_account_id', '=', 'c.account_id');
                }
                $cards[$index]['balanceRows'] = $connections->orderBy('a.name')->orderBy('c.id')->get(['c.account_id', 'a.name', 'c.company_balance_minor', 'c.balance_updated_at', DB::raw($costVisible ? 'CASE WHEN COALESCE(spent.unknown_cost,0)=0 THEN COALESCE(spent.cost,0) ELSE NULL END AS spent' : 'NULL AS spent')])
                    ->map(fn ($row): array => ['agent' => (int) $row->account_id, 'name' => $row->name, 'remaining' => ReportDataset::decimal($row->company_balance_minor), 'spent' => ReportDataset::decimal($row->spent), 'updatedAt' => $row->balance_updated_at])->all();
                $own = $actor->membership->account;
                $main = app(\App\Services\Digital\TopupDistribution::class)->main($own);
                $allocations = DB::table('topup_grants as g')->whereNotNull('g.connection_id')
                    ->when($own->type === AccountType::System, fn ($q) => $q->whereIn('g.target_account_id', $this->access->accounts($actor)->select('id')), fn ($q) => $q->where('g.target_account_id', $main?->id ?? 0))
                    ->join('accounts as agent', 'agent.id', '=', 'g.target_account_id')->join('digital_connections as central', 'central.id', '=', 'g.connection_id');
                if ((clone $allocations)->exists()) {
                    $allocated = $allocations->get(['g.target_account_id', 'agent.name', 'g.balance_minor', 'g.held_minor', 'g.spent_minor', 'central.balance_updated_at']);
                    $cards[$index]['value'] = ReportDataset::decimal($allocated->sum('spent_minor'));
                    $cards[$index]['note'] = 'المبلغ المصروف';
                    $cards[$index]['explanation'] = 'خصم سعر المدير للعمليات المؤكدة من حصص الوكلاء. سعر الوكيل يسجل للتحصيل على نقطة البيع.';
                    $cards[$index]['balanceRows'] = $allocated->map(fn ($r): array => ['agent' => (int) $r->target_account_id, 'name' => $r->name, 'remaining' => ReportDataset::decimal($r->balance_minor), 'spent' => ReportDataset::decimal($r->spent_minor), 'updatedAt' => $r->balance_updated_at])->all();
                    $cards[$index]['balance_rows_total'] = $allocated->count();
                    if ($own->type !== AccountType::System) {
                        $cards[$index]['remaining'] = ReportDataset::decimal($allocated->sum('balance_minor'));
                    }
                    unset($cards[$index]['cost_availability']);
                }
            }
        }

        return $cards;
    }

    public function summary(User $actor, array $filters): array
    {
        $db = DB::class;
        $today = CarbonImmutable::now('Asia/Baghdad');
        $day = $today->toDateString();
        $currency = $filters['currency'] ?? 'IQD';
        $unit = $currency === 'IQD' ? 'د.ع' : '$';
        $scope = $this->access->accounts($actor)->select('id');
        $account = $actor->membership->account;
        $type = $account->type->value;
        $pos = $type === 'pos';
        $main = $type === 'main_agent';
        $system = $type === 'system';
        $sales = $db::table('sales as r')->whereIn('r.account_id', clone $scope)->whereIn('r.product_id', $this->access->products($actor)->select('id'))->where('r.currency', $currency)->whereNotNull('r.issued_at');
        $daySales = $this->queries->period(clone $sales, ['from' => $day, 'to' => $day], 'r.issued_at');
        $total = $pos ? 'r.retail_total_minor' : 'r.total_minor';
        $profit = $pos ? 'CAST(r.retail_total_minor AS SIGNED)-CAST(r.total_minor AS SIGNED)' : 'CAST(r.credit_minor AS SIGNED)-CAST(r.load_cost_minor AS SIGNED)';
        $cards = [];
        $make = function (string $key, string $title, mixed $value, string $cardUnit, string $note, string $icon, array $permissions, array $rows, string $destination, array $parameters = []) use (&$cards, $actor): void {
            foreach ($permissions as $permission) {
                if (! $this->access->allows($actor, $permission)) {
                    return;
                }
            }
            $cards[$key] = ['key' => $key, 'title' => $title, 'value' => $value, 'unit' => $cardUnit, 'note' => $note, 'icon' => $icon, 'available' => true, 'rows' => $rows, 'destination' => $destination, 'parameters' => $parameters];
        };
        $saleRows = function (Builder $query, string $value, bool $money = true) use ($unit, $db): array {
            return $query->join('accounts as a', 'a.id', '=', 'r.account_id')->join('catalog_products as p', 'p.id', '=', 'r.product_id')->orderByDesc('r.issued_at')->limit(5)->get(['r.id', 'a.name', 'p.name as product', 'r.status', $db::raw($value.' as value')])->map(fn ($r): array => ['name' => $r->name.' · '.$r->product, 'value' => $money ? ReportDataset::decimal($r->value) : (int) $r->value, 'unit' => $money ? $unit : 'بطاقة', 'note' => $r->status])->all();
        };
        if ($this->access->allows($actor, 'sales.view')) {
            $make('sales', $pos ? 'مبيعات اليوم' : ($system ? 'مبيعات اليوم' : 'مبيعات الشبكة اليوم'), ReportDataset::decimal((clone $daySales)->sum($total)), $unit, 'اليوم', 'sales', ['sales.view'], $saleRows(clone $daySales, $total), 'sales', ['from' => $day, 'to' => $day]);
            if ($pos) {
                $make('quantity', 'البطاقات المباعة اليوم', (int) (clone $daySales)->sum('r.quantity'), 'بطاقة', 'اليوم', 'sell', ['sales.view'], $saleRows(clone $daySales, 'r.quantity', false), 'sales', ['from' => $day, 'to' => $day]);
            }
            if ($this->access->allows($actor, 'data.profit') && ! $system && ($pos || $this->access->allows($actor, 'data.cost'))) {
                $make('profit', $pos ? 'ربح نقطة البيع اليوم' : 'ربح الوكيل اليوم', ReportDataset::decimal((clone $daySales)->sum($db::raw($profit))), $unit, 'اليوم · قبل المصاريف', 'reports', ['sales.view', 'data.profit'], $saleRows(clone $daySales, $profit), 'sales', ['from' => $day, 'to' => $day]);
            }
            if (! $system && ! $pos && $this->access->allows($actor, 'data.profit') && ! $this->access->allows($actor, 'data.cost')) {
                $make('profit', 'ربح الوكيل اليوم', null, $unit, 'عرض الربح المشتق يتطلب صلاحية تكلفة التحميل.', 'reports', ['sales.view', 'data.profit'], [], 'sales');
                $cards['profit']['available'] = false;
                $cards['profit']['required_permissions'] = ['data.profit', 'data.cost'];
            }
            foreach (['failed' => 'Print Failed', 'reprints' => 'Reprint Requested'] as $key => $status) {
                if (! $this->access->allows($actor, 'exceptions.view')) {
                    continue;
                }
                $query = (clone $sales)->where('r.status', $status);
                $make($key, $key === 'failed' ? 'عمليات الطباعة الفاشلة' : 'طلبات إعادة الطباعة', (clone $query)->count(), 'طلب', 'تحتاج متابعة', 'exceptions', ['exceptions.view'], $saleRows($query, $total), 'exceptions', ['status' => $status]);
            }
        }
        if ($system && $this->access->allows($actor, 'data.profit') && $this->access->allows($actor, 'sales.view')) {
            $value = null;
            $rows = [];
            $known = $this->access->allows($actor, 'invoices.view') && $this->access->allows($actor, 'data.cost');
            if ($known) {
                $invoices = $db::table('stock_batches as r')->join('finance_invoices as i', 'i.id', '=', 'r.invoice_id')->whereIn('r.account_id', clone $scope)->where('r.currency', $currency)->whereNotIn('r.status', ['Cancelled', 'Reversed']);
                $this->queries->period($invoices, ['from' => $day, 'to' => $day], 'i.created_at');
                $difference = 'CAST(i.amount_minor AS SIGNED)-CAST(r.cost_total_minor AS SIGNED)';
                $value = ReportDataset::decimal((clone $invoices)->sum($db::raw($difference)));
                $rows = (clone $invoices)->orderByDesc('i.created_at')->limit(5)->get(['i.id', 'i.status', $db::raw($difference.' as value')])->map(fn ($r): array => ['name' => (string) $r->id, 'value' => ReportDataset::decimal($r->value), 'unit' => $unit, 'note' => $r->status])->all();
            }
            $make('profit', 'ربح ماسال من الطلبيات اليوم', $value, $unit, $known ? 'اليوم · قبل المصاريف' : 'عرض الربح يتطلب صلاحية تكلفة التحميل والفواتير.', 'reports', ['sales.view', 'data.profit'], $rows, 'wallets', ['tab' => 'invoices', 'from' => $day, 'to' => $day]);
            $cards['profit']['available'] = $known;
        }
        if ($this->access->allows($actor, 'wallets.view')) {
            $wallets = $db::table('finance_wallets as w')->join('accounts as a', 'a.id', '=', 'w.account_id')->where('w.kind', 'account')->where('w.service', 'voucher')->where('w.currency', $currency)->whereIn('w.account_id', clone $scope);
            if (! $system) {
                $wallets->where('w.account_id', $account->id);
            }
            $balance = 'CAST(w.balance_minor AS SIGNED)-CAST(w.held_minor AS SIGNED)';
            $rows = (clone $wallets)->orderBy('a.name')->limit(5)->get(['a.name', $db::raw($balance.' as value')])->map(fn ($r): array => ['name' => $r->name, 'value' => ReportDataset::decimal($r->value), 'unit' => $unit, 'note' => ''])->all();
            $knownOwn = $system || (clone $scope)->whereKey($account->id)->exists();
            $make('balance', 'الرصيد التشغيلي المتاح', $knownOwn ? ReportDataset::decimal((clone $wallets)->sum($db::raw($balance))) : null, $unit, $knownOwn ? 'البطاقات · الآن' : 'محفظة الحساب خارج نطاق الموظف.', 'wallets', ['wallets.view'], $rows, 'wallets', ['account_id' => $account->id]);
            $cards['balance']['available'] = $knownOwn;
            $funding = $db::table('finance_funding_requests as r')->where('r.status', 'pending')->where('r.currency', $currency)->where(function ($q) use ($scope): void {
                $q->whereIn('r.from_account_id', clone $scope)->orWhereIn('r.to_account_id', clone $scope);
            });
            if ($pos) {
                $funding->where('r.to_account_id', $account->id);
            }
            if ($main) {
                $funding->where('r.from_account_id', $account->id);
            }
            $rows = (clone $funding)->join('accounts as f', 'f.id', '=', 'r.from_account_id')->join('accounts as t', 't.id', '=', 'r.to_account_id')->orderByDesc('r.created_at')->limit(5)->get(['f.name as from_name', 't.name as to_name', 'r.amount_minor', 'r.status'])->map(fn ($r): array => ['name' => $r->from_name.' ← '.$r->to_name, 'value' => ReportDataset::decimal($r->amount_minor), 'unit' => $unit, 'note' => $r->status])->all();
            $make('funding', 'طلبات التمويل المعلقة', (clone $funding)->count(), 'طلب', 'بانتظار التمويل', 'wallets', ['wallets.view'], $rows, 'wallets', ['tab' => 'funding', 'status' => 'pending']);
        }
        $available = $db::table('stock_cards as r')->whereIn('r.account_id', clone $scope)->where('r.status', 'Available')->where('r.credit_held', false)->whereNull('r.sale_id')->where(function ($q) use ($day): void {
            $q->whereNull('r.expiry')->orWhere('r.expiry', '>', $day);
        })->join('stock_batches as b', 'b.id', '=', 'r.batch_id')->where('b.currency', $currency);
        if ($this->access->allows($actor, 'inventory.view')) {
            $inventory = clone $available;
            if ($main) {
                $inventory->where('r.account_id', $account->id);
            }
            $rows = (clone $inventory)->join('catalog_products as p', 'p.id', '=', 'r.product_id')->groupBy('p.id', 'p.name')->orderBy('p.name')->limit(5)->select('p.name')->selectRaw('COUNT(*) as quantity')->get()->map(fn ($r): array => ['name' => $r->name, 'value' => (int) $r->quantity, 'unit' => 'بطاقة', 'note' => ''])->all();
            $knownOwn = ! $main || (clone $scope)->whereKey($account->id)->exists();
            $make('inventory', 'البطاقات المتاحة', $knownOwn ? (clone $inventory)->count() : null, 'بطاقة', $knownOwn ? 'المخزون الحالي' : 'المخزون الخاص بالوكيل خارج نطاق الموظف.', 'inventory', ['inventory.view'], $rows, 'inventory', ['status' => 'Available']);
            $cards['inventory']['available'] = $knownOwn;
        }
        if ($this->access->allows($actor, 'support.view') && Schema::hasTable('support_tickets')) {
            $tickets = $this->support->tickets($actor)->whereNotIn('status', ['closed', 'مغلقة']);
            $rows = (clone $tickets)->orderByDesc('last_message_at')->limit(5)->get(['title', 'status'])->map(fn ($r): array => ['name' => $r->title, 'value' => null, 'unit' => '', 'note' => $r->status])->all();
            $make('support', 'رسائل الدعم المفتوحة', (clone $tickets)->count(), 'رسالة', 'تحتاج متابعة', 'support', ['support.view'], $rows, 'support', ['status' => 'open']);
        }
        $order = match ($type) {
            'pos' => ['balance', 'sales', 'quantity', 'failed', 'reprints', 'funding'],'main_agent' => ['balance', 'sales', 'profit', 'inventory', 'funding', 'reprints'],'sub_agent','sub_branch' => ['balance', 'sales', 'profit', 'funding', 'reprints', 'support'],default => ['sales', 'profit', 'inventory', 'funding', 'reprints', 'support']
        };
        $ordered = [];
        if ($system && $actor->membership->kind === 'owner' && $this->access->allows($actor, 'account.view')) {
            foreach ([['mainAgents', 'الوكلاء الرئيسيون', ['main_agent']], ['subAgents', 'الفروع والفروع الفرعية', ['sub_agent', 'sub_branch']], ['networkPOS', 'نقاط البيع', ['pos']]] as [$key,$title,$types]) {
                $q = $this->access->accounts($actor)->whereIn('type', $types);
                $permission = $key === 'networkPOS' ? 'pos.view' : 'agents.view';
                if (! $this->access->allows($actor, $permission)) {
                    continue;
                }
                $rows = (clone $q)->orderBy('name')->limit(5)->get(['name', 'city', 'status'])->map(fn ($row): array => ['name' => $row->name, 'value' => null, 'unit' => '', 'note' => $row->city.' · '.$row->status])->all();
                $ordered[] = ['key' => $key, 'title' => $title, 'value' => (clone $q)->count(), 'unit' => 'حساب', 'note' => 'نطاق شبكة التوزيع', 'icon' => 'agents', 'available' => true, 'rows' => $rows, 'destination' => $key === 'networkPOS' ? 'pos' : 'agents', 'parameters' => []];
            }
            if ($this->access->allows($actor, 'governorates.view')) {
                $cities = $db::table('account_cities')->where('active', true);
                $rows = (clone $cities)->orderBy('name')->limit(5)->get(['name'])->map(fn ($row): array => ['name' => $row->name, 'value' => null, 'unit' => '', 'note' => 'مفعّلة'])->all();
                array_splice($ordered, 2, 0, [['key' => 'activeGovernorates', 'title' => 'المحافظات المفعّلة', 'value' => (clone $cities)->count(), 'unit' => 'محافظة', 'note' => 'المحافظات', 'icon' => 'map', 'available' => true, 'rows' => $rows, 'destination' => 'governorates', 'parameters' => []]]);
            }
        }
        foreach ($order as $key) {
            if (isset($cards[$key])) {
                $ordered[] = $cards[$key];
            }
        }
        $ordered = [...$ordered, ...$this->digitalCards($actor, $currency)];
        $chart = [];
        if ($this->access->allows($actor, 'sales.view')) {
            $weekly = $this->queries->period(clone $sales, ['from' => $today->subDays(6)->toDateString(), 'to' => $day], 'r.issued_at');
            for ($i = 6; $i >= 0; $i--) {
                $date = $today->subDays($i);
                $start = $date->startOfDay()->utc();
                $end = $date->addDay()->startOfDay()->utc();
                $weekly->selectRaw('COALESCE(SUM(CASE WHEN r.issued_at >= ? AND r.issued_at < ? THEN '.$total.' ELSE 0 END),0) AS amount_'.$i.', SUM(CASE WHEN r.issued_at >= ? AND r.issued_at < ? THEN 1 ELSE 0 END) AS transactions_'.$i, [$start, $end, $start, $end]);
            }
            $totals = $weekly->first();
            for ($i = 6; $i >= 0; $i--) {
                $date = $today->subDays($i);
                $chart[] = ['day' => $date->toDateString(), 'label' => $date->format('m/d'), 'value' => ReportDataset::decimal($totals->{'amount_'.$i}), 'transactions' => (int) $totals->{'transactions_'.$i}, 'today' => $i === 0];
            }
        }
        $regions = [];
        if ($this->access->allows($actor, 'agents.view') && $this->access->allows($actor, 'pos.view') && $this->access->allows($actor, 'inventory.view')) {
            $agents = $this->access->accounts($actor)->where('type', 'main_agent')->orderBy('name')->get(['id', 'name', 'city', 'color']);
            $ids = $agents->pluck('id');
            $posCounts = $db::table('account_closure as region_scope')->join('accounts as point', 'point.id', '=', 'region_scope.descendant_id')->whereIn('region_scope.ancestor_id', $ids)->whereIn('point.id', $this->access->accounts($actor)->select('id'))->where('point.type', 'pos')->groupBy('region_scope.ancestor_id')->select('region_scope.ancestor_id')->selectRaw('COUNT(*) AS quantity')->pluck('quantity', 'ancestor_id');
            $stockCounts = (clone $available)->whereIn('r.account_id', $ids)->groupBy('r.account_id')->select('r.account_id')->selectRaw('COUNT(*) AS quantity')->pluck('quantity', 'account_id');
            foreach ($agents as $region) {
                $regions[] = ['id' => $region->id, 'name' => $region->name, 'city' => $region->city, 'color' => $region->color, 'pos_count' => (int) ($posCounts[$region->id] ?? 0), 'available_count' => (int) ($stockCounts[$region->id] ?? 0)];
            }
        }

        return ['cards' => $ordered, 'recent_operations' => $this->recentOperations($actor, $currency), 'chart' => $chart, 'regions' => $regions, 'currency' => $currency, 'day' => $day, 'timezone' => 'Asia/Baghdad', 'generated_at' => now()->toISOString(), 'availability' => ['digital_services' => $this->access->allows($actor, 'digital.view') && Schema::hasTable('digital_orders'), 'presence' => $this->access->allows($actor, 'map.view') && Schema::hasTable('user_presences')]];
    }
}

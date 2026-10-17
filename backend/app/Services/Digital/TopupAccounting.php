<?php

namespace App\Services\Digital;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Digital\DigitalConnection;
use App\Models\Digital\DigitalOffer;
use App\Models\Digital\DigitalOrder;
use App\Models\Digital\TopupGrant;
use App\Models\Finance\Invoice;
use App\Support\RequestReadCache;

class TopupAccounting
{
    public function grant(Account $account, bool $lock = false): ?TopupGrant
    {
        return $lock ? $this->resolveGrant($account, true) : RequestReadCache::remember('TopupAccounting.grant:'.$account->id, fn (): ?TopupGrant => $this->resolveGrant($account));
    }

    private function resolveGrant(Account $account, bool $lock = false): ?TopupGrant
    {
        $main = app(TopupDistribution::class)->main($account);
        $query = TopupGrant::where('target_account_id', $main?->id ?? 0)->whereNotNull('connection_id');

        return ($lock ? $query->lockForUpdate() : $query)->first();
    }

    public function connection(?Account $main): ?DigitalConnection
    {
        return RequestReadCache::remember('TopupAccounting.connection:'.($main?->id ?? 0), fn (): ?DigitalConnection => $this->resolveConnection($main));
    }

    private function resolveConnection(?Account $main): ?DigitalConnection
    {
        if (! $main) {
            return null;
        }
        $grant = $this->grant($main);

        return $grant ? DigitalConnection::find($grant->connection_id) : DigitalConnection::where('provider', 'topup')->where('account_id', $main->id)->first();
    }

    public function retail(DigitalOffer $offer, Account $account): int
    {
        $grant = $offer->connection->provider === 'topup' ? $this->grant($account) : null;

        return max($offer->retail_minor, (int) ($grant?->retail_prices[(string) $offer->topup_category_id] ?? $offer->retail_minor));
    }

    public function free(DigitalConnection $connection): ?int
    {
        if ($connection->company_balance_minor === null) {
            return null;
        }

        return max(0, $connection->company_balance_minor - (int) TopupGrant::where('connection_id', $connection->id)->sum('balance_minor'));
    }

    public function reserve(DigitalConnection $connection, Account $pos, DigitalOffer $offer): ?array
    {
        if ($connection->provider !== 'topup') {
            return null;
        }
        abort_unless($connection->account->type === AccountType::System, 409, 'بيع Topup يتطلب حصة من الإدارة وتمويل نقطة البيع.');
        $grant = $this->grant($pos, true);
        abort_unless($grant && $grant->active && $grant->connection_id === $connection->id, 403, 'لا توجد حصة Topup مفعلة للوكيل.');
        abort_unless($grant->balance_minor - $grant->held_minor >= $offer->retail_minor, 422, 'رصيد Topup المتاح للوكيل لا يكفي لهذه الفئة.');
        $providerHeld = (int) DigitalOrder::where('connection_id', $connection->id)->where('reservation_active', true)->lockForUpdate()->sum('quoted_cost_minor');
        abort_unless($connection->company_balance_minor !== null && $connection->company_balance_minor - $providerHeld >= $offer->cost_minor, 422, 'رصيد الشركة غير معلوم أو لا يكفي؛ حدّث رصيد التبب.');
        $wallets = app(TopupWallets::class);
        abort_unless($wallets->initialized($grant), 409, 'رصيد Topup لم يهيأ بعد؛ راجع مدير النظام.');
        $wallet = $wallets->point($grant, $pos);
        $wallets->hold($wallet, $this->retail($offer, $pos));
        $grant->held_minor += $offer->retail_minor;
        $grant->save();

        return ['grant' => $grant, 'wallet_id' => $wallet->id];
    }

    public function resolve(DigitalOrder $order, string $status): void
    {
        if (! $order->topup_grant_id || ! in_array($status, ['succeeded', 'failed', 'refunded'], true)) {
            return;
        }
        $grant = TopupGrant::whereKey($order->topup_grant_id)->lockForUpdate()->firstOrFail();
        $base = (int) $order->admin_price_minor;
        $wallets = app(TopupWallets::class);
        if ($order->accounted_at === null) {
            abort_unless($grant->held_minor >= $base, 409, 'حجز رصيد التبب غير مطابق للعملية.');
            $pointBudget = $order->budget_basis === 'point_budget_v1';
            $wallet = $wallets->bound($grant, $pointBudget ? $order->account_id : $grant->target_account_id, $pointBudget ? 'point' : 'main');
            abort_unless(! $pointBudget || $wallet->id === $order->budget_wallet_id, 409, 'محفظة رصيد العملية غير مطابقة.');
            $wallets->hold($wallet, -($pointBudget ? $order->quoted_retail_minor : $base));
            $grant->held_minor -= $base;
            if ($status !== 'failed') {
                abort_unless($grant->balance_minor >= $base, 409);
                $grant->balance_minor -= $base;
                $grant->spent_minor += $base;
                $wallets->settle($order, $grant);
                Invoice::create(['account_id' => $order->account_id, 'creditor_account_id' => $order->main_account_id,
                    'creator_id' => $order->creator_id, 'kind' => 'receivable', 'supplier' => $order->mainAccount->name,
                    'service' => 'topup', 'currency' => 'IQD', 'amount_minor' => $order->quoted_retail_minor,
                    'reference' => 'Topup '.$order->request_id, 'source_type' => 'digital_topup', 'source_id' => $order->id]);
            }
            $order->accounted_at = now();
            $order->save();
        }
        if ($status === 'refunded') {
            $invoice = Invoice::where('source_type', 'digital_topup')->where('source_id', $order->id)->lockForUpdate()->first();
            abort_unless($invoice && $invoice->paid_minor === 0, 409, 'العملية محصلة؛ يلزم تسوية محاسبية قبل الاسترجاع.');
            if ($invoice->status !== 'cancelled') {
                $wallets->settle($order, $grant, true);
                $grant->balance_minor += $base;
                $grant->spent_minor -= $base;
                $invoice->update(['status' => 'cancelled', 'version' => $invoice->version + 1]);
            }
        }
        $grant->save();
    }
}

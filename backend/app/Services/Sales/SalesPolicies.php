<?php

namespace App\Services\Sales;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\CatalogProduct;
use App\Models\Sales\PrintPolicy;
use App\Models\Sales\PrintRule;
use App\Models\Sales\Sale;
use App\Models\Sales\SaleLimit;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Finance\FinanceOperations;
use App\Services\Finance\Money;
use App\Services\ManagementAuthority;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SalesPolicies
{
    public function __construct(private SalesAccess $access, private AuditLogger $audit, private ManagementAuthority $authority, private FinanceOperations $operations) {}

    public function context(Account $account, ?int $productId = null): array
    {
        $base = PrintPolicy::findOrFail(1);
        $policy = $base->policy;
        $main = $this->access->main($account);
        $networkId = $main->id;
        $best = -1.0;
        $selected = null;
        $distances = DB::table('account_closure')->where('descendant_id', $account->id)->pluck('depth', 'ancestor_id');
        foreach (PrintRule::where('active', true)->orderBy('id')->get() as $rule) {
            $specific = $rule->policy['daily_product_mode'] === 'selected';
            if ($specific && ! in_array($productId, $rule->policy['daily_products'], true)) {
                continue;
            }
            foreach ($rule->targets as $target) {
                $rank = match ($target['kind']) {
                    'pos' => $account->type === AccountType::Pos && $target['id'] === $account->id ? 10000 : -1,
                    'agent' => $account->type !== AccountType::Pos && $target['id'] === $account->id ? 9000 : -1,
                    'tree' => isset($distances[$target['id']]) ? 8000 - (int) $distances[$target['id']] : -1,
                    default => -1,
                };
                if ($rank < 0) {
                    continue;
                }
                $rank += $specific ? 0.5 : 0;
                if ($rank > $best) {
                    $best = $rank;
                    $selected = $rule;
                    $networkId = $target['kind'] === 'tree' ? $target['id'] : $main->id;
                }
            }
        }
        if ($selected) {
            $policy = array_replace($policy, $selected->policy);
        }

        return ['policy' => $policy, 'settings' => $base->settings, 'version' => $base->version, 'network_id' => $networkId, 'rule_id' => $selected?->id, 'custom' => $selected !== null, 'product_id' => $policy['daily_product_mode'] === 'selected' ? $productId : null];
    }

    public function day(): array
    {
        $start = CarbonImmutable::now('Asia/Baghdad')->startOfDay()->utc();

        return [$start, $start->addDay()];
    }

    public function members(Account $account, array $context): array
    {
        return $context['policy']['daily_mode'] === 'network'
            ? DB::table('account_closure')->where('ancestor_id', $context['network_id'])->pluck('descendant_id')->map(fn ($id): int => (int) $id)->all() : [$account->id];
    }

    public function usage(Account $account, array $context): array
    {
        [$start, $end] = $this->day();
        $query = Sale::whereIn('account_id', $this->members($account, $context));
        if ($context['product_id']) {
            $query->where('product_id', $context['product_id']);
        }
        $used = (int) (clone $query)->where('first_printed_at', '>=', $start)->where('first_printed_at', '<', $end)->sum('quantity');
        $pending = (int) (clone $query)->whereNull('first_printed_at')->where('print_pending', true)->sum('quantity');
        $limit = $context['policy']['daily_cards'];

        return ['limit' => $limit, 'used' => $used, 'pending' => $pending, 'remaining' => $limit ? max(0, $limit - $used - $pending) : null, 'day' => $start->setTimezone('Asia/Baghdad')->toDateString(), 'mode' => $context['policy']['daily_mode']];
    }

    public function wait(Account $account, array $context): int
    {
        $last = Sale::where('account_id', $account->id)->max('print_started_at');

        return $last ? max(0, (int) ceil(CarbonImmutable::parse($last)->addSeconds($context['policy']['interval_seconds'])->getTimestamp() - now()->getTimestamp())) : 0;
    }

    public function checkSale(Account $account, CatalogProduct $product, int $quantity, int $total, array $context): void
    {
        abort_unless($context['settings']['sales_enabled'] && $context['settings']['printing_enabled'], 409, 'البيع أو الطباعة موقوفة من إعدادات النظام.');
        $policy = $context['policy'];
        $limits = SaleLimit::whereIn('account_id', DB::table('account_closure')->where('descendant_id', $account->id)->select('ancestor_id'))->where('product_id', $product->id)->get();
        $maximum = $policy['max_cards'];
        foreach ($limits as $limit) {
            if ($limit->max_cards !== null) {
                $maximum = min($maximum, $limit->max_cards);
            }
        }
        abort_unless($quantity <= $maximum, 422, 'عدد البطاقات يتجاوز الحد المسموح للطلب.');
        [$start, $end] = $this->day();
        $recent = Sale::where('account_id', $account->id)->where('issued_at', '>=', $start)->where('issued_at', '<', $end);
        $last = (clone $recent)->max('issued_at');
        abort_if($last && CarbonImmutable::parse($last)->addSeconds($context['settings']['velocity_seconds'])->isFuture(), 429, 'انتظر الفاصل المحدد بين العمليات.');
        $dailyQuantity = $product->daily_quantity;
        $dailyAmount = $product->daily_amount === null ? null : Money::minor($product->daily_amount);
        foreach ($limits as $limit) {
            if ($limit->daily_quantity !== null) {
                $dailyQuantity = $dailyQuantity === null ? $limit->daily_quantity : min($dailyQuantity, $limit->daily_quantity);
            }
            if ($limit->daily_amount_minor !== null) {
                $dailyAmount = $dailyAmount === null ? $limit->daily_amount_minor : min($dailyAmount, $limit->daily_amount_minor);
            }
        }
        if ($context['custom'] && $policy['daily_cards'] > 0) {
            $network = Sale::whereIn('account_id', $this->members($account, $context))->where('issued_at', '>=', $start)->where('issued_at', '<', $end);
            if ($context['product_id']) {
                $network->where('product_id', $context['product_id']);
            }
            abort_if((int) $network->sum('quantity') + $quantity > $policy['daily_cards'], 422, 'تم بلوغ الحد اليومي المخصص للبطاقات.');
        } elseif ($dailyQuantity !== null) {
            abort_if((int) (clone $recent)->where('product_id', $product->id)->sum('quantity') + $quantity > $dailyQuantity, 422, 'تم بلوغ الحد اليومي لكمية الفئة.');
        }
        if ($dailyAmount !== null) {
            abort_if((int) (clone $recent)->where('product_id', $product->id)->sum('total_minor') + $total > $dailyAmount, 422, 'تم بلوغ الحد المالي اليومي للفئة.');
        }
        $providerLimit = $context['settings']['provider_daily_minor'][$product->currency] ?? null;
        if ($providerLimit !== null) {
            abort_if((int) (clone $recent)->where('provider_id', $product->provider_id)->where('currency', $product->currency)->sum('total_minor') + $total > $providerLimit, 422, 'تم بلوغ الحد اليومي للمزود.');
        }
    }

    public function checkPrint(Sale $sale, Account $own, array $context): void
    {
        if ($sale->first_printed_at) {
            return;
        }
        $usage = $this->usage($own, $context);
        $pendingOwn = $sale->print_pending ? $sale->quantity : 0;
        abort_if($usage['limit'] && $usage['limit'] < $usage['used'] + $usage['pending'] - $pendingOwn + $sale->quantity, 422, 'تم بلوغ الحد اليومي للبطاقات المطبوعة.');
    }

    public function save(User $actor, array $data, Request $request): array
    {
        $this->access->require($actor, 'security.policies', true);
        $data['policy'] = $this->normalizePolicy($data['policy']);

        return $this->operations->execute($actor, 'sales.policy', $data, function () use ($actor, $data, $request): array {
            $base = PrintPolicy::lockForUpdate()->findOrFail(1);
            $this->authority->version($base, (int) $data['version']);
            $settings = $base->settings;
            if (isset($data['settings'])) {
                $settings = array_replace($settings, $data['settings']);
                if (array_key_exists('provider_daily', $data['settings'])) {
                    $settings['provider_daily_minor'] = array_map(fn (?string $v): ?int => $v === null ? null : Money::minor($v), $data['settings']['provider_daily']);
                    unset($settings['provider_daily']);
                }
            }
            $base->update(['policy' => $data['policy'], 'settings' => $settings, 'version' => $base->version + 1]);
            $this->audit->record('security.policies', $request, $actor, $actor->membership->account_id, ['policy' => $base->policy, 'settings' => $base->settings]);

            return $this->baseDto($base);
        });
    }

    public function baseDto(?PrintPolicy $base = null): array
    {
        $base ??= PrintPolicy::findOrFail(1);
        $settings = $base->settings;
        $settings['provider_daily'] = array_map(fn (?int $v): ?string => $v === null ? null : Money::decimal($v), $settings['provider_daily_minor']);
        unset($settings['provider_daily_minor']);

        return ['version' => $base->version, 'settings' => $settings, 'policy' => $base->policy];
    }

    public function saveRule(User $actor, ?int $id, array $data, Request $request): array
    {
        $this->access->require($actor, 'security.policies', true);
        $data['policy'] = $this->normalizePolicy($data['policy']);

        return $this->operations->execute($actor, 'sales.print-rule', $data + ['rule_id' => $id], function () use ($actor, $id, $data, $request): array {
            PrintPolicy::lockForUpdate()->findOrFail(1);
            $record = $id ? PrintRule::lockForUpdate()->findOrFail($id) : null;
            if ($record) {
                $this->authority->version($record, (int) $data['version']);
            } else {
                abort_unless((int) $data['version'] === 0, 409, 'القاعدة الجديدة تبدأ بإصدار صفر.');
            }
            $targets = $data['targets'];
            $keys = [];
            foreach ($targets as &$target) {
                $target['id'] = (int) $target['id'];
                $targetAccount = $this->access->accounts($actor)->findOrFail($target['id']);
                abort_unless(($target['kind'] === 'pos' && $targetAccount->type === AccountType::Pos) || ($target['kind'] !== 'pos' && in_array($targetAccount->type, [AccountType::MainAgent, AccountType::SubAgent, AccountType::SubBranch], true)), 422, 'نطاق قاعدة الطباعة غير صالح.');
                $key = $target['kind'].':'.$target['id'];
                if (isset($keys[$key])) {
                    throw ValidationException::withMessages(['targets' => 'النطاق مكرر.']);
                }
                $keys[$key] = true;
            }
            unset($target);
            if ($data['policy']['daily_product_mode'] === 'selected') {
                abort_unless(CatalogProduct::whereIn('id', $data['policy']['daily_products'])->count() === count($data['policy']['daily_products']), 422, 'اختر فئات موجودة.');
            }
            if ($data['active']) {
                foreach (PrintRule::where('active', true)->when($id, fn ($q) => $q->whereKeyNot($id))->get() as $existing) {
                    $sameMode = $existing->policy['daily_product_mode'] === $data['policy']['daily_product_mode'];
                    $overlap = array_intersect(array_map(fn (array $t): string => $t['kind'].':'.$t['id'], $existing->targets), array_keys($keys));
                    $sameProducts = $data['policy']['daily_product_mode'] === 'all' || array_intersect($existing->policy['daily_products'], $data['policy']['daily_products']);
                    abort_if($sameMode && $overlap && $sameProducts, 409, 'توجد قاعدة فعالة لنفس النطاق والفئات؛ عدّل القاعدة الحالية.');
                }
            }
            $values = ['name' => $data['name'], 'targets' => $targets, 'policy' => $data['policy'], 'active' => $data['active'], 'version' => $record ? $record->version + 1 : 1];
            if ($record) {
                $record->update($values);
            } else {
                $record = PrintRule::create($values);
            }
            $this->audit->record('security.policies', $request, $actor, $actor->membership->account_id, ['rule_id' => $record->id, 'rule' => $values]);

            return ['id' => $record->id] + $values;
        });
    }

    private function normalizePolicy(array $policy): array
    {
        foreach (['failed_retries', 'max_cards', 'interval_seconds', 'daily_cards'] as $field) {
            $policy[$field] = (int) $policy[$field];
        }
        $policy['daily_products'] = array_map(intval(...), $policy['daily_products']);

        return $policy;
    }
}

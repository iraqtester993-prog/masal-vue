<?php

namespace App\Services\Stock;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\CatalogProduct;
use App\Models\CatalogProvider;
use App\Models\Finance\Invoice;
use App\Models\OperatingGovernorate;
use App\Models\OrderSource;
use App\Models\Stock\StockBatch;
use App\Models\Stock\StockCard;
use App\Models\Stock\StockOrder;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\CatalogAccess;
use App\Services\Finance\FinanceOperations;
use App\Services\Finance\Ledger;
use App\Services\Finance\Money;
use App\Services\ManagementAuthority;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StockImport
{
    public function __construct(private StockAccess $access, private CatalogAccess $catalog, private FinanceOperations $operations, private Ledger $ledger, private AuditLogger $audit, private ManagementAuthority $authority, private StockValuation $valuation) {}

    public static function fingerprint(string $value): string
    {
        return hash_hmac('sha256', $value, (string) config('app.key'));
    }

    private function clean(mixed $value, int $max, string $field): string
    {
        if ($value === null) {
            return '';
        }
        if (! is_string($value) || mb_strlen($value) > $max) {
            throw ValidationException::withMessages([$field => 'بيانات البطاقة يجب أن تكون نصوصًا ضمن الحد المسموح.']);
        }

        return trim(preg_replace('/[\x{200e}\x{200f}\x{202a}-\x{202e}]/u', '', $value));
    }

    public static function categoryCode(string $code): string
    {
        return preg_replace('/^(EVS|EVD)-/', '', mb_strtoupper(trim($code)));
    }

    public static function expiry(string $value): string
    {
        if (preg_match('/^(\d{4})[-\/](\d{1,2})[-\/](\d{1,2})$/', $value, $match)) {
            return $match[1].'-'.str_pad($match[2], 2, '0', STR_PAD_LEFT).'-'.str_pad($match[3], 2, '0', STR_PAD_LEFT);
        }
        if (preg_match('/^(\d{1,2})[-\/](\d{1,2})[-\/](\d{4})$/', $value, $match)) {
            return $match[3].'-'.str_pad($match[2], 2, '0', STR_PAD_LEFT).'-'.str_pad($match[1], 2, '0', STR_PAD_LEFT);
        }

        return $value;
    }

    public function rows(array $rows, CatalogProduct $product, string $orderKey, string $lineKey, string $defaultExpiry = ''): array
    {
        $allowed = ['source_row', 'serial', 'pin', 'expiry', 'cvc', 'reference', 'extra_fields', 'parse_error'];
        $extraDefinitions = collect($product->extra_fields)->keyBy('key');
        $result = [];
        foreach ($rows as $index => $raw) {
            if (! is_array($raw) || array_is_list($raw) || array_diff(array_keys($raw), $allowed)) {
                throw ValidationException::withMessages(['rows' => 'يتضمن سطر البطاقة حقولًا غير مسموحة.']);
            }
            if (isset($raw['source_row']) && (! is_int($raw['source_row']) || $raw['source_row'] < 1 || $raw['source_row'] > 1000000)) {
                throw ValidationException::withMessages(['rows' => 'رقم سطر البطاقة غير صالح.']);
            }
            $row = ['source_row' => $raw['source_row'] ?? $index + 1];
            foreach (['pin' => 500, 'serial' => 190, 'expiry' => 30, 'cvc' => 100, 'reference' => 500, 'parse_error' => 1600] as $key => $max) {
                $row[$key] = $this->clean($raw[$key] ?? '', $max, 'rows');
            }
            if ($row['serial'] === '') {
                $row['serial'] = 'AUTO-'.self::fingerprint($orderKey.':'.$lineKey.':'.$index);
            }
            $row['expiry'] = self::expiry($row['expiry'] ?: $defaultExpiry);
            $extras = $raw['extra_fields'] ?? [];
            if (! is_array($extras) || (count($extras) && array_is_list($extras)) || array_diff(array_keys($extras), $extraDefinitions->keys()->all())) {
                throw ValidationException::withMessages(['rows' => 'حقول البطاقة الإضافية غير معرّفة لهذه الفئة.']);
            }
            $row['extra_fields'] = [];
            foreach ($extraDefinitions as $key => $definition) {
                $row['extra_fields'][$key] = $this->clean($extras[$key] ?? '', 500, 'rows');
            }
            foreach (['cvc', 'reference', 'parse_error'] as $optional) {
                if ($row[$optional] === '') {
                    unset($row[$optional]);
                }
            }
            if ($row['extra_fields'] === []) {
                unset($row['extra_fields']);
            }
            $result[] = $row === $raw ? $raw : $row;
        }

        return $result;
    }

    public function preview(User $actor, array $input, bool $lock = false): array
    {
        $account = $this->access->account($actor, (int) $input['account_id'], 'import.preview', $lock);
        abort_unless($account->isOperational(), 409, 'حساب الوكيل موقوف.');
        $providerQuery = CatalogProvider::whereKey($input['provider_id'])->where('status', 'active');
        $provider = ($lock ? $providerQuery->lockForUpdate() : $providerQuery)->firstOrFail();
        $sourceQuery = OrderSource::whereKey($input['source_id'])->where('network_account_id', $account->id)->where('provider_id', $provider->id)->where('status', 'active');
        $source = ($lock ? $sourceQuery->lockForUpdate() : $sourceQuery)->firstOrFail();
        $cityQuery = OperatingGovernorate::where('name', $input['city'])->where('active', true);
        if (! ($lock ? $cityQuery->lockForUpdate() : $cityQuery)->exists()) {
            throw ValidationException::withMessages(['city' => 'اختر محافظة مفعلة.']);
        }
        if ($lock) {
            DB::table('catalog_meta')->where('id', 1)->lockForUpdate()->firstOrFail();
        }
        $products = $this->catalog->forAccount($account->id)->where('provider_id', $provider->id)->where('status', 'active')->get();
        $draft = array_intersect_key($input, array_flip(['order_key', 'account_id', 'provider_id', 'source_id', 'city', 'category_count']));
        $draft['lines'] = [];
        $prepared = [];
        $allPins = [];
        $allSerials = [];
        foreach ($input['lines'] as $line) {
            $code = self::categoryCode($line['category_code'] ?? '');
            $matches = $code === '' ? collect() : $products->filter(fn ($product): bool => in_array($code, array_map(self::categoryCode(...), $product->import_codes), true));
            if ($matches->count() > 1) {
                throw ValidationException::withMessages(['lines' => 'رمز الاستيراد مربوط بأكثر من فئة؛ صحح إعدادات الفئات.']);
            }
            $product = $matches->first() ?? $products->firstWhere('id', $line['product_id'] ?? null);
            if (! $product || (! empty($line['product_id']) && (int) $line['product_id'] !== $product->id)) {
                throw ValidationException::withMessages(['lines' => 'فئة الملف لا تطابق الشركة أو الربط التلقائي أو الفئات المسموحة.']);
            }
            if ($product->allowed_cities && ! in_array($input['city'], $product->allowed_cities, true)) {
                throw ValidationException::withMessages(['lines' => 'الفئة غير مسموحة في محافظة الطلبية.']);
            }
            if (isset($line['declared_count']) && $line['declared_count'] !== count($line['rows'])) {
                throw ValidationException::withMessages(['lines' => 'عدد البطاقات لا يطابق رأس الملف.']);
            }
            $cost = Money::minor($line['cost'], 'cost');
            $expenses = Money::minor($line['expenses'], 'expenses', true);
            $priceQuery = DB::table('finance_prices')->where('account_id', $account->id)->where('product_id', $product->id);
            $price = ($lock ? $priceQuery->lockForUpdate() : $priceQuery)->first();
            if (! $price || $price->price_minor < 1 || $price->currency !== $product->currency || ($product->minimum_price !== null && $price->price_minor < Money::minor($product->minimum_price, 'minimum_price'))) {
                throw ValidationException::withMessages(['lines' => 'حدد سعرًا صحيحًا للفئة والعملة للوكيل من قسم الأسعار أولًا.']);
            }
            $rows = $this->rows($line['rows'], $product, $input['order_key'], $line['key'], $line['default_expiry'] ?? '');
            $normalized = ['key' => $line['key'], 'name' => $line['name'], 'category_code' => $code, 'product_id' => $product->id, 'declared_count' => $line['declared_count'] ?? null, 'cost' => Money::decimal($cost), 'expenses' => Money::decimal($expenses), 'default_expiry' => self::expiry($line['default_expiry'] ?? ''), 'rows' => $rows];
            $draft['lines'][] = $normalized;
            $prepared[] = ['line' => $normalized, 'product' => $product, 'price' => (int) $price->price_minor, 'price_version' => (int) $price->version, 'cost' => $cost, 'expenses' => $expenses];
            foreach ($rows as $row) {
                if ($row['pin'] !== '') {
                    $allPins[] = self::fingerprint($row['pin']);
                }
                $allSerials[] = self::fingerprint($row['serial']);
            }
        }
        if (count(array_unique(array_column($draft['lines'], 'product_id'))) !== (int) $input['category_count']) {
            throw ValidationException::withMessages(['category_count' => 'عدد الفئات لا يطابق ملفات الطلبية.']);
        }
        $existingPins = [];
        $existingSerials = [];
        foreach (array_chunk(array_unique($allPins), 500) as $chunk) {
            foreach (StockCard::whereIn('pin_hash', $chunk)->pluck('pin_hash') as $hash) {
                $existingPins[$hash] = true;
            }
        }
        foreach (array_chunk(array_unique($allSerials), 500) as $chunk) {
            foreach (StockCard::whereIn('serial_hash', $chunk)->get(['serial_hash', 'product_id']) as $card) {
                $existingSerials[$card->product_id.':'.$card->serial_hash] = true;
            }
        }
        unset($allPins, $allSerials);
        $report = ['quantity' => 0, 'rejected' => 0, 'category_count' => (int) $input['category_count'], 'amounts' => [], 'lines' => []];
        $today = now('Asia/Baghdad')->toDateString();
        foreach ($prepared as $entry) {
            $product = $entry['product'];
            $checked = [];
            $accepted = 0;
            foreach ($entry['line']['rows'] as $row) {
                $error = $row['parse_error'] ?? '';
                foreach ($product->field_policy as $field => $policy) {
                    if ($policy === 'required' && ($row[$field] ?? '') === '' && $error === '') {
                        $error = $field === 'pin' ? 'رمز البطاقة مفقود؛ أكمل الرمز في هذا السطر وتأكد من ربط عمود pin' : 'حقل مطلوب مفقود: '.$field.'؛ أكمله في الملف وتأكد من ربط عموده';
                    }
                }
                foreach ($product->extra_fields as $definition) {
                    if ($definition['required'] && $row['extra_fields'][$definition['key']] === '' && $error === '') {
                        $error = 'حقل مطلوب: '.$definition['label'];
                    }
                }
                $pinHash = self::fingerprint($row['pin']);
                $serialHash = self::fingerprint($row['serial']);
                $serialKey = $product->id.':'.$serialHash;
                if ($error === '' && isset($existingPins[$pinHash])) {
                    $error = 'رمز البطاقة مكرر داخل الملف أو موجود في المخزون؛ احذف الصف المكرر ثم أعد الفحص';
                }
                if ($error === '' && isset($existingSerials[$serialKey])) {
                    $error = 'سيريال البطاقة مكرر ضمن الفئة؛ صحح السيريال أو احذف الصف المكرر';
                }
                $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $row['expiry']);
                if ($error === '' && (! $date || $date->format('Y-m-d') !== $row['expiry'] || $row['expiry'] <= $today)) {
                    $error = 'تاريخ الانتهاء مفقود أو منتهٍ أو بتنسيق غير صحيح؛ استخدم تاريخًا بعد اليوم بصيغة YYYY-MM-DD أو حدد التاريخ الافتراضي';
                }
                if ($error === '') {
                    $existingPins[$pinHash] = true;
                    $existingSerials[$serialKey] = true;
                    $accepted++;
                }
                $checked[] = ['source_row' => $row['source_row'], 'error' => $error];
            }
            $rejected = count($checked) - $accepted;
            $amount = $accepted ? Money::multiply($entry['price'], $accepted) : 0;
            $costTotal = ($accepted ? Money::multiply($entry['cost'], $accepted) : 0) + $entry['expenses'];
            if ($costTotal > Money::MAX_MINOR || ($report['amounts'][$product->currency] ?? 0) + $amount > Money::MAX_MINOR) {
                throw ValidationException::withMessages(['amount' => 'المبلغ الإجمالي يتجاوز الحد المسموح.']);
            }
            $report['quantity'] += $accepted;
            $report['rejected'] += $rejected;
            $report['amounts'][$product->currency] = ($report['amounts'][$product->currency] ?? 0) + $amount;
            $report['lines'][] = ['key' => $entry['line']['key'], 'name' => $entry['line']['name'], 'category_code' => $entry['line']['category_code'], 'product_id' => $product->id, 'product_name' => $product->name, 'currency' => $product->currency, 'cost' => Money::decimal($entry['cost']), 'expenses' => Money::decimal($entry['expenses']), 'load_price' => Money::decimal($entry['price']), 'price_version' => $entry['price_version'], 'product_version' => $product->version, 'accepted' => $accepted, 'rejected' => $rejected, 'amount' => Money::decimal($amount), 'cost_total' => Money::decimal($costTotal), 'checked' => $checked];
        }
        unset($existingPins, $existingSerials, $prepared);
        $report['amounts'] = array_map(Money::decimal(...), $report['amounts']);
        $report['preview_hash'] = self::fingerprint(json_encode([$draft, $report], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $report['draft'] = $draft;

        return $report;
    }

    private function summary(array $preview): array
    {
        $result = array_intersect_key($preview, array_flip(['quantity', 'rejected', 'category_count', 'amounts', 'lines']));
        $result['product_ids'] = array_values(array_unique(array_column($preview['lines'], 'product_id')));

        return $result;
    }

    public function submit(User $actor, array $data, Request $request, ?int $id = null): StockOrder
    {
        $this->access->account($actor, $data['account_id'], 'import.preview');
        $result = $this->operations->execute($actor, $id ? 'import.resubmit' : 'import.submit', $data + ['order_id' => $id], function () use ($actor, $data, $request, $id): array {
            $order = $id ? $this->access->orders($actor)->lockForUpdate()->findOrFail($id) : null;
            if ($order) {
                abort_unless($order->creator_id === $actor->id && $order->status === 'returned' && $order->account_id === (int) $data['account_id'] && $order->payload['order_key'] === $data['order_key'], 403, 'لا يمكن تعديل هذه الطلبية.');
                $this->authority->version($order, $data['version']);
            }
            $preview = $this->preview($actor, $data, true);
            abort_unless(hash_equals($preview['preview_hash'], $data['preview_hash']), 409, 'تغير السعر أو صلاحية البطاقات؛ أعد المعاينة.');
            if (collect($preview['lines'])->contains(fn (array $line): bool => $line['accepted'] === 0) || ($preview['rejected'] && ! $data['exclude_rejected'])) {
                throw ValidationException::withMessages(['exclude_rejected' => 'صحح الملف الخالي من البطاقات الصالحة، وأكد استبعاد البطاقات المرفوضة بعد مراجعتها.']);
            }
            $values = ['account_id' => $data['account_id'], 'provider_id' => $data['provider_id'], 'source_id' => $data['source_id'], 'creator_id' => $actor->id, 'city' => $data['city'], 'payload' => $preview['draft'], 'summary' => $this->summary($preview), 'preview_hash' => $preview['preview_hash'], 'excluded_confirmed' => $data['exclude_rejected'], 'quantity' => $preview['quantity'], 'rejected' => $preview['rejected'], 'status' => 'pending', 'reason' => null, 'version' => $order ? $order->version + 1 : 1];
            if ($order) {
                $order->update($values);
            } else {
                $order = StockOrder::create($values);
            }
            $this->audit->record($id ? 'import.resubmit' : 'import.submit', $request, $actor, $data['account_id'], ['order_id' => $order->id, 'quantity' => $preview['quantity'], 'rejected' => $preview['rejected']]);

            return ['order_id' => $order->id];
        });

        return $this->access->orders($actor)->findOrFail($result['order_id']);
    }

    public function review(User $actor, int $id, array $data, Request $request): StockOrder
    {
        $this->access->require($actor, 'import.approve', true);
        $this->access->orders($actor)->findOrFail($id);
        try {
            $result = $this->operations->execute($actor, 'import.review', $data + ['order_id' => $id], function () use ($actor, $id, $data, $request): array {
                $order = $this->access->orders($actor)->lockForUpdate()->findOrFail($id);
                $this->authority->version($order, $data['version']);
                abort_unless($order->status === 'pending', 409, 'الطلبية ليست بانتظار الاعتماد.');
                if ($data['decision'] === 'approve') {
                    $preview = $this->preview($actor, $order->payload, true);
                    abort_unless(hash_equals($order->preview_hash, $preview['preview_hash']), 409, 'تغير السعر أو صلاحية البطاقات؛ أعد الطلبية للتصحيح والمعاينة.');
                    foreach ($preview['lines'] as $index => $line) {
                        $this->approveLine($actor, $order, $line, $preview['draft']['lines'][$index]['rows'], $data['idempotency_key'], $request);
                    }
                }
                $order->update(['status' => ['approve' => 'approved', 'reject' => 'rejected', 'return' => 'returned'][$data['decision']], 'reason' => $data['reason'] ?? '', 'reviewer_id' => $actor->id, 'reviewed_at' => now(), 'version' => $order->version + 1]);
                $this->audit->record('import.approve', $request, $actor, $order->account_id, ['order_id' => $id, 'decision' => $data['decision'], 'reason' => $data['reason'] ?? '', 'quantity' => $order->quantity]);

                return ['order_id' => $id];
            });
        } catch (UniqueConstraintViolationException) {
            abort(409, 'تغير المخزون أثناء الاعتماد؛ أعد الطلبية للمعاينة.');
        }

        return $this->access->orders($actor)->findOrFail($result['order_id']);
    }

    private function approveLine(User $actor, StockOrder $order, array $line, array $rows, string $key, Request $request): void
    {
        $good = [];
        foreach ($line['checked'] as $index => $check) {
            if ($check['error'] === '') {
                $good[] = $rows[$index];
            }
        }
        $cost = Money::minor($line['cost'], 'cost');
        $expenses = Money::minor($line['expenses'], 'expenses', true);
        $price = Money::minor($line['load_price']);
        $amount = Money::minor($line['amount']);
        $source = OrderSource::findOrFail($order->source_id);
        $this->valuation->revalue($actor, $order->account_id, $line['product_id'], $price, 'IMPORT:'.$key.':'.$line['key'], $request);
        $batch = StockBatch::create(['order_id' => $order->id, 'line_key' => $line['key'], 'account_id' => $order->account_id, 'product_id' => $line['product_id'], 'source_id' => $order->source_id, 'currency' => $line['currency'], 'file_name' => $line['name'], 'city' => $order->city, 'supplier' => $source->name, 'quantity' => count($good), 'rejected' => $line['rejected'], 'cost_minor' => $cost, 'expenses_minor' => $expenses, 'cost_total_minor' => Money::minor($line['cost_total']), 'load_price_minor' => $price, 'amount_minor' => $amount, 'status' => 'Loaded', 'version' => 1]);
        $perCardExpenses = intdiv($expenses, count($good));
        $remainder = $expenses % count($good);
        $insertRows = [];
        foreach ($good as $index => $row) {
            $secret = array_intersect_key($row, array_flip(['pin', 'cvc', 'reference', 'extra_fields']));
            $insertRows[] = ['batch_id' => $batch->id, 'account_id' => $order->account_id, 'product_id' => $line['product_id'], 'serial' => $row['serial'], 'serial_hash' => self::fingerprint($row['serial']), 'pin_hash' => self::fingerprint($row['pin']), 'secret' => Crypt::encryptString(json_encode($secret, JSON_THROW_ON_ERROR)), 'expiry' => $row['expiry'], 'cost_minor' => $cost + $perCardExpenses + ($index < $remainder ? 1 : 0), 'credit_minor' => $price, 'credit_held' => false, 'status' => 'Available', 'version' => 1, 'created_at' => now(), 'updated_at' => now()];
            if (count($insertRows) === 500) {
                DB::table('stock_cards')->insert($insertRows);
                $insertRows = [];
            }
        }
        if ($insertRows) {
            DB::table('stock_cards')->insert($insertRows);
        }
        $systemId = Account::where('type', AccountType::System)->value('id');
        $external = $this->ledger->wallet($systemId, 'voucher', $line['currency'], 'external');
        $main = $this->ledger->wallet($order->account_id, 'voucher', $line['currency']);
        $tx = $this->ledger->pair($actor, 'stock-import', 'IMPORT:'.hash('sha256', $key.':'.$line['key']), ['order_id' => $order->id, 'batch_id' => $batch->id, 'quantity' => count($good), 'amount_minor' => $amount], $external, $main, $amount, 'طلبية مخزون '.$batch->id);
        $invoice = Invoice::create(['account_id' => $order->account_id, 'creator_id' => $actor->id, 'kind' => 'receivable', 'supplier' => $source->name, 'service' => 'voucher', 'currency' => $line['currency'], 'amount_minor' => $amount, 'reference' => 'طلبية '.$order->id.' / '.$line['name'], 'source_type' => 'stock_batch', 'source_id' => $batch->id]);
        Invoice::create(['account_id' => $systemId, 'creator_id' => $actor->id, 'kind' => 'payable', 'supplier' => $source->name, 'service' => 'voucher', 'currency' => $line['currency'], 'amount_minor' => $batch->cost_total_minor, 'reference' => 'شراء مخزون '.$batch->id, 'source_type' => 'stock_batch', 'source_id' => $batch->id]);
        $batch->update(['transaction_id' => $tx->id, 'invoice_id' => $invoice->id]);
    }
}

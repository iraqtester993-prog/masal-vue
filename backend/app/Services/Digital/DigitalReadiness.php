<?php

namespace App\Services\Digital;

use App\Enums\AccountType;
use App\Models\Digital\CatalogSnapshot;
use App\Models\Digital\DigitalConnection;
use App\Models\User;
use App\Support\RequestReadCache;
use Illuminate\Support\Collection;

class DigitalReadiness
{
    public function __construct(private ProviderGateway $gateway) {}

    public function prime(Collection $connections): void
    {
        if (! request()->isMethod('GET') || $connections->isEmpty()) {
            return;
        }
        $latest = CatalogSnapshot::query()->where(function ($query) use ($connections): void {
            foreach ($connections as $connection) {
                $query->orWhere(fn ($row) => $row->where('connection_id', $connection->id)->where('credential_hash', DigitalConfiguration::credentialHash($connection->credential)));
            }
        })->selectRaw('MAX(id)')->groupBy('connection_id');
        $snapshots = CatalogSnapshot::whereIn('id', $latest)->get()->keyBy('connection_id');
        $catalogs = request()->attributes->get('masal.digital_readiness_catalogs', []);
        foreach ($connections as $connection) {
            $catalogs[$this->catalogKey($connection)] = $snapshots->get($connection->id);
        }
        request()->attributes->set('masal.digital_readiness_catalogs', $catalogs);
    }

    public function catalog(DigitalConnection $connection): ?CatalogSnapshot
    {
        $key = $this->catalogKey($connection);
        $catalogs = request()->isMethod('GET') ? request()->attributes->get('masal.digital_readiness_catalogs', []) : [];
        if (array_key_exists($key, $catalogs)) {
            return $catalogs[$key];
        }

        return RequestReadCache::remember($key, fn (): ?CatalogSnapshot => CatalogSnapshot::where('connection_id', $connection->id)->where('credential_hash', DigitalConfiguration::credentialHash($connection->credential))->latest('id')->first());
    }

    private function catalogKey(DigitalConnection $connection): string
    {
        return 'DigitalReadiness.catalog:'.$connection->id.':'.DigitalConfiguration::credentialHash($connection->credential);
    }

    /** @return array{ready:bool, issues:array, summary:string} */
    public function connection(DigitalConnection $connection, User $actor, ?Collection $visibleOffers = null): array
    {
        $connection->loadMissing(['account', 'company', 'offers.product.provider']);
        $own = $actor->membership->account;
        $management = $own->type === AccountType::System || $connection->account_id === $own->id;
        $offers = $visibleOffers ?? ($management ? $connection->offers->where('listed', true) : app(DigitalAccess::class)->offers($connection, $own));
        $issues = [];
        $add = function (string $code, string $message, string $action, bool $blocking = true) use (&$issues): void {
            $issues[] = compact('code', 'message', 'action', 'blocking');
        };
        $status = $this->gateway->status($connection);
        if (! $connection->credential) {
            $add('credential_missing', 'توكن الشركة غير مضاف.', 'أضف مفتاح API من إعدادات الربط لدى الإدارة.');
        }
        foreach ($status['missing'] as $missing) {
            if ($missing !== 'لم يُضف توكن الشركة بعد') {
                $add('gateway_configuration', 'إعداد الربط ناقص: '.$missing.'.', 'راجع إعداد بوابة الشركة على الخادم.');
            }
        }
        if (! $connection->active) {
            $add('connection_paused', 'ربط الخدمة موقوف.', 'اطلب من الإدارة تفعيل الربط.');
        }
        if ($connection->provider_id !== null && $connection->company?->status !== 'active') {
            $add('provider_paused', 'شركة الخدمة موقوفة في النظام.', 'اطلب من الإدارة تفعيل الشركة.');
        }
        if (! $status['purchases_enabled']) {
            $add('purchases_disabled', 'تنفيذ البيع الخارجي غير مفعّل.', 'تفعّل الإدارة الشراء بعد اعتماد الربط.');
        }
        if ($management && $connection->credential) {
            $snapshot = $this->catalog($connection);
            if (! $snapshot) {
                $add('catalog_not_fetched', 'لم تُجلب فئات الشركة بالتوكن الحالي.', 'اضغط جلب فئات الشركة للتحقق من التوكن والفئات.');
            } elseif (! count($snapshot->catalog)) {
                $add('catalog_empty', 'الشركة أعادت قائمة فئات فارغة لهذا التوكن.', 'اطلب من المزود تفعيل المحافظات والفئات لحسابك، ثم أعد الجلب.');
            } else {
                $mapped = $offers->where('listed', true)->filter(fn ($offer): bool => $offer->remote_id !== null && $offer->manual_category_version === null)->map(fn ($offer): string => $offer->remote_id.':'.($offer->province_id ?? ''))->all();
                $unmapped = collect($snapshot->catalog)->filter(fn (array $row): bool => ! in_array($row['remote_id'].':'.($row['province_id'] ?? ''), $mapped, true))->count();
                if ($unmapped > 0) {
                    $add('categories_unlinked', $unmapped.' من فئات الشركة غير مرتبطة بفئات النظام.', $connection->provider === 'topup' ? 'أعد جلب فئات Topup وربطها، ثم راجع التخصيص.' : 'اربط فئات الشركة بالفئات المحلية وحدد سعر البيع.', $unmapped === count($snapshot->catalog));
                }
            }
            $unpriced = $offers->where('listed', true)->filter(fn ($offer): bool => $offer->retail_minor <= 0 || $offer->cost_minor === null || $offer->cost_minor <= 0)->count();
            if ($unpriced) {
                $add('category_price_missing', $unpriced.' من الفئات لا تحتوي سعر بيع أو سعر شركة صالحًا.', 'راجع أسعار الفئات واستعلم عن سعر الشركة.', $unpriced === $offers->count());
            }
            $paused = $offers->where('listed', true)->where('active', false)->count();
            if ($paused) {
                $add('categories_paused', $paused.' من الفئات موقوفة للبيع.', 'فعّل الفئات المطلوبة من إعداد الربط.', $paused === $offers->count());
            }
            $productsPaused = $offers->filter(fn ($offer): bool => $offer->product->status !== 'active' || $offer->product->provider?->status !== 'active')->count();
            if ($productsPaused) {
                $add('products_paused', $productsPaused.' من الفئات أو شركاتها المحلية موقوفة.', 'فعّل الفئة وشركتها المحلية من إعدادات النظام.', $productsPaused === $offers->count());
            }
            if ($offers->contains(fn ($offer): bool => $offer->package_type === 'premium') && ! count($connection->bein_provinces ?? [])) {
                $add('subscriber_provinces_missing', 'قائمة محافظات المشترك المطلوبة للباقات غير متاحة.', 'أعد جلب فئات الرابعة وقائمة المحافظات من المزود.');
            }
        }
        if (! $management && $offers->isEmpty()) {
            $add('categories_not_assigned', 'لا توجد فئات مفعّلة ومسموحة لهذا الحساب.', 'راجع الوكيل لتخصيص الفئات وتفعيلها وأسعارها.');
        }
        if ($connection->provider === 'topup' && $own->type !== AccountType::System) {
            $grant = app(TopupAccounting::class)->grant($own);
            if (! $grant || $grant->connection_id !== $connection->id) {
                $add('allocation_missing', 'لم تخصص حصة Topup لهذا الوكيل.', 'اطلب من الإدارة تخصيص رصيد Topup وفئاته للوكيل.');
            } elseif (! $grant->active) {
                $add('allocation_paused', 'حصة Topup للوكيل موقوفة.', 'اطلب من الإدارة تفعيل الحصة.');
            } elseif ($own->type === AccountType::Pos && app(TopupWallets::class)->balance($own)['available'] <= 0) {
                $add('funding_required', 'محفظة Topup للنقطة غير ممولة برصيد متاح.', 'أرسل طلب تمويل Topup إلى الوكيل.');
            }
        }

        return $this->result($issues);
    }

    public function unconnected(): array
    {
        return $this->result([['code' => 'connection_missing', 'message' => 'لم يُخصص ربط Topup لهذا الحساب.', 'action' => 'راجع الإدارة لربط التوكن وتخصيص الرصيد والفئات.', 'blocking' => true]]);
    }

    private function result(array $issues): array
    {
        $blocking = array_values(array_filter($issues, fn (array $issue): bool => $issue['blocking']));

        return ['ready' => ! count($blocking), 'issues' => $issues, 'summary' => $blocking[0]['message'] ?? ($issues[0]['message'] ?? 'إعداد الخدمة مكتمل؛ يعتمد تنفيذ البيع على استجابة الشركة والرصيد المتاح.')];
    }
}

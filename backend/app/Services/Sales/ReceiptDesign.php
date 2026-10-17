<?php

namespace App\Services\Sales;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\AccountAttachment;
use App\Models\CatalogProduct;
use App\Models\Sales\ReceiptLayout;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Backups\BackupCoordination;
use App\Services\CatalogAccess;
use App\Services\Finance\FinanceOperations;
use App\Services\ManagementAuthority;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ReceiptDesign
{
    public const BLOCKS = ['company', 'header', 'category', 'image', 'codes', 'amount', 'agent', 'footer'];

    public const RECHARGE_TEMPLATE_PATTERN = '/\A\*(?:[0-9]{1,6}\*)+\{pin\}(?:\*[0-9]{1,6})*#\z/D';

    private const QR_FIELDS = ['qr_enabled', 'recharge_template'];

    public function __construct(private SalesAccess $access, private FinanceOperations $operations, private ManagementAuthority $authority, private AuditLogger $audit, private CatalogAccess $catalog) {}

    public function resolve(CatalogProduct $product, ?Account $main): array
    {
        $system = ReceiptLayout::where('scope_key', 'system:'.$product->id)->first();
        $personal = $main ? ReceiptLayout::where('scope_key', 'account:'.$main->id.':'.$product->id)->first() : null;
        $removed = $personal?->layout['agent_image_removed'] ?? false;
        $agentImage = $main && ! $removed && (($personal?->layout['agent_image_path'] ?? null) || AccountAttachment::where('account_id', $main->id)->where('kind', 'agent_image')->exists());
        $qr = $this->rechargeSettings($system?->layout ?? [], $personal?->layout ?? []);

        return [
            'color' => $system?->layout['color'] ?? '#172b4d',
            'width' => $product->receipt_width,
            'language' => $product->receipt_language,
            'header' => $system?->layout['header'] ?? $product->receipt_header ?? '',
            'footer' => $system?->layout['footer'] ?? $product->receipt_footer ?? '',
            'display_order' => $system?->layout['display_order'] ?? self::BLOCKS,
            'company_name' => $product->provider->name,
            'company_image' => $product->provider->image_path ? '/api/v1/sales/products/'.$product->id.'/images/provider' : null,
            'category_image' => $product->image_path ? '/api/v1/sales/products/'.$product->id.'/images/product' : null,
            'agent_name' => $main?->name,
            'agent_image' => $agentImage ? '/api/v1/sales/products/'.$product->id.'/images/agent?account_id='.$main->id : null,
            'agent_image_removed' => $removed,
            'agent_text' => $personal?->layout['agent_text'] ?? '',
            'agent_color' => $personal?->layout['agent_color'] ?? $main?->color ?? '#172b4d',
            'version' => $system?->version ?? 0,
            'personal_version' => $personal?->version ?? 0,
        ] + $qr;
    }

    public static function validRechargeTemplate(mixed $template): bool
    {
        return is_string($template) && strlen($template) <= 80 && preg_match(self::RECHARGE_TEMPLATE_PATTERN, $template) === 1;
    }

    private function rechargeSettings(array $system, array $personal = []): array
    {
        $template = $personal['recharge_template'] ?? $system['recharge_template'] ?? '';
        $valid = self::validRechargeTemplate($template);
        $systemTemplate = $system['recharge_template'] ?? '';
        $systemValid = self::validRechargeTemplate($systemTemplate);

        return ['qr_enabled' => (bool) ($personal['qr_enabled'] ?? $system['qr_enabled'] ?? false) && $valid,
            'recharge_template' => $valid ? $template : '',
            'qr_override' => isset($personal['qr_enabled']) || isset($personal['recharge_template']),
            'qr_system_enabled' => (bool) ($system['qr_enabled'] ?? false) && $systemValid,
            'qr_system_template' => $systemValid ? $systemTemplate : ''];
    }

    private function validateRechargeSettings(array $layout, array $system = []): void
    {
        $template = $layout['recharge_template'] ?? $system['recharge_template'] ?? '';
        if (($template !== '' && ! self::validRechargeTemplate($template)) || (($layout['qr_enabled'] ?? $system['qr_enabled'] ?? false) && ! self::validRechargeTemplate($template))) {
            throw ValidationException::withMessages(['layout.recharge_template' => 'أدخل صيغة شحن صحيحة تبدأ بـ * وتنتهي بـ # وتتضمن {pin} مرة واحدة بدل الرقم السري.']);
        }
    }

    public function save(User $actor, int $productId, array $data, Request $request): array
    {
        $accountId = $data['account_id'] ?? null;
        if ($accountId === null) {
            $this->access->require($actor, 'branding.receipt', true);
            $this->authority->require($actor, 'products.edit');
        } else {
            $this->access->require($actor, 'branding.edit');
        }
        $product = $this->catalog->products($actor)->with('provider')->findOrFail($productId);
        $main = $accountId === null ? null : $this->access->accounts($actor)->where('type', AccountType::MainAgent)->findOrFail($accountId);
        if ($main) {
            abort_unless($this->catalog->forAccount($main->id)->whereKey($productId)->exists(), 404);
        }

        $image = $request->file('image');
        unset($data['image']);
        abort_if($image && ! $main, 422, 'صورة الوكيل تتطلب اختيار حساب الوكيل الرئيسي.');
        $fingerprint = $data + ['product_id' => $productId, 'image_hash' => $image ? hash_file('sha256', $image->getRealPath()) : null];
        $createdPath = null;
        try {
            return $this->operations->execute($actor, 'sales.receipt-layout', $fingerprint, function () use ($actor, $productId, $data, $request, $main, $image, &$createdPath): array {
                $key = $main ? 'account:'.$main->id.':'.$productId : 'system:'.$productId;
                $record = ReceiptLayout::where('scope_key', $key)->lockForUpdate()->first();
                abort_unless(($record?->version ?? 0) === (int) $data['version'], 409, 'تغير تصميم البطاقة؛ أعد فتحه.');
                if ($main) {
                    $layout = array_intersect_key($data['layout'], array_flip(['agent_text', 'agent_color', 'agent_image_removed', ...self::QR_FIELDS]));
                    abort_unless(count($layout) === count($data['layout']), 422, 'تخصيص الوكيل يقتصر على هويته وإعدادات رمز الشحن.');
                    $oldPath = $record?->layout['agent_image_path'] ?? null;
                    $layout = array_replace($record?->layout ?? [], $layout);
                    foreach (self::QR_FIELDS as $field) {
                        if (array_key_exists($field, $data['layout']) && $data['layout'][$field] === null) {
                            unset($layout[$field]);
                        }
                    }
                    if (isset($layout['qr_enabled'])) {
                        $layout['qr_enabled'] = (bool) $layout['qr_enabled'];
                    }
                    $system = ReceiptLayout::where('scope_key', 'system:'.$productId)->sharedLock()->first();
                    $this->validateRechargeSettings($layout, $system?->layout ?? []);
                    if ($image) {
                        $mime = $image->getMimeType();
                        abort_unless(in_array($mime, ['image/png', 'image/jpeg'], true), 422);
                        $createdPath = $image->storeAs('receipt-agent-images', Str::uuid().($mime === 'image/png' ? '.png' : '.jpg'), 'local');
                        $layout['agent_image_path'] = $createdPath;
                        $layout['agent_image_mime'] = $mime;
                        $layout['agent_image_removed'] = false;
                    } elseif ($layout['agent_image_removed'] ?? false) {
                        unset($layout['agent_image_path'], $layout['agent_image_mime']);
                    }
                    if ($oldPath && $oldPath !== ($layout['agent_image_path'] ?? null)) {
                        DB::afterCommit(fn () => app(BackupCoordination::class)->deleteAsset($oldPath));
                    }
                } else {
                    $layout = array_replace(array_intersect_key($record?->layout ?? [], array_flip(self::QR_FIELDS)), $data['layout']);
                    abort_if(array_diff(array_keys($layout), ['header', 'footer', 'color', 'display_order', ...self::QR_FIELDS]), 422, 'تخصيص الوكيل يتطلب اختيار حسابه.');
                    if (array_key_exists('qr_enabled', $layout)) {
                        if ($layout['qr_enabled'] === null) {
                            throw ValidationException::withMessages(['layout.qr_enabled' => 'حدد إظهار رمز الشحن أو إخفاءه في التصميم العام.']);
                        }
                        $layout['qr_enabled'] = (bool) $layout['qr_enabled'];
                    }
                    if (array_key_exists('recharge_template', $layout)) {
                        $layout['recharge_template'] ??= '';
                    }
                    $this->validateRechargeSettings($layout);
                    $order = $layout['display_order'] ?? [];
                    if (count($order) !== count(self::BLOCKS) || count(array_unique($order)) !== count(self::BLOCKS) || array_diff($order, self::BLOCKS)) {
                        throw ValidationException::withMessages(['layout.display_order' => 'ترتيب البطاقة يجب أن يشمل كل الأقسام وبيانات الرموز.']);
                    }
                }
                $values = ['product_id' => $productId, 'account_id' => $main?->id, 'scope_key' => $key, 'layout' => $layout, 'version' => ($record?->version ?? 0) + 1];
                if ($record) {
                    $record->update($values);
                } else {
                    $record = ReceiptLayout::create($values);
                }
                $publicLayout = array_diff_key($layout, array_flip(['agent_image_path', 'agent_image_mime']));
                $this->audit->record($main ? 'branding.edit' : 'branding.receipt', $request, $actor, $main?->id ?? $actor->membership->account_id, ['product_id' => $productId, 'layout' => $publicLayout, 'has_image' => isset($layout['agent_image_path']), 'version' => $record->version]);

                return ['id' => $record->id, 'version' => $record->version, 'layout' => $publicLayout];
            });
        } catch (\Throwable $error) {
            if ($createdPath) {
                Storage::disk('local')->delete($createdPath);
            }
            throw $error;
        }
    }
}

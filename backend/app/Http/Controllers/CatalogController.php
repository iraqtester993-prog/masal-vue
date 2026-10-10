<?php

namespace App\Http\Controllers;

use App\Enums\AccountType;
use App\Http\Requests\CatalogRequest;
use App\Http\Resources\CatalogProductResource;
use App\Http\Resources\CatalogProviderResource;
use App\Models\CatalogProduct;
use App\Models\CatalogProvider;
use App\Services\AccountScope;
use App\Services\AuditLogger;
use App\Services\CatalogAccess;
use App\Services\ManagementAuthority;
use App\Services\Operations\MutationGuard;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class CatalogController extends Controller
{
    public function __construct(private CatalogAccess $access, private ManagementAuthority $authority, private AuditLogger $audit) {}

    public function preferences(Request $request): JsonResponse
    {
        $this->access->require($request->user(), 'products.view');

        return response()->json(['data' => ['hidden_columns' => json_decode(DB::table('catalog_preferences')->where('user_id', $request->user()->id)->value('hidden_columns') ?? '[]', true)]]);
    }

    public function savePreferences(CatalogRequest $request): JsonResponse
    {
        $this->access->require($request->user(), 'products.view');
        DB::transaction(function () use ($request): void {
            app(MutationGuard::class)->lock($request->user(), [], 'products.view');
            DB::table('catalog_preferences')->upsert([['user_id' => $request->user()->id, 'hidden_columns' => json_encode($request->validated('hidden_columns'), JSON_THROW_ON_ERROR)]], ['user_id'], ['hidden_columns']);
        });

        return $this->preferences($request);
    }

    private function filtered(Builder $query, CatalogRequest $request, bool $providers = false): Builder
    {
        $input = $request->validated();
        if (! empty($input['query'])) {
            $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $input['query']).'%';
            $columns = $providers ? ['name', 'id', 'supplier', 'connection'] : ['name', 'id', 'kind', 'currency', 'face_value', 'minimum_price', 'daily_quantity', 'daily_amount', 'import_codes', 'receipt_header', 'receipt_footer', 'allowed_cities'];
            $query->where(function ($search) use ($term, $columns): void {
                foreach ($columns as $column) {
                    $search->orWhereRaw($column." LIKE ? ESCAPE '!'", [$term]);
                }
            });
        }
        foreach ($providers ? ['supplier', 'connection'] : ['kind', 'provider'] as $field) {
            if (! empty($input[$field])) {
                $query->where($field === 'provider' ? 'provider_id' : $field, $input[$field]);
            }
        }
        if (! empty($input['state'])) {
            $query->where('status', $input['state'] === 'active' ? 'active' : 'disabled');
        }
        if (! empty($input['from'])) {
            $query->where('created_at', '>=', CarbonImmutable::parse($input['from'], 'Asia/Baghdad')->startOfDay()->utc());
        }
        if (! empty($input['to'])) {
            $query->where('created_at', '<', CarbonImmutable::parse($input['to'], 'Asia/Baghdad')->startOfDay()->addDay()->utc());
        }

        return $providers ? $query->orderBy('id') : $query->orderBy('display_order')->orderBy('id');
    }

    public function products(CatalogRequest $request): AnonymousResourceCollection
    {
        $this->access->require($request->user(), 'products.view');

        return CatalogProductResource::collection($this->filtered($this->access->products($request->user())->with('provider'), $request)->paginate($request->integer('per_page', 25)));
    }

    public function providers(CatalogRequest $request): AnonymousResourceCollection
    {
        $this->access->require($request->user(), 'providers.view', true);

        return CatalogProviderResource::collection($this->filtered(CatalogProvider::query(), $request, true)->paginate($request->integer('per_page', 25)));
    }

    public function options(Request $request): JsonResponse
    {
        $actor = $request->user();
        $permission = $actor->membership->account->type === AccountType::System && in_array('providers.view', $actor->membership->permissions(), true) ? 'providers.view' : 'products.view';
        $this->access->require($actor, $permission);
        $providers = CatalogProvider::query();
        if ($permission !== 'providers.view' || $actor->membership->kind === 'employee') {
            $providers->whereIn('id', $this->access->products($actor)->select('provider_id'));
        }

        return response()->json(['data' => ['providers' => (clone $providers)->get(['id', 'name', 'status', 'version']), 'suppliers' => (clone $providers)->distinct()->pluck('supplier'), 'connections' => (clone $providers)->distinct()->pluck('connection'), 'kinds' => $this->access->products($actor)->distinct()->pluck('kind'), 'cities' => DB::table('account_cities')->where('active', true)->pluck('name'), 'catalog_version' => (int) DB::table('catalog_meta')->where('id', 1)->value('version')]]);
    }

    private function change(CatalogRequest $request, string $class, string $permission, ?int $id): Model
    {
        $actor = $request->user();
        $providers = $class === CatalogProvider::class;
        $module = $providers ? 'providers' : 'products';
        $this->access->require($actor, $module.'.view', true);
        $this->access->require($actor, $permission, true);
        $newPath = null;
        $oldPath = null;
        try {
            $record = DB::transaction(function () use ($request, $class, $id, $providers, $module, $permission, $actor, &$newPath, &$oldPath): Model {
                app(MutationGuard::class)->lock($request->user());
                DB::table('catalog_meta')->where('id', 1)->lockForUpdate()->first();
                $record = $id ? $class::query()->lockForUpdate()->findOrFail($id) : new $class;
                $data = $request->validated();
                if ($id) {
                    $this->authority->version($record, $data['version']);
                }
                if (! $providers) {
                    $defaults = ['pin' => 'required', 'expiry' => 'required', 'serial' => 'required', 'cvc' => 'unused', 'reference' => 'unused'];
                    foreach (['field_policy', 'extra_fields'] as $key) {
                        if (($data[$key] ?? []) != ($id ? $record->{$key} : ($key === 'field_policy' ? $defaults : []))) {
                            $this->access->require($actor, 'products.fields', true);
                        }
                    }
                    if (($data['allowed_cities'] ?? []) != ($record->allowed_cities ?? [])) {
                        $this->access->require($actor, 'products.availability', true);
                    }
                    $data['daily_quantity'] = $data['daily_limit_type'] === 'quantity' ? $data['daily_quantity'] : null;
                    $data['daily_amount'] = $data['daily_limit_type'] === 'amount' ? $data['daily_amount'] : null;
                    $data['receipt_language'] = $data['receipt_language'] ?? '';
                    $data['receipt_header'] = $data['receipt_header'] ?? '';
                    $data['receipt_footer'] = $data['receipt_footer'] ?? '';
                    $codes = array_map(static fn ($value): string => mb_strtoupper(trim($value)), $data['import_codes']);
                    if (count($codes) !== count(array_unique($codes)) || in_array('', $codes, true)) {
                        throw ValidationException::withMessages(['import_codes' => 'معرّف استيراد فارغ أو مكرر.']);
                    }
                    $data['import_codes'] = $codes;
                }
                if ($request->hasFile('image') || $request->boolean('remove_image')) {
                    $this->access->require($actor, $module.'.images', true);
                    $oldPath = $record->image_path;
                    if ($request->hasFile('image')) {
                        $newPath = $request->file('image')->store('catalog/'.$module, 'local');
                        if (! $newPath) {
                            throw new \RuntimeException('Cannot store catalog image.');
                        }
                        $record->image_path = $newPath;
                        $record->image_mime = $request->file('image')->getMimeType();
                    } else {
                        $record->image_path = null;
                        $record->image_mime = null;
                    }
                }
                $record->fill(array_diff_key($data, array_flip(['image', 'remove_image', 'reason', 'version'])));
                $record->version = $id ? $record->version + 1 : 1;
                $record->save();
                DB::table('catalog_meta')->where('id', 1)->increment('version');
                $this->audit->record($permission, $request, $actor, null, ['record_id' => $record->id, 'version' => $record->version, 'reason' => $data['reason'] ?? null]);

                return $record;
            });
        } catch (\Throwable $error) {
            if ($newPath) {
                $this->removeFile($newPath);
            }
            if ($error instanceof UniqueConstraintViolationException) {
                throw ValidationException::withMessages(['name' => 'الاسم مستخدم مسبقًا.']);
            }
            throw $error;
        }
        if ($oldPath && $oldPath !== $newPath) {
            $this->removeFile($oldPath);
        }

        return $record;
    }

    private function removeFile(string $path): void
    {
        try {
            if (! Storage::disk('local')->delete($path)) {
                throw new \RuntimeException;
            }
        } catch (\Throwable) {
            Log::warning('Private catalog file cleanup failed.');
        }
    }

    public function storeProvider(CatalogRequest $request): CatalogProviderResource
    {
        return new CatalogProviderResource($this->change($request, CatalogProvider::class, 'providers.create', null));
    }

    public function updateProvider(CatalogRequest $request, int $id): CatalogProviderResource
    {
        return new CatalogProviderResource($this->change($request, CatalogProvider::class, 'providers.edit', $id));
    }

    public function storeProduct(CatalogRequest $request): CatalogProductResource
    {
        return new CatalogProductResource($this->change($request, CatalogProduct::class, 'products.create', null)->load('provider'));
    }

    public function updateProduct(CatalogRequest $request, int $id): CatalogProductResource
    {
        return new CatalogProductResource($this->change($request, CatalogProduct::class, 'products.edit', $id)->load('provider'));
    }

    private function status(CatalogRequest $request, string $class, int $id, string $permission): Model
    {
        $this->access->require($request->user(), $permission, true);

        return DB::transaction(function () use ($request, $class, $id, $permission): Model {
            app(MutationGuard::class)->lock($request->user());
            DB::table('catalog_meta')->where('id', 1)->lockForUpdate()->first();
            $record = $class::query()->lockForUpdate()->findOrFail($id);
            $data = $request->validated();
            $this->authority->version($record, $data['version']);
            $record->status = $data['status'];
            $record->version++;
            $record->save();
            DB::table('catalog_meta')->where('id', 1)->increment('version');
            $this->audit->record($permission, $request, $request->user(), null, ['record_id' => $id, 'status' => $record->status, 'version' => $record->version, 'reason' => $data['reason'] ?? null]);

            return $record;
        });
    }

    public function productStatus(CatalogRequest $request, int $id): CatalogProductResource
    {
        return new CatalogProductResource($this->status($request, CatalogProduct::class, $id, 'products.toggle')->load('provider'));
    }

    public function providerStatus(CatalogRequest $request, int $id): CatalogProviderResource
    {
        return new CatalogProviderResource($this->status($request, CatalogProvider::class, $id, 'providers.toggle'));
    }

    public function moveProduct(CatalogRequest $request, int $id): JsonResponse
    {
        $this->access->require($request->user(), 'products.order', true);
        DB::transaction(function () use ($request, $id): void {
            app(MutationGuard::class)->lock($request->user());
            DB::table('catalog_meta')->where('id', 1)->lockForUpdate()->first();
            $rows = CatalogProduct::orderBy('display_order')->orderBy('id')->lockForUpdate()->get();
            $index = $rows->search(static fn ($row): bool => $row->id === $id);
            abort_if($index === false, 404);
            $this->authority->version($rows[$index], $request->integer('version'));
            $next = $index + ($request->input('direction') === 'up' ? -1 : 1);
            abort_unless(isset($rows[$next]), 422, 'لا يمكن التحريك خارج القائمة.');
            $items = $rows->all();
            [$items[$index],$items[$next]] = [$items[$next], $items[$index]];
            foreach ($items as $position => $product) {
                if ($product->display_order !== $position + 1 || in_array($product->id, [$rows[$index]->id, $rows[$next]->id], true)) {
                    $product->display_order = $position + 1;
                    $product->version++;
                    $product->save();
                }
            }
            DB::table('catalog_meta')->where('id', 1)->increment('version');
            $this->audit->record('products.order', $request, $request->user(), null, ['record_id' => $id, 'direction' => $request->input('direction')]);
        });

        return response()->json(['data' => ['catalog_version' => (int) DB::table('catalog_meta')->where('id', 1)->value('version')]]);
    }

    public function productImage(Request $request, int $id): BinaryFileResponse
    {
        $this->access->require($request->user(), 'products.view');

        return $this->image($this->access->products($request->user())->findOrFail($id));
    }

    public function providerImage(Request $request, int $id): BinaryFileResponse
    {
        $actor = $request->user();
        $permissions = $actor->membership->permissions();
        $isSystemReader = $actor->membership->account->type === AccountType::System && in_array('providers.view', $permissions, true) && app(AccountScope::class)->query($actor)->whereKey($actor->membership->account_id)->exists();
        if (! $isSystemReader) {
            $this->access->require($actor, 'products.view');
            abort_unless($this->access->products($actor)->where('provider_id', $id)->exists(), 404);
        }

        return $this->image(CatalogProvider::findOrFail($id));
    }

    private function image(Model $record): BinaryFileResponse
    {
        abort_unless($record->image_path && Storage::disk('local')->exists($record->image_path), 404);

        return response()->file(Storage::disk('local')->path($record->image_path), ['Content-Type' => $record->image_mime, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }

    public function categories(CatalogRequest $request, int $id): JsonResponse
    {
        $actor = $request->user();
        $target = $this->authority->account($actor, $id, 'account.view');
        $canManage = $actor->membership->kind === 'owner' && $actor->membership->account_id !== $id && $target->type !== AccountType::System && in_array('agents.categories', $actor->membership->permissions(), true);
        $selected = $this->access->forAccount($id)->pluck('id')->all();
        $rows = ($canManage ? $this->access->options($actor, $target) : $this->access->forAccount($id))->with('provider')->orderBy('display_order')->orderBy('id')->get();

        return response()->json(['data' => ['account_id' => $id, 'account_version' => $target->version, 'catalog_version' => (int) DB::table('catalog_meta')->where('id', 1)->value('version'), 'can_manage' => $canManage, 'selected_ids' => $selected, 'products' => $rows->map(static fn ($p): array => ['id' => $p->id, 'name' => $p->name, 'provider_id' => $p->provider_id, 'provider_name' => $p->provider->name, 'face_value' => $p->face_value, 'currency' => $p->currency, 'status' => $p->status])]]);
    }

    public function saveCategories(CatalogRequest $request, int $id): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor->membership->kind === 'owner', 403);
        DB::transaction(function () use ($request, $id, $actor): void {
            app(MutationGuard::class)->lock($request->user());
            $meta = DB::table('catalog_meta')->where('id', 1)->lockForUpdate()->first();
            $data = $request->validated();
            abort_unless((int) $meta->version === (int) $data['catalog_version'], 409, 'تغيرت الفئات؛ أعد فتح الاختيار.');
            $target = $this->authority->account($actor, $id, 'agents.categories', false, true);
            $this->authority->version($target, $data['version']);
            $options = $this->access->options($actor, $target)->pluck('id')->all();
            if (array_diff($data['product_ids'], $options)) {
                throw ValidationException::withMessages(['product_ids' => 'لا يمكنك منح فئة غير متاحة لك أو ممنوعة من الأعلى.']);
            }
            DB::table('catalog_account_rules')->whereIn('id', $this->access->replaceableRules($actor, $id))->delete();
            $ruleId = DB::table('catalog_account_rules')->insertGetId(['target_account_id' => $id, 'authority_account_id' => $actor->membership->account_id]);
            foreach ($data['product_ids'] as $productId) {
                DB::table('catalog_rule_products')->insert(['rule_id' => $ruleId, 'product_id' => $productId]);
            }
            $target->version++;
            $target->save();
            DB::table('catalog_meta')->where('id', 1)->increment('version');
            $this->audit->record('agents.categories', $request, $actor, $id, ['product_ids' => $data['product_ids'], 'version' => $target->version, 'reason' => $data['reason'] ?? null]);
        });

        return $this->categories($request, $id);
    }
}

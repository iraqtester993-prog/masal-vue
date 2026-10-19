<?php

namespace App\Http\Controllers\Digital;

use App\Http\Controllers\Controller;
use App\Models\CatalogProduct;
use App\Models\Digital\CatalogSnapshot;
use App\Models\Digital\DigitalOffer;
use App\Models\Digital\TopupCategory;
use App\Services\AccountPrivacy;
use App\Services\AuditLogger;
use App\Services\Backups\BackupCoordination;
use App\Services\Digital\DigitalAccess;
use App\Services\Digital\DigitalConfiguration;
use App\Services\Finance\Money;
use App\Services\Operations\MutationGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class TopupCategoryController extends Controller
{
    public function __construct(private DigitalAccess $access) {}

    public function index(Request $request): JsonResponse
    {
        $actor = $request->user();
        $this->access->system($actor, 'topup.view');
        $input = $request->validate(['page' => 'sometimes|integer|min:1', 'q' => 'nullable|string|max:160', 'connection_id' => 'sometimes|integer|min:1']);
        $connections = $this->access->connections($actor)->where('provider', 'topup')->with('account')->get();
        if (isset($input['connection_id'])) {
            abort_unless($connections->contains('id', $input['connection_id']), 404);
            $connections = $connections->where('id', $input['connection_id']);
        }
        $snapshots = $connections->flatMap(fn ($connection) => CatalogSnapshot::where('connection_id', $connection->id)->where('credential_hash', DigitalConfiguration::credentialHash($connection->credential))->pluck('id'))->all();
        $query = TopupCategory::whereIn('connection_id', $connections->pluck('id'))->whereIn('catalog_snapshot_id', $snapshots)->where('active', true)->where('cost_minor', '>', 0);
        if (! empty($input['q'])) {
            $term = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $input['q']);
            $query->whereRaw("name LIKE ? ESCAPE '!'", ['%'.$term.'%']);
        }
        $rows = $query->orderBy('id')->paginate(25);
        $byId = $connections->keyBy('id');
        $images = DigitalOffer::whereIn('topup_category_id', $rows->getCollection()->pluck('id'))->with('product:id,image_path,version')->get()->keyBy('topup_category_id');

        return response()->json(['data' => $rows->getCollection()->map(fn ($row): array => ['id' => $row->id, 'name' => $row->name, 'type' => $row->type, 'remote_id' => $row->remote_id, 'price' => Money::decimal($row->cost_minor), 'active' => $row->active, 'version' => $row->version, 'company' => 'آسياسيل', 'connection_id' => $row->connection_id, 'main_account_name' => app(AccountPrivacy::class)->accountName($byId->get($row->connection_id)->account), 'image_version' => $images->get($row->id)?->product?->version, 'image_url' => $images->get($row->id)?->product?->image_path ? '/api/v1/topup/offers/'.$images->get($row->id)->id.'/image?v='.$images->get($row->id)->product->version : null])->all(), 'meta' => ['current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'total' => $rows->total()]], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function image(Request $request, int $id): JsonResponse
    {
        $this->access->system($request->user(), 'topup.categories');
        abort_if(array_diff(array_keys($request->all()), ['version', 'image', 'remove_image']), 422, 'يسمح بتعديل صورة الفئة فقط.');
        $data = $request->validate(['version' => 'required|integer|min:1', 'image' => 'required_without:remove_image|file|mimes:png,jpg,jpeg|max:684|dimensions:max_width=4096,max_height=4096', 'remove_image' => 'sometimes|boolean']);
        abort_unless($request->hasFile('image') || $request->boolean('remove_image'), 422, 'اختر صورة للفئة.');
        abort_if($request->hasFile('image') && $request->boolean('remove_image'), 422);
        $category = TopupCategory::whereIn('connection_id', $this->access->connections($request->user())->where('provider', 'topup')->select('id'))->findOrFail($id);
        $newPath = null;
        $oldPath = null;
        try {
            DB::transaction(function () use ($request, $data, $category, &$newPath, &$oldPath): void {
                app(MutationGuard::class)->lock($request->user(), action: 'topup.categories');
                $this->access->system($request->user(), 'topup.categories');
                $offer = DigitalOffer::where('topup_category_id', $category->id)->where('connection_id', $category->connection_id)->firstOrFail();
                $product = CatalogProduct::whereKey($offer->product_id)->lockForUpdate()->firstOrFail();
                abort_unless($product->version === (int) $data['version'], 409, 'تغيرت الصورة؛ أعد تحميل الفئات.');
                $oldPath = $product->image_path;
                $newPath = $request->hasFile('image') ? $request->file('image')->store('catalog/products', 'local') : null;
                if ($request->hasFile('image') && ! $newPath) {
                    throw new \RuntimeException('تعذر حفظ صورة الفئة.');
                }
                $product->update(['image_path' => $newPath, 'image_mime' => $newPath ? $request->file('image')->getMimeType() : null, 'version' => $product->version + 1]);
                app(AuditLogger::class)->record('products.images', $request, $request->user(), null, ['record_id' => $product->id, 'topup_category_id' => $category->id]);
            });
        } catch (\Throwable $error) {
            if ($newPath) {
                Storage::disk('local')->delete($newPath);
            }
            throw $error;
        }
        if ($oldPath && $oldPath !== $newPath) {
            app(BackupCoordination::class)->deleteAsset($oldPath);
        }

        return response()->json(['data' => ['saved' => true]]);
    }
}

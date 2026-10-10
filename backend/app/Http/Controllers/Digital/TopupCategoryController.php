<?php

namespace App\Http\Controllers\Digital;

use App\Http\Controllers\Controller;
use App\Models\Digital\CatalogSnapshot;
use App\Models\Digital\TopupCategory;
use App\Services\Digital\DigitalAccess;
use App\Services\Digital\DigitalConfiguration;
use App\Services\Finance\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TopupCategoryController extends Controller
{
    public function __construct(private DigitalAccess $access) {}

    public function index(Request $request): JsonResponse
    {
        $actor = $request->user();
        $this->access->owner($actor);
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

        return response()->json(['data' => $rows->getCollection()->map(fn ($row): array => ['id' => $row->id, 'name' => $row->name, 'type' => $row->type, 'remote_id' => $row->remote_id, 'price' => Money::decimal($row->cost_minor), 'active' => $row->active, 'version' => $row->version, 'company' => 'آسياسيل', 'connection_id' => $row->connection_id, 'main_account_name' => $byId->get($row->connection_id)->account->name])->all(), 'meta' => ['current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'total' => $rows->total()]], 200, ['Cache-Control' => 'private, no-store']);
    }
}

<?php

namespace App\Http\Controllers;

use App\Enums\AccountType;
use App\Http\Requests\ReferenceRequest;
use App\Http\Resources\ReferenceResource;
use App\Models\Account;
use App\Models\CatalogProvider;
use App\Models\OperatingGovernorate;
use App\Models\PosType;
use App\Services\AccountScope;
use App\Services\AuditLogger;
use App\Services\ManagementAuthority;
use App\Services\Operations\MutationGuard;
use App\Services\ReferenceAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReferenceController extends Controller
{
    private function module(string $kind): string
    {
        return match ($kind) {
            'governorates' => 'governorates', 'sources' => 'sources', 'pos-types' => 'posTypes', 'representatives' => 'representatives', default => abort(404),
        };
    }

    private function query(Request $request, string $kind, ReferenceAccess $access): Builder
    {
        return match ($kind) {
            'governorates' => OperatingGovernorate::query(), 'pos-types' => PosType::query(),
            'sources' => $access->sources($request->user())->with(['provider', 'network']),
            'representatives' => $access->representatives($request->user())->with(['agent', 'photos']), default => abort(404),
        };
    }

    private function filtered(ReferenceRequest $request, string $kind, ReferenceAccess $access): Builder
    {
        $query = $this->query($request, $kind, $access);
        $filters = $request->validated();
        if (! empty($filters['status'])) {
            $query->where($kind === 'governorates' ? 'active' : 'status', $kind === 'governorates' ? $filters['status'] === 'active' : $filters['status']);
        }
        foreach ($kind === 'sources' ? ['provider_id', 'network_account_id'] : ($kind === 'representatives' ? ['agent_account_id'] : []) as $field) {
            if (! empty($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }
        if ($kind === 'representatives' && ! empty($filters['city'])) {
            $query->whereHas('agent', fn ($agent) => $agent->where('city', $filters['city']));
        }
        if (! empty($filters['query'])) {
            $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $filters['query']).'%';
            $query->where(function ($search) use ($term, $kind): void {
                $search->whereRaw("name LIKE ? ESCAPE '!'", [$term]);
                if ($kind === 'representatives') {
                    foreach (['phone', 'address'] as $column) {
                        $search->orWhereRaw($column." LIKE ? ESCAPE '!'", [$term]);
                    }
                    $search->orWhereHas('agent', fn ($agent) => $agent->whereRaw("name LIKE ? ESCAPE '!'", [$term]));
                } elseif ($kind === 'sources') {
                    $search->orWhereHas('provider', fn ($provider) => $provider->whereRaw("name LIKE ? ESCAPE '!'", [$term]));
                }
            });
        }
        if ($kind === 'governorates') {
            $query->select('account_cities.*');
            foreach (['main_agents_count' => ['main_agent'], 'sub_agents_count' => ['sub_agent', 'sub_branch'], 'pos_count' => ['pos']] as $alias => $types) {
                $query->addSelect([$alias => Account::selectRaw('count(*)')->whereColumn('city', 'account_cities.name')->whereIn('type', $types)]);
            }
        }

        return $query;
    }

    public function index(ReferenceRequest $request, string $kind, ReferenceAccess $access): JsonResponse
    {
        $access->require($request->user(), $this->module($kind).'.view', in_array($kind, ['governorates', 'pos-types'], true));
        $rows = $this->filtered($request, $kind, $access)->orderBy('id')->paginate($request->integer('per_page', 25));

        return response()->json(['data' => ReferenceResource::collection($rows)->resolve($request), 'meta' => ['total' => $rows->total(), 'page' => $rows->currentPage(), 'per_page' => $rows->perPage(), 'last_page' => $rows->lastPage()]]);
    }

    public function store(ReferenceRequest $request, string $kind, ReferenceAccess $access, AuditLogger $audit): JsonResponse
    {
        abort_if($kind === 'governorates', 404);
        $access->require($request->user(), $this->module($kind).'.create', $kind === 'pos-types');
        try {
            $record = DB::transaction(function () use ($request, $kind, $access, $audit): Model {
                app(MutationGuard::class)->lock($request->user());
                $attributes = $this->attributes($request, $kind, $access);
                $record = $this->query($request, $kind, $access)->getModel()->newQuery()->create($attributes);
                $audit->record($this->module($kind).'.create', $request, $request->user(), $attributes['agent_account_id'] ?? $attributes['network_account_id'] ?? null, ['id' => $record->id, 'version' => $record->version]);

                return $record;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => 'الاسم مسجل مسبقًا ضمن نفس النطاق.']);
        }

        return response()->json(['data' => (new ReferenceResource($this->query($request, $kind, $access)->findOrFail($record->id)))->resolve($request)], 201);
    }

    public function update(ReferenceRequest $request, string $kind, int $id, ReferenceAccess $access, ManagementAuthority $authority, AuditLogger $audit): ReferenceResource
    {
        abort_if($kind === 'governorates', 404);
        $access->require($request->user(), $this->module($kind).'.edit', $kind === 'pos-types');
        try {
            DB::transaction(function () use ($request, $kind, $id, $access, $authority, $audit): void {
                app(MutationGuard::class)->lock($request->user());
                $record = $this->query($request, $kind, $access)->lockForUpdate()->findOrFail($id);
                $authority->version($record, $request->integer('version'));
                $attributes = $this->attributes($request, $kind, $access, $record);
                $record->fill($attributes);
                $record->version++;
                $record->save();
                $audit->record($this->module($kind).'.edit', $request, $request->user(), $attributes['agent_account_id'] ?? $attributes['network_account_id'] ?? null, ['id' => $id, 'version' => $record->version, 'reason' => $request->input('reason')]);
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => 'الاسم مسجل مسبقًا ضمن نفس النطاق.']);
        }

        return new ReferenceResource($this->query($request, $kind, $access)->findOrFail($id));
    }

    private function attributes(ReferenceRequest $request, string $kind, ReferenceAccess $access, ?Model $old = null): array
    {
        $data = $request->validated();
        if ($kind === 'sources') {
            $networkId = $data['network_account_id'] ?? $old?->network_account_id;
            if ($request->user()->membership->account->type === AccountType::System && ! $networkId) {
                throw ValidationException::withMessages(['network_account_id' => 'اختر الوكيل الرئيسي للمصدر.']);
            }
            $network = $access->writableNetwork($request->user(), $networkId);
            if ($old && (int) $old->network_account_id !== $network->id) {
                throw ValidationException::withMessages(['network_account_id' => 'لا يمكن نقل المصدر بين شبكات الوكلاء.']);
            }
            $provider = CatalogProvider::lockForUpdate()->findOrFail($data['provider_id']);
            if ($provider->status !== 'active' && (! $old || (int) $old->provider_id !== $provider->id)) {
                throw ValidationException::withMessages(['provider_id' => 'اختر شركة مفعلة.']);
            }

            return ['name' => $data['name'], 'normalized_name' => mb_strtolower($data['name']), 'network_account_id' => $network->id, 'provider_id' => $provider->id];
        }
        if ($kind === 'representatives') {
            $agent = $access->agents($request->user())->lockForUpdate()->findOrFail($data['agent_account_id']);
            if (! $old || (int) $old->agent_account_id !== $agent->id) {
                if (! $agent->isOperational()) {
                    throw ValidationException::withMessages(['agent_account_id' => 'اختر وكيلاً مفعلاً.']);
                }
                if ($old) {
                    $parents = Account::whereIn('id', DB::table('pos_representatives')->where('representative_id', $old->id)->select('account_id'))->pluck('parent_id');
                    $validParents = DB::table('account_closure')->where('descendant_id', $agent->id)->pluck('ancestor_id');
                    if ($parents->diff($validParents)->isNotEmpty()) {
                        throw ValidationException::withMessages(['agent_account_id' => 'المندوب مرتبط بنقطة بيع خارج نطاق الوكيل الجديد.']);
                    }
                }
            }

            return ['agent_account_id' => $agent->id, 'name' => $data['name'] ?? '', 'phone' => $data['phone'] ?? '', 'address' => $data['address'] ?? ''];
        }

        $status = $data['status'] ?? $old?->status ?? 'active';
        if ($status !== ($old?->status ?? 'active')) {
            $access->require($request->user(), 'posTypes.toggle', true);
        }

        return ['name' => $data['name'], 'normalized_name' => mb_strtolower($data['name']), 'status' => $status];
    }

    public function status(ReferenceRequest $request, string $kind, int $id, ReferenceAccess $access, ManagementAuthority $authority, AuditLogger $audit): ReferenceResource
    {
        $access->require($request->user(), $this->module($kind).'.toggle', in_array($kind, ['governorates', 'pos-types'], true));
        DB::transaction(function () use ($request, $kind, $id, $access, $authority, $audit): void {
            app(MutationGuard::class)->lock($request->user());
            $record = $this->query($request, $kind, $access)->lockForUpdate()->findOrFail($id);
            if ($kind === 'sources') {
                $access->writableNetwork($request->user(), $record->network_account_id);
            }
            $authority->version($record, $request->integer('version'));
            $record->{$kind === 'governorates' ? 'active' : 'status'} = $kind === 'governorates' ? $request->input('status') === 'active' : $request->input('status');
            $record->version++;
            $record->save();
            $audit->record($this->module($kind).'.toggle', $request, $request->user(), $record->agent_account_id ?? $record->network_account_id, ['id' => $id, 'status' => $request->input('status'), 'version' => $record->version, 'reason' => $request->input('reason')]);
        });

        return new ReferenceResource($this->query($request, $kind, $access)->findOrFail($id));
    }

    public function options(ReferenceRequest $request, ReferenceAccess $access, ManagementAuthority $authority): JsonResponse
    {
        $actor = $request->user();
        $authority->require($actor, 'account.view');
        abort_if($actor->membership->account->type === AccountType::Pos, 403);
        $agents = $access->agents($actor)->orderBy('id')->limit(10001)->get(['id', 'name', 'type', 'status', 'parent_id']);
        $networks = $access->networks($actor)->orderBy('id')->limit(10001)->get(['id', 'name', 'status']);
        $providers = CatalogProvider::where('status', 'active')->orderBy('id')->limit(10001)->get(['id', 'name', 'status']);
        abort_if(max($agents->count(), $networks->count(), $providers->count()) > 10000, 422, 'عدد الخيارات كبير؛ استخدم قائمة الحسابات المفلترة.');

        $writableNetworks = $access->agents($actor)->where('type', AccountType::MainAgent)->pluck('id')->all();
        $representatives = [];
        if ($request->filled('agent_account_id')) {
            $parent = $access->agents($actor)->findOrFail($request->integer('agent_account_id'));
            $rows = $access->representatives($actor)->whereIn('agent_account_id', DB::table('account_closure')->where('ancestor_id', $parent->id)->select('descendant_id'))->where('status', 'active')->with('agent')->orderBy('id')->limit(10001)->get();
            abort_if($rows->count() > 10000, 422, 'عدد المندوبين كبير؛ استخدم قائمة المندوبين المفلترة.');
            $representatives = ReferenceResource::collection($rows)->resolve($request);
        }

        return response()->json(['data' => ['agents' => $agents, 'networks' => $networks, 'writable_network_ids' => $writableNetworks, 'providers' => $providers, 'pos_types' => PosType::where('status', 'active')->orderBy('id')->get(['id', 'name', 'status', 'version']), 'available_representatives' => $representatives]]);
    }

    public function profile(ReferenceRequest $request, int $id, AccountScope $scope, ManagementAuthority $authority, ReferenceAccess $access): JsonResponse
    {
        $authority->require($request->user(), 'account.view');
        $account = $scope->query($request->user())->findOrFail($id);
        abort_unless($account->type === AccountType::Pos, 404);
        $available = $access->representatives($request->user())->whereIn('agent_account_id', DB::table('account_closure')->where('ancestor_id', $account->parent_id)->select('descendant_id'))->where('status', 'active')->with('agent')->orderBy('id')->limit(10001)->get();
        abort_if($available->count() > 10000, 422, 'عدد المندوبين كبير؛ استخدم قائمة المندوبين المفلترة.');
        $typeId = DB::table('pos_reference_profiles')->where('account_id', $id)->value('pos_type_id');
        $selected = DB::table('pos_representatives')->where('account_id', $id)->pluck('representative_id')->map(fn ($value): int => (int) $value)->all();
        $selectedRows = $access->representatives($request->user())->whereIn('id', $selected)->with('agent')->orderBy('id')->get();
        $types = PosType::where('status', 'active')->orWhere('id', $typeId)->orderBy('id')->get(['id', 'name', 'status', 'version']);

        return response()->json(['data' => ['account_id' => $id, 'version' => (int) $account->version, 'pos_type_id' => $typeId ? (int) $typeId : null, 'representative_ids' => $selected, 'selected_representatives' => ReferenceResource::collection($selectedRows)->resolve($request), 'pos_types' => $types, 'available_representatives' => ReferenceResource::collection($available)->resolve($request)]]);
    }

    public function saveProfile(ReferenceRequest $request, int $id, AccountScope $scope, ManagementAuthority $authority, AuditLogger $audit, ReferenceAccess $access): JsonResponse
    {
        DB::transaction(function () use ($request, $id, $authority, $audit, $access): void {
            app(MutationGuard::class)->lock($request->user());
            $actor = $request->user();
            $account = $authority->account($actor, $id, 'account.update', false, true);
            abort_unless($account->type === AccountType::Pos, 404);
            $authority->version($account, $request->integer('version'));
            $data = $request->validated();
            $oldType = DB::table('pos_reference_profiles')->where('account_id', $id)->value('pos_type_id');
            $oldReps = DB::table('pos_representatives')->where('account_id', $id)->pluck('representative_id')->map(fn ($value): int => (int) $value)->all();
            $newType = $data['pos_type_id'];
            if ($newType !== ($oldType ? (int) $oldType : null)) {
                $authority->require($actor, 'pos.type');
                if ($newType !== null && ! PosType::whereKey($newType)->where('status', 'active')->lockForUpdate()->exists()) {
                    throw ValidationException::withMessages(['pos_type_id' => 'اختر نوع نقطة بيع مفعلًا.']);
                }
            }
            $newReps = $data['representative_ids'];
            if (array_diff($newReps, $oldReps) || array_diff($oldReps, $newReps)) {
                $authority->require($actor, 'pos.representatives');
                $valid = $access->representatives($actor)->whereIn('id', $newReps)->whereIn('agent_account_id', DB::table('account_closure')->where('ancestor_id', $account->parent_id)->select('descendant_id'))->where(function ($enabled) use ($oldReps): void {
                    $enabled->where('status', 'active')->orWhereIn('id', $oldReps);
                })->lockForUpdate()->pluck('id')->all();
                if (array_diff($newReps, $valid)) {
                    throw ValidationException::withMessages(['representative_ids' => 'اختر مندوبي نقطة البيع ضمن نطاق الوكيل وصلاحياتك.']);
                }
            }
            DB::table('pos_reference_profiles')->updateOrInsert(['account_id' => $id], ['pos_type_id' => $newType]);
            DB::table('pos_representatives')->where('account_id', $id)->delete();
            foreach ($newReps as $repId) {
                DB::table('pos_representatives')->insert(['account_id' => $id, 'representative_id' => $repId]);
            }
            $account->version++;
            $account->save();
            $audit->record('pos.reference-profile', $request, $actor, $id, ['pos_type_id' => $newType, 'representative_ids' => $newReps, 'version' => $account->version, 'reason' => $data['reason'] ?? null]);
        });

        return $this->profile($request, $id, $scope, $authority, $access);
    }

    public function export(ReferenceRequest $request, string $kind, ReferenceAccess $access, AuditLogger $audit): JsonResponse
    {
        abort_unless(in_array($kind, ['pos-types', 'representatives'], true), 404);
        $access->require($request->user(), $this->module($kind).'.export', $kind === 'pos-types');
        $query = $this->filtered($request, $kind, $access);
        if ($kind === 'representatives') {
            $query->without('photos');
        }
        abort_if((clone $query)->count() > 10000, 422, 'حدد الفلاتر لتصدير 10000 سجل أو أقل.');
        $rows = $query->orderBy('id')->get();
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, $kind === 'pos-types' ? ['المعرف', 'النوع', 'الحالة'] : ['المعرف', 'اسم المندوب', 'رقم الهاتف', 'العنوان', 'الوكيل', 'الحالة'], ',', '"', '');
        foreach ($rows as $row) {
            $values = $kind === 'pos-types' ? [$row->id, $row->name] : [$row->id, $row->name, $row->phone, $row->address, $row->agent->name];
            $values[] = $row->status === 'active' ? 'مفعل' : 'موقوف';
            $values = array_map(static function ($value): string {
                $text = (string) $value;

                return preg_match('/^[\s]*[=+@\-]/u', $text) ? "'".$text : $text;
            }, $values);
            fputcsv($stream, $values, ',', '"', '');
        }
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);
        $audit->record($this->module($kind).'.export', $request, $request->user(), null, ['count' => $rows->count()]);

        return response()->json(['data' => ['filename' => 'masal-'.$kind.'.csv', 'csv' => $csv, 'count' => $rows->count()]], 200, ['Cache-Control' => 'private, no-store']);
    }
}

<?php

namespace App\Http\Controllers\Maps;

use App\Enums\AccountType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Maps\MapRequest;
use App\Models\Maps\UserPresence;
use App\Services\AccountScope;
use App\Services\AuditLogger;
use App\Services\ManagementAuthority;
use App\Services\Maps\MapAccess;
use App\Services\Operations\MutationGuard;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class MapController extends Controller
{
    public function __construct(private MapAccess $access, private AccountScope $scope, private ManagementAuthority $authority) {}

    public function index(MapRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $users = $this->access->users($request->user(), $filters);
        $total = (clone $users)->count();
        $rows = $this->access->resources($users->limit(5000)->get(), $request->user());
        $rows = array_values(array_filter($rows, fn (array $row): bool => match ($filters['status'] ?? '') {
            'online' => $row['online'], 'offline' => ! $row['online'], 'without_location' => $row['location'] === null, default => true,
        }));
        $branches = $this->scope->query($request->user())->whereNotIn('type', [AccountType::System->value, AccountType::Pos->value])->orderBy('id')->limit(1000)->get(['id', 'name', 'type', 'parent_id']);

        return response()->json(['data' => $rows, 'meta' => ['total_candidates' => $total, 'shown' => count($rows), 'truncated' => $total > 5000, 'limit' => 5000, 'timezone' => 'Asia/Baghdad', 'generated_at' => now()->toISOString()], 'branches' => $branches]);
    }

    public function own(MapRequest $request): JsonResponse
    {
        $actor = $request->user();
        $presence = UserPresence::where('user_id', $actor->id)->where('session_hash', $this->access->sessionHash($request))->where('session_version', $actor->session_version)->first();

        return response()->json(['data' => ['location_required' => $actor->membership->kind === 'owner' && $actor->membership->account->type !== AccountType::System, 'location_ready' => $this->access->locationReady($actor, $request), 'consent_version' => (int) ($presence?->consent_version ?? 1), 'location' => $presence?->latitude !== null && $presence?->longitude !== null ? ['lat' => $presence->latitude, 'lng' => $presence->longitude] : null, 'accuracy' => $presence?->accuracy, 'location_at' => $presence?->location_at?->toISOString()]]);
    }

    public function heartbeat(MapRequest $request): Response
    {
        $this->access->heartbeat($request->user(), $request, $request->validated());

        return response()->noContent();
    }

    public function locate(MapRequest $request): JsonResponse
    {
        $data = $request->validated();
        $presence = DB::transaction(function () use ($request, $data) {
            $actor = $request->user();
            app(MutationGuard::class)->lock($actor, [], 'maps.location');
            $record = $this->access->heartbeat($actor, $request)->fresh();
            $record = UserPresence::whereKey($record->id)->lockForUpdate()->firstOrFail();
            abort_unless($record->consent_version === (int) $data['consent_version'], 409, 'تغيرت حالة مشاركة الموقع؛ أعد التفعيل.');
            abort_if($record->location_at && CarbonImmutable::parse($data['recorded_at'])->lt($record->location_at), 409, 'وصل تحديث موقع أقدم من الموقع المحفوظ.');
            $record->fill(['latitude' => $data['latitude'], 'longitude' => $data['longitude'], 'accuracy' => $data['accuracy'], 'location_at' => $data['recorded_at'], 'sharing' => true])->save();

            return $record;
        });

        return response()->json(['data' => ['location_ready' => true, 'location' => ['lat' => $presence->latitude, 'lng' => $presence->longitude], 'location_at' => $presence->location_at->toISOString()]]);
    }

    public function disconnect(MapRequest $request): Response
    {
        $this->access->disconnect($request);

        return response()->noContent();
    }

    public function saveLocation(MapRequest $request, int $id, AuditLogger $audit): JsonResponse
    {
        $data = $request->validated();
        $location = DB::transaction(function () use ($request, $id, $data, $audit) {
            $account = $this->authority->account($request->user(), $id, 'account.update', false, true);
            abort_unless($account->isOperational(), 403);
            if ($account->type === AccountType::Pos) {
                $this->authority->require($request->user(), 'pos.location');
            }
            $current = DB::table('account_locations')->where('account_id', $id)->lockForUpdate()->first();
            abort_unless((int) ($current?->version ?? 0) === (int) $data['version'], 409, 'تغيرت البيانات؛ أعد فتحها قبل الحفظ.');
            $values = ['latitude' => $data['latitude'], 'longitude' => $data['longitude'], 'version' => ($current?->version ?? 0) + 1, 'actor_id' => $request->user()->id, 'updated_at' => now()];
            DB::table('account_locations')->updateOrInsert(['account_id' => $id], $values + ['created_at' => $current?->created_at ?? now()]);
            $audit->record('account.location', $request, $request->user(), $id, ['version' => $values['version']]);

            return ['account_id' => $id, 'latitude' => (float) $data['latitude'], 'longitude' => (float) $data['longitude'], 'version' => $values['version']];
        });

        return response()->json(['data' => $location]);
    }
}

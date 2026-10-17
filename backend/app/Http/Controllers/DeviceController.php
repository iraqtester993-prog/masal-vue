<?php

namespace App\Http\Controllers;

use App\Enums\AccountType;
use App\Models\Account;
use App\Services\AccountScope;
use App\Services\AuditLogger;
use App\Services\ManagementAuthority;
use App\Services\Operations\MutationGuard;
use App\Services\Sales\DeviceProof;
use App\Services\Sales\RegisteredDevices;
use App\Services\SessionRevoker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DeviceController extends Controller
{
    public function challenge(Request $request, DeviceProof $proof): JsonResponse
    {
        $data = $request->validate(['installation_id' => ['required', 'uuid'], 'proof_public_key' => ['required', 'string', 'max:512'], 'proof_key_kind' => ['required', 'in:android-keystore,webcrypto']]);
        $account = $request->user()->membership->account;
        abort_unless($account->type === AccountType::Pos, 403);

        return response()->json(['data' => $proof->challenge($request->user(), $account, $data, $request)]);
    }

    public function heartbeat(Request $request, RegisteredDevices $devices): JsonResponse
    {
        $data = $request->validate(array_replace(RegisteredDevices::rules(), ['installation_id' => ['required', 'uuid']]));
        abort_unless(! empty($data['installation_id']), 422);

        return response()->json(['data' => $devices->heartbeat($request->user(), $request->user()->membership->account, $data, $request)]);
    }

    public function index(Request $request, int $id, AccountScope $scope): JsonResponse
    {
        $account = $scope->query($request->user())->where('type', 'pos')->findOrFail($id);
        $rows = DB::table('pos_registered_devices')->where('account_id', $account->id)->whereNull('archived_at')->orderByDesc('last_seen_at')->orderByDesc('id')->paginate(10);
        $binding = DB::table('pos_login_bindings')->where('account_id', $account->id)->first();

        return response()->json(['data' => collect($rows->items())->map(fn (object $row): array => RegisteredDevices::dto($row) + ['login_device' => (int) ($binding?->registered_device_id ?? 0) === (int) $row->id]),
            'meta' => ['current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'total' => $rows->total(), 'login_binding' => ['device_id' => $binding?->registered_device_id, 'version' => (int) ($binding?->version ?? 1)]]]);
    }

    public function loginAccess(Request $request, int $id, int $deviceId, AccountScope $scope, ManagementAuthority $authority, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1'], 'binding_version' => ['required', 'integer', 'min:1']]);
        abort_if($request->user()->membership->account->type === AccountType::Pos, 403);
        $authority->require($request->user(), 'pos.device');
        $result = DB::transaction(function () use ($request, $id, $deviceId, $data, $scope, $authority, $audit): array {
            app(MutationGuard::class)->lock($request->user(), [$id], 'pos.device');
            $authority->require($request->user(), 'pos.device');
            $account = $scope->query($request->user())->where('type', 'pos')->findOrFail($id);
            DB::table('pos_login_bindings')->insertOrIgnore(['account_id' => $account->id, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
            $binding = DB::table('pos_login_bindings')->where('account_id', $account->id)->lockForUpdate()->first();
            $device = DB::table('pos_registered_devices')->where('account_id', $account->id)->where('id', $deviceId)->lockForUpdate()->first();
            abort_unless($device, 404);
            abort_unless((int) $device->version === (int) $data['version'] && (int) $binding->version === (int) $data['binding_version'], 409, 'تغير جهاز الدخول أو حالته؛ أعد فتح التفاصيل.');
            abort_if(empty($device->proof_public_key) || ! empty($device->archived_at), 422, 'سجل مفتاح الجهاز الجديد أولًا قبل نقل الدخول إليه.');
            if ((int) $binding->registered_device_id === (int) $device->id) {
                return ['device_id' => (int) $device->id, 'version' => (int) $binding->version];
            }
            abort_if(DB::table('digital_provider_attempts')->join('digital_orders', 'digital_orders.id', '=', 'digital_provider_attempts.order_id')
                ->where('digital_orders.account_id', $account->id)->whereNotNull('digital_provider_attempts.active_order_id')->sharedLock()->first(['digital_provider_attempts.id']), 409, 'توجد عملية لدى الشركة قيد التنفيذ؛ انتظر نتيجتها قبل نقل الجهاز.');
            DB::table('pos_login_bindings')->where('account_id', $account->id)->update(['registered_device_id' => $device->id, 'version' => $binding->version + 1, 'updated_by' => $request->user()->id, 'updated_at' => now()]);
            app(SessionRevoker::class)->revoke(DB::table('account_memberships')->where('account_id', $account->id)->pluck('user_id')->all());
            $audit->record('pos.device.login_transferred', $request, $request->user(), $account->id, ['previous_device_id' => $binding->registered_device_id, 'device_id' => $device->id]);

            return ['device_id' => (int) $device->id, 'version' => (int) $binding->version + 1];
        });

        return response()->json(['data' => $result]);
    }

    public function review(Request $request, int $id, int $deviceId, AccountScope $scope, ManagementAuthority $authority, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1'], 'status' => ['required', 'in:approved,revoked,archived']]);
        abort_if($request->user()->membership->account->type === AccountType::Pos, 403);
        $authority->require($request->user(), 'pos.device');
        $row = DB::transaction(function () use ($request, $id, $deviceId, $data, $scope, $authority, $audit): object {
            app(MutationGuard::class)->lock($request->user(), [$id], 'pos.device');
            $authority->require($request->user(), 'pos.device');
            $account = $scope->query($request->user())->where('type', 'pos')->findOrFail($id);
            $row = DB::table('pos_registered_devices')->where('account_id', $account->id)->where('id', $deviceId)->lockForUpdate()->first();
            abort_unless($row, 404);
            abort_unless((int) $row->version === (int) $data['version'], 409, 'تغيرت حالة الجهاز؛ أعد فتح التفاصيل.');
            abort_if($data['status'] === 'approved' && empty($row->proof_public_key), 422, 'حدّث تطبيق النقطة وسجل مفتاح الجهاز أولًا قبل اعتماده.');
            abort_if($data['status'] === 'archived' && $row->status === 'approved', 422, 'ألغ اعتماد الجهاز قبل أرشفته.');
            abort_if($data['status'] === 'archived' && DB::table('pos_login_bindings')->where('account_id', $account->id)->where('registered_device_id', $row->id)->exists(), 422, 'انقل دخول النقطة إلى جهاز آخر قبل أرشفة جهاز الدخول الحالي.');
            DB::table('pos_registered_devices')->where('id', $row->id)->update(['archived_at' => $data['status'] === 'archived' ? now() : null, 'status' => $data['status'], 'version' => $row->version + 1, 'reviewed_by' => $request->user()->id, 'reviewed_at' => now(), 'updated_at' => now()]);
            Account::whereKey($account->id)->update(['device_binding_enabled' => true]);
            $audit->record('pos.device.'.$data['status'], $request, $request->user(), $account->id, ['device_id' => $row->id]);

            return DB::table('pos_registered_devices')->where('id', $row->id)->first();
        });

        return response()->json(['data' => RegisteredDevices::dto($row)]);
    }
}

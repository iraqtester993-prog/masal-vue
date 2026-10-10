<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Http\Requests\Company\CompanyConversationRequest;
use App\Http\Requests\Company\CompanyInquiryRequest;
use App\Models\Company\CompanyInquiry;
use App\Models\Company\CompanyProfile;
use App\Services\Company\CompanyAssets;
use App\Services\Company\CompanyContent;
use App\Services\Company\CompanyConversations;
use App\Services\Operations\MutationGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CompanyPublicController extends Controller
{
    public function __construct(private CompanyContent $content, private CompanyAssets $assets) {}

    public function profile(): JsonResponse
    {
        $record = CompanyProfile::findOrFail(1);
        abort_unless($record->published !== null, 404, 'لم يُنشر موقع الشركة بعد.');

        return response()->json(['data' => ['profile' => $this->content->transport($record->published, true), 'published_at' => $record->published_at?->toISOString()]]);
    }

    public function asset(string $id): StreamedResponse
    {
        return $this->assets->response($id);
    }

    public function track(CompanyConversationRequest $request, CompanyConversations $conversations): JsonResponse
    {
        return response()->json(['data' => $conversations->detail($conversations->find($request->validated('token')), $request, true)]);
    }

    public function followup(CompanyConversationRequest $request, CompanyConversations $conversations): JsonResponse
    {
        $inquiry = $conversations->reply(null, $request->validated(), $request);

        return response()->json(['data' => $conversations->detail($inquiry, $request, true)]);
    }

    public function inquiry(CompanyInquiryRequest $request, CompanyConversations $conversations): JsonResponse
    {
        $data = $request->validated();
        $payload = array_map(fn (string $value): string => trim($value), array_intersect_key($data, array_flip(['name', 'contact', 'message'])));
        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $record = DB::transaction(function () use ($data, $payload, $hash): CompanyInquiry {
            app(MutationGuard::class)->lockRuntime();
            $profile = CompanyProfile::lockForUpdate()->findOrFail(1);
            abort_unless($profile->published !== null && ($profile->published['visibility']['care'] ?? false), 409, 'خدمة العملاء غير متاحة حاليًا.');
            $old = CompanyInquiry::where('idempotency_key', $data['idempotency_key'])->lockForUpdate()->first();
            if ($old !== null) {
                abort_unless(hash_equals($old->payload_hash, $hash), 409, 'معرف الرسالة مستخدم لبيانات مختلفة.');

                return $old;
            }

            return CompanyInquiry::create($payload + ['idempotency_key' => $data['idempotency_key'], 'payload_hash' => $hash, 'status' => 'new', 'version' => 1]);
        });

        return response()->json(['data' => ['registered' => true, 'id' => $record->id, 'tracking_token' => $conversations->token($record)]], 201);
    }
}

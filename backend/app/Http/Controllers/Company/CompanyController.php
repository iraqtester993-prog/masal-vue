<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Http\Requests\Company\CompanyAssetRequest;
use App\Http\Requests\Company\CompanyConversationRequest;
use App\Http\Requests\Company\CompanyInboxRequest;
use App\Http\Requests\Company\CompanyProfileRequest;
use App\Http\Resources\Company\CompanyInquiryResource;
use App\Models\Company\CompanyInquiry;
use App\Models\Company\CompanyProfile;
use App\Services\AuditLogger;
use App\Services\Company\CompanyAccess;
use App\Services\Company\CompanyAssets;
use App\Services\Company\CompanyContent;
use App\Services\Company\CompanyConversations;
use App\Services\Operations\MutationGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CompanyController extends Controller
{
    public function __construct(private CompanyAccess $access, private CompanyContent $content, private CompanyAssets $assets, private AuditLogger $audit) {}

    public function profile(Request $request): JsonResponse
    {
        $this->access->require($request->user());

        return response()->json(['data' => $this->content->dto(CompanyProfile::findOrFail(1))]);
    }

    public function save(CompanyProfileRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->content->save($request->user(), $request->validated(), $request)]);
    }

    public function upload(CompanyAssetRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->assets->upload($request->user(), $request->file('file'))], 201);
    }

    public function asset(Request $request, string $id): StreamedResponse
    {
        return $this->assets->response($id, $request->user());
    }

    public function inquiries(CompanyInboxRequest $request): JsonResponse
    {
        if ($request->is('api/v1/support/site-inquiries*')) {
            $this->access->requireInbox($request->user());
        } else {
            $this->access->require($request->user());
        }
        $q = CompanyInquiry::query();
        if ($request->filled('status')) {
            $q->where('status', $request->input('status'));
        }
        if ($term = trim($request->input('q', ''))) {
            $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term).'%';
            $q->where(function ($query) use ($term): void {
                foreach (['name', 'contact', 'message'] as $column) {
                    $query->orWhereRaw($column." LIKE ? ESCAPE '!'", [$term]);
                }
            });
        }
        $page = $q->orderByDesc('id')->paginate($request->integer('per_page', 20));

        return response()->json(['data' => CompanyInquiryResource::collection($page->getCollection())->resolve($request), 'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'new_count' => CompanyInquiry::where('status', 'new')->count()]]);
    }

    public function inquiryDetail(Request $request, int $id, CompanyConversations $conversations): JsonResponse
    {
        $this->access->requireInbox($request->user());

        return response()->json(['data' => $conversations->detail(CompanyInquiry::findOrFail($id), $request)]);
    }

    public function reply(CompanyConversationRequest $request, int $id, CompanyConversations $conversations): JsonResponse
    {
        $inquiry = $conversations->reply($request->user(), $request->validated(), $request, $id);

        return response()->json(['data' => $conversations->detail($inquiry, $request)]);
    }

    public function review(CompanyInboxRequest $request, int $id): JsonResponse
    {
        if ($request->is('api/v1/support/site-inquiries*')) {
            $this->access->requireInbox($request->user(), true);
        } else {
            $this->access->require($request->user());
        }
        $record = DB::transaction(function () use ($request, $id): CompanyInquiry {
            app(MutationGuard::class)->lock($request->user(), [], $request->is('api/v1/support/*') ? 'support.reply' : 'company.edit');
            $record = CompanyInquiry::lockForUpdate()->findOrFail($id);
            abort_unless($record->version === $request->integer('version'), 409, 'تغير الطلب؛ أعد فتحه قبل المتابعة.');
            $record->update(['status' => $request->input('status'), 'version' => $record->version + 1, 'reviewer_id' => $request->user()->id, 'reviewed_at' => now()]);
            $this->audit->record('company.inquiry.review', $request, $request->user(), $request->user()->membership->account_id, ['inquiry_id' => $record->id, 'status' => $record->status, 'version' => $record->version]);

            return $record;
        });

        return response()->json(['data' => (new CompanyInquiryResource($record))->resolve($request)]);
    }
}

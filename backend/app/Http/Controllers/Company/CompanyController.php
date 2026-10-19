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

    public function appConfig(Request $request): JsonResponse
    {
        $data = $this->configurationData(CompanyProfile::findOrFail(1));
        if ($request->boolean('manage')) {
            $data = $this->managementData($request, $data);
        }

        return response()->json(['data' => $data]);
    }

    private function configurationData(CompanyProfile $record): array
    {

        return ['version' => $record->content['app_config_version'] ?? 1, 'config' => $record->content['app_config'] ?? ['name' => 'دنانير', 'about' => '', 'terms' => '', 'privacy' => '', 'printers' => [
            ['id' => 'goojprt', 'name' => 'GOOJPRT / MTP-2', 'profile' => 'GOOJPRT', 'match' => 'GOOJPRT|JingPu|MTP-2|MTP-II'],
            ['id' => 'yhd-8006', 'name' => 'YHD-8006', 'profile' => 'YHD-8006', 'match' => 'YHD-8006|YHD8006'],
            ['id' => 'feasycom', 'name' => 'Feasycom', 'profile' => 'Feasycom', 'match' => 'Feasycom'],
            ['id' => 'z51', 'name' => 'Z51', 'profile' => 'Z51', 'match' => 'Z51'],
            ['id' => 'sunmi', 'name' => 'SUNMI', 'profile' => 'sunmi', 'match' => 'SUNMI'],
            ['id' => 'rovoo', 'name' => 'ROVOO', 'profile' => 'rovoo', 'match' => 'ROVOO|MTHD-M1'],
            ['id' => 'z91', 'name' => 'Z91', 'profile' => 'zcs', 'match' => 'Z91'],
        ]]];
    }

    private function managementData(Request $request, array $data): array
    {
        $permissions = $request->user()->membership->permissions();
        $this->access->configuration($request->user(), in_array('app.settings', $permissions, true) ? 'app.settings' : 'printers.manage');
        $fields = array_merge(in_array('app.settings', $permissions, true) ? ['name', 'about', 'terms', 'privacy'] : [], in_array('printers.manage', $permissions, true) ? ['printers'] : []);
        $data['config'] = array_intersect_key($data['config'], array_flip($fields));

        return $data;
    }

    public function saveAppConfig(Request $request): JsonResponse
    {
        $allowed = array_keys($this->managementData($request, $this->configurationData(CompanyProfile::findOrFail(1)))['config']);
        abort_if(is_array($request->input('config')) && array_diff(array_keys($request->input('config')), $allowed), 403);
        $data = $request->validate([
            'version' => ['required', 'integer', 'min:1'],
            'config' => ['required', 'array:name,about,terms,privacy,printers', 'min:1'],
            'config.name' => ['sometimes', 'required', 'string', 'max:120'],
            'config.about' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'config.terms' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'config.privacy' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'config.printers' => ['sometimes', 'array', 'max:40'],
            'config.printers.*' => ['array:id,name,profile,match,enabled'],
            'config.printers.*.enabled' => ['sometimes', 'boolean'],
            'config.printers.*.id' => ['required', 'string', 'regex:/^[a-zA-Z0-9_-]{1,60}$/', 'distinct'],
            'config.printers.*.name' => ['required', 'string', 'max:100'],
            'config.printers.*.profile' => ['required', 'in:GOOJPRT,YHD-8006,Feasycom,Z51,sunmi,rovoo,zcs'],
            'config.printers.*.match' => ['required', 'string', 'min:3', 'max:100'],
        ]);
        DB::transaction(function () use ($request, $data): void {
            app(MutationGuard::class)->lock($request->user());
            $record = CompanyProfile::lockForUpdate()->findOrFail(1);
            abort_unless((int) ($record->content['app_config_version'] ?? 1) === (int) $data['version'], 409, 'تغيرت البيانات؛ أعد تحميل الإعدادات.');
            $current = $this->configurationData($record)['config'];
            foreach (['name', 'about', 'terms', 'privacy'] as $field) {
                if (array_key_exists($field, $data['config'])) {
                    $this->access->configuration($request->user(), 'app.settings');
                }
            }
            if (array_key_exists('printers', $data['config'])) {
                $this->access->configuration($request->user(), 'printers.manage');
            }
            $content = $record->content;
            $content['app_config'] = array_replace($current, $data['config']);
            $content['app_config_version'] = (int) $data['version'] + 1;
            $record->update(['content' => $content, 'editor_id' => $request->user()->id]);
            $this->audit->record('company.app-config', $request, $request->user(), $request->user()->membership->account_id, ['printer_count' => count($content['app_config']['printers'])]);
        });

        return response()->json(['data' => $this->managementData($request, $this->configurationData(CompanyProfile::findOrFail(1)))]);
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

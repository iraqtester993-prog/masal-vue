<?php

namespace App\Http\Controllers\Digital;

use App\Http\Controllers\Controller;
use App\Services\Digital\TopupDistribution;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TopupDistributionController extends Controller
{
    public function __construct(private TopupDistribution $distribution) {}

    private function response(array $data): JsonResponse
    {
        return response()->json(['data' => $data], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function index(Request $request): JsonResponse
    {
        $input = $request->validate(['target_account_id' => 'sometimes|integer|min:1', 'connection_id' => 'sometimes|integer|min:1']);

        return $this->response($this->distribution->options($request->user(), $input['target_account_id'] ?? null, $input['connection_id'] ?? null));
    }

    public function update(Request $request, int $target): JsonResponse
    {
        $rules = ['idempotency_key' => 'required|string|regex:/\A[a-zA-Z0-9._:-]{8,100}\z/', 'version' => 'required|integer|min:0', 'category_ids' => 'present|array|list|max:500', 'category_ids.*' => 'integer|min:1|distinct:strict', 'active' => 'required|boolean'];
        $rules += ['connection_id' => 'sometimes|integer|min:1', 'allocation_balance' => 'sometimes|string|max:16'];
        abort_if(array_diff(array_keys($request->all()), ['idempotency_key', 'version', 'category_ids', 'active', 'connection_id', 'allocation_balance']), 422, 'حقول غير مسموحة.');
        $input = $request->validate($rules);
        $input['category_ids'] = array_map('intval', $input['category_ids']);
        abort_unless(count($input['category_ids']) === count(array_unique($input['category_ids'])), 422, 'الفئة مكررة.');

        return $this->response($this->distribution->save($request->user(), $target, $input, $request));
    }

    public function available(Request $request): JsonResponse
    {
        return $this->response($this->distribution->available($request->user()));
    }

    public function prices(Request $request): JsonResponse
    {
        abort_if(array_diff(array_keys($request->all()), ['idempotency_key', 'version', 'prices']), 422);
        $data = $request->validate(['idempotency_key' => 'required|string|regex:/\A[a-zA-Z0-9._:-]{8,100}\z/', 'version' => 'required|integer|min:1', 'prices' => 'required|array|list|max:500', 'prices.*' => 'array:category_id,price', 'prices.*.category_id' => 'required|integer|min:1|distinct', 'prices.*.price' => 'required|string|max:16']);

        return $this->response($this->distribution->prices($request->user(), $data, $request));
    }
}

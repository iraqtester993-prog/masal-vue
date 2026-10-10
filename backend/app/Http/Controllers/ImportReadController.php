<?php

namespace App\Http\Controllers;

use App\Http\Requests\ImportReadRequest;
use App\Services\ImportFileReader;
use App\Services\ManagementAuthority;
use App\Services\Stock\StockAccess;
use Illuminate\Http\JsonResponse;

class ImportReadController extends Controller
{
    public function __invoke(ImportReadRequest $request, StockAccess $access, ManagementAuthority $authority, ImportFileReader $reader): JsonResponse
    {
        $access->account($request->user(), $request->integer('account_id'), 'import.preview');
        $authority->require($request->user(), 'data.pin');

        return response()->json(['data' => ['sheets' => $reader->read($request->file('file'))]], 200, ['Cache-Control' => 'private, no-store']);
    }
}

<?php

namespace App\Http\Controllers;

use App\Http\Requests\Finance\FinanceRequest;
use App\Services\AccountLookup;
use App\Services\ManagementAuthority;
use Illuminate\Http\JsonResponse;

class AccountLookupController extends Controller
{
    public function accounts(FinanceRequest $request, AccountLookup $lookup, ManagementAuthority $authority): JsonResponse
    {
        $authority->require($request->user(), 'account.view');

        return $lookup->page($request);
    }
}

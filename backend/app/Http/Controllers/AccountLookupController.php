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
        if ($request->input('context') === 'staff_scope') {
            $request->validate(['ancestor_id' => ['required', 'integer', 'min:1']]);
            $permissions = $request->user()->membership->permissions();
            $authority->account($request->user(), $request->integer('ancestor_id'), in_array('staff.create', $permissions, true) ? 'staff.create' : 'staff.scope');
        } elseif ($request->input('context') === 'reports') {
            $permissions = $request->user()->membership->permissions();
            $authority->require($request->user(), in_array('reports.view', $permissions, true) ? 'reports.view' : 'dashboard.view');
        } else {
            $authority->require($request->user(), 'account.view');
        }

        return $lookup->page($request);
    }
}

<?php

namespace App\Http\Middleware;

use App\Enums\AccountType;
use App\Services\Maps\MapAccess;
use App\Services\Operations\AccountTimeGuard;
use App\Services\Operations\OperationAccess;
use App\Services\Operations\OperationGuard;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureOperationalRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        app(AccountTimeGuard::class)->enforce($user, $request, $request->isMethod('POST') && $request->is('api/v1/operations/activity'));
        $systemOwner = $user->membership->kind === 'owner' && $user->membership->account->type === AccountType::System;
        $recoveryAuthority = $systemOwner || ($user->membership->account->type === AccountType::System && in_array('security.view', $user->membership->permissions(), true) && in_array('security.policies', $user->membership->permissions(), true) && app(OperationAccess::class)->canRestoreGlobal($user));
        $recovery = $recoveryAuthority
            && ($request->is('api/v1/auth/me') || $request->is('api/v1/operations/security', 'api/v1/operations/security/*'));
        if (! $recovery && ($reason = app(OperationGuard::class)->reason($user->membership->account_id, 'login') ?? app(OperationGuard::class)->reason($user->membership->account_id, 'app'))) {
            abort_if($systemOwner, 423, $reason);
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            abort(401, $reason);
        }
        $path = $request->path();
        $key = 'app';
        if (str_starts_with($path, 'api/v1/stock/orders') || str_starts_with($path, 'api/v1/import/')) {
            $key = 'import';
        } elseif (preg_match('#^api/v1/sales/(?:reservations(?:/\d+/(?:issue|cancel))?|\d+/(?:print/[^/]+|reprint-request|deliver|receipt))$#', $path)) {
            $key = str_contains($path, 'reservations') ? 'sales' : 'printing';
        } elseif (preg_match('#^api/v1/digital/orders/\d+/print-authorization$#', $path)) {
            $key = 'printing';
        } elseif ($request->isMethod('POST') && in_array($path, ['api/v1/sales', 'api/v1/digital/orders'], true)) {
            $key = 'sales';
        }
        if (! $recovery) {
            app(OperationGuard::class)->assertAllowed($user->membership->account_id, $key);
        }
        $presence = str_starts_with($path, 'api/v1/maps/');
        if (config('maps.require_location') && ! $request->isMethodSafe() && ! $presence && ! $request->is('api/v1/operations/activity', 'api/v1/preferences') && $user->membership->kind === 'owner' && $user->membership->account->type !== AccountType::System) {
            abort_unless(app(MapAccess::class)->locationReady($user, $request), 428, 'فعّل مشاركة موقع الجهاز قبل تنفيذ العمليات.');
        }

        return $next($request);
    }
}

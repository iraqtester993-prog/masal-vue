<?php

namespace App\Http\Middleware;

use App\Services\Operations\AccountTimeGuard;
use App\Services\PasswordReset;
use App\Services\Sales\PosLoginDevice;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsurePortalMembership
{
    public function handle(Request $request, Closure $next, string $mode = 'active'): Response
    {
        if ($request->isMethod('GET')) {
            $request->attributes->set('masal.read_cache', []);
        }
        try {
            return $this->authorize($request, $next, $mode);
        } finally {
            $request->attributes->remove('masal.read_cache');
        }
    }

    private function authorize(Request $request, Closure $next, string $mode): Response
    {
        $user = $request->user();
        $user?->refresh();
        $portal = $request->attributes->get('portal');
        if ($user && Auth::guard('web')->viaRemember() && ! $request->session()->has('masal.portal')) {
            abort_unless($user->isOperational() && $user->membership->account->type->portal() === $portal, 403);
            app(AccountTimeGuard::class)->assertLogin($user);
            $request->session()->regenerate();
            $request->session()->put('masal.portal', $portal);
            $request->session()->put('masal.session_version', $user->session_version);
            app(AccountTimeGuard::class)->start($user, $request);
        }
        abort_unless($user && $request->session()->get('masal.portal') === $portal, 403, 'This session cannot access this portal.');
        if ($mode === 'active') {
            abort_unless((int) $request->session()->get('masal.session_version', 1) === (int) $user->session_version, 401, 'This session has been revoked.');
            abort_unless($user->isOperational(), 403, 'Account access is disabled.');
            abort_unless($user->membership->account->type->portal() === $portal, 403, 'This session cannot access this portal.');
            $resets = app(PasswordReset::class);
            if (! $resets->allowsRequiredRequest($request)) {
                $resets->assertReady($user);
            }
            if (! in_array($request->path(), ['api/v1/device/challenge', 'api/v1/device/heartbeat', 'api/v1/sales/device-session', 'api/v1/digital/device-session'], true)) {
                app(PosLoginDevice::class)->assertCurrent($user, $request);
            }

            return app(EnsureOperationalRequest::class)->handle($request, $next);
        }

        return $next($request);
    }
}

<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
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
        abort_unless($user && $request->session()->get('masal.portal') === $portal, 403, 'This session cannot access this portal.');
        if ($mode === 'active') {
            abort_unless((int) $request->session()->get('masal.session_version', 1) === (int) $user->session_version, 401, 'This session has been revoked.');
            abort_unless($user->isOperational(), 403, 'Account access is disabled.');
            abort_unless($user->membership->account->type->portal() === $portal, 403, 'This session cannot access this portal.');

            return app(EnsureOperationalRequest::class)->handle($request, $next);
        }

        return $next($request);
    }
}

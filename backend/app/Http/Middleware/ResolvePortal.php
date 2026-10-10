<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use Symfony\Component\HttpFoundation\Response;

class ResolvePortal
{
    public function handle(Request $request, Closure $next): Response
    {
        $portal = array_search(strtolower($request->getHost()), config('portals.hosts'), true);
        if (in_array(strtolower($request->getHost()), config('portals.public_hosts', []), true)) {
            $portal = 'public';
        }
        if (app()->environment(['local', 'testing']) && $request->hasHeader('X-Masal-Portal')) {
            $portal = $request->header('X-Masal-Portal');
        }
        abort_unless(in_array($portal, ['admin', 'agents', 'pos', 'public'], true), 403, 'Unknown portal host.');
        if ($portal === 'public') {
            $allowed = ($request->isMethod('GET') && ($request->is('api/v1/company/public', 'api/v1/company/public/assets/*', 'sanctum/csrf-cookie')))
                || ($request->isMethod('POST') && $request->is('api/v1/company/inquiries', 'api/v1/company/inquiries/track', 'api/v1/company/inquiries/followup'));
            abort_unless($allowed, 404);
        }

        $request->attributes->set('portal', $portal);
        config(['session.domain' => null, 'session.cookie' => 'masal_'.$portal.'_session']);
        Session::getFacadeRoot()->driver()->setName('masal_'.$portal.'_session');

        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}

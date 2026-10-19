<?php

namespace App\Http\Middleware;

use App\Http\Controllers\CatalogController;
use App\Http\Controllers\Digital\DigitalController;
use App\Http\Controllers\Sales\SalesController;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
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
        if ($response instanceof JsonResponse) {
            $response->setEncodingOptions($response->getEncodingOptions() | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        if (! $this->cacheArtwork($request, $response)) {
            $response->headers->set('Cache-Control', 'no-store, private');
        }
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'geolocation=(self), camera=(self), microphone=(), payment=(), usb=()');
        $response->headers->set('Content-Security-Policy', "frame-ancestors 'self'; object-src 'none'; base-uri 'self'; form-action 'self'");
        if ($request->is('api/*')) {
            $started = defined('LARAVEL_START') ? LARAVEL_START : ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));
            $response->headers->set('Server-Timing', 'app;dur='.number_format(max(0, (microtime(true) - $started) * 1000), 2, '.', ''));
        }

        return $response;
    }

    private function cacheArtwork(Request $request, Response $response): bool
    {
        if (! $request->isMethodCacheable() || ! $request->user() || ! $response instanceof BinaryFileResponse || $response->getStatusCode() !== 200) {
            return false;
        }
        $action = $request->route()?->getActionName();
        $isCatalog = in_array($action, [CatalogController::class.'@productImage', CatalogController::class.'@providerImage', DigitalController::class.'@offerImage'], true);
        $isSaleArtwork = $action === SalesController::class.'@image' && in_array($request->route('kind'), ['product', 'provider'], true);
        if ((! $isCatalog && ! $isSaleArtwork) || ! in_array($response->headers->get('Content-Type'), ['image/png', 'image/jpeg', 'image/webp'], true)) {
            return false;
        }
        $fingerprint = hash_file('sha256', $response->getFile()->getPathname());
        if ($fingerprint === false) {
            return false;
        }
        $actor = $request->user();
        $scope = [$actor->getAuthIdentifier(), $actor->membership?->account_id, $actor->session_version, $request->attributes->get('portal'), $request->path(), $fingerprint];
        $response->setEtag(hash('sha256', implode('|', $scope)));
        $response->headers->set('Cache-Control', 'private, no-cache, max-age=0, must-revalidate');
        $response->setVary('Authorization', false);
        $response->headers->remove('Last-Modified');
        if ($request->headers->has('If-None-Match')) {
            $response->isNotModified($request);
        }

        return true;
    }
}

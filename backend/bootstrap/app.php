<?php

use App\Http\Middleware\EnsurePortalMembership;
use App\Http\Middleware\ResolvePortal;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo('/login');
        $middleware->trimStrings(except: ['user.password', 'user.password_confirmation']);
        $middleware->prependToGroup('web', ResolvePortal::class);
        $middleware->alias(['portal.member' => EnsurePortalMembership::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        $exceptions->render(function (HttpExceptionInterface $exception, Request $request): ?JsonResponse {
            if ($exception->getStatusCode() === 403 && ($request->is('api/*') || $request->expectsJson())) {
                return response()->json(['message' => 'هذا الإجراء غير متاح حاليًا.'], 403);
            }

            return null;
        });
    })->create();

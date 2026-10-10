<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('login', function (Request $request): array {
            $portal = $request->attributes->get('portal');
            $submittedLogin = $request->input('login');
            $identity = hash('sha256', is_string($submittedLogin) ? mb_strtolower(trim($submittedLogin)) : '');

            return [
                Limit::perMinute(5)->by($portal.'|'.$request->ip().'|'.$identity),
                Limit::perMinute(30)->by('ip|'.$request->ip()),
            ];
        });
    }
}

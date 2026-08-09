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
        // Note: /login is intentionally not throttled here. A blanket per-IP
        // middleware limiter counts successful logins too and would lock out
        // legitimate users behind a shared/NAT IP. Login throttling lives in
        // LoginController and counts failures only.
        RateLimiter::for('auth-register', fn (Request $request): Limit => Limit::perMinute(5)->by($request->ip()));
        RateLimiter::for('device-temp-humidity', fn (Request $request): Limit => Limit::perMinute(120)->by($request->ip()));
        RateLimiter::for('public-submission-lookup', fn (Request $request): Limit => Limit::perMinute(20)->by($request->ip()));
        RateLimiter::for('public-submission-store', fn (Request $request): Limit => Limit::perMinute(5)->by($request->ip()));
    }
}

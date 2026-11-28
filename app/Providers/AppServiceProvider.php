<?php

namespace App\Providers;

use App\Support\ClientIp;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
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
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        // General API limit: per user when logged in, per IP otherwise
        RateLimiter::for('api', fn (Request $request) =>
            Limit::perMinute(120)->by($request->user()?->id ?: ClientIp::from($request))
        );

        // Account creation
        RateLimiter::for('register', fn (Request $request) =>
            Limit::perHour(10)->by(ClientIp::from($request))
        );

        // 6-digit codes: limit guesses per email (whatever the IP) and per IP
        RateLimiter::for('verify', fn (Request $request) => [
            Limit::perMinute(5)->by('verify:' . strtolower((string) $request->input('email'))),
            Limit::perMinute(30)->by('verify-ip:' . ClientIp::from($request)),
        ]);

        // Resending the verification email (stops email spam)
        RateLimiter::for('resend', fn (Request $request) => [
            Limit::perMinute(1)->by('resend:' . strtolower((string) $request->input('email'))),
            Limit::perHour(10)->by('resend-ip:' . ClientIp::from($request)),
        ]);
    }
}
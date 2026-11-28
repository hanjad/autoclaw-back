<?php

use Illuminate\Http\Request;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'user.role' => \App\Http\Middleware\UserRoleMiddleware::class,
        ]);

        // Behind Render's proxy, trust the forwarded protocol/host/port so https is detected.
        // X-Forwarded-For is deliberately NOT trusted: a client can forge it. The real visitor
        // address (for rate limits) comes from App\Support\ClientIp instead.
        $middleware->trustProxies(
            at: '*',
            headers: Request::HEADER_X_FORWARDED_PROTO | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_HOST,
        );

        // Global API rate limit (the 'api' limiter is defined in AppServiceProvider)
        $middleware->throttleApi('api');

        $middleware->api(append: [
            \App\Http\Middleware\SecurityHeaders::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Always answer API calls with JSON (no redirects to a "login" page, no HTML error pages)
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request, \Throwable $e) => $request->is('api/*') || $request->expectsJson()
        );
    })->create();

<?php

use App\Http\Middleware\ActivityLogMiddleware;
use App\Http\Middleware\AuthorizedDeviceMiddleware;
use App\Http\Middleware\BlockedIpMiddleware;
use App\Http\Middleware\EnsureOtpVerified;
use App\Http\Middleware\ForcePasswordChange;
use App\Http\Middleware\IntrusionDetectionMiddleware;
use App\Http\Middleware\PermissionMiddleware;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SessionTimeout;
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
        // Security kernel — order matters (see docs/Architecture.md §3).
        $middleware->prepend([
            BlockedIpMiddleware::class,
            AuthorizedDeviceMiddleware::class,
            IntrusionDetectionMiddleware::class,
        ]);

        $middleware->web(append: [
            SessionTimeout::class,
            ActivityLogMiddleware::class,
            SecurityHeaders::class,
        ]);

        $middleware->api(append: [
            SecurityHeaders::class,
        ]);

        $middleware->alias([
            'permission' => PermissionMiddleware::class,
            'otp.verified' => EnsureOtpVerified::class,
            'force.pwchange' => ForcePasswordChange::class,
        ]);

        $middleware->throttleApi('api');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // CSRF token failures are a STRIDE "Tampering" signal — record them.
        $exceptions->report(function (Illuminate\Session\TokenMismatchException $e) {
            if (app()->runningInConsole()) {
                return;
            }
            $request = request();
            \App\Models\IntrusionLog::create([
                'category' => 'csrf',
                'severity' => 'medium',
                'route' => $request->path(),
                'method' => $request->method(),
                'payload_excerpt' => 'CSRF token mismatch',
                'matched_rule' => 'csrf_mismatch',
                'ip' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 500),
                'user_id' => $request->user()?->id,
            ]);
        });
    })->create();

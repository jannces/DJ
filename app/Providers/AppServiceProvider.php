<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Rbac\RbacService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(RbacService::class);
    }

    public function boot(): void
    {
        // Use Bootstrap 5 pagination markup (project uses Bootstrap, not Tailwind).
        Paginator::useBootstrapFive();

        // Central authorization: every Gate check resolves through the RBAC
        // service, so `@can('perm')` and `->can('perm')` are DB-driven.
        Gate::before(function (User $user, string $ability) {
            return $user->hasPermission($ability) ? true : null;
        });

        // Global search is a back-office tool: it reaches employee records,
        // departments and every leave request. Rank-and-file employees have no
        // use for it and must not be offered it, so the ability is defined once
        // here and consumed by BOTH the topbar (@can) and the /search route
        // (can: middleware) — the UI and the server can never drift apart.
        // Super Admin passes earlier via the wildcard in Gate::before.
        Gate::define('use-global-search', function (User $user) {
            foreach (['employees.view', 'leave.requests.view-all', 'users.manage', 'departments.manage'] as $permission) {
                if ($user->hasPermission($permission)) {
                    return true;
                }
            }

            return false;
        });

        // API rate limiting (STRIDE: Denial of Service). 60 req/min per user/IP.
        RateLimiter::for('api', function ($request) {
            $key = optional($request->user())->id ?: $request->ip();

            return [Limit::perMinute(60)->by($key)];
        });

        // Login throttle limiter (used by named 'login' throttle if referenced).
        RateLimiter::for('login', function ($request) {
            return [Limit::perMinute(5)->by($request->ip())];
        });
    }
}

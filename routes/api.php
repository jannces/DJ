<?php

use App\Http\Controllers\Api\V1\AuthApiController;
use App\Http\Controllers\Api\V1\SecurityApiController;
use Illuminate\Support\Facades\Route;

/*
 * Versioned REST API (ADR: /api/v1). Token auth via Sanctum; permission-checked.
 * Full surface documented in public/openapi.yaml + Swagger UI at /api/documentation.
 */
Route::prefix('v1')->name('api.')->group(function () {
    Route::post('auth/login', [AuthApiController::class, 'login'])->name('auth.login');
    Route::post('auth/otp/verify', [AuthApiController::class, 'verifyOtp'])->name('auth.otp');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('auth/me', [AuthApiController::class, 'me'])->name('auth.me');
        Route::post('auth/logout', [AuthApiController::class, 'logout'])->name('auth.logout');

        // In-app polling endpoint for real-time alerts (session-auth in web too).
        Route::get('security/alerts', [SecurityApiController::class, 'alerts'])->name('security.alerts');
        Route::get('security/stats', [SecurityApiController::class, 'stats'])->name('security.stats');
    });
});

// Session-authenticated alert polling for the web UI bell (uses web guard).
Route::middleware(['web', 'auth'])->get('/internal/security/alerts', [SecurityApiController::class, 'alerts'])
    ->name('web.security.alerts');

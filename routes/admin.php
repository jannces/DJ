<?php

use App\Http\Controllers\Admin\ActivityLogController;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\DeviceController;
use App\Http\Controllers\Admin\NotificationController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\SecurityController;
use App\Http\Controllers\Admin\SettingController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\SearchController;
use Illuminate\Support\Facades\Route;

// Notifications (any authenticated user)
Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
Route::post('/notifications/{id}/read', [NotificationController::class, 'markRead'])->name('notifications.read');
Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.read-all');

// Global search — back-office only (see the `use-global-search` gate).
// Employees are denied server-side, not merely hidden in the UI.
Route::get('/search', [SearchController::class, 'index'])->middleware('can:use-global-search')->name('search');

// Roles & permissions.
// The five roles are fixed by the LGU's structure, so there is no `create` and
// no `store` — a sixth invented from a form would hold authority nothing in the
// organisation answers for. `destroy` stays and stays refusing: all five are
// system roles, and the route is what a replayed form would hit.
Route::middleware('permission:rbac.manage')->group(function () {
    Route::resource('roles', RoleController::class)->only(['index', 'edit', 'update', 'destroy']);
});

// Users
Route::middleware('permission:users.manage')->group(function () {
    Route::get('users', [UserController::class, 'index'])->name('users.index');
    Route::get('users/create', [UserController::class, 'create'])->name('users.create');
    Route::post('users', [UserController::class, 'store'])->name('users.store');
    Route::get('users/{user}/edit', [UserController::class, 'edit'])->name('users.edit');
    Route::put('users/{user}', [UserController::class, 'update'])->name('users.update');
    Route::get('users/{user}/history', [UserController::class, 'history'])->name('users.history');
    // Per-permission overrides are a page of their own, beside /edit and
    // /history. Roles are saved with the profile on the edit form now, so the
    // two forms that used to sit on that page and both submit roles are one.
    Route::get('users/{user}/access', [UserController::class, 'access'])->name('users.access');
    Route::post('users/{user}/access', [UserController::class, 'updateAccess'])->name('users.access.update');
    Route::post('users/{user}/reset-password', [UserController::class, 'resetPassword'])->name('users.reset-password');
    Route::post('users/{user}/block', [UserController::class, 'block'])->name('users.block');
    Route::post('users/{user}/unblock', [UserController::class, 'unblock'])->name('users.unblock');
    Route::post('users/{user}/toggle-active', [UserController::class, 'toggleActive'])->name('users.toggle-active');
    Route::post('users/{user}/archive', [UserController::class, 'archive'])->name('users.archive');
    Route::post('users/{id}/restore', [UserController::class, 'restore'])->name('users.restore');
    // No permanent delete. An account is archived, never destroyed: a
    // forceDelete cascaded through every leave application the person ever
    // filed -- approved ones included, each backed by a signed CSC Form 6 --
    // and nulled their name out of the audit, activity and intrusion logs.
    // Deleting an approver also stripped their name off other people's
    // approved applications. A system whose case rests on auditability cannot
    // offer that, and archiving already covers the reason: resigned, dismissed
    // or died.
});

// Authorized devices
Route::middleware('permission:devices.manage')->group(function () {
    Route::get('devices', [DeviceController::class, 'index'])->name('devices.index');
    Route::get('devices/create', [DeviceController::class, 'create'])->name('devices.create');
    Route::post('devices', [DeviceController::class, 'store'])->name('devices.store');
    Route::put('devices/{device}', [DeviceController::class, 'update'])->name('devices.update');
    Route::post('devices/{device}/toggle', [DeviceController::class, 'toggle'])->name('devices.toggle');
    Route::post('devices/{device}/archive', [DeviceController::class, 'archive'])->name('devices.archive');
});

// Security dashboard & monitoring
Route::middleware('permission:security.dashboard')->group(function () {
    Route::get('security', [SecurityController::class, 'dashboard'])->name('security.dashboard');
});
Route::middleware('permission:security.blocked-ips')->group(function () {
    Route::get('security/blocked-ips', [SecurityController::class, 'blockedIps'])->name('security.blocked-ips');
    Route::post('security/blocked-ips', [SecurityController::class, 'blockIp'])->name('security.block-ip');
    Route::post('security/blocked-ips/{blockedIp}/unblock', [SecurityController::class, 'unblockIp'])->name('security.unblock-ip');
    Route::post('security/blocked-ips/{blockedIp}/reblock', [SecurityController::class, 'reblockIp'])->name('security.reblock-ip');
    Route::post('security/blocked-ips/intruder', [SecurityController::class, 'blockIntruder'])->name('security.block-intruder');
});
Route::middleware('permission:security.intrusions')->group(function () {
    Route::get('security/intrusions', [SecurityController::class, 'intrusions'])->name('security.intrusions');
    // Reviewing is an action, not a side effect of opening a page.
    Route::post('security/intrusions/review', [SecurityController::class, 'reviewIntrusions'])->name('security.intrusions.review');
});

// Audit / activity logs
Route::get('audit-logs', [AuditLogController::class, 'index'])->middleware('permission:audit.view')->name('audit.index');
Route::get('activity-logs', [ActivityLogController::class, 'index'])->middleware('permission:activity.view')->name('activity.index');

// System settings
Route::middleware('permission:settings.manage')->group(function () {
    Route::get('settings', [SettingController::class, 'index'])->name('settings.index');
    Route::put('settings', [SettingController::class, 'update'])->name('settings.update');
});

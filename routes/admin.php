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

// Global search
Route::get('/search', [SearchController::class, 'index'])->name('search');

// Roles & permissions
Route::middleware('permission:rbac.manage')->group(function () {
    Route::resource('roles', RoleController::class)->except(['show']);
});

// Users
Route::middleware('permission:users.manage')->group(function () {
    Route::get('users', [UserController::class, 'index'])->name('users.index');
    Route::get('users/create', [UserController::class, 'create'])->name('users.create');
    Route::post('users', [UserController::class, 'store'])->name('users.store');
    Route::get('users/{user}/edit', [UserController::class, 'edit'])->name('users.edit');
    Route::put('users/{user}', [UserController::class, 'update'])->name('users.update');
    Route::get('users/{user}/history', [UserController::class, 'history'])->name('users.history');
    Route::post('users/{user}/assign-roles', [UserController::class, 'assignRoles'])->name('users.assign-roles');
    Route::post('users/{user}/reset-password', [UserController::class, 'resetPassword'])->name('users.reset-password');
    Route::post('users/{user}/block', [UserController::class, 'block'])->name('users.block');
    Route::post('users/{user}/unblock', [UserController::class, 'unblock'])->name('users.unblock');
    Route::post('users/{user}/toggle-active', [UserController::class, 'toggleActive'])->name('users.toggle-active');
    Route::post('users/{user}/archive', [UserController::class, 'archive'])->name('users.archive');
    Route::post('users/{id}/restore', [UserController::class, 'restore'])->name('users.restore');
    Route::delete('users/{id}', [UserController::class, 'destroy'])->name('users.destroy');
});

// Authorized devices
Route::middleware('permission:devices.manage')->group(function () {
    Route::get('devices', [DeviceController::class, 'index'])->name('devices.index');
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
});
Route::middleware('permission:security.intrusions')->group(function () {
    Route::get('security/intrusions', [SecurityController::class, 'intrusions'])->name('security.intrusions');
});

// Audit / activity logs
Route::get('audit-logs', [AuditLogController::class, 'index'])->middleware('permission:audit.view')->name('audit.index');
Route::get('activity-logs', [ActivityLogController::class, 'index'])->middleware('permission:activity.view')->name('activity.index');

// System settings
Route::middleware('permission:settings.manage')->group(function () {
    Route::get('settings', [SettingController::class, 'index'])->name('settings.index');
    Route::put('settings', [SettingController::class, 'update'])->name('settings.update');
});

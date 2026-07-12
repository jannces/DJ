<?php

use Illuminate\Support\Facades\Route;

// Phase 6 populates these controllers; routes are declared here so the
// permission-driven menu and tests can resolve their names.
Route::middleware('permission:leave.apply')->group(function () {
    Route::get('leave/apply', [\App\Http\Controllers\Leave\LeaveRequestController::class, 'create'])->name('leave.create');
    Route::post('leave', [\App\Http\Controllers\Leave\LeaveRequestController::class, 'store'])->name('leave.store');
    Route::post('leave/preview', [\App\Http\Controllers\Leave\LeaveRequestController::class, 'preview'])->name('leave.preview');
});
Route::middleware('permission:leave.view-own')->group(function () {
    Route::get('leave', [\App\Http\Controllers\Leave\LeaveRequestController::class, 'index'])->name('leave.index');
    Route::get('leave/balances', [\App\Http\Controllers\Leave\LeaveRequestController::class, 'balances'])->name('leave.balances');
    Route::get('leave/{leaveRequest}', [\App\Http\Controllers\Leave\LeaveRequestController::class, 'show'])->name('leave.show');
    Route::get('leave/{leaveRequest}/form6', [\App\Http\Controllers\Leave\LeaveRequestController::class, 'form6'])->name('leave.form6');
    Route::post('leave/{leaveRequest}/documents', [\App\Http\Controllers\Leave\LeaveRequestController::class, 'uploadDocument'])->name('leave.documents.store');
    Route::get('leave/documents/{document}', [\App\Http\Controllers\Leave\LeaveRequestController::class, 'downloadDocument'])->name('leave.documents.download');
});
Route::middleware('permission:leave.cancel')->group(function () {
    Route::post('leave/{leaveRequest}/cancel', [\App\Http\Controllers\Leave\LeaveRequestController::class, 'cancel'])->name('leave.cancel');
});
Route::middleware('permission:leave.requests.view-all')->group(function () {
    Route::get('all-leave', [\App\Http\Controllers\Leave\LeaveRequestController::class, 'all'])->name('leave.all');
});

// Review queues
Route::middleware('permission:leave.review.department')->group(function () {
    Route::get('review/department', [\App\Http\Controllers\Leave\ApprovalController::class, 'departmentQueue'])->name('review.department.index');
});
Route::middleware('permission:leave.certify.hr')->group(function () {
    Route::get('review/hr', [\App\Http\Controllers\Leave\ApprovalController::class, 'hrQueue'])->name('review.hr.index');
});
Route::middleware('permission:leave.approve.final')->group(function () {
    Route::get('review/final', [\App\Http\Controllers\Leave\ApprovalController::class, 'finalQueue'])->name('review.final.index');
});
Route::post('review/{leaveRequest}/act', [\App\Http\Controllers\Leave\ApprovalController::class, 'act'])->name('review.act');

// HR management modules
Route::middleware('permission:employees.view')->group(function () {
    Route::get('employees', [\App\Http\Controllers\Leave\EmployeeController::class, 'index'])->name('employees.index');
    Route::get('employees/{user}', [\App\Http\Controllers\Leave\EmployeeController::class, 'show'])->name('employees.show');
});
Route::middleware('permission:departments.manage')->group(function () {
    Route::resource('departments', \App\Http\Controllers\Leave\DepartmentController::class)->except('show');
});
Route::middleware('permission:positions.manage')->group(function () {
    Route::resource('positions', \App\Http\Controllers\Leave\PositionController::class)->except('show');
});
Route::middleware('permission:holidays.manage')->group(function () {
    Route::resource('holidays', \App\Http\Controllers\Leave\HolidayController::class)->only(['index', 'store', 'destroy']);
});
Route::middleware('permission:leave.balances.manage')->group(function () {
    Route::get('balances', [\App\Http\Controllers\Leave\BalanceController::class, 'index'])->name('balances.index');
    Route::post('balances/{user}/adjust', [\App\Http\Controllers\Leave\BalanceController::class, 'adjust'])->name('balances.adjust');
});
Route::middleware('permission:leave-types.manage')->group(function () {
    Route::resource('leave-types', \App\Http\Controllers\Leave\LeaveTypeController::class)->except('show');
});
Route::middleware('permission:reports.generate')->group(function () {
    Route::get('reports', [\App\Http\Controllers\Leave\ReportController::class, 'index'])->name('reports.index');
    Route::get('reports/{report}', [\App\Http\Controllers\Leave\ReportController::class, 'generate'])->name('reports.generate');
});

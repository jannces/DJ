<?php

use App\Http\Controllers\Leave\ApprovalController;
use App\Http\Controllers\Leave\BalanceController;
use App\Http\Controllers\Leave\DepartmentController;
use App\Http\Controllers\Leave\EmployeeController;
use App\Http\Controllers\Leave\HolidayController;
use App\Http\Controllers\Leave\LeaveRequestController;
use App\Http\Controllers\Leave\LeaveTypeController;
use App\Http\Controllers\Leave\PositionController;
use App\Http\Controllers\Leave\RankingController;
use App\Http\Controllers\Leave\ReportController;
use Illuminate\Support\Facades\Route;

// Phase 6 populates these controllers; routes are declared here so the
// permission-driven menu and tests can resolve their names.
// Reference page: the CSC Form 6 instructions, available to anyone who can file.
Route::middleware('permission:leave.view-own')->group(function () {
    Route::view('leave-instructions', 'leave.instructions')->name('leave.instructions');
});

Route::middleware('permission:leave.apply')->group(function () {
    Route::get('leave/apply', [LeaveRequestController::class, 'create'])->name('leave.create');
    Route::post('leave', [LeaveRequestController::class, 'store'])->name('leave.store');
    Route::post('leave/preview', [LeaveRequestController::class, 'preview'])->name('leave.preview');
});
Route::middleware('permission:leave.view-own')->group(function () {
    Route::get('leave', [LeaveRequestController::class, 'index'])->name('leave.index');
    // `leave/balances` was retired — balances and credit history are rendered on
    // the dashboard instead, from the same LeaveBalance/LeaveHistory queries.
    Route::get('leave/{leaveRequest}', [LeaveRequestController::class, 'show'])->name('leave.show');
    Route::get('leave/{leaveRequest}/form6', [LeaveRequestController::class, 'form6'])->name('leave.form6');
    // Read-only filled form preview, then download. Ownership is checked in the
    // controller — never trusted from the URL.
    Route::get('leave/{leaveRequest}/preview', [LeaveRequestController::class, 'previewForm'])->name('leave.preview-form');
    Route::get('leave/{leaveRequest}/timeline', [LeaveRequestController::class, 'timeline'])->name('leave.timeline');
    Route::post('leave/{leaveRequest}/documents', [LeaveRequestController::class, 'uploadDocument'])->name('leave.documents.store');
    Route::get('leave/documents/{document}', [LeaveRequestController::class, 'downloadDocument'])->name('leave.documents.download');
});
Route::middleware('permission:leave.cancel')->group(function () {
    Route::post('leave/{leaveRequest}/cancel', [LeaveRequestController::class, 'cancel'])->name('leave.cancel');
});
Route::middleware('permission:leave.requests.view-all')->group(function () {
    Route::get('all-leave', [LeaveRequestController::class, 'all'])->name('leave.all');
});

// Single approval queue — Mayor, Vice Mayor and HR share it, and whichever of
// them acts first decides the application. Both the queue and the decision are
// gated by the same permission, enforced server-side.
// Either permission reaches this page — the middleware takes "any of" — and
// the controller then shows each officer only what they may act on. A head who
// tries to act on another office is refused in the service, not merely absent
// from the list: a queue is presentation, and this route takes a request id.
Route::middleware('permission:leave.approve.final,leave.review.department')->group(function () {
    Route::get('review', [ApprovalController::class, 'queue'])->name('review.index');
    Route::post('review/{leaveRequest}/act', [ApprovalController::class, 'act'])->name('review.act');
});

// HR management modules
Route::middleware('permission:employees.view')->group(function () {
    Route::get('employees', [EmployeeController::class, 'index'])->name('employees.index');
    Route::get('employees/{user}', [EmployeeController::class, 'show'])->name('employees.show');
});
Route::middleware('permission:departments.manage')->group(function () {
    Route::resource('departments', DepartmentController::class)->except('show');
});
Route::middleware('permission:positions.manage')->group(function () {
    Route::resource('positions', PositionController::class)->except('show');
});
Route::middleware('permission:holidays.manage')->group(function () {
    Route::resource('holidays', HolidayController::class)->only(['index', 'store', 'destroy']);
});
Route::middleware('permission:leave.balances.manage')->group(function () {
    Route::get('balances', [BalanceController::class, 'index'])->name('balances.index');
    Route::post('balances/{user}/adjust', [BalanceController::class, 'adjust'])->name('balances.adjust');
});
// Who has used the most of each leave type. Gated on either permission: HR
// reads the whole LGU, a department head only the office they head — the scope
// is taken from the department record inside the controller, never from the
// request.
Route::get('rankings', [RankingController::class, 'index'])
    ->middleware('permission:employees.view,leave.review.department')
    ->name('rankings.index');

Route::middleware('permission:leave-types.manage')->group(function () {
    Route::resource('leave-types', LeaveTypeController::class)->except('show');
});
Route::middleware('permission:reports.generate')->group(function () {
    Route::get('reports', [ReportController::class, 'index'])->name('reports.index');
    Route::get('reports/{report}', [ReportController::class, 'generate'])->name('reports.generate');
});

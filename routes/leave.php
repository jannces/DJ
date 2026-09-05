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
use App\Http\Controllers\SignatureController;
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
    // The signature ON an application, addressed by the application. There is
    // deliberately no route that takes an employee id: who may see a
    // signature follows from who may see the application it is on.
    Route::get('leave/{leaveRequest}/signature', [SignatureController::class, 'show'])->name('leave.signature');
});

// A person's own signature. Scoped to the signed-in user in the controller --
// no id in the path, none in the form -- so `leave.view-own` is the whole
// gate: anyone who can file leave can keep a signature to file it with.
Route::middleware('permission:leave.view-own')->group(function () {
    Route::get('profile/signature', [SignatureController::class, 'edit'])->name('signature.edit');
    Route::get('profile/signature/image', [SignatureController::class, 'mine'])->name('signature.mine');
    Route::post('profile/signature', [SignatureController::class, 'store'])->name('signature.store');
    Route::delete('profile/signature', [SignatureController::class, 'destroy'])->name('signature.destroy');
});
Route::middleware('permission:leave.cancel')->group(function () {
    Route::post('leave/{leaveRequest}/cancel', [LeaveRequestController::class, 'cancel'])->name('leave.cancel');
});
Route::middleware('permission:leave.requests.view-all')->group(function () {
    Route::get('all-leave', [LeaveRequestController::class, 'all'])->name('leave.all');
});

// The approval queue. HR holds `leave.approve.final` and nobody else does, so
// this is HR's page — the Mayor oversees leave through All Leave Requests and
// a Department Head reads their own office's on their dashboard.
//
// The guard is on the route, not only on the menu entry: a menu is what a
// person is offered, and this route takes a request id that anybody could type.
// ApprovalWorkflowService::act() checks the same permission again, because the
// queue and the decision are two requests and only the second one changes data.
Route::middleware('permission:leave.approve.final')->group(function () {
    Route::get('review', [ApprovalController::class, 'queue'])->name('review.index');
    // A BLANK CSC Form 6, to be filled in by hand.
    //
    // HR's, not the applicant's. It is the paper an office hands across a
    // counter -- a walk-in with no account, a day the LAN box is down -- and
    // offering it to an employee on the page where they file without paper
    // would work against the point of the system. So it is gated on the
    // permission only HR holds, and it sits on HR's queue page.
    //
    // Its own path segment, not `leave/blank-form`: that would sit under
    // `leave/{leaveRequest}` and be read as an application id.
    Route::get('blank-leave-form', [LeaveRequestController::class, 'blankForm6'])->name('leave.form6-blank');
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

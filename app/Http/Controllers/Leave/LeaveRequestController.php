<?php

namespace App\Http\Controllers\Leave;

use App\Http\Controllers\Controller;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestDocument;
use App\Models\LeaveType;
use App\Services\Leave\LeaveApplicationService;
use App\Services\Leave\LeaveCreditService;
use App\Services\Leave\LeavePolicyEngine;
use App\Services\Security\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LeaveRequestController extends Controller
{
    public function __construct(
        private readonly LeaveApplicationService $applications,
        private readonly LeavePolicyEngine $policy,
        private readonly LeaveCreditService $credits,
        private readonly AuditLogger $audit,
    ) {
    }

    public function index(Request $request): View
    {
        $requests = LeaveRequest::with('leaveType')
            ->where('user_id', $request->user()->id)
            ->status($request->string('status')->toString() ?: null)
            ->latest()->paginate(12)->withQueryString();

        return view('leave.index', compact('requests'));
    }

    public function create(Request $request): View
    {
        $types = LeaveType::active()->orderBy('name')->get();
        $balances = $this->credits->balanceFor($request->user(), $types->firstWhere('code', 'VL'));

        return view('leave.create', [
            'types' => $types,
            'profile' => $request->user()->employeeProfile,
            'vlBalance' => $this->balanceValue($request, 'VL'),
            'slBalance' => $this->balanceValue($request, 'SL'),
        ]);
    }

    private function balanceValue(Request $request, string $code): float
    {
        $type = LeaveType::where('code', $code)->first();

        return $type ? (float) $this->credits->balanceFor($request->user(), $type)->balance : 0;
    }

    /** AJAX: compute working days + required documents + warnings before submit. */
    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'leave_type_id' => ['required', 'exists:leave_types,id'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
        ]);

        $type = LeaveType::findOrFail($data['leave_type_id']);
        $start = Carbon::parse($data['start_date']);
        $end = Carbon::parse($data['end_date']);
        $days = $this->applications->computeWorkingDays($start, $end);
        $validation = $this->policy->validate($type, $request->all(), $days, $start, now());

        return response()->json([
            'working_days' => $days,
            'required_documents' => $this->policy->requiredDocuments($type, $days),
            'warnings' => $validation['warnings'],
            'sufficient_credits' => $this->credits->hasSufficientCredits($request->user(), $type, $days),
            'requires_late_reason' => $validation['requires_late_reason'],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'leave_type_id' => ['required', 'exists:leave_types,id'],
            'date_filed' => ['required', 'date'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'purpose' => ['nullable', 'string', 'max:1000'],
            'commutation' => ['nullable', 'boolean'],
            'late_filing_reason' => ['nullable', 'string', 'max:500'],
            'applicant_signature' => ['required', 'string', 'max:150'],
            'details' => ['array'],
            'documents.*' => ['file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
        ]);

        $type = LeaveType::findOrFail($data['leave_type_id']);
        $leaveRequest = $this->applications->submit($request->user(), $type, $data);

        // Attach uploaded documents
        foreach ($request->file('documents', []) as $docType => $file) {
            $this->storeDocument($leaveRequest, $file, is_string($docType) ? $docType : 'supporting_document', $request->user()->id);
        }

        $message = 'Leave application submitted for review.';
        if ($leaveRequest->filing_warnings) {
            $message .= ' Note: '.implode(' ', $leaveRequest->filing_warnings);
        }

        return redirect()->route('leave.show', $leaveRequest)->with('status', $message);
    }

    public function show(Request $request, LeaveRequest $leaveRequest): View
    {
        $this->authorizeView($request, $leaveRequest);
        $leaveRequest->load('leaveType', 'user.employeeProfile.department', 'user.employeeProfile.position', 'approvals.approver', 'documents');

        return view('leave.show', compact('leaveRequest'));
    }

    public function balances(Request $request): View
    {
        $balances = $request->user()->leaveBalances()->with('leaveType')->get();
        $history = $request->user()->leaveHistory()->with('leaveType')->latest()->limit(100)->get();

        return view('leave.balances', compact('balances', 'history'));
    }

    public function cancel(Request $request, LeaveRequest $leaveRequest): RedirectResponse
    {
        abort_unless($leaveRequest->user_id === $request->user()->id, 403);
        $this->applications->cancel($leaveRequest, $request->user());

        return back()->with('status', 'Leave request cancelled.');
    }

    public function uploadDocument(Request $request, LeaveRequest $leaveRequest): RedirectResponse
    {
        abort_unless($leaveRequest->user_id === $request->user()->id, 403);
        $request->validate([
            'type' => ['required', 'string', 'max:50'],
            'document' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
        ]);

        $this->storeDocument($leaveRequest, $request->file('document'), $request->string('type'), $request->user()->id);

        return back()->with('status', 'Document uploaded.');
    }

    public function downloadDocument(Request $request, LeaveRequestDocument $document): StreamedResponse
    {
        $leaveRequest = $document->leaveRequest;
        $this->authorizeView($request, $leaveRequest);
        abort_unless(Storage::disk('local')->exists($document->path), 404);

        return Storage::disk('local')->download($document->path, $document->original_name);
    }

    public function all(Request $request): View
    {
        $requests = LeaveRequest::with('leaveType', 'user')
            ->when($request->string('status')->toString(), fn ($q, $s) => $q->where('status', $s))
            ->when($request->string('type')->toString(), fn ($q, $t) => $q->whereHas('leaveType', fn ($w) => $w->where('code', $t)))
            ->latest()->paginate(20)->withQueryString();
        $types = LeaveType::orderBy('name')->get();

        return view('leave.all', compact('requests', 'types'));
    }

    public function form6(Request $request, LeaveRequest $leaveRequest)
    {
        $this->authorizeView($request, $leaveRequest);
        $leaveRequest->load('leaveType', 'user.employeeProfile.department', 'user.employeeProfile.position', 'approvals.approver');
        $vl = $this->balanceForUser($leaveRequest, 'VL');
        $sl = $this->balanceForUser($leaveRequest, 'SL');

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('leave.form6', [
            'r' => $leaveRequest, 'vl' => $vl, 'sl' => $sl,
        ])->setPaper('a4');

        return $pdf->stream("CSC-Form6-{$leaveRequest->reference_no}.pdf");
    }

    private function balanceForUser(LeaveRequest $r, string $code): float
    {
        $type = LeaveType::where('code', $code)->first();

        return $type ? (float) $this->credits->balanceFor($r->user, $type)->balance : 0;
    }

    private function storeDocument(LeaveRequest $leaveRequest, $file, string $type, int $userId): void
    {
        $path = $file->store('leave-documents/'.$leaveRequest->id, 'local');
        $leaveRequest->documents()->create([
            'type' => $type,
            'original_name' => $file->getClientOriginalName(),
            'path' => $path,
            'hash' => hash_file('sha256', $file->getRealPath() ?: Storage::disk('local')->path($path)),
            'size' => $file->getSize(),
            'mime' => $file->getMimeType(),
            'uploaded_by' => $userId,
        ]);
    }

    private function authorizeView(Request $request, LeaveRequest $leaveRequest): void
    {
        $user = $request->user();
        if ($leaveRequest->user_id === $user->id) {
            return;
        }
        if ($user->hasPermission('leave.requests.view-all')
            || $user->hasPermission('leave.certify.hr')
            || $user->hasPermission('leave.approve.final')) {
            return;
        }
        if ($user->hasPermission('leave.review.department')
            && $leaveRequest->user->employeeProfile?->department_id === $user->employeeProfile?->department_id) {
            return;
        }
        abort(403);
    }
}

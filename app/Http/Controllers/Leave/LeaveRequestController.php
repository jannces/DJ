<?php

namespace App\Http\Controllers\Leave;

use App\Http\Controllers\Controller;
use App\Models\Approval;
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
    /**
     * The papers CSC Form 6 is offered on, all verified to hold the whole
     * sheet on one page: Legal 612x1008pt, Folio 612x936, A4 595x842, Letter
     * 612x792. Adding a size here means checking it, not assuming it.
     */
    private const PAPER_SIZES = ['legal', 'folio', 'a4', 'letter'];

    /** Long bond, which is the paper the LGU actually files this form on. */
    private const DEFAULT_PAPER = 'legal';

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
            ->latest()->paginate(config('lists.per_page'))->withQueryString();

        // The filter has always worked; nothing on the page ever offered it,
        // so you had to know to type ?status=approved into the address bar.
        return view('leave.index', compact('requests'));
    }

    /**
     * Printed CSC Form No. 6 lists the leave types in a fixed order. We keep
     * that order for the on-screen form so it reads like the official sheet,
     * but the list itself still comes from the database — an admin-added type
     * simply appears after the CSC ones rather than being dropped.
     */
    private const CSC_FORM6_ORDER = [
        'VL', 'FL', 'SL', 'ML', 'PL', 'SPL', 'SOLO', 'STL',
        'VAWC', 'RL', 'SLBW', 'SEL', 'AL', 'MON', 'TL',
    ];

    /**
     * The one list of leave types every rendering of Form No. 6 uses — the entry
     * form, the read-only preview and the PDF. Keeping it in one place is what
     * makes the three look like the same document: 6.A must list the same
     * options in the same order wherever the form is drawn.
     */
    private function cscOrderedTypes(): \Illuminate\Support\Collection
    {
        return LeaveType::active()->get()
            ->sortBy(function (LeaveType $type) {
                $position = array_search($type->code, self::CSC_FORM6_ORDER, true);

                return $position === false ? count(self::CSC_FORM6_ORDER) : $position;
            })
            ->values();
    }

    public function create(Request $request): View
    {
        // Who will be informed when this is submitted, and whose name goes in
        // box 7.B. Read off the applicant's own office; null when they head it
        // themselves, or when the office has no head on record.
        $office = $request->user()->employeeProfile?->department;
        $head = $office && (int) $office->head_user_id !== (int) $request->user()->id
            ? $office->head
            : null;

        return view('leave.create', [
            'types' => $this->cscOrderedTypes(),
            'profile' => $request->user()->employeeProfile,
            'departmentHead' => $head,
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
        // 6.A posts an array: the entry form uses a single <select name="…[]">,
        // which yields a one-element array, and the printed sheet is a checkbox
        // list. Either way the "exactly one type" rule is enforced here, on the
        // server, rather than by the shape of the control.
        $data = $request->validate([
            'leave_type_id' => ['required', 'array', 'size:1'],
            'leave_type_id.*' => ['required', 'integer', 'exists:leave_types,id'],
            'date_filed' => ['required', 'date'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'purpose' => ['nullable', 'string', 'max:1000'],
            'commutation' => ['nullable', 'boolean'],
            'late_filing_reason' => ['nullable', 'string', 'max:500'],
            'applicant_signature' => ['required', 'string', 'max:150'],
            'details' => ['array'],
            'documents.*' => ['file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
        ], [
            'leave_type_id.required' => 'Choose the type of leave you are applying for in section 6.A.',
            'leave_type_id.size' => 'Choose exactly one type of leave in section 6.A.',
            'leave_type_id.*.required' => 'Choose the type of leave you are applying for in section 6.A.',
            'leave_type_id.*.exists' => 'That leave type is not available. Choose one from the list.',
            'end_date.after_or_equal' => 'The last day of leave cannot fall before the first day.',
        ], [
            // These names are read aloud in the error summary at the top of the
            // form, so they are the words on the printed CSC sheet rather than
            // the column names: "The start date field is required" tells an
            // employee looking at a box labelled "From" very little.
            'date_filed' => 'date of filing',
            'start_date' => 'first day of leave',
            'end_date' => 'last day of leave',
            'applicant_signature' => 'signature of applicant',
        ]);

        $data['leave_type_id'] = (int) $data['leave_type_id'][0];

        // The CSC Form 6 layout prints every "In case of…" block at once, so the
        // browser posts a blank for each field the chosen leave type does not use.
        // Drop the empties: only the fields actually filled in are stored, which
        // keeps leave_requests.details identical in shape to before this form.
        $data['details'] = array_filter(
            $data['details'] ?? [],
            static fn ($value) => $value !== null && $value !== '' && $value !== [],
        );

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

    // balances() was removed together with the `leave/balances` route and view.
    // The employee dashboard now renders the same LeaveBalance and LeaveHistory
    // records (see App\Services\DashboardService::forUser).

    /**
     * Read-only preview of the completed CSC Form 6 before downloading it.
     * Employee-facing: an applicant sees their own submitted form exactly as it
     * will be printed, then chooses to download.
     */
    public function previewForm(Request $request, LeaveRequest $leaveRequest): View
    {
        $this->authorizeView($request, $leaveRequest);
        $leaveRequest->load('leaveType', 'user.employeeProfile.department', 'user.employeeProfile.position', 'approvals.approver', 'documents');

        return view('leave.preview-form', [
            'r' => $leaveRequest,
            'types' => $this->cscOrderedTypes(),
            'vl' => $this->balanceForUser($leaveRequest, 'VL'),
            'sl' => $this->balanceForUser($leaveRequest, 'SL'),
        ]);
    }

    /**
     * Employee-facing approval timeline: submitted → pending → decided.
     * This is a tracking view for the applicant, not an approver tool — the
     * approval queue deliberately has no timeline. Ownership is enforced by
     * authorizeView(), never taken from the URL.
     */
    public function timeline(Request $request, LeaveRequest $leaveRequest): View
    {
        $this->authorizeView($request, $leaveRequest);
        $leaveRequest->load('leaveType', 'approvals.approver.roles');

        return view('leave.timeline', ['r' => $leaveRequest]);
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
            // Two dropdowns and no way to look something up: on a list of
            // every application in the LGU, the reference number is how a
            // particular one gets found.
            ->when($request->string('q')->toString(), fn ($q, $s) => $q->where(
                fn ($w) => $w->where('reference_no', 'like', "%{$s}%")
                    ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$s}%"))
            ))
            ->when($request->string('status')->toString(), fn ($q, $s) => $q->where('status', $s))
            ->when($request->string('type')->toString(), fn ($q, $t) => $q->whereHas('leaveType', fn ($w) => $w->where('code', $t)))
            ->latest()->paginate(config('lists.per_page'))->withQueryString();

        return view('leave.all', [
            'requests' => $requests,
            'types' => LeaveType::orderBy('name')->pluck('name', 'code'),
        ]);
    }

    public function form6(Request $request, LeaveRequest $leaveRequest)
    {
        $this->authorizeView($request, $leaveRequest);
        $leaveRequest->load('leaveType', 'user.employeeProfile.department', 'user.employeeProfile.position', 'approvals.approver');
        $vl = $this->balanceForUser($leaveRequest, 'VL');
        $sl = $this->balanceForUser($leaveRequest, 'SL');

        // The sheet — 1–5, all of 6 and all of 7 — lands on ONE page on every
        // size offered here, so the download is a single sheet of paper like
        // the form it replaces. It used to be fixed to Legal, which meant a
        // 14-inch page whatever was in the tray: printers then either shrank
        // it until the citations were unreadable or clipped it.
        // Resolved once and used twice, so the paper the view sets its type
        // for is always the paper dompdf actually draws on. Reading the query
        // string separately in each place is how those two quietly diverge.
        $paper = $this->paperSize($request);

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('leave.form6', [
            'r' => $leaveRequest, 'vl' => $vl, 'sl' => $sl,
            'types' => $this->cscOrderedTypes(), 'paper' => $paper,
        ])->setPaper($paper, 'portrait');

        return $pdf->stream("CSC-Form6-{$leaveRequest->reference_no}.pdf");
    }

    /**
     * The same sheet with nothing filled in, to be completed by hand.
     *
     * The Apply page's Print button used to run window.print() over the web
     * entry form, which produced three pages of rounded cards, bordered input
     * boxes and a date-picker widget -- the application software, photographed.
     * What an office wants off that button is the form: a walk-in applicant, a
     * network down, an employee who would rather write it out.
     *
     * It renders through the SAME template as a filed application, on the same
     * paper sizes. A separate blank template would be a second thing to keep in
     * step with the first, which is the drift this whole change is undoing.
     *
     * No id in the path and no data loaded, because there is no application:
     * it carries nobody's information, so there is nothing here to authorise
     * beyond being a person who can file leave at all.
     */
    public function blankForm6(Request $request)
    {
        $paper = $this->paperSize($request);

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('leave.form6', [
            'r' => null, 'vl' => 0.0, 'sl' => 0.0,
            'types' => $this->cscOrderedTypes(), 'paper' => $paper,
        ])->setPaper($paper, 'portrait');

        return $pdf->stream('CSC-Form6-blank.pdf');
    }

    /**
     * Which paper the sheet is drawn on.
     *
     * An allowlist, not the raw parameter. dompdf's setPaper() accepts any
     * string it recognises and an arbitrary [x1,y1,x2,y2] array besides, so
     * passing the query string through would let a caller choose the page
     * geometry — a small thing on its own, and exactly the kind of unchecked
     * input this system is not supposed to have.
     *
     * Legal -- long bond -- is the default, because that is the paper the LGU
     * files this form on; an unknown value falls back to it rather than
     * failing, since a wrong paper size is a nuisance and a 500 on a download
     * is worse. An attack-shaped value never reaches here at all: the
     * intrusion detection middleware refuses the request first.
     */
    private function paperSize(Request $request): string
    {
        $paper = strtolower((string) $request->query('paper', ''));

        return in_array($paper, self::PAPER_SIZES, true) ? $paper : self::DEFAULT_PAPER;
    }

    /**
     * The "Total Earned" figure box 7.A of the CSC form certifies.
     *
     * Reads the SNAPSHOT taken when HR certified, not the live ledger. The
     * live ledger is wrong here the moment an application is approved: the
     * approval deducts the days, so a form reprinted afterwards showed the
     * post-deduction balance as the total earned and then subtracted the same
     * days again — 30 earned became "27 earned, less 3, balance 24".
     *
     * A certification is a statement about a moment. Falling back to the
     * ledger for an undecided application is correct for the same reason:
     * nothing has been certified yet, so today's figure is the honest one.
     */
    private function balanceForUser(LeaveRequest $r, string $code): float
    {
        $decision = $r->approvals->first(fn ($a) => $a->step_no === 1
            && $a->action !== Approval::ACTION_PENDING);

        $certified = $decision?->certified_balances;
        $key = $code === 'VL' ? 'vacation_balance' : 'sick_balance';

        if (is_array($certified) && array_key_exists($key, $certified)) {
            return (float) $certified[$key];
        }

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

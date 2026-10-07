<?php

namespace App\Http\Controllers\Leave;

use App\Exports\GenericReportExport;
use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\LeaveType;
use App\Services\Reports\ReportService;
use App\Services\Security\AuditLogger;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;

class ReportController extends Controller
{
    public function __construct(
        private readonly ReportService $reports,
        private readonly AuditLogger $audit,
    ) {
    }

    public function index(Request $request): View
    {
        return view('reports.index', [
            'groups' => ReportService::visibleTo($request->user()),
            'labels' => ReportService::GROUPS,
            'periods' => ReportService::PERIODS,
            // Six years back and one forward — the same range the service
            // clamps to, so the dropdown cannot offer a year that gets
            // silently rewritten on the way in.
            'years' => range((int) now()->year + 1, (int) now()->year - 5),
            'departments' => Department::orderBy('name')->get(),
            'types' => LeaveType::orderBy('name')->get(),
        ]);
    }

    public function generate(Request $request, string $report)
    {
        $permission = ReportService::permissionFor($report);
        abort_if($permission === null, 404);

        // `reports.generate` is the right to run reports; it is not the right
        // to read what is in them. Each report names the permission its subject
        // requires, and it is checked here rather than only hidden on the index
        // page — the URL is guessable and the export formats are the same route.
        abort_unless($request->user()->hasPermission($permission), 403);

        // Period first: every report covers one month or one year, so the
        // filters that survive are the ones that name it plus the report's own.
        $filters = $request->only(['period', 'year', 'month', 'department', 'status', 'type', 'category', 'user']);

        // A department report is scoped to the office the reader heads, and
        // that office comes from the record rather than from the request --
        // overwritten, not defaulted, so a head who sends ?department=3 gets
        // their own office and not the Treasurer's. The same rule the rest of
        // this system follows for an employee id: never take the subject of a
        // query from the person asking.
        if (ReportService::isDepartmentScoped($report)) {
            $office = ReportService::officeHeadedBy($request->user());

            abort_if($office === null, 403,
                'These reports cover the office you head, and you are not named as head of one.');

            $filters['department'] = $office->id;
        }

        $data = $this->reports->build($report, array_filter($filters));
        $format = $request->query('format', 'html');

        $this->audit->log('report_generated', null, [], ['report' => $report, 'format' => $format, 'filters' => $filters]);

        // The period belongs in the filename: a folder of "audit-20260819.pdf"
        // says nothing about which month each one covers.
        $filename = $report.'-'.str_replace(' ', '-', strtolower($data['period'])).'-'.now()->format('Ymd');

        // Two formats. CSV is gone from the backend as well as the button —
        // leaving the endpoint live would have been a half-removal, and an
        // export route nothing links to is exactly the kind of thing that
        // outlives the decision to drop it. `?format=csv` now falls through to
        // the on-screen view like any other unrecognised format.
        return match ($format) {
            'pdf' => Pdf::loadView('reports.pdf', ['data' => $data])->setPaper('a4', 'landscape')->download($filename.'.pdf'),
            'xlsx' => Excel::download(new GenericReportExport($data), $filename.'.xlsx'),
            default => view('reports.view', ['data' => $data,
                'departments' => Department::orderBy('name')->get(),
                'types' => LeaveType::orderBy('name')->get()]),
        };
    }
}

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

        $filters = $request->only(['from', 'to', 'department', 'status', 'type', 'category', 'year', 'month', 'user']);
        $data = $this->reports->build($report, array_filter($filters));
        $format = $request->query('format', 'html');

        $this->audit->log('report_generated', null, [], ['report' => $report, 'format' => $format, 'filters' => $filters]);

        $filename = $report.'-'.now()->format('Ymd_His');

        return match ($format) {
            'pdf' => Pdf::loadView('reports.pdf', ['data' => $data])->setPaper('a4', 'landscape')->download($filename.'.pdf'),
            'xlsx' => Excel::download(new GenericReportExport($data), $filename.'.xlsx'),
            'csv' => Excel::download(new GenericReportExport($data), $filename.'.csv', \Maatwebsite\Excel\Excel::CSV),
            default => view('reports.view', ['data' => $data,
                'departments' => Department::orderBy('name')->get(),
                'types' => LeaveType::orderBy('name')->get()]),
        };
    }
}

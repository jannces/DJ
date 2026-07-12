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

    public function index(): View
    {
        return view('reports.index', [
            'reports' => ReportService::REPORTS,
            'departments' => Department::orderBy('name')->get(),
            'types' => LeaveType::orderBy('name')->get(),
        ]);
    }

    public function generate(Request $request, string $report)
    {
        abort_unless(array_key_exists($report, ReportService::REPORTS), 404);

        // Security reports require the extra permission.
        if (in_array($report, ['intrusion', 'audit', 'blocked-login', 'user-activity'], true)) {
            abort_unless($request->user()->hasPermission('reports.security'), 403);
        }

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
                'types' => LeaveType::orderBy('name')->get(),
                'reports' => ReportService::REPORTS]),
        };
    }
}

<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/** One export class drives both XLSX and CSV from a ReportService dataset. */
class GenericReportExport implements FromArray, WithHeadings, WithTitle, WithStyles, ShouldAutoSize
{
    public function __construct(private readonly array $report)
    {
    }

    public function array(): array
    {
        return $this->report['rows'];
    }

    /**
     * Two heading rows: what the report is and which period it covers, then the
     * column names. A downloaded file that does not say its own period is
     * indistinguishable from any other month's once it is sitting in a folder.
     */
    public function headings(): array
    {
        return [
            [$this->report['title'].' — '.($this->report['period'] ?? '')],
            $this->report['columns'],
        ];
    }

    public function title(): string
    {
        return substr($this->report['title'], 0, 31);
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true, 'size' => 12]],
            2 => ['font' => ['bold' => true]],
        ];
    }
}

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
     *
     * The title row is padded to the full column count. A CSV whose first line
     * carries one field while every other line carries eight is a ragged file:
     * strict parsers reject it, and it stops being recognisable as CSV at all —
     * the download was being served as text/plain because of exactly that.
     */
    public function headings(): array
    {
        $columns = $this->report['columns'];
        $title = array_fill(0, max(1, count($columns)), '');
        $title[0] = $this->report['title'].' — '.($this->report['period'] ?? '');

        return [$title, $columns];
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

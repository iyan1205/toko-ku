<?php

namespace App\Exports\Accounting;

use App\Support\Uang;
use Maatwebsite\Excel\Concerns\Export;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/** Laporan keuangan (struktur FinancialReportService) ke Excel: kop toko, judul, lalu tabel. */
class ReportTableExport implements Export, FromArray, ShouldAutoSize, WithHeadings, WithStyles, WithTitle
{
    use Exportable;

    /** @param array<string, mixed> $report @param array<string, string> $store */
    public function __construct(private readonly array $report, private readonly array $store) {}

    public function headings(): array
    {
        return [
            [$this->store['nama_toko'] ?? ''],
            [$this->report['title'].' · '.($this->report['subtitle'] ?? '')],
            [],
            ['Keterangan', ...$this->report['columns']],
        ];
    }

    public function array(): array
    {
        return array_map(fn (array $row) => [
            ($row['type'] === 'account' ? '   ' : '').$row['label'],
            ...array_map(fn ($v) => $v === null ? null : (float) Uang::keDesimal((int) $v), $row['values'] ?? []),
        ], $this->report['rows']);
    }

    /** @return array<int, array<string, mixed>> */
    public function styles(Worksheet $sheet): array
    {
        $styles = [1 => ['font' => ['bold' => true, 'size' => 13]], 2 => ['font' => ['bold' => true]], 4 => ['font' => ['bold' => true]]];
        foreach ($this->report['rows'] as $i => $row) {
            if (in_array($row['type'], ['section', 'total', 'grand'], true)) {
                $styles[$i + 5] = ['font' => ['bold' => true]];
            }
        }

        return $styles;
    }

    public function title(): string
    {
        return mb_substr($this->report['title'], 0, 31);
    }
}

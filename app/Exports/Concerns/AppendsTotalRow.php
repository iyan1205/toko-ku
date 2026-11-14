<?php

namespace App\Exports\Concerns;

use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

/**
 * Baris "Total" di bawah tabel Excel sebuah ListExport. Kelas pemakai mengimplementasikan WithEvents
 * dan totalRow(): label kolom => nilai total.
 */
trait AppendsTotalRow
{
    /** @return array<string, float|int|null> */
    abstract public function totalRow(): array;

    /** @return array<class-string, callable> */
    public function registerEvents(): array
    {
        return [AfterSheet::class => function (AfterSheet $event): void {
            $sheet = $event->sheet->getDelegate();
            $row = $sheet->getHighestRow() + 1;
            $headings = $this->headings();
            $totals = $this->totalRow();

            $sheet->setCellValue('A'.$row, 'Total');
            foreach ($headings as $i => $label) {
                if (array_key_exists($label, $totals)) {
                    $sheet->setCellValue(Coordinate::stringFromColumnIndex($i + 1).$row, $totals[$label]);
                }
            }
            $sheet->getStyle('A'.$row.':'.Coordinate::stringFromColumnIndex(count($headings)).$row)->getFont()->setBold(true);
        }];
    }
}

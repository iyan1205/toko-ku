<?php

namespace App\Exports;

use App\Imports\ImportDefinition;
use App\Models\ImportBatch;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Laporan kesalahan impor: baris yang salah dengan kolom asli + Baris + Kesalahan. Berkas ini bisa
 * langsung diperbaiki lalu diunggah ulang (kolom tambahan diabaikan saat impor).
 */
class ImportErrorsExport implements FromArray, ShouldAutoSize, WithHeadings, WithStyles
{
    use Exportable;

    public function __construct(private readonly ImportDefinition $definition, private readonly ImportBatch $batch) {}

    public function array(): array
    {
        $keys = array_keys($this->definition->columns());

        return array_values(array_map(fn (array $row) => [
            ...array_map(fn (string $key) => $row['raw'][$key] ?? null, $keys),
            $row['line'],
            implode(' ', $row['errors']),
        ], array_filter($this->batch->rows, fn (array $row) => $row['errors'] !== [])));
    }

    public function headings(): array
    {
        return [...array_values(array_map(fn (array $c) => $c['label'], $this->definition->columns())), 'Baris', 'Kesalahan'];
    }

    public function styles(Worksheet $sheet): array
    {
        return [1 => ['font' => ['bold' => true]]];
    }
}

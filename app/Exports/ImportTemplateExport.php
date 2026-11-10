<?php

namespace App\Exports;

use App\Imports\ImportDefinition;
use Maatwebsite\Excel\Concerns\Export;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Templat impor: lembar "Data" (judul kolom saja, kolom teks diformat Teks agar angka 0 di depan
 * tidak hilang) dan lembar "Petunjuk" (wajib/tidak, keterangan, contoh). Tidak ada baris contoh
 * di lembar Data supaya tidak ikut terimpor.
 */
class ImportTemplateExport implements Export, WithMultipleSheets
{
    use Exportable;

    public function __construct(private readonly ImportDefinition $definition) {}

    public function sheets(): array
    {
        $columns = $this->definition->columns();

        return [
            new class($columns) implements FromArray, ShouldAutoSize, WithColumnFormatting, WithHeadings, WithStyles, WithTitle
            {
                /** @param array<string, array<string, mixed>> $columns */
                public function __construct(private readonly array $columns) {}

                public function array(): array
                {
                    return [];
                }

                public function headings(): array
                {
                    return array_values(array_map(fn (array $c) => $c['label'], $this->columns));
                }

                public function columnFormats(): array
                {
                    $formats = [];
                    foreach (array_values($this->columns) as $i => $column) {
                        if ($column['text'] ?? false) {
                            $formats[Coordinate::stringFromColumnIndex($i + 1)] = NumberFormat::FORMAT_TEXT;
                        }
                    }

                    return $formats;
                }

                public function styles(Worksheet $sheet): array
                {
                    return [1 => ['font' => ['bold' => true]]];
                }

                public function title(): string
                {
                    return 'Data';
                }
            },
            new class($columns, $this->definition->matchingRule()) implements FromArray, ShouldAutoSize, WithHeadings, WithStyles, WithTitle
            {
                /** @param array<string, array<string, mixed>> $columns */
                public function __construct(private readonly array $columns, private readonly string $rule) {}

                public function array(): array
                {
                    $rows = array_values(array_map(fn (array $c) => [
                        $c['label'], ($c['required'] ?? false) ? 'Wajib' : 'Opsional', $c['hint'] ?? '', $c['example'] ?? '',
                    ], $this->columns));

                    return [...$rows, [], ['Catatan', '', $this->rule, ''], ['', '', 'Isi data di lembar "Data" mulai baris 2. Jangan ubah judul kolom.', '']];
                }

                public function headings(): array
                {
                    return ['Kolom', 'Wajib?', 'Keterangan', 'Contoh'];
                }

                public function styles(Worksheet $sheet): array
                {
                    return [1 => ['font' => ['bold' => true]]];
                }

                public function title(): string
                {
                    return 'Petunjuk';
                }
            },
        ];
    }
}

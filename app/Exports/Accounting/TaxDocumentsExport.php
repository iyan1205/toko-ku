<?php

namespace App\Exports\Accounting;

use App\Support\Uang;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\Export;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

/** Data faktur pajak satu masa dengan susunan kolom dari config/tax-export.php. */
class TaxDocumentsExport implements Export, FromArray, ShouldAutoSize, WithHeadings, WithTitle
{
    use Exportable;

    /** @param Collection<int, array<string, mixed>> $documents */
    public function __construct(private readonly string $format, private readonly Collection $documents) {}

    /** @return list<array{header: string, field?: string, format?: string, value?: string}> */
    private function columns(): array
    {
        return config("tax-export.formats.{$this->format}.columns", []);
    }

    public function headings(): array
    {
        return array_column($this->columns(), 'header');
    }

    public function array(): array
    {
        return $this->documents->map(fn (array $doc) => array_map(function (array $column) use ($doc) {
            if (array_key_exists('value', $column)) {
                return $column['value'];
            }
            $value = $doc[$column['field'] ?? ''] ?? null;

            return match (true) {
                in_array($column['field'] ?? '', ['dpp', 'ppn'], true) => (float) Uang::keDesimal((int) $value),
                $value instanceof \DateTimeInterface => $value->format($column['format'] ?? 'Y-m-d'),
                default => $value,
            };
        }, $this->columns()))->values()->all();
    }

    public function title(): string
    {
        return mb_substr((string) config("tax-export.formats.{$this->format}.label", 'Faktur pajak'), 0, 31);
    }
}

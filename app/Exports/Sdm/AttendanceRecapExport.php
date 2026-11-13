<?php

namespace App\Exports\Sdm;

use App\Support\Format;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Rekap absensi (AttendanceRecapService::summarize) ke Excel, PDF, dan tabel di layar: kolom yang sama
 * dari columns() + row(), supaya angka yang diekspor sama dengan yang tampil.
 */
class AttendanceRecapExport implements FromArray, ShouldAutoSize, WithHeadings, WithStyles, WithTitle
{
    use Exportable;

    /**
     * @param  iterable<array<string, mixed>>  $summaries
     * @param  array<string, string>  $store
     */
    public function __construct(private readonly iterable $summaries, private readonly string $periodLabel, private readonly bool $withPay, private readonly array $store = []) {}

    /** @return list<array{key: string, label: string, type: string}> type: text | number | hours | money */
    public static function columns(bool $withPay): array
    {
        return array_values(array_filter([
            ['key' => 'code', 'label' => 'Kode', 'type' => 'text'],
            ['key' => 'name', 'label' => 'Nama', 'type' => 'text'],
            ['key' => 'department', 'label' => 'Departemen', 'type' => 'text'],
            ['key' => 'work_days', 'label' => 'Hari kerja', 'type' => 'number'],
            ['key' => 'attended', 'label' => 'Hadir', 'type' => 'number'],
            ['key' => 'late', 'label' => 'Terlambat (kali)', 'type' => 'number'],
            ['key' => 'late_minutes', 'label' => 'Terlambat (menit)', 'type' => 'number'],
            ['key' => 'early_count', 'label' => 'Pulang cepat (kali)', 'type' => 'number'],
            ['key' => 'early_minutes', 'label' => 'Pulang cepat (menit)', 'type' => 'number'],
            ['key' => 'missing_out', 'label' => 'Tidak absen pulang', 'type' => 'number'],
            ['key' => 'permit', 'label' => 'Izin', 'type' => 'number'],
            ['key' => 'sick', 'label' => 'Sakit', 'type' => 'number'],
            ['key' => 'leave', 'label' => 'Cuti', 'type' => 'number'],
            ['key' => 'unpaid_leave', 'label' => 'Cuti tanpa upah', 'type' => 'number'],
            ['key' => 'absent', 'label' => 'Alpha', 'type' => 'number'],
            ['key' => 'worked_rest_days', 'label' => 'Masuk di hari libur', 'type' => 'number'],
            ['key' => 'work_hours', 'label' => 'Jam kerja', 'type' => 'hours'],
            ['key' => 'overtime_hours', 'label' => 'Lembur (jam)', 'type' => 'hours'],
            $withPay ? ['key' => 'overtime_pay', 'label' => 'Upah lembur', 'type' => 'money'] : null,
        ]));
    }

    /**
     * Satu baris (kunci = columns()).
     *
     * @param  array<string, mixed>  $s
     * @return array<string, mixed>
     */
    public static function row(array $s): array
    {
        return [
            'code' => $s['employee']->code,
            'name' => $s['employee']->name,
            'department' => $s['employee']->department?->name,
            'work_days' => $s['work_days'],
            'attended' => $s['present'] + $s['late'],
            'late' => $s['late'],
            'late_minutes' => $s['late_minutes'],
            'early_count' => $s['early_count'],
            'early_minutes' => $s['early_minutes'],
            'missing_out' => $s['missing_out'],
            'permit' => $s['permit'],
            'sick' => $s['sick'],
            'leave' => $s['leave'],
            'unpaid_leave' => $s['unpaid_leave'],
            'absent' => $s['absent'],
            'worked_rest_days' => $s['worked_rest_days'],
            'work_hours' => round($s['work_minutes'] / 60, 2),
            'overtime_hours' => round($s['overtime_minutes'] / 60, 2),
            'overtime_pay' => $s['overtime_pay'],
        ];
    }

    /** Nilai untuk layar/PDF. */
    public static function display(array $column, mixed $value): string
    {
        return match ($column['type']) {
            'money' => $value === null ? '—' : Format::rupiah($value),
            'hours' => str_replace('.', ',', (string) $value),
            'number' => (string) $value,
            default => (string) ($value ?? ''),
        };
    }

    public function headings(): array
    {
        return [
            [$this->store['nama_toko'] ?? ''],
            ['Rekap Absensi · '.$this->periodLabel],
            [],
            array_column(self::columns($this->withPay), 'label'),
        ];
    }

    public function array(): array
    {
        $columns = self::columns($this->withPay);
        $rows = [];
        foreach ($this->summaries as $summary) {
            $row = self::row($summary);
            $rows[] = array_map(fn (array $c) => $c['type'] === 'money' && $row[$c['key']] !== null ? (float) $row[$c['key']] : $row[$c['key']], $columns);
        }

        return $rows;
    }

    /** @return array<int, array<string, mixed>> */
    public function styles(Worksheet $sheet): array
    {
        return [1 => ['font' => ['bold' => true, 'size' => 13]], 2 => ['font' => ['bold' => true]], 4 => ['font' => ['bold' => true]]];
    }

    public function title(): string
    {
        return 'Rekap Absensi';
    }
}

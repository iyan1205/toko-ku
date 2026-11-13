<?php

namespace App\Exports\Sdm;

use App\Exports\ListExport;
use App\Models\AttendanceCorrection;
use Illuminate\Database\Eloquent\Builder;

/** Daftar pengajuan koreksi absensi (SDM → Koreksi Absensi). */
class AttendanceCorrectionsExport extends ListExport
{
    public function documentTitle(): string
    {
        return 'Koreksi Absensi';
    }

    protected function baseQuery(): Builder
    {
        return AttendanceCorrection::query()->with(['employee:id,code,name', 'approver:id,name'])->filter($this->filters);
    }

    protected function defaultOrder(Builder $query): void
    {
        $query->orderByRaw("CASE WHEN status = 'submitted' THEN 0 ELSE 1 END")->latest('attendance_date')->latest('id');
    }

    protected function sorts(): array
    {
        return ['date' => 'attendance_date', 'number' => 'number'];
    }

    protected function columns(): array
    {
        return [
            ['label' => 'Nomor', 'value' => fn (AttendanceCorrection $r) => $r->number],
            ['label' => 'Karyawan', 'value' => fn (AttendanceCorrection $r) => $r->employee?->name],
            ['label' => 'Tanggal', 'value' => fn (AttendanceCorrection $r) => $r->attendance_date, 'type' => 'date'],
            ['label' => 'Sebelumnya', 'value' => fn (AttendanceCorrection $r) => $r->originalLabel()],
            ['label' => 'Diminta', 'value' => fn (AttendanceCorrection $r) => $r->requestedLabel()],
            ['label' => 'Alasan', 'value' => fn (AttendanceCorrection $r) => $r->reason],
            ['label' => 'Status', 'value' => fn (AttendanceCorrection $r) => $r->statusLabel()],
        ];
    }

    protected function filterSummary(): array
    {
        return array_filter(['Pencarian' => $this->filters['cari'] ?? null, 'Dari' => $this->filters['dari'] ?? null, 'Sampai' => $this->filters['sampai'] ?? null]);
    }
}

<?php

namespace App\Exports\Sdm;

use App\Exports\ListExport;
use App\Models\OvertimeRequest;
use Illuminate\Database\Eloquent\Builder;

/** Daftar pengajuan lembur (SDM → Lembur). */
class OvertimeRequestsExport extends ListExport
{
    public function documentTitle(): string
    {
        return 'Pengajuan Lembur';
    }

    protected function baseQuery(): Builder
    {
        return OvertimeRequest::query()->with(['employee:id,code,name', 'approver:id,name'])->filter($this->filters);
    }

    protected function defaultOrder(Builder $query): void
    {
        $query->orderByRaw("CASE WHEN status = 'submitted' THEN 0 ELSE 1 END")->latest('overtime_date')->latest('id');
    }

    protected function sorts(): array
    {
        return ['date' => 'overtime_date', 'number' => 'number', 'duration' => 'duration_minutes'];
    }

    protected function columns(): array
    {
        return [
            ['label' => 'Nomor', 'value' => fn (OvertimeRequest $r) => $r->number],
            ['label' => 'Karyawan', 'value' => fn (OvertimeRequest $r) => $r->employee?->name],
            ['label' => 'Tanggal', 'value' => fn (OvertimeRequest $r) => $r->overtime_date, 'type' => 'date'],
            ['label' => 'Jam', 'value' => fn (OvertimeRequest $r) => $r->timeLabel()],
            ['label' => 'Durasi (menit)', 'value' => fn (OvertimeRequest $r) => $r->duration_minutes, 'type' => 'number'],
            ['label' => 'Alasan', 'value' => fn (OvertimeRequest $r) => $r->reason],
            ['label' => 'Status', 'value' => fn (OvertimeRequest $r) => $r->statusLabel()],
        ];
    }

    protected function filterSummary(): array
    {
        return array_filter(['Pencarian' => $this->filters['cari'] ?? null, 'Dari' => $this->filters['dari'] ?? null, 'Sampai' => $this->filters['sampai'] ?? null]);
    }
}

<?php

namespace App\Exports\Sdm;

use App\Exports\ListExport;
use App\Models\ReimbursementRequest;
use Illuminate\Database\Eloquent\Builder;

/** Daftar pengajuan reimbursement (SDM → Reimbursement). */
class ReimbursementRequestsExport extends ListExport
{
    public function documentTitle(): string
    {
        return 'Pengajuan Reimbursement';
    }

    protected function baseQuery(): Builder
    {
        return ReimbursementRequest::query()->with(['employee:id,code,name', 'approver:id,name', 'attachments'])->withCount('attachments')->filter($this->filters);
    }

    protected function defaultOrder(Builder $query): void
    {
        $query->orderByRaw("CASE WHEN status = 'submitted' THEN 0 ELSE 1 END")->latest('expense_date')->latest('id');
    }

    protected function sorts(): array
    {
        return ['date' => 'expense_date', 'number' => 'number', 'amount' => 'amount'];
    }

    protected function columns(): array
    {
        return [
            ['label' => 'Nomor', 'value' => fn (ReimbursementRequest $r) => $r->number],
            ['label' => 'Karyawan', 'value' => fn (ReimbursementRequest $r) => $r->employee?->name],
            ['label' => 'Tanggal', 'value' => fn (ReimbursementRequest $r) => $r->expense_date, 'type' => 'date'],
            ['label' => 'Kategori', 'value' => fn (ReimbursementRequest $r) => $r->categoryLabel()],
            ['label' => 'Jumlah', 'value' => fn (ReimbursementRequest $r) => $r->amount, 'type' => 'money'],
            ['label' => 'Keterangan', 'value' => fn (ReimbursementRequest $r) => $r->reason],
            ['label' => 'Status', 'value' => fn (ReimbursementRequest $r) => $r->statusLabel()],
        ];
    }

    protected function filterSummary(): array
    {
        return array_filter(['Pencarian' => $this->filters['cari'] ?? null, 'Dari' => $this->filters['dari'] ?? null, 'Sampai' => $this->filters['sampai'] ?? null]);
    }
}

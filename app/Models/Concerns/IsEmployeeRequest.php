<?php

namespace App\Models\Concerns;

use App\Enums\DocumentStatus;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pengajuan karyawan dari Layanan Mandiri (lembur, reimbursement): status baku dokumen + persetujuan.
 * Ditolak = dibatalkan dengan is_rejected (dokumen tertutup; karyawan mengajukan ulang bila perlu).
 * Cabang dokumen = cabang penempatan karyawan, tanpa pemeriksaan cabang aktif pengaju.
 */
trait IsEmployeeRequest
{
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by')->withTrashed();
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by')->withTrashed();
    }

    public function statusLabel(): string
    {
        return $this->is_rejected ? 'Ditolak' : $this->documentStatus()->label();
    }

    public function statusColor(): string
    {
        return $this->is_rejected ? 'danger' : match ($this->documentStatus()) {
            DocumentStatus::Cancelled => 'neutral',
            DocumentStatus::Approved => 'success',
            default => $this->documentStatus()->color(),
        };
    }

    public function isPending(): bool
    {
        return $this->documentStatus() === DocumentStatus::Submitted;
    }

    /** @param array{cari?: ?string, status?: ?string, karyawan?: ?int, dari?: ?string, sampai?: ?string} $f */
    public function scopeFilter(Builder $query, array $f): Builder
    {
        $date = $this->documentDateColumn();

        return $query
            ->when($f['cari'] ?? null, fn (Builder $q, $kata) => $q->where(fn (Builder $w) => $w->where('number', 'like', "%{$kata}%")
                ->orWhereHas('employee', fn (Builder $e) => $e->withTrashed()->where('name', 'like', "%{$kata}%"))))
            ->when($f['status'] ?? null, fn (Builder $q, $v) => match ($v) {
                'rejected' => $q->where('is_rejected', true),
                'cancelled' => $q->where('status', 'cancelled')->where('is_rejected', false),
                default => $q->where('status', $v),
            })
            ->when($f['karyawan'] ?? null, fn (Builder $q, $v) => $q->where('employee_id', $v))
            ->when($f['dari'] ?? null, fn (Builder $q, $v) => $q->whereDate($date, '>=', $v))
            ->when($f['sampai'] ?? null, fn (Builder $q, $v) => $q->whereDate($date, '<=', $v));
    }

    /** Nama route daftar HR (untuk tautan dari kotak persetujuan). */
    abstract protected function hrRouteName(): string;
}

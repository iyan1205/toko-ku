<?php

namespace App\Models;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\HasApproval;
use App\Models\Concerns\HasDocumentStatus;
use App\Models\Concerns\IsEmployeeRequest;
use App\Models\Concerns\TracksAuthors;
use App\Support\Format;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Pengajuan lembur (jam mulai–selesai, durasi dalam menit). Upah lembur dihitung di Fase 13/14. */
class OvertimeRequest extends Model
{
    use BelongsToBranch, HasApproval, HasDocumentStatus, IsEmployeeRequest, SoftDeletes, TracksAuthors;

    protected $fillable = ['overtime_date', 'starts_at', 'ends_at', 'duration_minutes', 'reason'];

    protected function casts(): array
    {
        return [
            'overtime_date' => 'date', 'starts_at' => 'datetime', 'ends_at' => 'datetime', 'duration_minutes' => 'integer',
            'is_rejected' => 'boolean', 'submitted_at' => 'datetime', 'approved_at' => 'datetime', 'cancelled_at' => 'datetime',
        ];
    }

    public function documentType(): DocumentType
    {
        return DocumentType::Overtime;
    }

    public function documentDateColumn(): string
    {
        return 'overtime_date';
    }

    public function approvalDocumentType(): string
    {
        return 'overtime';
    }

    public function approvalTitle(): string
    {
        return "{$this->number} · Lembur {$this->employee?->name} ".Format::tanggal($this->overtime_date).' ('.$this->durationLabel().')';
    }

    public function approvalValue(string $field): mixed
    {
        return $field === 'hours' ? round($this->duration_minutes / 60, 2) : $this->getAttribute($field);
    }

    public function durationLabel(): string
    {
        return Format::durasi($this->duration_minutes);
    }

    public function timeLabel(): string
    {
        return $this->starts_at->format('H:i').'–'.$this->ends_at->format('H:i').($this->ends_at->isSameDay($this->starts_at) ? '' : ' (+1)');
    }

    protected function hrRouteName(): string
    {
        return 'overtime.index';
    }

    /** Pengajuan karyawan mengikuti cabang karyawannya, bukan cabang aktif pengaju. */
    protected function guardsBranchAccess(): bool
    {
        return false;
    }

    public function approvalSupervisor(): ?User
    {
        return $this->employee?->supervisorUser();
    }

    public function approvalUrl(): string
    {
        return route($this->hrRouteName(), ['cari' => $this->number]);
    }

    /** Ditolak lewat alur persetujuan: tutup pengajuan (bukan kembali ke draf). */
    public function onApprovalRejected(ApprovalRequest $request, User $by, string $note): void
    {
        $this->forceFill(['is_rejected' => true]);
        $this->moveToStatus(DocumentStatus::Cancelled, $by, mb_substr('Ditolak: '.$note, 0, 255));
    }
}

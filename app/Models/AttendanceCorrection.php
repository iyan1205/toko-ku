<?php

namespace App\Models;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\HasApproval;
use App\Models\Concerns\HasDocumentStatus;
use App\Models\Concerns\IsEmployeeRequest;
use App\Models\Concerns\TracksAuthors;
use App\Services\Sdm\AbsensiService;
use App\Support\Format;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Pengajuan koreksi absensi (lupa absen, jam mesin salah) dari Layanan Mandiri. Saat disetujui
 * (langsung oleh SDM atau lewat alur persetujuan) jam masuk/pulang diterapkan ke absensi hari itu.
 */
class AttendanceCorrection extends Model
{
    use BelongsToBranch, HasApproval, HasDocumentStatus, IsEmployeeRequest, SoftDeletes, TracksAuthors;

    protected $fillable = ['attendance_date', 'absensi_id', 'original_status', 'original_clock_in', 'original_clock_out', 'requested_clock_in', 'requested_clock_out', 'reason'];

    protected function casts(): array
    {
        return [
            'attendance_date' => 'date', 'original_clock_in' => 'datetime', 'original_clock_out' => 'datetime',
            'requested_clock_in' => 'datetime', 'requested_clock_out' => 'datetime',
            'is_rejected' => 'boolean', 'submitted_at' => 'datetime', 'approved_at' => 'datetime', 'cancelled_at' => 'datetime',
        ];
    }

    /** Disetujui → terapkan ke absensi, dalam transaksi yang sama dengan perubahan status. */
    protected static function booted(): void
    {
        static::updated(function (self $correction) {
            if ($correction->wasChanged('status') && $correction->documentStatus() === DocumentStatus::Approved) {
                app(AbsensiService::class)->terapkanKoreksi($correction);
            }
        });
    }

    public function absensi(): BelongsTo
    {
        return $this->belongsTo(Absensi::class);
    }

    public function documentType(): DocumentType
    {
        return DocumentType::AttendanceCorrection;
    }

    public function documentDateColumn(): string
    {
        return 'attendance_date';
    }

    public function approvalDocumentType(): string
    {
        return 'attendance_correction';
    }

    public function approvalTitle(): string
    {
        return "{$this->number} · Koreksi absensi {$this->employee?->name} ".Format::tanggal($this->attendance_date).' ('.$this->requestedLabel().')';
    }

    public function approvalValue(string $field): mixed
    {
        return $field === 'days_late' ? (int) $this->attendance_date->diffInDays($this->created_at ?? now()) : $this->getAttribute($field);
    }

    /** "08:00–17:00" (jam yang diminta). */
    public function requestedLabel(): string
    {
        return $this->timeRange($this->requested_clock_in, $this->requested_clock_out);
    }

    public function originalLabel(): string
    {
        return $this->original_clock_in || $this->original_clock_out ? $this->timeRange($this->original_clock_in, $this->original_clock_out) : 'Tidak ada absen';
    }

    private function timeRange($in, $out): string
    {
        return ($in?->format('H:i') ?? '—').'–'.($out?->format('H:i') ?? '—').($in && $out && ! $out->isSameDay($in) ? ' (+1)' : '');
    }

    protected function hrRouteName(): string
    {
        return 'attendance-corrections.index';
    }

    /** Pengajuan karyawan mengikuti cabang karyawannya, bukan cabang aktif pengaju. */
    protected function guardsBranchAccess(): bool
    {
        return false;
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

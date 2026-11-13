<?php

namespace App\Models;

use App\Enums\StatusCuti;
use App\Enums\StatusPersetujuan;
use App\Models\Concerns\HasApproval;
use App\Models\Concerns\HasAttachments;
use App\Services\Sdm\CutiService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Pengajuan cuti satu pegawai. Ditulis lewat CutiService.
 *
 * Persetujuan: bila ada alur "Cuti & izin" yang cocok di Pengaturan → Alur Persetujuan, pengajuan
 * diputuskan lewat ApprovalService (HasApproval). Bila tidak, tahap dari jenis cuti disalin saat
 * diajukan (relasi persetujuan, alur lama) supaya pengaturan yang sudah ada tetap berjalan.
 */
class Cuti extends Model
{
    use HasApproval, HasAttachments, HasFactory;

    protected $fillable = [
        'nomor', 'user_id', 'employee_id', 'branch_id', 'jenis_cuti_id', 'tanggal_mulai', 'tanggal_selesai',
        'jumlah_hari', 'alasan', 'status', 'tahap_aktif',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    public function approvalDocumentType(): string
    {
        return 'leave';
    }

    public function approvalTitle(): string
    {
        return "{$this->nomor} · {$this->jenisCuti?->nama} {$this->user?->name} {$this->rentangTanggal()} ({$this->jumlah_hari} hari)";
    }

    public function approvalUrl(): string
    {
        return route('leaves.show', $this);
    }

    public function approvalValue(string $field): mixed
    {
        return match ($field) {
            'leave_type' => $this->jenis_cuti_id,
            'days' => $this->jumlah_hari,
            default => $this->getAttribute($field),
        };
    }

    public function approvalSupervisor(): ?User
    {
        return $this->employee?->supervisorUser();
    }

    public function attachmentPermission(string $action): ?string
    {
        return $action === 'view' ? 'sdm.leaves.view' : 'sdm.leaves.approve';
    }

    /** Pemohon boleh melihat lampirannya, dan menambah/menghapus selama pengajuan masih menunggu. */
    public function allowsAttachmentAccess(User $user, string $action): bool
    {
        return $this->user_id === $user->id && ($action === 'view' || $this->status === StatusCuti::Menunggu);
    }

    /** Status cuti tetap Menunggu selama di alur persetujuan. */
    public function onApprovalSubmitted(ApprovalRequest $request): void {}

    public function onApprovalApproved(ApprovalRequest $request, User $by): void
    {
        app(CutiService::class)->selesaiDisetujui($this, $by);
    }

    public function onApprovalRejected(ApprovalRequest $request, User $by, string $note): void
    {
        $this->update(['status' => StatusCuti::Ditolak, 'tahap_aktif' => null]);
    }

    public function onApprovalCancelled(ApprovalRequest $request, ?User $by): void
    {
        if ($this->status === StatusCuti::Menunggu) {
            $this->update(['status' => StatusCuti::Dibatalkan, 'tahap_aktif' => null]);
        }
    }

    protected function casts(): array
    {
        return [
            'tanggal_mulai' => 'date',
            'tanggal_selesai' => 'date',
            'jumlah_hari' => 'integer',
            'status' => StatusCuti::class,
            'tahap_aktif' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function jenisCuti(): BelongsTo
    {
        return $this->belongsTo(JenisCuti::class);
    }

    public function persetujuan(): HasMany
    {
        return $this->hasMany(PersetujuanCuti::class)->orderBy('urutan');
    }

    /** Tahap yang sedang menunggu keputusan, atau null bila pengajuan sudah selesai. */
    public function tahapSekarang(): ?PersetujuanCuti
    {
        if ($this->status !== StatusCuti::Menunggu || $this->tahap_aktif === null) {
            return null;
        }

        return $this->persetujuan->firstWhere('urutan', $this->tahap_aktif);
    }

    /** Pengajuan orang lain yang tahap aktifnya ditujukan ke pengguna ini (orangnya atau perannya). */
    public function scopePerluDiputuskanOleh(Builder $query, User $user): Builder
    {
        $peran = $user->getRoleNames()->all();

        return $query
            ->where('status', StatusCuti::Menunggu)
            ->where('user_id', '!=', $user->id)
            ->whereHas('persetujuan', fn (Builder $q) => $q
                ->whereColumn('persetujuan_cutis.urutan', 'cutis.tahap_aktif')
                ->where('status', StatusPersetujuan::Menunggu)
                ->where(fn (Builder $q) => $q
                    ->where('approver_id', $user->id)
                    ->orWhere(fn (Builder $q) => $q->whereNull('approver_id')->whereIn('peran', $peran))));
    }

    /** @param array{status?:?string,jenis?:?int,pegawai?:?int,tahun?:?int} $f */
    public function scopeFilter(Builder $query, array $f): Builder
    {
        return $query
            ->when($f['status'] ?? null, fn (Builder $q, $v) => $q->where('status', $v))
            ->when($f['jenis'] ?? null, fn (Builder $q, $v) => $q->where('jenis_cuti_id', $v))
            ->when($f['pegawai'] ?? null, fn (Builder $q, $v) => $q->where('user_id', $v))
            ->when($f['tahun'] ?? null, fn (Builder $q, $v) => $q->whereYear('tanggal_mulai', $v));
    }

    public function rentangTanggal(): string
    {
        $mulai = $this->tanggal_mulai->format('d-m-Y');

        return $this->tanggal_mulai->equalTo($this->tanggal_selesai) ? $mulai : $mulai.' s/d '.$this->tanggal_selesai->format('d-m-Y');
    }
}

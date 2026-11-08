<?php

namespace App\Models;

use App\Enums\StatusCuti;
use App\Enums\StatusPersetujuan;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Pengajuan cuti satu pegawai. Ditulis lewat CutiService.
 * Tahap persetujuannya disalin dari jenis cuti saat diajukan (relasi persetujuan).
 */
class Cuti extends Model
{
    use HasFactory;

    protected $fillable = [
        'nomor', 'user_id', 'jenis_cuti_id', 'tanggal_mulai', 'tanggal_selesai',
        'jumlah_hari', 'alasan', 'status', 'tahap_aktif',
    ];

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

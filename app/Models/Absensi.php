<?php

namespace App\Models;

use App\Enums\PerangkatAbsensi;
use App\Enums\StatusAbsensi;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu baris = satu pegawai pada satu tanggal. Ditulis lewat AbsensiService:
 * absen masuk/pulang oleh pegawai sendiri, atau catatan manual oleh admin (recorded_by terisi).
 */
class Absensi extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'tanggal', 'status', 'clock_in', 'clock_out',
        'device_type', 'ip_address', 'location', 'keterangan', 'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'tanggal' => 'date',
            'status' => StatusAbsensi::class,
            'clock_in' => 'datetime',
            'clock_out' => 'datetime',
            'device_type' => PerangkatAbsensi::class,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function pencatat(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by')->withTrashed();
    }

    /** Lama bekerja dalam menit. Null bila belum absen pulang. */
    public function durasiMenit(): ?int
    {
        if ($this->clock_in === null || $this->clock_out === null) {
            return null;
        }

        return (int) $this->clock_in->diffInMinutes($this->clock_out);
    }

    /** Tautan peta bila lokasi berupa "lintang,bujur". */
    public function tautanPeta(): ?string
    {
        return $this->location ? 'https://www.google.com/maps?q='.urlencode($this->location) : null;
    }

    /** @param array{pegawai?:?int,status?:?string,dari?:?string,sampai?:?string} $f */
    public function scopeFilter(Builder $query, array $f): Builder
    {
        return $query
            ->when($f['pegawai'] ?? null, fn (Builder $q, $v) => $q->where('user_id', $v))
            ->when($f['status'] ?? null, fn (Builder $q, $v) => $q->where('status', $v))
            ->when($f['dari'] ?? null, fn (Builder $q, $v) => $q->whereDate('tanggal', '>=', $v))
            ->when($f['sampai'] ?? null, fn (Builder $q, $v) => $q->whereDate('tanggal', '<=', $v));
    }
}

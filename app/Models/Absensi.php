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

    /** Sumber catatan: web (absen sendiri), manual (dicatat SDM), fingerprint (impor mesin), correction (koreksi disetujui), leave (cuti). */
    public const SOURCES = ['web' => 'Absen sendiri', 'manual' => 'Dicatat SDM', 'fingerprint' => 'Mesin fingerprint', 'correction' => 'Koreksi', 'leave' => 'Cuti'];

    protected $fillable = [
        'user_id', 'employee_id', 'tanggal', 'status', 'clock_in', 'clock_out',
        'late_minutes', 'early_leave_minutes', 'source',
        'device_type', 'ip_address', 'location', 'clock_in_photo', 'clock_out_photo', 'clock_out_location', 'distance_m',
        'keterangan', 'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'tanggal' => 'date',
            'status' => StatusAbsensi::class,
            'clock_in' => 'datetime',
            'clock_out' => 'datetime',
            'device_type' => PerangkatAbsensi::class,
            'late_minutes' => 'integer',
            'early_leave_minutes' => 'integer',
            'distance_m' => 'integer',
        ];
    }

    /**
     * Absensi/jadwal dicatat per karyawan (employee_id). Catatan dari akun login melengkapi
     * employee_id dari karyawan yang tertaut, dan sebaliknya.
     */
    protected static function booted(): void
    {
        static::saving(function (self $model) {
            if ($model->employee_id === null && $model->user_id !== null) {
                $model->employee_id = Employee::query()->where('user_id', $model->user_id)->value('id');
            }
            if ($model->user_id === null && $model->employee_id !== null) {
                $model->user_id = Employee::withTrashed()->whereKey($model->employee_id)->value('user_id');
            }
        });
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function pencatat(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by')->withTrashed();
    }

    /** Nama pegawai: dari data karyawan, atau akun login untuk catatan lama. */
    public function personName(): string
    {
        return $this->employee?->name ?? $this->user?->name ?? '—';
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

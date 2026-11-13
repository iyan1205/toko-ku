<?php

namespace App\Models;

use App\Models\Concerns\RecordsActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Jam kerja yang dipakai di jadwal, mis. Pagi 08:00–16:00. */
class Shift extends Model
{
    use HasFactory, RecordsActivity;

    protected $fillable = ['nama', 'jam_masuk', 'jam_pulang', 'late_tolerance_minutes', 'early_leave_tolerance_minutes', 'break_minutes', 'color', 'is_active'];

    /** Warna penanda di kalender jadwal. */
    public const COLORS = ['primary' => 'Biru', 'success' => 'Hijau', 'warning' => 'Kuning', 'danger' => 'Merah', 'info' => 'Toska', 'neutral' => 'Abu-abu'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'late_tolerance_minutes' => 'integer',
            'early_leave_tolerance_minutes' => 'integer',
            'break_minutes' => 'integer',
        ];
    }

    public function jadwals(): HasMany
    {
        return $this->hasMany(Jadwal::class);
    }

    public function scopeAktif(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** "08:00" walau MySQL menyimpan "08:00:00". */
    protected function jamMasuk(): Attribute
    {
        return Attribute::get(fn (?string $nilai) => $nilai === null ? null : substr($nilai, 0, 5));
    }

    protected function jamPulang(): Attribute
    {
        return Attribute::get(fn (?string $nilai) => $nilai === null ? null : substr($nilai, 0, 5));
    }

    /** Shift malam: jam pulang jatuh keesokan harinya. */
    public function lintasHari(): bool
    {
        return $this->jam_pulang <= $this->jam_masuk;
    }

    /** Toleransi terlambat (menit): dari shift, atau bawaan Pengaturan SDM. */
    public function lateTolerance(): int
    {
        return $this->late_tolerance_minutes ?? (int) setting('sdm.attendance.late_tolerance', config('pos.absensi.toleransi_menit', 0));
    }

    /** Toleransi pulang cepat (menit). */
    public function earlyLeaveTolerance(): int
    {
        return $this->early_leave_tolerance_minutes ?? (int) setting('sdm.attendance.early_tolerance', 0);
    }

    /** Lama kerja terjadwal setelah dikurangi istirahat (menit). */
    public function workMinutes(): int
    {
        [$h1, $m1] = array_map('intval', explode(':', (string) $this->jam_masuk));
        [$h2, $m2] = array_map('intval', explode(':', (string) $this->jam_pulang));
        $minutes = ($h2 * 60 + $m2) - ($h1 * 60 + $m1);

        return max(0, ($minutes <= 0 ? $minutes + 1440 : $minutes) - $this->break_minutes);
    }

    public function rentang(): string
    {
        return "{$this->jam_masuk}–{$this->jam_pulang}".($this->lintasHari() ? ' (+1)' : '');
    }

    protected function activityLogName(): string
    {
        return 'sdm';
    }

    protected function activityLogLabel(): string
    {
        return 'Shift';
    }

    /** @return list<string> */
    protected function activityLogAttributes(): array
    {
        return $this->getFillable();
    }
}

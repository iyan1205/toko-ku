<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Jam kerja yang dipakai di jadwal, mis. Pagi 08:00–16:00. */
class Shift extends Model
{
    use HasFactory;

    protected $fillable = ['nama', 'jam_masuk', 'jam_pulang', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
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

    public function rentang(): string
    {
        return "{$this->jam_masuk}–{$this->jam_pulang}".($this->lintasHari() ? ' (+1)' : '');
    }
}

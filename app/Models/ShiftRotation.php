<?php

namespace App\Models;

use App\Models\Concerns\RecordsActivity;
use App\Models\Concerns\TracksAuthors;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Pola rotasi shift: urutan shift per hari yang berulang, misal Pagi, Pagi, Siang, Siang, Libur.
 * pattern = list id shift, null = libur. Diterapkan ke jadwal karyawan lewat ScheduleRotationService.
 */
class ShiftRotation extends Model
{
    use RecordsActivity, TracksAuthors;

    protected $fillable = ['name', 'pattern', 'description', 'is_active'];

    protected function casts(): array
    {
        return ['pattern' => 'array', 'is_active' => 'boolean'];
    }

    /** @return Collection<int, ?Shift> shift per hari dalam satu siklus */
    public function days(): Collection
    {
        $shifts = Shift::query()->whereIn('id', array_filter($this->pattern ?? []))->get()->keyBy('id');

        return collect($this->pattern ?? [])->map(fn ($id) => $id === null ? null : $shifts->get((int) $id));
    }

    /** "Pagi, Pagi, Siang, Libur" */
    public function summary(): string
    {
        return $this->days()->map(fn (?Shift $s) => $s?->nama ?? 'Libur')->join(', ');
    }

    protected function activityLogName(): string
    {
        return 'sdm';
    }

    protected function activityLogLabel(): string
    {
        return 'Pola rotasi shift';
    }

    /** @return list<string> */
    protected function activityLogAttributes(): array
    {
        return ['name', 'pattern', 'description', 'is_active'];
    }
}

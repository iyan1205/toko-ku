<?php

namespace App\Models;

use App\Enums\EmploymentType;
use App\Models\Concerns\TracksAuthors;
use App\Support\Format;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Riwayat perjanjian kerja (PKWT/PKWTT/harian/magang). Kontrak terbaru = status kerja karyawan saat ini. */
class EmployeeContract extends Model
{
    use SoftDeletes, TracksAuthors;

    protected $fillable = ['number', 'employment_type', 'start_date', 'end_date', 'notes'];

    protected function casts(): array
    {
        return ['employment_type' => EmploymentType::class, 'start_date' => 'date', 'end_date' => 'date'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** Sisa hari sampai kontrak berakhir (negatif = sudah lewat), null bila tanpa batas. */
    public function daysLeft(): ?int
    {
        return $this->end_date ? (int) CarbonImmutable::today()->diffInDays(CarbonImmutable::parse($this->end_date), false) : null;
    }

    public function periodLabel(): string
    {
        return Format::tanggal($this->start_date).' – '.($this->end_date ? Format::tanggal($this->end_date) : 'tanpa batas');
    }
}

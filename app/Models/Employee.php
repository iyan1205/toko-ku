<?php

namespace App\Models;

use App\Enums\EmploymentType;
use App\Models\Concerns\RecordsActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Karyawan. user_id opsional menautkan ke akun login (satu akun untuk satu karyawan);
 * Absensi, Jadwal, dan Cuti membaca data lewat akun tersebut.
 */
class Employee extends Model
{
    use HasFactory, RecordsActivity, SoftDeletes;

    protected $fillable = [
        'code', 'name', 'user_id', 'department_id', 'position_id', 'employment_type', 'identity_number',
        'birth_date', 'join_date', 'end_date', 'phone', 'email', 'address', 'is_active', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'employment_type' => EmploymentType::class,
            'birth_date' => 'date',
            'join_date' => 'date',
            'end_date' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    /** @param array{cari?:?string,departemen?:?int,jabatan?:?int,jenis?:?string,status?:?string} $f */
    public function scopeFilter(Builder $query, array $f): Builder
    {
        return $query
            ->when($f['cari'] ?? null, fn (Builder $q, $kata) => $q->where(fn (Builder $w) => $w
                ->where('code', 'like', "%{$kata}%")->orWhere('name', 'like', "%{$kata}%")
                ->orWhere('phone', 'like', "%{$kata}%")->orWhere('email', 'like', "%{$kata}%")))
            ->when($f['departemen'] ?? null, fn (Builder $q, $v) => $q->where('department_id', $v))
            ->when($f['jabatan'] ?? null, fn (Builder $q, $v) => $q->where('position_id', $v))
            ->when($f['jenis'] ?? null, fn (Builder $q, $v) => $q->where('employment_type', $v))
            ->when($f['status'] ?? null, fn (Builder $q, $v) => $q->where('is_active', $v === 'aktif'));
    }

    protected function activityLogName(): string
    {
        return 'sdm';
    }

    protected function activityLogLabel(): string
    {
        return 'Karyawan';
    }

    /** @return list<string> */
    protected function activityLogAttributes(): array
    {
        return ['code', 'name', 'user_id', 'department_id', 'position_id', 'employment_type', 'join_date', 'end_date', 'is_active'];
    }
}

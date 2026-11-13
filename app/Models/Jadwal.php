<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Satu baris = shift satu pegawai pada satu tanggal. shift_id kosong = libur. */
class Jadwal extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'employee_id', 'tanggal', 'shift_id'];

    protected function casts(): array
    {
        return ['tanggal' => 'date'];
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

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function libur(): bool
    {
        return $this->shift_id === null;
    }
}

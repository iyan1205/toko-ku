<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Satu baris = shift satu pegawai pada satu tanggal. shift_id kosong = libur. */
class Jadwal extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'tanggal', 'shift_id'];

    protected function casts(): array
    {
        return ['tanggal' => 'date'];
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

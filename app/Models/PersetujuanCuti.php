<?php

namespace App\Models;

use App\Enums\RoleName;
use App\Enums\StatusPersetujuan;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Keputusan satu tahap persetujuan pada satu pengajuan cuti. */
class PersetujuanCuti extends Model
{
    protected $fillable = [
        'cuti_id', 'urutan', 'peran', 'approver_id',
        'status', 'diputuskan_oleh', 'diputuskan_pada', 'catatan',
    ];

    protected function casts(): array
    {
        return [
            'urutan' => 'integer',
            'peran' => RoleName::class,
            'status' => StatusPersetujuan::class,
            'diputuskan_pada' => 'datetime',
        ];
    }

    public function cuti(): BelongsTo
    {
        return $this->belongsTo(Cuti::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_id')->withTrashed();
    }

    public function pemutus(): BelongsTo
    {
        return $this->belongsTo(User::class, 'diputuskan_oleh')->withTrashed();
    }

    /** Orang atau peran yang berhak memutuskan tahap ini (tanpa pengecualian Super Admin). */
    public function ditujukanKe(User $user): bool
    {
        return $this->approver_id !== null
            ? $this->approver_id === $user->id
            : $this->peran !== null && $user->hasRole($this->peran->value);
    }

    /** "Peran Admin" atau nama orang yang ditunjuk. */
    public function label(): string
    {
        return $this->approver_id !== null ? (string) $this->approver?->name : "Peran {$this->peran?->value}";
    }
}

<?php

namespace App\Models;

use App\Enums\RoleName;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Satu tahap alur persetujuan jenis cuti: diisi peran (siapa pun dengan peran itu) atau orang tertentu. */
class TahapPersetujuan extends Model
{
    protected $fillable = ['jenis_cuti_id', 'urutan', 'peran', 'approver_id'];

    protected function casts(): array
    {
        return [
            'urutan' => 'integer',
            'peran' => RoleName::class,
        ];
    }

    public function jenisCuti(): BelongsTo
    {
        return $this->belongsTo(JenisCuti::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_id')->withTrashed();
    }

    /** "Peran Admin" atau nama orang yang ditunjuk. */
    public function label(): string
    {
        return $this->approver_id !== null ? (string) $this->approver?->name : "Peran {$this->peran?->value}";
    }
}

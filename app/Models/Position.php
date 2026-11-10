<?php

namespace App\Models;

use App\Models\Concerns\RecordsActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Jabatan karyawan (SDM). Hanya bisa dihapus bila tidak dipakai karyawan mana pun. */
class Position extends Model
{
    use HasFactory, RecordsActivity;

    protected $fillable = ['name', 'description'];

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    /** Termasuk karyawan yang sudah dihapus: foreign key tetap menahan baris tersebut. */
    public function dipakaiKaryawan(): int
    {
        return $this->employees()->withTrashed()->count();
    }

    protected function activityLogName(): string
    {
        return 'sdm';
    }

    protected function activityLogLabel(): string
    {
        return 'Jabatan';
    }

    /** @return list<string> */
    protected function activityLogAttributes(): array
    {
        return ['name', 'description'];
    }
}

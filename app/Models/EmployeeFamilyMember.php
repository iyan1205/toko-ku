<?php

namespace App\Models;

use App\Enums\FamilyRelation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Anggota keluarga karyawan; is_dependent = tanggungan (dasar status PTKP). */
class EmployeeFamilyMember extends Model
{
    protected $fillable = ['name', 'relation', 'identity_number', 'birth_date', 'occupation', 'is_dependent', 'phone'];

    protected function casts(): array
    {
        return ['relation' => FamilyRelation::class, 'birth_date' => 'date', 'is_dependent' => 'boolean'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}

<?php

namespace App\Models;

use App\Enums\EducationLevel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Riwayat pendidikan karyawan. */
class EmployeeEducation extends Model
{
    protected $table = 'employee_educations';

    protected $fillable = ['level', 'institution', 'major', 'start_year', 'end_year'];

    protected function casts(): array
    {
        return ['level' => EducationLevel::class, 'start_year' => 'integer', 'end_year' => 'integer'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}

<?php

namespace App\Models;

use App\Enums\EmployeeMovementType;
use App\Models\Concerns\TracksAuthors;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Mutasi/promosi/demosi: posisi sebelum (from_*) dan sesudah (to_*). Diterapkan ke data karyawan
 * pada tanggal efektif (applied_at terisi); yang bertanggal depan diterapkan perintah sdm:proses-harian.
 */
class EmployeeMovement extends Model
{
    use SoftDeletes, TracksAuthors;

    /** Kolom karyawan yang ikut berubah (nama tanpa awalan from_/to_). */
    public const FIELDS = ['branch_id', 'department_id', 'position_id', 'supervisor_id', 'grade'];

    protected $fillable = ['type', 'effective_date', 'decree_number', 'notes'];

    protected function casts(): array
    {
        return ['type' => EmployeeMovementType::class, 'effective_date' => 'date', 'applied_at' => 'datetime'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function isApplied(): bool
    {
        return $this->applied_at !== null;
    }

    /**
     * Perubahan yang tercatat, untuk ditampilkan: label => [sebelum, sesudah].
     *
     * @return array<string, array{0: ?string, 1: ?string}>
     */
    public function changes(): array
    {
        $names = [
            'branch_id' => ['Cabang', fn ($id) => Branch::withTrashed()->find($id)?->name],
            'department_id' => ['Departemen', fn ($id) => Department::find($id)?->name],
            'position_id' => ['Jabatan', fn ($id) => Position::find($id)?->name],
            'supervisor_id' => ['Atasan', fn ($id) => Employee::withTrashed()->find($id)?->name],
            'grade' => ['Grade', fn ($v) => $v],
        ];
        $result = [];
        foreach ($names as $field => [$label, $resolve]) {
            $from = $this->getAttribute("from_{$field}");
            $to = $this->getAttribute("to_{$field}");
            if ($from != $to) {
                $result[$label] = [$from !== null ? $resolve($from) : null, $to !== null ? $resolve($to) : null];
            }
        }

        return $result;
    }
}

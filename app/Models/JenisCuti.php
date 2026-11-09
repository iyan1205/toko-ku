<?php

namespace App\Models;

use App\Enums\StatusAbsensi;
use App\Models\Concerns\RecordsActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Jenis cuti beserta kuota tahunan dan alur persetujuannya.
 * Tanpa tahap persetujuan = pengajuan langsung disetujui.
 */
class JenisCuti extends Model
{
    use HasFactory, RecordsActivity;

    protected $fillable = ['nama', 'kuota_tahunan', 'status_absensi', 'is_active'];

    protected function casts(): array
    {
        return [
            'kuota_tahunan' => 'integer',
            'status_absensi' => StatusAbsensi::class,
            'is_active' => 'boolean',
        ];
    }

    public function tahap(): HasMany
    {
        return $this->hasMany(TahapPersetujuan::class)->orderBy('urutan');
    }

    public function cutis(): HasMany
    {
        return $this->hasMany(Cuti::class);
    }

    public function scopeAktif(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function punyaKuota(): bool
    {
        return $this->kuota_tahunan !== null;
    }

    protected function activityLogName(): string
    {
        return 'sdm';
    }

    protected function activityLogLabel(): string
    {
        return 'Jenis cuti';
    }

    /** @return list<string> */
    protected function activityLogAttributes(): array
    {
        return $this->getFillable();
    }
}

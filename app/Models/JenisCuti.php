<?php

namespace App\Models;

use App\Enums\Gender;
use App\Enums\StatusAbsensi;
use App\Models\Concerns\RecordsActivity;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Jenis cuti beserta aturannya (kuota, saldo per masa kerja, surat keterangan, batas hari, masa kerja
 * minimal, jenis kelamin, hari kerja saja) dan alur persetujuan lamanya (tahap). Tanpa alur persetujuan
 * dan tanpa tahap = pengajuan langsung disetujui.
 */
class JenisCuti extends Model
{
    use HasFactory, RecordsActivity;

    public const CATEGORIES = [
        'annual' => 'Cuti tahunan', 'sick' => 'Sakit', 'maternity' => 'Melahirkan / keguguran',
        'special' => 'Cuti khusus (alasan penting)', 'unpaid' => 'Cuti tanpa upah', 'other' => 'Lainnya',
    ];

    protected $fillable = [
        'nama', 'category', 'kuota_tahunan', 'quota_tiers', 'status_absensi', 'is_paid', 'requires_document', 'document_after_days',
        'max_days_per_request', 'min_service_months', 'gender', 'working_days_only', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'kuota_tahunan' => 'integer',
            'quota_tiers' => 'array',
            'status_absensi' => StatusAbsensi::class,
            'is_paid' => 'boolean',
            'requires_document' => 'boolean',
            'document_after_days' => 'integer',
            'max_days_per_request' => 'integer',
            'min_service_months' => 'integer',
            'gender' => Gender::class,
            'working_days_only' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Kuota setahun untuk karyawan ini: tingkat masa kerja (quota_tiers) yang sudah tercapai pada
     * tanggal $at (bawaan: hari ini, paling lambat 31 Des tahun itu), atau kuota_tahunan bila tanpa
     * tingkat. Null = tanpa kuota.
     */
    public function quotaFor(?Employee $employee, int $year, ?CarbonInterface $at = null): ?int
    {
        if (! $this->punyaKuota() && empty($this->quota_tiers)) {
            return null;
        }
        if (empty($this->quota_tiers) || $employee?->join_date === null) {
            return $this->kuota_tahunan;
        }

        $at = CarbonImmutable::parse($at ?? today())->min(CarbonImmutable::create($year, 12, 31));
        $months = $this->serviceMonths($employee, $at);
        $days = 0;
        foreach (collect($this->quota_tiers)->sortBy('months') as $tier) {
            if ($months >= (int) $tier['months']) {
                $days = (int) $tier['days'];
            }
        }

        return $days;
    }

    /** Masa kerja (bulan penuh) sampai tanggal itu. */
    public function serviceMonths(Employee $employee, CarbonInterface $at): int
    {
        return $employee->join_date ? (int) CarbonImmutable::parse($employee->join_date)->diffInMonths($at, false) : 0;
    }

    /** "12 bln: 12 hari · 60 bln: 15 hari" */
    public function quotaTiersLabel(): string
    {
        return collect($this->quota_tiers ?? [])->sortBy('months')->map(fn ($t) => "masa kerja ≥ {$t['months']} bln: {$t['days']} hari")->join(' · ');
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

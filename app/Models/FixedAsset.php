<?php

namespace App\Models;

use App\Enums\DepreciationMethod;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\HasAttachments;
use App\Models\Concerns\RecordsActivity;
use App\Models\Concerns\TracksAuthors;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Aset tetap. Dicatat dan disusutkan lewat App\Services\Accounting\FixedAssetService dan
 * DepreciationService. accumulated_depreciation = akumulasi awal (aset lama) + penyusutan yang
 * sudah diposting. Aset yang dilepas (dijual/dihapus) berstatus disposed dan tidak disusutkan lagi.
 */
class FixedAsset extends Model
{
    use BelongsToBranch, HasAttachments, RecordsActivity, SoftDeletes, TracksAuthors;

    public const ACTIVE = 'active';

    public const DISPOSED = 'disposed';

    protected $fillable = [
        'name', 'asset_category_id', 'acquisition_date', 'acquisition_cost', 'residual_value', 'useful_life_months', 'method', 'declining_rate',
        'depreciation_start', 'opening_accumulated', 'credit_account_id', 'location', 'employee_id', 'holder_name', 'cost_center_id', 'serial_number', 'description',
    ];

    protected function casts(): array
    {
        return [
            'acquisition_date' => 'date',
            'acquisition_cost' => 'decimal:2',
            'residual_value' => 'decimal:2',
            'useful_life_months' => 'integer',
            'method' => DepreciationMethod::class,
            'declining_rate' => 'decimal:2',
            'depreciation_start' => 'date',
            'opening_accumulated' => 'decimal:2',
            'accumulated_depreciation' => 'decimal:2',
            'disposed_at' => 'date',
            'disposal_amount' => 'decimal:2',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(AssetCategory::class, 'asset_category_id')->withTrashed();
    }

    public function creditAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'credit_account_id')->withTrashed();
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class)->withTrashed();
    }

    public function disposalCashBankAccount(): BelongsTo
    {
        return $this->belongsTo(CashBankAccount::class, 'disposal_cash_bank_account_id')->withTrashed();
    }

    public function depreciations(): HasMany
    {
        return $this->hasMany(AssetDepreciation::class)->orderBy('period');
    }

    public function journals(): MorphMany
    {
        return $this->morphMany(Journal::class, 'source');
    }

    public function isDisposed(): bool
    {
        return $this->status === self::DISPOSED;
    }

    /** Nilai buku saat ini (Rupiah). */
    public function bookValue(): string
    {
        return number_format((float) $this->acquisition_cost - (float) $this->accumulated_depreciation, 2, '.', '');
    }

    /** "Gudang belakang · Pak Budi" */
    public function holderLabel(): ?string
    {
        $holder = $this->employee?->name ?? $this->holder_name;

        return collect([$this->location, $holder])->filter()->join(' · ') ?: null;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('status'), self::ACTIVE);
    }

    protected function activityLogName(): string
    {
        return 'akuntansi';
    }

    protected function activityLogLabel(): string
    {
        return 'Aset tetap';
    }

    /** @return list<string> */
    protected function activityLogAttributes(): array
    {
        return ['name', 'location', 'employee_id', 'holder_name', 'cost_center_id', 'serial_number', 'description', 'status'];
    }
}

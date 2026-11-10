<?php

namespace App\Models;

use App\Enums\AccountType;
use App\Enums\NormalBalance;
use App\Models\Concerns\RecordsActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Akun di bagan akun (COA). Hanya akun yang aktif dan bukan akun induk (is_header) yang bisa
 * dipakai di jurnal. is_system ditandai otomatis untuk akun yang dipakai jurnal otomatis
 * (config/accounting.php) dan tidak bisa diisi dari form.
 */
class Account extends Model
{
    use HasFactory, RecordsActivity, SoftDeletes;

    protected $fillable = ['code', 'name', 'type', 'normal_balance', 'parent_id', 'is_header', 'is_active', 'description'];

    protected function casts(): array
    {
        return [
            'type' => AccountType::class,
            'normal_balance' => NormalBalance::class,
            'is_header' => 'boolean',
            'is_system' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    /** Akun yang bisa dipakai di baris jurnal. */
    public function scopePostable(Builder $query): Builder
    {
        return $query->where('is_header', false)->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('code');
    }

    public function isPostable(): bool
    {
        return ! $this->is_header && $this->is_active && ! $this->trashed();
    }

    /** "1-1100 Kas" */
    public function label(): string
    {
        return "{$this->code} {$this->name}";
    }

    protected function activityLogName(): string
    {
        return 'akuntansi';
    }

    protected function activityLogLabel(): string
    {
        return 'Akun';
    }

    /** @return list<string> */
    protected function activityLogAttributes(): array
    {
        return ['code', 'name', 'type', 'normal_balance', 'parent_id', 'is_header', 'is_active', 'description'];
    }
}

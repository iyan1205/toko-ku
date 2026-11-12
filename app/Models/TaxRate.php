<?php

namespace App\Models;

use App\Models\Concerns\RecordsActivity;
use App\Models\Concerns\TracksAuthors;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Tarif PPh potong dengan tanggal berlaku. kind: pph23 | pph4_2. Satu kode bisa punya beberapa baris
 * (riwayat tarif); yang dipakai = effective_from terakhir sebelum/pada tanggal dokumen (TaxRateService).
 */
class TaxRate extends Model
{
    use RecordsActivity, SoftDeletes, TracksAuthors;

    public const KINDS = ['pph23' => 'PPh 23', 'pph4_2' => 'PPh 4(2) final'];

    protected $fillable = ['kind', 'code', 'name', 'rate', 'effective_from', 'description'];

    protected function casts(): array
    {
        return ['rate' => 'decimal:2', 'effective_from' => 'date'];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $rate) => $rate->company_id ??= Company::query()->where('is_default', true)->value('id'));
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? $this->kind;
    }

    protected function activityLogName(): string
    {
        return 'pengaturan';
    }

    protected function activityLogLabel(): string
    {
        return 'Tarif pajak';
    }

    /** @return list<string> */
    protected function activityLogAttributes(): array
    {
        return ['kind', 'code', 'name', 'rate', 'effective_from'];
    }
}

<?php

namespace App\Models;

use App\Enums\DocumentType;
use App\Enums\OpeningBalanceKind;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\HasDocumentStatus;
use App\Models\Concerns\TracksAuthors;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

/**
 * Saldo awal pembukuan satu cabang pada tanggal tertentu. Dibuat lewat
 * App\Services\Accounting\OpeningBalanceService: draf bisa diubah; posting membuat satu jurnal
 * (sumber opening_balance, kejadian posted) dengan selisih ke akun Ekuitas Saldo Awal;
 * pembatalan membalik jurnal itu.
 */
class OpeningBalance extends Model
{
    use BelongsToBranch, HasDocumentStatus, SoftDeletes, TracksAuthors;

    protected $fillable = ['balance_date', 'description'];

    protected function casts(): array
    {
        return [
            'balance_date' => 'date',
            'total' => 'decimal:2',
            'posted_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(OpeningBalanceLine::class)->orderBy('id');
    }

    /** @return Collection<int, OpeningBalanceLine> */
    public function linesOf(OpeningBalanceKind $kind): Collection
    {
        return $this->lines->filter(fn (OpeningBalanceLine $line) => $line->kind === $kind)->values();
    }

    public function journals(): MorphMany
    {
        return $this->morphMany(Journal::class, 'source');
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by')->withTrashed();
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by')->withTrashed();
    }

    public function documentType(): DocumentType
    {
        return DocumentType::OpeningBalance;
    }

    public function documentDateColumn(): string
    {
        return 'balance_date';
    }
}

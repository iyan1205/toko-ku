<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use App\Support\DocumentLink;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Jurnal umum. Dibuat hanya lewat App\Services\Accounting\JournalService (debit = kredit
 * divalidasi di sana). Jurnal tidak diubah atau dihapus; koreksi memakai jurnal pembalik.
 *
 * Jurnal manual: source_type null. Jurnal otomatis: source = dokumen asal (penjualan, faktur,
 * retur, pembayaran) dan source_event = kejadiannya (created, voided, approved).
 */
class Journal extends Model
{
    use BelongsToBranch;

    protected $fillable = ['number', 'journal_date', 'description', 'source_type', 'source_id', 'source_event', 'reversal_of_id', 'total', 'user_id'];

    protected function casts(): array
    {
        return [
            'journal_date' => 'date',
            'total' => 'decimal:2',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class)->orderBy('id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }

    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reversal_of_id');
    }

    public function isManual(): bool
    {
        return $this->source_type === null;
    }

    /** Jurnal manual yang belum dibalik dan bukan jurnal pembalik bisa dibalik dari halaman jurnal. */
    public function canBeReversedManually(): bool
    {
        return $this->isManual() && $this->reversal_of_id === null && ! $this->reversal()->exists();
    }

    /** "Manual", "Penjualan", "Pembayaran", ... */
    public function sourceLabel(): string
    {
        if ($this->isManual()) {
            return $this->reversal_of_id ? 'Pembalik manual' : 'Manual';
        }

        return match ($this->source_type) {
            'sale' => 'Penjualan',
            'sale_return' => 'Retur penjualan',
            'purchase' => 'Pembelian',
            'purchase_return' => 'Retur pembelian',
            'payment' => 'Pembayaran',
            default => 'Otomatis',
        }.($this->source_event === 'voided' ? ' (batal)' : '');
    }

    /** Dokumen yang bisa dibuka dari jurnal ini. Jurnal pembayaran membuka dokumen yang dibayar. */
    public function sourceDocument(): ?Model
    {
        $source = $this->source;

        return $source instanceof Payment ? $source->payable : $source;
    }

    public function sourceUrl(?User $user): ?string
    {
        return DocumentLink::url($this->sourceDocument(), $user);
    }

    /** @param array{cari?:?string,sumber?:?string,akun?:?int,dari?:?string,sampai?:?string} $f */
    public function scopeFilter(Builder $query, array $f): Builder
    {
        return $query
            ->when($f['cari'] ?? null, fn (Builder $q, $kata) => $q->where(fn (Builder $w) => $w
                ->where('number', 'like', "%{$kata}%")->orWhere('description', 'like', "%{$kata}%")))
            ->when($f['sumber'] ?? null, fn (Builder $q, $v) => match ($v) {
                'manual' => $q->whereNull('source_type'),
                'otomatis' => $q->whereNotNull('source_type'),
                default => $q->where('source_type', $v),
            })
            ->when($f['akun'] ?? null, fn (Builder $q, $v) => $q->whereHas('lines', fn (Builder $l) => $l->where('account_id', $v)))
            ->when($f['dari'] ?? null, fn (Builder $q, $v) => $q->whereDate('journal_date', '>=', $v))
            ->when($f['sampai'] ?? null, fn (Builder $q, $v) => $q->whereDate('journal_date', '<=', $v));
    }

    /** Jurnal otomatis ikut cabang dokumen sumbernya; jurnal pembalik ikut jurnal yang dibalik. */
    protected function branchSource(): ?array
    {
        return $this->source_type !== null
            ? [$this->source_type, $this->source_id]
            : ($this->reversal_of_id !== null ? [$this->getMorphClass(), $this->reversal_of_id] : null);
    }

    /** Jurnal otomatis dibuat sebagai akibat dokumen yang sudah lolos pemeriksaan cabang. */
    protected function guardsBranchAccess(): bool
    {
        return false;
    }
}

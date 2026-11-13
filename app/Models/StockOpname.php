<?php

namespace App\Models;

use App\Enums\StockOpnameStatus;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\HasApproval;
use App\Services\StockOpnameService;
use App\Services\StockService;
use App\Support\Uang;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Stok opname per gudang. Draft menyimpan hitungan fisik tanpa mengubah stok; saat
 * diselesaikan (final), selisihnya dibukukan lewat StockOpnameService. Status dan kolom
 * finalized_* hanya diubah service itu (tidak fillable).
 */
class StockOpname extends Model
{
    use BelongsToBranch, HasApproval;

    protected $fillable = ['number', 'warehouse_id', 'opname_date', 'note', 'user_id'];

    protected function casts(): array
    {
        return [
            'opname_date' => 'date',
            'status' => StockOpnameStatus::class,
            'finalized_at' => 'datetime',
            'submitted_at' => 'datetime',
            'freeze_stock' => 'boolean',
        ];
    }

    public function approvalDocumentType(): string
    {
        return 'stock_opname';
    }

    public function approvalTitle(): string
    {
        return "{$this->number} · Stok opname {$this->warehouse?->name}";
    }

    public function approvalUrl(): string
    {
        return route('stock-opnames.show', $this);
    }

    /** Nilai selisih (kelebihan + kekurangan, mutlak) dengan biaya rata-rata gudang saat ini. */
    public function approvalAmount(): string
    {
        $stok = app(StockService::class);
        $sen = $this->items()->with('product')->get()->sum(fn (StockOpnameItem $i) => $i->product
            ? Uang::kali(abs(Uang::milli($i->difference)), $stok->averageCost($i->product, $this->warehouse)) : 0);

        return Uang::keDesimal($sen);
    }

    public function approvalValue(string $field): mixed
    {
        return match ($field) {
            'total' => $this->approvalAmount(),
            'items' => $this->items()->where('difference', '!=', 0)->count(),
            default => $this->getAttribute($field),
        };
    }

    public function onApprovalSubmitted(ApprovalRequest $request): void
    {
        $this->forceFill(['status' => StockOpnameStatus::Submitted, 'submitted_at' => now()])->save();
    }

    /** Disetujui penuh: selisih dibukukan atas nama penyetuju terakhir. */
    public function onApprovalApproved(ApprovalRequest $request, User $by): void
    {
        app(StockOpnameService::class)->finalize($this, $by, approved: true);
    }

    /** Ditolak atau ditarik: kembali ke draft supaya bisa dihitung ulang. */
    public function onApprovalRejected(ApprovalRequest $request, User $by, string $note): void
    {
        $this->forceFill(['status' => StockOpnameStatus::Draft, 'submitted_at' => null])->save();
    }

    public function onApprovalCancelled(ApprovalRequest $request, ?User $by): void
    {
        $this->forceFill(['status' => StockOpnameStatus::Draft, 'submitted_at' => null])->save();
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockOpnameItem::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class)->withTrashed();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function finalizedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalized_by')->withTrashed();
    }

    /** Mutasi stok yang dihasilkan opname ini (hanya produk yang selisihnya tidak nol). */
    public function movements(): MorphMany
    {
        return $this->morphMany(StockMovement::class, 'reference');
    }

    public function isDraft(): bool
    {
        return $this->status === StockOpnameStatus::Draft;
    }

    /** @param array{cari?:?string,gudang?:?int,status?:?string,dari?:?string,sampai?:?string} $f */
    public function scopeFilter(Builder $query, array $f): Builder
    {
        return $query
            ->when($f['cari'] ?? null, fn (Builder $q, string $kata) => $q->where(fn (Builder $w) => $w
                ->where('number', 'like', "%{$kata}%")->orWhere('note', 'like', "%{$kata}%")))
            ->when($f['gudang'] ?? null, fn (Builder $q, $v) => $q->where('warehouse_id', $v))
            ->when($f['status'] ?? null, fn (Builder $q, $v) => $q->where('status', $v))
            ->when($f['dari'] ?? null, fn (Builder $q, $v) => $q->whereDate('opname_date', '>=', $v))
            ->when($f['sampai'] ?? null, fn (Builder $q, $v) => $q->whereDate('opname_date', '<=', $v));
    }
}

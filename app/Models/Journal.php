<?php

namespace App\Models;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\HasApproval;
use App\Models\Concerns\HasAttachments;
use App\Models\Concerns\HasDocumentStatus;
use App\Services\Accounting\JournalService;
use App\Support\DocumentLink;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Jurnal umum. Dibuat hanya lewat App\Services\Accounting\JournalService (debit = kredit
 * divalidasi di sana). Jurnal posted tidak diubah atau dihapus; koreksi memakai jurnal pembalik.
 *
 * Jurnal manual: source_type null. Jurnal otomatis: source = dokumen asal (penjualan, faktur,
 * retur, pembayaran) dan source_event = kejadiannya (created, voided, approved).
 *
 * Jurnal manual bisa dijadwalkan dibalik otomatis (auto_reverse_on) dan bisa berasal dari
 * templat jurnal berulang (recurring_journal_id + recurring_period).
 *
 * Status (HasDocumentStatus): jurnal otomatis langsung posted. Jurnal manual bisa disimpan
 * sebagai draf, diajukan ke alur persetujuan (HasApproval, dokumen "journal"), lalu posted
 * otomatis saat disetujui. Hanya jurnal posted yang dihitung di saldo akun.
 */
class Journal extends Model
{
    use BelongsToBranch, HasApproval, HasAttachments, HasDocumentStatus;

    /** Nama jenis dokumen sumber jurnal otomatis (kunci = morph map). */
    public const SOURCE_LABELS = [
        'sale' => 'Penjualan',
        'sale_return' => 'Retur penjualan',
        'purchase' => 'Pembelian',
        'purchase_return' => 'Retur pembelian',
        'payment' => 'Pembayaran',
        'stock_opname' => 'Stok opname',
        'stock_transfer' => 'Transfer stok',
        'stock_movement' => 'Penyesuaian stok',
        'opening_balance' => 'Saldo awal',
    ];

    protected $fillable = ['number', 'journal_date', 'description', 'source_type', 'source_id', 'source_event', 'reversal_of_id', 'total', 'user_id'];

    protected function casts(): array
    {
        return [
            'journal_date' => 'date',
            'auto_reverse_on' => 'date',
            'total' => 'decimal:2',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'posted_at' => 'datetime',
            'cancelled_at' => 'datetime',
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

    public function recurringJournal(): BelongsTo
    {
        return $this->belongsTo(RecurringJournal::class)->withTrashed();
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by')->withTrashed();
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by')->withTrashed();
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by')->withTrashed();
    }

    public function isManual(): bool
    {
        return $this->source_type === null;
    }

    /** Jurnal manual posted yang belum dibalik dan bukan jurnal pembalik bisa dibalik dari halaman jurnal. */
    public function canBeReversedManually(): bool
    {
        return $this->isManual() && $this->isPosted() && $this->reversal_of_id === null && ! $this->reversal()->exists();
    }

    /** "Manual", "Penjualan", "Pembayaran", ... */
    public function sourceLabel(): string
    {
        if ($this->isManual()) {
            return match (true) {
                $this->reversal_of_id !== null => 'Pembalik manual',
                $this->recurring_journal_id !== null => 'Jurnal berulang',
                default => 'Manual',
            };
        }

        return (self::SOURCE_LABELS[$this->source_type] ?? 'Otomatis')
            .(in_array($this->source_event, ['voided', 'void_out', 'void_in'], true) ? ' (batal)' : '');
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

    /** @param array{cari?:?string,sumber?:?string,akun?:?int,status?:?string,dari?:?string,sampai?:?string} $f */
    public function scopeFilter(Builder $query, array $f): Builder
    {
        return $query
            ->when($f['cari'] ?? null, fn (Builder $q, $kata) => $q->where(fn (Builder $w) => $w
                ->where('number', 'like', "%{$kata}%")->orWhere('description', 'like', "%{$kata}%")))
            ->when($f['sumber'] ?? null, fn (Builder $q, $v) => match ($v) {
                'manual' => $q->whereNull('source_type'),
                'otomatis' => $q->whereNotNull('source_type'),
                'berulang' => $q->whereNotNull('recurring_journal_id'),
                default => $q->where('source_type', $v),
            })
            ->when($f['akun'] ?? null, fn (Builder $q, $v) => $q->whereHas('lines', fn (Builder $l) => $l->where('account_id', $v)))
            ->when($f['status'] ?? null, fn (Builder $q, $v) => $q->where('status', $v))
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

    public function documentType(): DocumentType
    {
        return DocumentType::Journal;
    }

    public function documentDateColumn(): string
    {
        return 'journal_date';
    }

    public function approvalDocumentType(): string
    {
        return 'journal';
    }

    public function approvalTitle(): string
    {
        return "{$this->number} · {$this->description}";
    }

    public function approvalUrl(): string
    {
        return route('journals.show', $this);
    }

    public function approvalAmount(): string
    {
        return (string) $this->total;
    }

    /** Disetujui semua tahap: langsung diposting (masuk saldo) atas nama penyetuju terakhir. */
    public function onApprovalApproved(ApprovalRequest $request, User $by): void
    {
        $this->moveToStatus(DocumentStatus::Approved, $by);
        app(JournalService::class)->postDraft($this, $by);
    }
}

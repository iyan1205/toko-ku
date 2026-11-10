<?php

namespace App\Models\Concerns;

use App\Exceptions\AturanBisnisException;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Scopes\BranchScope;
use App\Models\Warehouse;
use App\Support\CurrentBranch;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Dokumen transaksi milik satu cabang (kolom company_id + branch_id).
 *
 *   - Query otomatis disaring ke cabang aktif (BranchScope).
 *   - Saat dibuat, cabang diisi otomatis bila belum diisi, berurutan dari:
 *       1. dokumen asal (branchSource(), misal pembayaran → penjualan yang dibayar),
 *       2. cabang gudangnya (branchWarehouseColumn(), bawaan warehouse_id),
 *       3. cabang aktif di topbar, atau cabang utama bila "Semua cabang"/tanpa login.
 *     company_id mengikuti perusahaan cabang itu.
 *   - Dokumen yang jatuh ke cabang yang tidak boleh diakses pengguna ditolak
 *     (bisa dimatikan per model lewat guardsBranchAccess(), misal kartu stok).
 */
trait BelongsToBranch
{
    public static function bootBelongsToBranch(): void
    {
        static::addGlobalScope(new BranchScope);

        static::creating(function (self $model) {
            $model->assignBranch();
        });
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class)->withTrashed();
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class)->withTrashed();
    }

    /**
     * Dokumen asal yang cabangnya diwarisi: [alias morph, id], atau null.
     *
     * @return array{0: ?string, 1: int|string|null}|null
     */
    protected function branchSource(): ?array
    {
        return null;
    }

    /** Kolom gudang yang menentukan cabang. */
    protected function branchWarehouseColumn(): string
    {
        return 'warehouse_id';
    }

    /** Tolak dokumen yang jatuh ke cabang di luar akses pengguna. */
    protected function guardsBranchAccess(): bool
    {
        return true;
    }

    protected function assignBranch(): void
    {
        $current = app(CurrentBranch::class);

        if ($this->getAttribute('branch_id') === null) {
            $this->setAttribute('branch_id', $this->resolveBranchId($current));
        }

        if ($this->getAttribute('company_id') === null) {
            $this->setAttribute('company_id', $current->companyIdOf((int) $this->getAttribute('branch_id')));
        }

        if ($this->guardsBranchAccess() && ! $current->allows((int) $this->getAttribute('branch_id'))) {
            $tujuan = Branch::withTrashed()->whereKey($this->getAttribute('branch_id'))->value('name') ?? 'cabang lain';

            throw new AturanBisnisException(sprintf(
                'Dokumen ini masuk ke %s, di luar cabang aktif Anda (%s). Ganti cabang aktif di topbar, atau pilih gudang cabang ini.',
                $tujuan,
                $current->isAll() ? 'Semua cabang' : ($current->get()?->name ?? '-'),
            ));
        }
    }

    private function resolveBranchId(CurrentBranch $current): int
    {
        [$type, $id] = $this->branchSource() ?? [null, null];
        $class = $type !== null ? Relation::getMorphedModel($type) : null;

        if ($class !== null && $id !== null) {
            $branchId = $class::query()->withoutGlobalScopes()->whereKey($id)->value('branch_id');
            if ($branchId !== null) {
                return (int) $branchId;
            }
        }

        $warehouseId = $this->getAttribute($this->branchWarehouseColumn());
        if ($warehouseId !== null) {
            $branchId = Warehouse::withTrashed()->whereKey($warehouseId)->value('branch_id');
            if ($branchId !== null) {
                return (int) $branchId;
            }
        }

        return $current->forNewDocument()->getKey();
    }
}

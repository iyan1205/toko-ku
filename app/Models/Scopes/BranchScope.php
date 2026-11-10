<?php

namespace App\Models\Scopes;

use App\Support\CurrentBranch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Batasi data transaksi ke cabang aktif di topbar (atau semua cabang yang boleh diakses bila
 * memilih "Semua cabang"). Tidak membatasi apa pun di console, scheduler, dan queue, kecuali
 * job mengatur CurrentBranch::restrictTo().
 *
 * Kode yang harus melihat semua cabang (cek nomor dokumen, jurnal otomatis) memakai
 * ->withoutGlobalScope(BranchScope::class).
 */
final class BranchScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $ids = app(CurrentBranch::class)->scopeIds();

        if ($ids !== null) {
            $builder->whereIn($model->qualifyColumn('branch_id'), $ids);
        }
    }
}

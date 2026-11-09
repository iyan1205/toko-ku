<?php

namespace App\Support;

use App\Models\Branch;
use Illuminate\Support\Collection;

/**
 * Cabang yang sedang dipilih pengguna di topbar, disimpan di session.
 * Bila belum memilih, atau cabang pilihannya sudah nonaktif/dihapus, dipakai cabang utama.
 *
 * Disiapkan untuk multi-cabang: data transaksi belum dipisah per cabang. Gudang (Fase 5a)
 * dan sesi kasir (Fase 6) akan membaca cabang dari sini.
 *
 * Didaftarkan scoped di AppServiceProvider: dihitung sekali per request.
 */
final class CurrentBranch
{
    public const SESSION_KEY = 'branch_id';

    /** @var Collection<int, Branch>|null */
    private ?Collection $options = null;

    /** @return Collection<int, Branch> cabang aktif, cabang utama paling atas */
    public function options(): Collection
    {
        return $this->options ??= Branch::query()->active()->ordered()->get();
    }

    public function get(): ?Branch
    {
        $selected = session(self::SESSION_KEY);

        return $this->options()->firstWhere('id', $selected)
            ?? $this->options()->firstWhere('is_default', true)
            ?? $this->options()->first();
    }

    public function set(Branch $branch): void
    {
        session([self::SESSION_KEY => $branch->getKey()]);
    }
}

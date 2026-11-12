<?php

namespace App\Services\Accounting;

use App\Enums\DocumentStatus;
use App\Models\Branch;
use App\Support\CurrentBranch;
use App\Support\Uang;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Dasar Buku Besar dan Neraca Saldo: baris jurnal POSTED (draf dan jurnal batal tidak dihitung),
 * disaring cabang aktif di topbar, lalu saringan halaman.
 *
 * Saringan ($f):
 *   cabang      : id cabang (hanya di dalam cabang yang terlihat)
 *   cost_center : id cost center, atau 'tanpa' untuk baris tanpa cost center
 *   dari/sampai : tanggal jurnal (Y-m-d)
 */
class LedgerService
{
    public function __construct(private readonly CurrentBranch $branches) {}

    /**
     * Query builder baris jurnal (alias jl) + jurnal (alias j).
     *
     * @param  array<string, mixed>  $f
     */
    public function lines(array $f): Builder
    {
        $query = DB::table('journal_lines as jl')
            ->join('journals as j', 'j.id', '=', 'jl.journal_id')
            ->where('j.status', DocumentStatus::Posted->value);

        $this->applyFilters($query, $f, 'j', 'jl');

        return $query;
    }

    /**
     * Saringan yang sama untuk query dengan nama tabel lain (misal Eloquent JournalLine + journals).
     *
     * @param  Builder|\Illuminate\Database\Eloquent\Builder  $query
     * @param  array<string, mixed>  $f
     */
    public function applyFilters($query, array $f, string $journals = 'journals', string $lines = 'journal_lines'): void
    {
        // '_semua_cabang' hanya dipakai proses internal (tutup buku); tidak bisa datang dari isian pengguna.
        if (empty($f['_semua_cabang']) && ($scope = $this->branches->scopeIds()) !== null) {
            $query->whereIn("{$journals}.branch_id", $scope ?: [0]);
        }

        if (! empty($f['cabang'])) {
            $query->where("{$journals}.branch_id", (int) $f['cabang']);
        }

        $costCenter = $f['cost_center'] ?? null;
        if ($costCenter === 'tanpa') {
            $query->whereNull("{$lines}.cost_center_id");
        } elseif (! empty($costCenter)) {
            $query->where("{$lines}.cost_center_id", (int) $costCenter);
        }
    }

    /**
     * Batas periode setengah-terbuka [from, until) dengan jam 00:00:00. Kolom tanggal jurnal di
     * SQLite tersimpan beserta jamnya, jadi perbandingan '<= 2026-11-30' akan melewatkan hari
     * terakhir; bentuk ini benar di SQLite maupun MySQL/MariaDB dan tetap memakai index.
     *
     * @return array{from: string, until: string}
     */
    public static function bounds(string $dari, string $sampai): array
    {
        return [
            'from' => date('Y-m-d 00:00:00', strtotime($dari)),
            'until' => date('Y-m-d 00:00:00', strtotime($sampai.' +1 day')),
        ];
    }

    /**
     * Saldo bersih (debit - kredit, sen) satu akun sebelum tanggal $before.
     *
     * @param  array<string, mixed>  $f
     */
    public function netBefore(int $accountId, string $before, array $f): int
    {
        $sum = $this->lines($f)->where('jl.account_id', $accountId)->where('j.journal_date', '<', self::bounds($before, $before)['from'])
            ->selectRaw('COALESCE(SUM(jl.debit), 0) as debit, COALESCE(SUM(jl.credit), 0) as credit')->first();

        return Uang::sen($sum->debit ?? 0) - Uang::sen($sum->credit ?? 0);
    }

    /**
     * Total debit dan kredit satu akun di periode (sen).
     *
     * @param  array<string, mixed>  $f
     * @return array{debit: int, credit: int}
     */
    public function periodTotals(int $accountId, string $from, string $to, array $f): array
    {
        $bounds = self::bounds($from, $to);
        $sum = $this->lines($f)->where('jl.account_id', $accountId)
            ->where('j.journal_date', '>=', $bounds['from'])->where('j.journal_date', '<', $bounds['until'])
            ->selectRaw('COALESCE(SUM(jl.debit), 0) as debit, COALESCE(SUM(jl.credit), 0) as credit')->first();

        return ['debit' => Uang::sen($sum->debit ?? 0), 'credit' => Uang::sen($sum->credit ?? 0)];
    }

    /**
     * Cabang yang bisa dipilih di saringan: hanya bila data yang terlihat mencakup lebih dari satu cabang.
     *
     * @return Collection<int, Branch>
     */
    public function branchOptions(): Collection
    {
        $options = $this->branches->options();
        $scope = $this->branches->scopeIds();

        if ($scope !== null) {
            $options = $options->whereIn('id', $scope)->values();
        }

        return $options->count() > 1 ? $options : $options->take(0);
    }
}

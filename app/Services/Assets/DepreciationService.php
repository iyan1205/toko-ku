<?php

namespace App\Services\Assets;

use App\Exceptions\AturanBisnisException;
use App\Models\AssetDepreciation;
use App\Models\DepreciationRun;
use App\Models\FixedAsset;
use App\Models\Scopes\BranchScope;
use App\Models\User;
use App\Services\Accounting\FiscalPeriodService;
use App\Services\Accounting\JournalService;
use App\Support\CurrentBranch;
use App\Support\Format;
use App\Support\Uang;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Penyusutan bulanan. run($periode) membuat satu DepreciationRun + satu jurnal per cabang:
 * D Beban Penyusutan | K Akumulasi Penyusutan per kategori (dan cost center aset), bertanggal akhir
 * periode. Aset yang tertinggal beberapa bulan (misal scheduler mati) disusutkan susulan sampai
 * periode itu dalam run yang sama, tetap satu baris per bulan. Aman diulang: periode yang sudah
 * disusutkan per aset dilewati.
 *
 * Dijalankan scheduler setiap tanggal 1 untuk bulan sebelumnya (aset:penyusutan) atau dari
 * halaman Penyusutan. Run dibatalkan dengan jurnal pembalik selama belum ada penyusutan sesudahnya.
 */
class DepreciationService
{
    public function __construct(
        private readonly DepreciationCalculator $calculator,
        private readonly JournalService $journals,
        private readonly FixedAssetService $assets,
    ) {}

    /**
     * Pratinjau: penyusutan yang akan dibuat untuk periode ini, per aset.
     *
     * @return Collection<int, array{asset: FixedAsset, rows: list<array{period: string, amount: int, book_value: int}>, total: int}>
     */
    public function preview(string $period, ?array $branchIds = null): Collection
    {
        return $this->eligible($branchIds)
            ->map(fn (FixedAsset $asset) => ['asset' => $asset, 'rows' => $rows = $this->calculator->schedule($asset, $period), 'total' => array_sum(array_column($rows, 'amount'))])
            ->filter(fn (array $item) => $item['total'] > 0)
            ->values();
    }

    /**
     * @param  list<int>|null  $branchIds  null = cabang yang terlihat pengguna (semua bila tanpa login)
     * @return list<DepreciationRun>
     */
    public function run(string $period, ?User $user, ?array $branchIds = null): array
    {
        $end = CarbonImmutable::parse($period.'-01')->endOfMonth()->startOfDay();
        if ($end->startOfMonth()->gt(CarbonImmutable::today()->startOfMonth())) {
            throw new AturanBisnisException('Penyusutan hanya untuk bulan berjalan atau sebelumnya.');
        }
        app(FiscalPeriodService::class)->ensureOpen($end);

        $runs = [];
        foreach ($this->preview($period, $branchIds)->groupBy(fn (array $item) => (int) $item['asset']->branch_id) as $branchId => $items) {
            $runs[] = DB::transaction(function () use ($period, $end, $branchId, $items, $user) {
                $run = new DepreciationRun(['period' => $period]);
                $run->forceFill(['branch_id' => $branchId ?: null, 'status' => DepreciationRun::POSTED, 'created_by' => $user?->getKey()])->save();

                $lines = [];
                $total = 0;
                foreach ($items as $item) {
                    /** @var FixedAsset $asset */
                    $asset = FixedAsset::withoutGlobalScope(BranchScope::class)->whereKey($item['asset']->id)->lockForUpdate()->firstOrFail();
                    $rows = $this->calculator->schedule($asset, $period);
                    foreach ($rows as $row) {
                        $run->items()->create(['fixed_asset_id' => $asset->id, 'period' => $row['period'], 'amount' => Uang::keDesimal($row['amount'])]);
                    }
                    $amount = array_sum(array_column($rows, 'amount'));
                    if ($amount <= 0) {
                        continue;
                    }
                    $total += $amount;
                    $this->assets->refreshAccumulated($asset);

                    $category = $asset->category;
                    foreach ([[$category->expense_account_id, 'debit'], [$category->accumulated_account_id, 'credit']] as [$accountId, $side]) {
                        $key = "{$accountId}|{$asset->cost_center_id}|{$side}";
                        $lines[$key] ??= ['account_id' => $accountId, 'cost_center_id' => $asset->cost_center_id, 'description' => "Penyusutan {$category->name}", 'debit' => 0, 'credit' => 0];
                        $lines[$key][$side] += $amount;
                    }
                }

                $run->forceFill(['total' => Uang::keDesimal($total), 'asset_count' => $items->count()])->save();
                $this->journals->post($end, 'Penyusutan aset tetap '.Format::bulan($end), array_values(array_map(fn (array $l) => [
                    ...$l, 'debit' => Uang::keDesimal($l['debit']), 'credit' => Uang::keDesimal($l['credit']),
                ], $lines)), $user, $run, 'posted', attributes: ['branch_id' => $branchId ?: null]);

                activity('akuntansi')->performedOn($run)->causedBy($user)->event('created')
                    ->withProperties(['periode' => $period, 'aset' => $items->count(), 'total' => $run->total])
                    ->log('Penyusutan aset '.Format::bulan($end).' dihitung ('.$items->count().' aset)');

                return $run;
            });
        }

        return $runs;
    }

    /** Batalkan run: jurnalnya dibalik dan penyusutan per asetnya dihapus. Hanya bila belum ada penyusutan sesudahnya. */
    public function cancel(DepreciationRun $run, User $user, string $reason): DepreciationRun
    {
        return DB::transaction(function () use ($run, $user, $reason) {
            $locked = DepreciationRun::withoutGlobalScope(BranchScope::class)->whereKey($run->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->isCancelled()) {
                throw new AturanBisnisException('Penyusutan ini sudah dibatalkan.');
            }

            $assetIds = $locked->items()->pluck('fixed_asset_id')->unique()->all();
            $latest = $locked->items()->max('period');
            $later = AssetDepreciation::query()->whereIn('fixed_asset_id', $assetIds)->where('period', '>', $latest)->exists();
            $disposed = FixedAsset::withoutGlobalScope(BranchScope::class)->whereIn('id', $assetIds)->where('status', FixedAsset::DISPOSED)->exists();
            if ($later || $disposed) {
                throw new AturanBisnisException('Sudah ada penyusutan sesudah periode ini atau asetnya sudah dilepas. Batalkan penyusutan yang lebih baru dulu.');
            }

            if ($journal = $this->journals->find($locked, 'posted')) {
                $this->journals->reverse($journal, "Pembatalan penyusutan {$locked->period}: {$reason}", $user, $locked, 'voided');
            }
            $locked->items()->delete();
            foreach (FixedAsset::withoutGlobalScope(BranchScope::class)->whereIn('id', $assetIds)->get() as $asset) {
                $this->assets->refreshAccumulated($asset);
            }
            $locked->forceFill(['status' => DepreciationRun::CANCELLED, 'cancelled_at' => now(), 'cancelled_by' => $user->getKey(), 'cancel_reason' => mb_substr($reason, 0, 255)])->save();

            activity('akuntansi')->performedOn($locked)->causedBy($user)->event('cancelled')
                ->withProperties(['alasan' => $reason])->log("Penyusutan {$locked->period} dibatalkan");

            return $locked;
        });
    }

    /** @param list<int>|null $branchIds @return Collection<int, FixedAsset> */
    private function eligible(?array $branchIds): Collection
    {
        $ids = $branchIds ?? app(CurrentBranch::class)->scopeIds();

        return FixedAsset::query()->withoutGlobalScope(BranchScope::class)->active()
            ->when($ids !== null, fn ($q) => $q->whereIn('branch_id', $ids ?: [0]))
            ->with('category')->orderBy('code')->get();
    }
}

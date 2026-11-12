<?php

namespace App\Services\Assets;

use App\Enums\DepreciationMethod;
use App\Models\FixedAsset;
use App\Support\Uang;
use Carbon\CarbonImmutable;

/**
 * Hitungan penyusutan bulanan (sen), tanpa menyimpan apa pun.
 *
 *   garis lurus  : (harga perolehan - nilai sisa) / umur, sama setiap bulan
 *   saldo menurun: nilai buku x tarif tahunan / 12
 *
 * Bulan ke-n dihitung sejak bulan perolehan (aset lama yang dicatat belakangan tetap memakai
 * umurnya sejak dibeli). Bulan terakhir umur ekonomis menghabiskan sisa nilai buku sampai nilai
 * sisa, dan penyusutan tidak pernah membuat nilai buku di bawah nilai sisa.
 */
class DepreciationCalculator
{
    /** Periode berikutnya yang belum disusutkan, "YYYY-MM". */
    public function nextPeriod(FixedAsset $asset): string
    {
        $last = $asset->depreciations()->max('period');

        return $last !== null
            ? CarbonImmutable::parse($last.'-01')->addMonthNoOverflow()->format('Y-m')
            : CarbonImmutable::parse($asset->depreciation_start)->format('Y-m');
    }

    /** Bulan ke berapa (1 = bulan perolehan) periode ini dalam umur aset. */
    public function monthNumber(FixedAsset $asset, string $period): int
    {
        $start = CarbonImmutable::parse($asset->acquisition_date)->startOfMonth();
        $current = CarbonImmutable::parse($period.'-01');

        return (int) $start->diffInMonths($current) + 1;
    }

    /** Penyusutan satu periode untuk nilai buku tertentu (sen). */
    public function amount(FixedAsset $asset, string $period, int $bookValue): int
    {
        $residual = Uang::sen($asset->residual_value);
        $remaining = $bookValue - $residual;
        $month = $this->monthNumber($asset, $period);
        $life = max(1, (int) $asset->useful_life_months);

        if ($remaining <= 0 || $month < 1) {
            return 0;
        }
        if ($month >= $life) {
            return $remaining;
        }

        $amount = $asset->method === DepreciationMethod::DecliningBalance
            ? (int) round($bookValue * (float) $asset->declining_rate / 100 / 12)
            : intdiv(Uang::sen($asset->acquisition_cost) - $residual, $life);

        return max(0, min($amount, $remaining));
    }

    /**
     * Penyusutan dari periode berikutnya sampai $until (termasuk), mulai dari nilai buku saat ini.
     *
     * @return list<array{period: string, amount: int, book_value: int}>
     */
    public function schedule(FixedAsset $asset, string $until, ?string $from = null, ?int $bookValue = null): array
    {
        $period = $from ?? $this->nextPeriod($asset);
        $book = $bookValue ?? (Uang::sen($asset->acquisition_cost) - Uang::sen($asset->accumulated_depreciation));
        $rows = [];

        for ($i = 0; $period <= $until && $i < 1200; $i++) {
            $amount = $this->amount($asset, $period, $book);
            if ($amount <= 0 && $book <= Uang::sen($asset->residual_value)) {
                break;
            }
            $book -= $amount;
            if ($amount > 0) {
                $rows[] = ['period' => $period, 'amount' => $amount, 'book_value' => $book];
            }
            $period = CarbonImmutable::parse($period.'-01')->addMonthNoOverflow()->format('Y-m');
        }

        return $rows;
    }

    /** Jadwal sampai habis umur (untuk kartu aset). @return list<array{period: string, amount: int, book_value: int}> */
    public function remainingSchedule(FixedAsset $asset): array
    {
        $end = CarbonImmutable::parse($asset->acquisition_date)->startOfMonth()->addMonthsNoOverflow(max(1, (int) $asset->useful_life_months) - 1)->format('Y-m');

        return $this->schedule($asset, $end);
    }
}

<?php

namespace App\Console\Commands;

use App\Services\Assets\DepreciationService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/** Dijalankan scheduler tanggal 1 setiap bulan: penyusutan aset untuk bulan sebelumnya (semua cabang). */
class RunDepreciation extends Command
{
    protected $signature = 'aset:penyusutan {--periode= : Periode YYYY-MM, bawaan bulan lalu}';

    protected $description = 'Hitung dan jurnal penyusutan aset tetap untuk satu periode (aman diulang).';

    public function handle(DepreciationService $depreciation): int
    {
        $period = $this->option('periode') ?: CarbonImmutable::today()->subMonthNoOverflow()->format('Y-m');

        try {
            $runs = $depreciation->run($period, null);
        } catch (Throwable $e) {
            $this->error("Penyusutan {$period} gagal: {$e->getMessage()}");
            activity('akuntansi')->event('auto_journal_failed')
                ->withProperties(['dokumen' => "penyusutan {$period}", 'pesan' => $e->getMessage()])
                ->log("Penyusutan aset {$period} gagal dibuat");

            return self::FAILURE;
        }

        $this->info("Penyusutan {$period}: ".count($runs).' jurnal, total Rp '.number_format(collect($runs)->sum(fn ($r) => (float) $r->total), 2, ',', '.').'.');

        return self::SUCCESS;
    }
}

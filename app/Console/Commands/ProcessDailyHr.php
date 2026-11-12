<?php

namespace App\Console\Commands;

use App\Services\Sdm\EmployeeMovementService;
use App\Services\Sdm\OffboardingService;
use Illuminate\Console\Command;

/**
 * Proses SDM harian (routes/console.php, 00:50): terapkan mutasi/promosi yang tanggal efektifnya
 * sudah tiba, dan nonaktifkan karyawan + akun login dari offboarding yang hari terakhirnya sudah lewat.
 * Aman dijalankan berulang.
 */
class ProcessDailyHr extends Command
{
    protected $signature = 'sdm:proses-harian';

    protected $description = 'Terapkan mutasi/promosi yang jatuh tempo dan nonaktifkan karyawan yang offboarding-nya selesai.';

    public function handle(EmployeeMovementService $movements, OffboardingService $offboardings): int
    {
        $this->info('Mutasi diterapkan: '.$movements->applyDue());
        $this->info('Karyawan dinonaktifkan: '.$offboardings->deactivateDue());

        return self::SUCCESS;
    }
}

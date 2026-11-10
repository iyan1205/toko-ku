<?php

namespace Database\Seeders;

use App\Services\Accounting\ChartOfAccountsService;
use Illuminate\Database\Seeder;

/**
 * Bagan akun standar toko retail Indonesia. Aman dijalankan ulang: akun yang sudah ada tidak diubah.
 *   php artisan db:seed --class=ChartOfAccountsSeeder
 */
class ChartOfAccountsSeeder extends Seeder
{
    public function run(ChartOfAccountsService $chart): void
    {
        $created = $chart->install();

        $this->command?->info("{$created} akun standar ditambahkan.");
    }
}

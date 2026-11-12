<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Notifications\HrExpiryReminder;
use App\Services\Sdm\EmployeeContractService;
use App\Services\Sdm\EmployeeDocumentService;
use Illuminate\Console\Command;

/**
 * Pengingat harian (routes/console.php, 07:05) ke pengguna dengan izin sdm.employees.update:
 * kontrak kerja yang berakhir dan dokumen karyawan yang kedaluwarsa dalam setting('sdm.reminder_days')
 * hari (bawaan 30), termasuk yang sudah lewat sampai 30 hari. Tidak mengirim apa pun bila tidak ada.
 */
class SendHrReminders extends Command
{
    protected $signature = 'sdm:pengingat';

    protected $description = 'Kirim pengingat kontrak kerja dan dokumen karyawan yang akan berakhir.';

    public function handle(EmployeeContractService $contracts, EmployeeDocumentService $documents): int
    {
        $days = (int) setting('sdm.reminder_days', 30);
        $c = $contracts->expiring($days)->get();
        $d = $documents->expiring($days)->get();
        if ($c->isEmpty() && $d->isEmpty()) {
            $this->info('Tidak ada kontrak atau dokumen yang akan berakhir.');

            return self::SUCCESS;
        }

        $summary = [
            'contracts' => $c->count(), 'contracts_late' => $c->filter(fn ($x) => $x->daysLeft() < 0)->count(),
            'documents' => $d->count(), 'documents_late' => $d->filter(fn ($x) => $x->daysLeft() < 0)->count(),
            'days' => $days,
        ];
        $users = User::query()->where('is_active', true)->permission('sdm.employees.update')->get();
        foreach ($users as $user) {
            $user->notify(new HrExpiryReminder($summary));
        }
        $this->info("Kontrak: {$summary['contracts']}, dokumen: {$summary['documents']}; dikirim ke {$users->count()} pengguna.");

        return self::SUCCESS;
    }
}

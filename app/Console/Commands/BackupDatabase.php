<?php

namespace App\Console\Commands;

use App\Exceptions\AturanBisnisException;
use App\Services\Settings\DatabaseBackupService;
use Illuminate\Console\Command;

class BackupDatabase extends Command
{
    protected $signature = 'backup:database';

    protected $description = 'Buat cadangan database (mysqldump, gzip) di storage/app/private/backups. Menyimpan '.DatabaseBackupService::KEEP.' berkas terbaru.';

    public function handle(DatabaseBackupService $backups): int
    {
        try {
            $name = $backups->create();
        } catch (AturanBisnisException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        activity('pengaturan')->event('backup_created')
            ->withProperties(['berkas' => $name, 'sumber' => 'jadwal otomatis'])
            ->log("Backup database {$name} dibuat");

        $this->info("Backup tersimpan: {$name}");

        return self::SUCCESS;
    }
}

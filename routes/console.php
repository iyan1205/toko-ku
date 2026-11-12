<?php

use App\Models\ExportFile;
use App\Models\ImportBatch;
use App\Support\QueueHealth;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
| Jadwal tugas. Butuh scheduler aktif di server (docs/DEPLOY.md):
|   * * * * * cd /var/www/toko-ku && php artisan schedule:run >> /dev/null 2>&1
| Status scheduler dan queue worker terlihat di Pengaturan → Sistem.
*/

// Tanda hidup scheduler (Pengaturan → Sistem).
Schedule::call(fn () => QueueHealth::schedulerBeat())->name('schedule-heartbeat')->everyMinute();

// Jurnal berulang yang jatuh tempo dan jurnal yang dijadwalkan dibalik otomatis (Akuntansi).
Schedule::command('akuntansi:jurnal-terjadwal')->dailyAt('00:20')->withoutOverlapping();

// Penyusutan aset tetap bulan lalu (tanggal 1, sebelum backup).
Schedule::command('aset:penyusutan')->monthlyOn(1, '00:40')->withoutOverlapping();

// Pengingat piutang dan hutang yang jatuh tempo (notifikasi lonceng + email).
Schedule::command('keuangan:pengingat-jatuh-tempo')->dailyAt('07:00')->withoutOverlapping();

// Cadangan database harian (Pengaturan → Backup). Menyimpan 14 berkas terbaru.
Schedule::command('backup:database')->dailyAt('01:30');

// Hapus log aktivitas yang lebih tua dari activitylog.delete_records_older_than_days (365 hari).
Schedule::command('activitylog:clean')->dailyAt('02:00');

// Hapus hasil impor > 30 hari dan berkas ekspor besar yang sudah kedaluwarsa.
Schedule::command('model:prune', ['--model' => [ImportBatch::class, ExportFile::class]])->dailyAt('02:15');

// Notifikasi yang sudah dibaca lebih dari 90 hari, dan job gagal lebih dari 30 hari.
Schedule::call(fn () => DB::table('notifications')->whereNotNull('read_at')->where('created_at', '<', now()->subDays(90))->delete())
    ->name('prune-read-notifications')->dailyAt('02:30');
Schedule::command('queue:prune-failed', ['--hours' => 720])->dailyAt('02:45');

<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Hapus log aktivitas yang lebih tua dari activitylog.delete_records_older_than_days (365 hari).
// Butuh scheduler aktif di server: * * * * * php artisan schedule:run
Schedule::command('activitylog:clean')->dailyAt('02:00');

// Cadangan database harian (Pengaturan → Backup). Menyimpan 14 berkas terbaru.
Schedule::command('backup:database')->dailyAt('01:30');

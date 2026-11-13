<?php

namespace App\Console\Commands;

use App\Models\StockLot;
use App\Models\User;
use App\Notifications\ExpiryReminder;
use Illuminate\Console\Command;

/**
 * Pengingat harian (routes/console.php, 07:10) ke pengguna dengan izin inventory.stock.view: batch yang
 * masih bersisa dan kedaluwarsa dalam setting('inventory.expiry_warning_days') hari (bawaan 30) atau sudah lewat.
 */
class SendExpiryReminders extends Command
{
    protected $signature = 'stok:kedaluwarsa';

    protected $description = 'Kirim pengingat batch stok yang mendekati atau sudah lewat tanggal kedaluwarsa.';

    public function handle(): int
    {
        $days = (int) setting('inventory.expiry_warning_days', 30);
        $lots = StockLot::query()->available()->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<=', today()->addDays($days)->toDateString())->get(['id', 'expiry_date']);

        if ($lots->isEmpty()) {
            $this->info('Tidak ada batch yang mendekati kedaluwarsa.');

            return self::SUCCESS;
        }

        $summary = ['lots' => $lots->count(), 'expired' => $lots->filter(fn ($l) => $l->expiry_date->lt(today()))->count(), 'days' => $days];
        $users = User::query()->where('is_active', true)->permission('inventory.stock.view')->get();
        foreach ($users as $user) {
            $user->notify(new ExpiryReminder($summary));
        }
        $this->info("Batch: {$summary['lots']} ({$summary['expired']} sudah lewat); dikirim ke {$users->count()} pengguna.");

        return self::SUCCESS;
    }
}

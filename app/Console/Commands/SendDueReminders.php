<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Notifications\DueReminder;
use App\Services\Receivables\PartyLedgerService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Pengingat jatuh tempo harian (routes/console.php): piutang ke pengguna dengan izin
 * pos.receivables.view, hutang ke pengguna dengan izin purchasing.payables.view. Batas "segera"
 * = setting receivables.reminder_days (bawaan 3 hari). Tidak mengirim apa pun bila tidak ada.
 */
class SendDueReminders extends Command
{
    protected $signature = 'keuangan:pengingat-jatuh-tempo';

    protected $description = 'Kirim ringkasan piutang dan hutang yang jatuh tempo dalam beberapa hari atau sudah lewat.';

    public function handle(PartyLedgerService $ledger): int
    {
        $today = CarbonImmutable::today();
        $days = (int) setting('receivables.reminder_days', 3);

        foreach ([true => 'pos.receivables.view', false => 'purchasing.payables.view'] as $receivable => $permission) {
            $summary = ['soon_count' => 0, 'soon_total' => 0, 'late_count' => 0, 'late_total' => 0, 'days' => $days];

            foreach ($ledger->openItems((bool) $receivable) as $item) {
                if ($item['due_date'] === null) {
                    continue;
                }
                $left = (int) $today->diffInDays(CarbonImmutable::parse($item['due_date']), false);
                if ($left < 0) {
                    $summary['late_count']++;
                    $summary['late_total'] += $item['balance'];
                } elseif ($left <= $days) {
                    $summary['soon_count']++;
                    $summary['soon_total'] += $item['balance'];
                }
            }

            if ($summary['soon_count'] + $summary['late_count'] === 0) {
                continue;
            }

            $users = User::query()->where('is_active', true)->permission($permission)->get();
            foreach ($users as $user) {
                $user->notify(new DueReminder((bool) $receivable, $summary));
            }

            $this->info(($receivable ? 'Piutang' : 'Hutang').": {$summary['late_count']} lewat, {$summary['soon_count']} segera; dikirim ke {$users->count()} pengguna.");
        }

        return self::SUCCESS;
    }
}

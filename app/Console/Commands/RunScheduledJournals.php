<?php

namespace App\Console\Commands;

use App\Enums\DocumentStatus;
use App\Models\Journal;
use App\Models\Scopes\BranchScope;
use App\Services\Accounting\JournalService;
use App\Services\Accounting\RecurringJournalService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Dijalankan scheduler setiap hari (routes/console.php): buat jurnal dari templat jurnal berulang
 * yang jadwalnya tiba, lalu balik jurnal yang dijadwalkan dibalik otomatis (auto_reverse_on).
 * Aman diulang: periode yang sudah dibuat dan jurnal yang sudah dibalik dilewati.
 */
class RunScheduledJournals extends Command
{
    protected $signature = 'akuntansi:jurnal-terjadwal {--tanggal= : Jalankan seolah-olah tanggal ini (Y-m-d), bawaan hari ini}';

    protected $description = 'Buat jurnal berulang yang jatuh tempo dan balik jurnal yang dijadwalkan dibalik otomatis.';

    public function handle(RecurringJournalService $recurring, JournalService $journals): int
    {
        $today = $this->option('tanggal') ? CarbonImmutable::parse($this->option('tanggal'))->startOfDay() : CarbonImmutable::today();

        $result = $recurring->runDue($today);
        $this->info("Jurnal berulang: {$result['created']} jurnal dibuat, {$result['failed']} templat gagal.");

        $reversed = 0;
        $failed = 0;
        Journal::query()->withoutGlobalScope(BranchScope::class)
            ->withStatus(DocumentStatus::Posted)
            ->whereNotNull('auto_reverse_on')
            ->where('auto_reverse_on', '<', $today->addDay()->format('Y-m-d 00:00:00'))
            ->whereNull('reversal_of_id')
            ->whereDoesntHave('reversal')
            ->orderBy('id')
            ->each(function (Journal $journal) use ($journals, &$reversed, &$failed) {
                try {
                    $journals->reverseScheduled($journal) && $reversed++;
                } catch (Throwable $e) {
                    $failed++;
                    Log::warning("Balik otomatis jurnal {$journal->number} gagal", ['error' => $e->getMessage()]);
                    activity('akuntansi')->performedOn($journal)->event('auto_journal_failed')
                        ->withProperties(['dokumen' => "jurnal {$journal->number}", 'pesan' => $e->getMessage()])
                        ->log("Jurnal {$journal->number} gagal dibalik otomatis");
                }
            });
        $this->info("Balik otomatis: {$reversed} jurnal dibalik, {$failed} gagal.");

        return self::SUCCESS;
    }
}

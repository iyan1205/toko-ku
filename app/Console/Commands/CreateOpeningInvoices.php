<?php

namespace App\Console\Commands;

use App\Enums\DocumentStatus;
use App\Enums\OpeningBalanceKind;
use App\Models\Invoice;
use App\Models\OpeningBalance;
use App\Models\Scopes\BranchScope;
use App\Models\User;
use App\Services\Receivables\InvoiceService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Sekali jalan setelah memasang Fase 10: saldo awal yang diposting sebelumnya belum punya faktur
 * saldo awal, sehingga piutang/hutangnya belum bisa dilunasi lewat Pelunasan dan belum masuk umur
 * piutang/hutang. Perintah ini membuatkannya. Aman diulang: saldo awal yang sudah punya faktur dilewati.
 */
class CreateOpeningInvoices extends Command
{
    protected $signature = 'keuangan:faktur-saldo-awal';

    protected $description = 'Buat faktur saldo awal untuk piutang/hutang dari saldo awal yang diposting sebelum Fase 10.';

    public function handle(InvoiceService $invoices): int
    {
        $created = 0;

        OpeningBalance::query()->withoutGlobalScope(BranchScope::class)
            ->withStatus(DocumentStatus::Posted)
            ->whereHas('lines', fn ($q) => $q->whereIn('kind', [OpeningBalanceKind::Receivable->value, OpeningBalanceKind::Payable->value]))
            ->whereNotIn('id', Invoice::withoutGlobalScope(BranchScope::class)->withTrashed()->whereNotNull('opening_balance_id')->select('opening_balance_id'))
            ->with('lines')
            ->orderBy('id')
            ->each(function (OpeningBalance $opening) use ($invoices, &$created) {
                $user = $opening->postedBy ?? $opening->creator ?? User::query()->orderBy('id')->first();
                DB::transaction(fn () => $invoices->createOpeningInvoices($opening, $user));
                $count = Invoice::withoutGlobalScope(BranchScope::class)->where('opening_balance_id', $opening->id)->count();
                $created += $count;
                $this->line("{$opening->number}: {$count} faktur saldo awal dibuat.");
            });

        $this->info($created > 0 ? "Selesai: {$created} faktur saldo awal dibuat." : 'Tidak ada saldo awal yang perlu dibuatkan faktur.');

        return self::SUCCESS;
    }
}

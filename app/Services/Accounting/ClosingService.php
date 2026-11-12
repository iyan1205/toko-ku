<?php

namespace App\Services\Accounting;

use App\Enums\AccountType;
use App\Enums\CashBankKind;
use App\Enums\DocumentStatus;
use App\Exceptions\AturanBisnisException;
use App\Models\Account;
use App\Models\BankStatement;
use App\Models\CashBankAccount;
use App\Models\CashTransaction;
use App\Models\FiscalYearClosing;
use App\Models\Invoice;
use App\Models\Journal;
use App\Models\RecurringJournal;
use App\Models\Scopes\BranchScope;
use App\Models\User;
use App\Services\Assets\DepreciationService;
use App\Support\Format;
use App\Support\Uang;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Tutup buku.
 *
 *   checklist(bulan) : hal yang sebaiknya beres sebelum bulan dikunci: jurnal/transaksi kas/faktur
 *                      yang masih draf, rekonsiliasi bank, penyusutan, dan jurnal berulang yang jatuh tempo.
 *   closeYear(tahun) : jurnal penutup per cabang per 31 Desember: saldo pendapatan, HPP, dan beban tahun
 *                      itu dinolkan ke Laba Ditahan. Bisa sekaligus mengunci semua bulan tahun itu.
 *   cancelYear       : jurnal penutup dibalik per 31 Desember (bulan Desember harus dibuka dulu).
 */
class ClosingService
{
    public function __construct(
        private readonly JournalService $journals,
        private readonly AccountMappingService $mapping,
        private readonly FinancialReportService $reports,
        private readonly FiscalPeriodService $periods,
    ) {}

    /** @return list<array{label: string, ok: bool, detail: string, url: ?string}> */
    public function checklist(int $year, int $month): array
    {
        $start = CarbonImmutable::create($year, $month, 1);
        ['from' => $from, 'until' => $until] = LedgerService::bounds($start->toDateString(), $start->endOfMonth()->toDateString());
        $inMonth = fn ($q, string $column) => $q->where($column, '>=', $from)->where($column, '<', $until);
        $pending = [DocumentStatus::Draft, DocumentStatus::Submitted, DocumentStatus::Approved];

        $journals = $inMonth(Journal::query()->withStatus(...$pending), 'journal_date')->count();
        $cash = $inMonth(CashTransaction::query()->withStatus(...$pending), 'transaction_date')->count();
        $invoices = $inMonth(Invoice::query()->withStatus(DocumentStatus::Draft), 'invoice_date')->count();

        $banks = CashBankAccount::query()->available()->active()->where('kind', CashBankKind::Bank->value)->get();
        $unreconciled = $banks->filter(fn (CashBankAccount $r) => ! BankStatement::query()->where('cash_bank_account_id', $r->id)->where('status', BankStatement::FINALIZED)
            ->whereDate('period_end', '>=', $start->endOfMonth()->toDateString())->exists());

        $depreciation = app(DepreciationService::class)->preview($start->format('Y-m'));
        $recurring = RecurringJournal::query()->where('is_active', true)->whereNotNull('next_run_on')->whereDate('next_run_on', '<=', $start->endOfMonth()->toDateString())->count();

        return [
            ['label' => 'Jurnal umum sudah diposting', 'ok' => $journals === 0, 'detail' => $journals === 0 ? 'Tidak ada draf atau pengajuan.' : "{$journals} jurnal masih draf/menunggu persetujuan.",
                'url' => route('journals.index', ['status' => 'draft', 'dari' => $start->toDateString(), 'sampai' => $start->endOfMonth()->toDateString()])],
            ['label' => 'Transaksi kas & bank sudah diposting', 'ok' => $cash === 0, 'detail' => $cash === 0 ? 'Tidak ada draf atau pengajuan.' : "{$cash} transaksi kas masih draf/menunggu persetujuan.",
                'url' => route('cash-transactions.index', ['dari' => $start->toDateString(), 'sampai' => $start->endOfMonth()->toDateString()])],
            ['label' => 'Faktur & tagihan manual sudah diposting', 'ok' => $invoices === 0, 'detail' => $invoices === 0 ? 'Tidak ada draf.' : "{$invoices} faktur/tagihan masih draf.", 'url' => null],
            ['label' => 'Rekonsiliasi bank sampai akhir bulan', 'ok' => $unreconciled->isEmpty(),
                'detail' => $banks->isEmpty() ? 'Tidak ada rekening bank.' : ($unreconciled->isEmpty() ? 'Semua rekening bank sudah direkonsiliasi dan dikunci.' : 'Belum: '.$unreconciled->pluck('name')->join(', ').'.'),
                'url' => route('bank-reconciliations.index')],
            ['label' => 'Penyusutan aset sudah dihitung', 'ok' => $depreciation->isEmpty(),
                'detail' => $depreciation->isEmpty() ? 'Semua aset aktif sudah disusutkan sampai bulan ini.' : $depreciation->count().' aset belum disusutkan ('.Format::rupiah(Uang::keDesimal($depreciation->sum('total'))).').',
                'url' => route('depreciation.index', ['periode' => $start->format('Y-m')])],
            ['label' => 'Jurnal berulang sudah dibuat', 'ok' => $recurring === 0, 'detail' => $recurring === 0 ? 'Tidak ada yang tertunda.' : "{$recurring} templat jatuh tempo belum dibuat jurnalnya.",
                'url' => route('recurring-journals.index')],
        ];
    }

    public function closingFor(int $year): ?FiscalYearClosing
    {
        return FiscalYearClosing::query()->where('fiscal_year', $year)->where('status', FiscalYearClosing::POSTED)->first();
    }

    /** Laba bersih tahun itu per cabang (sen), tanpa jurnal penutup. @return array<int, int> branch_id => laba */
    public function profitByBranch(int $year): array
    {
        $result = [];
        foreach ($this->branchIds($year) as $branchId) {
            $result[$branchId] = $this->reports->profitOf($this->reports->sums(['cabang' => $branchId, '_semua_cabang' => true], "{$year}-01-01", "{$year}-12-31", [FinancialReportService::CLOSING]));
        }

        return $result;
    }

    public function closeYear(int $year, User $user, bool $lockMonths = true): FiscalYearClosing
    {
        if ($this->closingFor($year)) {
            throw new AturanBisnisException("Tahun {$year} sudah ditutup.");
        }
        if (CarbonImmutable::create($year, 12, 31)->gt(CarbonImmutable::today())) {
            throw new AturanBisnisException("Tahun {$year} belum berakhir.");
        }
        $date = "{$year}-12-31";
        $this->periods->ensureOpen($date);
        $retained = $this->mapping->require('retained_earnings');

        return DB::transaction(function () use ($year, $user, $lockMonths, $date, $retained) {
            $closing = new FiscalYearClosing(['fiscal_year' => $year]);
            $closing->forceFill(['status' => FiscalYearClosing::POSTED, 'created_by' => $user->getKey()])->save();
            $total = 0;

            foreach ($this->branchIds($year) as $branchId) {
                $sums = $this->reports->sums(['cabang' => $branchId, '_semua_cabang' => true], "{$year}-01-01", $date, [FinancialReportService::CLOSING]);
                $lines = [];
                $profit = 0;
                foreach (Account::withTrashed()->whereIn('id', array_keys($sums))->whereIn('type', [AccountType::Revenue->value, AccountType::Cogs->value, AccountType::Expense->value])->get() as $account) {
                    $net = $sums[$account->id]['debit'] - $sums[$account->id]['credit'];
                    if ($net === 0) {
                        continue;
                    }
                    $profit -= $net;
                    $lines[] = ['account_id' => $account->id, 'description' => 'Penutupan '.$year, 'debit' => $net < 0 ? Uang::keDesimal(-$net) : '0', 'credit' => $net > 0 ? Uang::keDesimal($net) : '0'];
                }
                if ($lines === []) {
                    continue;
                }
                $lines[] = ['account_id' => $retained->id, 'description' => $profit >= 0 ? "Laba {$year}" : "Rugi {$year}",
                    'debit' => $profit < 0 ? Uang::keDesimal(-$profit) : '0', 'credit' => $profit > 0 ? Uang::keDesimal($profit) : '0'];
                $this->journals->post($date, "Jurnal penutup tahun {$year}", $lines, $user, $closing, "close-b{$branchId}", attributes: ['branch_id' => $branchId]);
                $total += $profit;
            }

            $closing->forceFill(['net_income' => Uang::keDesimal($total)])->save();

            if ($lockMonths) {
                for ($m = 1; $m <= 12; $m++) {
                    if (! $this->periods->isClosed(CarbonImmutable::create($year, $m, 1))) {
                        $this->periods->close($year, $m, $user, "Tutup buku {$year}");
                    }
                }
            }

            activity('akuntansi')->performedOn($closing)->causedBy($user)->event('year_closed')
                ->withProperties(['tahun' => $year, 'laba' => $closing->net_income, 'kunci_bulan' => $lockMonths])
                ->log("Tutup buku {$year}: laba ".Format::rupiah($closing->net_income).' dipindah ke Laba Ditahan');

            return $closing;
        });
    }

    public function cancelYear(FiscalYearClosing $closing, User $user, string $reason): FiscalYearClosing
    {
        $date = "{$closing->fiscal_year}-12-31";
        $this->periods->ensureOpen($date);

        return DB::transaction(function () use ($closing, $user, $reason, $date) {
            $locked = FiscalYearClosing::query()->whereKey($closing->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->isCancelled()) {
                throw new AturanBisnisException('Tutup buku ini sudah dibatalkan.');
            }

            foreach (Journal::withoutGlobalScope(BranchScope::class)->where('source_type', $locked->getMorphClass())->where('source_id', $locked->id)
                ->whereNull('reversal_of_id')->whereDoesntHave('reversal')->get() as $journal) {
                $this->journals->reverse($journal, "Batal tutup buku {$locked->fiscal_year}: {$reason}", $user, $locked, str_replace('close', 'void', $journal->source_event), $date);
            }
            $locked->forceFill(['status' => FiscalYearClosing::CANCELLED, 'cancelled_at' => now(), 'cancelled_by' => $user->getKey(), 'cancel_reason' => mb_substr($reason, 0, 255)])->save();

            activity('akuntansi')->performedOn($locked)->causedBy($user)->event('year_reopened')
                ->withProperties(['tahun' => $locked->fiscal_year, 'alasan' => $reason])->log("Tutup buku {$locked->fiscal_year} dibatalkan");

            return $locked;
        });
    }

    /** @return list<int> cabang yang punya jurnal posted di tahun itu */
    private function branchIds(int $year): array
    {
        ['from' => $from, 'until' => $until] = LedgerService::bounds("{$year}-01-01", "{$year}-12-31");

        return Journal::withoutGlobalScope(BranchScope::class)->withStatus(DocumentStatus::Posted)
            ->where('journal_date', '>=', $from)->where('journal_date', '<', $until)
            ->distinct()->orderBy('branch_id')->pluck('branch_id')->map(fn ($id) => (int) $id)->all();
    }
}

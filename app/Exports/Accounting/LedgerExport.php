<?php

namespace App\Exports\Accounting;

use App\Enums\DocumentStatus;
use App\Enums\NormalBalance;
use App\Exports\ListExport;
use App\Models\Account;
use App\Models\Branch;
use App\Models\CostCenter;
use App\Models\JournalLine;
use App\Services\Accounting\LedgerService;
use App\Support\Format;
use App\Support\Uang;
use Illuminate\Database\Eloquent\Builder;

/**
 * Buku besar satu akun: mutasi posted di periode dengan saldo berjalan. Saldo berjalan dihitung
 * database (window function) di atas semua baris yang tersaring, jadi tetap benar di setiap
 * halaman dan di ekspor. Saldo mengikuti saldo normal akun (akun bersaldo normal kredit: kredit - debit).
 */
class LedgerExport extends ListExport
{
    private ?Account $account = null;

    private ?int $opening = null;

    public function documentTitle(): string
    {
        return 'Buku Besar'.($this->account() ? ' '.$this->account()->label() : '');
    }

    public function account(): ?Account
    {
        return $this->account ??= isset($this->filters['akun']) ? Account::withTrashed()->find($this->filters['akun']) : null;
    }

    /** Saldo sebelum periode (sen, mengikuti saldo normal). */
    public function openingBalance(): int
    {
        if ($this->opening === null) {
            $account = $this->account();
            $net = $account ? app(LedgerService::class)->netBefore($account->id, $this->filters['dari'], $this->filters) : 0;
            $this->opening = $this->signed($net);
        }

        return $this->opening;
    }

    /** @return array{debit: int, credit: int, closing: int} total periode dan saldo akhir (sen) */
    public function totals(): array
    {
        $account = $this->account();
        $totals = $account
            ? app(LedgerService::class)->periodTotals($account->id, $this->filters['dari'], $this->filters['sampai'], $this->filters)
            : ['debit' => 0, 'credit' => 0];

        return $totals + ['closing' => $this->openingBalance() + $this->signed($totals['debit'] - $totals['credit'])];
    }

    /** Saldo berjalan baris ini (sen). */
    public function runningBalance(JournalLine $line): int
    {
        return $this->openingBalance() + $this->signed(Uang::sen($line->getAttribute('running_net') ?? 0));
    }

    protected function baseQuery(): Builder
    {
        $f = $this->filters;
        $bounds = LedgerService::bounds($f['dari'], $f['sampai']);
        $query = JournalLine::query()
            ->join('journals', 'journals.id', '=', 'journal_lines.journal_id')
            ->where('journals.status', DocumentStatus::Posted->value)
            ->where('journal_lines.account_id', (int) ($f['akun'] ?? 0))
            ->where('journals.journal_date', '>=', $bounds['from'])
            ->where('journals.journal_date', '<', $bounds['until'])
            ->select('journal_lines.*', 'journals.number as journal_number', 'journals.journal_date', 'journals.description as journal_description', 'journals.source_type as journal_source_type')
            ->selectRaw('SUM(journal_lines.debit - journal_lines.credit) OVER (ORDER BY journals.journal_date, journals.id, journal_lines.id) as running_net')
            ->with('costCenter:id,code,name');

        app(LedgerService::class)->applyFilters($query, $f);

        return $query;
    }

    protected function defaultOrder(Builder $query): void
    {
        $query->orderBy('journals.journal_date')->orderBy('journals.id')->orderBy('journal_lines.id');
    }

    protected function columns(): array
    {
        return [
            ['label' => 'Tanggal', 'value' => fn (JournalLine $l) => $l->journal_date, 'type' => 'date'],
            ['label' => 'Nomor Jurnal', 'value' => fn (JournalLine $l) => $l->journal_number],
            ['label' => 'Keterangan', 'value' => fn (JournalLine $l) => trim($l->journal_description.($l->description ? ' — '.$l->description : ''))],
            ['label' => 'Cost Center', 'value' => fn (JournalLine $l) => $l->costCenter?->label()],
            ['label' => 'Debit', 'value' => fn (JournalLine $l) => (float) $l->debit > 0 ? $l->debit : null, 'type' => 'money'],
            ['label' => 'Kredit', 'value' => fn (JournalLine $l) => (float) $l->credit > 0 ? $l->credit : null, 'type' => 'money'],
            ['label' => 'Saldo', 'value' => fn (JournalLine $l) => Uang::keDesimal($this->runningBalance($l)), 'type' => 'money'],
        ];
    }

    protected function filterSummary(): array
    {
        $f = $this->filters;

        return array_filter([
            'Akun' => $this->account()?->label(),
            'Periode' => Format::tanggal($f['dari']).' s.d. '.Format::tanggal($f['sampai']),
            'Cabang' => ! empty($f['cabang']) ? Branch::withTrashed()->find($f['cabang'])?->name : null,
            'Cost center' => match (true) {
                ($f['cost_center'] ?? null) === 'tanpa' => 'Tanpa cost center',
                ! empty($f['cost_center']) => CostCenter::withTrashed()->find($f['cost_center'])?->label(),
                default => null,
            },
            'Saldo awal' => Format::rupiah(Uang::keDesimal($this->openingBalance())),
        ]);
    }

    private function signed(int $net): int
    {
        return $this->account()?->normal_balance === NormalBalance::Credit ? -$net : $net;
    }
}

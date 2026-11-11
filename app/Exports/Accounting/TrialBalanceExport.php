<?php

namespace App\Exports\Accounting;

use App\Enums\AccountType;
use App\Exports\ListExport;
use App\Models\Account;
use App\Models\Branch;
use App\Models\CostCenter;
use App\Services\Accounting\LedgerService;
use App\Support\Format;
use App\Support\Uang;
use Illuminate\Database\Eloquent\Builder;

/**
 * Neraca saldo: per akun transaksi, saldo awal (sebelum periode), mutasi debit/kredit di periode,
 * dan saldo akhir, masing-masing di kolom debit atau kredit. Hanya jurnal posted. Tanpa saringan
 * cost center, total debit selalu sama dengan total kredit.
 */
class TrialBalanceExport extends ListExport
{
    public function documentTitle(): string
    {
        return 'Neraca Saldo';
    }

    /** Saldo bersih awal (debit - kredit, sen). */
    public static function opening(Account $account): int
    {
        return Uang::sen($account->getAttribute('opening_debit') ?? 0) - Uang::sen($account->getAttribute('opening_credit') ?? 0);
    }

    /** Saldo bersih akhir (debit - kredit, sen). */
    public static function closing(Account $account): int
    {
        return self::opening($account) + Uang::sen($account->getAttribute('period_debit') ?? 0) - Uang::sen($account->getAttribute('period_credit') ?? 0);
    }

    /**
     * Kolom angka satu akun (sen).
     *
     * @return array{opening_debit: int, opening_credit: int, period_debit: int, period_credit: int, closing_debit: int, closing_credit: int}
     */
    public static function figures(Account $account): array
    {
        $opening = self::opening($account);
        $closing = self::closing($account);

        return [
            'opening_debit' => max(0, $opening),
            'opening_credit' => max(0, -$opening),
            'period_debit' => Uang::sen($account->getAttribute('period_debit') ?? 0),
            'period_credit' => Uang::sen($account->getAttribute('period_credit') ?? 0),
            'closing_debit' => max(0, $closing),
            'closing_credit' => max(0, -$closing),
        ];
    }

    protected function baseQuery(): Builder
    {
        $f = $this->filters;
        ['from' => $dari, 'until' => $sampai] = LedgerService::bounds($f['dari'], $f['sampai']);

        $sums = app(LedgerService::class)->lines($f)
            ->where('j.journal_date', '<', $sampai)
            ->groupBy('jl.account_id')
            ->select('jl.account_id')
            ->selectRaw('SUM(CASE WHEN j.journal_date < ? THEN jl.debit ELSE 0 END) as opening_debit', [$dari])
            ->selectRaw('SUM(CASE WHEN j.journal_date < ? THEN jl.credit ELSE 0 END) as opening_credit', [$dari])
            ->selectRaw('SUM(CASE WHEN j.journal_date >= ? THEN jl.debit ELSE 0 END) as period_debit', [$dari])
            ->selectRaw('SUM(CASE WHEN j.journal_date >= ? THEN jl.credit ELSE 0 END) as period_credit', [$dari]);

        return Account::query()
            ->where('accounts.is_header', false)
            ->leftJoinSub($sums, 'tb', 'tb.account_id', '=', 'accounts.id')
            ->select('accounts.*', 'tb.opening_debit', 'tb.opening_credit', 'tb.period_debit', 'tb.period_credit')
            ->when(! ($f['nol'] ?? false), fn (Builder $q) => $q->whereNotNull('tb.account_id')
                ->where(fn (Builder $w) => $w->whereRaw('ROUND(COALESCE(tb.opening_debit, 0) - COALESCE(tb.opening_credit, 0), 2) <> 0')
                    ->orWhere('tb.period_debit', '<>', 0)->orWhere('tb.period_credit', '<>', 0)))
            ->when($f['tipe'] ?? null, fn (Builder $q, string $v) => $q->where('accounts.type', $v));
    }

    protected function defaultOrder(Builder $query): void
    {
        $query->orderBy('accounts.code');
    }

    protected function columns(): array
    {
        $money = fn (string $key) => fn (Account $a) => ($v = self::figures($a)[$key]) === 0 ? null : Uang::keDesimal($v);

        return [
            ['label' => 'Kode', 'value' => fn (Account $a) => $a->code],
            ['label' => 'Nama Akun', 'value' => fn (Account $a) => $a->name],
            ['label' => 'Kelompok', 'value' => fn (Account $a) => $a->type->label()],
            ['label' => 'Saldo Awal Debit', 'value' => $money('opening_debit'), 'type' => 'money'],
            ['label' => 'Saldo Awal Kredit', 'value' => $money('opening_credit'), 'type' => 'money'],
            ['label' => 'Mutasi Debit', 'value' => $money('period_debit'), 'type' => 'money'],
            ['label' => 'Mutasi Kredit', 'value' => $money('period_credit'), 'type' => 'money'],
            ['label' => 'Saldo Akhir Debit', 'value' => $money('closing_debit'), 'type' => 'money'],
            ['label' => 'Saldo Akhir Kredit', 'value' => $money('closing_credit'), 'type' => 'money'],
        ];
    }

    protected function filterSummary(): array
    {
        $f = $this->filters;

        return array_filter([
            'Periode' => Format::tanggal($f['dari']).' s.d. '.Format::tanggal($f['sampai']),
            'Cabang' => ! empty($f['cabang']) ? Branch::withTrashed()->find($f['cabang'])?->name : null,
            'Cost center' => match (true) {
                ($f['cost_center'] ?? null) === 'tanpa' => 'Tanpa cost center',
                ! empty($f['cost_center']) => CostCenter::withTrashed()->find($f['cost_center'])?->label(),
                default => null,
            },
            'Kelompok' => isset($f['tipe']) ? AccountType::tryFrom($f['tipe'])?->label() : null,
        ]);
    }
}

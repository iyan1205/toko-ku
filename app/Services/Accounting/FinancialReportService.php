<?php

namespace App\Services\Accounting;

use App\Enums\AccountType;
use App\Enums\CashFlowGroup;
use App\Models\Account;
use App\Support\Format;
use App\Support\Uang;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Laporan keuangan dari jurnal posted (LedgerService: cabang aktif, saringan cabang/cost center).
 *
 * Hasilnya struktur tabel yang sama untuk layar, Excel, dan PDF:
 *   ['title', 'subtitle', 'columns' => [label...], 'rows' => [[type, label, values[], links[], account_id]], 'check' => ?[ok, message]]
 *   type: section | account | total | grand | note. Nilai dalam sen, tanda mengikuti jenis bagiannya
 *   (akun kontra seperti Retur Penjualan atau Akumulasi Penyusutan tampil negatif).
 *
 * Jurnal penutup tahun (fiscal_year_closing) tidak dihitung di Laba Rugi dan Arus Kas, tetapi dihitung
 * di Neraca (laba sudah pindah ke Laba Ditahan). Jurnal saldo awal tidak dihitung sebagai arus kas;
 * kas dari saldo awal masuk Kas awal periode.
 */
class FinancialReportService
{
    public const CLOSING = 'fiscal_year_closing';

    public const OPENING = 'opening_balance';

    /** @var Collection<int, Account>|null */
    private ?Collection $accounts = null;

    public function __construct(private readonly LedgerService $ledger) {}

    /**
     * Kolom periode: single, monthly (per bulan, maks 12), previous (periode sebelumnya), last_year.
     *
     * @return list<array{label: string, from: string, to: string}>
     */
    public function periodColumns(string $mode, string $from, string $to): array
    {
        $start = CarbonImmutable::parse($from);
        $end = CarbonImmutable::parse($to);
        $label = fn (CarbonImmutable $a, CarbonImmutable $b) => $a->isSameDay($a->startOfMonth()) && $b->isSameDay($b->endOfMonth()->startOfDay()) && $a->isSameMonth($b)
            ? Format::bulan($a) : Format::tanggal($a).' – '.Format::tanggal($b);

        return match ($mode) {
            'monthly' => collect(range(0, min(11, (int) $start->startOfMonth()->diffInMonths($end->startOfMonth()))))->map(function (int $i) use ($start, $end) {
                $m = $start->startOfMonth()->addMonthsNoOverflow($i);

                return ['label' => Format::bulan($m), 'from' => max($m, $start)->toDateString(), 'to' => min($m->endOfMonth(), $end)->toDateString()];
            })->all(),
            'previous' => [
                ['label' => $label($start, $end), 'from' => $start->toDateString(), 'to' => $end->toDateString()],
                (function () use ($start, $end, $label) {
                    $days = (int) $start->diffInDays($end) + 1;
                    $prevEnd = $start->subDay();
                    $prevStart = $start->isSameDay($start->startOfMonth()) && $end->isSameDay($end->endOfMonth()->startOfDay())
                        ? $start->subMonthsNoOverflow((int) $start->diffInMonths($end->addDay()))->startOfMonth() : $prevEnd->subDays($days - 1);

                    return ['label' => $label($prevStart, $prevEnd), 'from' => $prevStart->toDateString(), 'to' => $prevEnd->toDateString()];
                })(),
            ],
            'last_year' => [
                ['label' => $label($start, $end), 'from' => $start->toDateString(), 'to' => $end->toDateString()],
                ['label' => $label($start->subYear(), $end->subYear()), 'from' => $start->subYear()->toDateString(), 'to' => $end->subYear()->toDateString()],
            ],
            default => [['label' => $label($start, $end), 'from' => $start->toDateString(), 'to' => $end->toDateString()]],
        };
    }

    /**
     * @param  array<string, mixed>  $f
     * @param  list<array{label: string, from: string, to: string}>  $columns
     * @param  bool  $difference  tambah kolom Selisih (kolom 1 - kolom 2)
     */
    public function incomeStatement(array $f, array $columns, bool $difference = false): array
    {
        $sums = array_map(fn (array $c) => $this->sums($f, $c['from'], $c['to'], [self::CLOSING]), $columns);
        $rows = [];
        $sections = [];
        foreach ([[AccountType::Revenue, 'Pendapatan', 1], [AccountType::Cogs, 'Harga pokok penjualan', -1], [AccountType::Expense, 'Beban', -1]] as [$type, $title, $sign]) {
            $rows[] = ['type' => 'section', 'label' => $title];
            $sections[$type->value] = $this->accountRows($rows, $type, $sums, $columns, $f, $sign);
            $rows[] = ['type' => 'total', 'label' => "Jumlah {$this->lower($title)}", 'values' => $sections[$type->value]];
            if ($type === AccountType::Cogs) {
                $rows[] = ['type' => 'grand', 'label' => 'Laba kotor', 'values' => $this->combine($sections['revenue'], $sections['cogs'], -1)];
            }
        }
        $net = $this->combine($this->combine($sections['revenue'], $sections['cogs'], -1), $sections['expense'], -1);
        $rows[] = ['type' => 'grand', 'label' => 'Laba (rugi) bersih', 'values' => $net];

        return $this->withDifference(['title' => 'Laba Rugi', 'columns' => array_column($columns, 'label'), 'rows' => $rows], $difference && count($columns) === 2);
    }

    /** @param array<string, mixed> $f */
    public function balanceSheet(array $f, string $asOf, ?string $compareAsOf = null): array
    {
        $dates = array_values(array_filter([$asOf, $compareAsOf]));
        $rows = [];
        $sections = [];
        $columns = array_map(fn (string $d) => ['label' => 'Per '.Format::tanggal($d), 'from' => CarbonImmutable::parse($d)->startOfYear()->toDateString(), 'to' => $d], $dates);
        $sums = array_map(fn (string $d) => $this->sums($f, null, $d), $dates);

        foreach ([[AccountType::Asset, 'Aset', 1], [AccountType::Liability, 'Liabilitas', 1], [AccountType::Equity, 'Ekuitas', 1]] as [$type, $title]) {
            $rows[] = ['type' => 'section', 'label' => $title];
            $sections[$type->value] = $this->accountRows($rows, $type, $sums, $columns, $f, 1);
            if ($type === AccountType::Equity) {
                $prior = [];
                $current = [];
                foreach ($dates as $i => $d) {
                    $yearStart = CarbonImmutable::parse($d)->startOfYear()->toDateString();
                    $prior[$i] = $this->profitOf($this->sums($f, null, CarbonImmutable::parse($yearStart)->subDay()->toDateString()));
                    $current[$i] = $this->profitOf($this->sums($f, $yearStart, $d));
                }
                if (array_filter($prior)) {
                    $rows[] = ['type' => 'account', 'label' => 'Laba tahun-tahun lalu (belum ditutup)', 'values' => $prior];
                }
                $rows[] = ['type' => 'account', 'label' => 'Laba (rugi) tahun berjalan', 'values' => $current];
                $sections['equity'] = $this->combine($this->combine($sections['equity'], $prior, 1), $current, 1);
            }
            $rows[] = ['type' => 'total', 'label' => "Jumlah {$this->lower($title)}", 'values' => $sections[$type->value]];
        }
        $right = $this->combine($sections['liability'], $sections['equity'], 1);
        $rows[] = ['type' => 'grand', 'label' => 'Jumlah liabilitas dan ekuitas', 'values' => $right];
        $ok = $right === $sections['asset'];

        return ['title' => 'Neraca', 'columns' => array_column($columns, 'label'), 'rows' => $rows,
            'check' => ['ok' => $ok, 'message' => $ok ? 'Seimbang: aset = liabilitas + ekuitas.' : 'Tidak seimbang. Periksa jurnal yang tidak seimbang.']];
    }

    /** @param array<string, mixed> $f */
    public function cashFlow(array $f, string $from, string $to): array
    {
        $exclude = [self::CLOSING, self::OPENING];
        $period = $this->sums($f, $from, $to, $exclude);
        $accounts = $this->accounts();
        $cashIds = $accounts->filter(fn (Account $a) => $a->is_cash_bank)->keys()->all();
        $link = fn (Account $a) => route('ledger.index', array_filter(['akun' => $a->id, 'dari' => $from, 'sampai' => $to, 'cabang' => $f['cabang'] ?? null]));

        $rows = [['type' => 'section', 'label' => CashFlowGroup::Operating->label()]];
        $net = $this->profitOf($period);
        $rows[] = ['type' => 'account', 'label' => 'Laba (rugi) bersih', 'values' => [$net]];
        $groups = ['operating' => $net, 'investing' => 0, 'financing' => 0];
        $lines = ['operating' => [], 'investing' => [], 'financing' => []];

        foreach ($accounts as $account) {
            if (in_array($account->id, $cashIds, true) || ! in_array($account->type, [AccountType::Asset, AccountType::Liability, AccountType::Equity], true)) {
                continue;
            }
            $effect = ($period[$account->id]['credit'] ?? 0) - ($period[$account->id]['debit'] ?? 0);
            if ($effect === 0) {
                continue;
            }
            $group = ($account->cash_flow ?? $this->defaultCashFlow($account))->value;
            $groups[$group] += $effect;
            $lines[$group][] = ['type' => 'account', 'label' => ($effect > 0 ? 'Kenaikan/penerimaan ' : 'Penurunan/pembayaran ').$account->name, 'values' => [$effect], 'links' => [$link($account)], 'account_id' => $account->id];
        }

        foreach (['operating', 'investing', 'financing'] as $key) {
            if ($key !== 'operating') {
                $rows[] = ['type' => 'section', 'label' => CashFlowGroup::from($key)->label()];
            }
            array_push($rows, ...$lines[$key]);
            $rows[] = ['type' => 'total', 'label' => 'Kas bersih dari '.$this->lower(CashFlowGroup::from($key)->label()), 'values' => [$groups[$key]]];
        }

        $change = array_sum($groups);
        $before = $this->sums($f, null, CarbonImmutable::parse($from)->subDay()->toDateString());
        $openingInPeriod = $this->sums($f, $from, $to, [], [self::OPENING]);
        $beginning = $this->cashOf($before, $cashIds) + $this->cashOf($openingInPeriod, $cashIds);
        $ending = $this->cashOf($this->sums($f, null, $to), $cashIds);
        $rows[] = ['type' => 'grand', 'label' => 'Kenaikan (penurunan) kas', 'values' => [$change]];
        $rows[] = ['type' => 'account', 'label' => 'Kas dan bank awal periode', 'values' => [$beginning]];
        $rows[] = ['type' => 'grand', 'label' => 'Kas dan bank akhir periode', 'values' => [$ending]];
        $ok = $beginning + $change === $ending;

        return ['title' => 'Arus Kas', 'columns' => [Format::tanggal($from).' – '.Format::tanggal($to)], 'rows' => $rows,
            'check' => ['ok' => $ok, 'message' => $ok ? 'Cocok dengan saldo kas dan bank.' : 'Selisih dengan saldo kas dan bank. Periksa akun kas/bank di Bagan Akun.']];
    }

    /** @param array<string, mixed> $f */
    public function equityStatement(array $f, string $from, string $to): array
    {
        $before = $this->sums($f, null, CarbonImmutable::parse($from)->subDay()->toDateString());
        $period = $this->sums($f, $from, $to);
        $rows = [];
        $total = [0, 0, 0, 0];

        foreach ($this->accounts()->filter(fn (Account $a) => $a->type === AccountType::Equity) as $account) {
            $opening = ($before[$account->id]['credit'] ?? 0) - ($before[$account->id]['debit'] ?? 0);
            $add = $period[$account->id]['credit'] ?? 0;
            $less = $period[$account->id]['debit'] ?? 0;
            if ($opening === 0 && $add === 0 && $less === 0) {
                continue;
            }
            $values = [$opening, $add, $less, $opening + $add - $less];
            $rows[] = ['type' => 'account', 'label' => "{$account->code} {$account->name}", 'values' => $values, 'account_id' => $account->id,
                'links' => [null, route('ledger.index', array_filter(['akun' => $account->id, 'dari' => $from, 'sampai' => $to, 'cabang' => $f['cabang'] ?? null])), null, null]];
            $total = array_map(fn ($a, $b) => $a + $b, $total, $values);
        }

        $profit = $this->profitOf($this->sums($f, $from, $to, [self::CLOSING]));
        $closing = $this->profitOf($this->sums($f, $from, $to, [], [self::CLOSING]));
        $opening = $this->profitOf($before);
        $values = [$opening, max(0, $profit) + max(0, $closing), max(0, -$profit) + max(0, -$closing), $opening + $profit + $closing];
        $rows[] = ['type' => 'account', 'label' => 'Laba (rugi) yang belum ditutup', 'values' => $values];
        $total = array_map(fn ($a, $b) => $a + $b, $total, $values);
        $rows[] = ['type' => 'grand', 'label' => 'Jumlah ekuitas', 'values' => $total];

        return ['title' => 'Perubahan Ekuitas', 'columns' => ['Saldo awal', 'Penambahan', 'Pengurangan', 'Saldo akhir'], 'rows' => $rows,
            'note' => 'Laba (rugi) periode ini '.Format::rupiah(Uang::keDesimal($profit)).'. Tutup buku tahunan memindahkan laba ke Laba Ditahan.'];
    }

    /**
     * Saldo per akun (sen) dari jurnal posted. $exclude: sumber jurnal yang tidak dihitung; $only: hanya sumber ini.
     *
     * @param  array<string, mixed>  $f
     * @param  list<string>  $exclude
     * @param  list<string>  $only
     * @return array<int, array{debit: int, credit: int}>
     */
    public function sums(array $f, ?string $from, string $to, array $exclude = [], array $only = []): array
    {
        $bounds = LedgerService::bounds($from ?? '1900-01-01', $to);
        $query = $this->ledger->lines($f)->where('j.journal_date', '<', $bounds['until'])
            ->when($from !== null, fn ($q) => $q->where('j.journal_date', '>=', $bounds['from']))
            ->when($exclude !== [], fn ($q) => $q->where(fn ($w) => $w->whereNull('j.source_type')->orWhereNotIn('j.source_type', $exclude)))
            ->when($only !== [], fn ($q) => $q->whereIn('j.source_type', $only))
            ->groupBy('jl.account_id')
            ->selectRaw('jl.account_id, COALESCE(SUM(jl.debit), 0) as debit, COALESCE(SUM(jl.credit), 0) as credit');

        return $query->get()->mapWithKeys(fn ($r) => [(int) $r->account_id => [
            'debit' => Uang::sen($r->debit), 'credit' => Uang::sen($r->credit),
        ]])->all();
    }

    /** Laba bersih (pendapatan - HPP - beban) dari saldo akun. @param array<int, array{debit: int, credit: int}> $sums */
    public function profitOf(array $sums): int
    {
        $profit = 0;
        foreach ($this->accounts() as $account) {
            if (in_array($account->type, [AccountType::Revenue, AccountType::Cogs, AccountType::Expense], true)) {
                $profit += ($sums[$account->id]['credit'] ?? 0) - ($sums[$account->id]['debit'] ?? 0);
            }
        }

        return $profit;
    }

    public function defaultCashFlow(Account $account): CashFlowGroup
    {
        return match (true) {
            $account->type === AccountType::Equity => CashFlowGroup::Financing,
            $account->type === AccountType::Liability && str_starts_with($account->code, '2-2') => CashFlowGroup::Financing,
            $account->type === AccountType::Asset && str_starts_with($account->code, '1-2') && $account->normal_balance->value === 'debit' => CashFlowGroup::Investing,
            default => CashFlowGroup::Operating,
        };
    }

    /**
     * Tambahkan baris akun satu jenis; kembalikan total per kolom.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  list<array<int, array{debit: int, credit: int}>>  $sums
     * @param  list<array{label: string, from: string, to: string}>  $columns
     * @param  array<string, mixed>  $f
     * @return list<int>
     */
    private function accountRows(array &$rows, AccountType $type, array $sums, array $columns, array $f, int $sign): array
    {
        $totals = array_fill(0, count($columns), 0);
        $debitNatural = in_array($type, [AccountType::Asset, AccountType::Cogs, AccountType::Expense], true);

        foreach ($this->accounts()->filter(fn (Account $a) => $a->type === $type) as $account) {
            $values = array_map(function (array $s) use ($account, $debitNatural) {
                $net = ($s[$account->id]['debit'] ?? 0) - ($s[$account->id]['credit'] ?? 0);

                return $debitNatural ? $net : -$net;
            }, $sums);
            if (! array_filter($values)) {
                continue;
            }
            $rows[] = ['type' => 'account', 'label' => "{$account->code} {$account->name}", 'values' => $values, 'account_id' => $account->id,
                'links' => array_map(fn (array $c) => route('ledger.index', array_filter(['akun' => $account->id, 'dari' => $c['from'], 'sampai' => $c['to'], 'cabang' => $f['cabang'] ?? null, 'cost_center' => $f['cost_center'] ?? null])), $columns)];
            $totals = array_map(fn ($t, $v) => $t + $v, $totals, $values);
        }

        return $totals;
    }

    /** @param array<int, array{debit: int, credit: int}> $sums @param list<int> $cashIds */
    private function cashOf(array $sums, array $cashIds): int
    {
        return array_sum(array_map(fn (int $id) => ($sums[$id]['debit'] ?? 0) - ($sums[$id]['credit'] ?? 0), $cashIds));
    }

    /** @param list<int> $a @param list<int> $b @return list<int> */
    private function combine(array $a, array $b, int $sign): array
    {
        return array_map(fn ($x, $y) => $x + $sign * $y, $a, $b);
    }

    /** Kolom Selisih (kolom 1 - kolom 2) untuk laporan pembanding. */
    private function withDifference(array $report, bool $compare): array
    {
        if (! $compare) {
            return $report;
        }
        $report['columns'][] = 'Selisih';
        foreach ($report['rows'] as &$row) {
            if (isset($row['values'])) {
                $row['values'][] = $row['values'][0] - $row['values'][1];
                if (isset($row['links'])) {
                    $row['links'][] = null;
                }
            }
        }

        return $report;
    }

    private function lower(string $text): string
    {
        return mb_strtolower($text);
    }

    /** @return Collection<int, Account> */
    private function accounts(): Collection
    {
        return $this->accounts ??= Account::withTrashed()->where('is_header', false)->orderBy('code')->get()->keyBy('id');
    }
}

<?php

namespace App\Services\Assets;

use App\Enums\AccountType;
use App\Enums\NormalBalance;
use App\Models\Account;
use App\Models\Budget;
use App\Models\User;
use App\Services\Accounting\LedgerService;
use App\Support\Uang;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Anggaran per akun per bulan, dan perbandingannya dengan realisasi (jurnal posted, cabang dan cost
 * center anggaran). Realisasi mengikuti saldo normal akun: beban = debit - kredit, pendapatan =
 * kredit - debit. Peringatan bila beban/HPP melebihi anggaran atau pendapatan di bawah target.
 */
class BudgetService
{
    public function __construct(private readonly LedgerService $ledger) {}

    /**
     * @param  array<string, mixed>  $data  name, fiscal_year, branch_id, cost_center_id, notes, lines: [account_id => [bulan => nilai]]
     */
    public function save(array $data, User $user, ?Budget $budget = null): Budget
    {
        $year = (int) $data['fiscal_year'];
        $duplicate = Budget::query()->where('fiscal_year', $year)
            ->where('branch_id', ($data['branch_id'] ?? null) ?: null)->where('cost_center_id', ($data['cost_center_id'] ?? null) ?: null)
            ->when($budget, fn ($q) => $q->whereKeyNot($budget->id))->first();
        if ($duplicate !== null) {
            throw ValidationException::withMessages(['fiscal_year' => "Sudah ada anggaran {$year} untuk cakupan ini ({$duplicate->name}). Ubah anggaran itu."]);
        }

        $accounts = Account::query()->postable()->whereIn('type', [AccountType::Revenue->value, AccountType::Cogs->value, AccountType::Expense->value])
            ->whereIn('id', array_map('intval', array_keys($data['lines'] ?? [])) ?: [0])->pluck('id')->all();
        $rows = [];
        foreach ($data['lines'] ?? [] as $accountId => $months) {
            if (! in_array((int) $accountId, $accounts, true)) {
                continue;
            }
            foreach ((array) $months as $month => $value) {
                $sen = Uang::sen($value ?? 0);
                if ($sen < 0) {
                    throw ValidationException::withMessages(["lines.{$accountId}.{$month}" => 'Nilai anggaran tidak boleh negatif.']);
                }
                if ($sen > 0 && $month >= 1 && $month <= 12) {
                    $rows[] = ['account_id' => (int) $accountId, 'month' => (int) $month, 'amount' => Uang::keDesimal($sen)];
                }
            }
        }
        if ($rows === []) {
            throw ValidationException::withMessages(['lines' => 'Isi nilai anggaran untuk minimal satu akun.']);
        }

        return DB::transaction(function () use ($data, $year, $rows, $user, $budget) {
            $budget ??= new Budget;
            $budget->fill([
                'name' => trim($data['name']),
                'fiscal_year' => $year,
                'branch_id' => ($data['branch_id'] ?? null) ?: null,
                'cost_center_id' => ($data['cost_center_id'] ?? null) ?: null,
                'notes' => $data['notes'] ?? null,
            ])->save();
            $budget->lines()->delete();
            $budget->lines()->createMany($rows);

            activity('akuntansi')->performedOn($budget)->causedBy($user)->event($budget->wasRecentlyCreated ? 'created' : 'updated')
                ->withProperties(['tahun' => $year, 'total' => Uang::keDesimal(array_sum(array_map(fn ($r) => Uang::sen($r['amount']), $rows)))])
                ->log("Anggaran {$budget->name} {$year} disimpan");

            return $budget;
        });
    }

    /**
     * Anggaran vs realisasi bulan $from..$to (sen).
     *
     * @return array{rows: list<array{account: Account, budget: int, actual: int, variance: int, percent: ?float, warning: bool}>, groups: array<string, array{budget: int, actual: int}>, from: string, to: string}
     */
    public function report(Budget $budget, int $fromMonth = 1, int $toMonth = 12): array
    {
        $from = CarbonImmutable::create($budget->fiscal_year, $fromMonth, 1)->toDateString();
        $to = CarbonImmutable::create($budget->fiscal_year, $toMonth, 1)->endOfMonth()->toDateString();
        $budget->loadMissing('lines.account');

        $filters = array_filter(['cabang' => $budget->branch_id, 'cost_center' => $budget->cost_center_id]);
        $rows = [];
        $groups = [];
        foreach ($budget->lines->groupBy('account_id') as $lines) {
            $account = $lines->first()->account;
            $planned = $lines->filter(fn ($l) => $l->month >= $fromMonth && $l->month <= $toMonth)->sum(fn ($l) => Uang::sen($l->amount));
            $totals = $this->ledger->periodTotals($account->id, $from, $to, $filters);
            $actual = $account->normal_balance === NormalBalance::Debit ? $totals['debit'] - $totals['credit'] : $totals['credit'] - $totals['debit'];
            $isIncome = $account->type === AccountType::Revenue;

            $rows[] = [
                'account' => $account,
                'budget' => $planned,
                'actual' => $actual,
                'variance' => $actual - $planned,
                'percent' => $planned > 0 ? round($actual / $planned * 100, 1) : null,
                'warning' => $isIncome ? $planned > 0 && $actual < $planned : $actual > $planned,
            ];
            $group = $account->type->label();
            $groups[$group]['budget'] = ($groups[$group]['budget'] ?? 0) + $planned;
            $groups[$group]['actual'] = ($groups[$group]['actual'] ?? 0) + $actual;
        }
        usort($rows, fn ($a, $b) => strcmp($a['account']->code, $b['account']->code));

        return ['rows' => $rows, 'groups' => $groups, 'from' => $from, 'to' => $to];
    }
}

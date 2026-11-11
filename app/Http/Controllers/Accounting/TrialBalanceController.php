<?php

namespace App\Http\Controllers\Accounting;

use App\Enums\AccountType;
use App\Exports\Accounting\TrialBalanceExport;
use App\Exports\ListExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\LedgerFilterRequest;
use App\Models\CostCenter;
use App\Services\Accounting\LedgerService;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/** Neraca saldo: saldo awal, mutasi, dan saldo akhir semua akun (izin accounting.trial-balance.*). */
class TrialBalanceController extends Controller
{
    public function index(LedgerFilterRequest $request, LedgerService $ledger): View
    {
        $filters = $request->filters();
        $rows = TrialBalanceExport::fromRequest($request, $filters)->query()->get();

        $figures = $rows->map(fn ($account) => ['account' => $account] + TrialBalanceExport::figures($account));
        $sum = fn ($items) => collect(['opening_debit', 'opening_credit', 'period_debit', 'period_credit', 'closing_debit', 'closing_credit'])
            ->mapWithKeys(fn (string $key) => [$key => $items->sum($key)])->all();

        return view('accounting.trial-balance.index', [
            'filters' => $filters,
            'groups' => collect(AccountType::cases())
                ->map(fn (AccountType $type) => ['type' => $type, 'rows' => $figures->filter(fn ($row) => $row['account']->type === $type)->values()])
                ->filter(fn ($group) => $group['rows']->isNotEmpty())
                ->map(fn ($group) => $group + ['total' => $sum($group['rows'])]),
            'total' => $sum($figures),
            'branches' => $ledger->branchOptions(),
            'costCenters' => CostCenter::query()->ordered()->get(['id', 'code', 'name', 'is_active']),
        ]);
    }

    public function export(LedgerFilterRequest $request): Response
    {
        $format = ListExport::formatFrom($request);

        return TrialBalanceExport::fromRequest($request, $request->filters())->download($format);
    }
}

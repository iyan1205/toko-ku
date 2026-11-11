<?php

namespace App\Http\Controllers\Accounting;

use App\Exports\Accounting\LedgerExport;
use App\Exports\ListExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\LedgerFilterRequest;
use App\Models\Account;
use App\Models\CostCenter;
use App\Services\Accounting\LedgerService;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/** Buku besar per akun: saldo awal, mutasi posted, dan saldo berjalan (izin accounting.ledger.*). */
class LedgerController extends Controller
{
    public function index(LedgerFilterRequest $request, LedgerService $ledger): View
    {
        $filters = $request->filters();
        $list = LedgerExport::fromRequest($request, $filters);
        $account = $list->account();

        return view('accounting.ledger.index', [
            'filters' => $filters,
            'account' => $account,
            'lines' => $account ? $list->paginate(50) : null,
            'list' => $list,
            'opening' => $account ? $list->openingBalance() : 0,
            'totals' => $account ? $list->totals() : null,
            'accounts' => Account::query()->where('is_header', false)->ordered()->get(['id', 'code', 'name', 'type', 'is_active']),
            'branches' => $ledger->branchOptions(),
            'costCenters' => CostCenter::query()->ordered()->get(['id', 'code', 'name', 'is_active']),
        ]);
    }

    public function export(LedgerFilterRequest $request): Response
    {
        $format = ListExport::formatFrom($request);
        $filters = $request->filters();

        if (empty($filters['akun'])) {
            throw ValidationException::withMessages(['akun' => 'Pilih akun yang buku besarnya diekspor.']);
        }

        return LedgerExport::fromRequest($request, $filters)->download($format);
    }
}

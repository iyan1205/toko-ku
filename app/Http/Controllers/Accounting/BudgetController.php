<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\BudgetRequest;
use App\Models\Account;
use App\Models\Branch;
use App\Models\Budget;
use App\Models\CostCenter;
use App\Services\Assets\BudgetService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Anggaran per akun per bulan dan laporan anggaran vs realisasi (izin accounting.budgets.*). */
class BudgetController extends Controller
{
    public function __construct(private readonly BudgetService $budgets) {}

    public function index(Request $request): View
    {
        $year = (int) ($request->validate(['tahun' => ['nullable', 'integer', 'between:2000,2100']])['tahun'] ?? now()->year);

        return view('accounting.budgets.index', [
            'year' => $year,
            'budgets' => Budget::query()->visible()->where('fiscal_year', $year)->with(['branch:id,name', 'costCenter:id,code,name'])->withSum('lines', 'amount')->orderBy('name')->get(),
        ]);
    }

    public function create(Request $request): View
    {
        $copy = $request->integer('salin') ? Budget::query()->visible()->with('lines')->find($request->integer('salin')) : null;
        $budget = new Budget(['fiscal_year' => $copy ? $copy->fiscal_year + 1 : now()->year, 'name' => $copy ? $copy->name : 'Anggaran '.now()->year, 'branch_id' => $copy?->branch_id, 'cost_center_id' => $copy?->cost_center_id]);
        $budget->setRelation('lines', $copy?->lines ?? collect());

        return view('accounting.budgets.form', $this->formData($budget));
    }

    public function store(BudgetRequest $request): RedirectResponse
    {
        $budget = $this->budgets->save($request->validated(), $request->user());

        return redirect()->route('budgets.show', $budget)->with('success', "Anggaran {$budget->name} tersimpan.");
    }

    public function show(Request $request, Budget $budget): View
    {
        abort_unless(Budget::query()->visible()->whereKey($budget->id)->exists(), 404);
        $range = $request->validate(['dari' => ['nullable', 'integer', 'between:1,12'], 'sampai' => ['nullable', 'integer', 'between:1,12', 'gte:dari']]);
        $from = (int) ($range['dari'] ?? 1);
        $to = (int) ($range['sampai'] ?? ($budget->fiscal_year === now()->year ? now()->month : 12));

        return view('accounting.budgets.show', [
            'budget' => $budget->load(['branch', 'costCenter']),
            'report' => $this->budgets->report($budget, $from, $to),
            'fromMonth' => $from,
            'toMonth' => $to,
        ]);
    }

    public function edit(Budget $budget): View
    {
        abort_unless(Budget::query()->visible()->whereKey($budget->id)->exists(), 404);

        return view('accounting.budgets.form', $this->formData($budget->load('lines')));
    }

    public function update(BudgetRequest $request, Budget $budget): RedirectResponse
    {
        $this->budgets->save($request->validated(), $request->user(), $budget);

        return redirect()->route('budgets.show', $budget)->with('success', "Anggaran {$budget->name} diperbarui.");
    }

    public function destroy(Budget $budget): RedirectResponse
    {
        $budget->delete();

        return redirect()->route('budgets.index', ['tahun' => $budget->fiscal_year])->with('success', "Anggaran {$budget->name} dihapus.");
    }

    /** @return array<string, mixed> */
    private function formData(Budget $budget): array
    {
        return [
            'budget' => $budget,
            'accounts' => Account::query()->postable()->whereIn('type', ['revenue', 'cogs', 'expense'])->ordered()->get(['id', 'code', 'name', 'type']),
            'branches' => Branch::query()->active()->ordered()->pluck('name', 'id'),
            'costCenters' => CostCenter::query()->active()->ordered()->get(['id', 'code', 'name'])->mapWithKeys(fn ($c) => [$c->id => $c->label()]),
        ];
    }
}

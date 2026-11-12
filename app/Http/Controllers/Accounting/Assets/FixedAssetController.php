<?php

namespace App\Http\Controllers\Accounting\Assets;

use App\Exports\Accounting\FixedAssetsExport;
use App\Exports\ListExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\FixedAssetRequest;
use App\Models\Account;
use App\Models\AssetCategory;
use App\Models\CashBankAccount;
use App\Models\CostCenter;
use App\Models\Employee;
use App\Models\FixedAsset;
use App\Rules\OpenPeriod;
use App\Services\Accounting\AccountMappingService;
use App\Services\Assets\DepreciationCalculator;
use App\Services\Assets\FixedAssetService;
use App\Support\CurrentBranch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/** Aset tetap: daftar, catat, kartu aset, ubah keterangan, hapus, dan pelepasan (izin accounting.assets.*). */
class FixedAssetController extends Controller
{
    public function __construct(private readonly FixedAssetService $assets) {}

    public function index(Request $request): View
    {
        $filters = $this->filter($request);
        $list = FixedAssetsExport::fromRequest($request, $filters);

        return view('accounting.assets.index', [
            'assets' => $list->paginate(20),
            'categories' => AssetCategory::query()->orderBy('name')->pluck('name', 'id'),
            'summary' => FixedAsset::query()->active()->selectRaw('COUNT(*) as jumlah, COALESCE(SUM(acquisition_cost), 0) as harga, COALESCE(SUM(accumulated_depreciation), 0) as akumulasi')->first(),
        ]);
    }

    public function export(Request $request): Response
    {
        $format = ListExport::formatFrom($request);

        return FixedAssetsExport::fromRequest($request, $this->filter($request))->download($format);
    }

    public function create(CurrentBranch $branches): View
    {
        $rekening = CashBankAccount::query()->available()->active()->with('account:id,code,name')->ordered()->get();
        $equity = app(AccountMappingService::class)->account('opening_equity');

        return view('accounting.assets.form', [
            'asset' => new FixedAsset(['acquisition_date' => now(), 'residual_value' => 0]),
            'categories' => AssetCategory::query()->active()->orderBy('name')->get(),
            'creditAccounts' => $rekening->mapWithKeys(fn ($r) => [$r->account_id => $r->account?->code.' '.$r->name.' (dibayar dari rekening ini)'])
                ->union(Account::query()->postable()->where('type', 'liability')->ordered()->get()->mapWithKeys(fn ($a) => [$a->id => $a->code.' '.$a->name.' (belum dibayar)']))
                ->union($equity ? [$equity->id => $equity->code.' '.$equity->name.' (aset lama / saldo awal)'] : []),
            'employees' => Employee::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id'),
            'costCenters' => CostCenter::query()->active()->ordered()->get(['id', 'code', 'name'])->mapWithKeys(fn ($c) => [$c->id => $c->label()]),
            'branchName' => $branches->forNewDocument()->name,
        ]);
    }

    public function store(FixedAssetRequest $request): RedirectResponse
    {
        $asset = $this->assets->create($request->validated(), $request->user());

        return redirect()->route('fixed-assets.show', $asset)->with('success', "Aset {$asset->code} {$asset->name} dicatat dan dijurnal.");
    }

    public function show(FixedAsset $fixedAsset, DepreciationCalculator $calculator): View
    {
        return view('accounting.assets.show', [
            'asset' => $fixedAsset->load(['category.assetAccount', 'category.accumulatedAccount', 'category.expenseAccount', 'employee', 'costCenter', 'creditAccount', 'branch', 'disposalCashBankAccount', 'depreciations.run', 'creator']),
            'schedule' => $fixedAsset->isDisposed() ? [] : $calculator->remainingSchedule($fixedAsset),
            'rekening' => CashBankAccount::query()->available()->active()->ordered()->get(['id', 'name', 'kind']),
        ]);
    }

    public function edit(FixedAsset $fixedAsset): View
    {
        return view('accounting.assets.edit', [
            'asset' => $fixedAsset,
            'employees' => Employee::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id'),
            'costCenters' => CostCenter::query()->active()->ordered()->get(['id', 'code', 'name'])->mapWithKeys(fn ($c) => [$c->id => $c->label()]),
        ]);
    }

    public function update(FixedAssetRequest $request, FixedAsset $fixedAsset): RedirectResponse
    {
        $this->assets->update($fixedAsset, $request->validated());

        return redirect()->route('fixed-assets.show', $fixedAsset)->with('success', "Aset {$fixedAsset->code} diperbarui.");
    }

    public function destroy(Request $request, FixedAsset $fixedAsset): RedirectResponse
    {
        $request->validate(['alasan' => ['required', 'string', 'min:5', 'max:200']], [], ['alasan' => 'alasan']);
        $this->assets->delete($fixedAsset, $request->user(), $request->input('alasan'));

        return redirect()->route('fixed-assets.index')->with('success', "Aset {$fixedAsset->code} dihapus dan jurnal perolehannya dibalik.");
    }

    public function dispose(Request $request, FixedAsset $fixedAsset): RedirectResponse
    {
        $data = $request->validate([
            'disposed_at' => ['required', 'date', 'before_or_equal:today', new OpenPeriod],
            'disposal_type' => ['required', Rule::in(['sold', 'scrapped'])],
            'disposal_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999999.99', 'decimal:0,2'],
            'disposal_cash_bank_account_id' => ['nullable', 'integer'],
            'disposal_note' => ['nullable', 'string', 'max:255'],
        ], [], ['disposed_at' => 'tanggal pelepasan', 'disposal_type' => 'jenis pelepasan', 'disposal_amount' => 'harga jual']);
        $this->assets->dispose($fixedAsset, $data, $request->user());

        return redirect()->route('fixed-assets.show', $fixedAsset)->with('success', "Aset {$fixedAsset->code} dilepas dan dijurnal.");
    }

    /** @return array<string, mixed> */
    private function filter(Request $request): array
    {
        return $request->validate([
            'cari' => ['nullable', 'string', 'max:100'],
            'kategori' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in([FixedAsset::ACTIVE, FixedAsset::DISPOSED])],
        ]);
    }
}

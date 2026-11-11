<?php

namespace App\Http\Controllers\Accounting;

use App\Enums\DocumentStatus;
use App\Enums\OpeningBalanceKind;
use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\OpeningBalanceRequest;
use App\Models\Account;
use App\Models\Customer;
use App\Models\OpeningBalance;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Services\Accounting\AccountMappingService;
use App\Services\Accounting\OpeningBalanceService;
use App\Support\CurrentBranch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Saldo awal: draf (izin create), posting (approve), dan pembatalan (void). Saldo awal posted
 * dikoreksi dengan membatalkannya lalu membuat yang baru.
 */
class OpeningBalanceController extends Controller
{
    public function __construct(private readonly OpeningBalanceService $balances) {}

    public function index(): View
    {
        return view('accounting.opening-balances.index', [
            'documents' => OpeningBalance::query()->with(['branch:id,name', 'creator:id,name'])->latest('balance_date')->latest('id')->paginate(20),
        ]);
    }

    public function create(CurrentBranch $branches): View
    {
        $document = new OpeningBalance(['balance_date' => now()->subDay()->startOfDay()]);
        $document->forceFill(['branch_id' => $branches->forNewDocument()->getKey()]);

        return view('accounting.opening-balances.form', $this->formData($document));
    }

    /** aksi=draft (bawaan) atau post (butuh izin approve, dicek OpeningBalanceRequest). */
    public function store(OpeningBalanceRequest $request): RedirectResponse
    {
        $document = $this->balances->saveDraft($request->validated(), $request->user());

        return $this->afterSave($request, $document);
    }

    public function show(OpeningBalance $openingBalance): View
    {
        return view('accounting.opening-balances.show', [
            'document' => $openingBalance->load(['lines.account', 'lines.warehouse', 'lines.customer', 'lines.supplier', 'branch', 'creator', 'postedBy', 'cancelledBy']),
            'journals' => $openingBalance->journals()->orderBy('id')->get(),
        ]);
    }

    public function edit(OpeningBalance $openingBalance): View|RedirectResponse
    {
        if (! $openingBalance->isEditable()) {
            return redirect()->route('opening-balances.show', $openingBalance)->with('error', 'Hanya draf saldo awal yang bisa diubah.');
        }

        return view('accounting.opening-balances.form', $this->formData($openingBalance->load('lines')));
    }

    public function update(OpeningBalanceRequest $request, OpeningBalance $openingBalance): RedirectResponse
    {
        $document = $this->balances->saveDraft($request->validated(), $request->user(), $openingBalance);

        return $this->afterSave($request, $document);
    }

    public function post(Request $request, OpeningBalance $openingBalance): RedirectResponse
    {
        $this->balances->post($openingBalance, $request->user());

        return redirect()->route('opening-balances.show', $openingBalance)->with('success', "Saldo awal {$openingBalance->number} diposting dan masuk saldo akun.");
    }

    public function cancel(Request $request, OpeningBalance $openingBalance): RedirectResponse
    {
        $request->validate(['alasan' => ['required', 'string', 'min:5', 'max:255']], [], ['alasan' => 'alasan']);
        $posted = $openingBalance->isPosted();
        $this->balances->cancel($openingBalance, $request->user(), $request->input('alasan'));

        return redirect()->route('opening-balances.show', $openingBalance)->with('success', $posted
            ? "Saldo awal {$openingBalance->number} dibatalkan dan jurnalnya dibalik."
            : "Draf saldo awal {$openingBalance->number} dibatalkan.");
    }

    public function destroy(Request $request, OpeningBalance $openingBalance): RedirectResponse
    {
        $this->balances->deleteDraft($openingBalance, $request->user());

        return redirect()->route('opening-balances.index')->with('success', "Draf saldo awal {$openingBalance->number} dihapus.");
    }

    private function afterSave(OpeningBalanceRequest $request, OpeningBalance $document): RedirectResponse
    {
        if ($request->input('aksi') === 'post') {
            $this->balances->post($document, $request->user());

            return redirect()->route('opening-balances.show', $document)->with('success', "Saldo awal {$document->number} diposting dan masuk saldo akun.");
        }

        return redirect()->route('opening-balances.show', $document)->with('success', "Draf saldo awal {$document->number} tersimpan. Belum memengaruhi saldo akun.");
    }

    /** @return array<string, mixed> */
    private function formData(OpeningBalance $document): array
    {
        $warehouses = Warehouse::query()->where('branch_id', $document->branch_id)->ordered()->get(['id', 'name', 'branch_id']);
        $control = $this->balances->controlAccounts();

        return [
            'document' => $document,
            'branchName' => $document->branch()->withTrashed()->value('name'),
            'accounts' => Account::query()->postable()->whereNotIn('id', array_keys($control) ?: [0])->ordered()->get(['id', 'code', 'name', 'type']),
            'warehouses' => $warehouses,
            'stockValues' => $this->balances->currentInventoryValues($warehouses),
            'customers' => Customer::query()->where('is_default', false)->orderBy('name')->get(['id', 'name', 'code']),
            'suppliers' => Supplier::query()->orderBy('name')->get(['id', 'name', 'code']),
            'equity' => app(AccountMappingService::class)->account('opening_equity'),
            'kinds' => OpeningBalanceKind::cases(),
            'existingPosted' => OpeningBalance::query()->where('branch_id', $document->branch_id)->withStatus(DocumentStatus::Posted)->whereKeyNot($document->id)->pluck('number'),
        ];
    }
}

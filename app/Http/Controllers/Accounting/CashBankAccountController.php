<?php

namespace App\Http\Controllers\Accounting;

use App\Enums\AccountType;
use App\Enums\CashBankKind;
use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\CashBankAccountRequest;
use App\Models\Account;
use App\Models\Branch;
use App\Models\CashBankAccount;
use App\Services\CashBank\CashBankAccountService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/** Rekening kas & bank: daftar beserta saldo, tambah, ubah (izin accounting.cash-bank.*). */
class CashBankAccountController extends Controller
{
    public function __construct(private readonly CashBankAccountService $accounts) {}

    public function index(): View
    {
        $rekening = CashBankAccount::query()->available()->with(['account:id,code,name', 'branch:id,name'])->ordered()->get();
        $balances = $this->accounts->balances($rekening);

        return view('accounting.cash-bank.accounts.index', [
            'rekening' => $rekening,
            'balances' => $balances,
            'replenish' => $rekening->filter->isPettyCash()->mapWithKeys(fn ($r) => [$r->id => max(0, (int) round((float) $r->imprest_amount * 100) - $balances[$r->id])]),
            'unlinked' => Account::query()->cashBank()->whereNotIn('id', CashBankAccount::withTrashed()->select('account_id'))->ordered()->get(['id', 'code', 'name']),
        ]);
    }

    public function create(): View
    {
        return view('accounting.cash-bank.accounts.form', $this->formData(new CashBankAccount(['kind' => CashBankKind::Bank, 'is_active' => true])));
    }

    public function store(CashBankAccountRequest $request): RedirectResponse
    {
        $rekening = $this->accounts->create($request->validated(), $request->user());

        return redirect()->route('cash-bank-accounts.index')->with('success', "Rekening {$rekening->name} ditambahkan ({$rekening->account->label()}).");
    }

    public function edit(CashBankAccount $cashBankAccount): View
    {
        return view('accounting.cash-bank.accounts.form', $this->formData($cashBankAccount->load('account')));
    }

    public function update(CashBankAccountRequest $request, CashBankAccount $cashBankAccount): RedirectResponse
    {
        $this->accounts->update($cashBankAccount, $request->validated());

        return redirect()->route('cash-bank-accounts.index')->with('success', "Rekening {$cashBankAccount->name} diperbarui.");
    }

    /** @return array<string, mixed> */
    private function formData(CashBankAccount $rekening): array
    {
        return [
            'rekening' => $rekening,
            'branches' => Branch::query()->active()->ordered()->pluck('name', 'id'),
            'accounts' => Account::query()->postable()->where('type', AccountType::Asset->value)
                ->whereNotIn('id', CashBankAccount::withTrashed()->select('account_id'))->ordered()->get(['id', 'code', 'name']),
        ];
    }
}

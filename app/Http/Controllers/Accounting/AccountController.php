<?php

namespace App\Http\Controllers\Accounting;

use App\Enums\AccountType;
use App\Exports\Accounting\AccountsExport;
use App\Exports\ListExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\AccountRequest;
use App\Models\Account;
use App\Services\Accounting\AccountService;
use App\Services\Accounting\ChartOfAccountsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/** Bagan akun (COA). Izin accounting.coa.* dipasang di routes/modules/accounting.php. */
class AccountController extends Controller
{
    public function __construct(private readonly AccountService $accounts) {}

    public function index(Request $request, ChartOfAccountsService $chart): View
    {
        return view('accounting.accounts.index', [
            'accounts' => AccountsExport::fromRequest($request, $this->filter($request))->paginate(60),
            'installed' => $chart->isInstalled(),
        ]);
    }

    public function export(Request $request): Response
    {
        $format = ListExport::formatFrom($request);

        return AccountsExport::fromRequest($request, $this->filter($request))->download($format);
    }

    /** Pasang bagan akun standar (aman diulang: akun yang sudah ada tidak diubah). */
    public function installStandard(Request $request, ChartOfAccountsService $chart): RedirectResponse
    {
        $created = $chart->install();

        activity('akuntansi')->causedBy($request->user())->event('coa_installed')
            ->withProperties(['akun_ditambahkan' => $created])
            ->log("Bagan akun standar dipasang ({$created} akun ditambahkan)");

        return redirect()->route('accounts.index')->with('success', $created > 0
            ? "{$created} akun standar ditambahkan. Jurnal otomatis sekarang bisa berjalan."
            : 'Semua akun standar sudah ada; tidak ada yang ditambahkan.');
    }

    public function create(Request $request): View
    {
        $parent = Account::find($request->integer('induk'));

        return view('accounting.accounts.form', $this->formData(new Account([
            'type' => $parent?->type ?? AccountType::Expense,
            'parent_id' => $parent?->id,
            'is_active' => true,
        ])));
    }

    public function store(AccountRequest $request): RedirectResponse
    {
        $account = $this->accounts->create($request->validated());

        return redirect()->route('accounts.index')->with('success', "Akun {$account->label()} ditambahkan.");
    }

    public function edit(Account $account): View
    {
        return view('accounting.accounts.form', $this->formData($account));
    }

    public function update(AccountRequest $request, Account $account): RedirectResponse
    {
        $this->accounts->update($account, $request->validated());

        return redirect()->route('accounts.index')->with('success', "Akun {$account->label()} diperbarui.");
    }

    public function destroy(Account $account): RedirectResponse
    {
        $this->accounts->delete($account);

        return redirect()->route('accounts.index')->with('success', "Akun {$account->label()} dihapus.");
    }

    /** @return array<string, mixed> */
    private function filter(Request $request): array
    {
        return $request->validate([
            'cari' => ['nullable', 'string', 'max:100'],
            'tipe' => ['nullable', Rule::enum(AccountType::class)],
            'status' => ['nullable', Rule::in(['aktif', 'nonaktif'])],
        ]);
    }

    /** @return array<string, mixed> */
    private function formData(Account $account): array
    {
        return [
            'account' => $account,
            'parents' => Account::query()->where('is_header', true)->whereKeyNot($account->id)->ordered()->get(['id', 'code', 'name', 'type']),
            'usedInJournals' => $account->exists && $account->lines()->exists(),
        ];
    }
}

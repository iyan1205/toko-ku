<?php

namespace App\Http\Controllers\Accounting;

use App\Exports\Accounting\BankStatementTemplateExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\BankStatementImportRequest;
use App\Models\Account;
use App\Models\BankStatement;
use App\Models\BankStatementLine;
use App\Models\CashBankAccount;
use App\Services\CashBank\BankReconciliationService;
use App\Services\Settings\StoreProfileService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\Response;

/** Rekonsiliasi bank: impor rekening koran, cocokkan, catat selisih, laporan, kunci. */
class BankReconciliationController extends Controller
{
    public function __construct(private readonly BankReconciliationService $reconciliation) {}

    public function index(): View
    {
        return view('accounting.cash-bank.reconciliations.index', [
            'statements' => BankStatement::query()->with('cashBankAccount:id,name')->withCount(['lines', 'lines as matched_count' => fn ($q) => $q->whereNotNull('journal_line_id')])
                ->latest('period_end')->latest('id')->paginate(20),
            'rekening' => CashBankAccount::query()->available()->active()->ordered()->get(['id', 'name', 'kind', 'account_number']),
        ]);
    }

    public function template(): Response
    {
        return Excel::download(new BankStatementTemplateExport, 'templat-rekening-koran.xlsx');
    }

    public function store(BankStatementImportRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $rekening = CashBankAccount::query()->available()->findOrFail($data['cash_bank_account_id']);
        $statement = $this->reconciliation->import($rekening, $request->file('file'), $data, $request->user());
        $matched = $this->reconciliation->autoMatch($statement, $request->user());

        return redirect()->route('bank-reconciliations.show', $statement)
            ->with('success', "{$statement->lines()->count()} mutasi diimpor; {$matched} langsung cocok dengan pencatatan di buku.");
    }

    public function show(BankStatement $bankStatement): View
    {
        $bankStatement->load(['cashBankAccount.account', 'lines.journalLine.journal', 'finalizedBy']);

        return view('accounting.cash-bank.reconciliations.show', [
            'statement' => $bankStatement,
            'report' => $this->reconciliation->report($bankStatement),
            'candidates' => $bankStatement->isFinalized() ? collect() : $bankStatement->lines->whereNull('journal_line_id')
                ->mapWithKeys(fn (BankStatementLine $line) => [$line->id => $this->reconciliation->candidates($line)->take(10)]),
            'accounts' => Account::query()->postable()->whereKeyNot($bankStatement->cashBankAccount->account_id)->ordered()->get(['id', 'code', 'name', 'type']),
        ]);
    }

    public function autoMatch(Request $request, BankStatement $bankStatement): RedirectResponse
    {
        $matched = $this->reconciliation->autoMatch($bankStatement, $request->user());

        return back()->with('success', $matched > 0 ? "{$matched} mutasi dicocokkan otomatis." : 'Tidak ada mutasi baru yang cocok otomatis. Cocokkan manual atau catat sebagai transaksi.');
    }

    public function match(Request $request, BankStatement $bankStatement, BankStatementLine $line): RedirectResponse
    {
        $this->belongs($bankStatement, $line);
        $data = $request->validate(['journal_line_id' => ['required', 'integer']], [], ['journal_line_id' => 'baris jurnal']);
        $this->reconciliation->match($line, (int) $data['journal_line_id'], $request->user());

        return back()->with('success', 'Mutasi dicocokkan.');
    }

    public function unmatch(BankStatement $bankStatement, BankStatementLine $line): RedirectResponse
    {
        $this->belongs($bankStatement, $line);
        $this->reconciliation->unmatch($line);

        return back()->with('success', 'Pencocokan dilepas.');
    }

    public function record(Request $request, BankStatement $bankStatement, BankStatementLine $line): RedirectResponse
    {
        $this->belongs($bankStatement, $line);
        abort_unless($request->user()->can('accounting.cash-bank.create'), 403);
        $data = $request->validate([
            'account_id' => ['required', 'integer'],
            'description' => ['nullable', 'string', 'max:255'],
        ], [], ['account_id' => 'akun lawan', 'description' => 'keterangan']);

        $transaction = $this->reconciliation->record($line, (int) $data['account_id'], $data['description'] ?? null, $request->user());

        return back()->with('success', "Mutasi dicatat sebagai {$transaction->number} dan dicocokkan.");
    }

    public function finalize(Request $request, BankStatement $bankStatement): RedirectResponse
    {
        $this->reconciliation->finalize($bankStatement, $request->user());

        return back()->with('success', 'Rekonsiliasi dikunci.');
    }

    public function destroy(BankStatement $bankStatement): RedirectResponse
    {
        $this->reconciliation->delete($bankStatement);

        return redirect()->route('bank-reconciliations.index')->with('success', 'Rekening koran dihapus. Pencatatan di buku tidak berubah.');
    }

    /** Laporan rekonsiliasi (PDF). */
    public function report(BankStatement $bankStatement, StoreProfileService $store): Response
    {
        $bankStatement->load(['cashBankAccount', 'finalizedBy']);

        return Pdf::loadView('accounting.cash-bank.reconciliations.pdf', [
            'statement' => $bankStatement,
            'report' => $this->reconciliation->report($bankStatement),
            'store' => $store->ambil(),
        ])->setPaper('a4')->stream("rekonsiliasi-{$bankStatement->id}.pdf");
    }

    private function belongs(BankStatement $statement, BankStatementLine $line): void
    {
        abort_unless($line->bank_statement_id === $statement->id, 404);
    }
}

<?php

namespace App\Http\Controllers\Accounting;

use App\Enums\CashTransactionType;
use App\Enums\DocumentStatus;
use App\Exports\Accounting\CashTransactionsExport;
use App\Exports\ListExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\CashTransactionRequest;
use App\Models\Account;
use App\Models\CashBankAccount;
use App\Models\CashTransaction;
use App\Models\CostCenter;
use App\Services\Accounting\AccountMappingService;
use App\Services\Approval\ApprovalService;
use App\Services\CashBank\CashBankAccountService;
use App\Services\CashBank\CashTransactionService;
use App\Support\Uang;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Transaksi kas & bank. Jenis dari URL: masuk (penerimaan), keluar (pengeluaran), transfer.
 * aksi=post (bawaan) memposting atau mengajukan persetujuan; aksi=draft menyimpan draf.
 */
class CashTransactionController extends Controller
{
    public function __construct(
        private readonly CashTransactionService $transactions,
        private readonly ApprovalService $approvals,
    ) {}

    public function index(Request $request): View
    {
        return view('accounting.cash-bank.transactions.index', [
            'transactions' => CashTransactionsExport::fromRequest($request, $this->filter($request))->paginate(20),
            'rekening' => CashBankAccount::query()->available()->ordered()->get(['id', 'name', 'kind']),
        ]);
    }

    public function export(Request $request): Response
    {
        $format = ListExport::formatFrom($request);

        return CashTransactionsExport::fromRequest($request, $this->filter($request))->download($format);
    }

    public function create(Request $request, string $jenis, CashBankAccountService $accounts): View
    {
        $type = CashTransactionType::fromSlug($jenis);
        $transaction = new CashTransaction([
            'type' => $type,
            'transaction_date' => now(),
            'cash_bank_account_id' => $request->integer('rekening') ?: null,
            'target_cash_bank_account_id' => $request->integer('tujuan') ?: null,
        ]);

        // Pengisian ulang kas kecil: transfer sebesar kekurangan terhadap plafon.
        $target = $transaction->target_cash_bank_account_id ? CashBankAccount::query()->available()->find($transaction->target_cash_bank_account_id) : null;
        if ($type === CashTransactionType::Transfer && $target?->isPettyCash()) {
            $transaction->amount = Uang::keDesimal($accounts->replenishAmount($target));
            $transaction->description = "Pengisian ulang {$target->name}";
        }

        return view('accounting.cash-bank.transactions.form', $this->formData($transaction));
    }

    public function store(CashTransactionRequest $request, string $jenis): RedirectResponse
    {
        $transaction = $this->transactions->saveDraft(CashTransactionType::fromSlug($jenis), $request->validated(), $request->user());

        if ($request->input('aksi', 'post') === 'post') {
            $transaction = $this->transactions->postOrSubmit($transaction, $request->user());
        }

        return redirect()->route('cash-transactions.show', $transaction)->with('success', $this->savedMessage($transaction));
    }

    public function show(Request $request, CashTransaction $cashTransaction): View
    {
        return view('accounting.cash-bank.transactions.show', [
            'transaction' => $cashTransaction->load(['lines.account', 'lines.costCenter', 'cashBankAccount', 'targetCashBankAccount', 'feeAccount', 'creator', 'postedBy', 'cancelledBy']),
            'approvalFlow' => $cashTransaction->isEditable() ? $this->approvals->flowFor($cashTransaction) : null,
        ]);
    }

    public function edit(CashTransaction $cashTransaction): View|RedirectResponse
    {
        if (! $cashTransaction->isEditable()) {
            return redirect()->route('cash-transactions.show', $cashTransaction)->with('error', 'Hanya draf yang bisa diubah.');
        }

        return view('accounting.cash-bank.transactions.form', $this->formData($cashTransaction->load('lines')));
    }

    public function update(CashTransactionRequest $request, CashTransaction $cashTransaction): RedirectResponse
    {
        $transaction = $this->transactions->saveDraft($cashTransaction->type, $request->validated(), $request->user(), $cashTransaction);

        if ($request->input('aksi', 'post') === 'post') {
            $transaction = $this->transactions->postOrSubmit($transaction, $request->user());
        }

        return redirect()->route('cash-transactions.show', $transaction)->with('success', $this->savedMessage($transaction));
    }

    public function post(Request $request, CashTransaction $cashTransaction): RedirectResponse
    {
        $transaction = $this->transactions->postOrSubmit($cashTransaction, $request->user());

        return redirect()->route('cash-transactions.show', $transaction)->with('success', $this->savedMessage($transaction));
    }

    /** Draf/pengajuan: izin create. Transaksi posted (jurnal dibalik): izin void. */
    public function cancel(Request $request, CashTransaction $cashTransaction): RedirectResponse
    {
        abort_unless($request->user()->can($cashTransaction->isPosted() ? 'accounting.cash-bank.void' : 'accounting.cash-bank.create'), 403);
        $request->validate(['alasan' => ['required', 'string', 'min:5', 'max:255']], [], ['alasan' => 'alasan']);
        $posted = $cashTransaction->isPosted();
        $this->transactions->cancel($cashTransaction, $request->user(), $request->input('alasan'));

        return redirect()->route('cash-transactions.show', $cashTransaction)->with('success', $posted
            ? "{$cashTransaction->number} dibatalkan dan jurnalnya dibalik."
            : "{$cashTransaction->number} dibatalkan.");
    }

    private function savedMessage(CashTransaction $transaction): string
    {
        return match ($transaction->documentStatus()) {
            DocumentStatus::Posted => "{$transaction->type->label()} {$transaction->number} diposting.",
            DocumentStatus::Submitted => "{$transaction->number} perlu persetujuan dan sudah diajukan. Diposting otomatis setelah disetujui.",
            default => "Draf {$transaction->number} tersimpan.",
        };
    }

    /** @return array<string, mixed> */
    private function filter(Request $request): array
    {
        return $request->validate([
            'cari' => ['nullable', 'string', 'max:100'],
            'jenis' => ['nullable', Rule::enum(CashTransactionType::class)],
            'rekening' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::enum(DocumentStatus::class)],
            'dari' => ['nullable', 'date'],
            'sampai' => ['nullable', 'date', 'after_or_equal:dari'],
        ]);
    }

    /** @return array<string, mixed> */
    private function formData(CashTransaction $transaction): array
    {
        $rekening = CashBankAccount::query()->available()->active()->with('account:id,code,name')->ordered()->get();

        return [
            'transaction' => $transaction,
            'rekening' => $rekening,
            'accounts' => Account::query()->postable()->whereNotIn('id', $rekening->pluck('account_id')->all() ?: [0])->ordered()->get(['id', 'code', 'name', 'type']),
            'costCenters' => CostCenter::query()->active()->ordered()->get(['id', 'code', 'name']),
            'feeAccount' => app(AccountMappingService::class)->optional('bank_fee'),
            'expenseAccounts' => Account::query()->postable()->whereIn('type', ['expense', 'cogs'])->ordered()->get(['id', 'code', 'name']),
        ];
    }
}

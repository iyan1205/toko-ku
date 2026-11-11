<?php

namespace App\Http\Controllers\Accounting;

use App\Enums\SettlementType;
use App\Exports\Accounting\SettlementsExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\SettlementRequest;
use App\Models\Account;
use App\Models\CashBankAccount;
use App\Models\Customer;
use App\Models\Settlement;
use App\Models\Supplier;
use App\Services\Accounting\AccountMappingService;
use App\Services\Receivables\OpenDocuments;
use App\Services\Receivables\SettlementService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Pelunasan piutang (receivable-settlements.*, izin pos.receivables.*) dan pembayaran hutang
 * (payable-settlements.*, izin purchasing.payables.*): penerimaan/pembayaran untuk banyak
 * faktur, uang muka, giro, dan nota kredit/debit.
 */
class SettlementController extends Controller
{
    public function __construct(
        private readonly SettlementService $settlements,
        private readonly OpenDocuments $documents,
    ) {}

    public function index(Request $request): View
    {
        $receivable = $this->receivable($request);
        $filters = $request->validate([
            'cari' => ['nullable', 'string', 'max:100'],
            'jenis' => ['nullable', Rule::in(array_map(fn ($t) => $t->value, SettlementType::forSide($receivable)))],
            'pihak' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(['posted', 'cancelled'])],
            'dari' => ['nullable', 'date'],
            'sampai' => ['nullable', 'date', 'after_or_equal:dari'],
        ]);

        return view('accounting.settlements.index', [
            'receivable' => $receivable,
            'settlements' => SettlementsExport::fromRequest($request, $filters + ['side' => $receivable ? 'piutang' : 'hutang'])->paginate(20),
            'parties' => $this->parties($receivable),
        ]);
    }

    /** Langkah 1 pilih pihak (dan jenis); langkah 2 isi uang dan alokasi ke dokumen terbukanya. */
    public function create(Request $request, AccountMappingService $mapping): View
    {
        $receivable = $this->receivable($request);
        $types = SettlementType::forSide($receivable);
        $type = SettlementType::tryFrom((string) $request->query('jenis')) ?? $types[0];
        abort_unless(in_array($type, $types, true), 404);

        $partyId = $request->integer($receivable ? 'pelanggan' : 'supplier') ?: null;
        $party = $partyId ? ($receivable ? Customer::find($partyId) : Supplier::find($partyId)) : null;
        $preselect = (string) $request->query('dokumen', '');

        return view('accounting.settlements.form', [
            'receivable' => $receivable,
            'type' => $type,
            'types' => $types,
            'party' => $party,
            'parties' => $this->parties($receivable),
            'documents' => $party ? $this->documents->open($receivable, $party->id) : collect(),
            'preselect' => $preselect,
            'deposit' => $party ? $this->settlements->depositBalance($receivable, $party->id) : 0,
            'rekening' => CashBankAccount::query()->available()->active()->ordered()->get(['id', 'name', 'kind', 'account_number']),
            'counterAccounts' => Account::query()->postable()->whereIn('type', $receivable ? ['revenue', 'expense'] : ['cogs', 'expense', 'revenue', 'asset'])->ordered()->get(['id', 'code', 'name']),
            'defaultCounter' => $mapping->optional($receivable ? 'credit_note' : 'debit_note')?->id,
        ]);
    }

    public function store(SettlementRequest $request): RedirectResponse
    {
        $settlement = $this->settlements->create($request->validated(), $request->user());

        return redirect()->route($settlement->type->routePrefix().'.show', $settlement)->with('success', "{$settlement->type->label()} {$settlement->number} tersimpan.");
    }

    public function show(Request $request, Settlement $settlement): View
    {
        abort_unless($settlement->type->isReceivable() === $this->receivable($request), 404);
        $settlement->load(['allocations.document', 'allocations.payment', 'customer', 'supplier', 'cashBankAccount', 'giroCashBankAccount', 'counterAccount', 'creator', 'cancelledBy']);

        return view('accounting.settlements.show', [
            'settlement' => $settlement,
            'receivable' => $settlement->type->isReceivable(),
            'documents' => $this->documents,
            'rekening' => CashBankAccount::query()->available()->active()->ordered()->get(['id', 'name', 'kind']),
        ]);
    }

    public function cancel(Request $request, Settlement $settlement): RedirectResponse
    {
        abort_unless($settlement->type->isReceivable() === $this->receivable($request), 404);
        $request->validate(['alasan' => ['required', 'string', 'min:5', 'max:255']], [], ['alasan' => 'alasan']);
        $this->settlements->cancel($settlement, $request->user(), $request->input('alasan'));

        return redirect()->route($settlement->type->routePrefix().'.show', $settlement)->with('success', "{$settlement->number} dibatalkan. Sisa faktur dikembalikan.");
    }

    private function receivable(Request $request): bool
    {
        return Str::startsWith((string) $request->route()?->getName(), 'receivable-settlements.');
    }

    /** @return Collection<int|string, string> */
    private function parties(bool $receivable)
    {
        return $receivable
            ? Customer::query()->where('is_default', false)->orderBy('name')->pluck('name', 'id')
            : Supplier::query()->orderBy('name')->pluck('name', 'id');
    }
}

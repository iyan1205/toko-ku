<?php

namespace App\Http\Controllers\Accounting;

use App\Enums\DocumentStatus;
use App\Exceptions\AturanBisnisException;
use App\Exports\Accounting\JournalsExport;
use App\Exports\ListExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\JournalRequest;
use App\Models\Account;
use App\Models\Journal;
use App\Models\User;
use App\Services\Accounting\JournalService;
use App\Services\Approval\ApprovalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Jurnal umum: daftar semua jurnal (manual dan otomatis), jurnal manual baru, dan jurnal
 * pembalik untuk jurnal manual. Jurnal otomatis dibalik oleh pembatalan dokumen asalnya.
 */
class JournalController extends Controller
{
    public function __construct(
        private readonly JournalService $journals,
        private readonly ApprovalService $approvals,
    ) {}

    public function index(Request $request): View
    {
        return view('accounting.journals.index', [
            'journals' => JournalsExport::fromRequest($request, $this->filter($request))->paginate(20),
            'accounts' => Account::query()->where('is_header', false)->ordered()->get(['id', 'code', 'name']),
        ]);
    }

    public function export(Request $request): Response
    {
        $format = ListExport::formatFrom($request);

        return JournalsExport::fromRequest($request, $this->filter($request))->download($format);
    }

    public function create(): View
    {
        return view('accounting.journals.form', [
            'journal' => new Journal(['journal_date' => now()]),
            'accounts' => Account::query()->postable()->ordered()->get(['id', 'code', 'name', 'type']),
        ]);
    }

    /**
     * aksi=post (bawaan): langsung diposting, atau diajukan bila ada alur persetujuan yang cocok.
     * aksi=draft: disimpan sebagai draf, belum memengaruhi saldo.
     */
    public function store(JournalRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $journal = $request->input('aksi') === 'draft'
            ? $this->journals->saveDraft($data['journal_date'], $data['description'], $data['lines'], $request->user())
            : $this->journals->createManual($data['journal_date'], $data['description'], $data['lines'], $request->user());

        return redirect()->route('journals.show', $journal)->with('success', $this->savedMessage($journal));
    }

    public function edit(Journal $journal): View|RedirectResponse
    {
        if (! $journal->isManual() || ! $journal->isEditable()) {
            return redirect()->route('journals.show', $journal)->with('error', 'Hanya draf jurnal manual yang bisa diubah.');
        }

        return view('accounting.journals.form', [
            'journal' => $journal->load('lines'),
            'accounts' => Account::query()->postable()->ordered()->get(['id', 'code', 'name', 'type']),
        ]);
    }

    /** Simpan perubahan draf; aksi=post sekalian memposting (atau mengajukan). */
    public function update(JournalRequest $request, Journal $journal): RedirectResponse
    {
        $data = $request->validated();
        $journal = $this->journals->saveDraft($data['journal_date'], $data['description'], $data['lines'], $request->user(), $journal);

        if ($request->input('aksi') !== 'draft') {
            $journal = $this->postOrSubmit($journal, $request->user());
        }

        return redirect()->route('journals.show', $journal)->with('success', $this->savedMessage($journal));
    }

    /** Posting draf, atau ajukan bila ada alur persetujuan yang cocok. */
    public function post(Request $request, Journal $journal): RedirectResponse
    {
        $journal = $this->postOrSubmit($journal, $request->user());

        return redirect()->route('journals.show', $journal)->with('success', $this->savedMessage($journal));
    }

    /** Batalkan jurnal yang belum diposting. */
    public function cancel(Request $request, Journal $journal): RedirectResponse
    {
        $request->validate(['alasan' => ['required', 'string', 'min:5', 'max:255']], [], ['alasan' => 'alasan']);
        $this->journals->cancelDraft($journal, $request->user(), $request->input('alasan'));

        return redirect()->route('journals.show', $journal)->with('success', "Jurnal {$journal->number} dibatalkan.");
    }

    public function show(Request $request, Journal $journal): View
    {
        return view('accounting.journals.show', [
            'journal' => $journal->load(['lines.account', 'user', 'postedBy', 'cancelledBy', 'reversal', 'reversalOf', 'source']),
            'approvalFlow' => $journal->isManual() && $journal->isEditable() ? $this->approvals->flowFor($journal) : null,
            'sourceUrl' => $journal->sourceUrl($request->user()),
        ]);
    }

    /** Balik jurnal manual (koreksi). Jurnal otomatis dibalik lewat pembatalan dokumennya. */
    public function reverse(Request $request, Journal $journal): RedirectResponse
    {
        $request->validate(['alasan' => ['required', 'string', 'min:5', 'max:200']], [], ['alasan' => 'alasan']);

        if (! $journal->isManual()) {
            throw new AturanBisnisException("Jurnal {$journal->number} dibuat otomatis. Batalkan dokumen asalnya untuk membalik jurnal ini.");
        }

        $reversal = $this->journals->reverse($journal, "Pembalik {$journal->number}: ".trim($request->input('alasan')), $request->user());

        return redirect()->route('journals.show', $reversal)->with('success', "Jurnal {$journal->number} dibalik dengan {$reversal->number}.");
    }

    private function postOrSubmit(Journal $journal, User $user): Journal
    {
        return $this->approvals->requiresApproval($journal)
            ? $this->journals->submit($journal, $user)
            : $this->journals->postDraft($journal, $user);
    }

    private function savedMessage(Journal $journal): string
    {
        return match ($journal->documentStatus()) {
            DocumentStatus::Posted => "Jurnal {$journal->number} diposting.",
            DocumentStatus::Submitted => "Jurnal {$journal->number} perlu persetujuan dan sudah diajukan. Jurnal masuk saldo setelah disetujui.",
            default => "Draf jurnal {$journal->number} tersimpan. Belum memengaruhi saldo akun.",
        };
    }

    /** @return array<string, mixed> */
    private function filter(Request $request): array
    {
        return $request->validate([
            'cari' => ['nullable', 'string', 'max:100'],
            'sumber' => ['nullable', Rule::in(array_keys(JournalsExport::SOURCES))],
            'akun' => ['nullable', 'integer', 'exists:accounts,id'],
            'status' => ['nullable', Rule::enum(DocumentStatus::class)],
            'dari' => ['nullable', 'date'],
            'sampai' => ['nullable', 'date', 'after_or_equal:dari'],
        ]);
    }
}

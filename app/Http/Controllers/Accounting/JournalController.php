<?php

namespace App\Http\Controllers\Accounting;

use App\Exceptions\AturanBisnisException;
use App\Exports\Accounting\JournalsExport;
use App\Exports\ListExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\JournalRequest;
use App\Models\Account;
use App\Models\Journal;
use App\Services\Accounting\JournalService;
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
    public function __construct(private readonly JournalService $journals) {}

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
            'accounts' => Account::query()->postable()->ordered()->get(['id', 'code', 'name', 'type']),
        ]);
    }

    public function store(JournalRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $journal = $this->journals->post($data['journal_date'], $data['description'], $data['lines'], $request->user());

        return redirect()->route('journals.show', $journal)->with('success', "Jurnal {$journal->number} tersimpan.");
    }

    public function show(Request $request, Journal $journal): View
    {
        return view('accounting.journals.show', [
            'journal' => $journal->load(['lines.account', 'user', 'reversal', 'reversalOf', 'source']),
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

    /** @return array<string, mixed> */
    private function filter(Request $request): array
    {
        return $request->validate([
            'cari' => ['nullable', 'string', 'max:100'],
            'sumber' => ['nullable', Rule::in(array_keys(JournalsExport::SOURCES))],
            'akun' => ['nullable', 'integer', 'exists:accounts,id'],
            'dari' => ['nullable', 'date'],
            'sampai' => ['nullable', 'date', 'after_or_equal:dari'],
        ]);
    }
}

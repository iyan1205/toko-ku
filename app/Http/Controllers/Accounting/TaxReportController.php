<?php

namespace App\Http\Controllers\Accounting;

use App\Enums\InvoiceType;
use App\Exports\Accounting\TaxDocumentsExport;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\TaxRate;
use App\Services\Accounting\LedgerService;
use App\Services\Tax\TaxReportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\Response;

/** Pajak: rekap PPN per masa, daftar & ekspor faktur, bukti potong PPh (izin accounting.taxes.*). */
class TaxReportController extends Controller
{
    public function __construct(private readonly TaxReportService $taxes) {}

    public function ppn(Request $request, LedgerService $ledger): View
    {
        $data = $request->validate([
            'tahun' => ['nullable', 'integer', 'between:2000,2100'],
            'cabang' => ['nullable', 'integer', Rule::in($ledger->branchOptions()->pluck('id')->all())],
        ]);
        $year = (int) ($data['tahun'] ?? now()->year);

        return view('accounting.taxes.ppn', [
            'year' => $year,
            'rows' => $this->taxes->ppnRecap($year, array_filter(['cabang' => $data['cabang'] ?? null])),
            'branches' => $ledger->branchOptions(),
        ]);
    }

    public function documents(Request $request): View
    {
        [$period, $direction] = $this->periodAndDirection($request);

        return view('accounting.taxes.documents', [
            'period' => $period,
            'direction' => $direction,
            'documents' => $this->taxes->documents($period, $direction),
        ]);
    }

    public function export(Request $request): Response
    {
        [$period, $direction] = $this->periodAndDirection($request);
        $format = $request->validate(['format' => ['nullable', Rule::in(['xlsx', 'csv'])]])['format'] ?? 'xlsx';

        return Excel::download(new TaxDocumentsExport($direction, $this->taxes->documents($period, $direction)),
            "faktur-{$direction}-{$period}.{$format}", $format === 'csv' ? ExcelWriter::CSV : ExcelWriter::XLSX);
    }

    public function withholdings(Request $request): View
    {
        $data = $request->validate([
            'masa' => ['nullable', 'date_format:Y-m'],
            'jenis' => ['nullable', Rule::in(array_keys(TaxRate::KINDS))],
        ]);
        $period = $data['masa'] ?? now()->subMonthNoOverflow()->format('Y-m');

        return view('accounting.taxes.withholdings', [
            'period' => $period,
            'kind' => $data['jenis'] ?? null,
            'invoices' => $this->taxes->withholdings($period, $data['jenis'] ?? null),
        ]);
    }

    /** Isi nomor dan tanggal bukti potong setelah dibuat di sistem pajak. */
    public function updateSlip(Request $request, Invoice $invoice): RedirectResponse
    {
        abort_unless($invoice->type === InvoiceType::Purchase && (float) $invoice->withholding_amount > 0, 404);
        $data = $request->validate([
            'withholding_slip_number' => ['nullable', 'string', 'max:50'],
            'withholding_slip_date' => ['nullable', 'date'],
        ], [], ['withholding_slip_number' => 'nomor bukti potong', 'withholding_slip_date' => 'tanggal bukti potong']);
        $invoice->forceFill($data)->save();

        activity('akuntansi')->performedOn($invoice)->causedBy($request->user())->event('updated')
            ->withProperties($data)->log("Bukti potong {$invoice->number} diisi");

        return back()->with('success', "Bukti potong {$invoice->number} tersimpan.");
    }

    /** @return array{0: string, 1: string} */
    private function periodAndDirection(Request $request): array
    {
        $data = $request->validate([
            'masa' => ['nullable', 'date_format:Y-m'],
            'arah' => ['nullable', Rule::in(['keluaran', 'masukan'])],
        ]);

        return [$data['masa'] ?? now()->subMonthNoOverflow()->format('Y-m'), $data['arah'] ?? 'keluaran'];
    }
}

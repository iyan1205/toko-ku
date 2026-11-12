<?php

namespace App\Http\Controllers\Accounting;

use App\Exports\Accounting\ReportTableExport;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\CostCenter;
use App\Services\Accounting\FinancialReportService;
use App\Services\Accounting\LedgerService;
use App\Services\Settings\StoreProfileService;
use App\Support\Format;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\Response;

/**
 * Laporan keuangan: Laba Rugi (periode, bulanan, pembanding), Neraca (pembanding akhir tahun lalu),
 * Arus Kas (tidak langsung), Perubahan Ekuitas. ?format=xlsx|pdf mengekspor dengan kop toko.
 * Angka akun menuju Buku Besar (lalu jurnalnya).
 */
class FinancialReportController extends Controller
{
    private const REPORTS = [
        'financial-reports.income' => 'income',
        'financial-reports.balance' => 'balance',
        'financial-reports.cash-flow' => 'cashFlow',
        'financial-reports.equity' => 'equity',
    ];

    public function __construct(private readonly FinancialReportService $reports) {}

    public function index(): View
    {
        return view('accounting.reports.index');
    }

    public function show(Request $request, LedgerService $ledger, StoreProfileService $store): View|Response
    {
        $kind = self::REPORTS[$request->route()->getName()];
        $f = $request->validate([
            'dari' => ['nullable', 'date'],
            'sampai' => ['nullable', 'date', 'after_or_equal:dari'],
            'per' => ['nullable', 'date'],
            'banding' => ['nullable', Rule::in(['single', 'monthly', 'previous', 'last_year', 'tahun_lalu'])],
            'cabang' => ['nullable', 'integer', Rule::in($ledger->branchOptions()->pluck('id')->all())],
            'cost_center' => ['nullable', 'integer', 'exists:cost_centers,id'],
            'format' => ['nullable', Rule::in(['xlsx', 'pdf'])],
        ]);
        $from = $f['dari'] ?? now()->startOfYear()->toDateString();
        $to = $f['sampai'] ?? now()->toDateString();
        $filters = array_filter(['cabang' => $f['cabang'] ?? null, 'cost_center' => $kind === 'income' ? ($f['cost_center'] ?? null) : null]);
        $mode = $f['banding'] ?? 'single';

        $report = match ($kind) {
            'income' => $this->reports->incomeStatement($filters, $this->reports->periodColumns($mode, $from, $to), in_array($mode, ['previous', 'last_year'], true)),
            'balance' => $this->reports->balanceSheet($filters, $f['per'] ?? $to, $mode === 'tahun_lalu' ? CarbonImmutable::parse($f['per'] ?? $to)->subYear()->endOfYear()->toDateString() : null),
            'cashFlow' => $this->reports->cashFlow($filters, $from, $to),
            'equity' => $this->reports->equityStatement($filters, $from, $to),
        };
        if ($kind === 'income' && $mode === 'monthly' && CarbonImmutable::parse($from)->startOfMonth()->diffInMonths(CarbonImmutable::parse($to)->startOfMonth()) > 11) {
            $report['note'] = 'Tampilan per bulan memuat 12 bulan pertama dari rentang yang dipilih.';
        }
        $report['subtitle'] = collect([
            $kind === 'balance' ? 'Per '.Format::tanggal($f['per'] ?? $to) : Format::tanggal($from).' s.d. '.Format::tanggal($to),
            isset($filters['cabang']) ? Branch::withTrashed()->find($filters['cabang'])?->name : null,
            isset($filters['cost_center']) ? CostCenter::withTrashed()->find($filters['cost_center'])?->label() : null,
        ])->filter()->join(' · ');

        if ($format = $f['format'] ?? null) {
            abort_unless($request->user()->can('accounting.reports.export'), 403);
            $name = Str::slug($report['title']).'-'.now()->format('Ymd');

            return $format === 'pdf'
                ? Pdf::loadView('accounting.reports.pdf', ['report' => $report, 'store' => $store->ambil()])->setPaper('a4', count($report['columns']) > 3 ? 'landscape' : 'portrait')->download("{$name}.pdf")
                : Excel::download(new ReportTableExport($report, $store->ambil()), "{$name}.xlsx");
        }

        return view('accounting.reports.show', [
            'kind' => $kind,
            'report' => $report,
            'filters' => ['dari' => $from, 'sampai' => $to, 'per' => $f['per'] ?? $to, 'banding' => $mode] + $filters,
            'branches' => $ledger->branchOptions(),
            'costCenters' => CostCenter::query()->ordered()->get(['id', 'code', 'name']),
        ]);
    }
}

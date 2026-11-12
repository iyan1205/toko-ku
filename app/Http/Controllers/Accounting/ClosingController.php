<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\FiscalYearClosing;
use App\Services\Accounting\ClosingService;
use App\Services\Accounting\FiscalPeriodService;
use App\Support\Format;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Tutup buku: checklist tutup bulan, tutup tahun (jurnal penutup), dan pembatalannya (izin accounting.closing.*). */
class ClosingController extends Controller
{
    public function __construct(private readonly ClosingService $closing) {}

    public function index(Request $request, FiscalPeriodService $periods): View
    {
        $year = (int) ($request->validate(['tahun' => ['nullable', 'integer', 'between:2000,2100']])['tahun'] ?? now()->year);
        $today = CarbonImmutable::today();

        return view('accounting.closing.index', [
            'year' => $year,
            'months' => collect(range(1, 12))->map(fn (int $m) => [
                'month' => $m,
                'date' => $date = CarbonImmutable::create($year, $m, 1),
                'closed' => $periods->isClosed($date),
                'future' => $date->gt($today->startOfMonth()),
            ]),
            'closingRecord' => $this->closing->closingFor($year),
            'profits' => $this->closing->profitByBranch($year),
            'branchNames' => Branch::withTrashed()->pluck('name', 'id'),
            'yearEnded' => CarbonImmutable::create($year, 12, 31)->lte($today),
            'history' => FiscalYearClosing::query()->with('cancelledBy:id,name')->latest('fiscal_year')->latest('id')->limit(10)->get(),
        ]);
    }

    public function checklist(int $tahun, int $bulan, FiscalPeriodService $periods): View
    {
        abort_unless($bulan >= 1 && $bulan <= 12, 404);

        return view('accounting.closing.checklist', [
            'year' => $tahun,
            'month' => $bulan,
            'date' => CarbonImmutable::create($tahun, $bulan, 1),
            'items' => $this->closing->checklist($tahun, $bulan),
            'closed' => $periods->isClosed(CarbonImmutable::create($tahun, $bulan, 1)),
        ]);
    }

    public function closeYear(Request $request, int $tahun): RedirectResponse
    {
        $lock = $request->boolean('kunci', true);
        $record = $this->closing->closeYear($tahun, $request->user(), $lock);

        return redirect()->route('closing.index', ['tahun' => $tahun])->with('success', "Tahun {$tahun} ditutup. Laba ".Format::rupiah($record->net_income).' dipindah ke Laba Ditahan'.($lock ? ' dan semua bulan dikunci.' : '.'));
    }

    public function cancelYear(Request $request, FiscalYearClosing $fiscalYearClosing): RedirectResponse
    {
        $request->validate(['alasan' => ['required', 'string', 'min:5', 'max:200']], [], ['alasan' => 'alasan']);
        $this->closing->cancelYear($fiscalYearClosing, $request->user(), $request->input('alasan'));

        return redirect()->route('closing.index', ['tahun' => $fiscalYearClosing->fiscal_year])->with('success', "Tutup buku {$fiscalYearClosing->fiscal_year} dibatalkan; jurnal penutupnya dibalik.");
    }
}

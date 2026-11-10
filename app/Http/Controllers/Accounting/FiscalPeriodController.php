<?php

namespace App\Http\Controllers\Accounting;

use App\Enums\FiscalPeriodStatus;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\FiscalPeriod;
use App\Services\Accounting\FiscalPeriodService;
use App\Support\Format;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Akuntansi → Kunci Periode. Bulan yang ditutup menolak transaksi bertanggal di dalamnya
 * (lihat FiscalPeriodService). Izin accounting.periods.update untuk menutup dan membuka kembali.
 */
class FiscalPeriodController extends Controller
{
    public function __construct(private readonly FiscalPeriodService $periods) {}

    public function index(Request $request): View
    {
        $sekarang = CarbonImmutable::now();
        $tahun = (int) ($request->validate(['tahun' => ['nullable', 'integer', 'between:2000,2100']])['tahun'] ?? $sekarang->year);

        $tersimpan = FiscalPeriod::query()
            ->with(['closedBy:id,name', 'reopenedBy:id,name'])
            ->where('company_id', Company::default()->getKey())
            ->where('year', $tahun)
            ->get()
            ->keyBy('month');

        $bulan = collect(range(1, 12))->map(function (int $m) use ($tahun, $tersimpan, $sekarang) {
            $awal = CarbonImmutable::create($tahun, $m, 1);
            $periode = $tersimpan->get($m);

            return [
                'year' => $tahun,
                'month' => $m,
                'label' => Format::bulan($awal),
                'status' => $periode?->status ?? FiscalPeriodStatus::Open,
                'period' => $periode,
                'future' => $awal->greaterThan($sekarang->startOfMonth()),
                'current' => $awal->isSameMonth($sekarang),
            ];
        });

        return view('accounting.periods.index', [
            'tahun' => $tahun,
            'bulan' => $bulan,
            'jumlahDitutup' => $bulan->where('status', FiscalPeriodStatus::Closed)->count(),
        ]);
    }

    public function close(Request $request, int $tahun, int $bulan): RedirectResponse
    {
        $data = $request->validate(['catatan' => ['nullable', 'string', 'max:255']]);
        $periode = $this->periods->close($tahun, $bulan, $request->user(), $data['catatan'] ?? null);

        return redirect()->route('fiscal-periods.index', ['tahun' => $tahun])
            ->with('success', 'Periode '.Format::bulan($periode->firstDay()).' ditutup. Transaksi bertanggal di bulan ini sekarang ditolak.');
    }

    public function reopen(Request $request, int $tahun, int $bulan): RedirectResponse
    {
        $request->validate(['alasan' => ['required', 'string', 'min:5', 'max:255']], [], ['alasan' => 'alasan']);
        $periode = $this->periods->reopen($tahun, $bulan, $request->user(), $request->input('alasan'));

        return redirect()->route('fiscal-periods.index', ['tahun' => $tahun])
            ->with('success', 'Periode '.Format::bulan($periode->firstDay()).' dibuka kembali.');
    }
}

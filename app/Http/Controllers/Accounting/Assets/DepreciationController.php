<?php

namespace App\Http\Controllers\Accounting\Assets;

use App\Http\Controllers\Controller;
use App\Models\DepreciationRun;
use App\Services\Assets\DepreciationService;
use App\Support\Format;
use App\Support\QueueHealth;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Penyusutan bulanan: pratinjau, hitung (izin accounting.assets.approve), riwayat, dan pembatalan. */
class DepreciationController extends Controller
{
    public function __construct(private readonly DepreciationService $depreciation) {}

    public function index(Request $request): View
    {
        $period = $request->validate(['periode' => ['nullable', 'date_format:Y-m']])['periode'] ?? now()->subMonthNoOverflow()->format('Y-m');

        return view('accounting.assets.depreciation.index', [
            'period' => $period,
            'preview' => $this->depreciation->preview($period),
            'runs' => DepreciationRun::query()->latest('period')->latest('id')->paginate(15)->withQueryString(),
            'schedulerAlive' => QueueHealth::schedulerAlive(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $period = $request->validate(['periode' => ['required', 'date_format:Y-m']], [], ['periode' => 'periode'])['periode'];
        $runs = $this->depreciation->run($period, $request->user());

        return redirect()->route('depreciation.index', ['periode' => $period])->with('success', $runs === []
            ? 'Tidak ada aset yang perlu disusutkan untuk '.Format::bulan($period.'-01').'.'
            : 'Penyusutan '.Format::bulan($period.'-01').' dijurnal: '.Format::rupiah(collect($runs)->sum(fn ($r) => (float) $r->total)).'.');
    }

    public function show(DepreciationRun $depreciationRun): View
    {
        return view('accounting.assets.depreciation.show', [
            'run' => $depreciationRun->load(['items.asset.category', 'cancelledBy', 'creator', 'branch']),
        ]);
    }

    public function cancel(Request $request, DepreciationRun $depreciationRun): RedirectResponse
    {
        $request->validate(['alasan' => ['required', 'string', 'min:5', 'max:200']], [], ['alasan' => 'alasan']);
        $this->depreciation->cancel($depreciationRun, $request->user(), $request->input('alasan'));

        return redirect()->route('depreciation-runs.show', $depreciationRun)->with('success', 'Penyusutan dibatalkan dan jurnalnya dibalik.');
    }
}

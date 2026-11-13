<?php

namespace App\Http\Controllers\Sdm;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sdm\HolidayRequest;
use App\Models\Branch;
use App\Models\Holiday;
use App\Support\Format;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Hari libur nasional dan cabang: tidak dihitung alpha, tarif lembur hari libur, cuti hari kerja. */
class HolidayController extends Controller
{
    public function index(Request $request): View
    {
        $year = (int) $request->integer('tahun', (int) today()->year);
        $year = max(2000, min(2100, $year));

        return view('sdm.holidays.index', [
            'year' => $year,
            'holidays' => Holiday::query()->with('branch:id,name')
                ->whereYear('date', $year)->orderBy('date')->get(),
            'editing' => $request->filled('ubah') ? Holiday::query()->find($request->integer('ubah')) : null,
            'branches' => Branch::query()->orderBy('name')->pluck('name', 'id'),
        ]);
    }

    public function store(HolidayRequest $request): RedirectResponse
    {
        $holiday = Holiday::create($this->data($request));

        return redirect()->route('holidays.index', ['tahun' => $holiday->date->year])
            ->with('success', "Hari libur {$holiday->name} (".Format::tanggal($holiday->date).') ditambahkan.');
    }

    public function update(HolidayRequest $request, Holiday $holiday): RedirectResponse
    {
        $holiday->update($this->data($request));

        return redirect()->route('holidays.index', ['tahun' => $holiday->date->year])
            ->with('success', "Hari libur {$holiday->name} diperbarui.");
    }

    public function destroy(Holiday $holiday): RedirectResponse
    {
        $holiday->delete();

        return redirect()->route('holidays.index', ['tahun' => $holiday->date->year])
            ->with('success', "Hari libur {$holiday->name} dihapus.");
    }

    /** @return array{date: string, name: string, branch_id: ?int, is_national: bool} */
    private function data(HolidayRequest $request): array
    {
        $branchId = $request->validated('branch_id') ?: null;

        return [
            'date' => $request->date('date')->toDateString(),
            'name' => $request->validated('name'),
            'branch_id' => $branchId,
            'is_national' => $branchId === null,
        ];
    }
}

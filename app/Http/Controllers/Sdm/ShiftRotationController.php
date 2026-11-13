<?php

namespace App\Http\Controllers\Sdm;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sdm\ApplyShiftRotationRequest;
use App\Http\Requests\Sdm\ShiftRotationRequest;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Shift;
use App\Models\ShiftRotation;
use App\Services\Sdm\ScheduleRotationService;
use App\Support\Format;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Pola rotasi shift (Pagi, Pagi, Siang, Siang, Libur, …) dan penerapannya ke jadwal karyawan. */
class ShiftRotationController extends Controller
{
    public function index(Request $request): View
    {
        return view('shifts.rotations', [
            'rotations' => ShiftRotation::query()->orderBy('name')->get(),
            'editing' => $request->filled('ubah') ? ShiftRotation::query()->find($request->integer('ubah')) : null,
            'shifts' => Shift::query()->orderBy('jam_masuk')->orderBy('nama')->get(),
            'employees' => Employee::query()->where('is_active', true)->orderBy('name')->get(['id', 'code', 'name', 'department_id']),
            'departments' => Department::query()->orderBy('name')->pluck('name', 'id'),
        ]);
    }

    public function store(ShiftRotationRequest $request): RedirectResponse
    {
        $rotation = ShiftRotation::create($request->validated());

        return redirect()->route('shift-rotations.index')->with('success', "Pola rotasi {$rotation->name} ditambahkan.");
    }

    public function update(ShiftRotationRequest $request, ShiftRotation $rotation): RedirectResponse
    {
        $rotation->update($request->validated());

        return redirect()->route('shift-rotations.index')->with('success', "Pola rotasi {$rotation->name} diperbarui.");
    }

    public function destroy(ShiftRotation $rotation): RedirectResponse
    {
        $rotation->delete();

        return redirect()->route('shift-rotations.index')->with('success', "Pola rotasi {$rotation->name} dihapus. Jadwal yang sudah dibuat dari pola ini tidak berubah.");
    }

    public function apply(ApplyShiftRotationRequest $request, ShiftRotation $rotation, ScheduleRotationService $service): RedirectResponse
    {
        $data = $request->validated();
        $start = CarbonImmutable::parse($data['start_date']);
        $counts = $service->apply($rotation, array_map('intval', $data['employee_ids']), $start, CarbonImmutable::parse($data['end_date']),
            (int) ($data['offset'] ?? 0), $data['overwrite'], $data['skip_holidays']);

        return redirect()->route('schedules.month', ['bulan' => $start->format('Y-m')])->with('success', sprintf(
            'Rotasi %s diterapkan %s–%s: %d jadwal baru, %d diubah, %d dilewati.',
            $rotation->name, Format::tanggal($start), Format::tanggal($data['end_date']), $counts['created'], $counts['updated'], $counts['skipped'],
        ));
    }
}

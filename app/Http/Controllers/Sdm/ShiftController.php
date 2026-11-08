<?php

namespace App\Http\Controllers\Sdm;

use App\Exceptions\AturanBisnisException;
use App\Http\Controllers\Controller;
use App\Http\Requests\ShiftRequest;
use App\Models\Shift;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class ShiftController extends Controller
{
    public function index(): View
    {
        return view('shifts.index', [
            'shifts' => Shift::query()->withCount('jadwals')->orderBy('jam_masuk')->orderBy('nama')->get(),
        ]);
    }

    public function store(ShiftRequest $request): RedirectResponse
    {
        $shift = Shift::create($request->validated());

        return back()->with('success', "Shift {$shift->nama} berhasil ditambahkan.");
    }

    public function update(ShiftRequest $request, Shift $shift): RedirectResponse
    {
        $shift->update($request->validated());

        return back()->with('success', "Shift {$shift->nama} berhasil diperbarui.");
    }

    public function destroy(Shift $shift): RedirectResponse
    {
        if ($jumlah = $shift->jadwals()->count()) {
            throw new AturanBisnisException("Shift {$shift->nama} masih dipakai di {$jumlah} jadwal. Nonaktifkan saja bila tidak dipakai lagi.");
        }

        $shift->delete();

        return back()->with('success', "Shift {$shift->nama} dihapus.");
    }
}

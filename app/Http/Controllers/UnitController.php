<?php

namespace App\Http\Controllers;

use App\Exceptions\AturanBisnisException;
use App\Http\Requests\UnitRequest;
use App\Models\Unit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class UnitController extends Controller
{
    public function index(Request $request): View
    {
        $cari = $request->validate(['cari' => ['nullable', 'string', 'max:100']])['cari'] ?? null;

        $items = Unit::query()
            ->withCount('products')
            ->when($cari, fn ($q, $kata) => $q->where('name', 'like', "%{$kata}%"))
            ->orderBy('name')
            ->paginate(10)
            ->withQueryString();

        return view('master.index', [
            'judul' => 'Satuan',
            'tunggal' => 'satuan',
            'prefix' => 'units',
            'items' => $items,
        ]);
    }

    public function store(UnitRequest $request): RedirectResponse
    {
        $satuan = Unit::create($request->validated());

        return back()->with('success', "Satuan {$satuan->name} berhasil ditambahkan.");
    }

    public function update(UnitRequest $request, Unit $unit): RedirectResponse
    {
        $unit->update($request->validated());

        return back()->with('success', "Satuan {$unit->name} berhasil diperbarui.");
    }

    public function destroy(Unit $unit): RedirectResponse
    {
        if ($jumlah = $unit->dipakaiProduk()) {
            throw new AturanBisnisException("Satuan {$unit->name} tidak dapat dihapus karena masih dipakai {$jumlah} produk.");
        }

        $unit->delete();

        return back()->with('success', "Satuan {$unit->name} dihapus.");
    }
}

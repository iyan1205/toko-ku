<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Exceptions\AturanBisnisException;
use App\Exports\Inventory\UnitsExport;
use App\Exports\ListExport;
use App\Http\Requests\UnitRequest;
use App\Models\Unit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class UnitController extends Controller
{
    public function index(Request $request): View
    {
        return view('inventory.master.index', [
            'judul' => 'Satuan',
            'tunggal' => 'satuan',
            'prefix' => 'units',
            'izin' => 'inventory.units',
            'items' => UnitsExport::fromRequest($request, $this->filter($request))->paginate(15),
        ]);
    }

    public function export(Request $request): Response
    {
        $format = ListExport::formatFrom($request);

        return UnitsExport::fromRequest($request, $this->filter($request))->download($format);
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

    /** @return array<string, mixed> */
    private function filter(Request $request): array
    {
        return $request->validate(['cari' => ['nullable', 'string', 'max:100']]);
    }
}

<?php

namespace App\Http\Controllers\Inventory;

use App\Exports\Inventory\WarehousesExport;
use App\Exports\ListExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\WarehouseRequest;
use App\Models\Branch;
use App\Models\Warehouse;
use App\Services\Inventory\WarehouseService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/** Gudang/lokasi stok. Izin inventory.warehouses.* dipasang di routes/modules/inventory.php. */
class WarehouseController extends Controller
{
    public function __construct(private readonly WarehouseService $warehouses) {}

    public function index(Request $request): View
    {
        return view('inventory.warehouses.index', [
            'warehouses' => WarehousesExport::fromRequest($request, $this->filter($request))->paginate(15),
            'branches' => Branch::query()->ordered()->get(['id', 'name']),
        ]);
    }

    public function create(): View
    {
        return view('inventory.warehouses.form', $this->formData(new Warehouse(['is_active' => true])));
    }

    public function store(WarehouseRequest $request): RedirectResponse
    {
        $warehouse = $this->warehouses->create($request->validated());

        return redirect()->route('warehouses.index')->with('success', "Gudang {$warehouse->name} ditambahkan.");
    }

    public function edit(Warehouse $warehouse): View
    {
        return view('inventory.warehouses.form', $this->formData($warehouse));
    }

    public function update(WarehouseRequest $request, Warehouse $warehouse): RedirectResponse
    {
        $this->warehouses->update($warehouse, $request->validated());

        return redirect()->route('warehouses.index')->with('success', "Gudang {$warehouse->name} diperbarui.");
    }

    public function destroy(Warehouse $warehouse): RedirectResponse
    {
        $this->warehouses->delete($warehouse);

        return redirect()->route('warehouses.index')->with('success', "Gudang {$warehouse->name} dihapus. Riwayat stoknya tetap tersimpan.");
    }

    public function export(Request $request): Response
    {
        $format = ListExport::formatFrom($request);

        return WarehousesExport::fromRequest($request, $this->filter($request))->download($format);
    }

    /** @return array<string, mixed> */
    private function filter(Request $request): array
    {
        return $request->validate([
            'cari' => ['nullable', 'string', 'max:100'],
            'cabang' => ['nullable', 'integer', 'exists:branches,id'],
            'status' => ['nullable', Rule::in(['aktif', 'nonaktif'])],
        ]);
    }

    /** @return array<string, mixed> */
    private function formData(Warehouse $warehouse): array
    {
        return [
            'warehouse' => $warehouse,
            'branches' => Branch::query()->active()->ordered()->get(['id', 'name'])->pluck('name', 'id'),
            'stock' => $warehouse->exists ? $warehouse->stocks()->where('quantity', '!=', 0)->count() : 0,
        ];
    }
}

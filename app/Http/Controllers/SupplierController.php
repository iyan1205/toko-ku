<?php

namespace App\Http\Controllers;

use App\Http\Requests\SupplierRequest;
use App\Models\Supplier;
use App\Services\SupplierService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SupplierController extends Controller
{
    public function __construct(private readonly SupplierService $service)
    {
    }

    public function index(Request $request): View
    {
        $cari = $request->validate(['cari' => ['nullable', 'string', 'max:100']])['cari'] ?? null;

        return view('suppliers.index', [
            'suppliers' => Supplier::query()->cari($cari)->orderBy('name')->paginate(10)->withQueryString(),
        ]);
    }

    public function create(): View
    {
        return view('suppliers.form', ['supplier' => new Supplier()]);
    }

    public function store(SupplierRequest $request): RedirectResponse
    {
        $supplier = $this->service->buat($request->validated());

        return redirect()->route('suppliers.show', $supplier)
            ->with('success', "Supplier {$supplier->name} berhasil ditambahkan dengan kode {$supplier->code}.");
    }

    public function show(Supplier $supplier): View
    {
        $selesai = $supplier->purchases()->selesai();

        return view('suppliers.show', [
            'supplier' => $supplier,
            'ringkasan' => [
                'jumlah' => (clone $selesai)->count(),
                'total' => (float) (clone $selesai)->sum('grand_total'),
                'hutang' => (float) (clone $selesai)->sum('balance'),
            ],
            'pembelian' => $supplier->purchases()->orderByDesc('purchase_date')->orderByDesc('id')->limit(10)->get(),
        ]);
    }

    public function edit(Supplier $supplier): View
    {
        return view('suppliers.form', ['supplier' => $supplier]);
    }

    public function update(SupplierRequest $request, Supplier $supplier): RedirectResponse
    {
        $this->service->perbarui($supplier, $request->validated());

        return redirect()->route('suppliers.show', $supplier)
            ->with('success', "Data {$supplier->name} berhasil diperbarui.");
    }

    public function destroy(Supplier $supplier): RedirectResponse
    {
        $this->service->hapus($supplier);

        return redirect()->route('suppliers.index')
            ->with('success', "Supplier {$supplier->name} dihapus.");
    }
}

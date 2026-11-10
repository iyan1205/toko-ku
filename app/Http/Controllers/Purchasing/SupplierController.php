<?php

namespace App\Http\Controllers\Purchasing;

use App\Exports\ListExport;
use App\Exports\Purchasing\SuppliersExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\SupplierRequest;
use App\Models\Supplier;
use App\Services\SupplierService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class SupplierController extends Controller
{
    public function __construct(private readonly SupplierService $service) {}

    public function index(Request $request): View
    {
        return view('purchasing.suppliers.index', [
            'suppliers' => SuppliersExport::fromRequest($request, $this->filter($request))->paginate(15),
        ]);
    }

    public function export(Request $request): Response
    {
        $format = ListExport::formatFrom($request);

        return SuppliersExport::fromRequest($request, $this->filter($request))->download($format);
    }

    public function create(): View
    {
        return view('purchasing.suppliers.form', ['supplier' => new Supplier]);
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

        return view('purchasing.suppliers.show', [
            'supplier' => $supplier,
            'ringkasan' => [
                'jumlah' => (clone $selesai)->count(),
                'total' => (float) (clone $selesai)->sum('grand_total'),
                'hutang' => (float) (clone $selesai)->sum('balance'),
            ],
            'pembelian' => $supplier->purchases()->orderByDesc('purchase_date')->orderByDesc('id')->limit(10)->get(),
            'po' => $supplier->purchaseOrders()->whereIn('status', ['approved', 'partial'])->orderByDesc('order_date')->limit(5)->get(),
        ]);
    }

    public function edit(Supplier $supplier): View
    {
        return view('purchasing.suppliers.form', ['supplier' => $supplier]);
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

    /** @return array<string, mixed> */
    private function filter(Request $request): array
    {
        return $request->validate([
            'cari' => ['nullable', 'string', 'max:100'],
            'hutang' => ['nullable', Rule::in(['ada'])],
        ]);
    }
}

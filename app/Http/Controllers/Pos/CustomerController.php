<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;

use App\Enums\PaymentMethod;
use App\Exports\ListExport;
use App\Exports\Pos\CustomersExport;
use App\Http\Requests\CustomerRequest;
use App\Models\Customer;
use App\Services\CustomerService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/** Pelanggan: daftar dengan ekspor, detail (riwayat belanja dan piutang), tambah, ubah, hapus. */
class CustomerController extends Controller
{
    public function __construct(private readonly CustomerService $service) {}

    public function index(Request $request): View
    {
        $list = CustomersExport::fromRequest($request, $this->filter($request));

        return view('customers.index', [
            'list' => $list,
            'customers' => $list->paginate(15),
        ]);
    }

    public function export(Request $request): Response
    {
        $format = ListExport::formatFrom($request);

        return CustomersExport::fromRequest($request, $this->filter($request))->download($format);
    }

    public function create(): View
    {
        return view('customers.form', ['customer' => new Customer]);
    }

    public function store(CustomerRequest $request): RedirectResponse
    {
        $pelanggan = $this->service->buat($request->validated());

        return redirect()->route('customers.show', $pelanggan)
            ->with('success', "Pelanggan {$pelanggan->name} berhasil ditambahkan dengan kode {$pelanggan->code}.");
    }

    public function show(Customer $customer): View
    {
        $selesai = $customer->sales()->selesai();

        return view('customers.show', [
            'customer' => $customer,
            'ringkasan' => [
                'jumlah' => (clone $selesai)->count(),
                'total' => (float) (clone $selesai)->sum('grand_total'),
                'retur' => (float) (clone $selesai)->sum('returned_amount'),
                'piutang' => (float) (clone $selesai)->sum('balance'),
                'terakhir' => (clone $selesai)->max('sold_at'),
            ],
            'penjualan' => $customer->sales()->orderByDesc('sold_at')->orderByDesc('id')->limit(10)->get(),
            'piutang' => $customer->sales()->outstanding()->orderBy('sold_at')->get(),
            'methods' => PaymentMethod::untukPembelian(),
        ]);
    }

    public function edit(Customer $customer): View
    {
        return view('customers.form', ['customer' => $customer]);
    }

    public function update(CustomerRequest $request, Customer $customer): RedirectResponse
    {
        $this->service->perbarui($customer, $request->validated());

        return redirect()->route('customers.show', $customer)
            ->with('success', "Data {$customer->name} berhasil diperbarui.");
    }

    public function destroy(Customer $customer): RedirectResponse
    {
        $this->service->hapus($customer);

        return redirect()->route('customers.index')
            ->with('success', "Pelanggan {$customer->name} dihapus.");
    }

    /** @return array<string, mixed> */
    private function filter(Request $request): array
    {
        return $request->validate([
            'cari' => ['nullable', 'string', 'max:100'],
            'piutang' => ['nullable', 'in:ada'],
        ]);
    }
}

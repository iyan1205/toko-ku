<?php

namespace App\Http\Controllers;

use App\Http\Requests\CustomerRequest;
use App\Models\Customer;
use App\Services\CustomerService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CustomerController extends Controller
{
    public function __construct(private readonly CustomerService $service)
    {
    }

    public function index(Request $request): View
    {
        $cari = $request->validate(['cari' => ['nullable', 'string', 'max:100']])['cari'] ?? null;

        return view('customers.index', [
            'customers' => Customer::query()->cari($cari)->orderByDesc('is_default')->orderBy('name')->paginate(10)->withQueryString(),
        ]);
    }

    public function create(): View
    {
        return view('customers.form', ['customer' => new Customer()]);
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
                'piutang' => (float) (clone $selesai)->sum('balance'),
            ],
            'penjualan' => $customer->sales()->orderByDesc('sold_at')->orderByDesc('id')->limit(10)->get(),
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
}

<?php

namespace App\Http\Controllers;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PurchaseStatus;
use App\Http\Requests\SaleRequest;
use App\Http\Requests\VoidSaleRequest;
use App\Models\Customer;
use App\Models\Sale;
use App\Services\SaleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SaleController extends Controller
{
    public function __construct(private readonly SaleService $service)
    {
    }

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Sale::class);

        $filter = $request->validate([
            'cari' => ['nullable', 'string', 'max:100'],
            'pelanggan' => ['nullable', 'integer', 'exists:customers,id'],
            'metode' => ['nullable', Rule::in(array_map(fn ($m) => $m->value, PaymentMethod::cases()))],
            'status' => ['nullable', Rule::in(array_map(fn ($s) => $s->value, PurchaseStatus::cases()))],
            'dari' => ['nullable', 'date'],
            'sampai' => ['nullable', 'date', 'after_or_equal:dari'],
        ]);

        $user = $request->user();
        $hanyaMilikSendiri = ! $user->can('view-sales');

        if ($hanyaMilikSendiri) {
            $filter['kasir'] = $user->id;
        }

        $aktif = Sale::query()->filter($filter)->selesai();

        return view('sales.index', [
            'penjualan' => Sale::query()->with(['customer', 'user'])->filter($filter)
                ->orderByDesc('sold_at')->orderByDesc('id')->paginate(15)->withQueryString(),
            'customers' => Customer::orderBy('name')->get(['id', 'name']),
            'hanyaMilikSendiri' => $hanyaMilikSendiri,
            'ringkasan' => [
                'omzet' => (float) (clone $aktif)->sum('grand_total'),
                'piutang' => (float) (clone $aktif)->sum('balance'),
            ],
        ]);
    }

    public function show(Sale $sale): View
    {
        Gate::authorize('view', $sale);

        return view('sales.show', [
            'penjualan' => $sale->load(['customer', 'user', 'voidedBy', 'items', 'payments.user']),
        ]);
    }

    public function store(SaleRequest $request): RedirectResponse
    {
        $penjualan = $this->service->buat($request->validated(), $request->user());

        $pesan = "Penjualan {$penjualan->number} tersimpan.";
        if ($penjualan->payment_method === PaymentMethod::Cash && (float) $penjualan->change_amount > 0) {
            $pesan .= ' Kembalian ' . \App\Support\Format::rupiah($penjualan->change_amount) . '.';
        }

        return redirect()->route('sales.show', $penjualan)->with('success', $pesan);
    }

    public function void(VoidSaleRequest $request, Sale $sale): RedirectResponse
    {
        Gate::authorize('void', $sale);

        $this->service->batalkan($sale, $request->input('alasan'), $request->user());

        return redirect()->route('sales.show', $sale)->with('success', "Penjualan {$sale->number} dibatalkan dan stok dikembalikan.");
    }
}

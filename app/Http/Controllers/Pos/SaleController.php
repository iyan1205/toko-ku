<?php

namespace App\Http\Controllers\Pos;

use App\Enums\PaymentMethod;
use App\Exports\ListExport;
use App\Exports\Pos\SalesExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\SaleRequest;
use App\Http\Requests\VoidSaleRequest;
use App\Models\Customer;
use App\Models\Sale;
use App\Models\User;
use App\Services\SaleService;
use App\Support\Format;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Riwayat transaksi kasir, detail, penyimpanan dari layar Kasir, dan pembatalan.
 * pos.transactions.view-own hanya melihat transaksinya sendiri (filter kasir dipaksa).
 */
class SaleController extends Controller
{
    public function __construct(private readonly SaleService $service) {}

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Sale::class);

        $filter = $this->filter($request);
        $list = SalesExport::fromRequest($request, $filter);
        $aktif = Sale::query()->filter($filter)->selesai();

        return view('sales.index', [
            'list' => $list,
            'penjualan' => $list->paginate(15),
            'customers' => Customer::orderByDesc('is_default')->orderBy('name')->get(['id', 'name']),
            'cashiers' => $this->ownOnly($request) ? collect() : User::withTrashed()->whereIn('id', Sale::query()->select('user_id')->distinct())->orderBy('name')->get(['id', 'name']),
            'methods' => collect(PaymentMethod::cases())->mapWithKeys(fn (PaymentMethod $m) => [$m->value => $m->label()]),
            'hanyaMilikSendiri' => $this->ownOnly($request),
            'ringkasan' => [
                'omzet' => (float) (clone $aktif)->sum('grand_total'),
                'jumlah' => (clone $aktif)->count(),
                'retur' => (float) (clone $aktif)->sum('returned_amount'),
                'piutang' => (float) (clone $aktif)->sum('balance'),
            ],
        ]);
    }

    public function export(Request $request): Response
    {
        $format = ListExport::formatFrom($request);

        return SalesExport::fromRequest($request, $this->filter($request))->download($format);
    }

    public function show(Sale $sale): View
    {
        Gate::authorize('view', $sale);

        return view('sales.show', [
            'penjualan' => $sale->load([
                'customer', 'user', 'voidedBy', 'warehouse', 'cashSession', 'items',
                'payments.user', 'returns.user',
            ]),
            'metode' => PaymentMethod::untukPembelian(),
        ]);
    }

    /**
     * Dari layar Kasir (fetch, Accept: application/json): JSON berisi nomor, kembalian, dan tautan struk,
     * supaya keranjang tidak hilang bila ditolak. Dari formulir biasa: diarahkan ke detail penjualan.
     */
    public function store(SaleRequest $request): RedirectResponse|JsonResponse
    {
        $penjualan = $this->service->buat($request->validated(), $request->user());

        $pesan = "Penjualan {$penjualan->number} tersimpan.";
        if ((float) $penjualan->change_amount > 0) {
            $pesan .= ' Kembalian '.Format::rupiah($penjualan->change_amount).'.';
        }

        if ($request->expectsJson()) {
            return response()->json([
                'id' => $penjualan->id,
                'number' => $penjualan->number,
                'grand_total' => (float) $penjualan->grand_total,
                'change' => (float) $penjualan->change_amount,
                'balance' => (float) $penjualan->balance,
                'message' => $pesan,
                'receipt_url' => $request->user()->can('pos.transactions.print') ? route('sales.receipt', $penjualan) : null,
                'show_url' => route('sales.show', $penjualan),
            ], 201);
        }

        return redirect()->route('sales.show', $penjualan)->with('success', $pesan);
    }

    public function void(VoidSaleRequest $request, Sale $sale): RedirectResponse
    {
        Gate::authorize('void', $sale);

        $this->service->batalkan($sale, $request->input('alasan'), $request->user());

        return redirect()->route('sales.show', $sale)->with('success', "Penjualan {$sale->number} dibatalkan dan stok dikembalikan.");
    }

    private function ownOnly(Request $request): bool
    {
        return ! $request->user()->can('pos.transactions.view');
    }

    /** @return array<string, mixed> */
    private function filter(Request $request): array
    {
        $filter = $request->validate([
            'cari' => ['nullable', 'string', 'max:100'],
            'pelanggan' => ['nullable', 'integer', 'exists:customers,id'],
            'kasir' => ['nullable', 'integer', 'exists:users,id'],
            'metode' => ['nullable', Rule::in(array_map(fn ($m) => $m->value, PaymentMethod::cases()))],
            'status' => ['nullable', Rule::in(array_keys(SalesExport::STATUSES))],
            'sesi' => ['nullable', 'integer'],
            'dari' => ['nullable', 'date'],
            'sampai' => ['nullable', 'date', 'after_or_equal:dari'],
        ]);

        if ($this->ownOnly($request)) {
            $filter['kasir'] = $request->user()->id;
        }

        return $filter;
    }
}

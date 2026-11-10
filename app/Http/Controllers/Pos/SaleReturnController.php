<?php

namespace App\Http\Controllers\Pos;

use App\Enums\PaymentMethod;
use App\Enums\SaleReturnStatus;
use App\Exports\ListExport;
use App\Exports\Pos\SaleReturnsExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\ApproveSaleReturnRequest;
use App\Http\Requests\Pos\SaleReturnRequest;
use App\Models\Customer;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Services\Pos\SaleReturnService;
use App\Support\Format;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Retur penjualan: daftar, ajukan (draft), setujui (stok kembali, piutang/uang dikoreksi), hapus draft.
 * Hak per retur dicek SaleReturnPolicy; transaksi asal harus bisa dilihat pengguna (SalePolicy).
 */
class SaleReturnController extends Controller
{
    public function __construct(private readonly SaleReturnService $returns) {}

    public function index(Request $request): View
    {
        $list = SaleReturnsExport::fromRequest($request, $this->filter($request));

        return view('pos.returns.index', [
            'list' => $list,
            'returns' => $list->paginate(15),
            'customers' => Customer::orderBy('name')->get(['id', 'name']),
            'statuses' => collect(SaleReturnStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()]),
            'pending' => SaleReturn::query()->filter($this->filter($request, withStatus: false))->where('status', SaleReturnStatus::Draft->value)->count(),
        ]);
    }

    public function export(Request $request): Response
    {
        $format = ListExport::formatFrom($request);

        return SaleReturnsExport::fromRequest($request, $this->filter($request))->download($format);
    }

    /** Tanpa ?penjualan: pilih transaksi. Dengan ?penjualan: formulir retur barang dari transaksi itu. */
    public function create(Request $request): View
    {
        $penjualanId = $request->integer('penjualan');

        if (! $penjualanId) {
            $cari = $request->validate(['cari' => ['nullable', 'string', 'max:100']])['cari'] ?? null;
            $filter = ['cari' => $cari] + ($request->user()->can('pos.transactions.view') ? [] : ['kasir' => $request->user()->id]);

            return view('pos.returns.pick', [
                'sales' => Sale::query()->with('customer:id,name')->selesai()
                    ->whereHas('items', fn (Builder $q) => $q->whereColumn('returned_quantity', '<', 'quantity'))
                    ->filter($filter)
                    ->orderByDesc('sold_at')->orderByDesc('id')
                    ->paginate(15)->withQueryString(),
            ]);
        }

        $penjualan = Sale::with(['customer', 'warehouse', 'items'])->selesai()->findOrFail($penjualanId);
        Gate::authorize('view', $penjualan);

        return view('pos.returns.create', [
            'penjualan' => $penjualan,
            'methods' => PaymentMethod::untukPembelian(),
        ]);
    }

    public function store(SaleReturnRequest $request): RedirectResponse
    {
        $penjualan = Sale::findOrFail($request->integer('sale_id'));
        Gate::authorize('view', $penjualan);

        $setujui = $request->input('aksi') === 'approve' && $request->user()->can('pos.returns.approve');
        $retur = $this->returns->create($penjualan, $request->validated(), $request->user(), $setujui);

        return redirect()->route('sale-returns.show', $retur)->with('success', $setujui
            ? $this->approvedMessage($retur)
            : "Retur {$retur->number} diajukan. Stok dan uang baru berubah setelah retur disetujui.");
    }

    public function show(SaleReturn $saleReturn): View
    {
        Gate::authorize('view', $saleReturn);

        $saleReturn->load(['sale', 'customer', 'warehouse', 'user', 'approvedBy', 'items', 'payments']);

        return view('pos.returns.show', [
            'retur' => $saleReturn,
            'methods' => PaymentMethod::untukPembelian(),
            // Perkiraan uang yang dikembalikan bila disetujui sekarang (piutang transaksi dikurangi dulu).
            'estimatedRefund' => max(0, (float) $saleReturn->total - (float) $saleReturn->sale->balance),
        ]);
    }

    public function approve(ApproveSaleReturnRequest $request, SaleReturn $saleReturn): RedirectResponse
    {
        Gate::authorize('approve', $saleReturn);

        $method = PaymentMethod::tryFrom((string) $request->validated('refund_method')) ?? PaymentMethod::Cash;
        $retur = $this->returns->approve($saleReturn, $request->user(), $method);

        return redirect()->route('sale-returns.show', $retur)->with('success', $this->approvedMessage($retur));
    }

    public function destroy(Request $request, SaleReturn $saleReturn): RedirectResponse
    {
        Gate::authorize('delete', $saleReturn);

        $this->returns->delete($saleReturn, $request->user());

        return redirect()->route('sale-returns.index')->with('success', "Pengajuan retur {$saleReturn->number} dihapus.");
    }

    private function approvedMessage(SaleReturn $retur): string
    {
        $pesan = "Retur {$retur->number} disetujui dan stok sudah kembali.";
        if ((float) $retur->receivable_reduction > 0) {
            $pesan .= ' Piutang berkurang '.Format::rupiah($retur->receivable_reduction).'.';
        }
        if ((float) $retur->refund_amount > 0) {
            $pesan .= ' Kembalikan '.Format::rupiah($retur->refund_amount).' ('.$retur->refund_method?->label().') ke pelanggan.';
        }

        return $pesan;
    }

    /** @return array<string, mixed> */
    private function filter(Request $request, bool $withStatus = true): array
    {
        $filter = $request->validate([
            'cari' => ['nullable', 'string', 'max:100'],
            'pelanggan' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::enum(SaleReturnStatus::class)],
            'dari' => ['nullable', 'date'],
            'sampai' => ['nullable', 'date', 'after_or_equal:dari'],
        ]);

        if (! $withStatus) {
            unset($filter['status']);
        }

        if (Gate::denies('viewAll', SaleReturn::class)) {
            $filter['kasir'] = $request->user()->id;
        }

        return $filter;
    }
}

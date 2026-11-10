<?php

namespace App\Http\Controllers\Purchasing;

use App\Enums\PurchaseReturnStatus;
use App\Exports\ListExport;
use App\Exports\Purchasing\PurchaseReturnsExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Purchasing\PurchaseReturnRequest;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Services\Purchasing\PurchaseReturnService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Retur pembelian: create (draft), delete (hapus draft), approve (stok keluar & hutang berkurang).
 * Izin purchasing.returns.* dipasang di routes/modules/purchasing.php.
 */
class PurchaseReturnController extends Controller
{
    public function __construct(private readonly PurchaseReturnService $returns) {}

    public function index(Request $request): View
    {
        return view('purchasing.returns.index', [
            'returns' => PurchaseReturnsExport::fromRequest($request, $this->filter($request))->paginate(15),
            'suppliers' => Supplier::orderBy('name')->get(['id', 'name']),
            'gudang' => Warehouse::query()->ordered()->get(['id', 'name']),
        ]);
    }

    public function export(Request $request): Response
    {
        $format = ListExport::formatFrom($request);

        return PurchaseReturnsExport::fromRequest($request, $this->filter($request))->download($format);
    }

    /** Tanpa ?faktur: pilih faktur. Dengan ?faktur: formulir retur barang dari faktur itu. */
    public function create(Request $request): View
    {
        $fakturId = $request->integer('faktur');

        if (! $fakturId) {
            $cari = $request->validate(['cari' => ['nullable', 'string', 'max:100']])['cari'] ?? null;

            return view('purchasing.returns.pick', [
                'purchases' => Purchase::query()->with('supplier:id,name')->selesai()
                    ->whereHas('items', fn ($q) => $q->whereColumn('returned_quantity', '<', 'quantity'))
                    ->filter(['cari' => $cari])
                    ->orderByDesc('purchase_date')->orderByDesc('id')
                    ->paginate(15)->withQueryString(),
            ]);
        }

        $faktur = Purchase::with(['supplier', 'warehouse', 'items'])->selesai()->findOrFail($fakturId);

        return view('purchasing.returns.create', [
            'faktur' => $faktur,
            'gudang' => Warehouse::query()->active()->ordered()->get(['id', 'name']),
        ]);
    }

    public function store(PurchaseReturnRequest $request): RedirectResponse
    {
        $setujui = $request->input('aksi') === 'approve';
        $faktur = Purchase::findOrFail($request->integer('purchase_id'));
        $retur = $this->returns->create($faktur, $request->validated(), $request->user(), $setujui);

        return redirect()->route('purchase-returns.show', $retur)->with('success', $setujui
            ? "Retur {$retur->number} disetujui. Stok berkurang dan hutang faktur {$faktur->number} disesuaikan."
            : "Draft retur {$retur->number} tersimpan. Stok dan hutang berubah setelah retur disetujui.");
    }

    public function show(PurchaseReturn $purchaseReturn): View
    {
        return view('purchasing.returns.show', [
            'retur' => $purchaseReturn->load(['purchase', 'supplier', 'warehouse', 'user', 'approvedBy', 'items']),
        ]);
    }

    public function approve(Request $request, PurchaseReturn $purchaseReturn): RedirectResponse
    {
        $retur = $this->returns->approve($purchaseReturn, $request->user());

        return redirect()->route('purchase-returns.show', $retur)
            ->with('success', "Retur {$retur->number} disetujui. Stok berkurang dan hutang faktur disesuaikan.");
    }

    public function destroy(Request $request, PurchaseReturn $purchaseReturn): RedirectResponse
    {
        $this->returns->delete($purchaseReturn, $request->user());

        return redirect()->route('purchase-returns.index')->with('success', "Draft retur {$purchaseReturn->number} dihapus.");
    }

    /** @return array<string, mixed> */
    private function filter(Request $request): array
    {
        return $request->validate([
            'cari' => ['nullable', 'string', 'max:100'],
            'supplier' => ['nullable', 'integer', 'exists:suppliers,id'],
            'gudang' => ['nullable', 'integer', 'exists:warehouses,id'],
            'status' => ['nullable', Rule::in(array_map(fn ($s) => $s->value, PurchaseReturnStatus::cases()))],
            'dari' => ['nullable', 'date'],
            'sampai' => ['nullable', 'date', 'after_or_equal:dari'],
        ]);
    }
}

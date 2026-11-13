<?php

namespace App\Http\Controllers\Inventory;

use App\Enums\StockTransferStatus;
use App\Exports\Inventory\StockTransfersExport;
use App\Exports\ListExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\StockTransferRequest;
use App\Models\Scopes\BranchScope;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\Warehouse;
use App\Services\Inventory\ProductSearch;
use App\Services\Inventory\StockTransferService;
use App\Services\Settings\StoreProfileService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/** Transfer stok antar gudang. Izin inventory.transfers.* dipasang di routes/modules/inventory.php. */
class StockTransferController extends Controller
{
    public function __construct(private readonly StockTransferService $transfers) {}

    public function index(Request $request): View
    {
        return view('inventory.transfers.index', [
            'transfers' => StockTransfersExport::fromRequest($request, $this->filter($request))->paginate(15),
            // Barang dalam perjalanan (transfer antar cabang yang belum diterima) dan nilainya.
            'dalamPerjalanan' => $this->inTransitSummary(),
            'gudang' => Warehouse::query()->inCurrentBranch()->ordered()->get(['id', 'name']),
        ]);
    }

    public function create(Request $request): View
    {
        // Gudang asal = gudang cabang aktif; gudang tujuan boleh cabang mana pun.
        $semuaGudang = Warehouse::query()->active()->ordered()->get(['id', 'name', 'is_default', 'branch_id']);
        $gudang = Warehouse::query()->active()->inCurrentBranch()->ordered()->get(['id', 'name', 'is_default']);

        // Baris dari old input setelah validasi gagal; stok dimuat ulang oleh formulir.
        $items = app(ProductSearch::class)->formLines(old('items', []));

        return view('inventory.transfers.create', [
            'gudang' => $gudang,
            'gudangTujuan' => $semuaGudang,
            'dari' => old('from_warehouse_id', $request->integer('dari') ?: ($gudang->firstWhere('is_default', true) ?? $gudang->first())?->id),
            'items' => $items,
        ]);
    }

    public function store(StockTransferRequest $request): RedirectResponse
    {
        $transfer = $this->transfers->create($request->validated(), $request->user());

        return redirect()->route('stock-transfers.show', $transfer)
            ->with('success', "Transfer {$transfer->number} selesai. {$transfer->items->count()} produk dipindahkan.");
    }

    public function show(StockTransfer $stockTransfer): View
    {
        return view('inventory.transfers.show', [
            'transfer' => $stockTransfer->load(['fromWarehouse', 'toWarehouse', 'user', 'voidedBy', 'receivedBy', 'items']),
        ]);
    }

    public function void(Request $request, StockTransfer $stockTransfer): RedirectResponse
    {
        $request->validate(['alasan' => ['required', 'string', 'min:5', 'max:255']], [], ['alasan' => 'alasan pembatalan']);

        $this->transfers->void($stockTransfer, $request->input('alasan'), $request->user());

        return redirect()->route('stock-transfers.show', $stockTransfer)
            ->with('success', "Transfer {$stockTransfer->number} dibatalkan dan stok dikembalikan ke gudang asal.");
    }

    /** Terima kiriman antar cabang di gudang tujuan (jumlah per baris; kurang = selisih). */
    public function receive(Request $request, StockTransfer $stockTransfer): RedirectResponse
    {
        $data = $request->validate([
            'received' => ['nullable', 'array'],
            'received.*' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'receive_note' => ['nullable', 'string', 'max:255'],
        ], [], ['received.*' => 'jumlah diterima']);

        $transfer = $this->transfers->receive($stockTransfer, $data['received'] ?? [], $data['receive_note'] ?? null, $request->user());

        return redirect()->route('stock-transfers.show', $stockTransfer)->with('success', $transfer->fresh('items')->hasShortage()
            ? "Transfer {$transfer->number} diterima dengan kekurangan; selisihnya dicatat sebagai selisih persediaan."
            : "Transfer {$transfer->number} diterima lengkap di {$transfer->toWarehouse->name}.");
    }

    /** @return array{count: int, value: float} */
    private function inTransitSummary(): array
    {
        $ids = StockTransfer::query()->visible()->where('status', StockTransferStatus::InTransit->value)->pluck('id');

        return [
            'count' => $ids->count(),
            'value' => abs((float) StockMovement::query()->withoutGlobalScope(BranchScope::class)
                ->where('reference_type', (new StockTransfer)->getMorphClass())->whereIn('reference_id', $ids)
                ->where('quantity', '<', 0)->sum('total_cost')),
        ];
    }

    /** Surat jalan (PDF) untuk menyertai barang yang dipindahkan. */
    public function print(StockTransfer $stockTransfer, StoreProfileService $store): Response
    {
        $stockTransfer->load(['fromWarehouse', 'toWarehouse', 'user', 'items']);

        return Pdf::loadView('inventory.transfers.pdf', [
            'transfer' => $stockTransfer,
            'store' => $store->ambil(),
        ])->setPaper('a4')->stream("surat-jalan-{$stockTransfer->number}.pdf");
    }

    public function export(Request $request): Response
    {
        $format = ListExport::formatFrom($request);

        return StockTransfersExport::fromRequest($request, $this->filter($request))->download($format);
    }

    /** @return array<string, mixed> */
    private function filter(Request $request): array
    {
        return $request->validate([
            'cari' => ['nullable', 'string', 'max:100'],
            'gudang' => ['nullable', 'integer', 'exists:warehouses,id'],
            'status' => ['nullable', Rule::in(array_map(fn ($s) => $s->value, StockTransferStatus::cases()))],
            'dari' => ['nullable', 'date'],
            'sampai' => ['nullable', 'date', 'after_or_equal:dari'],
        ]);
    }
}

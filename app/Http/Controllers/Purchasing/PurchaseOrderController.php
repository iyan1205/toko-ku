<?php

namespace App\Http\Controllers\Purchasing;

use App\Enums\PurchaseOrderStatus;
use App\Exceptions\AturanBisnisException;
use App\Exports\ListExport;
use App\Exports\Purchasing\PurchaseOrdersExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Purchasing\PurchaseOrderRequest;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Services\Approval\ApprovalService;
use App\Services\Inventory\ProductSearch;
use App\Services\Purchasing\PurchaseOrderService;
use App\Services\Settings\StoreProfileService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Purchase order. Izin purchasing.orders.*: create/update/delete untuk draft,
 * approve untuk menyetujui, membatalkan, dan menutup PO.
 */
class PurchaseOrderController extends Controller
{
    public function __construct(private readonly PurchaseOrderService $orders) {}

    public function index(Request $request): View
    {
        $list = PurchaseOrdersExport::fromRequest($request, $this->filter($request));

        return view('purchasing.orders.index', [
            'list' => $list,
            'orders' => $list->paginate(15),
            'suppliers' => Supplier::orderBy('name')->get(['id', 'name']),
            'gudang' => Warehouse::query()->inCurrentBranch()->ordered()->get(['id', 'name']),
        ]);
    }

    public function export(Request $request): Response
    {
        $format = ListExport::formatFrom($request);

        return PurchaseOrdersExport::fromRequest($request, $this->filter($request))->download($format);
    }

    public function create(Request $request, ProductSearch $search): View
    {
        return view('purchasing.orders.form', $this->formData(
            new PurchaseOrder([
                'supplier_id' => $request->integer('supplier') ?: null,
                'warehouse_id' => Warehouse::forCurrentBranch()->id,
                'order_date' => now(),
            ]),
            $search->formLines(old('items', [])),
        ));
    }

    /** aksi: draft | approve (butuh izin approve) | submit (ajukan ke alur persetujuan). */
    public function store(PurchaseOrderRequest $request): RedirectResponse
    {
        [$setujui, $ajukan] = $this->aksi($request);
        $order = $this->orders->create($request->validated(), $request->user(), $setujui, $ajukan);

        return redirect()->route('purchase-orders.show', $order)->with('success', $this->savedMessage($order, $ajukan));
    }

    public function show(PurchaseOrder $purchaseOrder, ApprovalService $approvals): View
    {
        return view('purchasing.orders.show', [
            'approvalFlow' => $purchaseOrder->isDraft() ? $approvals->flowFor($purchaseOrder) : null,
            'order' => $purchaseOrder->load([
                'supplier', 'warehouse', 'user', 'approvedBy', 'closedBy', 'cancelledBy', 'items',
                'receipts' => fn ($q) => $q->with(['warehouse:id,name', 'purchase:id,number,grand_total,balance,payment_status'])->withCount('items'),
            ]),
        ]);
    }

    public function edit(PurchaseOrder $purchaseOrder, ProductSearch $search): View|RedirectResponse
    {
        if (! $purchaseOrder->isDraft()) {
            return redirect()->route('purchase-orders.show', $purchaseOrder)->with('error', 'Hanya draft yang bisa diubah.');
        }

        $lines = old('items') !== null
            ? $search->formLines(old('items'))
            : $search->formLines($purchaseOrder->items->map(fn ($i) => $i->only(['product_id', 'quantity', 'unit_price', 'discount'])));

        return view('purchasing.orders.form', $this->formData($purchaseOrder, $lines));
    }

    public function update(PurchaseOrderRequest $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        [$setujui, $ajukan] = $this->aksi($request);
        $order = $this->orders->update($purchaseOrder, $request->validated(), $request->user(), $setujui, $ajukan);

        return redirect()->route('purchase-orders.show', $order)->with('success', $this->savedMessage($order, $ajukan));
    }

    /** Ajukan draft ke alur persetujuan (Pengaturan → Alur Persetujuan). */
    public function submit(Request $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $data = $request->validate(['catatan' => ['nullable', 'string', 'max:255']]);
        $order = $this->orders->submit($purchaseOrder, $request->user(), $data['catatan'] ?? null);

        return redirect()->route('purchase-orders.show', $order)->with('success', $this->savedMessage($order, true));
    }

    public function destroy(Request $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $this->orders->delete($purchaseOrder, $request->user());

        return redirect()->route('purchase-orders.index')->with('success', "Draft {$purchaseOrder->number} dihapus.");
    }

    public function approve(Request $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $this->orders->approve($purchaseOrder, $request->user());

        return redirect()->route('purchase-orders.show', $purchaseOrder)->with('success', "Purchase order {$purchaseOrder->number} disetujui.");
    }

    public function cancel(Request $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $request->validate(['alasan' => ['required', 'string', 'min:5', 'max:255']], [], ['alasan' => 'alasan']);
        $this->orders->cancel($purchaseOrder, $request->input('alasan'), $request->user());

        return redirect()->route('purchase-orders.show', $purchaseOrder)->with('success', "Purchase order {$purchaseOrder->number} dibatalkan.");
    }

    public function close(Request $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $request->validate(['alasan' => ['required', 'string', 'min:5', 'max:255']], [], ['alasan' => 'alasan']);
        $this->orders->close($purchaseOrder, $request->input('alasan'), $request->user());

        return redirect()->route('purchase-orders.show', $purchaseOrder)->with('success', "Purchase order {$purchaseOrder->number} ditutup. Sisa barang tidak lagi ditunggu.");
    }

    /** PO untuk dikirim ke supplier (PDF). Draft dicetak dengan tanda DRAFT. */
    public function print(PurchaseOrder $purchaseOrder, StoreProfileService $store): Response
    {
        if ($purchaseOrder->status === PurchaseOrderStatus::Cancelled) {
            throw new AturanBisnisException('Purchase order yang dibatalkan tidak bisa dicetak.');
        }

        return Pdf::loadView('purchasing.orders.pdf', [
            'order' => $purchaseOrder->load(['supplier', 'warehouse', 'user', 'approvedBy', 'items']),
            'store' => $store->ambil(),
        ])->setPaper('a4')->stream("{$purchaseOrder->number}.pdf");
    }

    /** @return array{0: bool, 1: bool} [setujui, ajukan]. Izin approve untuk aksi=approve dicek PurchaseOrderRequest. */
    private function aksi(Request $request): array
    {
        $aksi = $request->input('aksi');

        return [$aksi === 'approve', $aksi === 'submit'];
    }

    private function savedMessage(PurchaseOrder $order, bool $ajukan): string
    {
        return match ($order->status) {
            PurchaseOrderStatus::Approved => "Purchase order {$order->number} disimpan dan disetujui. Barang bisa diterima saat tiba.",
            PurchaseOrderStatus::Submitted => "Purchase order {$order->number} perlu persetujuan berjenjang dan sudah diajukan. Penyetuju diberi notifikasi.",
            default => $ajukan
                ? "Draft purchase order {$order->number} tersimpan. PO ini tidak memerlukan alur persetujuan; minta pengguna dengan izin menyetujui."
                : "Draft purchase order {$order->number} tersimpan.",
        };
    }

    /** @return array<string, mixed> */
    private function filter(Request $request): array
    {
        return $request->validate([
            'cari' => ['nullable', 'string', 'max:100'],
            'supplier' => ['nullable', 'integer', 'exists:suppliers,id'],
            'gudang' => ['nullable', 'integer', 'exists:warehouses,id'],
            'status' => ['nullable', Rule::in(array_map(fn ($s) => $s->value, PurchaseOrderStatus::cases()))],
            'dari' => ['nullable', 'date'],
            'sampai' => ['nullable', 'date', 'after_or_equal:dari'],
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return array<string, mixed>
     */
    private function formData(PurchaseOrder $order, array $lines): array
    {
        return [
            'order' => $order,
            'items' => $lines,
            'suppliers' => Supplier::orderBy('name')->get(['id', 'name']),
            'gudang' => Warehouse::query()->active()->inCurrentBranch()->ordered()->get(['id', 'name']),
        ];
    }
}

<?php

namespace App\Services\Inventory;

use App\Enums\PurchaseOrderStatus;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Services\Purchasing\PurchaseOrderService;
use App\Support\Uang;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Stok minimum/maksimum per gudang dan saran pemesanan ulang.
 *
 *   minimum  = warehouse_stocks.min_stock; tanpa itu gudang utama cabang memakai stok minimum produk.
 *   target   = warehouse_stocks.max_stock; tanpa itu 2 × minimum.
 *   dipesan  = sisa PO yang disetujui/diterima sebagian ke gudang itu.
 *   saran    = target − (stok + dipesan), bila stok + dipesan ≤ minimum.
 *
 * Saran yang dipilih dibuat menjadi draft PO, satu per supplier & gudang (harga = harga beli produk).
 */
class ReorderService
{
    public function __construct(private readonly PurchaseOrderService $orders) {}

    /**
     * @param  list<int>  $warehouseIds
     * @return Collection<int, array{product: Product, warehouse: Warehouse, stock: float, on_order: float, min: float, max: float, suggested: float, supplier_id: ?int}>
     */
    public function suggestions(array $warehouseIds): Collection
    {
        $warehouses = Warehouse::query()->whereIn('id', $warehouseIds)->get()->keyBy('id');
        $default = Warehouse::default();
        $levels = WarehouseStock::query()->whereIn('warehouse_id', $warehouseIds)->get()->keyBy(fn ($w) => $w->product_id.':'.$w->warehouse_id);
        $products = Product::aktif()->stockable()->with('unit:id,name')->orderBy('name')->get();
        $onOrder = DB::table('purchase_order_items')->join('purchase_orders', 'purchase_orders.id', '=', 'purchase_order_items.purchase_order_id')
            ->whereIn('purchase_orders.status', [PurchaseOrderStatus::Approved->value, PurchaseOrderStatus::Partial->value])
            ->whereIn('purchase_orders.warehouse_id', $warehouseIds)
            ->groupBy('purchase_order_items.product_id', 'purchase_orders.warehouse_id')
            ->selectRaw('purchase_order_items.product_id, purchase_orders.warehouse_id, SUM(purchase_order_items.quantity - purchase_order_items.received_quantity) as qty')
            ->get()->keyBy(fn ($r) => $r->product_id.':'.$r->warehouse_id);
        $suppliers = $this->lastSuppliers($products->modelKeys());

        $rows = collect();
        foreach ($products as $product) {
            foreach ($warehouses as $warehouse) {
                $level = $levels->get($product->id.':'.$warehouse->id);
                $min = $level?->min_stock !== null ? (float) $level->min_stock : ($warehouse->is($default) ? (float) $product->min_stock : null);
                if ($min === null || $min <= 0) {
                    continue;
                }
                $stock = (float) ($level?->quantity ?? 0);
                $ordered = max(0, (float) ($onOrder->get($product->id.':'.$warehouse->id)?->qty ?? 0));
                if ($stock + $ordered > $min) {
                    continue;
                }
                $max = $level?->max_stock !== null ? (float) $level->max_stock : $min * 2;
                $rows->push([
                    'product' => $product, 'warehouse' => $warehouse, 'stock' => $stock, 'on_order' => $ordered, 'min' => $min, 'max' => $max,
                    'suggested' => round(max($max - $stock - $ordered, 0), 3), 'supplier_id' => $suppliers[$product->id] ?? null,
                ]);
            }
        }

        return $rows->filter(fn ($r) => $r['suggested'] > 0)->values();
    }

    /**
     * Buat draft PO dari baris saran: satu PO per supplier & gudang.
     *
     * @param  list<array{product_id: int, warehouse_id: int, supplier_id: int, quantity: mixed}>  $lines
     * @return Collection<int, PurchaseOrder>
     */
    public function createDrafts(array $lines, User $user): Collection
    {
        $prices = Product::query()->whereIn('id', array_column($lines, 'product_id'))->pluck('purchase_price', 'id');

        return DB::transaction(fn () => collect($lines)
            ->filter(fn ($l) => Uang::milli($l['quantity'] ?? 0) > 0)
            ->groupBy(fn ($l) => $l['supplier_id'].':'.$l['warehouse_id'])
            ->map(fn (Collection $group) => $this->orders->create([
                'supplier_id' => (int) $group->first()['supplier_id'],
                'warehouse_id' => (int) $group->first()['warehouse_id'],
                'order_date' => today()->toDateString(),
                'note' => 'Dari saran pemesanan ulang',
                'items' => $group->map(fn ($l) => [
                    'product_id' => (int) $l['product_id'], 'quantity' => (string) $l['quantity'],
                    'unit_price' => (string) ($prices[(int) $l['product_id']] ?? 0), 'discount' => '0',
                ])->values()->all(),
            ], $user))->values());
    }

    /** Simpan stok minimum/maksimum per produk di satu gudang. @param array<int, array{min?: mixed, max?: mixed}> $levels */
    public function saveLevels(Warehouse $warehouse, array $levels): int
    {
        $count = 0;
        DB::transaction(function () use ($warehouse, $levels, &$count) {
            foreach ($levels as $productId => $l) {
                $min = ($l['min'] ?? '') === '' ? null : round((float) $l['min'], 3);
                $max = ($l['max'] ?? '') === '' ? null : round((float) $l['max'], 3);
                DB::table('warehouse_stocks')->insertOrIgnore(['warehouse_id' => $warehouse->id, 'product_id' => (int) $productId, 'quantity' => 0, 'created_at' => now(), 'updated_at' => now()]);
                $count += DB::table('warehouse_stocks')->where('warehouse_id', $warehouse->id)->where('product_id', (int) $productId)
                    ->update(['min_stock' => $min, 'max_stock' => $max, 'updated_at' => now()]);
            }
        });

        return $count;
    }

    /** Supplier pembelian terakhir tiap produk. @param list<int> $productIds @return array<int, int> */
    private function lastSuppliers(array $productIds): array
    {
        return DB::table('purchase_items')->join('purchases', 'purchases.id', '=', 'purchase_items.purchase_id')
            ->whereIn('purchase_items.product_id', $productIds)->where('purchases.status', 'completed')
            ->orderBy('purchases.purchase_date')->orderBy('purchases.id')
            ->get(['purchase_items.product_id', 'purchases.supplier_id'])
            ->mapWithKeys(fn ($r) => [$r->product_id => $r->supplier_id])->all();
    }
}

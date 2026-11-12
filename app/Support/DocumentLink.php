<?php

namespace App\Support;

use App\Models\CashTransaction;
use App\Models\DepreciationRun;
use App\Models\FixedAsset;
use App\Models\GoodsReceipt;
use App\Models\Invoice;
use App\Models\OpeningBalance;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\Settlement;
use App\Models\StockMovement;
use App\Models\StockOpname;
use App\Models\StockTransfer;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Nomor dan tautan dokumen asal sebuah mutasi stok (kartu stok, ekspor).
 * Tautan hanya diberikan bila pengguna boleh membuka dokumen tersebut.
 */
final class DocumentLink
{
    /** @var array<class-string<Model>, array{route: string, permission: string|list<string>}> */
    private const ROUTES = [
        Sale::class => ['route' => 'sales.show', 'permission' => ['pos.transactions.view', 'pos.transactions.view-own']],
        SaleReturn::class => ['route' => 'sale-returns.show', 'permission' => ['pos.returns.view', 'pos.returns.create', 'pos.returns.approve']],
        Purchase::class => ['route' => 'purchases.show', 'permission' => 'purchasing.invoices.view'],
        GoodsReceipt::class => ['route' => 'goods-receipts.show', 'permission' => 'purchasing.receipts.view'],
        PurchaseReturn::class => ['route' => 'purchase-returns.show', 'permission' => 'purchasing.returns.view'],
        StockOpname::class => ['route' => 'stock-opnames.show', 'permission' => 'inventory.opnames.view'],
        StockTransfer::class => ['route' => 'stock-transfers.show', 'permission' => 'inventory.transfers.view'],
        OpeningBalance::class => ['route' => 'opening-balances.show', 'permission' => 'accounting.opening-balances.view'],
    ];

    /** Nomor dokumen, atau null bila mutasi tidak punya dokumen (penyesuaian manual). */
    public static function number(?Model $reference): ?string
    {
        if ($reference instanceof StockMovement) {
            return 'Kartu stok '.($reference->product?->name ?? '#'.$reference->product_id);
        }

        return $reference?->getAttribute('number');
    }

    public static function url(?Model $reference, ?User $user): ?string
    {
        // Dokumen Fase 10: rutenya bergantung jenis (faktur penjualan/tagihan, pelunasan piutang/hutang).
        if ($reference instanceof Invoice) {
            return $user?->can($reference->type->permission('view')) ? route($reference->type->routePrefix().'.show', $reference) : null;
        }
        if ($reference instanceof Settlement) {
            return $user?->can($reference->type->permission('view')) ? route($reference->type->routePrefix().'.show', $reference) : null;
        }
        if ($reference instanceof FixedAsset) {
            return $user?->can('accounting.assets.view') ? route('fixed-assets.show', $reference) : null;
        }
        if ($reference instanceof DepreciationRun) {
            return $user?->can('accounting.assets.view') ? route('depreciation-runs.show', $reference) : null;
        }
        if ($reference instanceof CashTransaction) {
            return $user?->can('accounting.cash-bank.view') ? route('cash-transactions.show', $reference) : null;
        }

        // Penyesuaian stok manual tidak punya dokumen: buka kartu stok produk di gudangnya.
        if ($reference instanceof StockMovement) {
            return $user?->can('inventory.stock-card.view')
                ? route('stock.movements', ['produk' => $reference->product_id, 'gudang' => $reference->warehouse_id])
                : null;
        }

        $target = $reference ? (self::ROUTES[$reference::class] ?? null) : null;

        if ($target === null || $user === null || ! $user->canAny((array) $target['permission'])) {
            return null;
        }

        return route($target['route'], $reference);
    }
}

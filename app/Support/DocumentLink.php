<?php

namespace App\Support;

use App\Models\GoodsReceipt;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Sale;
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
        Purchase::class => ['route' => 'purchases.show', 'permission' => 'purchasing.invoices.view'],
        GoodsReceipt::class => ['route' => 'goods-receipts.show', 'permission' => 'purchasing.receipts.view'],
        PurchaseReturn::class => ['route' => 'purchase-returns.show', 'permission' => 'purchasing.returns.view'],
        StockOpname::class => ['route' => 'stock-opnames.show', 'permission' => 'inventory.opnames.view'],
        StockTransfer::class => ['route' => 'stock-transfers.show', 'permission' => 'inventory.transfers.view'],
    ];

    /** Nomor dokumen, atau null bila mutasi tidak punya dokumen (penyesuaian manual). */
    public static function number(?Model $reference): ?string
    {
        return $reference?->getAttribute('number');
    }

    public static function url(?Model $reference, ?User $user): ?string
    {
        $target = $reference ? (self::ROUTES[$reference::class] ?? null) : null;

        if ($target === null || $user === null || ! $user->canAny((array) $target['permission'])) {
            return null;
        }

        return route($target['route'], $reference);
    }
}

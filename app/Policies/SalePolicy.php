<?php

namespace App\Policies;

use App\Models\Sale;
use App\Models\User;

/**
 * Admin (view-sales) melihat semua penjualan. Kasir (view-own-sales) hanya miliknya sendiri.
 * Void hanya untuk yang berizin void-sale: kasir tidak bisa membatalkan transaksi yang sudah selesai.
 */
class SalePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAny(['view-sales', 'view-own-sales']);
    }

    public function view(User $user, Sale $sale): bool
    {
        return $user->can('view-sales')
            || ($user->can('view-own-sales') && $sale->user_id === $user->id);
    }

    public function void(User $user, Sale $sale): bool
    {
        return $user->can('void-sale');
    }
}

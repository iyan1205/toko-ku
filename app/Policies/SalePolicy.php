<?php

namespace App\Policies;

use App\Models\Sale;
use App\Models\User;

/**
 * pos.transactions.view = semua penjualan; pos.transactions.view-own = hanya miliknya sendiri.
 * Void hanya untuk pemegang pos.transactions.void: kasir tidak bisa membatalkan transaksi yang sudah selesai.
 */
class SalePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAny(['pos.transactions.view', 'pos.transactions.view-own']);
    }

    public function view(User $user, Sale $sale): bool
    {
        return $user->can('pos.transactions.view')
            || ($user->can('pos.transactions.view-own') && $sale->user_id === $user->id);
    }

    public function void(User $user, Sale $sale): bool
    {
        return $user->can('pos.transactions.void');
    }
}

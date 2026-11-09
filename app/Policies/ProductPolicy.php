<?php

namespace App\Policies;

use App\Models\Product;
use App\Models\User;

/**
 * Produk punya dua tingkat akses: melihat (Kasir) dan mengelola (Admin).
 * Kasir tidak boleh mengubah produk maupun harga beli.
 */
class ProductPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('inventory.products.view');
    }

    public function view(User $user, Product $product): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->can('inventory.products.create');
    }

    public function update(User $user, Product $product): bool
    {
        return $user->can('inventory.products.update');
    }

    public function delete(User $user, Product $product): bool
    {
        return $user->can('inventory.products.delete');
    }

    public function import(User $user): bool
    {
        return $user->can('inventory.products.import');
    }

    public function export(User $user): bool
    {
        return $user->can('inventory.products.export');
    }
}

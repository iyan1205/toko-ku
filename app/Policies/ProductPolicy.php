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
        return $user->canAny(['view-products', 'manage-products']);
    }

    public function view(User $user, Product $product): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->can('manage-products');
    }

    public function update(User $user, Product $product): bool
    {
        return $user->can('manage-products');
    }

    public function delete(User $user, Product $product): bool
    {
        return $user->can('manage-products');
    }

    public function import(User $user): bool
    {
        return $user->can('manage-products');
    }

    public function export(User $user): bool
    {
        return $user->can('manage-products');
    }
}

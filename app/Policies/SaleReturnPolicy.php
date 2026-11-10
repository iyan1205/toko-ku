<?php

namespace App\Policies;

use App\Models\SaleReturn;
use App\Models\User;

/**
 * Semua retur terlihat oleh penyetuju (pos.returns.approve) dan pemegang pos.transactions.view.
 * Pengguna lain (misal kasir) hanya melihat retur yang ia ajukan sendiri.
 */
class SaleReturnPolicy
{
    public function viewAll(User $user): bool
    {
        return $user->canAny(['pos.returns.approve', 'pos.transactions.view']);
    }

    public function view(User $user, SaleReturn $return): bool
    {
        return $this->viewAll($user)
            || ($user->canAny(['pos.returns.view', 'pos.returns.create']) && $return->user_id === $user->getKey());
    }

    public function approve(User $user, SaleReturn $return): bool
    {
        return $return->isDraft() && $user->can('pos.returns.approve');
    }

    /** Draft boleh dihapus pengajunya sendiri atau penyetuju. */
    public function delete(User $user, SaleReturn $return): bool
    {
        return $return->isDraft() && (
            $user->can('pos.returns.approve')
            || ($user->can('pos.returns.create') && $return->user_id === $user->getKey())
        );
    }
}

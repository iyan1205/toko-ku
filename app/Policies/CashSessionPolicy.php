<?php

namespace App\Policies;

use App\Models\CashSession;
use App\Models\User;

/**
 * pos.sessions.approve = atasan: melihat semua sesi, menutup sesi kasir lain, dan memverifikasi setoran.
 * pos.sessions.view / pos.sessions.create = kasir: hanya sesi miliknya sendiri.
 */
class CashSessionPolicy
{
    public function viewAll(User $user): bool
    {
        return $user->can('pos.sessions.approve');
    }

    public function view(User $user, CashSession $session): bool
    {
        return $user->can('pos.sessions.approve')
            || ($user->canAny(['pos.sessions.view', 'pos.sessions.create']) && $session->user_id === $user->getKey());
    }

    public function close(User $user, CashSession $session): bool
    {
        return $session->isOpen() && (
            $user->can('pos.sessions.approve')
            || ($user->can('pos.sessions.create') && $session->user_id === $user->getKey())
        );
    }

    public function verify(User $user, CashSession $session): bool
    {
        return ! $session->isOpen() && $session->verified_at === null && $user->can('pos.sessions.approve');
    }
}

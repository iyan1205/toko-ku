<?php

namespace App\Policies;

use App\Models\User;

/**
 * Semua pegawai (view-schedules) melihat jadwal seluruh pegawai supaya tahu siapa masuk kapan.
 * Mengubah jadwal dan shift dicek lewat middleware 'can:manage-schedules' di routes/web.php.
 */
class JadwalPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAny(['view-schedules', 'manage-schedules']);
    }
}

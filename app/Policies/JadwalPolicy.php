<?php

namespace App\Policies;

use App\Models\User;

/**
 * Semua pegawai (sdm.schedules.view) melihat jadwal seluruh pegawai supaya tahu siapa masuk kapan.
 * Mengubah jadwal dan shift dicek lewat middleware 'permission:' di routes/modules/sdm.php.
 */
class JadwalPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('sdm.schedules.view');
    }
}

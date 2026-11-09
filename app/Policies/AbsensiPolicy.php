<?php

namespace App\Policies;

use App\Models\User;

/**
 * Pegawai (sdm.attendances.record) melihat absensinya sendiri. sdm.attendances.view melihat semua pegawai.
 * Absen masuk/pulang dan catatan manual dicek lewat middleware 'can:' di routes/web.php.
 */
class AbsensiPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAny(['sdm.attendances.record', 'sdm.attendances.view']);
    }
}

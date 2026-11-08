<?php

namespace App\Policies;

use App\Models\User;

/**
 * Pegawai (record-attendance) melihat absensinya sendiri. Admin (manage-attendance) melihat semua pegawai.
 * Absen masuk/pulang dan catatan manual dicek lewat middleware 'can:' di routes/web.php.
 */
class AbsensiPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAny(['record-attendance', 'manage-attendance']);
    }
}

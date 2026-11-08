<?php

namespace App\Policies;

use App\Enums\StatusCuti;
use App\Models\Cuti;
use App\Models\PersetujuanCuti;
use App\Models\User;
use App\Services\Sdm\CutiService;

/**
 * Pegawai (request-leave) mengajukan dan melihat cutinya sendiri. Admin (view-leaves) melihat semua.
 * Approver melihat pengajuan yang salah satu tahapnya ditujukan kepadanya.
 */
class CutiPolicy
{
    public function __construct(private readonly CutiService $service) {}

    public function viewAny(User $user): bool
    {
        return $user->canAny(['request-leave', 'view-leaves']);
    }

    public function view(User $user, Cuti $cuti): bool
    {
        return $cuti->user_id === $user->id
            || $user->can('view-leaves')
            || $cuti->persetujuan->contains(fn (PersetujuanCuti $tahap) => $tahap->ditujukanKe($user));
    }

    public function create(User $user): bool
    {
        return $user->can('request-leave');
    }

    /** Menyetujui atau menolak tahap yang sedang aktif. */
    public function decide(User $user, Cuti $cuti): bool
    {
        return $this->service->bisaMemutuskan($user, $cuti);
    }

    public function cancel(User $user, Cuti $cuti): bool
    {
        return $cuti->user_id === $user->id && $cuti->status === StatusCuti::Menunggu;
    }
}

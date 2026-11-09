<?php

namespace App\Policies;

use App\Enums\StatusCuti;
use App\Models\Cuti;
use App\Models\PersetujuanCuti;
use App\Models\User;
use App\Services\Sdm\CutiService;

/**
 * Pegawai (sdm.leaves.create) mengajukan dan melihat cutinya sendiri. sdm.leaves.view melihat semua.
 * Approver melihat pengajuan yang salah satu tahapnya ditujukan kepadanya.
 */
class CutiPolicy
{
    public function __construct(private readonly CutiService $service) {}

    public function viewAny(User $user): bool
    {
        return $user->canAny(['sdm.leaves.create', 'sdm.leaves.view', 'sdm.leaves.approve']);
    }

    public function view(User $user, Cuti $cuti): bool
    {
        return $cuti->user_id === $user->id
            || $user->can('sdm.leaves.view')
            || $cuti->persetujuan->contains(fn (PersetujuanCuti $tahap) => $tahap->ditujukanKe($user));
    }

    public function create(User $user): bool
    {
        return $user->can('sdm.leaves.create');
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

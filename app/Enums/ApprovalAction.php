<?php

namespace App\Enums;

/** Kejadian di riwayat persetujuan (tabel approvals). */
enum ApprovalAction: string
{
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Submitted => 'Diajukan',
            self::Approved => 'Disetujui',
            self::Rejected => 'Ditolak',
            self::Cancelled => 'Ditarik',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Submitted => 'info',
            self::Approved => 'success',
            self::Rejected => 'danger',
            self::Cancelled => 'neutral',
        };
    }
}

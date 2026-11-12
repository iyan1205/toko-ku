<?php

namespace App\Enums;

/** Riwayat kepegawaian: promosi, demosi, atau mutasi (pindah cabang/departemen/jabatan/atasan). */
enum EmployeeMovementType: string
{
    case Promotion = 'promotion';
    case Demotion = 'demotion';
    case Transfer = 'transfer';

    public function label(): string
    {
        return match ($this) {
            self::Promotion => 'Promosi',
            self::Demotion => 'Demosi',
            self::Transfer => 'Mutasi',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Promotion => 'success',
            self::Demotion => 'warning',
            self::Transfer => 'info',
        };
    }
}

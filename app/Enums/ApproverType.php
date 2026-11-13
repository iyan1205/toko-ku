<?php

namespace App\Enums;

/**
 * Penyetuju satu tahap: siapa pun dengan peran tertentu, satu pengguna tertentu, atau atasan langsung
 * pengaju (hanya dokumen karyawan, config approval.documents.*.supervisor; ditentukan saat diajukan).
 */
enum ApproverType: string
{
    case Role = 'role';
    case User = 'user';
    case Supervisor = 'supervisor';

    public function label(): string
    {
        return match ($this) {
            self::Role => 'Peran',
            self::User => 'Pengguna',
            self::Supervisor => 'Atasan langsung',
        };
    }
}

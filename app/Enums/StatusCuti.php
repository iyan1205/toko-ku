<?php

namespace App\Enums;

enum StatusCuti: string
{
    case Menunggu = 'menunggu';
    case Disetujui = 'disetujui';
    case Ditolak = 'ditolak';
    case Dibatalkan = 'dibatalkan';

    public function label(): string
    {
        return match ($this) {
            self::Menunggu => 'Menunggu persetujuan',
            self::Disetujui => 'Disetujui',
            self::Ditolak => 'Ditolak',
            self::Dibatalkan => 'Dibatalkan',
        };
    }

    /**
     * Pengajuan yang masih menunggu atau sudah disetujui memakai kuota dan tanggal cuti.
     *
     * @return array<int, string>
     */
    public static function valuesBerlaku(): array
    {
        return [self::Menunggu->value, self::Disetujui->value];
    }
}

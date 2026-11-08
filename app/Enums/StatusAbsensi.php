<?php

namespace App\Enums;

enum StatusAbsensi: string
{
    case Hadir = 'hadir';
    case Terlambat = 'terlambat';
    case Izin = 'izin';
    case Sakit = 'sakit';
    case Alpa = 'alpa';
    case Cuti = 'cuti';

    public function label(): string
    {
        return match ($this) {
            self::Hadir => 'Hadir',
            self::Terlambat => 'Terlambat',
            self::Izin => 'Izin',
            self::Sakit => 'Sakit',
            self::Alpa => 'Alpa',
            self::Cuti => 'Cuti',
        };
    }

    /** Hadir dan Terlambat punya jam masuk/pulang. Izin, Sakit, Alpa, dan Cuti tidak. */
    public function pakaiJam(): bool
    {
        return in_array($this, [self::Hadir, self::Terlambat], true);
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Status yang boleh dipakai jenis cuti: dicatat ke absensi saat pengajuan cuti disetujui penuh.
     *
     * @return array<int, self>
     */
    public static function untukCuti(): array
    {
        return [self::Cuti, self::Izin, self::Sakit];
    }

    /** @return array<int, string> */
    public static function valuesPakaiJam(): array
    {
        return array_values(array_map(
            fn (self $status) => $status->value,
            array_filter(self::cases(), fn (self $status) => $status->pakaiJam()),
        ));
    }
}

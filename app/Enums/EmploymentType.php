<?php

namespace App\Enums;

/** Status kerja karyawan. Tetap = PKWTT, Kontrak = PKWT. */
enum EmploymentType: string
{
    case Permanent = 'permanent';
    case Contract = 'contract';
    case Daily = 'daily';
    case PartTime = 'part_time';
    case Intern = 'intern';

    public function label(): string
    {
        return match ($this) {
            self::Permanent => 'Tetap (PKWTT)',
            self::Contract => 'Kontrak (PKWT)',
            self::Daily => 'Harian',
            self::PartTime => 'Paruh waktu',
            self::Intern => 'Magang',
        };
    }

    /** Status yang punya tanggal akhir kontrak. */
    public function hasEndDate(): bool
    {
        return $this !== self::Permanent;
    }
}

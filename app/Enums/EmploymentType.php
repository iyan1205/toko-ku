<?php

namespace App\Enums;

/** Status kerja karyawan. */
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
            self::Permanent => 'Tetap',
            self::Contract => 'Kontrak',
            self::Daily => 'Harian',
            self::PartTime => 'Paruh waktu',
            self::Intern => 'Magang',
        };
    }
}

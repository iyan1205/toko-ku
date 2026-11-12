<?php

namespace App\Enums;

/** Alasan berhenti kerja (offboarding). */
enum TerminationType: string
{
    case Resign = 'resign';
    case Dismissal = 'dismissal';
    case ContractEnd = 'contract_end';
    case Retirement = 'retirement';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Resign => 'Mengundurkan diri (resign)',
            self::Dismissal => 'Pemutusan hubungan kerja (PHK)',
            self::ContractEnd => 'Kontrak berakhir',
            self::Retirement => 'Pensiun',
            self::Other => 'Lainnya',
        };
    }
}

<?php

namespace App\Enums;

/** Kelompok arus kas untuk akun neraca (metode tidak langsung). */
enum CashFlowGroup: string
{
    case Operating = 'operating';
    case Investing = 'investing';
    case Financing = 'financing';

    public function label(): string
    {
        return match ($this) {
            self::Operating => 'Aktivitas operasi',
            self::Investing => 'Aktivitas investasi',
            self::Financing => 'Aktivitas pendanaan',
        };
    }
}

<?php

namespace App\Enums;

/** Status giro/cek mundur: belum cair, sudah cair ke rekening, atau ditolak bank. */
enum GiroStatus: string
{
    case Pending = 'pending';
    case Cleared = 'cleared';
    case Bounced = 'bounced';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Belum cair',
            self::Cleared => 'Cair',
            self::Bounced => 'Ditolak',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Cleared => 'success',
            self::Bounced => 'danger',
        };
    }
}

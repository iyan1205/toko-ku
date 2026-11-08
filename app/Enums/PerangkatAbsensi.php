<?php

namespace App\Enums;

enum PerangkatAbsensi: string
{
    case Web = 'web';
    case Mobile = 'mobile';
    case Tablet = 'tablet';

    public function label(): string
    {
        return match ($this) {
            self::Web => 'Komputer',
            self::Mobile => 'HP',
            self::Tablet => 'Tablet',
        };
    }

    /** Tebakan kasar dari User-Agent browser. Cukup untuk keterangan di daftar absensi, bukan untuk keamanan. */
    public static function dariUserAgent(?string $userAgent): self
    {
        $ua = strtolower((string) $userAgent);

        return match (true) {
            str_contains($ua, 'ipad'), str_contains($ua, 'tablet'),
            str_contains($ua, 'android') && ! str_contains($ua, 'mobile') => self::Tablet,
            str_contains($ua, 'mobi'), str_contains($ua, 'iphone'), str_contains($ua, 'android') => self::Mobile,
            default => self::Web,
        };
    }
}

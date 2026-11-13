<?php

namespace App\Support;

/** Hitungan lokasi sederhana untuk absensi berbasis radius. */
final class Geo
{
    /** "lintang,bujur" → [lintang, bujur], atau null bila tidak valid. @return array{0: float, 1: float}|null */
    public static function parse(?string $value): ?array
    {
        if ($value === null || ! preg_match('/^(-?\d{1,2}(?:\.\d+)?),(-?\d{1,3}(?:\.\d+)?)$/', trim($value), $m)) {
            return null;
        }

        return [(float) $m[1], (float) $m[2]];
    }

    /** Jarak dua titik (meter, rumus haversine). */
    public static function distanceMeters(float $lat1, float $lng1, float $lat2, float $lng2): int
    {
        $r = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return (int) round($r * 2 * atan2(sqrt($a), sqrt(1 - $a)));
    }
}

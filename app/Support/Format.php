<?php

namespace App\Support;

use Carbon\Carbon;
use DateTimeInterface;

/**
 * Format tampilan sesuai konvensi Indonesia:
 * Rupiah "Rp 1.250.000" dan tanggal "DD-MM-YYYY".
 */
final class Format
{
    public static function rupiah(int|float|string|null $nilai, bool $denganSimbol = true): string
    {
        $angka = round((float) ($nilai ?? 0), 2);
        $desimal = fmod(abs($angka), 1.0) > 0.0 ? 2 : 0;
        $teks = number_format(abs($angka), $desimal, ',', '.');

        return ($angka < 0 ? '-' : '') . ($denganSimbol ? 'Rp ' : '') . $teks;
    }

    /** Jumlah/stok tanpa nol di belakang koma: 10.000 -> "10", 2.5 -> "2,5", 1000 -> "1.000". */
    public static function jumlah(int|float|string|null $nilai): string
    {
        $teks = number_format(round((float) ($nilai ?? 0), 3), 3, ',', '.');

        return rtrim(rtrim($teks, '0'), ',');
    }

    public static function tanggal(DateTimeInterface|string|null $tanggal, bool $denganJam = false): string
    {
        if ($tanggal === null || $tanggal === '') {
            return '-';
        }

        return Carbon::parse($tanggal)->format($denganJam ? 'd-m-Y H:i' : 'd-m-Y');
    }
}

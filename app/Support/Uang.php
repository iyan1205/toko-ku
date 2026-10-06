<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Hitung uang dan jumlah dengan bilangan bulat, tanpa float dan tanpa ekstensi bcmath.
 *   - Rupiah  -> "sen"   (x100)  : 12500.50  = 1250050
 *   - Jumlah  -> "milli" (x1000) : 2.5       = 2500
 * Pembulatan selalu setengah ke atas (half-up). Batas masukan dijaga validasi agar
 * milli x sen tidak melewati batas bilangan bulat 64-bit.
 */
final class Uang
{
    /** Parse nilai (string desimal dari formulir/DB, int, atau float) menjadi sen. */
    public static function sen(mixed $nilai): int
    {
        return self::parse($nilai, 2);
    }

    /** Parse nilai menjadi milli (3 desimal). */
    public static function milli(mixed $nilai): int
    {
        return self::parse($nilai, 3);
    }

    /** 1250050 -> "12500.50" (cocok untuk kolom decimal(15,2)). */
    public static function keDesimal(int $sen): string
    {
        return self::format($sen, 2);
    }

    /** 2500 -> "2.500" (cocok untuk kolom decimal(15,3)). */
    public static function milliKeDesimal(int $milli): string
    {
        return self::format($milli, 3);
    }

    /** jumlah(milli) x harga(sen) -> total (sen), dibulatkan setengah ke atas. Hanya untuk nilai >= 0. */
    public static function kali(int $milli, int $sen): int
    {
        self::pastikanTakNegatif($milli, $sen);

        return intdiv($milli * $sen * 2 + 1000, 2000);
    }

    /** total(sen) / jumlah(milli) -> harga per unit (sen), dibulatkan setengah ke atas. */
    public static function perUnit(int $totalSen, int $milli): int
    {
        self::pastikanTakNegatif($totalSen, $milli);

        if ($milli === 0) {
            throw new InvalidArgumentException('Jumlah tidak boleh nol.');
        }

        return intdiv($totalSen * 1000 * 2 + $milli, $milli * 2);
    }

    private static function parse(mixed $nilai, int $skala): int
    {
        if ($nilai === null || $nilai === '') {
            return 0;
        }

        if (is_int($nilai)) {
            return $nilai * (10 ** $skala);
        }

        if (is_float($nilai)) {
            $nilai = number_format($nilai, $skala, '.', '');
        }

        if (! is_string($nilai) || ! preg_match('/^(-?)(\d+)(?:\.(\d+))?$/', trim($nilai), $m)) {
            throw new InvalidArgumentException('Angka tidak valid: ' . (is_scalar($nilai) ? $nilai : gettype($nilai)));
        }

        $desimal = $m[3] ?? '';
        if (strlen($desimal) > $skala) {
            // Angka di luar skala hanya boleh nol ("10.500" untuk skala 2), selain itu ditolak, bukan dibulatkan diam-diam.
            if (rtrim(substr($desimal, $skala), '0') !== '') {
                throw new InvalidArgumentException("Angka melebihi {$skala} desimal: {$nilai}");
            }
            $desimal = substr($desimal, 0, $skala);
        }

        $hasil = (int) ($m[2] . str_pad($desimal, $skala, '0'));

        return $m[1] === '-' ? -$hasil : $hasil;
    }

    private static function format(int $nilai, int $skala): string
    {
        $negatif = $nilai < 0;
        $digit = str_pad((string) abs($nilai), $skala + 1, '0', STR_PAD_LEFT);

        return ($negatif ? '-' : '') . substr($digit, 0, -$skala) . '.' . substr($digit, -$skala);
    }

    private static function pastikanTakNegatif(int ...$angka): void
    {
        foreach ($angka as $a) {
            if ($a < 0) {
                throw new InvalidArgumentException('Nilai tidak boleh negatif.');
            }
        }
    }
}

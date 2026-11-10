<?php

namespace App\Support;

/**
 * Perhitungan baris barang dan total dokumen pembelian (faktur, purchase order) dengan bilangan
 * bulat: jumlah dalam milli (x1000), uang dalam sen (x100). Tidak memakai float.
 *
 *   subtotal     = jumlah (qty x harga) semua baris, SEBELUM diskon baris
 *   item_discount = jumlah diskon baris
 *   grand_total  = subtotal - item_discount - discount + tax + other_cost
 */
final class DocumentTotals
{
    /** Batas total per dokumen: Rp 99.999.999.999,99 (muat di decimal(15,2)). */
    public const MAX_TOTAL_SEN = 9_999_999_999_999;

    /**
     * Baris dari input formulir. Kesalahan dikumpulkan per baris (kunci items.{i}.*).
     *
     * @param  array<int, array{product_id:int|string, quantity:mixed, unit_price?:mixed, discount?:mixed}>  $items
     * @return array{0: array<int, array{milli:int, harga:int, diskon:int}>, 1: array<string, string>} [baris per product_id, kesalahan]
     */
    public static function lines(array $items): array
    {
        $baris = [];
        $errors = [];

        foreach (array_values($items) as $i => $it) {
            $id = (int) ($it['product_id'] ?? 0);
            $milli = Uang::milli($it['quantity'] ?? 0);
            $harga = Uang::sen($it['unit_price'] ?? 0);
            $diskon = Uang::sen($it['discount'] ?? 0);

            if (isset($baris[$id])) {
                $errors["items.{$i}.product_id"] = 'Produk yang sama tidak boleh muncul dua kali. Gabungkan jumlahnya.';
            } elseif ($milli <= 0) {
                $errors["items.{$i}.quantity"] = 'Jumlah harus lebih dari nol.';
            } elseif ($diskon > Uang::kali($milli, $harga)) {
                $errors["items.{$i}.discount"] = 'Diskon melebihi nilai barang pada baris ini.';
            } else {
                $baris[$id] = ['milli' => $milli, 'harga' => $harga, 'diskon' => $diskon];
            }
        }

        if ($baris === [] && $errors === []) {
            $errors['items'] = 'Tambahkan minimal satu produk.';
        }

        return [$baris, $errors];
    }

    /**
     * @param  array<int, array{milli:int, harga:int, diskon:int}>  $baris
     * @return array{baris:array<int,array{milli:int,harga:int,diskon:int,total:int}>, subtotal:int, diskonBaris:int, diskon:int, pajak:int, biaya:int, total:int}
     */
    public static function totals(array $baris, int $diskon, int $pajak, int $biaya): array
    {
        $subtotal = 0;
        $diskonBaris = 0;

        foreach ($baris as $id => $b) {
            $kotor = Uang::kali($b['milli'], $b['harga']);
            $baris[$id]['total'] = $kotor - $b['diskon'];
            $subtotal += $kotor;
            $diskonBaris += $b['diskon'];
        }

        return [
            'baris' => $baris,
            'subtotal' => $subtotal,
            'diskonBaris' => $diskonBaris,
            'diskon' => $diskon,
            'pajak' => $pajak,
            'biaya' => $biaya,
            'total' => $subtotal - $diskonBaris - $diskon + $pajak + $biaya,
        ];
    }

    /**
     * Kesalahan pada angka kepala dokumen.
     *
     * @param  array{subtotal:int, diskonBaris:int, diskon:int, total:int}  $hitung
     * @return array<string, string>
     */
    public static function headerErrors(array $hitung): array
    {
        $errors = [];

        if ($hitung['diskon'] > $hitung['subtotal'] - $hitung['diskonBaris']) {
            $errors['discount'] = 'Diskon melebihi subtotal setelah diskon baris.';
        }

        if ($hitung['total'] > self::MAX_TOTAL_SEN) {
            $errors['items'] = 'Total terlalu besar (maksimal Rp 99.999.999.999,99).';
        }

        return $errors;
    }
}

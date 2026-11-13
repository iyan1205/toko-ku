<?php

namespace App\Support;

use App\Models\ProductUnit;

/**
 * Baris dokumen yang diisi dalam satuan lain (misal 5 DUS @ Rp 120.000, isi 12) diubah ke satuan dasar
 * (60 PCS @ Rp 10.000) sebelum disimpan, supaya stok, penerimaan, retur, dan HPP tetap dalam satuan dasar.
 * Harga per satuan dasar dibulatkan ke sen.
 */
final class UnitConversion
{
    /**
     * @param  array<int, array<string, mixed>>  $items  baris dengan product_id, quantity, unit_price?, unit_id?
     * @return array<int, array<string, mixed>>
     */
    public static function toBase(array $items): array
    {
        $pairs = collect($items)->filter(fn ($row) => ! empty($row['unit_id']));
        if ($pairs->isEmpty()) {
            return $items;
        }

        $units = ProductUnit::query()->whereIn('product_id', $pairs->pluck('product_id'))->whereIn('unit_id', $pairs->pluck('unit_id'))->get()
            ->keyBy(fn (ProductUnit $u) => $u->product_id.':'.$u->unit_id);

        return array_map(function (array $row) use ($units) {
            $unit = ! empty($row['unit_id']) ? $units->get(((int) $row['product_id']).':'.((int) $row['unit_id'])) : null;
            unset($row['unit_id']);
            if ($unit === null) {
                return $row;
            }
            $factor = (float) $unit->factor;
            $row['quantity'] = Uang::milliKeDesimal((int) round(Uang::milli($row['quantity'] ?? 0) * $factor));
            if (array_key_exists('unit_price', $row) && $row['unit_price'] !== null && $row['unit_price'] !== '') {
                $row['unit_price'] = Uang::keDesimal((int) round(Uang::sen($row['unit_price']) / $factor));
            }

            return $row;
        }, $items);
    }
}

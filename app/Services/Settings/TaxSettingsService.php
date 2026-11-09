<?php

namespace App\Services\Settings;

use App\Models\User;

/**
 * Pengaturan pajak penjualan (PPN): aktif/tidak, nama, tarif, dan apakah harga jual
 * sudah termasuk pajak.
 *
 * Belum dihitung di transaksi kasir. Kasir mulai memakai pengaturan ini saat
 * halaman kasir diperbarui (Fase 6); sampai saat itu nilai transaksi tidak berubah.
 */
class TaxSettingsService
{
    /** @return array{enabled: bool, name: string, rate: float, inclusive: bool} */
    public function get(): array
    {
        return [
            'enabled' => setting('tax.enabled') === '1',
            'name' => (string) setting('tax.name', 'PPN'),
            'rate' => (float) setting('tax.rate', '11'),
            'inclusive' => setting('tax.inclusive', '1') === '1',
        ];
    }

    /** @param array{enabled: bool, name: string, rate: float|string, inclusive: bool} $data */
    public function save(array $data, User $actor): void
    {
        $before = $this->describe($this->get());

        setting([
            'tax.enabled' => (bool) $data['enabled'],
            'tax.name' => trim($data['name']),
            'tax.rate' => $this->formatRate((float) $data['rate']),
            'tax.inclusive' => (bool) $data['inclusive'],
        ]);

        $after = $this->describe($this->get());
        $changed = array_keys(array_diff_assoc($after, $before));

        if ($changed !== []) {
            activity('pengaturan')->causedBy($actor)->event('updated')
                ->withProperties([
                    'old' => array_intersect_key($before, array_flip($changed)),
                    'attributes' => array_intersect_key($after, array_flip($changed)),
                ])
                ->log('Pengaturan pajak diubah');
        }
    }

    /** Tarif tanpa nol berlebih: 11 → "11", 11.5 → "11.5". */
    private function formatRate(float $rate): string
    {
        return rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.');
    }

    /**
     * @param  array{enabled: bool, name: string, rate: float, inclusive: bool}  $tax
     * @return array<string, string>
     */
    private function describe(array $tax): array
    {
        return [
            'aktif' => $tax['enabled'] ? 'ya' : 'tidak',
            'nama' => $tax['name'],
            'tarif' => $this->formatRate($tax['rate']).'%',
            'harga_termasuk_pajak' => $tax['inclusive'] ? 'ya' : 'tidak',
        ];
    }
}

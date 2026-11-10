<?php

namespace App\Services\Inventory;

use App\Exceptions\AturanBisnisException;
use App\Models\Warehouse;
use App\Support\Format;
use Illuminate\Support\Facades\DB;

/**
 * Tambah, ubah, dan hapus gudang dengan aturan:
 *   - selalu ada tepat satu gudang utama, dan gudang utama selalu aktif;
 *   - gudang hanya bisa dinonaktifkan atau dihapus bila stoknya 0 (pindahkan dulu lewat Transfer Stok).
 */
class WarehouseService
{
    /** @param array{code: string, name: string, address?: ?string, branch_id?: ?int, is_active?: bool, is_default?: bool} $data */
    public function create(array $data): Warehouse
    {
        return DB::transaction(function () use ($data) {
            $warehouse = new Warehouse($data);
            $warehouse->save();

            if ($data['is_default'] ?? false) {
                $this->makeDefault($warehouse);
            }

            return $warehouse;
        });
    }

    /** @param array{code: string, name: string, address?: ?string, branch_id?: ?int, is_active?: bool, is_default?: bool} $data */
    public function update(Warehouse $warehouse, array $data): Warehouse
    {
        $deactivating = $warehouse->is_active && ! ($data['is_active'] ?? true);

        if ($deactivating && $warehouse->is_default) {
            throw new AturanBisnisException('Gudang utama tidak bisa dinonaktifkan. Jadikan gudang lain sebagai gudang utama terlebih dahulu.');
        }

        return DB::transaction(function () use ($warehouse, $data, $deactivating) {
            if ($deactivating) {
                $this->ensureEmpty(Warehouse::whereKey($warehouse->getKey())->lockForUpdate()->firstOrFail(), 'dinonaktifkan');
            }

            $warehouse->fill($data)->save();

            if (($data['is_default'] ?? false) && ! $warehouse->is_default) {
                $this->makeDefault($warehouse);
            }

            return $warehouse;
        });
    }

    public function delete(Warehouse $warehouse): void
    {
        if ($warehouse->is_default) {
            throw new AturanBisnisException('Gudang utama tidak bisa dihapus. Jadikan gudang lain sebagai gudang utama terlebih dahulu.');
        }

        DB::transaction(function () use ($warehouse) {
            $this->ensureEmpty(Warehouse::whereKey($warehouse->getKey())->lockForUpdate()->firstOrFail(), 'dihapus');
            $warehouse->delete();
        });
    }

    /** Setiap produk harus 0 (bukan jumlahnya): stok minus satu produk tidak boleh menutupi stok produk lain. */
    private function ensureEmpty(Warehouse $warehouse, string $action): void
    {
        $products = $warehouse->stocks()->where('quantity', '!=', 0)->count();

        if ($products > 0) {
            throw new AturanBisnisException(sprintf(
                'Gudang %s masih berisi stok untuk %s produk dan tidak bisa %s. Pindahkan stoknya dulu lewat Transfer Stok.',
                $warehouse->name, Format::jumlah($products), $action,
            ));
        }
    }

    /** Jadikan gudang utama; gudang utama sebelumnya menjadi gudang biasa. Gudang utama selalu aktif. */
    private function makeDefault(Warehouse $warehouse): void
    {
        Warehouse::query()->whereKeyNot($warehouse->getKey())->where('is_default', true)->get()
            ->each(fn (Warehouse $other) => $other->forceFill(['is_default' => false])->save());

        $warehouse->forceFill(['is_default' => true, 'is_active' => true])->save();
    }
}

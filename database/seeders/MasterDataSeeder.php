<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Unit;
use Illuminate\Database\Seeder;

/** Aman dijalankan berulang kali: semua data dicari dulu sebelum dibuat. */
class MasterDataSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['Makanan', 'Minuman', 'Sembako', 'Rokok', 'Elektronik', 'Lainnya'] as $nama) {
            Category::firstOrCreate(['name' => $nama]);
        }

        foreach (['PCS', 'BOX', 'KG', 'GRAM', 'LITER', 'BOTOL', 'DUS', 'PACK'] as $nama) {
            Unit::firstOrCreate(['name' => $nama]);
        }

        // Pelanggan bawaan untuk transaksi tanpa pelanggan.
        Customer::umum();

        // Produk contoh hanya untuk pengembangan. Stok awal 0; stok diisi lewat Pembelian/Stok Opname.
        if (! app()->isProduction()) {
            $this->produkContoh();
        }
    }

    private function produkContoh(): void
    {
        $makanan = Category::where('name', 'Makanan')->value('id');
        $minuman = Category::where('name', 'Minuman')->value('id');
        $pcs = Unit::where('name', 'PCS')->value('id');
        $botol = Unit::where('name', 'BOTOL')->value('id');

        $contoh = [
            ['sku' => 'AYM-001', 'name' => 'Ayam Geprek', 'category_id' => $makanan, 'unit_id' => $pcs, 'purchase_price' => 8000, 'selling_price' => 10000, 'min_stock' => 5],
            ['sku' => 'NSI-001', 'name' => 'Nasi Putih', 'category_id' => $makanan, 'unit_id' => $pcs, 'purchase_price' => 3000, 'selling_price' => 5000, 'min_stock' => 10],
            ['sku' => 'MNM-001', 'name' => 'Teh Botol', 'category_id' => $minuman, 'unit_id' => $botol, 'purchase_price' => 3500, 'selling_price' => 5000, 'min_stock' => 12],
            ['sku' => 'SBL-001', 'name' => 'Sambal Bawang', 'category_id' => $makanan, 'unit_id' => $pcs, 'purchase_price' => 1000, 'selling_price' => 2000, 'min_stock' => 10],
        ];

        foreach ($contoh as $data) {
            Product::firstOrCreate(['sku' => $data['sku']], $data);
        }
    }
}

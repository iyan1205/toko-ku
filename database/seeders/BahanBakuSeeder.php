<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use App\Models\Unit;
use Illuminate\Database\Seeder;

/**
 * Bahan baku dan biaya operasional untuk Dkriuk.
 * Aman dijalankan berulang kali (idempotent).
 * Harga dapat berubah sewaktu-waktu — update langsung lewat form produk.
 */
class BahanBakuSeeder extends Seeder
{
    public function run(): void
    {
        $bahan = Category::firstOrCreate(['name' => 'Bahan Baku']);
        $operasional = Category::firstOrCreate(['name' => 'Operasional']);

        $satuan = fn (string $n) => Unit::firstOrCreate(['name' => $n])->id;

        $data = [
            // Kategori, Nama, Satuan, Harga beli
            [$bahan->id, 'Ayam Karkas 1/9 Potong', 'PCS', 5000],
            [$bahan->id, 'Tepung ORI', 'KG', 19500],
            [$bahan->id, 'Kulit 1 Kg', 'KG', 45000],
            [$bahan->id, 'Kentang 2,5 Kg', 'KG', 75000],
            [$bahan->id, 'Box Dkriuk 100 pcs', 'PACK', 100000],
            [$bahan->id, 'Box Dkribho 100 pcs', 'PACK', 82000],
            [$bahan->id, 'Kertas Nasi 100 pcs', 'PACK', 15000],
            [$bahan->id, 'Plastik Kecil 25/15 100 pcs', 'PACK', 13000],
            [$bahan->id, 'Plastik Besar 36/25 50 pcs', 'PACK', 13000],
            [$bahan->id, 'Kemasan Kecil 100 pcs', 'PACK', 19000],
            [$bahan->id, 'Kemasan Besar 100 pcs', 'PACK', 25000],
            [$bahan->id, 'Hand Glove 1 Pack isi 200', 'PACK', 26000],
            [$bahan->id, 'Saos Tomat Branding 100 pcs', 'PACK', 29000],
            [$bahan->id, 'Saos Hot Branding 100 pcs', 'PACK', 30000],
            [$bahan->id, 'Cup Kulit 50 pcs', 'PACK', 30000],
            [$bahan->id, 'Kemasan Kentang 100 pcs', 'PACK', 22000],
            [$bahan->id, 'Sambal Geprek 25 pcs', 'PACK', 87500],
            [$bahan->id, 'Sambal Bawang 25 pcs', 'PACK', 75000],
            [$bahan->id, 'Saos Dkribho Level 2', 'PACK', 25000],
            [$bahan->id, 'Saos Keju 1 Pouch', 'PACK', 23500],
            [$bahan->id, 'Saos Black Paper 1 Pouch', 'PACK', 25000],
            [$bahan->id, 'Saus Korean Spicy 10 pcs', 'PACK', 25000],
            [$bahan->id, 'Sambal Cakalang 25 pcs', 'PACK', 80000],
            [$bahan->id, 'Patty Burger 10 pcs', 'PACK', 34000],
            [$bahan->id, 'Roti Burger 4 pcs', 'PACK', 13000],
            [$bahan->id, 'Sosis 12 pcs', 'PACK', 65000],
            [$operasional->id, 'Lampu Hot Snack 100 Watt', 'PCS', 16000],
            [$operasional->id, 'Gas 3 Kg', 'PCS', 23000],
            [$operasional->id, 'Minyak Resto', 'LITER', 225000],
        ];

        $no = 1;
        foreach ($data as [$kategori, $nama, $satuanNama, $harga]) {
            Product::firstOrCreate(
                ['name' => $nama],
                [
                    'sku' => 'BHN-' . str_pad((string) $no, 4, '0', STR_PAD_LEFT),
                    'category_id' => $kategori,
                    'unit_id' => $satuan($satuanNama),
                    'purchase_price' => $harga,
                    'selling_price' => 0,
                    'min_stock' => 0,
                    'is_active' => true,
                ]
            );
            $no++;
        }
    }
}

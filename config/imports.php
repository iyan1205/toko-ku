<?php

use App\Imports\Definitions\CustomersImport;
use App\Imports\Definitions\SuppliersImport;

/*
|--------------------------------------------------------------------------
| Jenis impor massal (kerangka impor: templat → unggah → pratinjau → proses)
|--------------------------------------------------------------------------
| Kunci = nama route imports.{kunci}.*; slug = URL /impor/{slug}; permission = izin yang
| menjaga semua route impor jenis itu. Menambah jenis: buat turunan App\Imports\ImportDefinition,
| daftarkan di sini, tambahkan aksi 'import' di config/permission-matrix.php, lalu pasang tombol
| "Impor" di halaman daftarnya. Impor produk masih memakai halaman lamanya (Inventori → Produk).
*/

return [
    'types' => [
        'customers' => ['class' => CustomersImport::class, 'slug' => 'pelanggan', 'permission' => 'pos.customers.import'],
        'suppliers' => ['class' => SuppliersImport::class, 'slug' => 'supplier', 'permission' => 'purchasing.suppliers.import'],
    ],

    // Berkas yang boleh diunggah dan ukurannya (KB).
    'mimes' => ['xlsx', 'xls', 'csv'],
    'max_kb' => 5120,
];

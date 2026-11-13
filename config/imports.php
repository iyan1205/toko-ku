<?php

use App\Imports\Definitions\AccountsImport;
use App\Imports\Definitions\AttendanceScansImport;
use App\Imports\Definitions\CustomersImport;
use App\Imports\Definitions\HolidaysImport;
use App\Imports\Definitions\ProductsImport;
use App\Imports\Definitions\SuppliersImport;

/*
|--------------------------------------------------------------------------
| Jenis impor massal (kerangka impor: templat → unggah → pratinjau → proses)
|--------------------------------------------------------------------------
| Kunci = nama route imports.{kunci}.*; slug = URL /impor/{slug}; permission = izin yang
| menjaga semua route impor jenis itu. Menambah jenis: buat turunan App\Imports\ImportDefinition,
| daftarkan di sini, tambahkan aksi 'import' di config/permission-matrix.php, lalu pasang tombol
| "Impor" di halaman daftarnya. Produk memakai kerangka ini sejak Fase 16 (halaman impor lama tetap ada).
*/

return [
    'types' => [
        'products' => ['class' => ProductsImport::class, 'slug' => 'produk', 'permission' => 'inventory.products.import'],
        'customers' => ['class' => CustomersImport::class, 'slug' => 'pelanggan', 'permission' => 'pos.customers.import'],
        'suppliers' => ['class' => SuppliersImport::class, 'slug' => 'supplier', 'permission' => 'purchasing.suppliers.import'],
        'accounts' => ['class' => AccountsImport::class, 'slug' => 'akun', 'permission' => 'accounting.coa.import'],
        'holidays' => ['class' => HolidaysImport::class, 'slug' => 'hari-libur', 'permission' => 'sdm.holidays.import'],
        'attendance_scans' => ['class' => AttendanceScansImport::class, 'slug' => 'absensi-fingerprint', 'permission' => 'sdm.attendances.import'],
    ],

    // Berkas yang boleh diunggah dan ukurannya (KB).
    'mimes' => ['xlsx', 'xls', 'csv'],
    'max_kb' => 5120,
];

<?php

/*
|--------------------------------------------------------------------------
| Katalog hak akses (izin)
|--------------------------------------------------------------------------
| Satu-satunya daftar izin aplikasi. Nama izin = "{modul}.{fitur}.{aksi}",
| misal "inventory.products.view". Dipakai oleh:
|   - RolePermissionSeeder  : membuat semua izin + hak akses peran bawaan
|   - halaman Hak Akses     : matriks baris = fitur, kolom = aksi, per modul
|   - validasi & test       : izin di luar katalog ditolak
|
| Menambah fitur baru: tambahkan di sini, lalu jalankan
|   php artisan db:seed --class=RolePermissionSeeder
| Izin baru otomatis diberikan ke peran bawaan sesuai daftar di seeder;
| hak akses yang sudah diatur lewat halaman Hak Akses tidak ditimpa.
|
| 'planned' => true menandai fitur yang belum dibangun (menu "Segera Hadir").
| Izinnya tetap dibuat agar peran bisa disiapkan sejak sekarang.
*/

return [
    /* Urutan kolom di matriks. */
    'actions' => [
        'view' => 'Lihat',
        'view-own' => 'Lihat milik sendiri',
        'record' => 'Catat milik sendiri',
        'create' => 'Tambah',
        'update' => 'Ubah',
        'delete' => 'Hapus',
        'void' => 'Batalkan',
        'approve' => 'Setujui',
        'print' => 'Cetak',
        'import' => 'Impor',
        'export' => 'Ekspor',
    ],

    'modules' => [
        'dashboard' => [
            'label' => 'Dashboard',
            'features' => [
                'overview' => ['label' => 'Ringkasan & KPI', 'actions' => ['view']],
            ],
        ],

        'pos' => [
            'label' => 'Kasir (POS)',
            'features' => [
                'transactions' => ['label' => 'Transaksi penjualan', 'actions' => ['view', 'view-own', 'create', 'void', 'print', 'export']],
                // Sesi & retur: view/create = milik sendiri; approve = semua, verifikasi setoran, dan persetujuan retur.
                'sessions' => ['label' => 'Sesi/shift kasir', 'actions' => ['view', 'create', 'approve', 'export']],
                'returns' => ['label' => 'Retur penjualan', 'actions' => ['view', 'create', 'approve', 'export']],
                'customers' => ['label' => 'Pelanggan', 'actions' => ['view', 'create', 'update', 'delete', 'export']],
                'receivables' => ['label' => 'Piutang pelanggan', 'actions' => ['view', 'create', 'export']],
            ],
        ],

        'inventory' => [
            'label' => 'Inventori',
            'features' => [
                'products' => ['label' => 'Produk', 'actions' => ['view', 'create', 'update', 'delete', 'import', 'export']],
                'categories' => ['label' => 'Kategori', 'actions' => ['view', 'create', 'update', 'delete', 'export']],
                'units' => ['label' => 'Satuan', 'actions' => ['view', 'create', 'update', 'delete', 'export']],
                'stock' => ['label' => 'Posisi stok', 'actions' => ['view', 'export']],
                'stock-card' => ['label' => 'Kartu stok', 'actions' => ['view', 'export']],
                'adjustments' => ['label' => 'Penyesuaian stok', 'actions' => ['create']],
                'opnames' => ['label' => 'Stok opname', 'actions' => ['view', 'create', 'approve', 'export']],
                'transfers' => ['label' => 'Mutasi/transfer stok', 'actions' => ['view', 'create', 'void', 'print', 'export']],
                'warehouses' => ['label' => 'Gudang/lokasi', 'actions' => ['view', 'create', 'update', 'delete', 'export']],
                'recipes' => ['label' => 'Resep (BOM)', 'actions' => ['view', 'create', 'update', 'delete', 'export']],
            ],
        ],

        'purchasing' => [
            'label' => 'Pembelian',
            'features' => [
                'suppliers' => ['label' => 'Supplier', 'actions' => ['view', 'create', 'update', 'delete', 'export']],
                'orders' => ['label' => 'Purchase order', 'actions' => ['view', 'create', 'update', 'delete', 'approve', 'print', 'export']],
                'invoices' => ['label' => 'Faktur pembelian', 'actions' => ['view', 'create', 'void', 'export']],
                'receipts' => ['label' => 'Penerimaan barang', 'actions' => ['view', 'create', 'void', 'export']],
                'returns' => ['label' => 'Retur pembelian', 'actions' => ['view', 'create', 'delete', 'approve', 'export']],
                'payables' => ['label' => 'Hutang supplier', 'actions' => ['view', 'create', 'export']],
            ],
        ],

        'accounting' => [
            'label' => 'Akuntansi',
            'features' => [
                'coa' => ['label' => 'Bagan akun (COA)', 'actions' => ['view', 'create', 'update', 'delete', 'export']],
                // Jurnal tidak bisa diubah/dihapus; void = membuat jurnal pembalik untuk jurnal manual.
                'journals' => ['label' => 'Jurnal umum', 'actions' => ['view', 'create', 'void', 'export']],
                'ledger' => ['label' => 'Buku besar', 'actions' => ['view', 'export'], 'planned' => true],
                'cash-bank' => ['label' => 'Kas & bank', 'actions' => ['view', 'create', 'update', 'export'], 'planned' => true],
                'reports' => ['label' => 'Laporan keuangan', 'actions' => ['view', 'export'], 'planned' => true],
                'closing' => ['label' => 'Tutup buku', 'actions' => ['view', 'approve'], 'planned' => true],
            ],
        ],

        'sdm' => [
            'label' => 'SDM',
            'features' => [
                'employees' => ['label' => 'Karyawan', 'actions' => ['view', 'create', 'update', 'delete', 'export']],
                'departments' => ['label' => 'Departemen & jabatan', 'actions' => ['view', 'create', 'update', 'delete', 'export']],
                'attendances' => ['label' => 'Absensi', 'actions' => ['view', 'record', 'create', 'update', 'delete', 'export']],
                'schedules' => ['label' => 'Jadwal kerja', 'actions' => ['view', 'update']],
                'shifts' => ['label' => 'Shift kerja', 'actions' => ['view', 'create', 'update', 'delete']],
                'leaves' => ['label' => 'Cuti & izin', 'actions' => ['view', 'create', 'approve']],
                'leave-types' => ['label' => 'Jenis cuti & alur persetujuan', 'actions' => ['view', 'create', 'update', 'delete']],
                'payroll' => ['label' => 'Penggajian', 'actions' => ['view', 'create', 'approve', 'export'], 'planned' => true],
            ],
        ],

        'reports' => [
            'label' => 'Laporan',
            'features' => [
                'sales' => ['label' => 'Laporan penjualan', 'actions' => ['view', 'export']],
                'purchases' => ['label' => 'Laporan pembelian', 'actions' => ['view', 'export']],
                'stock' => ['label' => 'Laporan stok', 'actions' => ['view', 'export']],
                'gross-profit' => ['label' => 'Laba kotor', 'actions' => ['view', 'export'], 'planned' => true],
                'cashiers' => ['label' => 'Kinerja kasir', 'actions' => ['view', 'export'], 'planned' => true],
            ],
        ],

        'access' => [
            'label' => 'Pengguna & Hak Akses',
            'features' => [
                'users' => ['label' => 'Pengguna', 'actions' => ['view', 'create', 'update', 'delete']],
                'roles' => ['label' => 'Peran', 'actions' => ['view', 'create', 'update', 'delete']],
                'permissions' => ['label' => 'Matriks hak akses', 'actions' => ['view', 'update']],
                'activity-log' => ['label' => 'Log aktivitas', 'actions' => ['view']],
            ],
        ],

        'settings' => [
            'label' => 'Pengaturan',
            'features' => [
                'store' => ['label' => 'Profil toko', 'actions' => ['view', 'update']],
                'branches' => ['label' => 'Cabang', 'actions' => ['view', 'create', 'update', 'delete']],
                'taxes' => ['label' => 'Pajak', 'actions' => ['view', 'update']],
                'payment-methods' => ['label' => 'Metode pembayaran', 'actions' => ['view', 'update']],
                'pos' => ['label' => 'Kasir', 'actions' => ['view', 'update']],
                'accounting' => ['label' => 'Akuntansi (jurnal otomatis)', 'actions' => ['view', 'update']],
                'receipts' => ['label' => 'Struk & printer', 'actions' => ['view', 'update']],
                'numbering' => ['label' => 'Penomoran dokumen', 'actions' => ['view', 'update']],
                'backup' => ['label' => 'Backup', 'actions' => ['view', 'create']],
            ],
        ],
    ],
];

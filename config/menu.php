<?php

/*
|--------------------------------------------------------------------------
| Menu sidebar (Struktur Menu di docs/REDESIGN-TOKOKU.md)
|--------------------------------------------------------------------------
| Dibaca oleh App\Support\Menu. Susunan: grup -> menu -> submenu (children).
|
| Setiap menu/submenu:
|   key         : unik, juga dipakai di URL halaman "Segera Hadir" (/segera-hadir/{key})
|   label       : teks menu
|   icon        : nama Heroicons, hanya untuk menu tingkat atas
|   route       : nama route (untuk status enabled)
|   active      : pola routeIs() penanda menu aktif (string/array); bawaan = route
|   permission  : string, array (salah satu cukup), atau null (semua pengguna login)
|   status      : enabled (bawaan) | coming_soon (tampil dengan badge "Segera Hadir") | hidden
|   description : teks singkat di bawah judul halaman indeks dan di halaman "Segera Hadir"
|
| Status coming_soon pada menu tingkat atas berlaku untuk semua submenunya.
| permission memakai nama izin {modul}.{fitur}.{aksi} dari config/permission-matrix.php.
*/

return [
    [
        'label' => 'Utama',
        'items' => [
            [
                'key' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'home',
                'route' => 'dashboard', 'permission' => null,
                'description' => 'Ringkasan penjualan, stok, dan aktivitas toko.',
            ],
            [
                'key' => 'approvals', 'label' => 'Persetujuan Saya', 'icon' => 'check-badge',
                'route' => 'approvals.index', 'active' => 'approvals.*', 'permission' => null,
                'description' => 'Dokumen yang menunggu persetujuan Anda, pengajuan Anda, dan keputusan yang sudah Anda buat.',
            ],
        ],
    ],

    [
        'label' => 'Penjualan',
        'items' => [
            [
                'key' => 'pos', 'label' => 'Kasir (POS)', 'icon' => 'shopping-cart',
                'children' => [
                    ['key' => 'pos-new', 'label' => 'Transaksi Baru', 'route' => 'pos.index', 'active' => 'pos.index', 'permission' => 'pos.transactions.create'],
                    ['key' => 'pos-history', 'label' => 'Riwayat Transaksi', 'route' => 'sales.index', 'active' => 'sales.*', 'permission' => ['pos.transactions.view', 'pos.transactions.view-own'],
                        'description' => 'Daftar penjualan, detail, struk, dan pembatalan.'],
                    ['key' => 'pos-sessions', 'label' => 'Sesi/Shift Kasir', 'route' => 'pos.sessions.index', 'active' => 'pos.sessions.*', 'permission' => ['pos.sessions.view', 'pos.sessions.create', 'pos.sessions.approve'],
                        'description' => 'Buka dan tutup sesi kasir dengan modal awal, lalu rekap setoran per metode pembayaran.'],
                    ['key' => 'pos-returns', 'label' => 'Retur Penjualan', 'route' => 'sale-returns.index', 'active' => 'sale-returns.*', 'permission' => ['pos.returns.view', 'pos.returns.create', 'pos.returns.approve'],
                        'description' => 'Barang yang dikembalikan pelanggan: diajukan kasir, disetujui atasan, lalu stok kembali dan dana dikembalikan.'],
                    ['key' => 'pos-customers', 'label' => 'Pelanggan', 'route' => 'customers.index', 'active' => 'customers.*', 'permission' => 'pos.customers.view',
                        'description' => 'Data pelanggan dan saldo piutang.'],
                    ['key' => 'pos-receivables', 'label' => 'Piutang Pelanggan', 'route' => 'receivables.index', 'active' => 'receivables.*', 'permission' => 'pos.receivables.view',
                        'description' => 'Daftar piutang pelanggan dan pencatatan pelunasan.'],
                ],
            ],
        ],
    ],

    [
        'label' => 'Operasional',
        'items' => [
            [
                'key' => 'inventory', 'label' => 'Inventori', 'icon' => 'cube',
                'children' => [
                    ['key' => 'inventory-products', 'label' => 'Produk', 'route' => 'products.index', 'active' => 'products.*', 'permission' => 'inventory.products.view',
                        'description' => 'Katalog produk, harga, dan stok minimum.'],
                    ['key' => 'inventory-categories', 'label' => 'Kategori', 'route' => 'categories.index', 'active' => 'categories.*', 'permission' => 'inventory.categories.view',
                        'description' => 'Kelompok produk untuk kasir dan laporan.'],
                    ['key' => 'inventory-units', 'label' => 'Satuan', 'route' => 'units.index', 'active' => 'units.*', 'permission' => 'inventory.units.view',
                        'description' => 'Satuan jual dan satuan stok, misal pcs, porsi, kg.'],
                    ['key' => 'inventory-stock', 'label' => 'Stok', 'route' => 'stock.index', 'active' => ['stock.index', 'stock.adjust.*'], 'permission' => 'inventory.stock.view',
                        'description' => 'Posisi stok semua produk per gudang dan penyesuaian manual.'],
                    ['key' => 'inventory-stock-card', 'label' => 'Kartu Stok', 'route' => 'stock.movements', 'active' => 'stock.movements', 'permission' => 'inventory.stock-card.view',
                        'description' => 'Riwayat setiap pergerakan stok: masuk, keluar, dan penyesuaian.'],
                    ['key' => 'inventory-transfers', 'label' => 'Mutasi/Transfer Stok', 'route' => 'stock-transfers.index', 'active' => 'stock-transfers.*', 'permission' => 'inventory.transfers.view',
                        'description' => 'Pindahkan stok antar gudang.'],
                    ['key' => 'inventory-opname', 'label' => 'Stok Opname', 'route' => 'stock-opnames.index', 'active' => 'stock-opnames.*', 'permission' => 'inventory.opnames.view',
                        'description' => 'Hitung stok fisik dan catat selisihnya.'],
                    ['key' => 'inventory-warehouses', 'label' => 'Gudang/Lokasi', 'route' => 'warehouses.index', 'active' => 'warehouses.*', 'permission' => 'inventory.warehouses.view',
                        'description' => 'Kelola gudang dan lokasi penyimpanan; stok dicatat per gudang.'],
                    ['key' => 'inventory-recipes', 'label' => 'Resep (BOM)', 'route' => 'recipes.index', 'active' => 'recipes.*', 'permission' => 'inventory.recipes.view',
                        'description' => 'Komposisi bahan baku per produk dan perhitungan biayanya.'],
                ],
            ],
            [
                'key' => 'purchasing', 'label' => 'Pembelian', 'icon' => 'truck',
                'children' => [
                    ['key' => 'purchasing-suppliers', 'label' => 'Supplier', 'route' => 'suppliers.index', 'active' => 'suppliers.*', 'permission' => 'purchasing.suppliers.view',
                        'description' => 'Data pemasok barang.'],
                    ['key' => 'purchasing-orders', 'label' => 'Purchase Order', 'route' => 'purchase-orders.index', 'active' => 'purchase-orders.*', 'permission' => 'purchasing.orders.view',
                        'description' => 'Pesanan pembelian ke supplier: draft, disetujui, lalu diterima.'],
                    ['key' => 'purchasing-invoices', 'label' => 'Faktur Pembelian', 'route' => 'purchases.index', 'active' => 'purchases.*', 'permission' => 'purchasing.invoices.view',
                        'description' => 'Faktur dan hutang dari penerimaan barang, serta pembelian langsung tanpa PO.'],
                    ['key' => 'purchasing-receipts', 'label' => 'Penerimaan Barang', 'route' => 'goods-receipts.index', 'active' => 'goods-receipts.*', 'permission' => 'purchasing.receipts.view',
                        'description' => 'Terima barang dari Purchase Order, bisa sebagian, langsung ke gudang tujuan.'],
                    ['key' => 'purchasing-returns', 'label' => 'Retur Pembelian', 'route' => 'purchase-returns.index', 'active' => 'purchase-returns.*', 'permission' => 'purchasing.returns.view',
                        'description' => 'Kembalikan barang ke supplier dan kurangi hutang.'],
                    ['key' => 'purchasing-payables', 'label' => 'Hutang Supplier', 'route' => 'payables.index', 'active' => 'payables.*', 'permission' => 'purchasing.payables.view',
                        'description' => 'Daftar hutang ke supplier, jatuh tempo, dan pembayarannya.'],
                ],
            ],
        ],
    ],

    [
        'label' => 'Keuangan',
        'items' => [
            [
                'key' => 'accounting', 'label' => 'Akuntansi', 'icon' => 'calculator',
                'children' => [
                    ['key' => 'accounting-coa', 'label' => 'Bagan Akun (COA)', 'route' => 'accounts.index', 'active' => 'accounts.*', 'permission' => 'accounting.coa.view',
                        'description' => 'Daftar akun standar toko retail Indonesia.'],
                    ['key' => 'accounting-journals', 'label' => 'Jurnal Umum', 'route' => 'journals.index', 'active' => 'journals.*', 'permission' => 'accounting.journals.view',
                        'description' => 'Jurnal manual dan jurnal otomatis dari penjualan dan pembelian.'],
                    ['key' => 'accounting-periods', 'label' => 'Kunci Periode', 'route' => 'fiscal-periods.index', 'active' => 'fiscal-periods.*', 'permission' => 'accounting.periods.view',
                        'description' => 'Tutup bulan yang sudah final agar tidak ada transaksi baru atau perubahan bertanggal di bulan itu.'],
                    ['key' => 'accounting-ledger', 'label' => 'Buku Besar', 'status' => 'coming_soon', 'permission' => 'accounting.ledger.view',
                        'description' => 'Mutasi dan saldo per akun.'],
                    ['key' => 'accounting-cash-bank', 'label' => 'Kas & Bank', 'status' => 'coming_soon', 'permission' => 'accounting.cash-bank.view',
                        'description' => 'Pemasukan, pengeluaran, dan saldo kas serta rekening bank.'],
                    ['key' => 'accounting-reports', 'label' => 'Laporan Keuangan', 'status' => 'coming_soon', 'permission' => 'accounting.reports.view',
                        'description' => 'Laba rugi, neraca, dan arus kas.'],
                    ['key' => 'accounting-closing', 'label' => 'Tutup Buku', 'status' => 'coming_soon', 'permission' => 'accounting.closing.view',
                        'description' => 'Kunci periode yang sudah selesai agar tidak berubah.'],
                ],
            ],
        ],
    ],

    [
        'label' => 'Sumber Daya Manusia',
        'items' => [
            [
                'key' => 'sdm', 'label' => 'SDM', 'icon' => 'user-group',
                'children' => [
                    ['key' => 'sdm-employees', 'label' => 'Karyawan', 'route' => 'employees.index', 'active' => 'employees.*', 'permission' => 'sdm.employees.view',
                        'description' => 'Data karyawan, terhubung ke akun login bila ada.'],
                    ['key' => 'sdm-departments', 'label' => 'Departemen & Jabatan', 'route' => 'departments.index', 'active' => ['departments.*', 'positions.*'], 'permission' => 'sdm.departments.view',
                        'description' => 'Struktur departemen dan jabatan karyawan.'],
                    ['key' => 'sdm-attendances', 'label' => 'Absensi', 'route' => 'attendances.index', 'active' => 'attendances.*', 'permission' => ['sdm.attendances.view', 'sdm.attendances.record'],
                        'description' => 'Absen masuk/pulang dan catatan izin, sakit, atau alpa.'],
                    ['key' => 'sdm-schedules', 'label' => 'Jadwal Kerja', 'route' => 'schedules.index', 'active' => 'schedules.*', 'permission' => 'sdm.schedules.view',
                        'description' => 'Jadwal shift tiap pegawai per hari.'],
                    ['key' => 'sdm-shifts', 'label' => 'Shift Kerja', 'route' => 'shifts.index', 'active' => 'shifts.*', 'permission' => 'sdm.shifts.view',
                        'description' => 'Jam masuk dan pulang tiap shift kerja.'],
                    ['key' => 'sdm-leaves', 'label' => 'Cuti & Izin', 'route' => 'leaves.index', 'active' => 'leaves.*', 'permission' => ['sdm.leaves.view', 'sdm.leaves.create', 'sdm.leaves.approve'],
                        'description' => 'Pengajuan cuti dan izin beserta persetujuannya.'],
                    ['key' => 'sdm-leave-types', 'label' => 'Jenis Cuti', 'route' => 'leave-types.index', 'active' => 'leave-types.*', 'permission' => 'sdm.leave-types.view',
                        'description' => 'Jenis cuti, kuota tahunan, dan alur persetujuan.'],
                    ['key' => 'sdm-payroll', 'label' => 'Penggajian', 'status' => 'coming_soon', 'permission' => 'sdm.payroll.view',
                        'description' => 'Hitung gaji dari absensi dan buat jurnal beban gaji.'],
                ],
            ],
        ],
    ],

    [
        'label' => 'Analitik',
        'items' => [
            [
                'key' => 'reports', 'label' => 'Laporan', 'icon' => 'chart-bar',
                'children' => [
                    ['key' => 'reports-sales', 'label' => 'Penjualan', 'route' => 'reports.index', 'active' => 'reports.*', 'permission' => 'reports.sales.view',
                        'description' => 'Ringkasan, harian, per produk, per kasir, dan per metode pembayaran.'],
                    ['key' => 'reports-purchases', 'label' => 'Pembelian', 'status' => 'coming_soon', 'permission' => 'reports.purchases.view',
                        'description' => 'Pembelian per supplier, per produk, dan per periode.'],
                    ['key' => 'reports-stock', 'label' => 'Stok', 'status' => 'coming_soon', 'permission' => 'reports.stock.view',
                        'description' => 'Nilai persediaan dan stok per gudang.'],
                    ['key' => 'reports-gross-profit', 'label' => 'Laba Kotor', 'status' => 'coming_soon', 'permission' => 'reports.gross-profit.view',
                        'description' => 'Laba kotor per produk, kategori, dan periode.'],
                    ['key' => 'reports-cashiers', 'label' => 'Kinerja Kasir', 'status' => 'coming_soon', 'permission' => 'reports.cashiers.view',
                        'description' => 'Jumlah transaksi, omzet, dan selisih setoran per kasir.'],
                ],
            ],
        ],
    ],

    [
        'label' => 'Sistem',
        'items' => [
            [
                'key' => 'access', 'label' => 'Pengguna & Hak Akses', 'icon' => 'shield-check',
                'children' => [
                    ['key' => 'access-users', 'label' => 'Pengguna', 'route' => 'users.index', 'active' => 'users.*', 'permission' => 'access.users.view',
                        'description' => 'Akun login, peran, dan status aktif.'],
                    ['key' => 'access-roles', 'label' => 'Peran (Role)', 'route' => 'roles.index', 'active' => 'roles.*', 'permission' => 'access.roles.view',
                        'description' => 'Peran bawaan dan peran buatan sendiri.'],
                    ['key' => 'access-permissions', 'label' => 'Hak Akses', 'route' => 'permissions.index', 'active' => 'permissions.*', 'permission' => 'access.permissions.view',
                        'description' => 'Matriks hak akses per peran, dikelompokkan per modul.'],
                    ['key' => 'access-activity-log', 'label' => 'Log Aktivitas', 'route' => 'activity-log.index', 'active' => 'activity-log.*', 'permission' => 'access.activity-log.view',
                        'description' => 'Siapa mengubah apa dan kapan.'],
                ],
            ],
            [
                'key' => 'settings', 'label' => 'Pengaturan', 'icon' => 'cog-6-tooth',
                'children' => [
                    ['key' => 'settings-store', 'label' => 'Profil Toko', 'route' => 'settings.edit', 'active' => 'settings.edit', 'permission' => 'settings.store.view',
                        'description' => 'Identitas toko: nama, alamat, NPWP, logo, dan media sosial.'],
                    ['key' => 'settings-branches', 'label' => 'Cabang', 'route' => 'settings.branches.index', 'active' => 'settings.branches.*', 'permission' => 'settings.branches.view',
                        'description' => 'Daftar cabang/outlet dan cabang utama.'],
                    ['key' => 'settings-taxes', 'label' => 'Pajak', 'route' => 'settings.taxes.edit', 'active' => 'settings.taxes.*', 'permission' => 'settings.taxes.view',
                        'description' => 'Tarif PPN dan cara perhitungannya (termasuk atau belum termasuk harga).'],
                    ['key' => 'settings-payment-methods', 'label' => 'Metode Pembayaran', 'route' => 'settings.payment-methods.edit', 'active' => 'settings.payment-methods.*', 'permission' => 'settings.payment-methods.view',
                        'description' => 'Aktifkan, nonaktifkan, dan urutkan metode pembayaran di kasir.'],
                    ['key' => 'settings-pos', 'label' => 'Kasir', 'route' => 'settings.pos.edit', 'active' => 'settings.pos.*', 'permission' => 'settings.pos.view',
                        'description' => 'Aturan layar Kasir, misal wajib buka sesi sebelum menjual.'],
                    ['key' => 'settings-accounting', 'label' => 'Akuntansi', 'route' => 'settings.accounting.edit', 'active' => 'settings.accounting.*', 'permission' => 'settings.accounting.view',
                        'description' => 'Nyalakan atau matikan jurnal otomatis dari penjualan dan pembelian.'],
                    ['key' => 'settings-receipts', 'label' => 'Struk & Printer', 'route' => 'settings.receipts.edit', 'active' => 'settings.receipts.*', 'permission' => 'settings.receipts.view',
                        'description' => 'Tata letak struk, pratinjau, dan pengaturan printer.'],
                    ['key' => 'settings-approvals', 'label' => 'Alur Persetujuan', 'route' => 'settings.approval-flows.index', 'active' => 'settings.approval-flows.*', 'permission' => 'settings.approvals.view',
                        'description' => 'Dokumen yang perlu disetujui (misal PO di atas Rp 5.000.000), tahapnya, dan siapa penyetujunya.'],
                    ['key' => 'settings-numbering', 'label' => 'Penomoran Dokumen', 'route' => 'settings.numbering.edit', 'active' => 'settings.numbering.*', 'permission' => 'settings.numbering.view',
                        'description' => 'Format nomor dokumen, misal INV/2026/11/0001.'],
                    ['key' => 'settings-system', 'label' => 'Sistem', 'route' => 'settings.system.index', 'active' => 'settings.system.*', 'permission' => 'settings.system.view',
                        'description' => 'Status scheduler dan queue worker, antrean email & ekspor, dan job yang gagal.'],
                    ['key' => 'settings-backup', 'label' => 'Backup', 'route' => 'settings.backups.index', 'active' => 'settings.backups.*', 'permission' => 'settings.backup.view',
                        'description' => 'Buat dan unduh cadangan database.'],
                ],
            ],
        ],
    ],
];

<?php

/*
|--------------------------------------------------------------------------
| Akuntansi: pemetaan akun untuk jurnal otomatis
|--------------------------------------------------------------------------
|
| Setiap peran akun (penjualan, PPN keluaran, persediaan, ...) punya akun bawaan berdasarkan
| kode di bagan akun standar (App\Services\Accounting\ChartOfAccountsService). Pemilik bisa
| memilih akun lain di Pengaturan → Akuntansi; pilihannya disimpan di setting
| accounting.map.{peran} (id akun) dan dibaca lewat App\Services\Accounting\AccountMappingService.
|
|   group    : kelompok di halaman pengaturan
|   types    : kelompok akun (App\Enums\AccountType) yang boleh dipilih
|   optional : boleh dikosongkan (misal diskon penjualan: kosong = diskon langsung mengurangi penjualan)
|   cash_bank: hanya akun bertanda kas/bank yang boleh dipilih
|
| Akun yang sedang dipetakan ditandai "akun sistem": tidak bisa dihapus, dinonaktifkan, atau
| diganti kode/kelompoknya. Nyala/mati jurnal otomatis: setting accounting.auto_journal.
|
*/

return [
    'mapping' => [
        'cash' => ['group' => 'Kas & bank', 'label' => 'Kas (pembayaran tunai)', 'default' => '1-1100', 'types' => ['asset'], 'cash_bank' => true],
        'bank' => ['group' => 'Kas & bank', 'label' => 'Bank (metode non-tunai tanpa akun khusus)', 'default' => '1-1200', 'types' => ['asset'], 'cash_bank' => true],

        'sales' => ['group' => 'Penjualan', 'label' => 'Penjualan', 'default' => '4-1000', 'types' => ['revenue']],
        'sales_discount' => ['group' => 'Penjualan', 'label' => 'Diskon penjualan', 'default' => '4-1200', 'types' => ['revenue', 'expense'], 'optional' => true,
            'hint' => 'Kosongkan bila diskon langsung mengurangi akun Penjualan.'],
        'sales_return' => ['group' => 'Penjualan', 'label' => 'Retur penjualan', 'default' => '4-1100', 'types' => ['revenue']],
        'vat_out' => ['group' => 'Penjualan', 'label' => 'PPN keluaran', 'default' => '2-1200', 'types' => ['liability']],
        'receivable' => ['group' => 'Penjualan', 'label' => 'Piutang usaha (pelanggan)', 'default' => '1-1300', 'types' => ['asset']],
        'cogs' => ['group' => 'Penjualan', 'label' => 'Harga pokok penjualan', 'default' => '5-1000', 'types' => ['cogs', 'expense']],

        'inventory' => ['group' => 'Pembelian & persediaan', 'label' => 'Persediaan barang dagang', 'default' => '1-1400', 'types' => ['asset']],
        'vat_in' => ['group' => 'Pembelian & persediaan', 'label' => 'PPN masukan', 'default' => '1-1500', 'types' => ['asset']],
        'payable' => ['group' => 'Pembelian & persediaan', 'label' => 'Hutang usaha (supplier)', 'default' => '2-1100', 'types' => ['liability']],
        'other_receivable' => ['group' => 'Pembelian & persediaan', 'label' => 'Uang retur dari supplier', 'default' => '1-1350', 'types' => ['asset']],
        'inventory_variance' => ['group' => 'Pembelian & persediaan', 'label' => 'Selisih persediaan (opname & penyesuaian stok)', 'default' => '5-2000', 'types' => ['cogs', 'expense']],
        'interbranch' => ['group' => 'Pembelian & persediaan', 'label' => 'Rekening antar cabang (transfer stok antar cabang)', 'default' => '1-1700', 'types' => ['asset', 'liability']],

        'customer_deposit' => ['group' => 'Piutang & hutang', 'label' => 'Uang muka pelanggan', 'default' => '2-1500', 'types' => ['liability'], 'hint' => 'Uang dari pelanggan yang belum dipakai melunasi faktur.'],
        'supplier_deposit' => ['group' => 'Piutang & hutang', 'label' => 'Uang muka pembelian (ke supplier)', 'default' => '1-1360', 'types' => ['asset']],
        'credit_note' => ['group' => 'Piutang & hutang', 'label' => 'Nota kredit (bawaan)', 'default' => '4-1300', 'types' => ['revenue', 'expense'], 'hint' => 'Akun bawaan saat membuat nota kredit; bisa diganti per nota.'],
        'debit_note' => ['group' => 'Piutang & hutang', 'label' => 'Nota debit (bawaan)', 'default' => '5-1500', 'types' => ['cogs', 'expense', 'revenue', 'asset'], 'hint' => 'Akun bawaan saat membuat nota debit; bisa diganti per nota.'],
        'giro_receivable' => ['group' => 'Piutang & hutang', 'label' => 'Giro/cek diterima (belum cair)', 'default' => '1-1150', 'types' => ['asset']],
        'giro_payable' => ['group' => 'Piutang & hutang', 'label' => 'Giro/cek dikeluarkan (belum cair)', 'default' => '2-1150', 'types' => ['liability']],
        'bank_fee' => ['group' => 'Kas & bank', 'label' => 'Biaya administrasi bank', 'default' => '6-1600', 'types' => ['expense'], 'hint' => 'Bawaan untuk biaya transfer dan potongan bank di rekonsiliasi.'],

        'salary_expense' => ['group' => 'SDM', 'label' => 'Beban gaji & upah', 'default' => '6-1000', 'types' => ['expense'], 'hint' => 'Dipakai modul Penggajian.'],
        'salary_payable' => ['group' => 'SDM', 'label' => 'Hutang gaji', 'default' => '2-1300', 'types' => ['liability'], 'hint' => 'Dipakai modul Penggajian.'],

        'opening_equity' => ['group' => 'Ekuitas', 'label' => 'Ekuitas saldo awal', 'default' => '3-9000', 'types' => ['equity'], 'hint' => 'Penyeimbang saat saldo awal diposting.'],
        'retained_earnings' => ['group' => 'Ekuitas', 'label' => 'Laba ditahan', 'default' => '3-3000', 'types' => ['equity'], 'hint' => 'Dipakai tutup buku tahunan.'],
    ],

    // Metode pembayaran tanpa akun khusus memakai peran ini. Metode yang tidak tercantum memakai 'bank'.
    'payment_accounts' => [
        'cash' => 'cash',
    ],
];

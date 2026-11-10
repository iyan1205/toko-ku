<?php

/*
|--------------------------------------------------------------------------
| Akuntansi: akun yang dipakai jurnal otomatis
|--------------------------------------------------------------------------
|
| Jurnal otomatis (penjualan, pembelian, pembayaran, retur, pembatalan) mencari akun
| berdasarkan kode di bawah ini. Kode bawaan sama dengan bagan akun standar
| (Database\Seeders\ChartOfAccountsSeeder). Akun-akun ini ditandai "akun sistem" sehingga
| tidak bisa dihapus atau dinonaktifkan. Nyala/mati jurnal otomatis diatur di
| Pengaturan → Akuntansi (setting accounting.auto_journal).
|
*/

return [
    'accounts' => [
        'cash' => '1-1100',             // Kas
        'bank' => '1-1200',             // Bank (transfer, debit, kartu kredit, QRIS, e-wallet)
        'receivable' => '1-1300',       // Piutang usaha (pelanggan)
        'other_receivable' => '1-1350', // Piutang lain-lain (uang retur dari supplier)
        'inventory' => '1-1400',        // Persediaan barang dagang
        'vat_in' => '1-1500',           // PPN masukan
        'payable' => '2-1100',          // Hutang usaha (supplier)
        'vat_out' => '2-1200',          // PPN keluaran
        'sales' => '4-1000',            // Penjualan
        'sales_return' => '4-1100',     // Retur penjualan
        'cogs' => '5-1000',             // Harga pokok penjualan
    ],

    // Metode pembayaran => kunci akun di atas. Metode yang tidak tercantum memakai 'bank'.
    'payment_accounts' => [
        'cash' => 'cash',
    ],
];

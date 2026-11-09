<?php

return [
    /*
    | Izinkan stok menjadi minus. Default MATI: penjualan/pengurangan yang melebihi stok ditolak
    | dengan pesan "Stok tidak mencukupi.". Nanti dipindah ke menu Pengaturan Toko (tabel settings).
    */
    'allow_negative_stock' => (bool) env('POS_ALLOW_NEGATIVE_STOCK', false),

    /*
    | ---------------------------------------------------------------------------
    | Struk thermal
    | ---------------------------------------------------------------------------
    | Struk dicetak dari browser komputer kasir (window.print) ke printer thermal
    | yang terpasang di komputer itu. Server tidak perlu terhubung ke printer.
    |
    | Identitas toko (nama, alamat, NPWP, logo, media sosial) diatur di
    | Pengaturan → Profil Toko; lebar kertas, footer, logo/NPWP di struk, dan
    | cetak otomatis di Pengaturan → Struk & Printer (tabel `settings`).
    |
    | 'cetak_otomatis' : bila true, halaman struk langsung membuka dialog cetak
    |               saat dibuka. Sejak Fase 4 diatur di Pengaturan → Struk & Printer;
    |               nilai .env ini hanya menjadi bawaan sampai pilihan di sana disimpan.
    */
    'struk' => [
        'cetak_otomatis' => (bool) env('POS_STRUK_AUTO_PRINT', false),
    ],

    /*
    | ---------------------------------------------------------------------------
    | Absensi pegawai
    | ---------------------------------------------------------------------------
    | 'jam_masuk'       : jam mulai kerja "HH:MM", contoh "08:00". Pegawai yang absen
    |                     masuk lewat jam ini + toleransi tercatat Terlambat.
    |                     Kosong (bawaan) = tidak ada penanda terlambat, semua Hadir.
    | 'toleransi_menit' : kelonggaran sebelum dianggap terlambat.
    */
    'absensi' => [
        'jam_masuk' => env('POS_ABSENSI_JAM_MASUK'),
        'toleransi_menit' => (int) env('POS_ABSENSI_TOLERANSI_MENIT', 0),
    ],
];

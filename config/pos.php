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
    | Identitas toko (nama, alamat, telepon, Instagram, TikTok, pemilik), lebar
    | kertas, dan footer diatur pemilik lewat menu Pengaturan → Profil Toko
    | (tabel `settings`, lihat StoreProfileService). Di sini hanya tinggal
    | perilaku cetak yang tidak perlu diubah dari aplikasi.
    |
    | 'cetak_otomatis' : bila true, halaman struk langsung membuka dialog cetak
    |               saat dibuka. Default false supaya tidak mengganggu saat
    |               struk dibuka untuk dilihat/direkam.
    */
    'struk' => [
        'cetak_otomatis' => (bool) env('POS_STRUK_AUTO_PRINT', false),
    ],
];

<?php

return [
    /*
    | Izinkan stok menjadi minus. Default MATI: penjualan/pengurangan yang melebihi stok ditolak
    | dengan pesan "Stok tidak mencukupi.". Nanti dipindah ke menu Pengaturan Toko (tabel settings).
    */
    'allow_negative_stock' => (bool) env('POS_ALLOW_NEGATIVE_STOCK', false),
];

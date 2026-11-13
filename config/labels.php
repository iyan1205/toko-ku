<?php

/*
|--------------------------------------------------------------------------
| Ukuran label barcode/harga (Inventori → Cetak Label)
|--------------------------------------------------------------------------
| width/height dalam mm. roll = printer label (satu label per halaman); a4 = kertas stiker A4 (kolom
| dihitung dari lebar). Tambahkan ukuran lain di sini sesuai kertas label yang dipakai.
*/

return [
    'sizes' => [
        '33x15' => ['label' => '33 × 15 mm (stiker kecil, misal T&J 103)', 'width' => 33, 'height' => 15],
        '38x25' => ['label' => '38 × 25 mm', 'width' => 38, 'height' => 25],
        '50x25' => ['label' => '50 × 25 mm', 'width' => 50, 'height' => 25],
        '58x40' => ['label' => '58 × 40 mm (rak)', 'width' => 58, 'height' => 40],
    ],
    'max_labels' => 1000,
];

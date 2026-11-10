<?php

use App\Models\Journal;
use App\Models\PurchaseOrder;

/*
|--------------------------------------------------------------------------
| Dokumen yang bisa diberi alur persetujuan (Pengaturan → Alur Persetujuan)
|--------------------------------------------------------------------------
| Model dokumen memakai trait App\Models\Concerns\HasApproval. fields = nilai dokumen yang
| bisa dipakai di kondisi alur (dibaca lewat approvalValue()), semuanya nominal Rupiah.
|
| Menambah dokumen: pakai HasApproval di modelnya, daftarkan di sini, dan sediakan tombol
| "Ajukan persetujuan" di halaman dokumennya (komponen x-approval.panel).
*/

return [
    'documents' => [
        'purchase_order' => [
            'label' => 'Purchase order',
            'model' => PurchaseOrder::class,
            'fields' => ['total' => 'Total'],
        ],
        'journal' => [
            'label' => 'Jurnal umum (manual)',
            'model' => Journal::class,
            'fields' => ['total' => 'Total'],
        ],
    ],

    'operators' => [
        '>' => 'lebih dari',
        '>=' => 'minimal',
        '<' => 'kurang dari',
        '<=' => 'maksimal',
        '=' => 'sama dengan',
    ],
];

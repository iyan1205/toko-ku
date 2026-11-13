<?php

use App\Models\AttendanceCorrection;
use App\Models\CashTransaction;
use App\Models\Journal;
use App\Models\OvertimeRequest;
use App\Models\PurchaseOrder;
use App\Models\ReimbursementRequest;

/*
|--------------------------------------------------------------------------
| Dokumen yang bisa diberi alur persetujuan (Pengaturan → Alur Persetujuan)
|--------------------------------------------------------------------------
| Model dokumen memakai trait App\Models\Concerns\HasApproval. fields = nilai dokumen yang
| bisa dipakai di kondisi alur (dibaca lewat approvalValue()): nominal Rupiah, kecuali jam lembur.
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
        'cash_out' => [
            'label' => 'Pengeluaran kas',
            'model' => CashTransaction::class,
            'fields' => ['total' => 'Total'],
        ],
        'cash_transfer' => [
            'label' => 'Transfer antar rekening',
            'model' => CashTransaction::class,
            'fields' => ['total' => 'Total'],
        ],
        'overtime' => [
            'label' => 'Pengajuan lembur',
            'model' => OvertimeRequest::class,
            'fields' => ['hours' => 'Jam lembur'],
        ],
        'reimbursement' => [
            'label' => 'Pengajuan reimbursement',
            'model' => ReimbursementRequest::class,
            'fields' => ['total' => 'Total'],
        ],
        'attendance_correction' => [
            'label' => 'Koreksi absensi',
            'model' => AttendanceCorrection::class,
            'fields' => ['days_late' => 'Selisih hari pengajuan'],
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

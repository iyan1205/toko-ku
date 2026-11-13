<?php

use App\Models\AttendanceCorrection;
use App\Models\CashTransaction;
use App\Models\Cuti;
use App\Models\Journal;
use App\Models\OvertimeRequest;
use App\Models\PurchaseOrder;
use App\Models\ReimbursementRequest;
use App\Models\StockOpname;

/*
|--------------------------------------------------------------------------
| Dokumen yang bisa diberi alur persetujuan (Pengaturan → Alur Persetujuan)
|--------------------------------------------------------------------------
| Model dokumen memakai trait App\Models\Concerns\HasApproval. fields = nilai dokumen yang
| bisa dipakai di kondisi alur (dibaca lewat approvalValue()). Bawaannya nominal Rupiah; formats
| mengubahnya menjadi angka biasa (type number + unit) atau pilihan (type option + options = sumber
| daftar di ApprovalFlow::fieldOptions()). supervisor = true: tahap boleh "Atasan langsung" pengaju
| (dokumen menyediakan approvalSupervisor()).
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
            'formats' => ['hours' => ['type' => 'number', 'unit' => 'jam']],
            'supervisor' => true,
        ],
        'reimbursement' => [
            'label' => 'Pengajuan reimbursement',
            'model' => ReimbursementRequest::class,
            'fields' => ['total' => 'Total'],
            'supervisor' => true,
        ],
        'attendance_correction' => [
            'label' => 'Koreksi absensi',
            'model' => AttendanceCorrection::class,
            'fields' => ['days_late' => 'Selisih hari pengajuan'],
            'formats' => ['days_late' => ['type' => 'number', 'unit' => 'hari']],
            'supervisor' => true,
        ],
        'stock_opname' => [
            'label' => 'Stok opname (penyesuaian selisih)',
            'model' => StockOpname::class,
            'fields' => ['total' => 'Nilai selisih', 'items' => 'Jumlah produk berselisih'],
            'formats' => ['items' => ['type' => 'number', 'unit' => 'produk']],
        ],
        'leave' => [
            'label' => 'Cuti & izin',
            'model' => Cuti::class,
            'fields' => ['leave_type' => 'Jenis cuti', 'days' => 'Jumlah hari'],
            'formats' => ['leave_type' => ['type' => 'option', 'options' => 'leave_types'], 'days' => ['type' => 'number', 'unit' => 'hari']],
            'supervisor' => true,
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

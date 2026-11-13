<?php

/*
|--------------------------------------------------------------------------
| SDM: pilihan data karyawan, dokumen, offboarding, dan reimbursement
|--------------------------------------------------------------------------
| Pilihan di sini hanya daftar isian (bukan tarif). Tarif BPJS, PTKP, dan PPh 21 diatur sebagai
| data berlaku-per-tanggal di penggajian. Lama pengingat kontrak/dokumen: setting('sdm.reminder_days').
*/

return [
    'religions' => ['Islam', 'Kristen Protestan', 'Katolik', 'Hindu', 'Buddha', 'Konghucu', 'Lainnya'],

    'blood_types' => ['A', 'B', 'AB', 'O'],

    'banks' => ['BCA', 'BRI', 'BNI', 'Mandiri', 'BSI', 'CIMB Niaga', 'Permata', 'Danamon', 'BTN', 'Lainnya'],

    // Jenis dokumen karyawan (kunci => label). Dokumen dengan masa berlaku diingatkan sebelum kedaluwarsa.
    'document_types' => [
        'ktp' => 'KTP',
        'kk' => 'Kartu keluarga',
        'npwp' => 'NPWP',
        'bpjs' => 'Kartu BPJS',
        'diploma' => 'Ijazah',
        'contract' => 'Perjanjian kerja',
        'driving_license' => 'SIM',
        'certificate' => 'Sertifikat',
        'health' => 'Surat keterangan sehat',
        'other' => 'Lainnya',
    ],

    // Barang standar di checklist offboarding; aset tetap yang dipegang karyawan ikut ditambahkan otomatis.
    'offboarding_checklist' => [
        'Kartu identitas karyawan',
        'Seragam',
        'Kunci & akses (pintu, loker, laci kasir)',
        'Serah terima pekerjaan',
    ],

    'reimbursement_categories' => [
        'transport' => 'Transportasi',
        'meal' => 'Makan',
        'medical' => 'Kesehatan',
        'communication' => 'Pulsa & internet',
        'supplies' => 'Perlengkapan kerja',
        'other' => 'Lainnya',
    ],
    /*
    | Jenis cuti standar (tombol "Lengkapi jenis cuti standar" di SDM → Jenis Cuti) sebagai titik awal,
    | merujuk UU 13/2003 pasal 79, 82, 93. Pemilik tetap mengubahnya di halaman jenis cuti; nilai di sini
    | hanya dipakai saat tombol ditekan (jenis yang sudah diatur tidak ditimpa).
    */
    'standard_leave_types' => [
        ['nama' => 'Cuti Tahunan', 'category' => 'annual', 'kuota_tahunan' => 12, 'quota_tiers' => [['months' => 12, 'days' => 12]], 'min_service_months' => 12, 'status_absensi' => 'cuti'],
        ['nama' => 'Sakit', 'category' => 'sick', 'status_absensi' => 'sakit', 'requires_document' => true, 'document_after_days' => 1],
        ['nama' => 'Cuti Melahirkan', 'category' => 'maternity', 'gender' => 'female', 'max_days_per_request' => 90, 'working_days_only' => false, 'status_absensi' => 'cuti', 'requires_document' => true],
        ['nama' => 'Cuti Keguguran', 'category' => 'maternity', 'gender' => 'female', 'max_days_per_request' => 45, 'working_days_only' => false, 'status_absensi' => 'cuti', 'requires_document' => true],
        ['nama' => 'Cuti Menikah', 'category' => 'special', 'max_days_per_request' => 3, 'status_absensi' => 'cuti'],
        ['nama' => 'Menikahkan Anak', 'category' => 'special', 'max_days_per_request' => 2, 'status_absensi' => 'cuti'],
        ['nama' => 'Khitanan / Baptis Anak', 'category' => 'special', 'max_days_per_request' => 2, 'status_absensi' => 'cuti'],
        ['nama' => 'Istri Melahirkan / Keguguran', 'category' => 'special', 'gender' => 'male', 'max_days_per_request' => 2, 'status_absensi' => 'cuti'],
        ['nama' => 'Keluarga Inti Meninggal', 'category' => 'special', 'max_days_per_request' => 2, 'status_absensi' => 'cuti'],
        ['nama' => 'Anggota Keluarga Serumah Meninggal', 'category' => 'special', 'max_days_per_request' => 1, 'status_absensi' => 'cuti'],
        ['nama' => 'Cuti Tanpa Upah', 'category' => 'unpaid', 'is_paid' => false, 'status_absensi' => 'izin'],
    ],
];

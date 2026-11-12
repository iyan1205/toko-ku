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
];

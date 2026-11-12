<?php

/*
|--------------------------------------------------------------------------
| Format ekspor data faktur pajak (Akuntansi → Pajak → PPN)
|--------------------------------------------------------------------------
|
| Format impor sistem pajak DJP (e-Faktur/Coretax) bisa berubah. Susunan kolom ekspor diatur di
| sini, tanpa mengubah kode: tambah/ubah/urutkan kolom, ganti judul, atau tambah format baru.
|
|   header : judul kolom di berkas
|   field  : data yang diisi (lihat daftar di bawah)
|   format : khusus tanggal, format PHP date (misal 'd/m/Y')
|   value  : nilai tetap (bila kolom harus diisi konstan), menggantikan field
|
| Field: date, number, type, party_tax_id, party_name, party_address, dpp, rate, ppn, reference
|
*/

return [
    'formats' => [
        'keluaran' => [
            'label' => 'Faktur pajak keluaran',
            'columns' => [
                ['header' => 'Tanggal Faktur', 'field' => 'date', 'format' => 'd/m/Y'],
                ['header' => 'Nomor Dokumen', 'field' => 'number'],
                ['header' => 'Jenis Dokumen', 'field' => 'type'],
                ['header' => 'NPWP/NIK Pembeli', 'field' => 'party_tax_id'],
                ['header' => 'Nama Pembeli', 'field' => 'party_name'],
                ['header' => 'Alamat Pembeli', 'field' => 'party_address'],
                ['header' => 'DPP', 'field' => 'dpp'],
                ['header' => 'Tarif PPN', 'field' => 'rate'],
                ['header' => 'PPN', 'field' => 'ppn'],
                ['header' => 'Referensi', 'field' => 'reference'],
            ],
        ],
        'masukan' => [
            'label' => 'Faktur pajak masukan',
            'columns' => [
                ['header' => 'Tanggal Faktur', 'field' => 'date', 'format' => 'd/m/Y'],
                ['header' => 'Nomor Faktur Supplier', 'field' => 'reference'],
                ['header' => 'Nomor Dokumen', 'field' => 'number'],
                ['header' => 'NPWP Penjual', 'field' => 'party_tax_id'],
                ['header' => 'Nama Penjual', 'field' => 'party_name'],
                ['header' => 'DPP', 'field' => 'dpp'],
                ['header' => 'Tarif PPN', 'field' => 'rate'],
                ['header' => 'PPN', 'field' => 'ppn'],
            ],
        ],
    ],
];

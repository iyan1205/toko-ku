<?php

/*
| Pesan validasi Bahasa Indonesia. Kunci yang tidak tercantum di sini
| otomatis memakai bawaan Laravel (bahasa Inggris) lewat fallback locale.
*/
return [
    'accepted' => ':attribute harus disetujui.',
    'array' => ':attribute harus berupa array.',
    'between' => [
        'numeric' => ':attribute harus di antara :min dan :max.',
        'string' => ':attribute harus terdiri dari :min sampai :max karakter.',
    ],
    'boolean' => ':attribute harus bernilai benar atau salah.',
    'confirmed' => 'Konfirmasi :attribute tidak cocok.',
    'current_password' => 'Kata sandi saat ini salah.',
    'date' => ':attribute bukan tanggal yang valid.',
    'decimal' => ':attribute harus memiliki :decimal angka desimal.',
    'email' => ':attribute harus berupa alamat email yang valid.',
    'file' => ':attribute harus berupa berkas.',
    'uploaded' => ':attribute gagal diunggah.',
    'exists' => ':attribute yang dipilih tidak valid.',
    'gt' => [
        'numeric' => ':attribute harus lebih besar dari :value.',
    ],
    'gte' => [
        'numeric' => ':attribute harus lebih besar atau sama dengan :value.',
    ],
    'image' => ':attribute harus berupa gambar.',
    'in' => ':attribute yang dipilih tidak valid.',
    'integer' => ':attribute harus berupa bilangan bulat.',
    'lt' => [
        'numeric' => ':attribute harus lebih kecil dari :value.',
    ],
    'lte' => [
        'numeric' => ':attribute harus lebih kecil atau sama dengan :value.',
    ],
    'max' => [
        'numeric' => ':attribute tidak boleh lebih dari :max.',
        'file' => ':attribute tidak boleh lebih dari :max kilobita.',
        'string' => ':attribute tidak boleh lebih dari :max karakter.',
        'array' => ':attribute tidak boleh lebih dari :max item.',
    ],
    'mimes' => ':attribute harus berupa berkas bertipe: :values.',
    'min' => [
        'numeric' => ':attribute minimal :min.',
        'file' => ':attribute minimal :min kilobita.',
        'string' => ':attribute minimal :min karakter.',
        'array' => ':attribute minimal terdiri dari :min item.',
    ],
    'numeric' => ':attribute harus berupa angka.',
    'password' => [
        'letters' => ':attribute harus mengandung setidaknya satu huruf.',
        'mixed' => ':attribute harus mengandung huruf besar dan huruf kecil.',
        'numbers' => ':attribute harus mengandung setidaknya satu angka.',
        'symbols' => ':attribute harus mengandung setidaknya satu simbol.',
        'uncompromised' => ':attribute yang dipilih pernah bocor di internet. Pilih :attribute lain.',
    ],
    'regex' => 'Format :attribute tidak valid.',
    'required' => ':attribute wajib diisi.',
    'size' => [
        'string' => ':attribute harus terdiri dari :size karakter.',
    ],
    'string' => ':attribute harus berupa teks.',
    'unique' => ':attribute sudah digunakan.',

    'custom' => [],
    'attributes' => [],
];

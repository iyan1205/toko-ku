<?php

/*
|--------------------------------------------------------------------------
| Jenis notifikasi dan kanal bawaannya
|--------------------------------------------------------------------------
| database = lonceng di topbar (langsung), mail = email (lewat queue).
| Pengguna bisa mengubahnya sendiri di Profil Saya → Notifikasi; tanpa pilihan, dipakai bawaan di sini.
| Kunci = App\Notifications\AppNotification::type().
*/

return [
    'approval.requested' => [
        'label' => 'Permintaan persetujuan',
        'description' => 'Ada dokumen yang menunggu persetujuan Anda.',
        'database' => true,
        'mail' => true,
    ],
    'approval.decided' => [
        'label' => 'Hasil persetujuan',
        'description' => 'Dokumen yang Anda ajukan disetujui atau ditolak.',
        'database' => true,
        'mail' => true,
    ],
    'export.ready' => [
        'label' => 'Ekspor selesai',
        'description' => 'File ekspor data besar siap diunduh, atau gagal dibuat.',
        'database' => true,
        'mail' => false,
    ],
];

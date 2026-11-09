<?php

use App\Support\SettingStore;

if (! function_exists('setting')) {
    /**
     * Pengaturan toko dari tabel `settings` (Pengaturan → Profil Toko, Pajak, Struk, dst.).
     *
     *   setting('nama_toko')                  nilai, atau null bila belum diisi
     *   setting('tax.rate', '11')             dengan nilai bawaan
     *   setting(['tax.rate' => '12'])         simpan satu atau beberapa nilai
     *   setting()                             semua nilai (key => nilai)
     *
     * Nilai selalu berupa string (atau $default). Ubah ke tipe lain di pemanggil.
     *
     * @param  string|array<string, string|int|bool|null>|null  $key
     */
    function setting(string|array|null $key = null, mixed $default = null): mixed
    {
        $store = app(SettingStore::class);

        if ($key === null) {
            return $store->all();
        }

        if (is_array($key)) {
            $store->set($key);

            return null;
        }

        return $store->get($key, $default);
    }
}

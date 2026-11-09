<?php

namespace App\Services;

use App\Models\Setting;

/**
 * Profil toko: identitas yang tampil di struk dan (nanti) dokumen lain.
 *
 * Nilai disimpan di tabel `settings`, tetapi SELALU ada nilai bawaan yang masuk akal
 * (nama dari config app.name, footer bawaan, lebar kertas 58 mm) supaya struk tetap
 * benar walau pemilik belum pernah membuka halaman Pengaturan.
 *
 * Lebar kertas sengaja dibaca dari sini, bukan config('pos.struk.*'), karena ini
 * pengaturan per-toko yang harus bisa diubah dari aplikasi tanpa menyentuh .env.
 */
class StoreProfileService
{
    /** Lebar kertas roll yang didukung printer thermal umum. */
    public const LEBAR_DIDUKUNG = [58, 80];

    /** Kunci pengaturan profil toko (dipakai juga oleh form). */
    public const KUNCI = [
        'nama_toko',
        'nama_pemilik',
        'alamat',
        'telepon',
        'instagram',
        'tiktok',
        'footer_struk',
        'lebar_struk_mm',
    ];

    public function __construct()
    {
        // dipakai lewat container; tidak ada dependensi
    }

    /** @return array<string, mixed> seluruh profil, sudah termasuk bawaan */
    public function ambil(): array
    {
        $tersimpan = Setting::semua();

        return [
            'nama_toko' => $this->nilai($tersimpan, 'nama_toko', config('app.name')),
            'nama_pemilik' => $this->nilai($tersimpan, 'nama_pemilik', ''),
            'alamat' => $this->nilai($tersimpan, 'alamat', ''),
            'telepon' => $this->nilai($tersimpan, 'telepon', ''),
            'instagram' => $this->nilai($tersimpan, 'instagram', ''),
            'tiktok' => $this->nilai($tersimpan, 'tiktok', ''),
            'footer_struk' => $this->nilai($tersimpan, 'footer_struk', 'Terima kasih telah berbelanja'),
            'lebar_struk_mm' => $this->lebar($tersimpan['lebar_struk_mm'] ?? null),
        ];
    }

    /** @param array<string, mixed> $data */
    public function simpan(array $data): void
    {
        $sebelum = $this->ambil();

        Setting::simpan([
            'nama_toko' => $data['nama_toko'] ?? null,
            'nama_pemilik' => $data['nama_pemilik'] ?? null,
            'alamat' => $data['alamat'] ?? null,
            'telepon' => $data['telepon'] ?? null,
            'instagram' => $data['instagram'] ?? null,
            'tiktok' => $data['tiktok'] ?? null,
            'footer_struk' => $data['footer_struk'] ?? null,
            'lebar_struk_mm' => isset($data['lebar_struk_mm']) ? (string) $data['lebar_struk_mm'] : null,
        ]);

        $sesudah = $this->ambil();
        $berubah = array_keys(array_diff_assoc(array_map('strval', $sesudah), array_map('strval', $sebelum)));

        if ($berubah !== []) {
            activity('pengaturan')->event('updated')
                ->withProperties([
                    'old' => array_intersect_key($sebelum, array_flip($berubah)),
                    'attributes' => array_intersect_key($sesudah, array_flip($berubah)),
                ])
                ->log('Profil toko diubah');
        }
    }

    /** Lebar kertas struk dalam mm; nilai tak dikenal dikembalikan ke 58. */
    public function lebar(?string $nilai = null): int
    {
        $nilai ??= Setting::ambil('lebar_struk_mm');
        $lebar = (int) $nilai;

        return in_array($lebar, self::LEBAR_DIDUKUNG, true) ? $lebar : 58;
    }

    /** @param array<string, string|null> $tersimpan */
    private function nilai(array $tersimpan, string $kunci, ?string $bawaan): string
    {
        $nilai = $tersimpan[$kunci] ?? null;

        return ($nilai === null || trim((string) $nilai) === '') ? (string) ($bawaan ?? '') : (string) $nilai;
    }
}

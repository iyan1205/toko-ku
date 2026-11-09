<?php

namespace App\Services\Settings;

use App\Models\Setting;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Profil toko: identitas yang tampil di struk dan dokumen lain (nama, alamat, NPWP, logo, ...),
 * ditambah lebar kertas dan footer struk yang diatur di Pengaturan → Struk & Printer.
 *
 * Nilai disimpan di tabel `settings`, tetapi SELALU ada nilai bawaan yang masuk akal
 * (nama dari config app.name, footer bawaan, lebar kertas 58 mm) supaya struk tetap
 * benar walau pemilik belum pernah membuka halaman Pengaturan.
 */
class StoreProfileService
{
    /** Lebar kertas roll yang didukung printer thermal umum. */
    public const LEBAR_DIDUKUNG = [58, 80];

    public const FOOTER_BAWAAN = 'Terima kasih telah berbelanja';

    /** Folder logo di disk `public`. */
    private const FOLDER_LOGO = 'toko';

    /** Kunci yang disimpan simpan(). Logo diurus terpisah (berkas unggahan). */
    public const KUNCI = [
        'nama_toko',
        'nama_pemilik',
        'alamat',
        'telepon',
        'email',
        'npwp',
        'instagram',
        'tiktok',
        'footer_struk',
        'lebar_struk_mm',
    ];

    /** @return array<string, mixed> seluruh profil, sudah termasuk bawaan */
    public function ambil(): array
    {
        $tersimpan = Setting::semua();
        $logo = $this->nilai($tersimpan, 'logo', '');

        return [
            'nama_toko' => $this->nilai($tersimpan, 'nama_toko', config('app.name')),
            'nama_pemilik' => $this->nilai($tersimpan, 'nama_pemilik', ''),
            'alamat' => $this->nilai($tersimpan, 'alamat', ''),
            'telepon' => $this->nilai($tersimpan, 'telepon', ''),
            'email' => $this->nilai($tersimpan, 'email', ''),
            'npwp' => $this->nilai($tersimpan, 'npwp', ''),
            'instagram' => $this->nilai($tersimpan, 'instagram', ''),
            'tiktok' => $this->nilai($tersimpan, 'tiktok', ''),
            'logo' => $logo,
            'logo_url' => $logo !== '' ? Storage::disk('public')->url($logo) : null,
            'footer_struk' => $this->nilai($tersimpan, 'footer_struk', self::FOOTER_BAWAAN),
            'lebar_struk_mm' => $this->lebar($tersimpan['lebar_struk_mm'] ?? null),
        ];
    }

    /**
     * Simpan kolom yang ADA di $data saja; kolom yang tidak dikirim tidak diubah. Nilai kosong
     * berarti "pakai bawaan". Logo: kirim 'logo' (berkas) untuk mengganti, 'hapus_logo' => true
     * untuk menghapus.
     *
     * @param  array<string, mixed>  $data
     */
    public function simpan(array $data): void
    {
        $sebelum = $this->ambil();

        $pasangan = [];
        foreach (self::KUNCI as $kunci) {
            if (array_key_exists($kunci, $data)) {
                $pasangan[$kunci] = $data[$kunci] === null ? null : (string) $data[$kunci];
            }
        }

        $logoBaru = ($data['logo'] ?? null) instanceof UploadedFile ? $data['logo'] : null;
        if ($logoBaru || ($data['hapus_logo'] ?? false)) {
            $pasangan['logo'] = $logoBaru?->store(self::FOLDER_LOGO, 'public');
        }

        Setting::simpan($pasangan);

        if (array_key_exists('logo', $pasangan) && $sebelum['logo'] !== '') {
            Storage::disk('public')->delete($sebelum['logo']);
        }

        $sesudah = $this->ambil();
        $berubah = array_keys(array_diff_assoc(
            array_map('strval', array_diff_key($sesudah, ['logo_url' => true])),
            array_map('strval', array_diff_key($sebelum, ['logo_url' => true])),
        ));

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

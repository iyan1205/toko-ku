<?php

namespace App\Services\Settings;

use App\Models\User;

/**
 * Pengaturan Struk & Printer: lebar kertas, footer, cetak otomatis, dan bagian yang
 * ditampilkan (logo, NPWP). Lebar kertas dan footer memakai kunci lama di tabel settings
 * (lebar_struk_mm, footer_struk) sehingga nilai yang sudah diisi tetap terpakai.
 *
 * Cetak otomatis dulu diatur lewat .env (POS_STRUK_AUTO_PRINT). Nilai .env itu tetap
 * menjadi bawaan sampai pemilik menyimpan pilihan di halaman ini.
 */
class ReceiptSettingsService
{
    public function __construct(private readonly StoreProfileService $profile) {}

    /** @return array{paper_width: int, footer: string, auto_print: bool, show_logo: bool, show_npwp: bool} */
    public function get(): array
    {
        $profile = $this->profile->ambil();

        return [
            'paper_width' => $profile['lebar_struk_mm'],
            'footer' => $profile['footer_struk'],
            'auto_print' => $this->flag('receipt.auto_print', (bool) config('pos.struk.cetak_otomatis', false)),
            'show_logo' => $this->flag('receipt.show_logo', false),
            'show_npwp' => $this->flag('receipt.show_npwp', false),
        ];
    }

    /**
     * Identitas toko untuk kepala struk (HTML dan teks), sudah menyaring bagian yang
     * dimatikan: 'logo_url' null bila logo tidak ditampilkan, 'npwp' kosong bila NPWP tidak ditampilkan.
     *
     * @param  array{show_logo: bool, show_npwp: bool}|null  $settings  pilihan lain (pratinjau), bawaan = yang tersimpan
     * @return array<string, mixed>
     */
    public function storeHeader(?array $settings = null): array
    {
        $profile = $this->profile->ambil();
        $settings ??= $this->get();

        return array_merge($profile, [
            'logo_url' => $settings['show_logo'] ? $profile['logo_url'] : null,
            'npwp' => $settings['show_npwp'] ? $profile['npwp'] : '',
        ]);
    }

    /** @param array{paper_width: int, footer: ?string, auto_print: bool, show_logo: bool, show_npwp: bool} $data */
    public function save(array $data, User $actor): void
    {
        $before = $this->describe($this->get());

        setting([
            // Kunci lama: dibaca juga oleh StoreProfileService untuk struk HTML dan struk teks.
            'lebar_struk_mm' => (string) $data['paper_width'],
            'footer_struk' => $data['footer'],
            'receipt.auto_print' => (bool) $data['auto_print'],
            'receipt.show_logo' => (bool) $data['show_logo'],
            'receipt.show_npwp' => (bool) $data['show_npwp'],
        ]);

        $after = $this->describe($this->get());
        $changed = array_keys(array_diff_assoc($after, $before));

        if ($changed !== []) {
            activity('pengaturan')->causedBy($actor)->event('updated')
                ->withProperties([
                    'old' => array_intersect_key($before, array_flip($changed)),
                    'attributes' => array_intersect_key($after, array_flip($changed)),
                ])
                ->log('Pengaturan struk diubah');
        }
    }

    private function flag(string $key, bool $default): bool
    {
        $value = setting($key);

        return $value === null ? $default : $value === '1';
    }

    /**
     * @param  array{paper_width: int, footer: string, auto_print: bool, show_logo: bool, show_npwp: bool}  $settings
     * @return array<string, string>
     */
    private function describe(array $settings): array
    {
        return [
            'lebar_kertas' => $settings['paper_width'].' mm',
            'footer' => $settings['footer'],
            'cetak_otomatis' => $settings['auto_print'] ? 'ya' : 'tidak',
            'tampilkan_logo' => $settings['show_logo'] ? 'ya' : 'tidak',
            'tampilkan_npwp' => $settings['show_npwp'] ? 'ya' : 'tidak',
        ];
    }
}

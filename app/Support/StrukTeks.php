<?php

namespace App\Support;

use App\Models\Sale;
use App\Support\Format;

/**
 * Menyusun struk sebagai teks murni berkolom tetap untuk printer thermal.
 *
 * Dipakai jalur cetak langsung dari HP (RawBT / aplikasi jembatan Bluetooth):
 * printer thermal hanya menerima teks ESC/POS, bukan HTML. Lebar baris harus
 * tetap (32 karakter untuk kertas 58 mm, 48 untuk 80 mm) supaya kolom harga
 * sejajar di kertas, bukan dihitung ulang oleh font browser.
 */
final class StrukTeks
{
    /** Lebar kolom teks untuk tiap ukuran kertas. */
    private const LEBAR = [58 => 32, 80 => 48];

    public function __construct(
        private readonly Sale $penjualan,
        private readonly array $toko,
    ) {
    }

    public static function lebarKertas(int $lebarMm): int
    {
        return self::LEBAR[$lebarMm] ?? self::LEBAR[58];
    }

    /** Struk lengkap sebagai teks siap kirim ke printer. */
    public function susun(): string
    {
        $lebar = self::lebarKertas((int) ($this->toko['lebar_struk_mm'] ?? 58));
        $s = $this->penjualan;
        $baris = [];

        // ---------- Kepala: identitas toko ----------
        $baris[] = $this->tengah(mb_strtoupper((string) $this->toko['nama_toko']), $lebar);
        foreach (['nama_pemilik', 'alamat'] as $kunci) {
            if (! empty($this->toko[$kunci])) {
                $baris[] = $this->tengah((string) $this->toko[$kunci], $lebar);
            }
        }
        if (! empty($this->toko['telepon'])) {
            $baris[] = $this->tengah('Telp ' . $this->toko['telepon'], $lebar);
        }
        $baris[] = str_repeat('-', $lebar);

        // ---------- Info transaksi ----------
        $baris[] = $this->duaKolom('No', (string) $s->number, $lebar);
        $baris[] = $this->duaKolom('Tanggal', Format::tanggal($s->sold_at, true), $lebar);
        $baris[] = $this->duaKolom('Kasir', (string) ($s->user?->name ?? '-'), $lebar);
        $baris[] = $this->duaKolom('Pelanggan', (string) ($s->customer?->name ?? '-'), $lebar);
        $baris[] = str_repeat('-', $lebar);

        // ---------- Barang ----------
        foreach ($s->items as $i) {
            $baris[] = $this->potong((string) $i->product_name, $lebar);

            $kiri = Format::jumlah($i->quantity) . ' ' . $i->unit_name
                . ' x ' . Format::rupiah($i->unit_price, false);
            if ((float) $i->discount > 0) {
                $kiri .= ' (-' . Format::rupiah($i->discount, false) . ')';
            }
            $baris[] = $this->duaKolom($kiri, Format::rupiah($i->line_total, false), $lebar);
        }

        // ---------- Total ----------
        $baris[] = str_repeat('-', $lebar);
        $baris[] = $this->duaKolom('Subtotal', Format::rupiah($s->subtotal, false), $lebar);
        if ((float) $s->item_discount > 0) {
            $baris[] = $this->duaKolom('Diskon item', '-' . Format::rupiah($s->item_discount, false), $lebar);
        }
        if ((float) $s->discount > 0) {
            $baris[] = $this->duaKolom('Diskon', '-' . Format::rupiah($s->discount, false), $lebar);
        }
        $baris[] = $this->duaKolom('TOTAL', Format::rupiah($s->grand_total, false), $lebar);

        // ---------- Pembayaran ----------
        $baris[] = str_repeat('-', $lebar);
        $baris[] = $this->duaKolom('Metode', $s->payment_method->label(), $lebar);
        if ($s->payment_method->value === 'cash') {
            $baris[] = $this->duaKolom('Bayar', Format::rupiah($s->tendered_amount, false), $lebar);
            $baris[] = $this->duaKolom('Kembali', Format::rupiah($s->change_amount, false), $lebar);
        }
        $baris[] = $this->duaKolom(
            'Status',
            $s->dibatalkan() ? 'DIBATALKAN' : $s->payment_status->labelPenjualan(),
            $lebar
        );
        if ((float) $s->balance > 0 && ! $s->dibatalkan()) {
            $baris[] = $this->duaKolom('Sisa piutang', Format::rupiah($s->balance, false), $lebar);
        }

        if ($s->dibatalkan()) {
            $baris[] = str_repeat('-', $lebar);
            $baris[] = $this->tengah('** STRUK DIBATALKAN **', $lebar);
            if ($s->void_reason) {
                $baris[] = $this->tengah((string) $s->void_reason, $lebar);
            }
        }

        if ($s->note) {
            $baris[] = str_repeat('-', $lebar);
            foreach ($this->bungkus('Catatan: ' . $s->note, $lebar) as $b) {
                $baris[] = $b;
            }
        }

        // ---------- Kaki ----------
        $baris[] = str_repeat('-', $lebar);
        foreach (array_filter(array_map('trim', explode("\n", (string) ($this->toko['footer_struk'] ?? '')))) as $b) {
            $baris[] = $this->tengah($b, $lebar);
        }

        $sosmed = [];
        if (! empty($this->toko['instagram'])) {
            $sosmed[] = 'IG @' . $this->toko['instagram'];
        }
        if (! empty($this->toko['tiktok'])) {
            $sosmed[] = 'TikTok @' . $this->toko['tiktok'];
        }
        if ($sosmed) {
            // Usahakan satu baris; kalau tidak muat, tiap akun turun ke barisnya sendiri
            // supaya nama akun tidak terpotong di kertas 58 mm.
            $gabung = implode(' | ', $sosmed);
            foreach (mb_strlen($gabung) <= $lebar ? [$gabung] : $sosmed as $b) {
                $baris[] = $this->tengah($b, $lebar);
            }
        }

        $baris[] = $this->tengah(Format::tanggal(now(), true), $lebar);

        // Baris kosong di akhir: memberi jarak sebelum printer memotong kertas.
        $baris[] = '';
        $baris[] = '';

        return implode("\n", $baris);
    }

    // ---------------- Pembantu tata letak ----------------

    /** Teks di tengah selebar kertas. */
    private function tengah(string $teks, int $lebar): string
    {
        $teks = $this->potong($teks, $lebar);
        $sisa = max(0, $lebar - mb_strlen($teks));

        return str_repeat(' ', intdiv($sisa, 2)) . $teks;
    }

    /** Kiri + kanan dalam satu baris; teks kanan selalu rata kanan. */
    private function duaKolom(string $kiri, string $kanan, int $lebar): string
    {
        $kanan = $this->potong($kanan, $lebar);
        $sisaKiri = $lebar - mb_strlen($kanan) - 1;

        if ($sisaKiri < 1) {
            // Tidak muat sebaris: turunkan nilai ke baris berikutnya, tetap rata kanan.
            return $this->potong($kiri, $lebar) . "\n" . $this->rataKanan($kanan, $lebar);
        }

        return str_pad($this->potong($kiri, $sisaKiri), $sisaKiri) . ' ' . $kanan;
    }

    private function rataKanan(string $teks, int $lebar): string
    {
        $teks = $this->potong($teks, $lebar);

        return str_repeat(' ', max(0, $lebar - mb_strlen($teks))) . $teks;
    }

    /** Pecah teks panjang menjadi beberapa baris selebar kertas. */
    private function bungkus(string $teks, int $lebar): array
    {
        return explode("\n", wordwrap($teks, $lebar, "\n", true));
    }

    /** Potong teks yang melebihi lebar kertas supaya tata letak tidak rusak. */
    private function potong(string $teks, int $lebar): string
    {
        return mb_strlen($teks) <= $lebar ? $teks : mb_substr($teks, 0, $lebar);
    }
}

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Struk {{ $penjualan->number }}</title>
    <style>
        /* Lebar kertas roll: 58 mm (printer mini) atau 80 mm. margin 0 supaya tidak ada
           ruang terbuang; printer thermal tidak punya area margin yang bisa dipakai. */
        @page {
            size: {{ $lebarMm }}mm auto;
            margin: 0;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            padding: 4mm 2mm;
            background: #fff;
            color: #000;
            font-family: "Courier New", ui-monospace, monospace;
            font-size: 11px;
            line-height: 1.35;
        }

        .struk {
            width: {{ $lebarMm }}mm;
            margin: 0 auto;
        }

        .tengah { text-align: center; }
        .kanan { text-align: right; }
        .tebal { font-weight: 700; }
        .kecil { font-size: 10px; }

        .nama-toko { font-size: 14px; font-weight: 700; letter-spacing: 0.5px; }

        hr {
            border: 0;
            border-top: 1px dashed #000;
            margin: 5px 0;
        }

        table { width: 100%; border-collapse: collapse; }
        td { vertical-align: top; padding: 0; }

        .baris-nama { word-break: break-word; }

        .total-baris td { padding-top: 2px; }

        /* Pratinjau di layar: beri bingkai supaya terlihat seperti kertas struk. */
        @media screen {
            body { background: #e7e5e4; }
            .struk {
                background: #fff;
                padding: 4mm 2mm;
                box-shadow: 0 1px 4px rgba(0, 0, 0, 0.25);
            }
            .aksi { text-align: center; margin: 12px 0; }
            .aksi button {
                font-family: system-ui, sans-serif;
                font-size: 14px;
                padding: 8px 16px;
                border: 1px solid #a8a29e;
                border-radius: 6px;
                background: #fff;
                cursor: pointer;
            }
            .aksi button:hover { background: #f5f5f4; }
            .aksi button + button { margin-left: 8px; }
            /* Tombol utama: aksi yang paling sering dipakai di HP. */
            #tombol-bluetooth {
                background: #b91c1c;
                border-color: #b91c1c;
                color: #fff;
                font-weight: 600;
            }
            #tombol-bluetooth:hover { background: #991b1b; }
            .aksi .catatan-bluetooth {
                margin-top: 8px;
                font-family: system-ui, sans-serif;
                font-size: 12px;
                color: #57534e;
            }
        }

        @media print {
            body { background: #fff; padding: 0; }
            .struk { box-shadow: none; padding: 0 2mm; }
            .aksi { display: none; }
        }
    </style>
</head>
<body>
    <div class="aksi">
        <button type="button" onclick="window.print()">Cetak (printer biasa)</button>

        {{-- Cetak ke printer Bluetooth yang terhubung ke HP.
             Browser tidak bisa bicara langsung ke printer thermal (Bluetooth Classic),
             jadi teks struk dikirim ke aplikasi jembatan RawBT lewat Android Intent. --}}
        <button type="button" id="tombol-bluetooth" onclick="cetakBluetooth()" hidden>
            Cetak via Bluetooth
        </button>
    </div>

    <div class="struk">
        <div class="tengah">
            <div class="nama-toko">{{ $toko['nama_toko'] }}</div>
            @if ($toko['nama_pemilik'])
                <div class="kecil">{{ $toko['nama_pemilik'] }}</div>
            @endif
            @if ($toko['alamat'])
                <div class="kecil">{{ $toko['alamat'] }}</div>
            @endif
            @if ($toko['telepon'])
                <div class="kecil">Telp {{ $toko['telepon'] }}</div>
            @endif
        </div>

        <hr>

        <table>
            <tr><td>No</td><td class="kanan">{{ $penjualan->number }}</td></tr>
            <tr><td>Tanggal</td><td class="kanan">@tanggal($penjualan->sold_at, true)</td></tr>
            <tr><td>Kasir</td><td class="kanan">{{ $penjualan->user?->name }}</td></tr>
            <tr><td>Pelanggan</td><td class="kanan">{{ $penjualan->customer?->name }}</td></tr>
        </table>

        <hr>

        @foreach ($penjualan->items as $i)
            <div class="baris-nama">{{ $i->product_name }}</div>
            <table class="total-baris">
                <tr>
                    <td>
                        {{ \App\Support\Format::jumlah($i->quantity) }} {{ $i->unit_name }}
                        &times; {{ \App\Support\Format::rupiah($i->unit_price, false) }}
                        @if ((float) $i->discount > 0)
                            <span class="kecil">(diskon {{ \App\Support\Format::rupiah($i->discount, false) }})</span>
                        @endif
                    </td>
                    <td class="kanan">{{ \App\Support\Format::rupiah($i->line_total, false) }}</td>
                </tr>
            </table>
        @endforeach

        <hr>

        <table>
            <tr><td>Subtotal</td><td class="kanan">{{ \App\Support\Format::rupiah($penjualan->subtotal, false) }}</td></tr>
            @if ((float) $penjualan->item_discount > 0)
                <tr><td>Diskon item</td><td class="kanan">-{{ \App\Support\Format::rupiah($penjualan->item_discount, false) }}</td></tr>
            @endif
            @if ((float) $penjualan->discount > 0)
                <tr><td>Diskon</td><td class="kanan">-{{ \App\Support\Format::rupiah($penjualan->discount, false) }}</td></tr>
            @endif
            <tr class="tebal">
                <td>TOTAL</td>
                <td class="kanan">{{ \App\Support\Format::rupiah($penjualan->grand_total, false) }}</td>
            </tr>
        </table>

        <hr>

        <table>
            <tr><td>Metode</td><td class="kanan">{{ $penjualan->payment_method->label() }}</td></tr>

            @if ($penjualan->payment_method->value === 'cash')
                <tr><td>Bayar</td><td class="kanan">{{ \App\Support\Format::rupiah($penjualan->tendered_amount, false) }}</td></tr>
                <tr><td>Kembali</td><td class="kanan">{{ \App\Support\Format::rupiah($penjualan->change_amount, false) }}</td></tr>
            @endif

            @if ($penjualan->dibatalkan())
                <tr><td>Status</td><td class="kanan tebal">DIBATALKAN</td></tr>
            @else
                <tr><td>Status</td><td class="kanan">{{ $penjualan->payment_status->labelPenjualan() }}</td></tr>
            @endif

            @if ((float) $penjualan->balance > 0 && ! $penjualan->dibatalkan())
                <tr class="tebal"><td>Sisa piutang</td><td class="kanan">{{ \App\Support\Format::rupiah($penjualan->balance, false) }}</td></tr>
            @endif
        </table>

        @if ($penjualan->dibatalkan())
            <hr>
            <div class="tengah tebal">** STRUK DIBATALKAN **</div>
            <div class="kecil tengah">
                {{ $penjualan->void_reason }}
            </div>
        @endif

        @if ($penjualan->note)
            <hr>
            <div class="kecil">Catatan: {{ $penjualan->note }}</div>
        @endif

        <hr>

        <div class="tengah kecil">
            @foreach (array_filter(explode("\n", $footer)) as $baris)
                <div>{{ trim($baris) }}</div>
            @endforeach

            @if ($toko['instagram'] || $toko['tiktok'])
                <div style="margin-top: 2px;">
                    @if ($toko['instagram']) IG {{ '@'.$toko['instagram'] }}@endif
                    @if ($toko['instagram'] && $toko['tiktok']) &middot; @endif
                    @if ($toko['tiktok']) TikTok {{ '@'.$toko['tiktok'] }}@endif
                </div>
            @endif

            <div style="margin-top: 4px;">{{ \App\Support\Format::tanggal(now(), true) }}</div>
        </div>
    </div>

    @if ($cetakOtomatis)
        <script>
            window.addEventListener('load', () => window.print());
        </script>
    @endif

    <script>
        /*
         * Cetak ke printer thermal Bluetooth lewat aplikasi jembatan RawBT.
         *
         * Kenapa harus begini: printer struk memakai Bluetooth Classic (SPP), dan
         * browser tidak punya akses ke profil itu. RawBT adalah aplikasi Android
         * yang menerima teks dari halaman web lalu meneruskannya ke printer yang
         * sudah dipasangkan (paired) di pengaturan Bluetooth HP.
         *
         * Alurnya: ambil teks struk dari server -> bungkus jadi Android Intent
         * dengan skema "rawbt" -> Android membuka RawBT -> RawBT mencetak.
         */
        const URL_TEKS = @json(route('sales.receipt.text', $penjualan), JSON_UNESCAPED_SLASHES);

        // Skema Intent RawBT. Kalau memakai aplikasi jembatan lain, ganti bagian ini.
        const SKEMA_RAWBT = 'rawbt:';
        const PAKET_RAWBT = 'ru.a402d.rawbtprinter';

        function diAndroid() {
            return /Android/i.test(navigator.userAgent);
        }

        // RawBT minta baris dengan CRLF, bukan LF saja.
        function keBarisPrinter(teks) {
            return teks.replace(/\r?\n/g, '\r\n');
        }

        async function cetakBluetooth() {
            const tombol = document.getElementById('tombol-bluetooth');
            const teksAsli = tombol.textContent;
            tombol.disabled = true;
            tombol.textContent = 'Mengirim…';

            try {
                const res = await fetch(URL_TEKS, {
                    headers: { 'Accept': 'application/json' },
                    credentials: 'same-origin',
                });
                if (!res.ok) throw new Error('gagal mengambil teks struk');

                const data = await res.json();
                const isi = encodeURIComponent(keBarisPrinter(data.teks));

                // Intent URI: Android menyerahkan ini ke RawBT, yang lalu mencetak.
                window.location.href =
                    'intent:' + isi +
                    '#Intent;scheme=' + SKEMA_RAWBT.replace(':', '') +
                    ';package=' + PAKET_RAWBT +
                    ';end;';
            } catch (e) {
                alert('Gagal mengirim struk ke printer. Pastikan aplikasi RawBT terpasang dan printer sudah dipasangkan di Bluetooth HP.');
            } finally {
                tombol.disabled = false;
                tombol.textContent = teksAsli;
            }
        }

        // Tombol hanya muncul di HP/tablet Android, tempat RawBT bisa dipanggil.
        // Di komputer, tombol "Cetak (printer biasa)" tetap jadi jalur utamanya.
        window.addEventListener('DOMContentLoaded', () => {
            if (diAndroid()) {
                document.getElementById('tombol-bluetooth').hidden = false;
                const pesan = document.createElement('p');
                pesan.className = 'catatan-bluetooth';
                pesan.textContent = 'Butuh aplikasi RawBT dan printer yang sudah dipasangkan di Bluetooth HP.';
                document.querySelector('.aksi').appendChild(pesan);
            }
        });
    </script>
</body>
</html>

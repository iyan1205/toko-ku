{{-- Halaman cetak label (berdiri sendiri, tanpa layout aplikasi). Ukuran dalam mm sesuai config/labels.php. --}}
@use('App\Support\Code128')
@use('App\Support\Format')
@php
    $w = $size['width'];
    $h = $size['height'];
    $kecil = $h < 20;
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Cetak label</title>
    <style>
        @page { size: {{ $layout === 'roll' ? $w.'mm '.$h.'mm' : 'A4' }}; margin: {{ $layout === 'roll' ? '0' : '8mm' }}; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: Arial, Helvetica, sans-serif; color: #000; }
        .lembar { display: flex; flex-wrap: wrap; gap: {{ $layout === 'a4' ? '2mm' : '0' }}; }
        .label { width: {{ $w }}mm; height: {{ $h }}mm; padding: 1mm 1.5mm; overflow: hidden; display: flex; flex-direction: column; justify-content: space-between;
                 {{ $layout === 'roll' ? 'page-break-after: always;' : 'border: 0.1mm dashed #bbb;' }} }
        .nama { font-size: {{ $kecil ? '6.5pt' : '8pt' }}; line-height: 1.1; max-height: 2.2em; overflow: hidden; }
        .toko { font-size: 6pt; }
        .harga { font-size: {{ $kecil ? '8pt' : '11pt' }}; font-weight: bold; }
        .kode { flex: 1; min-height: 4mm; display: flex; flex-direction: column; justify-content: flex-end; }
        .kode svg { width: 100%; height: {{ $kecil ? '6mm' : max(8, $h * 0.35) }}mm; display: block; }
        .teks { font-size: 6pt; text-align: center; letter-spacing: 0.3pt; }
        .baris { display: flex; justify-content: space-between; align-items: baseline; gap: 1mm; }
        .alat { padding: 8px; background: #f1f5f9; font-size: 13px; }
        @media print { .alat { display: none; } }
    </style>
</head>
<body>
    <div class="alat">{{ $labels->count() }} label · {{ $w }} × {{ $h }} mm. <button onclick="window.print()">Cetak</button> (atur skala 100% dan tanpa margin di dialog cetak)</div>
    <div class="lembar">
        @foreach ($labels as $p)
            @php
                $kode = $p->barcode ?: $p->sku;
            @endphp
            <div class="label">
                @if (in_array('store', $show, true))
                    <div class="toko">{{ $store }}</div>
                @endif
                @if (in_array('name', $show, true))
                    <div class="nama">{{ $p->name }}</div>
                @endif
                <div class="kode">
                    {!! Code128::svg($kode) !!}
                    <div class="teks">{{ $kode }}</div>
                </div>
                @if (in_array('price', $show, true) || in_array('sku', $show, true))
                    <div class="baris">
                        <span class="teks">{{ in_array('sku', $show, true) && $p->barcode ? $p->sku : '' }}</span>
                        @if (in_array('price', $show, true))
                            <span class="harga">{{ Format::rupiah($p->hargaSetelahDiskon()) }}</span>
                        @endif
                    </div>
                @endif
            </div>
        @endforeach
    </div>
</body>
</html>

@php
    // Stok masuk = hijau, stok keluar = biru, penyesuaian = netral, rusak/hilang = merah.
    $warna = match ($jenis->value) {
        'purchase', 'sale_return' => 'success',
        'sale', 'purchase_return' => 'info',
        'adjustment' => 'neutral',
        default => 'danger',
    };
@endphp
<x-ui.badge :color="$warna">{{ $jenis->label() }}</x-ui.badge>

@php
    // Lunas = hijau. Sebagian = kuning (perlu ditagih). Belum dibayar (piutang/hutang) = merah.
    $warna = match ($status->value) {
        'paid' => 'success',
        'partial' => 'warning',
        default => 'danger',
    };
@endphp
<x-ui.badge :color="$warna">{{ ($penjualan ?? false) ? $status->labelPenjualan() : $status->label() }}</x-ui.badge>

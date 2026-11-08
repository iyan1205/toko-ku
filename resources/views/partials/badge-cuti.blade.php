@php
    // Menunggu = kuning (perlu tindakan). Disetujui = hijau. Ditolak = merah. Dibatalkan = netral.
    $warna = match ($status) {
        \App\Enums\StatusCuti::Menunggu => 'warning',
        \App\Enums\StatusCuti::Disetujui => 'success',
        \App\Enums\StatusCuti::Ditolak => 'danger',
        \App\Enums\StatusCuti::Dibatalkan => 'neutral',
    };
@endphp
<x-ui.badge :color="$warna">{{ $status->label() }}</x-ui.badge>

@php
    // Aman = hijau, menipis = kuning (perlu perhatian), habis = merah.
    $warna = match ($status->value) {
        'AMAN' => 'success',
        'MENIPIS' => 'warning',
        default => 'danger',
    };
@endphp
<x-ui.badge :color="$warna">{{ $status->value }}</x-ui.badge>

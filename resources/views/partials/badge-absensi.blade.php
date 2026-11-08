@php
    // Hadir = hijau. Terlambat = kuning. Izin/sakit = biru/ungu (tidak hadir tapi beralasan). Alpa = merah. Cuti = netral.
    $warna = match ($status) {
        \App\Enums\StatusAbsensi::Hadir => 'success',
        \App\Enums\StatusAbsensi::Terlambat => 'warning',
        \App\Enums\StatusAbsensi::Izin => 'info',
        \App\Enums\StatusAbsensi::Sakit => 'primary',
        \App\Enums\StatusAbsensi::Alpa => 'danger',
        \App\Enums\StatusAbsensi::Cuti => 'neutral',
    };
@endphp
<x-ui.badge :color="$warna">{{ $status->label() }}</x-ui.badge>

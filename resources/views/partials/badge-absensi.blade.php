@php
    // Hadir = hijau. Terlambat = kuning. Izin/sakit = biru/ungu (tidak hadir tapi beralasan). Alpa = merah muda.
    $warna = match ($status) {
        \App\Enums\StatusAbsensi::Hadir => 'bg-emerald-100 text-emerald-800',
        \App\Enums\StatusAbsensi::Terlambat => 'bg-amber-100 text-amber-800',
        \App\Enums\StatusAbsensi::Izin => 'bg-sky-100 text-sky-800',
        \App\Enums\StatusAbsensi::Sakit => 'bg-violet-100 text-violet-800',
        \App\Enums\StatusAbsensi::Alpa => 'bg-rose-100 text-rose-800',
        \App\Enums\StatusAbsensi::Cuti => 'bg-teal-100 text-teal-800',
    };
@endphp
<span class="badge {{ $warna }}">{{ $status->label() }}</span>

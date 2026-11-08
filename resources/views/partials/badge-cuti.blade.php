@php
    // Menunggu = kuning (perlu tindakan). Disetujui = hijau. Ditolak = merah muda. Dibatalkan = netral.
    $warna = match ($status) {
        \App\Enums\StatusCuti::Menunggu => 'bg-amber-100 text-amber-800',
        \App\Enums\StatusCuti::Disetujui => 'bg-emerald-100 text-emerald-800',
        \App\Enums\StatusCuti::Ditolak => 'bg-rose-100 text-rose-800',
        \App\Enums\StatusCuti::Dibatalkan => 'bg-slate-100 text-slate-700',
    };
@endphp
<span class="badge {{ $warna }}">{{ $status->label() }}</span>

@php
    // Netral untuk aman, kuning untuk menipis (perlu perhatian), merah untuk habis.
    $warna = match ($status->value) {
        'AMAN' => 'bg-stone-100 text-stone-700',
        'MENIPIS' => 'bg-accent-100 text-accent-800',
        default => 'bg-brand-100 text-brand-800',
    };
@endphp
<span class="badge {{ $warna }}">{{ $status->value }}</span>

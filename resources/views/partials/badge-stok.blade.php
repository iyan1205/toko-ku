@php
    $warna = match ($status->value) {
        'AMAN' => 'bg-teal-50 text-teal-800',
        'MENIPIS' => 'bg-amber-50 text-amber-800',
        default => 'bg-red-50 text-red-800',
    };
@endphp
<span class="rounded-full px-2.5 py-0.5 text-xs font-medium {{ $warna }}">{{ $status->value }}</span>

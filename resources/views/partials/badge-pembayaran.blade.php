@php
    $warna = match ($status->value) {
        'paid' => 'bg-teal-50 text-teal-800',
        'partial' => 'bg-amber-50 text-amber-800',
        default => 'bg-red-50 text-red-800',
    };
@endphp
<span class="whitespace-nowrap rounded-full px-2.5 py-0.5 text-xs font-medium {{ $warna }}">{{ $status->label() }}</span>

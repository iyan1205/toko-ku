@php
    $warna = match ($jenis->value) {
        'purchase', 'sale_return' => 'bg-teal-50 text-teal-800',
        'sale', 'purchase_return' => 'bg-sky-50 text-sky-800',
        'adjustment' => 'bg-stone-100 text-stone-700',
        default => 'bg-red-50 text-red-800',
    };
@endphp
<span class="whitespace-nowrap rounded-full px-2.5 py-0.5 text-xs font-medium {{ $warna }}">{{ $jenis->label() }}</span>

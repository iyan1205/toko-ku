@php
    // Lunas = netral tenang. Sebagian = kuning (perlu ditagih). Piutang/hutang = merah (belum ada uang masuk).
    $warna = match ($status->value) {
        'paid' => 'bg-stone-100 text-stone-700',
        'partial' => 'bg-accent-100 text-accent-800',
        default => 'bg-brand-100 text-brand-800',
    };
@endphp
<span class="badge {{ $warna }}">{{ ($penjualan ?? false) ? $status->labelPenjualan() : $status->label() }}</span>

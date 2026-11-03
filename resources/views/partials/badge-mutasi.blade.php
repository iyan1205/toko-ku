@php
    // Stok masuk (netral) vs stok keluar (biru) dibedakan dari warnanya,
    // bukan dari merah/kuning yang disimpan untuk status penting.
    $warna = match ($jenis->value) {
        'purchase', 'sale_return' => 'bg-emerald-50 text-emerald-800',
        'sale', 'purchase_return' => 'bg-sky-50 text-sky-800',
        'adjustment' => 'bg-stone-100 text-stone-700',
        default => 'bg-brand-50 text-brand-800',
    };
@endphp
<span class="badge {{ $warna }}">{{ $jenis->label() }}</span>

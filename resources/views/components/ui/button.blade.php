{{--
    Tombol. Menjadi <a> bila diberi href, selain itu <button>.
    variant: primary | secondary | danger | ghost | link     size: sm | md | lg

    <x-ui.button type="submit">Simpan</x-ui.button>
    <x-ui.button :href="route('products.create')" icon="plus">Tambah produk</x-ui.button>
    <x-ui.button variant="ghost" icon="trash" aria-label="Hapus" />   (tombol ikon saja: wajib aria-label)
--}}
@props([
    'variant' => 'primary',
    'size' => 'md',
    'href' => null,
    'type' => 'button',
    'icon' => null,
    'iconRight' => null,
])

@php
    $tag = $href ? 'a' : 'button';
    $iconOnly = $slot->isEmpty() && ($icon || $iconRight);

    $variantClass = match ($variant) {
        'secondary' => 'border border-slate-300 bg-white text-slate-700 shadow-sm hover:bg-slate-50 hover:text-slate-900 focus-visible:ring-primary-500',
        'danger' => 'bg-danger-600 text-white shadow-sm hover:bg-danger-700 focus-visible:ring-danger-500',
        'ghost' => 'text-slate-600 hover:bg-slate-100 hover:text-slate-900 focus-visible:ring-primary-500',
        'link' => 'text-primary-600 hover:text-primary-700 hover:underline focus-visible:ring-primary-500',
        default => 'bg-primary-600 text-white shadow-sm hover:bg-primary-700 focus-visible:ring-primary-500',
    };

    $sizeClass = match (true) {
        $variant === 'link' => 'text-sm',
        $iconOnly => ['sm' => 'p-1.5', 'md' => 'p-2', 'lg' => 'p-2.5'][$size],
        default => ['sm' => 'px-3 py-1.5 text-xs', 'md' => 'px-4 py-2 text-sm', 'lg' => 'px-5 py-2.5 text-base'][$size],
    };

    $iconClass = $size === 'lg' ? 'h-5 w-5' : 'h-4 w-4';
@endphp

<{{ $tag }}
    @if ($href) href="{{ $href }}" @else type="{{ $type }}" @endif
    {{ $attributes->class([
        'inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-lg font-medium transition-colors',
        'focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-2',
        'disabled:pointer-events-none disabled:opacity-50',
        $variantClass,
        $sizeClass,
    ]) }}>
    @if ($icon)
        <x-ui.icon :name="$icon" variant="m" :class="$iconClass" />
    @endif
    {{ $slot }}
    @if ($iconRight)
        <x-ui.icon :name="$iconRight" variant="m" :class="$iconClass" />
    @endif
</{{ $tag }}>

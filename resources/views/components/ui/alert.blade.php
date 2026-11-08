{{--
    Kotak pesan di dalam halaman. type: info | success | warning | danger

    <x-ui.alert type="warning" title="Stok minus diizinkan">Penjualan tetap tersimpan walau stok habis.</x-ui.alert>
    <x-ui.alert type="success" dismissible>Data tersimpan.</x-ui.alert>
--}}
@props(['type' => 'info', 'title' => null, 'dismissible' => false])

@php
    [$boxClass, $iconClass, $icon] = match ($type) {
        'success' => ['border-success-200 bg-success-50 text-success-800', 'text-success-500', 'check-circle'],
        'warning' => ['border-warning-200 bg-warning-50 text-warning-800', 'text-warning-500', 'exclamation-triangle'],
        'danger' => ['border-danger-200 bg-danger-50 text-danger-800', 'text-danger-500', 'x-circle'],
        default => ['border-info-200 bg-info-50 text-info-800', 'text-info-500', 'information-circle'],
    };
@endphp

<div x-data="{ tampil: true }" x-show="tampil"
     role="{{ in_array($type, ['warning', 'danger'], true) ? 'alert' : 'status' }}"
     {{ $attributes->class(['flex gap-3 rounded-lg border p-4 text-sm', $boxClass]) }}>
    <x-ui.icon :name="$icon" variant="m" :class="'h-5 w-5 '.$iconClass" />
    <div class="min-w-0 flex-1">
        @if ($title)
            <p class="font-semibold">{{ $title }}</p>
        @endif
        <div @class(['mt-1' => $title])>{{ $slot }}</div>
    </div>
    @if ($dismissible)
        <button type="button" x-on:click="tampil = false" class="-m-1 shrink-0 rounded p-1 opacity-60 hover:opacity-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-current" aria-label="Tutup">
            <x-ui.icon name="x-mark" variant="m" class="h-4 w-4" />
        </button>
    @endif
</div>

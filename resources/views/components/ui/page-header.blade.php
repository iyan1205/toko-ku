{{--
    Judul halaman + deskripsi singkat + tombol aksi di kanan.

    <x-ui.page-header title="Produk" description="Kelola katalog, harga, dan stok minimum.">
        <x-slot:actions>
            <x-ui.button variant="secondary" icon="arrow-down-tray">Ekspor</x-ui.button>
            <x-ui.button :href="route('products.create')" icon="plus">Tambah produk</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>
--}}
@props(['title', 'description' => null])

<div {{ $attributes->class('flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between') }}>
    <div class="min-w-0">
        <h1 class="text-xl font-semibold tracking-tight text-slate-900 sm:text-2xl">{{ $title }}</h1>
        @if ($description)
            <p class="mt-1 text-sm text-slate-500">{{ $description }}</p>
        @endif
    </div>
    @isset($actions)
        <div class="flex shrink-0 flex-wrap items-center gap-2">{{ $actions }}</div>
    @endisset
</div>

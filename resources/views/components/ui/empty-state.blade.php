{{--
    Tampilan saat data kosong. Slot (opsional) untuk tombol aksi.

    <x-ui.empty-state icon="cube" title="Belum ada produk" description="Tambahkan produk pertama Anda.">
        <x-ui.button :href="route('products.create')" icon="plus">Tambah produk</x-ui.button>
    </x-ui.empty-state>
--}}
@props(['icon' => 'inbox', 'title' => 'Belum ada data', 'description' => null])

<div {{ $attributes->class('flex flex-col items-center justify-center px-6 py-12 text-center') }}>
    <span class="grid h-12 w-12 place-items-center rounded-full bg-slate-100 text-slate-400">
        <x-ui.icon :name="$icon" class="h-6 w-6" />
    </span>
    <h3 class="mt-4 text-sm font-semibold text-slate-900">{{ $title }}</h3>
    @if ($description)
        <p class="mt-1 max-w-sm text-sm text-slate-500">{{ $description }}</p>
    @endif
    @if ($slot->isNotEmpty())
        <div class="mt-5 flex flex-wrap justify-center gap-2">{{ $slot }}</div>
    @endif
</div>

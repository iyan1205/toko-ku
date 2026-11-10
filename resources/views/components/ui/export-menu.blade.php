{{--
    Tombol Ekspor (Excel/PDF) untuk tabel daftar. Filter dan urutan yang sedang aktif ikut
    terbawa, sehingga isi berkas sama dengan tabel di layar. Controller membaca ?format=xlsx|pdf
    (lihat App\Exports\ListExport).

    @can('inventory.products.export')
        <x-ui.export-menu :href="route('products.export')" />
    @endcan
--}}
@props(['href'])

@php
    $query = request()->except(['page', 'format']);
    $url = fn (string $format) => $href.'?'.http_build_query($query + ['format' => $format]);
@endphp

<x-ui.dropdown width="w-48" {{ $attributes }}>
    <x-slot:trigger>
        <x-ui.button variant="secondary" icon="arrow-down-tray" icon-right="chevron-down" x-bind:aria-expanded="open">Ekspor</x-ui.button>
    </x-slot:trigger>
    <x-ui.dropdown-item :href="$url('xlsx')" icon="table-cells">Excel (.xlsx)</x-ui.dropdown-item>
    <x-ui.dropdown-item :href="$url('pdf')" icon="document-text">PDF</x-ui.dropdown-item>
</x-ui.dropdown>

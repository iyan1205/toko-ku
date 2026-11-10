{{-- Daftar produk: cari/scan, filter kategori/stok/status, urutkan, ekspor & impor Excel. --}}
@use('App\Support\Format')
@php
    $kelola = auth()->user()->can('inventory.products.update');
@endphp

<x-app-layout>
    <x-slot name="header">Produk</x-slot>
    <x-slot name="description">Katalog produk, harga, dan stok minimum. Stok diubah lewat pembelian, transfer, atau opname.</x-slot>
    <x-slot name="actions">
        @can('inventory.products.import')
            <x-ui.button variant="secondary" :href="route('products.import')" icon="arrow-up-tray">Impor</x-ui.button>
        @endcan
        @can('inventory.products.export')
            <x-ui.export-menu :href="route('products.export')" />
        @endcan
        @can('inventory.products.create')
            <x-ui.button :href="route('products.create')" icon="plus">Tambah produk</x-ui.button>
        @endcan
    </x-slot>

    <x-ui.table :paginator="$produk">
        <x-slot:toolbar>
            <x-ui.filter-bar placeholder="Cari nama, SKU, atau scan barcode">
                <x-ui.select name="kategori" label="Kategori" hide-label :options="$kategori->pluck('name', 'id')" :selected="request('kategori')" placeholder="Semua kategori" />
                <x-ui.select name="stok" label="Stok" hide-label :options="['aman' => 'Aman', 'menipis' => 'Menipis', 'habis' => 'Habis']" :selected="request('stok')" placeholder="Semua stok" />
                <x-ui.select name="status" label="Status" hide-label :options="['aktif' => 'Aktif', 'nonaktif' => 'Nonaktif']" :selected="request('status')" placeholder="Semua status" />
            </x-ui.filter-bar>
        </x-slot:toolbar>

        <x-slot:head>
            <x-ui.table.th sort="name">Produk</x-ui.table.th>
            <x-ui.table.th sort="category">Kategori</x-ui.table.th>
            @if ($kelola)
                <x-ui.table.th align="right" sort="purchase_price">Harga beli</x-ui.table.th>
            @endif
            <x-ui.table.th align="right" sort="selling_price">Harga jual</x-ui.table.th>
            <x-ui.table.th align="right" sort="stock">Stok</x-ui.table.th>
            <x-ui.table.th>Status</x-ui.table.th>
            <x-ui.table.th align="right"><span class="sr-only">Aksi</span></x-ui.table.th>
        </x-slot:head>

        @forelse ($produk as $p)
            <tr @class(['text-slate-500' => ! $p->is_active])>
                <x-ui.table.td>
                    <div class="flex items-center gap-3">
                        @if ($p->photoUrl())
                            <img src="{{ $p->photoUrl() }}" alt="" class="h-10 w-10 shrink-0 rounded-lg object-cover" loading="lazy">
                        @else
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-slate-400" aria-hidden="true">
                                <x-ui.icon name="cube" class="h-5 w-5" />
                            </span>
                        @endif
                        <div class="min-w-0">
                            <a href="{{ route('products.show', $p) }}" class="font-medium text-slate-900 hover:text-primary-700 hover:underline">{{ $p->name }}</a>
                            <p class="text-xs text-slate-500">{{ $p->sku }}@if ($p->barcode) · {{ $p->barcode }}@endif</p>
                        </div>
                    </div>
                </x-ui.table.td>
                <x-ui.table.td muted>{{ $p->category?->name }}</x-ui.table.td>
                @if ($kelola)
                    <x-ui.table.td align="right" numeric muted>@rupiah($p->purchase_price)</x-ui.table.td>
                @endif
                <x-ui.table.td align="right" numeric>@rupiah($p->selling_price)</x-ui.table.td>
                <x-ui.table.td align="right" numeric>
                    <span class="font-medium text-slate-900">{{ Format::jumlah($p->stock) }}</span>
                    <span class="text-slate-500">{{ $p->unit?->name }}</span>
                    <div class="mt-1">@include('partials.badge-stok', ['status' => $p->statusStok()])</div>
                </x-ui.table.td>
                <x-ui.table.td>
                    <x-ui.badge :color="$p->is_active ? 'success' : 'neutral'" dot>{{ $p->is_active ? 'Aktif' : 'Nonaktif' }}</x-ui.badge>
                </x-ui.table.td>
                <x-ui.table.td align="right">
                    <div class="flex items-center justify-end gap-1">
                        @can('update', $p)
                            <x-ui.button variant="ghost" size="sm" icon="pencil-square" :href="route('products.edit', $p)" aria-label="Ubah {{ $p->name }}" />
                        @endcan
                        @can('delete', $p)
                            <x-ui.button variant="ghost" size="sm" icon="trash" aria-label="Hapus {{ $p->name }}" class="text-danger-600 hover:text-danger-700"
                                x-data x-on:click="$dispatch('konfirmasi-hapus', { action: {{ \Illuminate\Support\Js::from(route('products.destroy', $p)) }}, nama: {{ \Illuminate\Support\Js::from($p->name) }} })" />
                        @endcan
                    </div>
                </x-ui.table.td>
            </tr>
        @empty
            <x-ui.table.empty :colspan="$kelola ? 7 : 6" icon="cube" title="Tidak ada produk yang cocok" description="Ubah kata kunci atau filter pencarian." />
        @endforelse
    </x-ui.table>
</x-app-layout>

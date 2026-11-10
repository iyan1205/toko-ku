{{-- Daftar gudang/lokasi stok: cari, filter cabang/status, urutkan, ekspor. --}}
<x-app-layout>
    <x-slot name="header">Gudang/Lokasi</x-slot>
    <x-slot name="description">Tempat stok disimpan. Kasir dan transaksi tanpa pilihan gudang memakai gudang utama.</x-slot>
    <x-slot name="actions">
        @can('inventory.warehouses.export')
            <x-ui.export-menu :href="route('warehouses.export')" />
        @endcan
        @can('inventory.warehouses.create')
            <x-ui.button :href="route('warehouses.create')" icon="plus">Tambah gudang</x-ui.button>
        @endcan
    </x-slot>

    <x-ui.table :paginator="$warehouses">
        <x-slot:toolbar>
            <x-ui.filter-bar placeholder="Cari kode, nama, atau alamat">
                <x-ui.select name="cabang" label="Cabang" hide-label :options="$branches->pluck('name', 'id')" :selected="request('cabang')" placeholder="Semua cabang" />
                <x-ui.select name="status" label="Status" hide-label :options="['aktif' => 'Aktif', 'nonaktif' => 'Nonaktif']" :selected="request('status')" placeholder="Semua status" />
            </x-ui.filter-bar>
        </x-slot:toolbar>

        <x-slot:head>
            <x-ui.table.th sort="name">Gudang</x-ui.table.th>
            <x-ui.table.th>Cabang</x-ui.table.th>
            <x-ui.table.th>Alamat</x-ui.table.th>
            <x-ui.table.th align="right" sort="stock">Stok</x-ui.table.th>
            <x-ui.table.th>Status</x-ui.table.th>
            <x-ui.table.th align="right"><span class="sr-only">Aksi</span></x-ui.table.th>
        </x-slot:head>

        @forelse ($warehouses as $warehouse)
            <tr>
                <x-ui.table.td>
                    <p class="flex flex-wrap items-center gap-2 font-medium text-slate-900">
                        {{ $warehouse->name }}
                        @if ($warehouse->is_default)
                            <x-ui.badge color="primary">Utama</x-ui.badge>
                        @endif
                    </p>
                    <p class="mt-0.5 font-mono text-xs text-slate-500">{{ $warehouse->code }}</p>
                </x-ui.table.td>
                <x-ui.table.td muted>{{ $warehouse->branch?->name ?? '—' }}</x-ui.table.td>
                <x-ui.table.td><p class="max-w-xs truncate text-slate-700">{{ $warehouse->address ?: '—' }}</p></x-ui.table.td>
                <x-ui.table.td align="right" numeric>
                    @can('inventory.stock.view')
                        <a href="{{ route('stock.index', ['gudang' => $warehouse->id]) }}" class="font-medium text-slate-900 hover:text-primary-700 hover:underline">{{ \App\Support\Format::jumlah($warehouse->total_stock ?? 0) }}</a>
                    @else
                        {{ \App\Support\Format::jumlah($warehouse->total_stock ?? 0) }}
                    @endcan
                    <p class="text-xs text-slate-500">{{ \App\Support\Format::jumlah($warehouse->product_count) }} produk</p>
                </x-ui.table.td>
                <x-ui.table.td>
                    <x-ui.badge :color="$warehouse->is_active ? 'success' : 'neutral'" dot>{{ $warehouse->is_active ? 'Aktif' : 'Nonaktif' }}</x-ui.badge>
                </x-ui.table.td>
                <x-ui.table.td align="right">
                    <div class="flex items-center justify-end gap-1">
                        @can('inventory.warehouses.update')
                            <x-ui.button variant="ghost" size="sm" icon="pencil-square" :href="route('warehouses.edit', $warehouse)" aria-label="Ubah {{ $warehouse->name }}" />
                        @endcan
                        @if (! $warehouse->is_default && auth()->user()->can('inventory.warehouses.delete'))
                            <x-ui.button variant="ghost" size="sm" icon="trash" aria-label="Hapus {{ $warehouse->name }}" class="text-danger-600 hover:text-danger-700"
                                x-data x-on:click="$dispatch('konfirmasi-hapus', { action: {{ \Illuminate\Support\Js::from(route('warehouses.destroy', $warehouse)) }}, nama: {{ \Illuminate\Support\Js::from('Gudang '.$warehouse->name) }}, pesan: 'akan dihapus. Gudang yang masih berisi stok tidak bisa dihapus.' })" />
                        @endif
                    </div>
                </x-ui.table.td>
            </tr>
        @empty
            <x-ui.table.empty colspan="6" icon="building-storefront" title="Tidak ada gudang yang cocok" description="Ubah kata kunci atau filter pencarian." />
        @endforelse
    </x-ui.table>
</x-app-layout>

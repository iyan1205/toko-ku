{{-- Daftar transfer stok antar gudang. --}}
@use('App\Support\Format')

<x-app-layout>
    <x-slot name="header">Transfer Stok</x-slot>
    <x-slot name="description">Pindahkan stok antar gudang. Transfer langsung selesai saat disimpan.</x-slot>
    <x-slot name="actions">
        @can('inventory.transfers.export')
            <x-ui.export-menu :href="route('stock-transfers.export')" />
        @endcan
        @can('inventory.transfers.create')
            <x-ui.button :href="route('stock-transfers.create')" icon="plus">Transfer baru</x-ui.button>
        @endcan
    </x-slot>

    <x-ui.table :paginator="$transfers">
        <x-slot:toolbar>
            <x-ui.filter-bar placeholder="Cari nomor, produk, atau catatan">
                <x-ui.select name="gudang" label="Gudang" hide-label :options="$gudang->pluck('name', 'id')" :selected="request('gudang')" placeholder="Semua gudang" />
                <x-ui.select name="status" label="Status" hide-label :options="['completed' => 'Selesai', 'voided' => 'Dibatalkan']" :selected="request('status')" placeholder="Semua status" />
                <x-ui.input name="dari" label="Dari tanggal" hide-label type="date" :value="request('dari')" />
                <x-ui.input name="sampai" label="Sampai tanggal" hide-label type="date" :value="request('sampai')" />
            </x-ui.filter-bar>
        </x-slot:toolbar>

        <x-slot:head>
            <x-ui.table.th sort="number">Nomor</x-ui.table.th>
            <x-ui.table.th sort="date">Tanggal</x-ui.table.th>
            <x-ui.table.th>Dari → Ke</x-ui.table.th>
            <x-ui.table.th align="right" sort="quantity">Barang</x-ui.table.th>
            <x-ui.table.th>Status</x-ui.table.th>
            <x-ui.table.th>Oleh</x-ui.table.th>
        </x-slot:head>

        @forelse ($transfers as $t)
            <tr>
                <x-ui.table.td>
                    <a href="{{ route('stock-transfers.show', $t) }}" class="font-medium text-slate-900 hover:text-primary-700 hover:underline">{{ $t->number }}</a>
                    @if ($t->note)
                        <p class="max-w-xs truncate text-xs text-slate-500">{{ $t->note }}</p>
                    @endif
                </x-ui.table.td>
                <x-ui.table.td numeric muted>{{ Format::tanggal($t->transfer_date) }}</x-ui.table.td>
                <x-ui.table.td>
                    <span class="text-slate-700">{{ $t->fromWarehouse?->name }}</span>
                    <x-ui.icon name="arrow-right" variant="m" class="mx-1 inline h-4 w-4 text-slate-400" />
                    <span class="text-slate-700">{{ $t->toWarehouse?->name }}</span>
                </x-ui.table.td>
                <x-ui.table.td align="right" numeric>
                    {{ Format::jumlah($t->total_quantity) }}
                    <p class="text-xs text-slate-500">{{ $t->items_count }} produk</p>
                </x-ui.table.td>
                <x-ui.table.td><x-ui.badge :color="$t->status->color()" dot>{{ $t->status->label() }}</x-ui.badge></x-ui.table.td>
                <x-ui.table.td muted>{{ $t->user?->name }}</x-ui.table.td>
            </tr>
        @empty
            <x-ui.table.empty colspan="6" icon="arrows-right-left" title="Belum ada transfer yang cocok" description="Ubah filter, atau buat transfer baru." />
        @endforelse
    </x-ui.table>
</x-app-layout>

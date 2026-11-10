{{-- Daftar stok opname per gudang (draft dan selesai). --}}
@use('App\Support\Format')

<x-app-layout>
    <x-slot name="header">Stok Opname</x-slot>
    <x-slot name="description">Hitung stok fisik per gudang. Draft belum mengubah stok; selisih dibukukan saat opname diselesaikan.</x-slot>
    <x-slot name="actions">
        @can('inventory.opnames.export')
            <x-ui.export-menu :href="route('stock-opnames.export')" />
        @endcan
        @can('inventory.opnames.create')
            <x-ui.button :href="route('stock-opnames.create')" icon="plus">Opname baru</x-ui.button>
        @endcan
    </x-slot>

    <x-ui.table :paginator="$opname">
        <x-slot:toolbar>
            <x-ui.filter-bar placeholder="Cari nomor atau catatan">
                <x-ui.select name="gudang" label="Gudang" hide-label :options="$gudang->pluck('name', 'id')" :selected="request('gudang')" placeholder="Semua gudang" />
                <x-ui.select name="status" label="Status" hide-label :options="['draft' => 'Draft', 'final' => 'Selesai']" :selected="request('status')" placeholder="Semua status" />
                <x-ui.input name="dari" label="Dari tanggal" hide-label type="date" :value="request('dari')" />
                <x-ui.input name="sampai" label="Sampai tanggal" hide-label type="date" :value="request('sampai')" />
            </x-ui.filter-bar>
        </x-slot:toolbar>

        <x-slot:head>
            <x-ui.table.th sort="number">Nomor</x-ui.table.th>
            <x-ui.table.th sort="date">Tanggal</x-ui.table.th>
            <x-ui.table.th>Gudang</x-ui.table.th>
            <x-ui.table.th align="right">Dihitung</x-ui.table.th>
            <x-ui.table.th align="right" sort="difference">Berselisih</x-ui.table.th>
            <x-ui.table.th>Status</x-ui.table.th>
            <x-ui.table.th>Oleh</x-ui.table.th>
        </x-slot:head>

        @forelse ($opname as $o)
            <tr>
                <x-ui.table.td>
                    <a href="{{ route('stock-opnames.show', $o) }}" class="font-medium text-slate-900 hover:text-primary-700 hover:underline">{{ $o->number }}</a>
                    @if ($o->note)
                        <p class="max-w-xs truncate text-xs text-slate-500">{{ $o->note }}</p>
                    @endif
                </x-ui.table.td>
                <x-ui.table.td numeric muted>{{ Format::tanggal($o->opname_date) }}</x-ui.table.td>
                <x-ui.table.td muted>{{ $o->warehouse?->name }}</x-ui.table.td>
                <x-ui.table.td align="right" numeric>{{ Format::jumlah($o->items_count) }} produk</x-ui.table.td>
                <x-ui.table.td align="right" numeric>
                    @if ($o->selisih_count > 0)
                        <span class="font-medium text-warning-700">{{ Format::jumlah($o->selisih_count) }}</span>
                    @else
                        <span class="text-slate-400">0</span>
                    @endif
                </x-ui.table.td>
                <x-ui.table.td><x-ui.badge :color="$o->status->color()" dot>{{ $o->status->label() }}</x-ui.badge></x-ui.table.td>
                <x-ui.table.td muted>{{ $o->user?->name }}</x-ui.table.td>
            </tr>
        @empty
            <x-ui.table.empty colspan="7" icon="clipboard-document-check" title="Belum ada stok opname yang cocok" description="Ubah filter, atau mulai opname baru." />
        @endforelse
    </x-ui.table>
</x-app-layout>

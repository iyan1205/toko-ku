{{-- Pilih purchase order yang barangnya akan diterima. --}}
@use('App\Support\Format')

<x-app-layout>
    <x-slot name="header">Terima barang</x-slot>
    <x-slot name="description">Pilih purchase order yang barangnya sudah tiba. Hanya PO yang disetujui yang tampil.</x-slot>

    <x-ui.table :paginator="$orders">
        <x-slot:toolbar>
            <x-ui.filter-bar placeholder="Cari nomor PO atau supplier" />
        </x-slot:toolbar>

        <x-slot:head>
            <x-ui.table.th>Purchase order</x-ui.table.th>
            <x-ui.table.th>Supplier</x-ui.table.th>
            <x-ui.table.th>Gudang tujuan</x-ui.table.th>
            <x-ui.table.th>Perkiraan tiba</x-ui.table.th>
            <x-ui.table.th>Status</x-ui.table.th>
            <x-ui.table.th align="right"><span class="sr-only">Aksi</span></x-ui.table.th>
        </x-slot:head>

        @forelse ($orders as $o)
            <tr>
                <x-ui.table.td><a href="{{ route('purchase-orders.show', $o) }}" class="font-medium text-slate-900 hover:underline">{{ $o->number }}</a></x-ui.table.td>
                <x-ui.table.td>{{ $o->supplier?->name }}</x-ui.table.td>
                <x-ui.table.td muted>{{ $o->warehouse?->name }}</x-ui.table.td>
                <x-ui.table.td numeric muted>{{ Format::tanggal($o->expected_date) }}</x-ui.table.td>
                <x-ui.table.td><x-ui.badge :color="$o->status->color()">{{ $o->status->label() }}</x-ui.badge></x-ui.table.td>
                <x-ui.table.td align="right">
                    <x-ui.button size="sm" :href="route('goods-receipts.create', ['po' => $o->id])" icon="inbox-arrow-down">Terima</x-ui.button>
                </x-ui.table.td>
            </tr>
        @empty
            <x-ui.table.empty colspan="6" icon="clipboard-document-check" title="Tidak ada PO yang menunggu barang" description="Setujui purchase order terlebih dahulu.">
                @can('purchasing.orders.view')
                    <x-ui.button variant="secondary" :href="route('purchase-orders.index')">Lihat purchase order</x-ui.button>
                @endcan
            </x-ui.table.empty>
        @endforelse
    </x-ui.table>
</x-app-layout>

{{-- Daftar penerimaan barang dari purchase order. --}}
@use('App\Support\Format')

<x-app-layout>
    <x-slot name="header">Penerimaan Barang</x-slot>
    <x-slot name="description">Barang dari purchase order yang sudah tiba. Setiap penerimaan menambah stok dan mencatat hutang.</x-slot>
    <x-slot name="actions">
        @can('purchasing.receipts.export')
            <x-ui.export-menu :href="route('goods-receipts.export')" />
        @endcan
        @can('purchasing.receipts.create')
            <x-ui.button :href="route('goods-receipts.create')" icon="inbox-arrow-down">
                Terima barang @if ($menunggu > 0)<span class="ml-1 rounded-full bg-white/20 px-1.5 text-xs">{{ $menunggu }} PO</span>@endif
            </x-ui.button>
        @endcan
    </x-slot>

    <x-ui.table :paginator="$receipts">
        <x-slot:toolbar>
            <x-ui.filter-bar placeholder="Cari nomor, PO, surat jalan, atau supplier">
                <x-ui.select name="supplier" label="Supplier" hide-label :options="$suppliers->pluck('name', 'id')" :selected="request('supplier')" placeholder="Semua supplier" />
                <x-ui.select name="gudang" label="Gudang" hide-label :options="$gudang->pluck('name', 'id')" :selected="request('gudang')" placeholder="Semua gudang" />
                <x-ui.select name="status" label="Status" hide-label :options="['completed' => 'Selesai', 'voided' => 'Dibatalkan']" :selected="request('status')" placeholder="Semua status" />
                <x-ui.input name="dari" label="Dari tanggal" hide-label type="date" :value="request('dari')" />
                <x-ui.input name="sampai" label="Sampai tanggal" hide-label type="date" :value="request('sampai')" />
            </x-ui.filter-bar>
        </x-slot:toolbar>

        <x-slot:head>
            <x-ui.table.th sort="number">Nomor</x-ui.table.th>
            <x-ui.table.th sort="date">Tanggal</x-ui.table.th>
            <x-ui.table.th sort="supplier">Supplier</x-ui.table.th>
            <x-ui.table.th>Purchase order</x-ui.table.th>
            <x-ui.table.th>Gudang</x-ui.table.th>
            <x-ui.table.th align="right">Nilai</x-ui.table.th>
            <x-ui.table.th>Status</x-ui.table.th>
        </x-slot:head>

        @forelse ($receipts as $r)
            <tr @class(['text-slate-400' => $r->dibatalkan()])>
                <x-ui.table.td>
                    <a href="{{ route('goods-receipts.show', $r) }}" class="font-medium text-slate-900 hover:text-primary-700 hover:underline">{{ $r->number }}</a>
                    <p class="text-xs text-slate-500">{{ $r->items_count }} produk @if ($r->delivery_note_number) · SJ {{ $r->delivery_note_number }} @endif</p>
                </x-ui.table.td>
                <x-ui.table.td numeric muted>{{ Format::tanggal($r->received_date) }}</x-ui.table.td>
                <x-ui.table.td>{{ $r->supplier?->name }}</x-ui.table.td>
                <x-ui.table.td><a href="{{ route('purchase-orders.show', $r->purchase_order_id) }}" class="text-primary-700 hover:underline">{{ $r->purchaseOrder?->number }}</a></x-ui.table.td>
                <x-ui.table.td muted>{{ $r->warehouse?->name }}</x-ui.table.td>
                <x-ui.table.td align="right" numeric>@rupiah($r->purchase?->grand_total ?? 0)</x-ui.table.td>
                <x-ui.table.td><x-ui.badge :color="$r->status->color()" dot>{{ $r->status->label() }}</x-ui.badge></x-ui.table.td>
            </tr>
        @empty
            <x-ui.table.empty colspan="7" icon="inbox-arrow-down" title="Belum ada penerimaan yang cocok" description="Barang diterima dari purchase order yang sudah disetujui." />
        @endforelse
    </x-ui.table>
</x-app-layout>

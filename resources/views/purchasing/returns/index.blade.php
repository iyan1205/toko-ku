{{-- Daftar retur pembelian (draft dan disetujui). --}}
@use('App\Support\Format')

<x-app-layout>
    <x-slot name="header">Retur Pembelian</x-slot>
    <x-slot name="description">Barang yang dikembalikan ke supplier. Retur yang disetujui mengurangi stok dan hutang.</x-slot>
    <x-slot name="actions">
        @can('purchasing.returns.export')
            <x-ui.export-menu :href="route('purchase-returns.export')" />
        @endcan
        @can('purchasing.returns.create')
            <x-ui.button :href="route('purchase-returns.create')" icon="plus">Retur baru</x-ui.button>
        @endcan
    </x-slot>

    <x-ui.table :paginator="$returns">
        <x-slot:toolbar>
            <x-ui.filter-bar placeholder="Cari nomor, faktur, alasan, atau supplier">
                <x-ui.select name="supplier" label="Supplier" hide-label :options="$suppliers->pluck('name', 'id')" :selected="request('supplier')" placeholder="Semua supplier" />
                <x-ui.select name="gudang" label="Gudang" hide-label :options="$gudang->pluck('name', 'id')" :selected="request('gudang')" placeholder="Semua gudang" />
                <x-ui.select name="status" label="Status" hide-label :options="['draft' => 'Draft', 'approved' => 'Disetujui']" :selected="request('status')" placeholder="Semua status" />
                <x-ui.input name="dari" label="Dari tanggal" hide-label type="date" :value="request('dari')" />
                <x-ui.input name="sampai" label="Sampai tanggal" hide-label type="date" :value="request('sampai')" />
            </x-ui.filter-bar>
        </x-slot:toolbar>

        <x-slot:head>
            <x-ui.table.th sort="number">Nomor</x-ui.table.th>
            <x-ui.table.th sort="date">Tanggal</x-ui.table.th>
            <x-ui.table.th sort="supplier">Supplier</x-ui.table.th>
            <x-ui.table.th>Faktur</x-ui.table.th>
            <x-ui.table.th align="right" sort="total">Nilai</x-ui.table.th>
            <x-ui.table.th>Status</x-ui.table.th>
        </x-slot:head>

        @forelse ($returns as $r)
            <tr>
                <x-ui.table.td>
                    <a href="{{ route('purchase-returns.show', $r) }}" class="font-medium text-slate-900 hover:text-primary-700 hover:underline">{{ $r->number }}</a>
                    <p class="max-w-xs truncate text-xs text-slate-500">{{ $r->reason }}</p>
                </x-ui.table.td>
                <x-ui.table.td numeric muted>{{ Format::tanggal($r->return_date) }}</x-ui.table.td>
                <x-ui.table.td>{{ $r->supplier?->name }}</x-ui.table.td>
                <x-ui.table.td><a href="{{ route('purchases.show', $r->purchase_id) }}" class="text-primary-700 hover:underline">{{ $r->purchase?->number }}</a></x-ui.table.td>
                <x-ui.table.td align="right" numeric>
                    @rupiah($r->total)
                    @if ((float) $r->refund_amount > 0)
                        <p class="text-xs text-warning-700">refund @rupiah($r->refund_amount)</p>
                    @endif
                </x-ui.table.td>
                <x-ui.table.td><x-ui.badge :color="$r->status->color()" dot>{{ $r->status->label() }}</x-ui.badge></x-ui.table.td>
            </tr>
        @empty
            <x-ui.table.empty colspan="6" icon="arrow-uturn-left" title="Belum ada retur yang cocok" description="Ubah filter, atau buat retur dari halaman faktur." />
        @endforelse
    </x-ui.table>
</x-app-layout>

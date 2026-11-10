{{-- Retur penjualan: diajukan kasir, disetujui atasan. Cari, filter, urutkan, ekspor. --}}
@use('App\Support\Format')

<x-app-layout>
    <x-slot name="header">Retur Penjualan</x-slot>
    <x-slot name="description">Barang yang dikembalikan pelanggan. Stok dan uang baru berubah setelah retur disetujui.</x-slot>
    <x-slot name="actions">
        @can('pos.returns.export')
            <x-ui.export-menu :href="route('sale-returns.export')" />
        @endcan
        @can('pos.returns.create')
            <x-ui.button :href="route('sale-returns.create')" icon="plus">Retur baru</x-ui.button>
        @endcan
    </x-slot>

    @if ($pending > 0)
        <x-ui.alert type="warning" class="mb-6">
            {{ $pending }} retur menunggu persetujuan.
            <a href="{{ route('sale-returns.index', ['status' => 'draft']) }}" class="font-medium underline">Tampilkan</a>
        </x-ui.alert>
    @endif

    <x-ui.table :paginator="$returns">
        <x-slot:toolbar>
            <x-ui.filter-bar placeholder="Cari nomor retur, nomor penjualan, pelanggan, atau alasan">
                <x-ui.select name="pelanggan" label="Pelanggan" hide-label :options="$customers->pluck('name', 'id')" :selected="request('pelanggan')" placeholder="Semua pelanggan" />
                <x-ui.select name="status" label="Status" hide-label :options="$statuses" :selected="request('status')" placeholder="Semua status" />
                <x-ui.input name="dari" label="Dari tanggal" hide-label type="date" :value="request('dari')" />
                <x-ui.input name="sampai" label="Sampai tanggal" hide-label type="date" :value="request('sampai')" />
            </x-ui.filter-bar>
        </x-slot:toolbar>

        <x-slot:head>
            <x-ui.table.th sort="number">Nomor</x-ui.table.th>
            <x-ui.table.th sort="date">Tanggal</x-ui.table.th>
            <x-ui.table.th>Penjualan</x-ui.table.th>
            <x-ui.table.th sort="customer">Pelanggan</x-ui.table.th>
            <x-ui.table.th align="right" sort="total">Nilai</x-ui.table.th>
            <x-ui.table.th>Status</x-ui.table.th>
        </x-slot:head>

        @forelse ($returns as $r)
            <tr>
                <x-ui.table.td>
                    <a href="{{ route('sale-returns.show', $r) }}" class="font-medium text-slate-900 hover:text-primary-700 hover:underline">{{ $r->number }}</a>
                    <p class="max-w-xs truncate text-xs text-slate-500">{{ $r->reason }}</p>
                </x-ui.table.td>
                <x-ui.table.td numeric muted>{{ Format::tanggal($r->return_date) }}</x-ui.table.td>
                <x-ui.table.td muted>{{ $r->sale?->number }}</x-ui.table.td>
                <x-ui.table.td>{{ $r->customer?->name }}</x-ui.table.td>
                <x-ui.table.td align="right" numeric>@rupiah($r->total)</x-ui.table.td>
                <x-ui.table.td><x-ui.badge :color="$r->status->color()" dot>{{ $r->status->label() }}</x-ui.badge></x-ui.table.td>
            </tr>
        @empty
            <x-ui.table.empty colspan="6" icon="arrow-uturn-left" title="Belum ada retur yang cocok" description="Retur dibuat dari halaman detail penjualan atau tombol Retur baru." />
        @endforelse
    </x-ui.table>
</x-app-layout>

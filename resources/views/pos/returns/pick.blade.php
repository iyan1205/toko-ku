{{-- Pilih transaksi kasir yang barangnya dikembalikan pelanggan. Kasir hanya melihat transaksinya sendiri. --}}
@use('App\Support\Format')

<x-app-layout>
    <x-slot name="header">Retur baru</x-slot>
    <x-slot name="description">Pilih transaksi asal barang yang dikembalikan. Cari dengan nomor di struk.</x-slot>

    <x-ui.table :paginator="$sales">
        <x-slot:toolbar>
            <x-ui.filter-bar placeholder="Cari nomor transaksi atau nama pelanggan" />
        </x-slot:toolbar>
        <x-slot:head>
            <x-ui.table.th>Transaksi</x-ui.table.th>
            <x-ui.table.th>Waktu</x-ui.table.th>
            <x-ui.table.th>Pelanggan</x-ui.table.th>
            <x-ui.table.th align="right">Total</x-ui.table.th>
            <x-ui.table.th align="right"><span class="sr-only">Aksi</span></x-ui.table.th>
        </x-slot:head>
        @forelse ($sales as $p)
            <tr>
                <x-ui.table.td><a href="{{ route('sales.show', $p) }}" class="font-medium text-slate-900 hover:underline">{{ $p->number }}</a></x-ui.table.td>
                <x-ui.table.td numeric muted>{{ Format::tanggal($p->sold_at, true) }}</x-ui.table.td>
                <x-ui.table.td>{{ $p->customer?->name }}</x-ui.table.td>
                <x-ui.table.td align="right" numeric>@rupiah($p->grand_total)</x-ui.table.td>
                <x-ui.table.td align="right"><x-ui.button size="sm" variant="secondary" :href="route('sale-returns.create', ['penjualan' => $p->id])" icon="arrow-uturn-left">Retur</x-ui.button></x-ui.table.td>
            </tr>
        @empty
            <x-ui.table.empty colspan="5" icon="receipt-percent" title="Tidak ada transaksi yang bisa diretur" description="Transaksi yang dibatalkan atau sudah diretur semua tidak tampil." />
        @endforelse
    </x-ui.table>
</x-app-layout>

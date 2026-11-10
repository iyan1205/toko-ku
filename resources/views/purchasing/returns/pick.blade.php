{{-- Pilih faktur pembelian yang barangnya akan diretur. --}}
@use('App\Support\Format')

<x-app-layout>
    <x-slot name="header">Retur baru</x-slot>
    <x-slot name="description">Pilih faktur pembelian asal barang yang dikembalikan.</x-slot>

    <x-ui.table :paginator="$purchases">
        <x-slot:toolbar>
            <x-ui.filter-bar placeholder="Cari nomor faktur, faktur supplier, atau supplier" />
        </x-slot:toolbar>
        <x-slot:head>
            <x-ui.table.th>Faktur</x-ui.table.th>
            <x-ui.table.th>Tanggal</x-ui.table.th>
            <x-ui.table.th>Supplier</x-ui.table.th>
            <x-ui.table.th align="right">Total</x-ui.table.th>
            <x-ui.table.th align="right"><span class="sr-only">Aksi</span></x-ui.table.th>
        </x-slot:head>
        @forelse ($purchases as $p)
            <tr>
                <x-ui.table.td>
                    <a href="{{ route('purchases.show', $p) }}" class="font-medium text-slate-900 hover:underline">{{ $p->number }}</a>
                    @if ($p->supplier_invoice_number)<p class="text-xs text-slate-500">{{ $p->supplier_invoice_number }}</p>@endif
                </x-ui.table.td>
                <x-ui.table.td numeric muted>{{ Format::tanggal($p->purchase_date) }}</x-ui.table.td>
                <x-ui.table.td>{{ $p->supplier?->name }}</x-ui.table.td>
                <x-ui.table.td align="right" numeric>@rupiah($p->grand_total)</x-ui.table.td>
                <x-ui.table.td align="right"><x-ui.button size="sm" variant="secondary" :href="route('purchase-returns.create', ['faktur' => $p->id])" icon="arrow-uturn-left">Retur</x-ui.button></x-ui.table.td>
            </tr>
        @empty
            <x-ui.table.empty colspan="5" icon="document-text" title="Tidak ada faktur yang bisa diretur" />
        @endforelse
    </x-ui.table>
</x-app-layout>

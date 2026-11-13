{{-- Batch yang sudah atau akan kedaluwarsa (produk yang melacak batch). Terdekat dulu. --}}
@use('App\Support\Format')

<x-app-layout>
    <x-slot name="header">Mendekati Kedaluwarsa</x-slot>
    <x-slot name="description">Batch yang masih bersisa dan kedaluwarsa dalam {{ $days }} hari atau sudah lewat. Stok keluar otomatis memakai batch terdekat dulu (FEFO).</x-slot>
    <x-slot name="actions">
        @can('inventory.stock.export')
            <x-ui.export-menu :href="route('stock.expiring.export')" />
        @endcan
    </x-slot>

    <x-ui.table :paginator="$lots">
        <x-slot:toolbar>
            <x-ui.filter-bar placeholder="Cari produk, SKU, atau batch">
                <x-ui.select name="gudang" label="Gudang" hide-label :options="$gudang->pluck('name', 'id')" :selected="$filter['gudang'] ?? null" placeholder="Semua gudang" />
                <x-ui.select name="hari" label="Dalam" hide-label :options="[7 => '7 hari', 30 => '30 hari', 60 => '60 hari', 90 => '90 hari', 180 => '180 hari']" :selected="$filter['hari'] ?? $days" />
            </x-ui.filter-bar>
        </x-slot:toolbar>
        <x-slot:head>
            <x-ui.table.th>Produk</x-ui.table.th>
            <x-ui.table.th>Gudang</x-ui.table.th>
            <x-ui.table.th>Batch</x-ui.table.th>
            <x-ui.table.th sort="expiry">Kedaluwarsa</x-ui.table.th>
            <x-ui.table.th align="right" sort="quantity">Sisa stok</x-ui.table.th>
            <x-ui.table.th align="right">Nilai</x-ui.table.th>
        </x-slot:head>
        @forelse ($lots as $lot)
            @php
                $sisaHari = (int) today()->diffInDays($lot->expiry_date, false);
            @endphp
            <tr>
                <x-ui.table.td>
                    <a href="{{ route('products.show', $lot->product_id) }}" class="font-medium text-slate-900 hover:underline">{{ $lot->product?->name }}</a>
                    <span class="block text-xs text-slate-500">{{ $lot->product?->sku }}</span>
                </x-ui.table.td>
                <x-ui.table.td muted>{{ $lot->warehouse?->name }}</x-ui.table.td>
                <x-ui.table.td class="font-mono text-xs">{{ $lot->batch_number ?? '—' }}</x-ui.table.td>
                <x-ui.table.td class="whitespace-nowrap">
                    {{ Format::tanggal($lot->expiry_date) }}
                    @if ($sisaHari < 0)
                        <x-ui.badge color="danger" class="ml-1">Lewat {{ abs($sisaHari) }} hari</x-ui.badge>
                    @elseif ($sisaHari <= 7)
                        <x-ui.badge color="warning" class="ml-1">{{ $sisaHari }} hari lagi</x-ui.badge>
                    @else
                        <span class="block text-xs text-slate-500">{{ $sisaHari }} hari lagi</span>
                    @endif
                </x-ui.table.td>
                <x-ui.table.td align="right" numeric>{{ Format::jumlah($lot->quantity) }} {{ $lot->product?->unit?->name }}</x-ui.table.td>
                <x-ui.table.td align="right" numeric>@rupiah((float) $lot->quantity * (float) $lot->unit_cost)</x-ui.table.td>
            </tr>
        @empty
            <x-ui.table.empty colspan="6" icon="check-circle" title="Tidak ada batch yang mendekati kedaluwarsa" description="Batch tercatat saat barang berbatch diterima." />
        @endforelse
    </x-ui.table>
</x-app-layout>

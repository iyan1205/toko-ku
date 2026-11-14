{{-- Nilai persediaan per tanggal (App\Exports\Reports\InventoryValuationExport). --}}
@use('App\Support\Format')
@use('App\Exports\Reports\InventoryValuationExport')

<x-app-layout>
    <x-slot name="header">Laporan Stok</x-slot>
    <x-slot name="description">Jumlah dan nilai persediaan per produk pada akhir tanggal yang dipilih, dari biaya mutasi stok (FIFO/rata-rata).</x-slot>
    <x-slot name="actions">
        @can('reports.stock.export')
            <x-ui.export-menu :href="route('reports.stock.export', 'nilai-persediaan')" />
        @endcan
    </x-slot>

    @include('reports.stock.nav')

    <div class="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <x-ui.stat-card label="Nilai persediaan per {{ Format::tanggal($list->date()) }}" :value="Format::rupiah($totals['value'])" icon="banknotes" />
        <x-ui.stat-card label="Produk berstok" :value="Format::jumlah($totals['items'])" icon="cube" color="neutral" />
    </div>

    <x-ui.table :paginator="$rows">
        <x-slot:toolbar>
            <x-ui.filter-bar placeholder="Cari produk atau SKU">
                <x-ui.input name="per" label="Per tanggal" hide-label type="date" :value="$filter['per']" />
                <x-ui.select name="gudang" label="Gudang" hide-label :options="$gudang->pluck('name', 'id')" :selected="$filter['gudang'] ?? null" placeholder="Semua gudang" />
                <x-ui.select name="kategori" label="Kategori" hide-label :options="$kategori" :selected="$filter['kategori'] ?? null" placeholder="Semua kategori" />
            </x-ui.filter-bar>
        </x-slot:toolbar>
        <x-slot:head>
            <x-ui.table.th sort="name">Produk</x-ui.table.th>
            <x-ui.table.th sort="category">Kategori</x-ui.table.th>
            <x-ui.table.th align="right" sort="qty">Jumlah</x-ui.table.th>
            <x-ui.table.th align="right">Biaya per unit</x-ui.table.th>
            <x-ui.table.th align="right" sort="value">Nilai</x-ui.table.th>
        </x-slot:head>
        @forelse ($rows as $p)
            <tr>
                <x-ui.table.td>
                    @if ($p->trashed())
                        <span class="font-medium text-slate-900">{{ $p->name }}</span> <x-ui.badge color="neutral">Dihapus</x-ui.badge>
                    @else
                        <a href="{{ route('stock.movements', ['produk' => $p->id] + array_filter(['gudang' => $filter['gudang'] ?? null, 'sampai' => $filter['per']])) }}" class="font-medium text-slate-900 hover:underline">{{ $p->name }}</a>
                    @endif
                    <span class="block text-xs text-slate-500">{{ $p->sku }}</span>
                </x-ui.table.td>
                <x-ui.table.td muted>{{ $p->category?->name ?? '—' }}</x-ui.table.td>
                <x-ui.table.td align="right" numeric @class(['text-danger-600' => (float) $p->qty_at < 0])>{{ Format::jumlah($p->qty_at) }} {{ $p->unit?->name }}</x-ui.table.td>
                <x-ui.table.td align="right" numeric muted>{{ ($biaya = InventoryValuationExport::unitCost($p)) !== null ? Format::rupiah($biaya) : '—' }}</x-ui.table.td>
                <x-ui.table.td align="right" numeric class="font-medium text-slate-900">@rupiah(InventoryValuationExport::value($p))</x-ui.table.td>
            </tr>
        @empty
            <x-ui.table.empty colspan="5" icon="cube" title="Tidak ada stok pada tanggal ini" description="Ubah tanggal, gudang, atau kategori." />
        @endforelse
        @if ($rows->isNotEmpty())
            <tr class="bg-slate-50 font-semibold">
                <x-ui.table.td colspan="4">Total {{ Format::jumlah($totals['items']) }} produk</x-ui.table.td>
                <x-ui.table.td align="right" numeric>@rupiah($totals['value'])</x-ui.table.td>
            </tr>
        @endif
    </x-ui.table>

    <p class="mt-3 text-xs text-slate-500">
        Posisi dihitung dari stok gudang sekarang dikurangi mutasi sesudah tanggal itu. Stok minus tidak bernilai.
        Barang yang sedang dalam perjalanan antar cabang tidak termasuk gudang mana pun.
    </p>
</x-app-layout>

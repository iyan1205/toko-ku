{{-- Perputaran stok per produk untuk satu periode (App\Exports\Reports\StockTurnoverExport). --}}
@use('App\Support\Format')
@use('App\Exports\Reports\StockTurnoverExport')

@php
    $perputaran = StockTurnoverExport::turnover($totals['usage'], $totals['average']);
    $hari = $list->daysOfInventory($totals['usage'], $totals['average']);
@endphp

<x-app-layout>
    <x-slot name="header">Laporan Stok</x-slot>
    <x-slot name="description">Seberapa cepat persediaan berputar: HPP/pemakaian dibagi rata-rata nilai persediaan awal dan akhir periode.</x-slot>
    <x-slot name="actions">
        @can('reports.stock.export')
            <x-ui.export-menu :href="route('reports.stock.export', 'perputaran')" />
        @endcan
    </x-slot>

    @include('reports.stock.nav')

    <div class="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-ui.stat-card label="HPP / pemakaian" :value="Format::rupiah($totals['usage'])" icon="arrow-trending-down" :hint="Format::tanggal($list->from()).' s.d. '.Format::tanggal($list->to())" />
        <x-ui.stat-card label="Rata-rata persediaan" :value="Format::rupiah($totals['average'])" icon="banknotes" color="neutral"
                        :hint="'Awal '.Format::rupiah($totals['opening']).' · akhir '.Format::rupiah($totals['closing'])" />
        <x-ui.stat-card label="Perputaran" :value="$perputaran !== null ? Format::jumlah($perputaran).' kali' : '—'" icon="arrow-path" color="info" :hint="$list->days().' hari'" />
        <x-ui.stat-card label="Hari persediaan" :value="$hari !== null ? Format::jumlah($hari).' hari' : '—'" icon="calendar-days" color="neutral" hint="Rata-rata stok cukup untuk sekian hari" />
    </div>

    <x-ui.table :paginator="$rows">
        <x-slot:toolbar>
            <x-ui.filter-bar placeholder="Cari produk atau SKU">
                <x-ui.input name="dari" label="Dari tanggal" hide-label type="date" :value="$filter['dari']" />
                <x-ui.input name="sampai" label="Sampai tanggal" hide-label type="date" :value="$filter['sampai']" />
                <x-ui.select name="gudang" label="Gudang" hide-label :options="$gudang->pluck('name', 'id')" :selected="$filter['gudang'] ?? null" placeholder="Semua gudang" />
                <x-ui.select name="kategori" label="Kategori" hide-label :options="$kategori" :selected="$filter['kategori'] ?? null" placeholder="Semua kategori" />
            </x-ui.filter-bar>
        </x-slot:toolbar>
        <x-slot:head>
            <x-ui.table.th sort="name">Produk</x-ui.table.th>
            <x-ui.table.th align="right">Persediaan awal</x-ui.table.th>
            <x-ui.table.th align="right" sort="closing">Persediaan akhir</x-ui.table.th>
            <x-ui.table.th align="right" sort="usage">Keluar</x-ui.table.th>
            <x-ui.table.th align="right" sort="turnover">Perputaran</x-ui.table.th>
            <x-ui.table.th align="right">Hari persediaan</x-ui.table.th>
        </x-slot:head>
        @forelse ($rows as $p)
            @php
                $rata = $list->average($p);
                $kali = StockTurnoverExport::turnover((float) $p->usage_value, $rata);
                $hariProduk = $list->daysOfInventory((float) $p->usage_value, $rata);
            @endphp
            <tr>
                <x-ui.table.td>
                    <span class="font-medium text-slate-900">{{ $p->name }}</span>
                    <span class="block text-xs text-slate-500">{{ $p->sku }}{{ $p->category ? ' · '.$p->category->name : '' }}</span>
                </x-ui.table.td>
                <x-ui.table.td align="right" numeric>
                    @rupiah((float) $p->opening_qty > 0 ? $p->opening_value : 0)
                    <span class="block text-xs text-slate-500">{{ Format::jumlah($p->opening_qty) }} {{ $p->unit?->name }}</span>
                </x-ui.table.td>
                <x-ui.table.td align="right" numeric>
                    @rupiah((float) $p->closing_qty > 0 ? $p->closing_value : 0)
                    <span class="block text-xs text-slate-500">{{ Format::jumlah($p->closing_qty) }} {{ $p->unit?->name }}</span>
                </x-ui.table.td>
                <x-ui.table.td align="right" numeric>
                    @rupiah($p->usage_value)
                    <span class="block text-xs text-slate-500">{{ Format::jumlah($p->usage_qty) }} {{ $p->unit?->name }}</span>
                </x-ui.table.td>
                <x-ui.table.td align="right" numeric class="font-medium text-slate-900">{{ $kali !== null ? Format::jumlah($kali).'×' : '—' }}</x-ui.table.td>
                <x-ui.table.td align="right" numeric muted>{{ $hariProduk !== null ? Format::jumlah($hariProduk).' hari' : '—' }}</x-ui.table.td>
            </tr>
        @empty
            <x-ui.table.empty colspan="6" icon="arrow-path" title="Tidak ada persediaan atau pemakaian pada periode ini" description="Ubah periode, gudang, atau kategori." />
        @endforelse
    </x-ui.table>

    <p class="mt-3 text-xs text-slate-500">
        Persediaan awal = nilai pada akhir hari sebelum tanggal awal. Keluar = penjualan dikurangi retur penjualan, ditambah bahan yang terpakai produksi;
        transfer, penyesuaian, barang rusak, dan hilang tidak dihitung. Perputaran makin besar berarti barang makin cepat laku.
    </p>
</x-app-layout>

{{-- Barang lambat & mati (App\Exports\Reports\SlowMovingExport). --}}
@use('App\Support\Format')
@use('App\Exports\Reports\SlowMovingExport')

@php
    $hari = $list->days();
    $warna = ['mati' => 'danger', 'lambat' => 'warning', 'baru' => 'info', 'normal' => 'success'];
    $ringkas = fn (string $s) => $summary->get($s) ?? (object) ['items' => 0, 'value' => 0];
    $tautStatus = fn (string $s) => route('reports.stock.slow-moving', ['status' => $s] + request()->only(['hari', 'gudang', 'kategori', 'cari']));
@endphp

<x-app-layout>
    <x-slot name="header">Laporan Stok</x-slot>
    <x-slot name="description">Barang yang masih berstok tetapi tidak atau jarang keluar dalam {{ $hari }} hari terakhir, beserta nilai yang tertahan.</x-slot>
    <x-slot name="actions">
        @can('reports.stock.export')
            <x-ui.export-menu :href="route('reports.stock.export', 'lambat-mati')" />
        @endcan
    </x-slot>

    @include('reports.stock.nav')

    <div class="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-ui.stat-card label="Mati (tidak keluar {{ $hari }} hari)" :value="Format::rupiah($ringkas('mati')->value)" icon="no-symbol" color="danger"
                        :hint="Format::jumlah($ringkas('mati')->items).' produk'" :href="$tautStatus('mati')" />
        <x-ui.stat-card label="Lambat (habis > {{ $hari }} hari lagi)" :value="Format::rupiah($ringkas('lambat')->value)" icon="clock" color="warning"
                        :hint="Format::jumlah($ringkas('lambat')->items).' produk'" :href="$tautStatus('lambat')" />
        <x-ui.stat-card label="Baru masuk, belum keluar" :value="Format::rupiah($ringkas('baru')->value)" icon="sparkles" color="info"
                        :hint="Format::jumlah($ringkas('baru')->items).' produk'" :href="$tautStatus('baru')" />
        <x-ui.stat-card label="Normal" :value="Format::rupiah($ringkas('normal')->value)" icon="check-circle" color="success"
                        :hint="Format::jumlah($ringkas('normal')->items).' produk'" :href="$tautStatus('normal')" />
    </div>

    <x-ui.table :paginator="$rows">
        <x-slot:toolbar>
            <x-ui.filter-bar placeholder="Cari produk atau SKU">
                <x-ui.select name="hari" label="Periode" hide-label :options="collect(SlowMovingExport::DAYS)->mapWithKeys(fn ($d) => [$d => $d.' hari terakhir'])" :selected="$hari" />
                <x-ui.select name="status" label="Status" hide-label :options="['semua' => 'Semua status'] + SlowMovingExport::STATUSES" :selected="$filter['status'] ?? null" placeholder="Mati & lambat" />
                <x-ui.select name="gudang" label="Gudang" hide-label :options="$gudang->pluck('name', 'id')" :selected="$filter['gudang'] ?? null" placeholder="Semua gudang" />
                <x-ui.select name="kategori" label="Kategori" hide-label :options="$kategori" :selected="$filter['kategori'] ?? null" placeholder="Semua kategori" />
            </x-ui.filter-bar>
        </x-slot:toolbar>
        <x-slot:head>
            <x-ui.table.th sort="name">Produk</x-ui.table.th>
            <x-ui.table.th align="right" sort="stock">Stok</x-ui.table.th>
            <x-ui.table.th align="right" sort="value">Nilai</x-ui.table.th>
            <x-ui.table.th align="right" sort="usage">Keluar {{ $hari }} hari</x-ui.table.th>
            <x-ui.table.th align="right">Habis dalam</x-ui.table.th>
            <x-ui.table.th sort="last_out">Terakhir keluar</x-ui.table.th>
            <x-ui.table.th>Status</x-ui.table.th>
        </x-slot:head>
        @forelse ($rows as $p)
            @php($habis = $list->daysOfCover($p))
            <tr>
                <x-ui.table.td>
                    <a href="{{ route('stock.movements', ['produk' => $p->id] + array_filter(['gudang' => $filter['gudang'] ?? null])) }}" class="font-medium text-slate-900 hover:underline">{{ $p->name }}</a>
                    <span class="block text-xs text-slate-500">{{ $p->sku }}{{ $p->category ? ' · '.$p->category->name : '' }}{{ $p->is_active ? '' : ' · nonaktif' }}</span>
                </x-ui.table.td>
                <x-ui.table.td align="right" numeric>{{ Format::jumlah($p->stock_qty) }} {{ $p->unit?->name }}</x-ui.table.td>
                <x-ui.table.td align="right" numeric class="font-medium text-slate-900">@rupiah($p->stock_value)</x-ui.table.td>
                <x-ui.table.td align="right" numeric>{{ Format::jumlah($p->usage_qty) }}</x-ui.table.td>
                <x-ui.table.td align="right" numeric muted>{{ $habis !== null ? Format::jumlah($habis).' hari' : '—' }}</x-ui.table.td>
                <x-ui.table.td class="whitespace-nowrap">
                    {{ $p->last_out_at ? Format::tanggal($p->last_out_at) : 'Belum pernah' }}
                    @if ($p->last_in_at)
                        <span class="block text-xs text-slate-500">Masuk {{ Format::tanggal($p->last_in_at) }}</span>
                    @endif
                </x-ui.table.td>
                <x-ui.table.td><x-ui.badge :color="$warna[$p->movement_status] ?? 'neutral'">{{ SlowMovingExport::STATUSES[$p->movement_status] ?? $p->movement_status }}</x-ui.badge></x-ui.table.td>
            </tr>
        @empty
            <x-ui.table.empty colspan="7" icon="check-circle" title="Tidak ada barang dengan status ini" description="Ubah periode, status, atau gudang." />
        @endforelse
    </x-ui.table>

    <p class="mt-3 text-xs text-slate-500">
        Keluar = penjualan dikurangi retur penjualan, ditambah bahan yang terpakai produksi. <strong>Mati</strong>: tidak keluar sama sekali dalam {{ $hari }} hari.
        <strong>Lambat</strong>: stok sekarang lebih banyak dari yang keluar selama {{ $hari }} hari, jadi baru habis lebih dari {{ $hari }} hari lagi.
        <strong>Baru masuk</strong>: pertama kali masuk dalam {{ $hari }} hari dan belum keluar.
    </p>
</x-app-layout>

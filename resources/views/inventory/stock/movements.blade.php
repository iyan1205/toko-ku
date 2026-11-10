{{--
    Kartu stok: setiap mutasi, terbaru dulu. Saldo = stok di gudang mutasi itu setelah mutasi.
    Filter produk datang dari tautan (halaman stok/produk); filter lain dari bar filter.
--}}
@use('App\Support\Format')
@use('App\Support\DocumentLink')

<x-app-layout>
    <x-slot name="header">Kartu Stok</x-slot>
    <x-slot name="description">Riwayat setiap pergerakan stok. Riwayat tidak bisa diubah; koreksi dicatat sebagai mutasi baru.</x-slot>
    <x-slot name="actions">
        @can('inventory.stock-card.export')
            <x-ui.export-menu :href="route('stock.movements.export')" />
        @endcan
        @can('inventory.adjustments.create')
            <x-ui.button :href="route('stock.adjust.create', array_filter(['produk' => $produkDipilih?->id, 'gudang' => request('gudang')]))" icon="adjustments-horizontal">
                Penyesuaian stok
            </x-ui.button>
        @endcan
    </x-slot>

    @if ($produkDipilih)
        <div class="mb-4 flex flex-wrap items-center gap-3 rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm shadow-card">
            <x-ui.icon name="cube" class="h-5 w-5 text-slate-400" />
            <span class="text-slate-600">Produk: <a href="{{ route('products.show', $produkDipilih) }}" class="font-medium text-slate-900 hover:underline">{{ $produkDipilih->name }}</a>
                <span class="text-slate-500">({{ $produkDipilih->sku }})</span></span>
            <span class="text-slate-600">Total stok <strong class="tabular-nums text-slate-900">{{ Format::jumlah($produkDipilih->stock) }}</strong></span>
            <a href="{{ route('stock.movements', request()->except(['produk', 'page'])) }}" class="ml-auto text-primary-700 hover:underline">Tampilkan semua produk</a>
        </div>
    @endif

    <x-ui.table :paginator="$mutasi">
        <x-slot:toolbar>
            <x-ui.filter-bar :search="null">
                @if ($produkDipilih)
                    <input type="hidden" name="produk" value="{{ $produkDipilih->id }}">
                @endif
                <x-ui.select name="gudang" label="Gudang" hide-label :options="$gudang->pluck('name', 'id')" :selected="request('gudang')" placeholder="Semua gudang" />
                <x-ui.select name="jenis" label="Jenis" hide-label :options="collect($jenis)->mapWithKeys(fn ($j) => [$j->value => $j->label()])" :selected="request('jenis')" placeholder="Semua jenis" />
                <x-ui.input name="dari" label="Dari tanggal" hide-label type="date" :value="request('dari')" />
                <x-ui.input name="sampai" label="Sampai tanggal" hide-label type="date" :value="request('sampai')" />
            </x-ui.filter-bar>
        </x-slot:toolbar>

        <x-slot:head>
            <x-ui.table.th sort="date">Waktu</x-ui.table.th>
            <x-ui.table.th>Produk</x-ui.table.th>
            <x-ui.table.th>Gudang</x-ui.table.th>
            <x-ui.table.th>Jenis</x-ui.table.th>
            <x-ui.table.th align="right">Jumlah</x-ui.table.th>
            <x-ui.table.th align="right">Saldo gudang</x-ui.table.th>
            <x-ui.table.th>Keterangan</x-ui.table.th>
            <x-ui.table.th>Oleh</x-ui.table.th>
        </x-slot:head>

        @forelse ($mutasi as $m)
            @php($masuk = (float) $m->quantity > 0)
            <tr>
                <x-ui.table.td numeric muted>{{ Format::tanggal($m->created_at, true) }}</x-ui.table.td>
                <x-ui.table.td>
                    <a href="{{ route('stock.movements', ['produk' => $m->product_id] + request()->only('gudang')) }}" class="font-medium text-slate-900 hover:underline">{{ $m->product?->name }}</a>
                    <p class="text-xs text-slate-500">{{ $m->product?->sku }}</p>
                </x-ui.table.td>
                <x-ui.table.td muted>{{ $m->warehouse?->name ?? '—' }}</x-ui.table.td>
                <x-ui.table.td>@include('partials.badge-mutasi', ['jenis' => $m->type])</x-ui.table.td>
                <x-ui.table.td align="right" numeric :class="$masuk ? 'font-semibold text-success-700' : 'font-semibold text-danger-600'">
                    {{ $masuk ? '+' : '−' }}{{ Format::jumlah(abs((float) $m->quantity)) }}
                </x-ui.table.td>
                <x-ui.table.td align="right" numeric>
                    <span class="text-slate-500">{{ Format::jumlah($m->stock_before) }} →</span>
                    <span class="font-medium text-slate-900">{{ Format::jumlah($m->stock_after) }}</span>
                </x-ui.table.td>
                <x-ui.table.td>
                    @if ($nomor = DocumentLink::number($m->reference))
                        @if ($url = DocumentLink::url($m->reference, auth()->user()))
                            <a href="{{ $url }}" class="font-medium text-primary-700 hover:underline">{{ $nomor }}</a>
                        @else
                            <span class="font-medium text-slate-700">{{ $nomor }}</span>
                        @endif
                    @endif
                    <p class="max-w-xs text-xs text-slate-500">{{ $m->note ?: '—' }}</p>
                </x-ui.table.td>
                <x-ui.table.td muted>{{ $m->user?->name ?? 'Sistem' }}</x-ui.table.td>
            </tr>
        @empty
            <x-ui.table.empty colspan="8" icon="clipboard-document-list" title="Belum ada mutasi stok yang cocok" description="Ubah filter atau rentang tanggal." />
        @endforelse
    </x-ui.table>
</x-app-layout>
